<?php

namespace GlpiPlugin\Aisuite\Level1;

use CommonDBTM;
use CronTask;
use Config;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * GLPI CronTask registrar for the AI Level 1 Assistant background queue.
 *
 * Ticket creation and follow-up replies never call the AI provider directly
 * (see Assistant::handleTicketCreation() / handleFollowupReply()): they only
 * flag the row as `needs_processing` on `glpi_plugin_aisuite_level1_logs`, so
 * GLPI's own save/submit response stays instant. This CronTask ("level1queue",
 * registered in hook.php) is what actually calls the AI provider and posts
 * the resulting followup, shortly after, in the background.
 */
class Queue extends CommonDBTM {

    /**
     * MUST be named cronInfo. Describes the "level1queue" automatic action
     * shown in Setup > Automatic actions.
     */
    public static function cronInfo($name) {
        switch ($name) {
            case 'level1queue':
                return [
                    'description' => __('AI Level 1 Assistant: processes queued ticket analyses and follow-up replies in the background.', 'aisuite'),
                ];
        }
        return [];
    }

    /**
     * MUST be named cron<Name> (matching the "level1queue" name given to
     * CronTask::Register() in hook.php). Called periodically by GLPI's
     * internal or external cron runner.
     */
    public static function cronLevel1queue(CronTask $task) {
        $conf = Config::getConfigurationValues('plugin:aisuite');
        if (isset($conf['level1_enabled']) && !$conf['level1_enabled']) {
            return 0;
        }

        $assistant = new Assistant();
        $processed = $assistant->processQueue($task);

        return $processed > 0 ? 1 : 0;
    }
}
