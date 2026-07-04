<?php

namespace GlpiPlugin\Aisuite\Level1;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Ticket;
use ITILFollowup;
use Group_Ticket;
use CommonITILActor;
use CronTask;
use Toolbox;
use Html;
use GlpiPlugin\Aisuite\Shared\PluginConfig;
use GlpiPlugin\Aisuite\Shared\ProviderFactory;
use GlpiPlugin\Aisuite\Shared\CostCalculator;
use GlpiPlugin\Aisuite\Shared\JsonResponseExtractor;
use GlpiPlugin\Aisuite\Shared\HtmlSanitizer;

/**
 * AI Level 1 Assistant.
 *
 * Runs automatically on every new ticket (item_add hook, same trigger point
 * as AI Smart Sorter), then keeps the conversation going every time the
 * requester answers back (item_add hook on ITILFollowup). It tries to solve
 * the request itself with steps that require neither admin/IT rights nor
 * advanced technical skill. It only hands the ticket off to the group
 * configured in the "AI Level 1 Assistant" config tab once it has asked its
 * basic clarifying questions and still cannot make progress on its own.
 *
 * Conversation state is tracked in `glpi_plugin_aisuite_level1_logs`
 * (one row per ticket): status is one of 'pending' (still talking to the
 * user), 'resolved', 'escalated' or 'user_declined'.
 */
class Assistant {

    // Maximum number of AI question rounds before giving up and escalating:
    // round 1 = the initial questions asked right after ticket creation,
    // round 2 = one more attempt using the user's answer. If it still can't
    // resolve the ticket after that, it escalates instead of asking again.
    private const MAX_ROUNDS = 2;

    private $config;

    // Provider family actually used for the last AI call, kept for cost
    // calculation (set in callAi(), read back in calculateCost()).
    private $lastUsedProviderType = 'openai';

    // Ensures the "process right after this request" shutdown hook is only
    // registered once per PHP process, even if several rows get queued
    // during the same request.
    private static $shutdownScheduled = false;

    public function __construct() {
        $this->config = PluginConfig::get();
    }

    /**
     * Entry point: ticket just created (item_add hook on Ticket). Does NOT call
     * the AI provider directly: it only queues the work (`needs_processing`),
     * so GLPI's own ticket-creation response stays instant. The AI call and the
     * resulting followup are then run immediately after this very request ends
     * (see scheduleImmediateProcessing()), with the "level1queue" CronTask as a
     * pure safety net for anything that would slip through.
     */
    public function handleTicketCreation(Ticket $ticket) {
        $title    = $ticket->fields['name'] ?? '';
        $content  = $ticket->fields['content'] ?? '';
        $ticketId = $ticket->fields['id'];

        $cleanContent = strip_tags(html_entity_decode($content));
        $userQuery    = "Title: $title\nContent: $cleanContent";

        if (strlen($cleanContent) < 10) {
            return;
        }

        $this->saveLogRow($ticketId, [
            'conversation_json' => json_encode([['role' => 'user', 'content' => $userQuery]], JSON_UNESCAPED_UNICODE),
            'status'            => 'pending',
            'round'             => 0,
            'needs_processing'  => 1,
        ]);

        $this->scheduleImmediateProcessing();
    }

    /**
     * Entry point: the requester answered back on the ticket (item_add hook
     * on ITILFollowup). Only continues the conversation if this ticket has
     * a pending (unresolved, not yet escalated/declined) Level 1 exchange.
     * Same as handleTicketCreation(): only queues the reply here, the actual
     * AI call happens right after this request ends, so submitting the reply
     * in GLPI stays instant.
     */
    public function handleFollowupReply(int $ticketId, string $followupContent) {
        $log = $this->getLogRow($ticketId);
        if ($log === null || $log['status'] !== 'pending') {
            return;
        }

        $cleanReply = trim(strip_tags(html_entity_decode($followupContent)));
        if ($cleanReply === '') {
            return;
        }

        $decodedConversation = json_decode($log['conversation_json'] ?? '', true);
        $conversation = is_array($decodedConversation) ? $decodedConversation : [];
        $conversation[] = ['role' => 'user', 'content' => $cleanReply];

        $this->saveLogRow($ticketId, [
            'conversation_json' => json_encode($conversation, JSON_UNESCAPED_UNICODE),
            'needs_processing'  => 1,
        ]);

        $this->scheduleImmediateProcessing();
    }

