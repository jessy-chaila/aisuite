<?php

namespace GlpiPlugin\Aisuite\SmartSorter;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Ticket;
use Item_Ticket;
use TicketTask;
use Planning;
use Session;
use Toolbox;
use CommonITILActor;
use GlpiPlugin\Aisuite\Shared\PluginConfig;
use GlpiPlugin\Aisuite\Shared\ProviderFactory;
use GlpiPlugin\Aisuite\Shared\CostCalculator;
use GlpiPlugin\Aisuite\Shared\JsonResponseExtractor;

class Sorter {

    private $config;

    // Model / provider actually used for the last AI call, kept for cost
    // calculation (set in handleTicketCreation(), read back in processAiResponse()).
    private $lastUsedModel = '';
    private $lastUsedProviderType = 'openai';

    public function __construct() {
        $this->config = PluginConfig::get();
    }

    public function handleTicketCreation(Ticket $ticket) {
        global $DB;

        $title      = $ticket->fields['name'] ?? '';
        $content      = $ticket->fields['content'] ?? '';
        $ticketId     = $ticket->fields['id'];

        $cleanContent = strip_tags(html_entity_decode($content));
        $userQuery    = "Title: $title\nContent: $cleanContent";

        if (strlen($cleanContent) < 10) {
            return;
        }

        $categoriesMap = self::getGLPICategories();
        // "Liaison Matérielle" setting (enabled by default): when off, the user's
        // assets are not even offered to the AI, so nothing can be detected or linked.
        $hardwareLinking = !isset($this->config['sorter_enable_hardware_linking'])
            || !empty($this->config['sorter_enable_hardware_linking']);
        $assetsMap     = $hardwareLinking ? $this->getRequesterAssets($ticket) : [];
        $typesMap      = self::getTicketTypes();

        Toolbox::logInFile('aisuite', sprintf(__("Ticket #%d Analysis.", 'aisuite'), $ticketId) . "\n");

        $assetsListStr = !$hardwareLinking
            ? "Hardware detection is disabled: always set detected_hardware_name to null."
            : (empty($assetsMap)
            ? "No assets linked to user."
            : json_encode(array_keys($assetsMap), JSON_UNESCAPED_UNICODE));

        $categoriesStr = json_encode($categoriesMap, JSON_UNESCAPED_UNICODE);
        $typesStr      = json_encode($typesMap, JSON_UNESCAPED_UNICODE);
        $urgenciesStr  = json_encode(self::getScale('urgency'), JSON_UNESCAPED_UNICODE);
        $impactsStr    = json_encode(self::getScale('impact'), JSON_UNESCAPED_UNICODE);
        $lockedFields  = self::getUserSetFields($ticket);
        $userSetStr    = empty($lockedFields)
            ? 'None: the requester left urgency and impact at their default value, you must propose both.'
            : json_encode($lockedFields['values'], JSON_UNESCAPED_UNICODE);
        $rules         = trim((string)($this->config['sorter_prioritization_rules'] ?? ''));
        $rulesStr      = $rules !== '' ? $rules : 'None provided: use common IT service desk sense (a single-user incident is rarely very urgent).';
        $customContext = $this->config['sorter_system_prompt_context'] ?? '';

        /* Technical: Reinforced prompt with few-shot examples for strict language matching */
        $systemPrompt = "You are an expert IT Service Desk dispatcher working with GLPI.

        STRICT RULE: The 'reasoning' field MUST BE in the EXACT same language as the user's input.

        EXAMPLES:
        User Input: \"Mon écran est noir\"
        Reasoning: \"L'utilisateur signale un problème d'affichage...\"

        User Input: \"My screen is black\"
        Reasoning: \"The user is reporting a display issue...\"

        YOUR TASK:
        Analyze the user request and map it to the EXISTING database entries provided below.

        AVAILABLE CATEGORIES (ID => Name):
        $categoriesStr

        AVAILABLE TICKET TYPES (ID => Name):
        $typesStr

        AVAILABLE URGENCY LEVELS (ID => Name):
        $urgenciesStr

        AVAILABLE IMPACT LEVELS (ID => Name):
        $impactsStr

        VALUES ALREADY SET BY THE REQUESTER (field => ID). Do NOT suggest these fields (use null) but take them into account in your reasoning:
        $userSetStr

        PRIORITIZATION RULES OF THE ORGANIZATION (they take precedence over your own judgment for urgency and impact):
        $rulesStr

        USER ASSETS (Type - Name):
        $assetsListStr

        RULES:
        1. You MUST choose a 'suggested_category_id' strictly from the AVAILABLE CATEGORIES list.
        2. You MUST choose a 'suggested_type_id' strictly from the AVAILABLE TICKET TYPES list. Typically, a malfunction/breakage/error is an Incident, while a request for a new service/access/information is a Request (Demande).
        3. If the text mentions a device, check if it matches one in USER ASSETS using logical deduction.
        4. Output strictly valid JSON.
        5. Write the 'reasoning' in the SAME LANGUAGE as the user request (STRICTLY).
        6. Choose 'suggested_urgency' and 'suggested_impact' strictly from the matching AVAILABLE lists, unless the requester already set them. Urgency = how fast it must be handled; impact = how many users/services are affected. Priority is NOT your decision: it is computed from urgency and impact.
        7. For EACH of category, type, urgency and impact, give your own certainty 0-100 in the matching '*_confidence' field. Be honest: use a low value when the request does not give enough information, and use null for the value if you cannot decide.

        Context provided by admin: $customContext

        JSON OUTPUT FORMAT:
        {
            \"suggested_category_id\": <ID_INT_OR_NULL>,
            \"suggested_category_name\": \"<NAME_STRING>\",
            \"suggested_type_id\": <ID_INT_OR_NULL>,
            \"suggested_type_name\": \"<NAME_STRING>\",
            \"suggested_urgency\": <ID_INT_OR_NULL>,
            \"suggested_impact\": <ID_INT_OR_NULL>,
            \"category_confidence\": <0-100>,
            \"type_confidence\": <0-100>,
            \"urgency_confidence\": <0-100>,
            \"impact_confidence\": <0-100>,
            \"confidence_score\": <0-100_OVERALL>,
            \"detected_hardware_name\": \"<EXACT_NAME_FROM_USER_ASSETS_OR_NULL>\",
            \"reasoning\": \"<REASONING_IN_THE_SAME_LANGUAGE_AS_USER_INPUT>\"
        }";

        // AI Smart Sorter follows the AI Suite-wide active provider (Providers tab),
        // same as AI Smart Check and AI Chatbot.
        $providerType = ProviderFactory::normalizeType($this->config['provider_active'] ?? 'openai');
        $provider     = ProviderFactory::make($providerType);

        $conversation = [['role' => 'user', 'content' => $userQuery]];
        $selectedModel = trim((string)($this->config['provider_' . $providerType . '_model'] ?? ''));
        $aiConfig = [
            'api_url'  => $this->config['provider_' . $providerType . '_url'] ?? '',
            'api_key'  => $this->config['provider_' . $providerType . '_key'] ?? '',
            'ai_model' => $selectedModel
        ];

        // Kept for cost calculation in processAiResponse().
        $this->lastUsedModel = $selectedModel;
        $this->lastUsedProviderType = $providerType;

        // Technical: this hook runs synchronously inside the item_add hook,
        // i.e. directly in the ticket-creation request/response cycle
        // (unlike AI Level 1 Assistant, which queues its own AI call to a
        // background CronTask). A network/API exception from the provider
        // call must never propagate out of here, or it would break ticket
        // creation for the end user for a purely best-effort classification
        // feature.
        try {
            $result = $provider->call($systemPrompt, $conversation, $aiConfig);
        } catch (\Throwable $e) {
            Toolbox::logInFile('aisuite', "AI Smart Sorter Exception Ticket #{$ticketId}: " . $e->getMessage() . "\n");
            return;
        }

        if (empty($result['error']) && !empty($result['assistantText'])) {

            /* Technical: Retrieve token usage metrics */
            $usage = $result['usage'] ?? [];

            $this->processAiResponse($ticket, $result['assistantText'], $userQuery, $assetsMap, $categoriesMap, $usage);

        } else {
            Toolbox::logInFile('aisuite', __("AI Error: ", 'aisuite') . ($result['error'] ?? 'Unknown') . "\n");
        }
    }

