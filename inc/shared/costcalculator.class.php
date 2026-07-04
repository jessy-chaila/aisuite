<?php

namespace GlpiPlugin\Aisuite\Shared;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Shared token-cost calculator, used by AI Smart Check, AI Smart Sorter and
 * AI Level 1 Assistant.
 *
 * Technical: consolidated from 3 near-identical per-module copies (each with
 * a slightly different signature/return type) as part of the security/quality
 * audit. `compute()` always returns the array shape; `computeFormatted()` is
 * kept only for the one call site (AI Smart Check) that historically wanted
 * a pre-formatted string.
 */
class CostCalculator {

    /**
     * Built-in fallback prices (USD per 1M tokens), used only if no custom
     * price has been entered in the Providers tab.
     */
    private const DEFAULT_PRICES = [
        'openai'    => ['in' => 5.00,  'out' => 15.00],
        'anthropic' => ['in' => 3.00,  'out' => 15.00],
        'google'    => ['in' => 1.25,  'out' => 5.00],
    ];

    /**
     * @param string $providerType 'openai', 'anthropic' or 'google'.
     * @param array  $conf         Plugin configuration (for
     *                             'provider_<type>_price_input'/'_price_output').
     * @param array  $usage        Provider call usage array, expects
     *                             'prompt_tokens'/'completion_tokens' keys.
     *
     * @return array{cost: float, tokens: int}
     */
    public static function compute(string $providerType, array $conf, array $usage): array {
        $inputTokens  = (int)($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int)($usage['completion_tokens'] ?? 0);
        $totalTokens  = $inputTokens + $outputTokens;

        $default = self::DEFAULT_PRICES[$providerType] ?? ['in' => 0, 'out' => 0];

        $priceInputPerMillion  = (float)($conf['provider_' . $providerType . '_price_input']  ?? 0);
        $priceOutputPerMillion = (float)($conf['provider_' . $providerType . '_price_output'] ?? 0);

        if ($priceInputPerMillion <= 0) {
            $priceInputPerMillion = $default['in'];
        }
        if ($priceOutputPerMillion <= 0) {
            $priceOutputPerMillion = $default['out'];
        }

        $cost = ($inputTokens / 1000000 * $priceInputPerMillion) + ($outputTokens / 1000000 * $priceOutputPerMillion);

        return [
            'cost'   => round($cost, 6),
            'tokens' => $totalTokens,
        ];
    }

    /**
     * Same computation as compute(), formatted as a 5-decimal string
     * (e.g. "0.00420"), matching AI Smart Check's historical return type.
     */
    public static function computeFormatted(string $providerType, array $conf, array $usage): string {
        $result = self::compute($providerType, $conf, $usage);
        return number_format($result['cost'], 5);
    }
}
