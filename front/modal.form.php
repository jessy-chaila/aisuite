<?php

include "../../../inc/includes.php";

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();

/* Technical: Security check to restrict access to Central interface only (Technicians/Admins) */
if (Session::getCurrentInterface() !== 'central') {
    echo json_encode(['success' => false, 'message' => __('Unauthorized access (Central interface required)', 'aisuite')]);
    exit;
}

// Defense in depth: the Smart Sorter JS/modal are only loaded when the module
// is enabled, but this endpoint could still be hit directly.
$aisuiteConf = Config::getConfigurationValues('plugin:aisuite');
if (isset($aisuiteConf['sorter_enabled']) && !$aisuiteConf['sorter_enabled']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Module AI Smart Sorter désactivé.', 'aisuite')]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---------------------------------------------------------------------
// Fresh CSRF token, fetched by smartsorter.js right before it POSTs
// dismiss_suggestion/apply_suggestion (same pattern as the chatbot's
// ajax.chat.php and Level1's ajax.level1.php). Minting it on demand,
// immediately before the actual write request, avoids reusing a token
// that may already have been consumed/rotated by other activity on the
// same ticket page (background polling, the automatic get_suggestion
// call, etc.).
// ---------------------------------------------------------------------
if ($action === 'get_csrf_token') {
    echo json_encode(['csrf_token' => Session::getNewCSRFToken()]);
    exit;
}

// CSRF only guards the state-changing actions (dismiss_suggestion,
// apply_suggestion). 'get_suggestion' is a pure read - already scoped by
// canViewItem() below - and is fired automatically by smartsorter.js on
// every ticket page load without a prefetched token (mirroring the
// original, pre-audit behavior); requiring CSRF for it made the popup
// depend on GLPI's session-wide CSRF token still being the one embedded
// when the page was rendered, which can get rotated out by other AJAX
// activity on the same page before this call fires.
if (in_array($action, ['dismiss_suggestion', 'apply_suggestion'], true) && !Session::validateCSRF($_POST)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Jeton CSRF invalide.', 'aisuite')]);
    exit;
}

$ticketId = (int)($_POST['tickets_id'] ?? 0);

if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'message' => __('Missing Ticket ID', 'aisuite')]);
    exit;
}

// Ensure the current user can actually view this specific ticket (not just the interface check above)
$checkTicket = new Ticket();
if (!$checkTicket->getFromDB($ticketId) || !$checkTicket->canViewItem()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Accès non autorisé à ce ticket.', 'aisuite')]);
    exit;
}

// 'dismiss_suggestion' and 'apply_suggestion' are write actions (they update
// the ticket's category, link hardware, create a task): canViewItem() above
// is not enough for those two - 'get_suggestion' stays read-only and keeps
// working for users who can only view the ticket.
if (in_array($action, ['dismiss_suggestion', 'apply_suggestion'], true) && !$checkTicket->canUpdateItem()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Droits insuffisants pour modifier ce ticket.', 'aisuite')]);
    exit;
}

global $DB;

// Whitelist of hardware item types this module is allowed to link/instantiate.
// The type comes from AI-generated JSON: never instantiate an arbitrary class name.
$allowedHardwareTypes = ['Computer', 'Monitor', 'Printer', 'Phone', 'Peripheral'];