    /* Technical: Robust JSON extraction and processing of AI response */
    private function processAiResponse(Ticket $ticket, $stringResponse, $originalInput, $assetsMap, $categoriesMap, $usage = []) {
        global $DB;

        $extraction = JsonResponseExtractor::extract((string)$stringResponse);
        $data       = $extraction['data'];
        $cleanJson  = $extraction['cleanJson'];

        if ($data === null) {
            Toolbox::logInFile('aisuite', "JSON Decode Error Ticket #{$ticket->getID()}: " . $extraction['error'] . " | Raw: " . substr(trim((string)$stringResponse), 0, 150) . "\n");
            return;
        }

        // Technical: never trust detected_hardware_id/type directly from the
        // AI's raw JSON output - a successful prompt injection in the ticket
        // content could otherwise fabricate an arbitrary item ID/type here.
        // These two fields may only ever be set below, via the assetsMap
        // lookup keyed on the AI-suggested hardware NAME, which is itself
        // restricted to assets actually owned by the ticket's requester
        // (see getRequesterAssets()).
        unset($data['detected_hardware_id'], $data['detected_hardware_type']);

        // Fields the requester filled in are never overridden, and the AI cannot
        // claim otherwise: this list is computed server-side only.
        $data['locked_fields'] = array_keys(self::getUserSetFields($ticket)['values'] ?? []);
        unset($data['suggested_priority'], $data['priority_confidence']);

        $confidence = (int)($data['confidence_score'] ?? 0);
        $detectedName = $data['detected_hardware_name'] ?? null;

        /* Technical: Map AI detected asset name to database ID and Type */
        if ($detectedName && isset($assetsMap[$detectedName])) {
            $data['detected_hardware_id']      = $assetsMap[$detectedName]['id'];
            $data['detected_hardware_type']    = $assetsMap[$detectedName]['type'];
            $data['detected_hardware_display'] = $assetsMap[$detectedName]['translated_label'];
        }

        // Persist the server-side computed fields (locked_fields, hardware mapping).
        $cleanJson = json_encode($data, JSON_UNESCAPED_UNICODE);

        /* Technical: Real-time cost calculation based on the admin-configured price */
        $costData = CostCalculator::compute($this->lastUsedProviderType, $this->config, $usage);

        $executionCost = $costData['cost'];
        $totalTokens   = $costData['tokens'];

        /* Technical: Automated classification logic based on confidence threshold */
        $autoMode  = (bool)($this->config['sorter_enable_auto_mode'] ?? 0);
        $threshold = (int)($this->config['sorter_confidence_threshold'] ?? 80);
        $action    = 'suggestion_only';

        if ($autoMode) {
            $appliedValues = $this->applyChangesDirectly($ticket, $data, $categoriesMap, $threshold, $confidence >= $threshold);
            $applied       = array_keys($appliedValues);
            if (!empty($applied)) {
                // Fields applied automatically are remembered so the suggestion
                // modal can flag them if some other fields stayed below threshold.
                // Values are kept too: the priority actually applied (from the applied
                // urgency/impact only) can differ from the one derived from the full
                // suggestion, and the modal must not flag it as applied then.
                $data['auto_applied_fields'] = $applied;
                $data['auto_applied_values'] = $appliedValues;
                $cleanJson = json_encode($data, JSON_UNESCAPED_UNICODE);
                Toolbox::logInFile('aisuite', sprintf(__("Auto-Applied changes for Ticket #%d (Score: %d%%)", 'aisuite'), $ticket->getID(), $confidence) . "\n");
                // Everything suggested was applied: nothing left to review.
                if (count($applied) >= count(self::resolveSuggestions($data, $categoriesMap, $ticket))) {
                    $action = 'auto_applied';
                }
            }
        }

        /* Technical: Audit trail logging */
        $DB->insert('glpi_plugin_aismartsorter_logs', [
            'tickets_id'       => $ticket->fields['id'],
            'input_data'       => $originalInput,
            'ai_response'      => $cleanJson,
            'confidence_score' => $confidence,
            'action_taken'     => $action,
            'execution_cost'   => $executionCost,
            'token_usage'      => $totalTokens
        ]);
    }

