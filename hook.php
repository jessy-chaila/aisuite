<?php

/**
 * Plugin AI Suite - File: hook.php
 * Install / uninstall hooks: database schema + default shared configuration.
 */

/**
 * Install hook.
 * MUST be named plugin_aisuite_install.
 */
function plugin_aisuite_install() {
    global $DB;

    $migration = new Migration(100);

    // ---------------------------------------------------------
    // 1. DATABASE - AI Smart Check
    // ---------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_aismartcheck_analyses')) {
        $query = "CREATE TABLE `glpi_plugin_aismartcheck_analyses` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `tickets_id` int unsigned NOT NULL,
                    `content` longtext COLLATE utf8mb4_unicode_ci,
                    `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tickets_id` (`tickets_id`)
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
        $migration->addPostQuery($query);
    }

    // ---------------------------------------------------------
    // 2. DATABASE - AI Smart Sorter
    // ---------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_aismartsorter_logs')) {
        $query = "CREATE TABLE `glpi_plugin_aismartsorter_logs` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `tickets_id` int unsigned NOT NULL,
                    `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `input_data` longtext COLLATE utf8mb4_unicode_ci,
                    `ai_response` longtext COLLATE utf8mb4_unicode_ci,
                    `confidence_score` int DEFAULT 0,
                    `action_taken` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
                    `execution_cost` DECIMAL(10, 6) DEFAULT 0.000000,
                    `token_usage` INT DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `tickets_id` (`tickets_id`)
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
        $migration->addPostQuery($query);
    } else {
        $migration->addField('glpi_plugin_aismartsorter_logs', 'execution_cost', 'DECIMAL(10, 6) DEFAULT 0.000000');
        $migration->addField('glpi_plugin_aismartsorter_logs', 'token_usage', 'INT DEFAULT 0');
    }

    // ---------------------------------------------------------
    // 2bis. DATABASE - AI Level 1 Assistant
    // One row per ticket (unique tickets_id): tracks the ongoing
    // conversation state across multiple AI Q&A rounds.
    // ---------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_aisuite_level1_logs')) {
        $query = "CREATE TABLE `glpi_plugin_aisuite_level1_logs` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `tickets_id` int unsigned NOT NULL,
                    `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `conversation_json` longtext COLLATE utf8mb4_unicode_ci,
                    `ai_response` longtext COLLATE utf8mb4_unicode_ci,
                    `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
                    `round` int unsigned NOT NULL DEFAULT 0,
                    `needs_processing` tinyint unsigned NOT NULL DEFAULT 0,
                    `execution_cost` DECIMAL(10, 6) DEFAULT 0.000000,
                    `token_usage` INT DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tickets_id` (`tickets_id`)
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";
        $migration->addPostQuery($query);
    } else {
        // Upgrade path from the first (pre-release) schema: add the new
        // conversation-state columns and drop the old boolean flags they replace.
        $migration->addField('glpi_plugin_aisuite_level1_logs', 'conversation_json', 'longtext COLLATE utf8mb4_unicode_ci');
        $migration->addField('glpi_plugin_aisuite_level1_logs', 'status', "varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'");
        $migration->addField('glpi_plugin_aisuite_level1_logs', 'round', 'int unsigned NOT NULL DEFAULT 0');
        // needs_processing: set by handleTicketCreation()/handleFollowupReply() and
        // cleared by the "level1queue" CronTask once it has called the AI provider,
        // so the ticket/followup save itself never waits on the AI call.
        $migration->addField('glpi_plugin_aisuite_level1_logs', 'needs_processing', 'tinyint unsigned NOT NULL DEFAULT 0');
        $migration->addField('glpi_plugin_aisuite_level1_logs', 'date_mod', 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        if ($DB->fieldExists('glpi_plugin_aisuite_level1_logs', 'resolved')) {
            $migration->dropField('glpi_plugin_aisuite_level1_logs', 'resolved');
        }
        if ($DB->fieldExists('glpi_plugin_aisuite_level1_logs', 'escalated')) {
            $migration->dropField('glpi_plugin_aisuite_level1_logs', 'escalated');
        }
        if ($DB->fieldExists('glpi_plugin_aisuite_level1_logs', 'user_declined')) {
            $migration->dropField('glpi_plugin_aisuite_level1_logs', 'user_declined');
        }
        if ($DB->fieldExists('glpi_plugin_aisuite_level1_logs', 'input_data')) {
            $migration->dropField('glpi_plugin_aisuite_level1_logs', 'input_data');
        }
    }

    $migration->executeMigration();

    // ---------------------------------------------------------
    // 2ter. AI Level 1 Assistant background queue (CronTask)
    // Runs the actual AI call/followup posting shortly after ticket creation
    // or a follow-up reply, so GLPI's own save/submit response stays instant.
    // CronTask::Register() is a no-op if already registered, so calling it on
    // every install/upgrade never resets an admin's schedule tweaks.
    // ---------------------------------------------------------
    CronTask::Register(
        'GlpiPlugin\Aisuite\Level1\Queue',
        'level1queue',
        60,
        [
            'comment'   => __('Processes AI Level 1 Assistant ticket analyses and follow-up replies in the background.', 'aisuite'),
            'mode'      => CronTask::MODE_INTERNAL,
            'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
        ]
    );

    // ---------------------------------------------------------
    // 3. Shared configuration (Providers + per-module settings)
    // Full version: no license/quota fields.
    // ---------------------------------------------------------
    $default_config = [
        // --- Shared AI provider (single active family for the whole suite) ---
        // 'openai' covers OpenAI, Azure OpenAI, xAI (Grok) and Mistral (same wire format).
        'provider_active'           => 'openai',
        'provider_openai_url'       => '',
        'provider_openai_key'       => '',
        'provider_openai_model'     => '',
        'provider_anthropic_url'    => '',
        'provider_anthropic_key'    => '',
        'provider_anthropic_model'  => '',
        'provider_google_url'       => '',
        'provider_google_key'       => '',
        'provider_google_model'     => '',

        // --- AI Smart Check (uses provider_active; falls back to 'openai' if 'google') ---
        'smartcheck_system_prompt'      => '',
        'smartcheck_enable_kb_search'   => 0,

        // --- AI Smart Sorter (always uses the 'openai' family credentials) ---
        'sorter_enable_auto_mode'        => 0,
        'sorter_confidence_threshold'    => 80,
        'sorter_enable_hardware_linking' => 1,
        'sorter_system_prompt_context'   => '',

        // --- AI Chatbot (uses provider_active) ---
        'chat_system_prompt'        => '',
        'chat_support_phone'        => '',
        'chat_bot_icon_type'        => 'emoji',
        'chat_bot_icon_text'        => '?',
        'chat_bot_icon_image_url'   => '',
        'chat_bot_color'            => '',
        'chat_bot_color_use_theme'  => 1,

        // --- AI Level 1 Assistant (uses provider_active) ---
        'level1_system_prompt'      => '',
        'level1_escalation_group'   => 0,
        // ID of the auto-created "Assistant IA" group (see below); 0 until created.
        'level1_ai_group_id'        => 0,

        // Guards the one-time plaintext-API-key encryption migration below.
        // A fresh install has no keys yet, so it starts already "migrated".
        'provider_keys_encrypted'   => 1,
    ];

    $current_config  = Config::getConfigurationValues('plugin:aisuite');
    $isFreshInstall  = (count($current_config) === 0);
    $newAiGroupId    = 0;

    // ---------------------------------------------------------
    // 3bis-0. One-time migration: encrypt existing plaintext API keys.
    // Only relevant on upgrade (a fresh install has no keys yet). Guarded by
    // 'provider_keys_encrypted' so it only ever runs once. Encrypts directly
    // via GLPIKey and writes with $DB->update()/$DB->insert() instead of
    // Config::setConfigurationValues(), to stay correct regardless of
    // whether the 'secured_configs' hook (see setup.php) has already been
    // registered for this request - routing a one-time migration through
    // setConfigurationValues() could otherwise double-encrypt a value if
    // the hook happens to already be active by the time this runs.
    // ---------------------------------------------------------
    if (!$isFreshInstall && empty($current_config['provider_keys_encrypted'])) {
        $glpikey = new GLPIKey();

        foreach (\GlpiPlugin\Aisuite\Shared\PluginConfig::getSecuredFields() as $field) {
            $value = $current_config[$field] ?? '';
            if ($value === '') {
                continue;
            }

            // Already round-trips through decrypt(): already encrypted
            // (e.g. a previous, partially-completed migration attempt) -
            // leave it untouched.
            $alreadyDecrypted = $glpikey->decrypt($value);
            if ($alreadyDecrypted !== null && $alreadyDecrypted !== '') {
                continue;
            }

            $encrypted = $glpikey->encrypt($value);
            $DB->update('glpi_configs', ['value' => $encrypted], [
                'context' => 'plugin:aisuite',
                'name'    => $field,
            ]);
            $current_config[$field] = $encrypted;
        }

        $DB->update('glpi_configs', ['value' => '1'], [
            'context' => 'plugin:aisuite',
            'name'    => 'provider_keys_encrypted',
        ]);
        if ($DB->affectedRows() === 0) {
            $DB->insert('glpi_configs', [
                'context' => 'plugin:aisuite',
                'name'    => 'provider_keys_encrypted',
                'value'   => '1',
            ]);
        }
        $current_config['provider_keys_encrypted'] = '1';
    }

    // ---------------------------------------------------------
    // 3bis. "Assistant IA" group (auto-created)
    // Tickets the AI is actively handling get assigned to this group, so they
    // show up as "being handled" instead of unassigned. Idempotent: reuses
    // the group referenced by 'level1_ai_group_id' if it still exists, so
    // re-running install/upgrade never creates duplicates. Removed entirely
    // on uninstall (see plugin_aisuite_uninstall()).
    // ---------------------------------------------------------
    $existingAiGroupId = (int)($current_config['level1_ai_group_id'] ?? 0);
    $aiGroup = new Group();
    if ($existingAiGroupId <= 0 || !$aiGroup->getFromDB($existingAiGroupId)) {
        $newAiGroupId = (int)$aiGroup->add([
            'name'         => __('Assistant IA', 'aisuite'),
            'comment'      => __('Groupe créé automatiquement par le plugin AI Suite : les tickets pris en charge par l\'Assistant IA Niveau 1 y sont assignés temporairement.', 'aisuite'),
            'entities_id'  => 0,
            'is_recursive' => 1,
            'is_assign'    => 1,
        ]);
        if ($newAiGroupId) {
            $default_config['level1_ai_group_id'] = $newAiGroupId;
            $current_config['level1_ai_group_id']  = $newAiGroupId;
        }
    }

    if ($isFreshInstall) {
        Config::setConfigurationValues('plugin:aisuite', $default_config);
    } else {
        // Merge any missing keys (upgrade path) without overwriting existing values
        $missing = array_diff_key($default_config, $current_config);
        if (!empty($missing)) {
            Config::setConfigurationValues('plugin:aisuite', array_merge($current_config, $missing));
        } elseif ($newAiGroupId) {
            // No brand-new config keys, but the group was just (re)created for
            // an existing install: persist its id even though nothing else changed.
            Config::setConfigurationValues('plugin:aisuite', $current_config);
        }
    }

    return true;
}

/**
 * Uninstall hook.
 * MUST be named plugin_aisuite_uninstall.
 */
function plugin_aisuite_uninstall() {
    global $DB;

    $migration = new Migration(100);

    $migration->dropTable('glpi_plugin_aismartcheck_analyses');
    $migration->dropTable('glpi_plugin_aismartsorter_logs');
    $migration->dropTable('glpi_plugin_aisuite_level1_logs');

    $migration->executeMigration();

    // CronTask::Unregister($name) only matches the legacy "Plugin{$name}*"
    // itemtype pattern, which doesn't apply to our namespaced class: delete
    // the registration directly instead.
    $DB->delete('glpi_crontasks', ['itemtype' => 'GlpiPlugin\Aisuite\Level1\Queue']);

    // Remove the "Assistant IA" group created at install time, if it still exists.
    $conf      = Config::getConfigurationValues('plugin:aisuite');
    $aiGroupId = (int)($conf['level1_ai_group_id'] ?? 0);
    if ($aiGroupId > 0) {
        $group = new Group();
        if ($group->getFromDB($aiGroupId)) {
            $group->delete(['id' => $aiGroupId], true);
        }
    }

    Config::deleteConfigurationValues('plugin:aisuite');

    return true;
}
