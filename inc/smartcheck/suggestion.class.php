<?php

namespace GlpiPlugin\Aisuite\SmartCheck;

use Ticket;
use ITILFollowup;
use Session;
use Config;
use DB;
use Toolbox;
use GlpiPlugin\Aisuite\Shared\PluginConfig;
use GlpiPlugin\Aisuite\Shared\ProviderFactory;
use GlpiPlugin\Aisuite\Shared\CostCalculator;
use GlpiPlugin\Aisuite\Shared\HtmlSanitizer;

class Suggestion {

    /**
     * Generates the AI analysis HTML for a specific ticket.
     * @param int $ticketId
     * @return string HTML content or Error message
     */
    public static function getAnalysisHtml(int $ticketId): string {
        global $CFG_GLPI;

        // 1. Retrieve Ticket Data
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return "<div class='alert alert-danger'>" . __('Ticket introuvable.', 'aisuite') . "</div>";
        }

        $title = $ticket->fields['name'];
        // Strip tags to reduce token usage and noise
        $contentRaw = strip_tags(html_entity_decode($ticket->fields['content']));
        $content = substr($contentRaw, 0, 15000);

        // 2. Load Configuration (Shared AI Suite config)
        $conf = PluginConfig::get();

        // AI Smart Check follows the AI Suite-wide active provider (Providers tab).
        $providerType = ProviderFactory::normalizeType($conf['provider_active'] ?? 'openai');
        $apiUrl       = $conf['provider_' . $providerType . '_url'] ?? '';
        $apiKey       = $conf['provider_' . $providerType . '_key'] ?? '';

        // Determine Model: Use saved model or fallback defaults based on provider
        $savedModel = $conf['provider_' . $providerType . '_model'] ?? '';
        if (!empty($savedModel)) {
            $aiModel = $savedModel;
        } else {
            // Updated defaults
            if ($providerType === 'anthropic') {
                $aiModel = 'claude-3-5-sonnet-20240620';
            } elseif ($providerType === 'google') {
                $aiModel = 'gemini-1.5-pro';
            } else {
                $aiModel = 'gpt-4o'; // Default for OpenAI-compatible endpoints
            }
        }

        $systemPrompt = $conf['smartcheck_system_prompt'] ?? 'You are a helpful IT assistant.';
        $enableKbSearch = (int)($conf['smartcheck_enable_kb_search'] ?? 0);

        if (empty($apiKey)) {
             return "<div class='alert alert-danger'>" . __('Clé API manquante. Configurez le fournisseur IA actif dans l\'onglet « Fournisseurs IA ».', 'aisuite') . "</div>";
        }

        // --- TRANSLATION VARIABLES ---
        $t_actions     = __('Actions Recommandées', 'aisuite');
        $t_articles    = __('Articles suggérés', 'aisuite');
        $t_high_txt    = __('HAUTE', 'aisuite');
        $t_med_txt     = __('MOYENNE', 'aisuite');
        $t_low_txt     = __('BASSE', 'aisuite');
        $t_found       = __('article(s) trouvé(s) dans la base de connaissances', 'aisuite');
        $t_none        = __('Aucun article pertinent trouvé depuis la base de connaissance', 'aisuite');

        // Instruction to force AI to respect ticket language for content
        $langInstruction = <<<TEXT
CRITICAL LANGUAGE RULE:
1. The specific headers in the HTML Template below (like "{$t_actions}", "{$t_articles}") are provided in the USER'S interface language.
2. DO NOT let these headers influence your output language.
3. DETECT the language of the TICKET CONTENT (Title/Description).
4. YOU MUST WRITE the [Diagnosis Title], [Diagnosis Description], and [Action Step Description] in the SAME LANGUAGE as the Ticket Content.
   - If Ticket is English -> Output English content (even if headers are French).
   - If Ticket is French -> Output French content.
TEXT;

        // --- KB Logic Setup ---
        $kbContext = "";

