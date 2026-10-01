<?php

namespace GlpiPlugin\Aisuite\SmartSorter;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * AI Smart Sorter score reliability: compares the certainty the AI announced for
 * each field with what actually happened afterwards, using the audit log
 * (glpi_plugin_aismartsorter_logs) and the tickets as they are today.
 *
 * Two independent measures per field and certainty bucket:
 *  - "kept":     of the suggestions that were applied (automatically or by a
 *                click on "Apply"), how many still match the ticket today. A
 *                value a human changed afterwards counts as a miss. Caveat: an
 *                unchanged value may simply not have been reviewed.
 *  - "accepted": of the suggestions a human decided on in the popup, how many
 *                were applied rather than dismissed.
 *
 * Priority is left out on purpose: it is derived from urgency and impact.
 */
class Stats {

    /** Certainty buckets: label => [min, max] (inclusive). */
    private const BUCKETS = [
        '90-100' => [90, 100],
        '80-89'  => [80, 89],
        '70-79'  => [70, 79],
        '50-69'  => [50, 69],
        '0-49'   => [0, 49],
    ];

    /** field => [suggestion key in the AI JSON, ticket column] */
    private const FIELDS = [
        'category' => ['suggested_category_id', 'itilcategories_id'],
        'type'     => ['suggested_type_id',     'type'],
        'urgency'  => ['suggested_urgency',     'urgency'],
        'impact'   => ['suggested_impact',      'impact'],
    ];

    /**
     * @return array<string, array<string, array{n:int, applied:int, kept:int, decided:int, accepted:int}>>
     *         [field => [bucket => counters]]
     */
    public static function compute($limit = 1000) {
        global $DB;

        $logs = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'ai_response', 'confidence_score', 'action_taken'],
            'FROM'   => 'glpi_plugin_aismartsorter_logs',
            'WHERE'  => ['action_taken' => ['auto_applied', 'applied_by_user', 'dismissed_by_user', 'suggestion_only']],
            'ORDER'  => 'id DESC',
            'LIMIT'  => (int)$limit,
        ]) as $row) {
            $logs[] = $row;
        }

        $tickets = [];
        $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['tickets_id'], $logs)));
        if (!empty($ids)) {
            foreach ($DB->request([
                'SELECT' => ['id', 'itilcategories_id', 'type', 'urgency', 'impact'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['id' => $ids, 'is_deleted' => 0],
            ]) as $t) {
                $tickets[(int)$t['id']] = $t;
            }
        }

        $stats = [];
        foreach (array_keys(self::FIELDS) as $field) {
            foreach (array_keys(self::BUCKETS) as $bucket) {
                $stats[$field][$bucket] = ['n' => 0, 'applied' => 0, 'kept' => 0, 'decided' => 0, 'accepted' => 0];
            }
        }

        foreach ($logs as $log) {
            $data = json_decode((string)$log['ai_response'], true);
            if (!is_array($data) || !isset($tickets[(int)$log['tickets_id']])) {
                continue;
            }
            $ticket = $tickets[(int)$log['tickets_id']];
            $locked = (array)($data['locked_fields'] ?? []);
            $action = $log['action_taken'];

            foreach (self::FIELDS as $field => [$key, $column]) {
                $suggested = (int)($data[$key] ?? 0);
                if ($suggested <= 0 || in_array($field, $locked, true)) {
                    continue;
                }
                $conf   = (int)($data[$field . '_confidence'] ?? $log['confidence_score'] ?? 0);
                $bucket = self::bucketOf($conf);
                $c      = &$stats[$field][$bucket];
                $c['n']++;

                // Was this field actually applied by this log entry?
                $wasApplied = $action === 'applied_by_user'
                    || ($action === 'auto_applied' && in_array($field, (array)($data['auto_applied_fields'] ?? []), true));
                if ($wasApplied) {
                    $c['applied']++;
                    if ((int)$ticket[$column] === $suggested) {
                        $c['kept']++;
                    }
                }
                if ($action === 'applied_by_user' || $action === 'dismissed_by_user') {
                    $c['decided']++;
                    if ($action === 'applied_by_user') {
                        $c['accepted']++;
                    }
                }
                unset($c);
            }
        }

        return $stats;
    }

    private static function bucketOf($conf) {
        foreach (self::BUCKETS as $label => [$min, $max]) {
            if ($conf >= $min && $conf <= $max) {
                return $label;
            }
        }
        return '0-49';
    }

    /**
     * HTML table(s) for the config screen. Rows with no data are skipped; the
     * counts are always shown so a "100 %" on 2 cases is not mistaken for a trend.
     */
    public static function render() {
        $stats = self::compute();
        $names = [
            'category' => __('Catégorie', 'aisuite'),
            'type'     => __('Type', 'aisuite'),
            'urgency'  => __('Urgence', 'aisuite'),
            'impact'   => __('Impact', 'aisuite'),
        ];
        $pct = static function ($part, $total) {
            if ($total <= 0) {
                return '<span class="text-muted">-</span>';
            }
            $p   = (int)round($part * 100 / $total);
            $cls = $p >= 90 ? 'bg-success text-white' : ($p >= 70 ? 'bg-warning text-dark' : 'bg-danger text-white');
            return "<span class='badge $cls'>$p%</span> <small class='text-muted'>($part/$total)</small>";
        };

        $rows = '';
        foreach ($stats as $field => $buckets) {
            foreach ($buckets as $bucket => $c) {
                if ($c['n'] === 0) {
                    continue;
                }
                $rows .= '<tr><td><strong>' . htmlspecialchars($names[$field]) . '</strong></td>'
                    . '<td>' . htmlspecialchars($bucket) . ' %</td>'
                    . '<td>' . $c['n'] . '</td>'
                    . '<td>' . $pct($c['kept'], $c['applied']) . '</td>'
                    . '<td>' . $pct($c['accepted'], $c['decided']) . '</td></tr>';
            }
        }

        $html = "<p class='text-muted'>"
            . __("Compare la certitude annoncée par l'IA à ce qui s'est réellement passé ensuite. Si les tranches hautes ont un meilleur taux que les basses, le score est fiable et le seuil peut être réglé en conséquence.", 'aisuite')
            . "</p>";

        if ($rows === '') {
            return $html . "<div class='p-4 text-center text-muted'>" . __('Pas encore assez de données.', 'aisuite') . "</div>";
        }

        $html .= "<div class='table-responsive'><table class='table table-sm table-striped'><thead><tr>"
            . '<th>' . __('Champ', 'aisuite') . '</th>'
            . '<th>' . __('Certitude annoncée', 'aisuite') . '</th>'
            . '<th>' . __('Cas', 'aisuite') . '</th>'
            . '<th>' . __('Valeur conservée', 'aisuite') . '</th>'
            . '<th>' . __('Acceptée dans la fenêtre', 'aisuite') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';

        $html .= "<p class='small text-muted mb-0'>"
            . "<strong>" . __('Valeur conservée', 'aisuite') . "</strong> : "
            . __("part des suggestions appliquées (automatiquement ou par clic) dont la valeur est toujours celle du ticket aujourd'hui. Une valeur modifiée ensuite par un humain compte comme une erreur ; une valeur jamais relue compte comme conservée.", 'aisuite')
            . "<br><strong>" . __('Acceptée dans la fenêtre', 'aisuite') . "</strong> : "
            . __("part des suggestions affichées dans la fenêtre qui ont été appliquées plutôt qu'ignorées.", 'aisuite')
            . "</p>";

        return $html;
    }
}