    /* Technical: Scales offered to the AI and accepted back (id => label).
     * Urgency/impact honour the instance's urgency_mask/impact_mask; priority
     * excludes 6 ("Major"), which is reserved to humans. */
    public static function getScale($field) {
        global $CFG_GLPI;
        $scale = [];
        for ($i = 1; $i <= 5; $i++) {
            if ($field === 'urgency') {
                if (!empty($CFG_GLPI['urgency_mask']) && !($CFG_GLPI['urgency_mask'] & (1 << $i))) continue;
                $scale[$i] = Ticket::getUrgencyName($i);
            } elseif ($field === 'impact') {
                if (!empty($CFG_GLPI['impact_mask']) && !($CFG_GLPI['impact_mask'] & (1 << $i))) continue;
                $scale[$i] = Ticket::getImpactName($i);
            } else {
                $scale[$i] = Ticket::getPriorityName($i);
            }
        }
        return $scale;
    }

    /* Technical: Which of urgency/impact/priority did the requester really fill in?
     * GLPI always stores a value, so "left untouched" can only be detected as
     * "still equal to the default" (Medium). Priority counts as user-set when it
     * was changed by hand (differs from what the matrix gives for the stored
     * urgency/impact). Returns ['values' => [field => id]], empty if nothing set. */
    private static function getUserSetFields(Ticket $ticket) {
        $defaults = Ticket::getDefaultValues();
        $values   = [];
        foreach (['urgency', 'impact'] as $field) {
            $current = (int)($ticket->fields[$field] ?? 0);
            if ($current > 0 && $current !== (int)($defaults[$field] ?? 3)) {
                $values[$field] = $current;
            }
        }
        $priority = (int)($ticket->fields['priority'] ?? 0);
        if ($priority > 0 && $priority !== (int)Ticket::computePriority((int)($ticket->fields['urgency'] ?? 3), (int)($ticket->fields['impact'] ?? 3))) {
            $values['priority'] = $priority;
        }
        return empty($values) ? [] : ['values' => $values];
    }