        if ($enableKbSearch === 1) {
            $articles = self::searchKnowledgeBase($title);

            if (!empty($articles)) {
                $kbListJson = json_encode($articles, JSON_UNESCAPED_UNICODE);
                $kbBaseUrl = $CFG_GLPI['root_doc'] . "/front/knowbaseitem.form.php?id=";

                $kbContext = <<<TXT

[INTERNAL KNOWLEDGE BASE DATA]
STATUS: FOUND
BASE_URL: {$kbBaseUrl}
AVAILABLE_ARTICLES: {$kbListJson}
TXT;
            } else {
                $kbContext = <<<TXT

[INTERNAL KNOWLEDGE BASE DATA]
STATUS: EMPTY (No matching articles found in local DB)
TXT;
            }
        }

        // Template Instruction
        $kbTemplateInstruction = <<<HTML
       <div class="kb-section">
           <ul class="list-group">
               <li class="list-group-item">
                   <a href="[LINK]" target="_blank"><i class="fas fa-external-link-alt"></i> [Title]</a>
               </li>
           </ul>
           </div>
HTML;

        // KB Rules
        $kbRules = <<<RULES
**KNOWLEDGE BASE (KB) RULES:**
1. **SOURCE:** Only use articles from `[INTERNAL KNOWLEDGE BASE DATA]`.
2. **NO HALLUCINATIONS:** No external links.
3. **LOGIC:**
   - **CASE A (Relevant Article Found):** List the article link(s). BELOW the list, display this specific footer: `<div class="text-muted small mt-2">[INSERT NUMBER] {$t_found}</div>`.
   - **CASE B (No Article):** Display EXACTLY this paragraph: `<p class="text-muted fst-italic mt-2">{$t_none}</p>`.
   - **NEVER** display both Case A and Case B footers together. It is strictly one OR the other.
RULES;

        // 3. Prompt Engineering (full version: always 10 actionable steps)
        $constraintInstruction = "**QUANTITY RULE:** Provide **EXACTLY 10 actionable steps**.";

        // Define the HTML structure expected from the AI
        $audienceInstruction = <<<TEXT
**AUDIENCE (CRITICAL):** This analysis is read exclusively by an experienced IT
technician/system administrator working on the ticket, NEVER by the
end-user/requester. Write accordingly:
- Use precise technical vocabulary (system administration, networking,
  hardware, software error codes, log/config file names, CLI commands when
  relevant).
- Do NOT write reassuring, simplified, or "customer support" style sentences
  aimed at a non-technical end-user.
- Assume the reader already knows IT fundamentals - go straight to the
  diagnosis and the concrete technical action to perform (what to check,
  where, with which tool/command), not generic advice.
TEXT;

        $formattingRules = <<<PROMPT
IMPORTANT - OUTPUT FORMAT RULES (STRICT):
1. Respond ONLY in valid HTML (no markdown, no code blocks).
2. Do NOT include <html>, <head>, or <body> tags.
3. {$langInstruction}

{$audienceInstruction}

{$constraintInstruction}

**VISUAL STYLE RULES (BADGES):**
You must assign the correct Bootstrap class based on the priority you decide:
- **{$t_high_txt}:** Use class `bg-danger` (Red).
- **{$t_med_txt}:** Use class `bg-warning text-dark` (Yellow).
- **{$t_low_txt}:** Use class `bg-secondary` (Gray).

**SORTING & GROUPING RULES (CRITICAL):**
1. **GROUP BY PRIORITY:** You MUST output items in this strict order:
   - First: ALL items with **{$t_high_txt}** priority.
   - Second: ALL items with **{$t_med_txt}** priority.
   - Third: ALL items with **{$t_low_txt}** priority.
2. **NO MIXING:** Never place a '{$t_high_txt}' item after a '{$t_med_txt}' item.

{$kbRules}

**HTML TEMPLATE (MANDATORY):**
You MUST use this template structure.

   <div class="ai-analysis-block">
       <h4>[Diagnosis Title]</h4>
       <p>[Brief Diagnosis Description]</p>