/* Technical: Action to retrieve the latest AI suggestion for a specific ticket */
if ($action === 'get_suggestion') {

    /* Technical: Fetch analysis log including execution cost */
    $iterator = $DB->request([
        'SELECT' => ['id', 'ai_response', 'confidence_score', 'execution_cost'],
        'FROM'   => 'glpi_plugin_aismartsorter_logs',
        'WHERE'  => [
            'tickets_id'   => $ticketId,
            'action_taken' => 'suggestion_only'
        ],
        'ORDER'  => 'id DESC',
        'LIMIT'  => 1
    ]);

    if (count($iterator) === 0) {
        echo json_encode(['success' => false, 'has_suggestion' => false]);
        exit;
    }

    $row = $iterator->current();
    $aiData = json_decode($row['ai_response'], true);

    if (json_last_error() !== JSON_ERROR_NONE) {
         echo json_encode(['success' => false, 'has_suggestion' => false]);
         exit;
    }

    /* Technical: Resolve every suggested field (category, type, urgency, impact,
     * priority) with its own confidence. Labels come from GLPI itself, never from
     * the AI-provided names; category/type are always listed (even undetermined),
     * the other fields only if the AI proposed a valid value. */
    $sorterClass   = '\\GlpiPlugin\\Aisuite\\SmartSorter\\Sorter';
    $suggestions   = $sorterClass::resolveSuggestions($aiData, null, $checkTicket);
    $autoApplied   = (array)($aiData['auto_applied_fields'] ?? []);
    $appliedValues = (array)($aiData['auto_applied_values'] ?? []);
    $valueLabels   = [
        'type'     => static fn($v) => Ticket::getTicketTypeName($v),
        'urgency'  => static fn($v) => Ticket::getUrgencyName($v),
        'impact'   => static fn($v) => Ticket::getImpactName($v),
        'priority' => static fn($v) => Ticket::getPriorityName($v),
    ];
    $fieldTitles   = [
        'category' => __('Catégorie suggérée', 'aisuite'),
        'type'     => __('Type suggéré', 'aisuite'),
        'urgency'  => __('Urgence suggérée', 'aisuite'),
        'impact'   => __('Impact suggéré', 'aisuite'),
        'priority' => __('Priorité suggérée', 'aisuite'),
    ];
    $fields = [];
    foreach ($fieldTitles as $field => $title) {
        if (!isset($suggestions[$field]) && !in_array($field, ['category', 'type'], true)) {
            continue;
        }
        if ($field === 'category') {
            $valueName = isset($suggestions['category'])
                ? ($aiData['suggested_category_name'] ?? $aiData['suggested_category'] ?? null)
                : null;
        } elseif (isset($suggestions[$field])) {
            $valueName = $valueLabels[$field]($suggestions[$field]['value']);
        } else {
            $valueName = null;
        }
        $fields[] = [
            'key'        => $field,
            'label'      => $title,
            'value'      => $valueName,
            'confidence' => isset($suggestions[$field]) ? $suggestions[$field]['confidence'] : null,
            // Flagged only if what was applied is the suggested value (logs from before
            // auto_applied_values existed fall back to the field list).
            'applied'    => isset($appliedValues[$field])
                ? (isset($suggestions[$field]) && (int)$appliedValues[$field] === $suggestions[$field]['value'])
                : in_array($field, $autoApplied, true),
        ];
    }

    /* Technical: Hardware identification */
    $hardwareDisplay = $aiData['detected_hardware_display']
        ?? $aiData['detected_hardware_name']
        ?? $aiData['detected_hardware']
        ?? null;

    /* Technical: Execution cost formatting for UI display */
    $costRaw = isset($row['execution_cost']) ? (float)$row['execution_cost'] : 0.0;
    $costDisplay = '$' . number_format($costRaw, 5);

    $labels = [
        'title'          => __('Suggestion AI SmartSorter', 'aisuite'),
        'confidence'     => __('Certitude', 'aisuite'),
        'already_applied' => __('Déjà appliqué automatiquement', 'aisuite'),
        'hardware_found' => __('Matériel détecté', 'aisuite'),
        'hardware_none'  => __('Aucun matériel détecté', 'aisuite'),
        'btn_ignore'     => __('Ignorer', 'aisuite'),
        'btn_apply'      => __('Appliquer', 'aisuite'),
        'btn_applying'   => __('Application...', 'aisuite'),
        'not_determined' => __('Non déterminée', 'aisuite'),
        'cost_info'      => __('Coût estimé :', 'aisuite')
    ];

    echo json_encode([
        'success'        => true,
        'has_suggestion' => true,
        'log_id'         => $row['id'],
        'fields'         => $fields,
        // The model sometimes double-escapes quotes (\" shown literally): undo it.
        'reasoning'      => str_replace('\\"', '"', (string)($aiData['reasoning'] ?? '')),
        'confidence'     => $row['confidence_score'],
        'hardware'       => $hardwareDisplay,
        'hardware_enabled' => !isset($aisuiteConf['sorter_enable_hardware_linking']) || !empty($aisuiteConf['sorter_enable_hardware_linking']),
        'cost'           => $costDisplay,
        'labels'         => $labels
    ]);
    exit;
}

