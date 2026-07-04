<?php

namespace GlpiPlugin\Aisuite\Provider;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * OpenAI-compatible Provider Implementation, shared by AI Smart Check,
 * AI Smart Sorter and AI Level 1 Assistant.
 *
 * Covers every provider that speaks the OpenAI Chat Completions wire format:
 * OpenAI, Azure OpenAI, xAI (Grok) and Mistral. Only the endpoint URL, API
 * key and model/deployment name change between these backends.
 *
 * Technical: consolidated from 3 near-identical per-module copies as part of
 * the security/quality audit. Behavior is the union of what each copy did:
 * the "response too long" detection from AI Smart Check's copy, the
 * restricted-model temperature handling from AI Level 1 Assistant's copy.
 */
class OpenAi implements ProviderInterface {

    public function call(string $systemPrompt, array $conversation, array $config): array {
        $url = $config['api_url'] ?? ($config['ai_api_url'] ?? '');
        if (empty($url)) {
            $url = 'https://api.openai.com/v1/chat/completions';
        }

        // SSRF hardening: the endpoint is admin-configurable (Providers tab),
        // but must always be HTTPS. front/config.form.php already refuses to
        // persist a non-HTTPS URL, this is defense in depth in case a value
        // ever reaches this point some other way (direct DB edit, etc.).
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
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

        $openaiMessages = [];

        if (!empty($systemPrompt)) {
            $openaiMessages[] = [
                'role'    => 'system',
                'content' => $systemPrompt,
            ];
        }

        foreach ($conversation as $t) {
            $openaiMessages[] = [
                'role'    => $t['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $t['content'],
            ];
        }

        $payload = [
            'messages'              => $openaiMessages,
            'max_completion_tokens' => $maxTokens,
        ];

        $currentModel = trim((string)($config['ai_model'] ?? ''));

        // Only send 'model' when provided: Azure deployments already encode
        // the model in the URL, but OpenAI/xAI/Mistral require it explicitly.
        if (!empty($currentModel)) {
            $payload['model'] = $currentModel;
        }

        // Newer 'Reasoning' (o1, o3) or 'Nano' models reject temperature != 1:
        // omit it for these models to use their default value.
        $restrictedModels = ['gpt-5-nano', 'gpt-5-mini', 'o1', 'o3'];
        if (!in_array($currentModel, $restrictedModels, true)) {
            $payload['temperature'] = $temperature;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'api-key: ' . $key,
                'Authorization: Bearer ' . $key,
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

        if (isset($decoded['error'])) {
            $msg  = $decoded['error']['message'] ?? __('Unknown Error', 'aisuite');
            $code = $decoded['error']['code'] ?? 'N/A';
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => sprintf(__('API Error (%s): %s', 'aisuite'), $code, $msg),
            ];
        }

        if (empty($decoded['choices']) || !isset($decoded['choices'][0])) {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Unexpected JSON structure (No "choices" found).', 'aisuite'),
            ];
        }

        $choice       = $decoded['choices'][0];
        $finishReason = $choice['finish_reason'] ?? 'unknown';

        if ($finishReason === 'content_filter') {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Content Filter: Response blocked by provider safety settings.', 'aisuite'),
            ];
        }

        $msg           = $choice['message'] ?? [];
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

        if (empty($assistantText) && $finishReason === 'length') {
            return [
                'assistantText' => null,
                'usage'         => [],
                'error'         => __('Response too long (token limit reached). Increase the max tokens setting.', 'aisuite'),
            ];
        }

        $usage = $decoded['usage'] ?? [];

        return [
            'assistantText' => $assistantText,
            'usage'         => $usage,
            'error'         => null,
        ];
    }

    public function getLabel(): string {
        return __('OpenAI (compatible)', 'aisuite');
    }
}
