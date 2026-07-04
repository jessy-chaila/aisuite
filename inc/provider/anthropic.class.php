<?php

namespace GlpiPlugin\Aisuite\Provider;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Anthropic (Claude) Provider Implementation, shared by AI Smart Check,
 * AI Smart Sorter and AI Level 1 Assistant.
 *
 * Technical: consolidated from 3 near-identical per-module copies as part of
 * the security/quality audit.
 */
class Anthropic implements ProviderInterface {

    public function call(string $systemPrompt, array $conversation, array $config): array {
        $url = $config['api_url'] ?? ($config['ai_api_url'] ?? '');
        if (empty($url)) {
            $url = 'https://api.anthropic.com/v1/messages';
        }

        // SSRF hardening: see OpenAi::call() for rationale.
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Invalid provider URL: only HTTPS endpoints are allowed.', 'aisuite'),
            ];
        }

        $key = $config['api_key'] ?? ($config['ai_api_key'] ?? '');

        $model = trim((string)($config['ai_model'] ?? ''));
        if ($model === '') {
            $model = 'claude-3-5-sonnet-20240620';
        }

        $temperature = (float)($config['ai_temperature'] ?? 0.1);
        $maxTokens   = (int)($config['ai_max_tokens'] ?? 4000);
        $timeout     = (int)($config['ai_timeout'] ?? 60);

        $headers = [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ];

        // Anthropic does not use a 'system' role in the messages array: the
        // system prompt is a separate top-level payload field.
        $anthropicMessages = [];
        foreach ($conversation as $t) {
            $anthropicMessages[] = [
                'role'    => $t['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $t['content'],
            ];
        }

        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'system'      => $systemPrompt,
            'messages'    => $anthropicMessages,
            'temperature' => $temperature,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
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

        if (isset($decoded['error'])) {
            $type = $decoded['error']['type'] ?? 'error';
            $msg  = $decoded['error']['message'] ?? __('Unknown Error', 'aisuite');
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => sprintf(__('API Error (%s): %s', 'aisuite'), $type, $msg),
            ];
        }

        $assistantText = '';
        if (isset($decoded['content']) && is_array($decoded['content'])) {
            foreach ($decoded['content'] as $block) {
                if (isset($block['type']) && $block['type'] === 'text') {
                    $assistantText .= $block['text'];
                }
            }
        }

        if (empty($assistantText)) {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Empty response content.', 'aisuite'),
            ];
        }

        $inputTokens  = $decoded['usage']['input_tokens']  ?? 0;
        $outputTokens = $decoded['usage']['output_tokens'] ?? 0;

        $usage = [
            'prompt_tokens'     => $inputTokens,
            'completion_tokens' => $outputTokens,
            'total_tokens'      => $inputTokens + $outputTokens,
        ];

        return [
            'assistantText' => $assistantText,
            'usage'         => $usage,
            'error'         => null,
        ];
    }

    public function getLabel(): string {
        return __('Anthropic (Claude)', 'aisuite');
    }
}