/* Technical: Action to mark a suggestion as dismissed by user */
if ($action === 'dismiss_suggestion') {
    $logId = (int)($_POST['log_id'] ?? 0);
    if ($logId > 0) {
        // Technical: scope the update to this specific ticket - without this,
        // a user able to view/update ticket A could pass the log_id of a
        // suggestion belonging to a different ticket B and dismiss it.
        $DB->update('glpi_plugin_aismartsorter_logs', ['action_taken' => 'dismissed_by_user'], [
            'id'         => $logId,
            'tickets_id' => $ticketId,
        ]);
    }
    echo json_encode(['success' => true]);
    exit;
}

/* Technical: Action to apply AI category and link hardware to the ticket */
if ($action === 'apply_suggestion') {
    $logId = (int)($_POST['log_id'] ?? 0);

    if ($logId <= 0) {
        echo json_encode(['success' => false, 'message' => __('Invalid Log ID', 'aisuite')]);
        exit;
    }

    // Technical: scope the lookup to this specific ticket - without this, a
    // user able to view/update ticket A could pass the log_id of a
    // suggestion belonging to a different ticket B and apply it to A.
    $iterator = $DB->request(['FROM' => 'glpi_plugin_aismartsorter_logs', 'WHERE' => [
        'id'         => $logId,
        'tickets_id' => $ticketId,
    ]]);

    if (count($iterator) === 0) {
        echo json_encode(['success' => false, 'message' => __('Log not found', 'aisuite')]);
        exit;
    }

    $row = $iterator->current();
    $aiData = json_decode($row['ai_response'], true);

    /* Technical: Apply the suggested ITIL classification (category, type,
     * urgency, impact, priority). Every value is re-validated against what the
     * instance actually offers (helpdesk-visible categories, Ticket types,
     * urgency/impact masks) - never trusted as an arbitrary ID from the stored
     * AI JSON, in case a prompt injection altered it when it was generated. */
    $sorterClass  = '\\GlpiPlugin\\Aisuite\\SmartSorter\\Sorter';
    $ticketUpdate = ['id' => $ticketId] + $sorterClass::buildTicketUpdate($checkTicket, $aiData)['fields'];

    if (count($ticketUpdate) > 1) {
        $ticket = new Ticket();
        $ticket->update($ticketUpdate);
    }

    /* Technical: Link hardware items and create private task.
     * The hardware type comes from AI-generated JSON: only ever instantiate
     * one of the explicitly whitelisted item types, never an arbitrary class. */
    $hardwareLinkingEnabled = !isset($aisuiteConf['sorter_enable_hardware_linking']) || !empty($aisuiteConf['sorter_enable_hardware_linking']);
    if (
        $hardwareLinkingEnabled
        && !empty($aiData['detected_hardware_id'])
        && !empty($aiData['detected_hardware_type'])
        && in_array($aiData['detected_hardware_type'], $allowedHardwareTypes, true)
        && class_exists($aiData['detected_hardware_type'])
    ) {
        // Technical: revalidate the item belongs to the same entity as the
        // ticket before linking it - defense in depth against a stored
        // suggestion referencing an item outside the ticket's entity scope.
        $item = new $aiData['detected_hardware_type']();
        $itemFound = $item->getFromDB($aiData['detected_hardware_id']);
        $sameEntity = $itemFound
            && Session::haveAccessToEntity((int)($item->fields['entities_id'] ?? -1))
            && (int)($item->fields['entities_id'] ?? -1) === (int)$checkTicket->fields['entities_id'];

        if ($itemFound && $sameEntity) {
            $itemTicket = new Item_Ticket();
            $itemTicket->add([
                'tickets_id' => $ticketId,
                'itemtype'   => $aiData['detected_hardware_type'],
                'items_id'   => $aiData['detected_hardware_id']
            ]);

            $link = $item->getLink();
            $taskContent = sprintf(
                __('Matériel : %s ajouté dans l\'onglet Éléments.', 'aisuite'),
                $link
            );

            $task = new TicketTask();
            $task->add([
                'tickets_id' => $ticketId,
                'is_private' => 1,
                'content'    => $taskContent,
                'users_id'   => Session::getLoginUserID(),
                'state'      => Planning::DONE
            ]);
        }
    }

    /* Technical: Finalize log entry with manual application status */
    $DB->update(
        'glpi_plugin_aismartsorter_logs',
        ['action_taken' => 'applied_by_user'],
        ['id' => $logId, 'tickets_id' => $ticketId]
    );

    echo json_encode(['success' => true, 'message' => __('Ticket updated', 'aisuite')]);
    exit;
}

echo json_encode(['success' => false, 'message' => __('Unknown Action', 'aisuite')]);
