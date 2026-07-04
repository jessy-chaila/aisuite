<?php

/**
 * Plugin GLPI AI CHAT - File: claude.class.php
 * Claude (Anthropic) provider implementation for the AI Chat plugin.
 *
 * NOTE: renamed from claudeprovider.class.php to claude.class.php to match
 * GLPI's namespaced plugin autoloading convention (class "Claude" -> file
 * "claude.class.php"). The old filename would cause a "class not found"
 * fatal error if this provider were selected.
 */

namespace GlpiPlugin\Aisuite\AiChat\Provider;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Claude (Anthropic) Provider for the AI Suite plugin.
 *
 * This class handles:
 * - Payload construction for /v1/messages
 * - HTTP call (cURL)
 * - Extraction of the assistant's text from the Anthropic response
 *
 * It DOES NOT interpret the "business" JSON (answer / needs_ticket / ...),
 * as that task is handled in Chat::callAI().
 */
class Claude implements ProviderInterface {

   /**
    * Calls the Claude API (Anthropic)
    *
    * @param string $systemPrompt Common system prompt
    * @param array  $conversation Normalized history: [ ['role'=>'user|assistant','content'=>'...'], ... ]
    * @param array  $config       Plugin config (ai_api_url, ai_api_key, ai_model, ...)
    *
    * @return array{assistantText: ?string, error: ?string}
    */
   public function call(string $systemPrompt, array $conversation, array $config): array {
      $url   = $config['ai_api_url'] ?? '';

      // SSRF hardening: only ever call an HTTPS endpoint. The URL is
      // admin-configurable (Providers tab), which now also refuses to
      // persist a non-HTTPS value - this is defense in depth.
      if (parse_url((string)$url, PHP_URL_SCHEME) !== 'https') {
         return [
            'assistantText' => null,
            'error'         => 'format',
         ];
      }

      $key   = $config['ai_api_key'] ?? '';
      $model = trim((string)($config['ai_model'] ?? ''));

      // Payload construction for Anthropic /v1/messages
      $payload = [
         'model'       => $model,
         'max_tokens'  => 512,
         'temperature' => 0.2,
         'system'      => $systemPrompt,
         'messages'    => array_map(function (array $t) {
            return [
               'role'    => $t['role'] === 'assistant' ? 'assistant' : 'user',
               'content' => $t['content'],
            ];
         }, $conversation),
      ];

      $ch = curl_init($url);
      curl_setopt_array($ch, [
         CURLOPT_POST           => true,
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
         CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
         ],
         CURLOPT_POSTFIELDS     => json_encode($payload),
         CURLOPT_CONNECTTIMEOUT => 10,
         CURLOPT_TIMEOUT        => 15,
      ]);

      $result = curl_exec($ch);

      if ($result === false) {
         curl_close($ch);
         return [
            'assistantText' => null,
            'error'         => 'communication',
         ];
      }

      curl_close($ch);

      $decoded = json_decode($result, true);
      if (!is_array($decoded)) {
         return [
            'assistantText' => null,
            'error'         => 'format',
         ];
      }

      $assistantText = null;

      // Anthropic returns content in content[0].text
      if (isset($decoded['content'][0]['text'])) {
         $assistantText = $decoded['content'][0]['text'];

      } elseif (isset($decoded['content'][0]['type'])
                && $decoded['content'][0]['type'] === 'text'
                && isset($decoded['content'][0]['text'])) {
         $assistantText = $decoded['content'][0]['text'];
      }

      return [
         'assistantText' => $assistantText,
         'error'         => null,
      ];
   }

   /**
    * Returns the provider label
    * * @return string
    */
   public function getLabel(): string {
      return __('Claude (Anthropic)', 'aisuite');
   }
}