    /* Technical: Validate the AI suggestions against what was actually offered
     * (never trusting raw IDs from the AI JSON, e.g. after a prompt injection).
     * Returns [field => ['value' => int, 'confidence' => 0-100, 'column' => ticket column]].
     * - A field without its own '*_confidence' falls back to the overall score,
     *   so suggestions logged by older versions keep working.
     * - Fields in 'locked_fields' (set by the requester) are skipped.
     * - Urgency is capped to the admin-configured maximum (clamped, not dropped:
     *   in auto mode nobody reviews it).
     * - Priority is never taken from the AI: it is computed from the final
     *   urgency/impact with GLPI's matrix; its confidence is the lowest of the two. */
    public static function resolveSuggestions(array $aiData, ?array $categoriesMap = null, ?Ticket $ticket = null) {
        if ($categoriesMap === null) {
            $categoriesMap = self::getGLPICategories();
        }
        $locked = (array)($aiData['locked_fields'] ?? []);
        $candidates = [
            'category' => ['itilcategories_id', $aiData['suggested_category_id'] ?? 0, $categoriesMap],
            'type'     => ['type',              $aiData['suggested_type_id'] ?? 0,     self::getTicketTypes()],
            'urgency'  => ['urgency',           $aiData['suggested_urgency'] ?? 0,     self::getScale('urgency')],
            'impact'   => ['impact',            $aiData['suggested_impact'] ?? 0,      self::getScale('impact')],
        ];
        $out = [];
        foreach ($candidates as $field => [$column, $value, $allowed]) {
            $value = (int)$value;
            if (in_array($field, $locked, true) || $value <= 0 || !isset($allowed[$value])) continue;
            $confidence = $aiData[$field . '_confidence'] ?? $aiData['confidence_score'] ?? 0;
            $out[$field] = [
                'value'      => $value,
                'confidence' => max(0, min(100, (int)$confidence)),
                'column'     => $column,
            ];
        }

        $maxUrgency = (int)(PluginConfig::get()['sorter_max_urgency'] ?? 0);
        if ($maxUrgency > 0 && isset($out['urgency']) && $out['urgency']['value'] > $maxUrgency) {
            $out['urgency']['value'] = $maxUrgency;
        }

        if ($ticket !== null && !in_array('priority', $locked, true) && (isset($out['urgency']) || isset($out['impact']))) {
            $out['priority'] = [
                'value'      => (int)Ticket::computePriority(
                    $out['urgency']['value'] ?? (int)$ticket->fields['urgency'],
                    $out['impact']['value'] ?? (int)$ticket->fields['impact']
                ),
                'confidence' => min(array_column(array_intersect_key($out, ['urgency' => 1, 'impact' => 1]), 'confidence')),
                'column'     => 'priority',
            ];
        }
        return $out;
    }

