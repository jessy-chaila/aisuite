<?php

namespace GlpiPlugin\Aisuite\SmartSorter;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

use Ticket;
use Item_Ticket;
use TicketTask;
use Planning;
use Session;
use Toolbox;
use CommonITILActor;
use GlpiPlugin\Aisuite\Shared\PluginConfig;
use GlpiPlugin\Aisuite\Shared\ProviderFactory;
use GlpiPlugin\Aisuite\Shared\CostCalculator;
use GlpiPlugin\Aisuite\Shared\JsonResponseExtractor;

class Sorter {

    private $config;

    // Model / provider actually used for the last AI call, kept for cost
    // calculation (set in handleTicketCreation(), read back in processAiResponse()).
    private $lastUsedModel = '';
    private $lastUsedProviderType = 'openai';

    public function __construct() {
        $this->config = PluginConfig::get();
    }

    public function handleTicketCreation(Ticket $ticket) {
        global $DB;

        $title      = $ticket->fields['name'] ?? '';
        $content      = $ticket->fields['content'] ?? '';
        $ticketId     = $ticket->fields['id'];

        $cleanContent = strip_tags(html_entity_decode($content));
        $userQuery    = "Title: $title\nContent: $cleanContent";

        if (strlen($cleanContent) < 10) {
            return;
        }

        $categoriesMap = $this->getGLPICategories();
        $assetsMap     = $this->getRequesterAssets($ticket);

        Toolbox::logInFile('aisuite', sprintf(__("Ticket #%d Analysis.", 'aisuite'), $ticketId) . "\n");

        $assetsListStr = empty($assetsMap)
            ? "No assets linked to user."
            : json_encode(array_keys($assetsMap), JSON_UNESCAPED_UNICODE);

        $categoriesStr = json_encode($categoriesMap, JSON_UNESCAPED_UNICODE);
        $customContext = $this->config['sorter_system_prompt_context'] ?? '';

        /* Technical: Reinforced prompt with few-shot examples for strict language matching */
        $systemPrompt = "You are an expert IT Service Desk dispatcher working with GLPI.

        STRICT RULE: The 'reasoning' field MUST BE in the EXACT same language as the user's input.

        EXAMPLES:
        User Input: \"Mon écran est noir\"
        Reasoning: \"L'utilisateur signale un problème d'affichage...\"

        User Input: \"My screen is black\"
        Reasoning: \"The user is reporting a display issue...\"

        YOUR TASK:
        Analyze the user request and map it to the EXISTING database entries provided below.

        AVAILABLE CATEGORIES (ID => Name):
        $categoriesStr

        USER ASSETS (Type - Name):
        $assetsListStr

        RULES:
        1. You MUST choose a 'suggested_category_id' strictly from the AVAILABLE CATEGORIES list.
        2. If the text mentions a device, check if it matches one in USER ASSETS using logical deduction.
        3. Output strictly valid JSON.
        4. Write the 'reasoning' in the SAME LANGUAGE as the user request (STRICTLY).

        Context provided by admin: $customContext

        JSON OUTPUT FORMAT:
        {
            \"suggested_category_id\": <ID_INT_OR_NULL>,
            \"suggested_category_name\": \"<NAME_STRING>\",
            \"confidence_score\": <0-100>,
            \"detected_hardware_name\": \"<EXACT_NAME_FROM_USER_ASSETS_OR_NULL>\",
            \"reasoning\": \"<REASONING_IN_THE_SAME_LANGUAGE_AS_USER_INPUT>\"
        }";

        // AI Smart Sorter follows the AI Suite-wide active provider (Providers tab),
        // same as AI Smart Check and AI Chatbot.
        $providerType = ProviderFactory::normalizeType($this->config['provider_active'] ?? 'openai');
        $provider     = ProviderFactory::make($providerType);

        $conversation = [['role' => 'user', 'content' => $userQuery]];
        $selectedModel = trim((string)($this->config['provider_' . $providerType . '_model'] ?? ''));
        $aiConfig = [
            'api_url'  => $this->config['provider_' . $providerType . '_url'] ?? '',
            'api_key'  => $this->config['provider_' . $providerType . '_key'] ?? '',
            'ai_model' => $selectedModel
        ];

        // Kept for cost calculation in processAiResponse().
        $this->lastUsedModel = $selectedModel;
        $this->lastUsedProviderType = $providerType;

        // Technical: this hook runs synchronously inside the item_add hook,
        // i.e. directly in the ticket-creation request/response cycle
        // (unlike AI Level 1 Assistant, which queues its own AI call to a
        // background CronTask). A network/API exception from the provider
        // call must never propagate out of here, or it would break ticket
        // creation for the end user for a purely best-effort classification
        // feature.
        try {
            $result = $provider->call($systemPrompt, $conversation, $aiConfig);
        } catch (\Throwable $e) {
            Toolbox::logInFile('aisuite', "AI Smart Sorter Exception Ticket #{$ticketId}: " . $e->getMessage() . "\n");
            return;
        }

        if (empty($result['error']) && !empty($result['assistantText'])) {

            /* Technical: Retrieve token usage metrics */
            $usage = $result['usage'] ?? [];

            $this->processAiResponse($ticket, $result['assistantText'], $userQuery, $assetsMap, $categoriesMap, $usage);

        } else {
            Toolbox::logInFile('aisuite', __("AI Error: ", 'aisuite') . ($result['error'] ?? 'Unknown') . "\n");
        }
    }

