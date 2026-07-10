<?php

/**
 * Plugin AI Suite - File: setup.php
 *
 * Plugin fusionnant les modules :
 *  - AI Smart Check  (analyse IA sur les tickets)
 *  - AI Smart Sorter (tri / catégorisation automatique des tickets)
 *  - AI Chat         (chatbot IA support niveau 1)
 *
 * Version "full" : aucune logique de licence, tous les modules sont
 * actifs sans restriction.
 */

use GlpiPlugin\Aisuite\SmartCheck\Ticket as SmartCheckTicket;
use GlpiPlugin\Aisuite\SmartSorter\Ticket as SmartSorterTicket;
use GlpiPlugin\Aisuite\SmartSorter\Sorter as SmartSorterSorter;
use GlpiPlugin\Aisuite\Level1\Ticket as Level1Ticket;

define('PLUGIN_AISUITE_VERSION', '1.0.2');

/**
 * Init hooks of the plugin.
 * MUST be named plugin_init_aisuite.
 */
function plugin_init_aisuite() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['aisuite'] = true;
    $PLUGIN_HOOKS['config_page']['aisuite']    = 'front/config.form.php';

    // Technical: registers the 3 provider API key fields as "secured"
    // config values. GLPI's Config::setConfigurationValues() then encrypts
    // them transparently on write (GLPIKey/libsodium) instead of storing
    // plaintext API keys in glpi_configs, and `glpi:security:changekey` will
    // re-encrypt them on key rotation. Reads still need to go through
    // GlpiPlugin\Aisuite\Shared\PluginConfig::get(), since GLPI does not
    // auto-decrypt on read.
    $PLUGIN_HOOKS['secured_configs']['aisuite'] = \GlpiPlugin\Aisuite\Shared\PluginConfig::getSecuredFields();

    if (Plugin::isPluginActive('aisuite')) {

        // Per-module enable/disable switches, each configurable in its own
        // tab of the config screen. Enabled by default so existing installs
        // keep working unchanged after an update.
        $conf = Config::getConfigurationValues('plugin:aisuite');
        $smartcheck_enabled = !isset($conf['smartcheck_enabled']) || (bool)$conf['smartcheck_enabled'];
        $sorter_enabled     = !isset($conf['sorter_enabled'])     || (bool)$conf['sorter_enabled'];
        $chat_enabled       = !isset($conf['chat_enabled'])       || (bool)$conf['chat_enabled'];
        $level1_enabled     = !isset($conf['level1_enabled'])     || (bool)$conf['level1_enabled'];

        // --- AI Smart Check : tab on Ticket ---
        if ($smartcheck_enabled) {
            Plugin::registerClass(SmartCheckTicket::class, [
                'addtabon' => 'Ticket'
            ]);
        }

        if ($sorter_enabled) {
            Plugin::registerClass(SmartSorterTicket::class);
            Plugin::registerClass(SmartSorterSorter::class);

            // Assets JS/CSS (Smart Sorter modal) : interface Central (techniciens) uniquement
            if (Session::getCurrentInterface() === 'central') {
                $PLUGIN_HOOKS['add_javascript']['aisuite'][] = 'js/smartsorter.js';
                $PLUGIN_HOOKS['add_css']['aisuite'][]        = 'css/smartsorter.css';
            }
        }

        if ($level1_enabled) {
            Plugin::registerClass(Level1Ticket::class);

            // Bouton "Désactiver l'IA" : interface Helpdesk (utilisateurs) uniquement
            if (Session::getCurrentInterface() === 'helpdesk') {
                $PLUGIN_HOOKS['add_javascript']['aisuite'][] = 'js/level1.js';
                $PLUGIN_HOOKS['add_css']['aisuite'][]        = 'css/level1.css';
            }

            // Renomme l'auteur affiché dans les notifications (mail) pour les
            // followups postés par l'assistant (users_id = 0, jamais un vrai
            // compte GLPI) en "Assistant IA" au lieu d'un nom vide.
            $PLUGIN_HOOKS['item_get_datas']['aisuite'] = [
                'NotificationTargetTicket' => [Level1Ticket::class, 'hookNotificationData'],
            ];
        }

        // --- item_add dispatcher ---
        // GLPI only allows ONE callback per itemtype per plugin key, so AI Smart
        // Sorter and AI Level 1 Assistant (both triggered right after ticket
        // creation) share a single closure that dispatches to each module
        // independently, based on its own enable/disable flag. AI Level 1
        // Assistant also needs to react to ITILFollowup creation, to continue
        // its conversation once the requester replies in the ticket.
        $PLUGIN_HOOKS['item_add']['aisuite'] = [];

        if ($sorter_enabled || $level1_enabled) {
            $PLUGIN_HOOKS['item_add']['aisuite']['Ticket'] = function ($item) use ($sorter_enabled, $level1_enabled) {
                if ($sorter_enabled) {
                    SmartSorterTicket::hookItemAdd($item);
                }
                if ($level1_enabled) {
                    Level1Ticket::hookItemAdd($item);
                }
            };
        }

        if ($level1_enabled) {
            $PLUGIN_HOOKS['item_add']['aisuite']['ITILFollowup'] = function ($item) {
                Level1Ticket::hookFollowupAdd($item);
            };
        }

        // AI Chat : bulle de discussion, toutes interfaces
        if ($chat_enabled) {
            $PLUGIN_HOOKS['add_javascript']['aisuite'][] = 'js/chatbot.js';
            $PLUGIN_HOOKS['add_css']['aisuite'][]        = 'css/chatbot.css';
        }
    }
}

/**
 * Get the name and the version of the plugin.
 * MUST be named plugin_version_aisuite.
 */
function plugin_version_aisuite() {
    return [
        'name'           => 'AI Suite',
        'version'        => PLUGIN_AISUITE_VERSION,
        'author'         => 'COREFORGE, Jessy Chaila',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://coreforge.fr',
        'requirements'   => [
            'glpi' => [
                'min' => '11.0.0',
            ]
        ],
        'config_page'    => 'front/config.form.php'
    ];
}

/**
 * Check prerequisites before install.
 * MUST be named plugin_aisuite_check_prerequisites.
 */
function plugin_aisuite_check_prerequisites() {
    if (version_compare(GLPI_VERSION, '11.0.0', '<')) {
        echo sprintf(__('This plugin requires GLPI >= %s', 'aisuite'), '11.0.0');
        return false;
    }
    return true;
}
