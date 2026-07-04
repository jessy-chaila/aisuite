<?php

namespace GlpiPlugin\Aisuite\Provider;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Gemini (Google) Provider Implementation, shared by AI Smart Check,
 * AI Smart Sorter and AI Level 1 Assistant.
 *
 * Technical: consolidated from 3 near-identical per-module copies as part of
 * the security/quality audit.
 */
class Gemini implements ProviderInterface {

    public function call(string $systemPrompt, array $conversation, array $config): array {
        $url = $config['api_url'] ?? ($config['ai_api_url'] ?? '');

        // SSRF hardening: see OpenAi::call() for rationale. Gemini has no
        // hardcoded default endpoint (the model ID is normally baked into the
        // configured URL), so an empty/non-HTTPS URL is always rejected here.
        if (parse_url((string)$url, PHP_URL_SCHEME) !== 'https') {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Invalid provider URL: only HTTPS endpoints are allowed.', 'aisuite'),
            ];
        }

        $key = $config['api_key'] ?? ($config['ai_api_key'] ?? '');

        $temperature = (float)($config['ai_temperature'] ?? 0.1);
        $maxTokens   = (int)($config['ai_max_tokens'] ?? 4000);
        $timeout     = (int)($config['ai_timeout'] ?? 60);

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
                'temperature'     => $temperature,
                'maxOutputTokens' => $maxTokens,
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
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $result = curl_exec($ch);

        if ($result === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('cURL Error: ', 'aisuite') . $curlError,
            ];
        }

        curl_close($ch);
        $decoded = json_decode($result, true);

        if (!is_array($decoded)) {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Invalid response (Non-JSON): ', 'aisuite') . substr($result, 0, 200),
            ];
        }

        if (isset($decoded['error']['message'])) {
            $msg  = (string)$decoded['error']['message'];
            $code = $decoded['error']['code'] ?? 'N/A';
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => sprintf(__('API Error (%s): %s', 'aisuite'), $code, $msg),
            ];
        }

        if (empty($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Unexpected JSON structure (No "choices" found).', 'aisuite'),
            ];
        }

        $assistantText = $decoded['candidates'][0]['content']['parts'][0]['text'];

        $promptTokens     = $decoded['usageMetadata']['promptTokenCount']     ?? 0;
        $completionTokens = $decoded['usageMetadata']['candidatesTokenCount'] ?? 0;
        $totalTokens      = $decoded['usageMetadata']['totalTokenCount']      ?? ($promptTokens + $completionTokens);

        $usage = [
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $totalTokens,
        ];

        return [
            'assistantText' => $assistantText,
            'usage'         => $usage,
            'error'         => null,
        ];
    }

    public function getLabel(): string {
        return __('Gemini (Google)', 'aisuite');
    }
}