    /* Technical: Build the Ticket update from the validated suggestions.
     * $threshold = null applies every suggested field (manual "Apply" click);
     * otherwise only fields whose own confidence reaches it are applied.
     * Priority is recomputed from what is actually applied (urgency/impact
     * applied now, the ticket's current value for the other one).
     * Returns ['fields' => [column => value], 'applied' => [field, ...], 'values' => [field => value]]. */
    public static function buildTicketUpdate(Ticket $ticket, array $aiData, ?array $categoriesMap = null, $threshold = null) {
        $fields  = [];
        $applied = [];
        $values  = [];
        foreach (self::resolveSuggestions($aiData, $categoriesMap, $ticket) as $field => $s) {
            if ($field === 'priority') continue;
            if ($threshold !== null && $s['confidence'] < $threshold) continue;
            $fields[$s['column']] = $s['value'];
            $values[$field]       = $s['value'];
            $applied[]            = $field;
        }
        if (!in_array('priority', (array)($aiData['locked_fields'] ?? []), true) && (isset($fields['urgency']) || isset($fields['impact']))) {
            $fields['priority'] = (int)Ticket::computePriority(
                $fields['urgency'] ?? (int)$ticket->fields['urgency'],
                $fields['impact'] ?? (int)$ticket->fields['impact']
            );
            $values['priority'] = $fields['priority'];
            $applied[]          = 'priority';
        }
        return ['fields' => $fields, 'applied' => $applied, 'values' => $values];
    }

