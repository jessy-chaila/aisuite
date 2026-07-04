<?php

namespace GlpiPlugin\Aisuite\Shared;

use Config;
use GLPIKey;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Shared config accessor for the AI Suite plugin.
 *
 * Technical: added as part of the security audit. `provider_openai_key`,
 * `provider_anthropic_key` and `provider_google_key` are registered via the
 * `secured_configs` plugin hook (see setup.php), which makes GLPI's
 * Config::setConfigurationValues() transparently encrypt them on write
 * (GLPIKey, libsodium XChaCha20-Poly1305) instead of storing plaintext API
 * keys in glpi_configs. GLPI does NOT symmetrically auto-decrypt on read:
 * Config::getConfigurationValues() always returns the raw (encrypted) DB
 * value. Every one of this plugin's ~13 call sites used to call
 * Config::getConfigurationValues('plugin:aisuite') directly and would have
 * sent the encrypted blob straight to the AI provider as the API key.
 *
 * PluginConfig::get() is a drop-in replacement for that call: same return
 * shape, but with the 3 secured fields decrypted.
 */
class PluginConfig {

    private const SECURED_FIELDS = [
        'provider_openai_key',
        'provider_anthropic_key',
        'provider_google_key',
    ];

    /**
     * @return array Plugin-wide configuration, with API keys decrypted.
     */
    public static function get(): array {
        $conf = Config::getConfigurationValues('plugin:aisuite');
        return self::decryptSecuredFields($conf);
    }

    /**
     * Decrypts the secured fields of an already-fetched config array.
     * Exposed separately so call sites that already hold a config array
     * (e.g. after Config::setConfigurationValues() during a save) can reuse
     * it without an extra DB round-trip.
     */
    public static function decryptSecuredFields(array $conf): array {
        $glpikey = new GLPIKey();

        foreach (self::SECURED_FIELDS as $field) {
            if (empty($conf[$field])) {
                continue;
            }

            $decrypted = $glpikey->decrypt($conf[$field]);

            // decrypt() returns null (or an empty string) if the value isn't
            // valid ciphertext for the current app key - e.g. a plaintext
            // value that predates the one-time migration, or that was
            // entered directly in the DB. Keep the raw value in that case
            // rather than silently wiping out a configured API key.
            if ($decrypted !== null && $decrypted !== '') {
                $conf[$field] = $decrypted;
            }
        }

        return $conf;
    }

    /**
     * @return string[] Config field names that must be encrypted at rest.
     */
    public static function getSecuredFields(): array {
        return self::SECURED_FIELDS;
    }
}