    /* Technical: Robust JSON extraction and processing of AI response */
    private function processAiResponse(Ticket $ticket, $stringResponse, $originalInput, $assetsMap, $categoriesMap, $usage = []) {
        global $DB;

        $extraction = JsonResponseExtractor::extract((string)$stringResponse);
        $data       = $extraction['data'];
        $cleanJson  = $extraction['cleanJson'];

        if ($data === null) {
            Toolbox::logInFile('aisuite', "JSON Decode Error Ticket #{$ticket->getID()}: " . $extraction['error'] . " | Raw: " . substr(trim((string)$stringResponse), 0, 150) . "\n");
            return;
        }

        // Technical: never trust detected_hardware_id/type directly from the
        // AI's raw JSON output - a successful prompt injection in the ticket
        // content could otherwise fabricate an arbitrary item ID/type here.
        // These two fields may only ever be set below, via the assetsMap
        // lookup keyed on the AI-suggested hardware NAME, which is itself
        // restricted to assets actually owned by the ticket's requester
        // (see getRequesterAssets()).
        unset($data['detected_hardware_id'], $data['detected_hardware_type']);

        $confidence = (int)($data['confidence_score'] ?? 0);
        $detectedName = $data['detected_hardware_name'] ?? null;

        /* Technical: Map AI detected asset name to database ID and Type */
        if ($detectedName && isset($assetsMap[$detectedName])) {
            $data['detected_hardware_id']      = $assetsMap[$detectedName]['id'];
            $data['detected_hardware_type']    = $assetsMap[$detectedName]['type'];
            $data['detected_hardware_display'] = $assetsMap[$detectedName]['translated_label'];
            $cleanJson = json_encode($data);
        }

        /* Technical: Real-time cost calculation based on the admin-configured price */
        $costData = CostCalculator::compute($this->lastUsedProviderType, $this->config, $usage);

        $executionCost = $costData['cost'];
        $totalTokens   = $costData['tokens'];

        /* Technical: Automated classification logic based on confidence threshold */
        $autoMode  = (bool)($this->config['sorter_enable_auto_mode'] ?? 0);
        $threshold = (int)($this->config['sorter_confidence_threshold'] ?? 80);
        $action    = 'suggestion_only';

        if ($autoMode && $confidence >= $threshold) {
            $this->applyChangesDirectly($ticket, $data, $categoriesMap);
            $action = 'auto_applied';
            Toolbox::logInFile('aisuite', sprintf(__("Auto-Applied changes for Ticket #%d (Score: %d%%)", 'aisuite'), $ticket->getID(), $confidence) . "\n");
        }

        /* Technical: Audit trail logging */
        $DB->insert('glpi_plugin_aismartsorter_logs', [
            'tickets_id'       => $ticket->fields['id'],
            'input_data'       => $originalInput,
            'ai_response'      => $cleanJson,
            'confidence_score' => $confidence,
            'action_taken'     => $action,
            'execution_cost'   => $executionCost,
            'token_usage'      => $totalTokens
        ]);
    }

