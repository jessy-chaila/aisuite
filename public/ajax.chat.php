<?php

/**
 * Plugin GLPI AI CHAT - File: public/ajax.chat.php
 * AJAX gateway for the chatbot: handles configuration retrieval, user info,
 * chat history management, and ticket creation.
 */

use GlpiPlugin\Aisuite\AiChat\Chat;

include('../../../inc/includes.php');

// Ensure user is logged in
Session::checkLoginUser();

header('Content-Type: application/json; charset=utf-8');

// Defense in depth: the chatbot bubble (chatbot.js) is only loaded when the
// module is enabled, but this endpoint could still be hit directly.
$aisuiteConf = Config::getConfigurationValues('plugin:aisuite');
if (isset($aisuiteConf['chat_enabled']) && !$aisuiteConf['chat_enabled']) {
    echo json_encode(['error' => __('Module AI Chatbot désactivé.', 'aisuite')]);
    exit;
}

// Accept the action from either GET (read-only actions) or POST (state-changing actions).
$action  = $_POST['action']  ?? $_GET['action']  ?? '';
$message = $_GET['message'] ?? '';

$chat = new Chat();

// ---------------------------------------------------------------------
// Fresh CSRF token, used by the frontend before state-changing actions
// (e.g. create_ticket) that must be sent as POST with this token.
// ---------------------------------------------------------------------
if ($action === 'get_csrf_token') {
   echo json_encode(['csrf_token' => Session::getNewCSRFToken()]);
   exit;
}

// ---------------------------------------------------------------------
// Reset conversation history in session.
// State-changing action, sent as POST. CSRF is already enforced upstream
// by GLPI's own Kernel (Glpi\Kernel\Listener\ControllerListener\
// CheckCsrfListener), which validates - and consumes - the token before
// this script ever runs. Re-validating the same token here a second time
// with Session::validateCSRF() would always fail (already consumed), so
// this endpoint intentionally does not duplicate that check.
// ---------------------------------------------------------------------
if ($action === 'reset_history') {
   unset($_SESSION['plugin_aisuite_chat_history']);
   unset($_SESSION['plugin_aisuite_chat_free_uses']); // Reset free usage counter
   echo json_encode(['success' => true]);
   exit;
}

// ---------------------------------------------------------------------
// Return display name and initials of the logged-in user
// ---------------------------------------------------------------------
if ($action === 'get_user') {
   $user = new User();
   $name = __('Vous', 'aisuite');
   $initials = 'VO';

   if ($user->getFromDB(Session::getLoginUserID())) {
      // GLPI fields: realname = Last Name, firstname = First Name
      $firstname = trim($user->fields['firstname'] ?? '');
      $lastname  = trim($user->fields['realname']  ?? '');

      if ($firstname !== '' || $lastname !== '') {
         // Display format: Firstname LASTNAME
         $full = trim($firstname . ' ' . mb_strtoupper($lastname, 'UTF-8'));
         if ($full !== '') {
            $name = $full;
         }

         // Generate initials
         $initials = '';
         if ($firstname !== '') {
            $initials .= mb_strtoupper(mb_substr($firstname, 0, 1, 'UTF-8'), 'UTF-8');
         }
         if ($lastname !== '') {
            $initials .= mb_strtoupper(mb_substr($lastname, 0, 1, 'UTF-8'), 'UTF-8');
         }
         if ($initials === '') {
            $initials = 'US';
         }
      }
   }

   echo json_encode([
      'name'      => $name,
      'initials'  => $initials,
   ]);
   exit;
}

// ---------------------------------------------------------------------
// Return UI configuration (icon, color, mode)
// ---------------------------------------------------------------------
if ($action === 'get_config') {
   $raw = Config::getConfigurationValues('plugin:aisuite');
   $config = [
      'bot_icon_type'       => $raw['chat_bot_icon_type'] ?? 'emoji',
      'bot_icon_text'       => $raw['chat_bot_icon_text'] ?? '',
      'bot_icon_image_url'  => $raw['chat_bot_icon_image_url'] ?? '',
      'bot_color'           => $raw['chat_bot_color'] ?? '',
      'bot_color_use_theme' => $raw['chat_bot_color_use_theme'] ?? 1,
   ];

   $icon_type = $config['bot_icon_type']      ?? 'emoji';
   $icon_text = trim((string)($config['bot_icon_text'] ?? ''));
   $icon_img  = trim((string)($config['bot_icon_image_url'] ?? ''));
   $color     = trim((string)($config['bot_color'] ?? ''));

   // Sanitize icon type
   if ($icon_type !== 'image' && $icon_type !== 'emoji') {
      $icon_type = 'emoji';
   }
   if ($icon_text === '') {
      $icon_text = '?';
   }

   $use_theme = !empty($config['bot_color_use_theme']);

   echo json_encode([
      'mode'                => 'full',
      'bot_icon_type'       => $icon_type,
      'bot_icon_text'       => $icon_text,
      'bot_icon_image_url'  => $icon_img,
      'bot_color'           => $color,
      'bot_color_use_theme' => $use_theme,
      'welcome_message'     => __('Bonjour, cet assistant vous aide à formuler et suivre vos demandes GLPI. Décrivez votre problème et il vous proposera des vérifications simples ou l\'ouverture d\'un ticket si nécessaire.', 'aisuite'),
      'header_title'        => __('Assistant GLPI', 'aisuite'),
      'header_subtitle'     => __('Support niveau 1', 'aisuite'),
      'input_placeholder'   => __('Décrivez votre problème...', 'aisuite'),
      'close_title'         => __('Fermer', 'aisuite'),
      'send_title'          => __('Envoyer', 'aisuite'),
   ]);
   exit;
}

// ---------------------------------------------------------------------
// Ticket creation from chatbot.
// State-changing action, sent as POST. CSRF is already enforced upstream
// by GLPI's own Kernel (see the comment on reset_history above) - by the
// time this code runs, the request has already passed that check.
// ---------------------------------------------------------------------
if ($action === 'create_ticket') {
   $question = $_POST['question'] ?? '';
   $answer   = $_POST['answer']   ?? '';
   $title    = $_POST['title']    ?? null;

   try {
      $res = $chat->createTicketFromChat($question, $answer, $title);
   } catch (\Throwable $e) {
      Toolbox::logInFile('aisuite', 'AI Chatbot create_ticket Exception: ' . $e->getMessage() . "\n");
      $res = ['success' => false, 'message' => __('Erreur lors de la création du ticket.', 'aisuite')];
   }
   echo json_encode($res);
   exit;
}

// ---------------------------------------------------------------------
// Standard Chat Message handling
// ---------------------------------------------------------------------

try {
   $response = $chat->handleMessage($message);
} catch (\Throwable $e) {
   Toolbox::logInFile('aisuite', 'AI Chatbot handleMessage Exception: ' . $e->getMessage() . "\n");
   $response = ['error' => __('Erreur système. Veuillez réessayer plus tard.', 'aisuite')];
}
echo json_encode($response);
