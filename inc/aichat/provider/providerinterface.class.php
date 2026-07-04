<?php

/**
 * Plugin GLPI AI CHAT - File: providerinterface.class.php
 * Common interface for all AI providers in the AI Suite plugin.
 */

namespace GlpiPlugin\Aisuite\AiChat\Provider;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Interface common to all AI providers for the AI Suite plugin.
 *
 * Each provider:
 * - Constructs its specific payload (Anthropic, OpenAI, Gemini, etc.),
 * - Performs the HTTP call,
 * - Returns a standardized array:
 * [
 * 'assistantText' => ?string, // Raw text returned by the LLM (often a business JSON string)
 * 'error'         => ?string, // null or an error code ('communication', 'format', etc.)
 * ]
 */
interface ProviderInterface {

   /**
    * Calls the AI provider and returns the raw model response.
    *
    * @param string $systemPrompt Common system prompt (constructed by Chat)
    * @param array  $conversation Normalized history:
    * [ ['role'=>'user|assistant','content'=>'...'], ... ]
    * @param array  $config       Plugin config (ai_api_url, ai_api_key, ai_model, ...)
    *
    * @return array{assistantText: ?string, error: ?string}
    */
   public function call(string $systemPrompt, array $conversation, array $config): array;

   /**
    * Human-readable label of the provider (e.g. "Claude (Anthropic)", "ChatGPT (OpenAI)", etc.).
    * * @return string
    */
   public function getLabel(): string;
}