    /**
     * Makes the queued row(s) get processed right after GLPI has already sent
     * its response back to the browser, instead of waiting for the next
     * "level1queue" CronTask run (which depends on how/whether each client's
     * server actually triggers GLPI's cron: internal page-load probability,
     * system crontab, `glpi:cron` console, etc. — all of that varies between
     * environments, which is exactly what we don't want to depend on here).
     *
     * On PHP-FPM (the overwhelmingly common way GLPI is served today),
     * fastcgi_finish_request() flushes the response immediately and the
     * process keeps running invisibly to actually call the AI provider and
     * post the followup a moment later. On setups without FPM, the callback
     * still runs at the natural end of the script (no behaviour depends on
     * any server configuration either way), so this works identically
     * everywhere with zero environment-specific parameters. The CronTask
     * remains registered purely as a safety net (e.g. if the request is
     * killed before its shutdown functions run).
     */
    private function scheduleImmediateProcessing(): void {
        if (self::$shutdownScheduled) {
            return;
        }
        self::$shutdownScheduled = true;

        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('ignore_user_abort')) {
                // No FPM available: keep running even if the client already
                // navigated away, so the AI call still completes.
                ignore_user_abort(true);
            }

            (new Assistant())->processQueue();
        });
    }

    /**
     * Called by the "level1queue" CronTask (see Queue class): picks up every
     * row flagged `needs_processing` and actually calls the AI provider, then
     * posts the resulting followup (solution / questions / escalation). This
     * is what keeps the AI call off GLPI's own request/response cycle.
     *
     * @param CronTask|null $task  Used to report progress volume in the GLPI
     *                             automatic actions log; null when called
     *                             outside of a real CronTask run.
     * @param int           $limit Max rows processed per run, to keep each
     *                             cron execution short.
     * @return int Number of rows actually processed.
     */
    public function processQueue(?CronTask $task = null, int $limit = 20): int {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_aisuite_level1_logs',
            'WHERE' => ['needs_processing' => 1],
            'ORDER' => 'date_mod ASC',
            'LIMIT' => $limit,
        ]);

        $processed = 0;
        foreach ($iterator as $log) {
            $this->processQueuedRow($log);
            $processed++;
            if ($task !== null) {
                $task->addVolume(1);
            }
        }

        return $processed;
    }

    /* Technical: Actually calls the AI provider for one queued row and processes
     * its response. Clears `needs_processing` up front (before the potentially
     * slow AI call) so an overlapping cron run never picks up the same row twice. */
    private function processQueuedRow(array $log): void {
        $ticketId = (int)$log['tickets_id'];
        $round    = (int)$log['round'] + 1;

        $this->saveLogRow($ticketId, ['needs_processing' => 0]);

        // The ticket may have been closed/resolved by a technician (or deleted)
        // in the time between the reply being queued and this row actually
        // being processed: don't post an AI followup on a ticket a human has
        // already wrapped up, and stop the AI from being involved any further,
        // same as a technician taking over directly.
        $ticket = new Ticket();
        if (
            !$ticket->getFromDB($ticketId)
            || $ticket->isDeleted()
            || in_array((int)$ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)
        ) {
            $this->unassignAiGroup($ticketId);
            $this->saveLogRow($ticketId, [
                'status'           => 'ticket_closed',
                'needs_processing' => 0,
            ]);
            return;
        }

        Toolbox::logInFile('aisuite', sprintf(__("Level 1 Assistant - Ticket #%d Analysis (round %d).", 'aisuite'), $ticketId, $round) . "\n");

        $decoded = json_decode($log['conversation_json'] ?? '', true);
        $conversation = is_array($decoded) ? $decoded : [];

        // Technical: the AI call runs from a background CronTask/shutdown
        // callback (never inline in the ticket-creation request, see
        // scheduleImmediateProcessing()), but a network/API exception here
        // must still not go uncaught: it would otherwise abort processQueue()
        // entirely, leaving every other queued row in this batch unprocessed.
        try {
            $result = $this->callAi($conversation);
        } catch (\Throwable $e) {
            Toolbox::logInFile('aisuite', "AI Level 1 Assistant Exception Ticket #{$ticketId}: " . $e->getMessage() . "\n");
            return;
        }

        if (empty($result['error']) && !empty($result['assistantText'])) {
            $this->processAiResponse($ticketId, $conversation, $result['assistantText'], $result['usage'] ?? [], $round, $log);
        } else {
            Toolbox::logInFile('aisuite', __("AI Level 1 Assistant Error: ", 'aisuite') . ($result['error'] ?? 'Unknown') . "\n");
        }
    }

    /* Technical: Shared AI call (system prompt + provider selection) for both
     * the initial analysis and every follow-up round. */
    private function callAi(array $conversation): array {
        $customContext = $this->config['level1_system_prompt'] ?? '';

        /* Technical: Prompt engineering enforcing the "no admin rights / no technical skill"
         * boundary, and a strict JSON contract so the response can be parsed reliably. */
        $systemPrompt = "You are a Level 1 IT support assistant working directly inside a GLPI helpdesk ticket, talking to the end user who opened it. This is an ongoing conversation: earlier turns may already contain your previous questions and the user's answers.

STRICT RULE: Write all user-facing text in the EXACT same language as the ticket content.

YOUR TASK:
Decide whether you can now guide the user to a solution using ONLY actions that:
- require NO administrator or IT privileges of any kind,
- require NO advanced technical skill,
- are safe for a regular office employee to perform themselves in an enterprise context
  (e.g. restarting an application, checking a cable, clearing a browser cache, adjusting
  a setting in their own user account, restarting their own workstation).

If you now have enough information, write the solution as short, numbered,
easy-to-follow steps for a non-technical reader.

If you still don't have enough information, prepare a short list of relevant
clarifying questions (avoid repeating questions already asked earlier in the
conversation).

Additional context provided by the administrator: $customContext

Output STRICTLY valid JSON (no markdown, no code fences), with this exact structure:
{
  \"resolved\": true or false,
  \"solution_html\": \"<p>...</p><ol><li>...</li></ol>\" (ONLY if resolved is true; safe HTML fragment, no <html>/<body> tags, written in the user's language),
  \"clarifying_questions\": [\"question 1\", \"question 2\"] (ONLY if resolved is false; written in the user's language; max 4 questions),
  \"reasoning\": \"short internal reasoning, any language\"
}";

        $providerType = ProviderFactory::normalizeType($this->config['provider_active'] ?? 'openai');
        $provider     = ProviderFactory::make($providerType);

        $selectedModel = trim((string)($this->config['provider_' . $providerType . '_model'] ?? ''));
        $aiConfig = [
            'api_url'  => $this->config['provider_' . $providerType . '_url'] ?? '',
            'api_key'  => $this->config['provider_' . $providerType . '_key'] ?? '',
            'ai_model' => $selectedModel
        ];

        $this->lastUsedProviderType = $providerType;

        return $provider->call($systemPrompt, $conversation, $aiConfig);
    }

    /* Technical: Robust JSON extraction and processing of the AI response, for
     * both the initial round and every follow-up round. */
    private function processAiResponse(int $ticketId, array $conversation, $stringResponse, $usage, int $round, ?array $existingLog = null) {
        $extraction = JsonResponseExtractor::extract((string)$stringResponse);
        $data       = $extraction['data'];
        $cleanJson  = $extraction['cleanJson'];

        if ($data === null) {
            Toolbox::logInFile('aisuite', "Level 1 Assistant JSON Decode Error Ticket #{$ticketId}: " . $extraction['error'] . " | Raw: " . substr(trim((string)$stringResponse), 0, 150) . "\n");
            return;
        }

        $resolved   = !empty($data['resolved']) && !empty($data['solution_html']);
        $costData   = CostCalculator::compute($this->lastUsedProviderType, $this->config, $usage);
        $prevCost   = (float)($existingLog['execution_cost'] ?? 0);
        $prevTokens = (int)($existingLog['token_usage'] ?? 0);

        if ($resolved) {
            // Technical: solution_html is AI-generated (JSON field the model is
            // asked to return). Sanitized before being persisted into the
            // ticket's public followup, since a successful prompt-injection in
            // the ticket content could otherwise make the model emit a stored
            // XSS payload (script tags, event handlers, javascript: links...).
            $safeHtml = HtmlSanitizer::sanitizeStrict((string)$data['solution_html']);
            $this->postSolutionFollowup($ticketId, $safeHtml, $round);
            $this->assignAiGroup($ticketId);
            $status = 'resolved';
        } else {
            $questions = is_array($data['clarifying_questions'] ?? null) ? $data['clarifying_questions'] : [];

            if ($round >= self::MAX_ROUNDS) {
                // The assistant asked its basic questions (and, if applicable, a follow-up
                // round using the user's answer) and still cannot make progress on its own:
                // hand off to the configured technician group now (escalateToGroup() also
                // removes the "Assistant IA" group assignment).
                $this->postEscalationFollowup($ticketId);
                $this->escalateToGroup($ticketId);
                $status = 'escalated';
            } else {
                $this->postQuestionsFollowup($ticketId, $questions, $round);
                $this->assignAiGroup($ticketId);
                $status = 'pending';
            }

            // Keep the assistant's own turn in the conversation transcript so the
            // next round has full context (its questions + the user's next answer).
            $assistantTurn = !empty($questions)
                ? implode(' ', $questions)
                : (string)($data['reasoning'] ?? '');
            $conversation[] = ['role' => 'assistant', 'content' => $assistantTurn];
        }

        $this->saveLogRow($ticketId, [
            'conversation_json' => json_encode($conversation, JSON_UNESCAPED_UNICODE),
            'ai_response'       => $cleanJson,
            'status'            => $status,
            'round'             => $round,
            'execution_cost'    => round($prevCost + $costData['cost'], 6),
            'token_usage'       => $prevTokens + $costData['tokens'],
        ]);
    }

    /* Technical: Post the level-1 solution as a PUBLIC followup, visible to the requester.
     * Authored by the assistant itself (users_id = 0), never by whichever human happened
     * to be logged in when the underlying hook fired. */
    private function postSolutionFollowup(int $ticketId, string $solutionHtml, int $round) {
        $header = "<strong>" . __('🤖 Assistant IA — Suggestion de résolution :', 'aisuite') . "</strong><br>";

        $fup = new ITILFollowup();
        $fup->add([
            'itemtype'   => 'Ticket',
            'items_id'   => $ticketId,
            'content'    => $header . $solutionHtml,
            'is_private' => 0,
            'users_id'   => 0,
        ]);
    }

    /* Technical: Post the clarifying questions as a PUBLIC followup. Authored by the
     * assistant itself (users_id = 0). No escalation at this point: the ticket is only
     * reassigned once the round cap is reached (see processAiResponse()). */
    private function postQuestionsFollowup(int $ticketId, array $questions, int $round) {
        $header = ($round <= 1)
            ? __("🤖 Assistant IA : pourriez-vous préciser quelques points pour m'aider à vous proposer une solution ?", 'aisuite')
            : __("🤖 Assistant IA : merci pour ces précisions. J'ai encore besoin de quelques éléments :", 'aisuite');

        $content = "<strong>" . $header . "</strong>";

        $list = '';
        foreach ($questions as $question) {
            $list .= '<li>' . Html::entities_deep((string)$question) . '</li>';
        }
        if ($list !== '') {
            $content .= "<ul>$list</ul>";
        }

        $fup = new ITILFollowup();
        $fup->add([
            'itemtype'   => 'Ticket',
            'items_id'   => $ticketId,
            'content'    => $content,
            'is_private' => 0,
            'users_id'   => 0,
        ]);
    }

    /* Technical: Post the final "handing off to a human" message once the round cap
     * is reached. Authored by the assistant itself (users_id = 0). */
    private function postEscalationFollowup(int $ticketId) {
        $content = "<strong>" . __("🤖 Assistant IA : merci pour ces précisions. Je ne suis malheureusement pas en mesure de résoudre ce ticket seul, un technicien va prendre le relais.", 'aisuite') . "</strong>";

        $fup = new ITILFollowup();
        $fup->add([
            'itemtype'   => 'Ticket',
            'items_id'   => $ticketId,
            'content'    => $content,
            'is_private' => 0,
            'users_id'   => 0,
        ]);
    }

    /* Technical: Reassign the ticket to the group configured in the module's config tab
     * (used only when the AI genuinely can't help anymore, i.e. the round cap was reached
     * without a manual opt-out — see processAiResponse()). Always removes the "Assistant IA"
     * group assignment first: the ticket is handed off, it's no longer "being handled by AI". */
    public function escalateToGroup(int $ticketId): bool {
        $groupId = (int)($this->config['level1_escalation_group'] ?? 0);
        if ($groupId <= 0) {
            return false;
        }

        $this->unassignAiGroup($ticketId);

        return $this->addGroupAssignment($ticketId, $groupId);
    }

    /**
     * Mark a ticket as "user declined AI handling" (opt-out button). Public:
     * called directly from public/ajax.level1.php. Per the plugin's design,
     * opting out never assigns the configured escalation group: it simply
     * removes the "Assistant IA" group assignment, leaving the ticket
     * unassigned ("Incoming tickets") unless a technician assigns it manually.
     */
    public function markUserDeclined(int $ticketId) {
        $this->unassignAiGroup($ticketId);
        $this->saveLogRow($ticketId, [
            'status'           => 'user_declined',
            'needs_processing' => 0,
        ]);
    }

    /**
     * Called when a technical-profile user (technician, admin, super-admin,
     * observer...) answers directly on a ticket the assistant was still
     * handling: the AI steps back for good on this ticket, same effect as a
     * manual opt-out (group unassigned, no more processing), but attributed
     * to a human agent taking over rather than the requester declining.
     */
    public function markTechnicianTakeover(int $ticketId): void {
        $log = $this->getLogRow($ticketId);
        if ($log === null || $log['status'] !== 'pending') {
            return;
        }

        $this->unassignAiGroup($ticketId);
        $this->saveLogRow($ticketId, [
            'status'           => 'technician_takeover',
            'needs_processing' => 0,
        ]);
    }

    /**
     * Whether the AI has been permanently stepped back on this specific ticket
     * (manual opt-out or technician takeover). Public: used by
     * public/ajax.level1.php so the "Disable AI" button can stay hidden on
     * page reload instead of reappearing once the AI is no longer involved.
     */
    public function isAiDisabledForTicket(int $ticketId): bool {
        $log = $this->getLogRow($ticketId);
        if ($log === null) {
            return false;
        }
        return in_array($log['status'], ['user_declined', 'technician_takeover', 'ticket_closed'], true);
    }

    /* Technical: Assigns the ticket to the auto-created "Assistant IA" group so it
     * shows up as being actively handled by the AI instead of unassigned. Called
     * every time the assistant posts a followup that isn't an escalation (solution
     * or clarifying questions) — see processAiResponse(). */
    private function assignAiGroup(int $ticketId): void {
        $groupId = $this->getAiGroupId();
        if ($groupId <= 0) {
            return;
        }
        $this->addGroupAssignment($ticketId, $groupId);
    }

    /* Technical: Removes the "Assistant IA" group assignment: on opt-out, on
     * technician takeover, or right before escalating to the configured group.
     * Leaves the ticket unassigned ("Incoming tickets") unless another group
     * gets assigned right after. */
    private function unassignAiGroup(int $ticketId): void {
        $groupId = $this->getAiGroupId();
        if ($groupId <= 0) {
            return;
        }
        $this->removeGroupAssignment($ticketId, $groupId);
    }

    private function getAiGroupId(): int {
        return (int)($this->config['level1_ai_group_id'] ?? 0);
    }

    /* Technical: Assign $groupId to $ticketId (type ASSIGN), skipping if already assigned. */
    private function addGroupAssignment(int $ticketId, int $groupId): bool {
        $groupTicket = new Group_Ticket();
        $already = $groupTicket->find([
            'tickets_id' => $ticketId,
            'groups_id'  => $groupId,
            'type'       => CommonITILActor::ASSIGN,
        ]);

        if (!empty($already)) {
            return true;
        }

        return (bool)$groupTicket->add([
            'tickets_id' => $ticketId,
            'groups_id'  => $groupId,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    }

    /* Technical: Remove any ASSIGN link between $ticketId and $groupId. */
    private function removeGroupAssignment(int $ticketId, int $groupId): void {
        $groupTicket = new Group_Ticket();
        $existing = $groupTicket->find([
            'tickets_id' => $ticketId,
            'groups_id'  => $groupId,
            'type'       => CommonITILActor::ASSIGN,
        ]);

        foreach ($existing as $row) {
            $groupTicket->delete(['id' => $row['id']]);
        }
    }

    /* Technical: Fetch the current Level 1 conversation state for a ticket, if any. */
    private function getLogRow(int $ticketId): ?array {
        global $DB;
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_aisuite_level1_logs',
            'WHERE' => ['tickets_id' => $ticketId],
        ]);
        return count($iterator) ? $iterator->current() : null;
    }

    /* Technical: Insert or update (upsert) the Level 1 conversation state for a ticket. */
    private function saveLogRow(int $ticketId, array $fields) {
        global $DB;
        $existing = $this->getLogRow($ticketId);

        if ($existing !== null) {
            $DB->update('glpi_plugin_aisuite_level1_logs', $fields, ['tickets_id' => $ticketId]);
        } else {
            $DB->insert('glpi_plugin_aisuite_level1_logs', array_merge(['tickets_id' => $ticketId], $fields));
        }
    }

}
