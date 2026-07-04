<?php

/**
 * Plugin AI Suite - File: public/ajax.level1.php
 * AJAX endpoint for the "AI Level 1 Assistant" module: lets an end user
 * (Helpdesk / self-service interface) explicitly opt out of AI handling and
 * request to be taken in charge by a human technician directly.
 */

use GlpiPlugin\Aisuite\Level1\Assistant;

// Disable error display to prevent invalid JSON output if PHP warnings occur
ini_set('display_errors', 0);
error_reporting(E_ALL);

include '../../../inc/includes.php';

header('Content-Type: application/json');

Session::checkLoginUser();

// Defense in depth: the opt-out button is only injected when the module is
// enabled, but this endpoint could still be hit directly.
$aisuiteConf = Config::getConfigurationValues('plugin:aisuite');
if (isset($aisuiteConf['level1_enabled']) && !$aisuiteConf['level1_enabled']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Module AI Level 1 Assistant désactivé.', 'aisuite')]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---------------------------------------------------------------------
// Fresh CSRF token, fetched by level1.js before it POSTs the actual
// "request_human" action (same pattern as the chatbot's ajax.chat.php).
// ---------------------------------------------------------------------
if ($action === 'get_csrf_token') {
    // If the AI has already been permanently stepped back on this specific
    // ticket (manual opt-out or a technician taking over directly), the
    // button no longer serves any purpose: report it so level1.js can simply
    // not display it, instead of showing a control that would just error out
    // if clicked again.
    $ticketIdForStatus = (int)($_GET['tickets_id'] ?? 0);
    $aiDisabled = false;
    if ($ticketIdForStatus > 0) {
        $statusTicket = new Ticket();
        if ($statusTicket->getFromDB($ticketIdForStatus) && $statusTicket->canViewItem()) {
            $aiDisabled = (new Assistant())->isAiDisabledForTicket($ticketIdForStatus);
        }
    }

    echo json_encode([
        'csrf_token'  => Session::getNewCSRFToken(),
        'ai_disabled' => $aiDisabled,
        'labels'      => [
            'button'  => __('Désactiver l\'IA', 'aisuite'),
            'confirm' => __('Voulez-vous être pris en charge directement par un technicien humain, sans passer par l\'assistant IA ?', 'aisuite'),
            'done'    => __('Votre demande a été transmise à un technicien.', 'aisuite'),
            'error'   => __('Une erreur est survenue, réessayez plus tard.', 'aisuite'),
        ],
    ]);
    exit;
}

if (!Session::validateCSRF($_POST)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Jeton CSRF invalide.', 'aisuite')]);
    exit;
}

$ticketId = (int)($_POST['tickets_id'] ?? 0);

if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'message' => __('Missing Ticket ID', 'aisuite')]);
    exit;
}

// Ensure the current user can actually view this specific ticket.
$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Accès non autorisé à ce ticket.', 'aisuite')]);
    exit;
}

if ($action !== 'request_human') {
    echo json_encode(['success' => false, 'message' => __('Unknown Action', 'aisuite')]);
    exit;
}

$assistant = new Assistant();

// This followup is a deliberate, explicit human action (the requester clicking
// "Disable AI"), not an AI-generated message, so it is correctly attributed
// to the logged-in user rather than posted as the assistant (users_id = 0).
$fup = new ITILFollowup();
$fup->add([
    'itemtype'   => 'Ticket',
    'items_id'   => $ticketId,
    'content'    => '<strong>' . __("🙋 L'utilisateur a demandé à être pris en charge directement par un technicien.", 'aisuite') . '</strong>',
    'is_private' => 0,
    'users_id'   => Session::getLoginUserID() ?: 0,
]);

// Opting out never assigns the configured escalation group: it only removes
// the "Assistant IA" group assignment, leaving the ticket unassigned
// ("Incoming tickets") so it doesn't look like it's still being handled by
// anyone until a technician picks it up. Also stops the AI from resuming
// questioning this ticket afterwards.
$assistant->markUserDeclined($ticketId);

echo json_encode(['success' => true, 'message' => __('Votre demande a été transmise à un technicien.', 'aisuite')]);
