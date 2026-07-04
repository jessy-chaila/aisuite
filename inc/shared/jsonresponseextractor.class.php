<?php

namespace GlpiPlugin\Aisuite\Shared;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Shared AI JSON-response extraction, used by AI Smart Check, AI Smart
 * Sorter and AI Level 1 Assistant: all three modules ask the AI provider to
 * reply with a single JSON object, sometimes wrapped in a ```json markdown
 * fence and/or preceded/followed by stray prose the model added anyway.
 *
 * Technical: consolidated from 3 identical per-module copies as part of the
 * security/quality audit.
 */
class JsonResponseExtractor {

    /**
     * Strips a possible ```json ... ``` markdown fence, isolates the
     * outermost {...} object and decodes it.
     *
     * @param string $rawResponse Raw text returned by the AI provider.
     *
     * @return array{data: ?array, cleanJson: string, error: ?string}
     *         'data' is null and 'error' is set (json_last_error_msg()) if
     *         decoding failed; 'cleanJson' always holds the extracted (but
     *         not necessarily valid) JSON substring, for logging.
     */
    public static function extract(string $rawResponse): array {
        $rawResponse = trim($rawResponse);
        $cleanJson   = str_replace(['```json', '```'], '', $rawResponse);

        $firstBracket = strpos($cleanJson, '{');
        $lastBracket  = strrpos($cleanJson, '}');

        if ($firstBracket !== false && $lastBracket !== false) {
            $cleanJson = substr($cleanJson, $firstBracket, ($lastBracket - $firstBracket) + 1);
        }

        $data = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'data'      => null,
                'cleanJson' => $cleanJson,
                'error'     => json_last_error_msg(),
            ];
        }

        return [
            'data'      => $data,
            'cleanJson' => $cleanJson,
            'error'     => null,
        ];
    }
}
