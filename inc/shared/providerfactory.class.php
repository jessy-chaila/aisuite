<?php

namespace GlpiPlugin\Aisuite\Shared;

use GlpiPlugin\Aisuite\Provider\Anthropic;
use GlpiPlugin\Aisuite\Provider\Gemini;
use GlpiPlugin\Aisuite\Provider\OpenAi;
use GlpiPlugin\Aisuite\Provider\ProviderInterface;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Shared AI provider factory, used by AI Smart Check, AI Smart Sorter and
 * AI Level 1 Assistant to instantiate the currently-configured provider
 * (Providers tab, single plugin-wide choice).
 *
 * Technical: consolidated from 3 identical per-module switch/if blocks as
 * part of the security/quality audit.
 */
class ProviderFactory {

    /**
     * @param string $providerType 'openai', 'anthropic' or 'google'. Any
     *                             other value falls back to 'openai'.
     */
    public static function make(string $providerType): ProviderInterface {
        switch ($providerType) {
            case 'anthropic':
                return new Anthropic();
            case 'google':
                return new Gemini();
            case 'openai':
            default:
                return new OpenAi();
        }
    }

    /**
     * Normalizes an arbitrary/untrusted provider type string against the
     * list of providers the plugin actually supports.
     */
    public static function normalizeType(?string $providerType): string {
        if (!in_array($providerType, ['openai', 'anthropic', 'google'], true)) {
            return 'openai';
        }
        return $providerType;
    }
}
