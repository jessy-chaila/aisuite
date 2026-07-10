<?php

use GlpiPlugin\Aisuite\SmartCheck\Suggestion;

// Disable error display to prevent invalid JSON output if PHP warnings occur
ini_set('display_errors', 0);
error_reporting(E_ALL);

include '../../../inc/includes.php';

header('Content-Type: application/json');

// --- Security: authentication, interface and CSRF checks ---
Session::checkLoginUser();

if (Session::getCurrentInterface() !== 'central' || !Session::haveRight('ticket', READ)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Accès non autorisé.', 'aisuite')]);
    exit;
}

// Defense in depth: the "AI Smart Check" tab is only registered on Ticket
// when the module is enabled, but this endpoint could still be hit directly.
$aisuiteConf = Config::getConfigurationValues('plugin:aisuite');
if (isset($aisuiteConf['smartcheck_enabled']) && !$aisuiteConf['smartcheck_enabled']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Module AI Smart Check désactivé.', 'aisuite')]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---------------------------------------------------------------------
// Fresh CSRF token, fetched by the Smart Check JS (ticket.class.php)
// right before each state-changing POST. GLPI 11's Kernel validates AND
// consumes the CSRF token before this script runs, so the single token
// embedded when the tab was rendered is only good for ONE request: once
// the "analyze_ticket" POST consumed it, the "Note"/save POST reused a
// dead token and the platform returned an HTML error page (parsed as a
// "Erreur réseau" in the browser), which a page refresh worked around.
// Minting a fresh token on demand (GET, not CSRF-guarded) immediately
// before each POST fixes this - same pattern as ajax.chat.php,
// ajax.level1.php and modal.form.php (Smart Sorter).
// ---------------------------------------------------------------------
if ($action === 'get_csrf_token') {
    echo json_encode(['csrf_token' => Session::getNewCSRFToken()]);
    exit;
}

if (!Session::validateCSRF($_POST)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Jeton CSRF invalide.', 'aisuite')]);
    exit;
}

// Basic Validation
if (!isset($_POST['tickets_id']) || $action === '') {
    echo json_encode(['success' => false, 'message' => __('Missing parameters.', 'aisuite')]);
    exit;
}

$ticketId = (int)$_POST['tickets_id'];

// Ensure the ticket exists and the current user can actually view it
$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Accès non autorisé à ce ticket.', 'aisuite')]);
    exit;
}

// 'update_content' persists checkbox state and 'save_note' writes a new
// ITILFollowup: both are write actions and must not be gated solely on
// the ticket READ right checked above (analyze_ticket only reads/re-runs
// the analysis, so canViewItem() stays sufficient for it).
if (in_array($action, ['update_content', 'save_note'], true) && !$ticket->canUpdateItem()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Droits insuffisants pour modifier ce ticket.', 'aisuite')]);
    exit;
}

try {
    if ($action === 'analyze_ticket') {
        // Run or Rerun Analysis
        $html = Suggestion::getAnalysisHtml($ticketId);
        echo json_encode(['success' => true, 'html' => $html]);

    } elseif ($action === 'update_content') {
        // Save checkbox state (Persistent Content)
        $content = $_POST['content'] ?? '';
        if (empty($content)) {
            throw new Exception(__('Empty content.', 'aisuite'));
        }

        // Update DB with HTML containing 'checked' attributes
        // (Suggestion::saveAnalysisToDb() sanitizes $content internally)
        $res = Suggestion::saveAnalysisToDb($ticketId, $content);
        echo json_encode(['success' => $res]);

    } elseif ($action === 'save_note') {
        // Export as Ticket Note (Suggestion::saveAsNote() sanitizes the
        // content internally)
        $content = $_POST['content'] ?? '';
        $result = Suggestion::saveAsNote($ticketId, $content);
        // Queue a native GLPI confirmation toast that will be rendered on the
        // page reload triggered by the JS right after this call, so the user
        // still gets feedback that the note was added.
        if (!empty($result['success'])) {
            Session::addMessageAfterRedirect(
                __('Note ajoutée au ticket.', 'aisuite'),
                true,
                INFO
            );
        }
        echo json_encode($result);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
