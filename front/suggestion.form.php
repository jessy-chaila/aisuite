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

if (!Session::validateCSRF($_POST)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Jeton CSRF invalide.', 'aisuite')]);
    exit;
}

// Basic Validation
if (!isset($_POST['tickets_id']) || !isset($_POST['action'])) {
    echo json_encode(['success' => false, 'message' => __('Missing parameters.', 'aisuite')]);
    exit;
}

$action = $_POST['action'];
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
        echo json_encode($result);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
