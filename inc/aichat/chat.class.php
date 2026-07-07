<?php

/**
 * Plugin GLPI AI CHAT - File: chat.class.php
 * Main business logic for handling chat messages, AI calls, and ticket creation.
 */

namespace GlpiPlugin\Aisuite\AiChat;

use Config;
use Session;
use Ticket;
use Toolbox;
use GlpiPlugin\Aisuite\AiChat\Provider\Claude;
use GlpiPlugin\Aisuite\AiChat\Provider\OpenAI as OpenAIProvider;
use GlpiPlugin\Aisuite\AiChat\Provider\Gemini;
use GlpiPlugin\Aisuite\Shared\PluginConfig;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class Chat {

   /**
    * Retrieves the plugin configuration from the GLPI Config table.
    * * @return array
    */
   private function getConfig(): array {
      $raw = PluginConfig::get();

      // AI Chatbot follows the AI Suite-wide active provider (Providers tab).
      $provider = $raw['provider_active'] ?? 'openai';
      if (!in_array($provider, ['openai', 'anthropic', 'google'], true)) {
         $provider = 'openai';
      }

      return [
         'support_phone' => $raw['chat_support_phone'] ?? '',
         'ai_api_url'    => $raw['provider_' . $provider . '_url'] ?? '',
         'ai_api_key'    => $raw['provider_' . $provider . '_key'] ?? '',
         'ai_model'      => $raw['provider_' . $provider . '_model'] ?? '',
         'system_prompt' => $raw['chat_system_prompt'] ?? '',
         'ai_provider'   => $provider,
      ];
   }

   /**
    * Processes a user message and returns the structure expected by the JS frontend.
    * * @param string $message User input
    * @return array
    */
   public function handleMessage(string $message): array {
      $config = $this->getConfig();

      if (trim($message) === '') {
         return [
            'answer'        => __('Merci de préciser votre question.', 'aisuite'),
            'needs_ticket'  => false,
            'suggest_call'  => false,
            'support_phone' => $config['support_phone'] ?? null,
            'ticket_title'  => null,
         ];
      }

      // Conversation history stored in PHP session
      // Format: [ ['role'=>'user','content'=>'...'], ['role'=>'assistant','content'=>'...'], ... ]
      $history = $_SESSION['plugin_aisuite_chat_history'] ?? [];

      // AI Call with current message + history
      $aiResponse   = $this->callAI($message, $config, $history);
      $answer       = $aiResponse['answer']       ?? __('Je n’ai pas pu générer de réponse.', 'aisuite');
      $needs_ticket = (bool) ($aiResponse['needs_ticket'] ?? false);
      $suggest_call = (bool) ($aiResponse['suggest_call'] ?? false);
      $ticket_title = $aiResponse['ticket_title'] ?? null;

      // Update history (text displayed to the user)
      $history[] = [
         'role'    => 'user',
         'content' => $message,
      ];
      $history[] = [
         'role'    => 'assistant',
         'content' => $answer,
      ];

      // Keep only the last 10 messages (5 user/assistant turns)
      $_SESSION['plugin_aisuite_chat_history'] = array_slice($history, -10);

      return [
         'answer'        => $answer,
         'needs_ticket'  => $needs_ticket,
         'suggest_call'  => $suggest_call,
         'support_phone' => $config['support_phone'] ?? null,
         'ticket_title'  => $ticket_title,
      ];
   }

   /**
    * Calls the AI engine based on the current configuration.
    *
    * Expected return is a strict JSON:
    * {
    * "answer": "user text",
    * "needs_ticket": true/false,
    * "suggest_call": true/false,
    * "ticket_title": "short title"
    * }
    * * @param string $message Current message
    * @param array  $config Plugin configuration
    * @param array  $history Conversation history
    * @return array
    */
   private function callAI(string $message, array $config, array $history = []): array {
      $url      = $config['ai_api_url']   ?? '';
      $key      = $config['ai_api_key']   ?? '';
      $model    = trim((string)($config['ai_model']     ?? ''));
      $provider = trim((string)($config['ai_provider'] ?? 'openai'));

      // Missing service configuration
      if ($url === '' || $key === '' || $model === '') {
         return [
            'answer'       => __('Le service d’assistance automatique n’est pas configuré (URL, clé API ou modèle IA manquant). Veuillez contacter votre administrateur.', 'aisuite'),
            'needs_ticket' => true,
            'suggest_call' => true,
            'ticket_title' => null,
         ];
      }

      // Readable labels for error reporting
      $providerLabels = [
         'anthropic' => 'Claude (Anthropic)',
         'openai'    => 'OpenAI (compatible)',
         'google'    => 'Gemini (Google)',
      ];
      $providerLabel = $providerLabels[$provider] ?? $provider;

      // ------------------------------------------------------------------
      // Common system prompt for all providers
      // ------------------------------------------------------------------
      $baseSystemPrompt = <<<TXT
Tu es un assistant de support informatique de niveau 1 intégré à GLPI.

TON RÔLE
- Tu aides les utilisateurs finaux à diagnostiquer et résoudre des problèmes simples.
- Tu peux aussi décider qu’un ticket doit être créé ou qu’un appel téléphonique est préférable.

LANGUE
- Tu réponds uniquement en français, de manière claire, concise et professionnelle.

CONTEXTE
- Tu as accès à l'historique de la conversation (messages précédents).
- Tu dois en tenir compte pour enchaîner logiquement : ne recommence pas par un message de bienvenue si la conversation est déjà en cours.

PÉRIMÈTRE DU SUPPORT NIVEAU 1
- Tu peux proposer uniquement des vérifications simples que tout utilisateur peut réaliser sans droits administrateur ni compétences techniques avancées.
- Exemples d’actions AUTORISÉES :
  - vérifier que l’application est bien lancée / fermée puis relancée ;
  - vérifier les câbles / la connexion réseau de base (Wi-Fi activé, câble branché) ;
  - vérifier les identifiants de connexion (login/mot de passe) ;
  - vérifier l’espace disque libre dans l’interface graphique ;
  - demander une capture d’écran ou une description exacte du message d’erreur ;
  - proposer de redémarrer l’application ou l’ordinateur une fois.
- Si le problème nécessite des actions plus avancées, tu NE DONNES PAS les détails techniques, tu expliques simplement que cela dépasse le niveau 1 et tu proposes la création d’un ticket.

ACTIONS INTERDITES (NIVEAU 1)
- Tu NE DOIS PAS proposer :
  - l’édition de la base de registre ou de fichiers système ;
  - le mode sans échec, les options de démarrage avancées, msconfig, services système ;
  - l’analyse de journaux système ou de journaux applicatifs détaillés ;
  - la réinstallation complète d’un logiciel ou du système d’exploitation ;
  - la modification de règles de firewall, proxy, antivirus ou politique de sécurité ;
  - toute action nécessitant des droits administrateur ou un accès serveur.
- Si ce type d’action serait normalement nécessaire, tu le signales simplement (sans donner les procédures) et tu mets "needs_ticket" = true.

FORMAT DE SORTIE (OBLIGATOIRE)
- Tu DOIS répondre en JSON strict, SANS aucun autre texte avant ou après.
- Le JSON doit respecter exactement cette structure :

{
  "answer": "réponse texte pour l'utilisateur, en français",
  "needs_ticket": true ou false,
  "suggest_call": true ou false,
  "ticket_title": "titre court pour le ticket ou \"\" si aucun ticket n'est nécessaire"
}

- N’ajoute AUCUN autre champ dans le JSON.
- N’ajoute pas de balises de code (par exemple ```json ou ```).
- Ne mets AUCUN commentaire dans le JSON.
- Le JSON doit être valide et parseable.

RÈGLES MÉTIER

1) Champ "answer"
- Contient la réponse destinée à l’utilisateur final.
- Explique clairement quoi faire, avec des étapes simples si nécessaire.
- Reste factuel, sans promesses irréalistes.
- Ne détaille PAS des procédures techniques avancées (logs, mode sans échec, réinstallation, etc.). Dans ces cas, oriente vers un ticket.

2) Champ "needs_ticket"
- Mets "needs_ticket" = true si au moins une des conditions suivantes est vraie :
  - le problème semble complexe ou nécessite une analyse approfondie ;
  - tu ne peux pas résoudre le problème avec des instructions simples de niveau 1 ;
  - il manque des informations importantes pour traiter la demande ;
  - cela touche des droits / accès / sécurité / pannes globales ou impacts forts.
