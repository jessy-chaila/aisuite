<?php

/**
 * Plugin AI Suite - File: openai.class.php
 * OpenAI-compatible provider implementation for the AI Chatbot module.
 *
 * NOTE: this file was renamed from openaiprovider.class.php to openai.class.php
 * to match GLPI's namespaced plugin autoloading convention, which maps class
 * name "OpenAI" (lowercased: "openai") to file "openai.class.php". The old
 * filename caused a "class not found" fatal error whenever the chatbot tried
 * to actually call the AI (while the Providers tab's own "Tester la connexion"
 * button still worked, since it uses the SmartCheck module's provider class,
 * not this one).
 */

namespace GlpiPlugin\Aisuite\AiChat\Provider;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * OpenAI-compatible Provider for the AI Suite plugin.
 *
 * Covers every provider that speaks the OpenAI Chat Completions wire format:
 * OpenAI, Azure OpenAI, xAI (Grok) and Mistral. Only the endpoint URL, API
 * key and model/deployment name change between these backends.
 *
 * - 'api-key' header is sent for Azure-style authentication.
 * - 'Authorization: Bearer' header is sent for standard OpenAI-style authentication.
 * - The 'model' field is only included in the payload when provided, since
 *   Azure deployments already encode the model in the URL itself.
 */
class OpenAI implements ProviderInterface {

   /**
    * Calls the OpenAI-compatible API
    *
    * @param string $systemPrompt Common system prompt
    * @param array  $conversation Normalized history: [ ['role'=>'user|assistant','content'=>'...'], ... ]
    * @param array  $config       Plugin config (ai_api_url, ai_api_key, ai_model, ...)
    *
    * @return array{assistantText: ?string, error: ?string}
    */
   public function call(string $systemPrompt, array $conversation, array $config): array {
      $url = $config['ai_api_url'] ?? '';
      if (empty($url)) {
         $url = 'https://api.openai.com/v1/chat/completions';
      }

      // SSRF hardening: only ever call an HTTPS endpoint. The URL is
      // admin-configurable (Providers tab), which now also refuses to
      // persist a non-HTTPS value - this is defense in depth.
      if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
         return [
            'assistantText' => null,
            'error'         => 'format',
         ];
      }

      $key   = $config['ai_api_key'] ?? '';
      $model = trim((string)($config['ai_model'] ?? ''));

      // Construct messages in OpenAI/Azure format
      $openaiMessages = [];

      // System message first
      $openaiMessages[] = [
         'role'    => 'system',
         'content' => $systemPrompt,
      ];

      // History + current message
      foreach ($conversation as $t) {
         $openaiMessages[] = [
            'role'    => $t['role'] === 'assistant' ? 'assistant' : 'user',
            'content' => $t['content'],
         ];
      }

      $payload = [
         'messages'              => $openaiMessages,
         'temperature'           => 0.2,
         'max_completion_tokens' => 800,
      ];

      // Only send 'model' when provided: Azure deployments already encode
      // the model in the URL, but OpenAI/xAI/Mistral require it explicitly.
      if (!empty($model)) {
         $payload['model'] = $model;
      }

      $ch = curl_init($url);
      curl_setopt_array($ch, [
         CURLOPT_POST           => true,
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
         CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . $key,             // Azure-style authentication
            'Authorization: Bearer ' . $key // OpenAI / xAI / Mistral-style authentication
         ],
         CURLOPT_POSTFIELDS     => json_encode($payload),
         CURLOPT_CONNECTTIMEOUT => 10,
         CURLOPT_TIMEOUT        => 30,
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

      // Standard error handling (returns error codes to be translated by Chat::callAI())
      if (!is_array($decoded) || empty($decoded['choices'][0]['message'])) {
         return [
            'assistantText' => null,
            'error'         => 'format',
         ];
      }

      $msg = $decoded['choices'][0]['message'];

      $assistantText = null;

      if (is_string($msg['content'] ?? null)) {
         $assistantText = $msg['content'];
      } elseif (is_array($msg['content'] ?? null)) {
         $parts = [];
         foreach ($msg['content'] as $part) {
            if (is_string($part)) {
               $parts[] = $part;
            } elseif (is_array($part) && isset($part['text'])) {
               $parts[] = $part['text'];
            }
         }
         $assistantText = implode("\n", $parts);
      }

      return [
         'assistantText' => $assistantText,
         'error'         => null,
      ];
   }

   /**
    * Returns the provider label
    *
    * @return string
    */
   public function getLabel(): string {
      return __('OpenAI (compatible)', 'aisuite');
   }
}