    /* Technical: Apply the ITIL classification (category, type, urgency, impact,
     * priority - each only if its own confidence reaches $threshold) and the
     * hardware link (only if the overall confidence does, $linkHardware) via
     * the GLPI Ticket API. $categoriesMap is the exact set of helpdesk-visible
     * categories that was actually offered to the AI (see getGLPICategories()).
     * Returns the values applied, as [field => value]. */
    private function applyChangesDirectly(Ticket $ticket, $aiData, array $categoriesMap = [], $threshold = 80, $linkHardware = true) {
        $ticketId = $ticket->getID();

        $update  = self::buildTicketUpdate($ticket, $aiData, $categoriesMap, $threshold);
        $applied = $update['applied'];
        if (!empty($update['fields'])) {
            // Technical: use a separate Ticket object, never $ticket itself. $ticket is
            // the object being added: CommonDBTM::add() checks $this->input['_add']
            // right after the item_add hooks to clear the creation form data kept
            // in session. update() overwrites ->input on the object it is called on,
            // so using $ticket here would skip that cleanup and the ticket form would
            // redisplay the stale creation values (needing a reload to show ours).
            $updater = new Ticket();
            $updater->update(['id' => $ticketId] + $update['fields']);
        }

        $classificationApplied = !empty($applied);
        $suggestions           = self::resolveSuggestions($aiData, $categoriesMap, $ticket);

        // The hardware type comes from AI-generated JSON: only ever instantiate
        // one of the explicitly whitelisted item types, never an arbitrary class.
        $allowedHardwareTypes = ['Computer', 'Monitor', 'Printer', 'Phone', 'Peripheral'];

        $hardwareLinked = false;
        $hardwareLink   = '';
        if (
            $linkHardware
            && !empty($aiData['detected_hardware_id'])
            && !empty($aiData['detected_hardware_type'])
            && in_array($aiData['detected_hardware_type'], $allowedHardwareTypes, true)
            && class_exists($aiData['detected_hardware_type'])
        ) {
            // Technical: revalidate the item actually belongs to the same
            // entity as the ticket before linking it. detected_hardware_id
            // is only ever populated from getRequesterAssets() (see
            // processAiResponse()), which already scopes to the requester's
            // own assets, but this check stays defense in depth against any
            // future change to that lookup weakening the guarantee.
            $item = new $aiData['detected_hardware_type']();
            $itemFound = $item->getFromDB($aiData['detected_hardware_id']);
            $sameEntity = $itemFound
                && \Session::haveAccessToEntity((int)($item->fields['entities_id'] ?? -1))
                && (int)($item->fields['entities_id'] ?? -1) === (int)$ticket->fields['entities_id'];

            if ($itemFound && $sameEntity) {
                $itemTicket = new Item_Ticket();
                $itemTicket->add([
                    'tickets_id' => $ticketId,
                    'itemtype'   => $aiData['detected_hardware_type'],
                    'items_id'   => $aiData['detected_hardware_id']
                ]);

                $hardwareLink = $item->getLink();
                $hardwareLinked = true;
            }
        }

        // Technical: one private task summarising everything done automatically.
        if ($classificationApplied || $hardwareLinked) {
            $task = new TicketTask();
            $task->add([
                'tickets_id' => $ticketId,
                'is_private' => 1,
                'content'    => self::buildAutoActionHtml($aiData, $update['values'], $suggestions, $categoriesMap, $hardwareLink),
                'users_id'   => Session::getLoginUserID() ?: 0,
                'state'      => Planning::DONE
            ]);
        }

        return $update['values'];
    }