- Sinon, mets "needs_ticket" = false.

3) Champ "suggest_call"
- Mets "suggest_call" = true si :
  - la situation est urgente (plus de production, panne totale, incident sécurité) ;
  - l'utilisateur semble perdu malgré tes explications ;
  - tu estimes qu’un échange téléphonique serait beaucoup plus efficace.
- Sinon, mets "suggest_call" = false.

4) Champ "ticket_title"
- Si "needs_ticket" = true, tu DOIS générer un titre COURT ET CLAIR qui résume le problème, par exemple :
  - "Problème d'export PDF avec Alizée"
  - "Blocage à l'ouverture de Outlook"
  - "Impossible d'imprimer sur l'imprimante BOCCA"
- Le titre NE doit PAS :
  - contenir de phrase complète (pas de "Bonjour", pas de formules de politesse) ;
  - contenir de date, de numéro de ticket, ni le mot "ticket".
- Si "needs_ticket" = false, mets "ticket_title" = "" (chaîne vide).

RAPPEL IMPORTANT
- Tu dois renvoyer UNIQUEMENT le JSON brut, sans texte, sans explication, sans mise en forme autour.
- Ne renvoie pas plusieurs objets JSON : un seul objet, une seule fois.
TXT;

      $systemPrompt = $baseSystemPrompt;
      if (!empty($config['system_prompt'])) {
         $systemPrompt .= "\n\nContexte supplémentaire fourni par le client :\n" . $config['system_prompt'];
      }

      // ------------------------------------------------------------------
      // History normalization: array of user/assistant turns
      // ------------------------------------------------------------------
      $conversation = [];

      foreach ($history as $turn) {
         if (!isset($turn['role'], $turn['content'])) {
            continue;
         }
         $role    = ($turn['role'] === 'assistant') ? 'assistant' : 'user';
         $content = trim((string)$turn['content']);
         if ($content === '') {
            continue;
         }
         $conversation[] = [
            'role'    => $role,
            'content' => $content,
         ];
      }

      // Add current user message
      $conversation[] = [
         'role'    => 'user',
         'content' => $message,
      ];

      // ------------------------------------------------------------------
      // API call based on the selected provider
      // ------------------------------------------------------------------
      $assistantText = null;

      switch ($provider) {
         case 'anthropic': {
            $claudeProvider = new Claude();
            $resultProvider = $claudeProvider->call($systemPrompt, $conversation, $config);

            if (!empty($resultProvider['error'])) {
               if ($resultProvider['error'] === 'communication') {
                  return [
                     'answer'       => __('Erreur de communication avec le moteur IA (Claude).', 'aisuite'),
                     'needs_ticket' => true,
                     'suggest_call' => true,
                     'ticket_title' => null,
                  ];
               }

               if ($resultProvider['error'] === 'format') {
                  return [
                     'answer'       => __('Réponse IA invalide (format non JSON) pour Claude.', 'aisuite'),
                     'needs_ticket' => true,
                     'suggest_call' => true,
                     'ticket_title' => null,
                  ];
               }

               return [
                  'answer'       => __('Erreur lors de l’appel au moteur IA (Claude).', 'aisuite'),
                  'needs_ticket' => true,
                  'suggest_call' => true,
                  'ticket_title' => null,
               ];
            }

            $assistantText = $resultProvider['assistantText'] ?? null;
            break;
         }

         case 'openai': {
            $providerObj    = new OpenAIProvider();
            $resultProvider = $providerObj->call($systemPrompt, $conversation, $config);

            if (!empty($resultProvider['error'])) {
               if ($resultProvider['error'] === 'communication') {
                  return [
                     'answer'       => sprintf(__('Erreur de communication avec le moteur IA (%s).', 'aisuite'), $providerLabel),
                     'needs_ticket' => true,
                     'suggest_call' => true,
                     'ticket_title' => null,
                  ];
               }

               if ($resultProvider['error'] === 'format') {
                  return [
                     'answer'       => sprintf(__('Réponse IA invalide (format non JSON) pour %s.', 'aisuite'), $providerLabel),
                     'needs_ticket' => true,
                     'suggest_call' => true,
                     'ticket_title' => null,
                  ];
               }

               return [
                  'answer'       => sprintf(__('Erreur lors de l’appel au moteur IA (%s).', 'aisuite'), $providerLabel),
                  'needs_ticket' => true,
                  'suggest_call' => true,
                  'ticket_title' => null,
               ];
            }

            $assistantText = $resultProvider['assistantText'] ?? null;
            break;
         }

         case 'google': {
            $providerObj    = new Gemini();
            $resultProvider = $providerObj->call($systemPrompt, $conversation, $config);

            if (!empty($resultProvider['error'])) {
               if ($resultProvider['error'] === 'communication') {
                  return [
                     'answer'       => sprintf(__('Erreur de communication avec le moteur IA (%s).', 'aisuite'), $providerLabel),
                     'needs_ticket' => true,
                     'suggest_call' => true,
                     'ticket_title' => null,
                  ];
               }

               if ($resultProvider['error'] === 'format') {
                  return [
                     'answer'       => sprintf(__('Réponse IA invalide (format non JSON) pour %s.', 'aisuite'), $providerLabel),
                     'needs_ticket' => true,
                     'suggest_call' => true,
                     'ticket_title' => null,
                  ];
               }

               if ($resultProvider['error'] === 'api_error') {
                  $apiMsg = trim((string)($resultProvider['assistantText'] ?? __('Erreur renvoyée par l’API distante.', 'aisuite')));
                  return [
                     'answer'       => sprintf(__('Erreur renvoyée par l’API %s : %s', 'aisuite'), $providerLabel, $apiMsg),
                     'needs_ticket' => true,
                     'suggest_call' => false,
                     'ticket_title' => null,
                  ];
               }

               return [
                  'answer'       => sprintf(__('Erreur lors de l’appel au moteur IA (%s).', 'aisuite'), $providerLabel),
                  'needs_ticket' => true,
                  'suggest_call' => true,
                  'ticket_title' => null,
               ];
            }

            $assistantText = $resultProvider['assistantText'] ?? null;
            break;
         }

         default:
            return [
               'answer'       => sprintf(__('Le fournisseur d’IA sélectionné (%s) n’est pas reconnu par cette version du plugin.', 'aisuite'), $providerLabel),
               'needs_ticket' => false,
               'suggest_call' => false,
               'ticket_title' => null,
            ];
      }

      // ------------------------------------------------------------------
      // Post-processing: interpretation of the business JSON
      // ------------------------------------------------------------------
      $assistantText = trim((string)$assistantText);

      if ($assistantText === '') {
         return [
            'answer'       => sprintf(__('Le moteur IA (%s) n’a renvoyé aucun contenu.', 'aisuite'), $providerLabel),
            'needs_ticket' => true,
            'suggest_call' => true,
            'ticket_title' => null,
         ];
      }

      // Attempt 1: direct JSON parse
      $json = json_decode($assistantText, true);

      // Attempt 2: strip possible markdown code blocks if parsing failed
      if (!is_array($json)) {
         if (preg_match('~```(?:json)?\s*(\{.*\})\s*```~s', $assistantText, $m)) {
            $clean = trim($m[1]);
            $tmp   = json_decode($clean, true);
            if (is_array($tmp)) {
               $json          = $tmp;
               $assistantText = $clean;
            }
         }
      }

      if (!is_array($json)) {
         // Generic fallback: show raw text and force ticket/call flags.
         // strip_tags() is a defense-in-depth measure: the AI response is
         // untrusted content (prompt injection risk) and must never reach
         // the browser as raw HTML, even though the frontend also escapes it.
         return [
            'answer'        => strip_tags($assistantText),
            'needs_ticket'  => true,
            'suggest_call'  => true,
            'ticket_title'  => null,
         ];
      }

      $title = null;
      if (isset($json['ticket_title']) && is_string($json['ticket_title'])) {
         $t = trim($json['ticket_title']);
         if ($t !== '') {
            $title = $t;
         }
      }

      return [
         'answer'        => strip_tags($json['answer'] ?? $assistantText),
         'needs_ticket'  => (bool)($json['needs_ticket'] ?? false),
         'suggest_call'  => (bool)($json['suggest_call'] ?? false),
         'ticket_title'  => $title,
      ];
   }

   /**
    * Creates a GLPI ticket based on the chatbot conversation history.
    *
    * @param string      $question      Concatenated user messages history
    * @param string      $answer        (Not used, kept for compatibility)
    * @param string|null $preferredTitle Title suggested by the AI (ticket_title)
    * @return array
    */
   public function createTicketFromChat(string $question, string $answer, ?string $preferredTitle = null): array {
      $question       = trim($question);
      $preferredTitle = trim((string)$preferredTitle);

      // Prioritize AI suggested title
      $title = '';

      if ($preferredTitle !== '') {
         $title = $preferredTitle;

         // Truncate if necessary
         if (mb_strlen($title, 'UTF-8') > 120) {
            $title = mb_substr($title, 0, 117, 'UTF-8') . '...';
         }

      } else {
         // Fallback title generation from user messages
         $rawLines = preg_split("/\r\n|\n|\r/u", $question);
         $lines = [];

         foreach ($rawLines as $line) {
            $line = trim($line);
            if ($line !== '') {
               $lines[] = $line;
            }
         }

         if (empty($lines)) {
            $title = __('Demande via chatbot IA', 'aisuite');
         } else {
            $titleLine = $lines[0];

            // Skip short greetings
            foreach ($lines as $line) {
               $low = mb_strtolower($line, 'UTF-8');
               $isGreeting = preg_match('/^(bonjour|bonsoir|salut|hello|coucou|bjr|bjs)\b/u', $low);
               if ($isGreeting && mb_strlen($low, 'UTF-8') <= 40) {
                  continue;
               }
               $titleLine = $line;
               break;
            }

            // Extract first sentence as title
            $separators = "/(\.|\?|!)/u";
            $parts = preg_split($separators, $titleLine, 2, PREG_SPLIT_DELIM_CAPTURE);
            if (!empty($parts[0])) {
               $title = trim($parts[0]);
            } else {
               $title = trim($titleLine);
            }

            if (mb_strlen($title, 'UTF-8') > 120) {
               $title = mb_substr($title, 0, 117, 'UTF-8') . '...';
            }

            if ($title === '') {
               $title = __('Demande via chatbot IA', 'aisuite');
            }
         }
      }

      // Ticket content compilation
      $rawLines = preg_split("/\r\n|\n|\r/u", $question);
      $lines = [];

      foreach ($rawLines as $line) {
         $line = trim($line);
         if ($line !== '') {
            $lines[] = $line;
         }
      }

      if (empty($lines)) {
         $content = __('Conversation utilisateur (via chatbot IA) :', 'aisuite') . "\n\n" . $question;
      } else {
         $formattedLines = [];
         foreach ($lines as $idx => $line) {
            $num = $idx + 1;
            $formattedLines[] = sprintf(__("Message %d de l'utilisateur :", 'aisuite'), $num) . "\n{$line}";
         }

         $content = __('Conversation utilisateur (via chatbot IA) :', 'aisuite') . "\n\n" . implode("\n\n", $formattedLines);
      }

      $ticket = new Ticket();

      $input = [
         'name'               => $title,
         'content'            => $content,
         'users_id_recipient' => Session::getLoginUserID(),
         'entities_id'        => $_SESSION['glpiaactive_entity'] ?? 0,
         'status'             => Ticket::INCOMING,
      ];

      if ($ticket_id = $ticket->add($input)) {
         return [
            'success'   => true,
            'ticket_id' => $ticket_id,
            'title'     => $title,
         ];
      }

      // Technical: log why Ticket::add() refused the ticket (missing rights
      // on the resolved entity, mandatory field, etc.) - the JS only shows a
      // generic message to the user, but this previously left no trace at
      // all server-side, making failures like a wrong/missing entity id
      // silent and hard to diagnose.
      Toolbox::logInFile('aisuite', sprintf(
          "AI Chatbot createTicketFromChat: Ticket::add() failed (entities_id=%d, user=%d).\n",
          (int)$input['entities_id'],
          Session::getLoginUserID() ?: 0
      ));

      return ['success' => false];
   }
}
