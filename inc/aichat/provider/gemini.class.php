<?php

/**
 * Plugin GLPI AI CHAT - File: gemini.class.php
 * Gemini (Google) provider implementation for the AI Chat plugin.
 *
 * NOTE: renamed from geminiprovider.class.php to gemini.class.php to match
 * GLPI's namespaced plugin autoloading convention (class "Gemini" -> file
 * "gemini.class.php"). The old filename would cause a "class not found"
 * fatal error if this provider were selected.
 */

namespace GlpiPlugin\Aisuite\AiChat\Provider;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Gemini (Google) Provider for the AI Suite plugin.
 *
 * This class handles:
 * - Payload construction for the Gemini API (generateContent)
 * - HTTP call (cURL)
 * - Extraction of the assistant's text from the Gemini response
 *
 * It DOES NOT interpret the "business" JSON (answer / needs_ticket / ...),
 * as that task is handled in Chat::callAI().
 */
class Gemini implements ProviderInterface {

   /**
    * Calls the Gemini API (Google)
    *
    * @param string $systemPrompt Common system prompt
    * @param array  $conversation Normalized history: [ ['role'=>'user|assistant','content'=>'...'], ... ]
    * @param array  $config       Plugin config (ai_api_url, ai_api_key, ai_model, ...)
    *
    * @return array{assistantText: ?string, error: ?string}
    */
   public function call(string $systemPrompt, array $conversation, array $config): array {
      $url = $config['ai_api_url'] ?? '';

      // SSRF hardening: only ever call an HTTPS endpoint. The URL is
      // admin-configurable (Providers tab), which now also refuses to
      // persist a non-HTTPS value - this is defense in depth.
      if (parse_url((string)$url, PHP_URL_SCHEME) !== 'https') {
         return [
            'assistantText' => null,
            'error'         => 'format',
         ];
      }

      $key = $config['ai_api_key'] ?? '';
      // $model is currently unused as the Gemini URL usually includes the model ID
      $model = trim((string)($config['ai_model'] ?? ''));

      // Construct "contents" in Gemini format
      $contents = [];
      foreach ($conversation as $t) {
         $contents[] = [
            'role'  => ($t['role'] === 'assistant') ? 'model' : 'user',
            'parts' => [
               ['text' => $t['content']],
            ],
         ];
      }

      $payload = [
         'contents'           => $contents,
         'system_instruction' => [
            'role'  => 'system',
            'parts' => [
               ['text' => $systemPrompt],
            ],
         ],
         'generationConfig'   => [
            'temperature'     => 0.2,
            'maxOutputTokens' => 512,
         ],
      ];

      $ch = curl_init($url);
      curl_setopt_array($ch, [
         CURLOPT_POST           => true,
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
         CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $key,
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

      // Handle non-JSON response
      if (!is_array($decoded)) {
         return [
            'assistantText' => null,
            'error'         => 'format',
         ];
      }

      // Explicit handling of errors returned by the Gemini API
      if (isset($decoded['error']['message'])) {
         $apiErrorMessage = (string)$decoded['error']['message'];

         return [
            // Return message so caller can display it
            'assistantText' => $apiErrorMessage,
            'error'         => 'api_error',
         ];
      }

      // Standard case: expect candidates[0].content.parts[0].text
      if (empty($decoded['candidates'][0]['content']['parts'][0]['text'])) {
         return [
            'assistantText' => null,
            'error'         => 'format',
         ];
      }

      $assistantText = $decoded['candidates'][0]['content']['parts'][0]['text'];

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
      return __('Gemini (Google)', 'aisuite');
   }
}
