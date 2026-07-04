<?php

namespace GlpiPlugin\Aisuite\Provider;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Shared AI provider contract, used by AI Smart Check, AI Smart Sorter and
 * AI Level 1 Assistant (AI Chatbot keeps its own lighter-weight provider
 * classes under GlpiPlugin\Aisuite\AiChat\Provider, since it has a
 * deliberately different latency/token budget and does not need per-call
 * token usage tracking).
 *
 * Technical: this interface used to be duplicated 3 times (one copy per
 * module, byte-for-byte identical apart from the namespace) — consolidated
 * here as part of the security/quality audit to have a single place to
 * enforce HTTPS-only endpoints and a single, consistent error/usage contract.
 */
interface ProviderInterface {

    /**
     * Executes the AI API call.
     *
     * @param string $systemPrompt Common system prompt defining AI behavior.
     * @param array  $conversation Normalized history: [ ['role'=>'user|assistant','content'=>'...'], ... ]
     * @param array  $config       Call configuration. Recognized keys:
     *                             'api_url'/'ai_api_url', 'api_key'/'ai_api_key' (both
     *                             spellings accepted, for backward compatibility with
     *                             the different config key names historically used by
     *                             each module), 'ai_model', and optionally
     *                             'ai_timeout' (seconds, default 60),
     *                             'ai_temperature' (default 0.1),
     *                             'ai_max_tokens' (default 4000).
     *
     * @return array{assistantText: ?string, usage: array, error: ?string}
     */
    public function call(string $systemPrompt, array $conversation, array $config): array;

    /**
     * Returns the localized provider label for UI display.
     *
     * @return string
     */
    public function getLabel(): string;
}