    /* Technical: Apply ITIL category and hardware link via GLPI Ticket API.
     * $categoriesMap is the exact set of helpdesk-visible categories that
     * was actually offered to the AI (see getGLPICategories()): the AI's
     * 'suggested_category_id' is only ever applied if it's one of these -
     * never trusted as an arbitrary ID, in case a prompt injection in the
     * ticket content made the model return an ID it was never offered
     * (e.g. a category from a different, non-helpdesk-visible context). */
    private function applyChangesDirectly(Ticket $ticket, $aiData, array $categoriesMap = []) {
        $ticketId = $ticket->getID();

        $newCategoryId = isset($aiData['suggested_category_id']) ? (int)$aiData['suggested_category_id'] : 0;
        if ($newCategoryId > 0 && isset($categoriesMap[$newCategoryId])) {
            $ticket->update([
                'id'                => $ticketId,
                'itilcategories_id' => $newCategoryId
            ]);
        } else {
            $newCategoryId = 0;
        }

        // The hardware type comes from AI-generated JSON: only ever instantiate
        // one of the explicitly whitelisted item types, never an arbitrary class.
        $allowedHardwareTypes = ['Computer', 'Monitor', 'Printer', 'Phone', 'Peripheral'];

        $hardwareLinked = false;
        if (
            !empty($aiData['detected_hardware_id'])
            && !empty($aiData['detected_hardware_type'])
            && in_array($aiData['detected_hardware_type'], $allowedHardwareTypes, true)
            && class_exists($aiData['detected_hardware_type'])
        ) {
            // Technical: revalidate the item actually belongs to the same
            // entity as the ticket before linking it. detected_hardware_id
            // is only ever populated from getRequesterAssets() (see
            // processAiResponse()), which already scopes to the requester's
            // own assets, but this check stays defense in depth against any
            // future change to that lookup weakening the guarantee.
            $item = new $aiData['detected_hardware_type']();
            $itemFound = $item->getFromDB($aiData['detected_hardware_id']);
            $sameEntity = $itemFound
                && \Session::haveAccessToEntity((int)($item->fields['entities_id'] ?? -1))
                && (int)($item->fields['entities_id'] ?? -1) === (int)$ticket->fields['entities_id'];

            if ($itemFound && $sameEntity) {
                $itemTicket = new Item_Ticket();
                $itemTicket->add([
                    'tickets_id' => $ticketId,
                    'itemtype'   => $aiData['detected_hardware_type'],
                    'items_id'   => $aiData['detected_hardware_id']
                ]);

                $link = $item->getLink();

                $taskContent = sprintf(
                    __('⚡ AI Auto-Action (Confiance %s%%) : Catégorie définie et matériel %s lié automatiquement.', 'aisuite'),
                    $aiData['confidence_score'],
                    $link
                );

                $task = new TicketTask();
                $task->add([
                    'tickets_id' => $ticketId,
                    'is_private' => 1,
                    'content'    => $taskContent,
                    'users_id'   => Session::getLoginUserID() ?: 0,
                    'state'      => Planning::DONE
                ]);
                $hardwareLinked = true;
            }
        }

        if (!$hardwareLinked && $newCategoryId > 0) {
            $task = new TicketTask();
            $task->add([
                'tickets_id' => $ticketId,
                'is_private' => 1,
                'content'    => sprintf(__('⚡ AI Auto-Action (Confiance %s%%) : Catégorie mise à jour automatiquement.', 'aisuite'), $aiData['confidence_score']),
                'users_id'   => Session::getLoginUserID() ?: 0,
                'state'      => Planning::DONE
            ]);
        }
    }

    /* Technical: Fetch visible ITIL categories from DB (Limited to 150) */
    private function getGLPICategories() {
        global $DB;
        $cats = [];
        if (!$DB->tableExists('glpi_itilcategories')) return [];
        $iterator = $DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['is_helpdeskvisible' => 1], 'ORDER' => 'completename']);
        foreach ($iterator as $row) { if(count($cats)>150) break; $cats[$row['id']] = $row['completename']; }
        return $cats;
    }

    /* Technical: Retrieve all assets currently assigned to the ticket requester */
    private function getRequesterAssets(Ticket $ticket) {
        global $DB;

        $users = $ticket->getUsers(\CommonITILActor::REQUESTER);
        if (empty($users)) return [];

        $userId = $users[0]['users_id'];
        $assetsMap = [];
        $itemTypes = ['Computer', 'Monitor', 'Printer', 'Phone', 'Peripheral'];

        foreach ($itemTypes as $type) {
            if (!class_exists($type)) continue;

            $item = new $type();
            $table = $item->getTable();

            if (!$DB->tableExists($table)) continue;
            $columns = $DB->listFields($table);
            if (!isset($columns['users_id'])) continue;

            $where = ['users_id' => $userId, 'is_deleted' => 0];
            if (isset($columns['is_template'])) $where['is_template'] = 0;

            $iterator = $DB->request(['SELECT' => ['id', 'name'], 'FROM' => $table, 'WHERE' => $where]);

            foreach ($iterator as $data) {
                $technicalKey = $type . " - " . $data['name'];
                $translatedType = $item->getTypeName(1);
                $translatedLabel = $translatedType . " - " . $data['name'];

                $assetsMap[$technicalKey] = [
                    'type' => $type,
                    'id'   => $data['id'],
                    'translated_label' => $translatedLabel
                ];
            }
        }
        return $assetsMap;
    }
}