    /* Technical: HTML of the private task posted after an automatic classification:
     * a title line, then one bullet per applied field with its own certainty (the
     * overall score is deliberately not shown: it no longer gates classification).
     * Only tags GLPI's rich-text sanitizer keeps (p, ul, li, strong, em). Priority
     * has no certainty of its own: it is derived from urgency/impact. */
    private static function buildAutoActionHtml(array $aiData, array $values, array $suggestions, array $categoriesMap, $hardwareLink) {
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $rows = [
            'category' => [__('Catégorie', 'aisuite'), fn($v) => $categoriesMap[$v] ?? ''],
            'type'     => [__('Type', 'aisuite'),      fn($v) => Ticket::getTicketTypeName($v)],
            'urgency'  => [__('Urgence', 'aisuite'),   fn($v) => Ticket::getUrgencyName($v)],
            'impact'   => [__('Impact', 'aisuite'),    fn($v) => Ticket::getImpactName($v)],
            'priority' => [__('Priorité', 'aisuite'),  fn($v) => Ticket::getPriorityName($v)],
        ];

        $items = '';
        foreach ($rows as $field => [$label, $format]) {
            if (!isset($values[$field])) continue;
            if ($field === 'priority') {
                $note = ' <em>(' . $e(__('calculée depuis urgence et impact', 'aisuite')) . ')</em>';
            } else {
                $note = isset($suggestions[$field]) ? ' <em>(' . (int)$suggestions[$field]['confidence'] . ' %)</em>' : '';
            }
            $items .= '<li><strong>' . $e($label) . '</strong> : ' . $e($format($values[$field])) . $note . '</li>';
        }
        if ($hardwareLink !== '') {
            // $hardwareLink comes from CommonDBTM::getLink(): already escaped HTML.
            $items .= '<li><strong>' . $e(__('Matériel lié', 'aisuite')) . '</strong> : ' . $hardwareLink . '</li>';
        }

        return '<p><strong>⚡ ' . $e(__('Action automatique AI Smart Sorter', 'aisuite')) . '</strong></p>'
            . '<ul>' . $items . '</ul>';
    }

    /* Technical: Fetch the ticket types available in this GLPI instance.
     * Read dynamically from Ticket::getTypes() so the values (and their
     * localized labels) always match the running instance, instead of being
     * hardcoded. A defensive fallback to the two standard GLPI core types
     * (Incident / Request) is kept in case the method is ever unavailable. */
    private static function getTicketTypes() {
        $types = [];
        if (class_exists('Ticket') && method_exists('Ticket', 'getTypes')) {
            foreach (\Ticket::getTypes() as $id => $name) {
                $id = (int)$id;
                if ($id > 0) {
                    $types[$id] = $name;
                }
            }
        }
        if (empty($types)) {
            $types = [
                \Ticket::INCIDENT_TYPE => __('Incident'),
                \Ticket::DEMAND_TYPE   => __('Request'),
            ];
        }
        return $types;
    }

    /* Technical: Fetch visible ITIL categories from DB (Limited to 150) */
    private static function getGLPICategories() {
        global $DB;
        $cats = [];
        if (!$DB->tableExists('glpi_itilcategories')) return [];
        $iterator = $DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['is_helpdeskvisible' => 1], 'ORDER' => 'completename']);
        foreach ($iterator as $row) { if(count($cats)>150) break; $cats[$row['id']] = $row['completename']; }
        return $cats;
    }

    /* Technical: Retrieve all assets currently assigned to the ticket requester */
    private function getRequesterAssets(Ticket $ticket) {
        global $DB;

        $users = $ticket->getUsers(\CommonITILActor::REQUESTER);
        if (empty($users)) return [];

        $userId = $users[0]['users_id'];
        $assetsMap = [];
        $itemTypes = ['Computer', 'Monitor', 'Printer', 'Phone', 'Peripheral'];

        foreach ($itemTypes as $type) {
            if (!class_exists($type)) continue;

            $item = new $type();
            $table = $item->getTable();

            if (!$DB->tableExists($table)) continue;
            $columns = $DB->listFields($table);
            if (!isset($columns['users_id'])) continue;

            $where = ['users_id' => $userId, 'is_deleted' => 0];
            if (isset($columns['is_template'])) $where['is_template'] = 0;

            $iterator = $DB->request(['SELECT' => ['id', 'name'], 'FROM' => $table, 'WHERE' => $where]);

            foreach ($iterator as $data) {
                $technicalKey = $type . " - " . $data['name'];
                $translatedType = $item->getTypeName(1);
                $translatedLabel = $translatedType . " - " . $data['name'];

                $assetsMap[$technicalKey] = [
                    'type' => $type,
                    'id'   => $data['id'],
                    'translated_label' => $translatedLabel
                ];
            }
        }
        return $assetsMap;
    }
}