       <h4 class="mt-3">{$t_actions}</h4>
       <ul class="list-group list-group-flush">
           <li class="list-group-item">
               <label class="form-check-label d-flex align-items-center" style="cursor:pointer; width:100%;">
                   <input class="form-check-input me-3 ai-checkbox" type="checkbox">
                   <span class="badge [CLASS] ai-priority-badge">[{$t_high_txt}/{$t_med_txt}/{$t_low_txt}]</span>
                   <span class="ms-2">[Action Step Description]</span>
               </label>
           </li>
       </ul>

       <h4 class="mt-4"><i class="fas fa-book"></i> {$t_articles}</h4>
       {$kbTemplateInstruction}

   </div>
PROMPT;

        $finalSystemPrompt = $systemPrompt . "\n\n" . $formattingRules;
        $userPromptContent = "Ticket: $title\n\nDesc:\n$content" . $kbContext;

        $conversation = [['role' => 'user', 'content' => $userPromptContent]];

        $config = [
            'ai_api_url' => $apiUrl,
            'ai_api_key' => $apiKey,
            'ai_model'   => $aiModel
        ];

        // 4. Call AI Provider
        try {
            $provider = ProviderFactory::make($providerType);

            $response = $provider->call($finalSystemPrompt, $conversation, $config);

            if ($response['error']) {
                return "<div class='alert alert-danger'>" . sprintf(__('Erreur Provider : %s', 'aisuite'), $response['error']) . "</div>";
            }
            if (empty($response['assistantText'])) {
                return "<div class='alert alert-danger'>" . __('Réponse vide de l\'IA.', 'aisuite') . "</div>";
            }

            $rawContent = str_replace(['```html', '```'], '', $response['assistantText']);

            // Technical: this HTML is entirely AI-generated. A successful
            // prompt injection in the ticket content could otherwise make
            // the model emit <script>, event-handler attributes, javascript:
            // links, etc., which would then be persisted and re-displayed as
            // a stored XSS payload. The mandated template (badges, checkbox
            // list, KB links) still needs div/span/label/input structure and
            // one inline style, so this uses the denylist-based "rich" mode
            // rather than a tag allowlist that would break the intended UI.
            $cleanContent = HtmlSanitizer::sanitizeRich($rawContent);

            // --- COST CALCULATION BLOCK ---
            $usage = $response['usage'] ?? ['total_tokens' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0];
            $costInfo = CostCalculator::computeFormatted($providerType, $conf, $usage);

            // Format the cost string (Translatable)
            $txtCost = sprintf(
                __('Coût : %s Tokens utilisés (~%s €)', 'aisuite'),
                $usage['total_tokens'],
                $costInfo
            );

            // Append cost info at the bottom of the HTML. Built from
            // trusted, plugin-generated values only (translated string +
            // numeric usage figures) - safe to append after sanitization.
            $cleanContent .= "<div class='text-end mt-2 pt-2 border-top text-muted' style='font-size: 0.75rem; font-style: italic;'>
                <i class='fas fa-coins me-1'></i> {$txtCost}
            </div>";
            // ------------------------------

            self::saveAnalysisToDb($ticketId, $cleanContent);
            return $cleanContent;

        } catch (\Throwable $e) {
            Toolbox::logInFile('aisuite', "AI Smart Check Exception Ticket #{$ticketId}: " . $e->getMessage() . "\n");
            return "<div class='alert alert-danger'>" . __('Erreur système lors de l\'analyse IA. Consultez les journaux du serveur pour plus de détails.', 'aisuite') . "</div>";
        }
    }

    // --- Private Helper Methods ---

    /**
     * Search for relevant KB articles based on title keywords.
     * @param string $title
     * @return array List of ['id' => int, 'title' => string]
     */
    private static function searchKnowledgeBase(string $title): array {
        global $DB;

        // 1. Manual string cleaning (replace punctuation with spaces)
        $cleanTitle = str_replace(
            ['\'', '"', '.', ',', ';', ':', '?', '!', '-', '_', '(', ')', '[', ']', '{', '}', '/', '\\', '+', '*', '&'],
            ' ',
            $title
        );

        // 2. Explode into words
        $words = explode(' ', $cleanTitle);

        // 3. Filter: Keep words >= 2 chars (e.g., "IP", "PC", "VM")
        $keywords = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 2) {
                $keywords[] = $w;
            }
        }

        if (empty($keywords)) {
            return [];
        }

        // 4. Build the WHERE clause using GLPI's query builder (parameterized,
        //    values are escaped automatically). LIKE wildcards (% and _)
        //    coming from the ticket title are escaped so they can't widen
        //    the search pattern beyond a literal keyword match.
        $whereClause = [];
        foreach ($keywords as $word) {
            $safeWord = str_replace(['%', '_'], ['\\%', '\\_'], $word);
            $whereClause[] = ['name' => ['LIKE', '%' . $safeWord . '%']];
        }

        // Query: NO FILTER on deleted/active status to ensure SQL compatibility first
        $iterator = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_knowbaseitems',
            'WHERE'  => [
                'OR' => $whereClause
            ],
            'LIMIT'  => 5
        ]);

        $results = [];
        foreach ($iterator as $row) {
            $results[] = [
                'id'    => $row['id'],
                'title' => $row['name']
            ];
        }

        return $results;
    }

    // --- DB Methods ---

    /**
     * Retrieve saved analysis from the database.
     * @param int $ticketId
     * @return array|null
     */
    public static function getSavedAnalysis(int $ticketId) {
        global $DB;
        $iterator = $DB->request([
            'SELECT' => ['content', 'date_mod'],
            'FROM'   => 'glpi_plugin_aismartcheck_analyses',
            'WHERE'  => ['tickets_id' => $ticketId]
        ]);
        return count($iterator) ? $iterator->current() : null;
    }

    /**
     * Save or Update analysis content.
     * @param int $ticketId
     * @param string $content
     * @return bool
     */
    public static function saveAnalysisToDb(int $ticketId, string $content): bool {
        global $DB;
        // Technical: this is also reachable directly from front/suggestion.form.php
        // ('update_content' action) with raw, client-submitted HTML (the
        // checkbox-state persistence feature) - sanitize unconditionally
        // here rather than trusting every caller to have done it already.
        // Idempotent: re-sanitizing this class's own already-clean output
        // (the getAnalysisHtml() call site) is a harmless no-op.
        $content = HtmlSanitizer::sanitizeRich($content);
        $exists = self::getSavedAnalysis($ticketId);
        if ($exists !== null) {
            return $DB->update('glpi_plugin_aismartcheck_analyses', ['content' => $content], ['tickets_id' => $ticketId]);
        } else {
            return $DB->insert('glpi_plugin_aismartcheck_analyses', ['tickets_id' => $ticketId, 'content' => $content]);
        }
    }

    /**
     * Convert the analysis HTML into a private Note on the ticket.
     * @param int $ticketId
     * @param string $htmlContent
     * @return array
     * @throws \Exception
     */
    public static function saveAsNote(int $ticketId, string $htmlContent): array {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new \Exception(__('Ticket introuvable.', 'aisuite'));
        }

        $fup = new ITILFollowup();
        // Translate note header
        $noteHeader = "<strong>" . __('[AI Smart Check] Analyse :', 'aisuite') . "</strong><br>";

        // Technical: reachable directly from front/suggestion.form.php
        // ('save_note' action) with raw, client-submitted HTML - sanitize
        // unconditionally, same rationale as saveAnalysisToDb() above.
        $safeHtml = HtmlSanitizer::sanitizeRich($htmlContent);

        $input = [
            'itemtype'    => 'Ticket',
            'items_id'    => $ticketId,
            'content'     => $noteHeader . $safeHtml,
            'is_private' => 1,
            'users_id'    => Session::getLoginUserID()
        ];
        return $fup->add($input)
            ? ['success' => true, 'message' => __('Note ajoutée avec succès.', 'aisuite')]
            : ['success' => false, 'message' => __('Erreur lors de la création de la note.', 'aisuite')];
    }
}
