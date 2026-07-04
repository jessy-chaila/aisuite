<?php

/**
 * Plugin AI Suite - File: front/config.form.php
 *
 * Écran de configuration unique regroupant les 3 modules :
 *  - Fournisseurs IA (un seul fournisseur actif, partagé par tous les modules)
 *  - AI Smart Check
 *  - AI Smart Sorter
 *  - AI Chatbot
 *
 * Version complète : aucune restriction de licence.
 */

use GlpiPlugin\Aisuite\Shared\PluginConfig;
use GlpiPlugin\Aisuite\Shared\ProviderFactory;

include GLPI_ROOT . '/inc/includes.php';

$plugin_config_name = 'plugin:aisuite';

// Les 3 familles de fournisseurs IA prises en charge par la suite.
// 'openai' couvre tout ce qui parle le format OpenAI Chat Completions :
// OpenAI, Azure OpenAI, xAI (Grok) et Mistral (seuls l'URL, la clé et le
// modèle/déploiement changent d'un backend à l'autre).
$families = [
    'openai'    => __('OpenAI (compatible : OpenAI, Azure, xAI, Mistral)', 'aisuite'),
    'anthropic' => __('Anthropic (Claude)', 'aisuite'),
    'google'    => __('Google (Gemini)', 'aisuite'),
];

// Default cost-estimation prices (USD per 1 million tokens), used whenever
// the admin has not entered a custom price below. These only drive the
// informational cost estimate shown in AI Smart Check / AI Smart Sorter,
// they never affect billing with the actual provider.
$price_defaults = [
    'openai'    => ['input' => 5.00,  'output' => 15.00],
    'anthropic' => ['input' => 3.00,  'output' => 15.00],
    'google'    => ['input' => 1.25,  'output' => 5.00],
];

// =========================================================================
// 1. AJAX Handler - Test de connexion (fournisseur actif)
// =========================================================================
if (isset($_POST['action']) && $_POST['action'] === 'test_provider') {
    Session::checkRight('config', UPDATE);
    Session::validateCSRF($_POST);

    $family = ProviderFactory::normalizeType($_POST['family'] ?? '');

    // If the key field was left blank, the admin is testing the
    // already-saved key (the field is never pre-filled with the real value,
    // see the Providers tab rendering below) - fall back to the stored,
    // decrypted key for that family instead of failing with "missing key".
    $postedKey = $_POST['api_key'] ?? '';
    if ($postedKey === '') {
        $storedConf = PluginConfig::get();
        $postedKey  = $storedConf['provider_' . $family . '_key'] ?? '';
    }

    $test_config = [
        'ai_api_url' => $_POST['api_url'] ?? '',
        'ai_api_key' => $postedKey,
        'ai_model'   => $_POST['ai_model'] ?? '',
    ];

    if (empty($test_config['ai_api_key'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => __('Veuillez renseigner la clé API.', 'aisuite'), 'csrf_token' => Session::getNewCSRFToken()]);
        exit;
    }

    $provider = ProviderFactory::make($family);

    $conversation = [['role' => 'user', 'content' => 'Ping']];
    $response = $provider->call('', $conversation, $test_config);

    header('Content-Type: application/json');
    // A fresh token is returned because the kernel-level CSRF check consumes
    // (invalidates) the token used for this AJAX call: without renewing it,
    // the next "Enregistrer" submit on the same page would be rejected.
    $fresh_token = Session::getNewCSRFToken();
    if (!empty($response['error'])) {
        echo json_encode(['success' => false, 'message' => __('Erreur API : ', 'aisuite') . $response['error'], 'csrf_token' => $fresh_token]);
    } else {
        echo json_encode(['success' => true, 'message' => __('Connexion réussie !', 'aisuite'), 'csrf_token' => $fresh_token]);
    }
    exit;
}

Session::checkRight('config', UPDATE);

// =========================================================================
// 2. Save handlers (one per tab, identified by its own submit button)
// =========================================================================
$saved = false;

// --- Providers tab ---
if (isset($_POST['save_providers'])) {
    $active = $_POST['provider_active'] ?? 'openai';
    if (!array_key_exists($active, $families)) {
        $active = 'openai';
    }

    // SSRF hardening: only ever persist an HTTPS endpoint URL. An invalid
    // scheme is dropped (saved as empty) rather than silently kept as
    // typed, so a bad value is visibly blank instead of looking accepted.
    $sanitizeProviderUrl = static function (string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return (parse_url($url, PHP_URL_SCHEME) === 'https') ? $url : '';
    };

    $providersToSave = [
        'provider_active'          => $active,
        'provider_openai_url'      => $sanitizeProviderUrl($_POST['provider_openai_url'] ?? ''),
        'provider_openai_model'    => $_POST['provider_openai_model'] ?? '',
        'provider_anthropic_url'   => $sanitizeProviderUrl($_POST['provider_anthropic_url'] ?? ''),
        'provider_anthropic_model' => $_POST['provider_anthropic_model'] ?? '',
        'provider_google_url'      => $sanitizeProviderUrl($_POST['provider_google_url'] ?? ''),
        'provider_google_model'    => $_POST['provider_google_model'] ?? '',

        // Cost-estimation prices (USD per 1M tokens), one input/output pair
        // per provider family. Purely informational, editable without touching code.
        'provider_openai_price_input'      => $_POST['provider_openai_price_input']      ?? '',
        'provider_openai_price_output'     => $_POST['provider_openai_price_output']     ?? '',
        'provider_anthropic_price_input'   => $_POST['provider_anthropic_price_input']   ?? '',
        'provider_anthropic_price_output'  => $_POST['provider_anthropic_price_output']  ?? '',
        'provider_google_price_input'      => $_POST['provider_google_price_input']      ?? '',
        'provider_google_price_output'     => $_POST['provider_google_price_output']     ?? '',
    ];

    // API keys: the field is always rendered blank (see the Providers tab
    // below - the stored value is encrypted and must never be echoed back
    // into the page or re-submitted as if it were a fresh plaintext key).
    // Only overwrite a family's stored key if the admin actually typed a
    // new one; an empty submission means "keep the existing key".
    foreach (['openai', 'anthropic', 'google'] as $fam) {
        $postedKey = $_POST['provider_' . $fam . '_key'] ?? '';
        if ($postedKey !== '') {
            $providersToSave['provider_' . $fam . '_key'] = $postedKey;
        }
    }

    Config::setConfigurationValues($plugin_config_name, $providersToSave);
    $saved = 'providers';
}

// --- AI Smart Check tab ---
if (isset($_POST['save_smartcheck'])) {
    Config::setConfigurationValues($plugin_config_name, [
        'smartcheck_enabled'          => !empty($_POST['smartcheck_enabled']) ? 1 : 0,
        'smartcheck_system_prompt'    => $_POST['smartcheck_system_prompt'] ?? '',
        'smartcheck_enable_kb_search' => (int)($_POST['smartcheck_enable_kb_search'] ?? 0),
    ]);
    $saved = 'smartcheck';
}

// --- AI Smart Sorter tab ---
if (isset($_POST['save_sorter'])) {
    Config::setConfigurationValues($plugin_config_name, [
        'sorter_enabled'                 => !empty($_POST['sorter_enabled']) ? 1 : 0,
        'sorter_enable_auto_mode'        => (int)($_POST['sorter_enable_auto_mode'] ?? 0),
        'sorter_confidence_threshold'    => (int)($_POST['sorter_confidence_threshold'] ?? 80),
        'sorter_enable_hardware_linking' => (int)($_POST['sorter_enable_hardware_linking'] ?? 0),
        'sorter_system_prompt_context'   => $_POST['sorter_system_prompt_context'] ?? '',
    ]);
    $saved = 'sorter';
}

// --- AI Chatbot tab ---
if (isset($_POST['save_chat'])) {
    global $CFG_GLPI;
    $existing_icon_url = $_POST['chat_bot_icon_image_url_current'] ?? '';
    $new_icon_url      = $existing_icon_url;

    if (isset($_FILES['chat_bot_icon_image_file']) && $_FILES['chat_bot_icon_image_file']['error'] === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['chat_bot_icon_image_file']['tmp_name'];
        $name = $_FILES['chat_bot_icon_image_file']['name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Technical: 'svg' intentionally excluded - an SVG can embed
        // <script>/event-handler attributes and browsers will happily
        // execute them when the file is opened/rendered directly, making
        // this upload a stored-XSS vector for whichever admin views the
        // chatbot icon later. Raster formats only.
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            // Technical: validate the upload is an actual decodable raster
            // image, not just a correctly-named file (getimagesize() parses
            // the real image header rather than trusting the extension).
            $imageInfo = @getimagesize($tmp);

            if ($imageInfo !== false) {
                $dest_dir = GLPI_ROOT . '/plugins/aisuite/public/img';
                if (!is_dir($dest_dir)) {
                    @mkdir($dest_dir, 0755, true);
                }
                $dest_name = uniqid('bot_icon_', true) . '.' . $ext;
                $dest_path = $dest_dir . '/' . $dest_name;
                if (@move_uploaded_file($tmp, $dest_path)) {
                    $new_icon_url = $CFG_GLPI['root_doc'] . '/plugins/aisuite/public/img/' . $dest_name;
                }
            }
        }
    }

    Config::setConfigurationValues($plugin_config_name, [
        'chat_enabled'             => !empty($_POST['chat_enabled']) ? 1 : 0,
        'chat_system_prompt'       => $_POST['chat_system_prompt'] ?? '',
        'chat_support_phone'       => $_POST['chat_support_phone'] ?? '',
        'chat_bot_icon_type'       => $_POST['chat_bot_icon_type'] ?? 'emoji',
        'chat_bot_icon_text'       => $_POST['chat_bot_icon_text'] ?? '',
        'chat_bot_icon_image_url'  => $new_icon_url,
        'chat_bot_color'           => $_POST['chat_bot_color'] ?? '',
        'chat_bot_color_use_theme' => !empty($_POST['chat_bot_color_use_theme']) ? 1 : 0,
    ]);
    $saved = 'chat';
}

// --- AI Level 1 Assistant tab ---
if (isset($_POST['save_level1'])) {
    Config::setConfigurationValues($plugin_config_name, [
        'level1_enabled'          => !empty($_POST['level1_enabled']) ? 1 : 0,
        'level1_system_prompt'    => $_POST['level1_system_prompt'] ?? '',
        'level1_escalation_group' => (int)($_POST['level1_escalation_group'] ?? 0),
    ]);
    $saved = 'level1';
}

// =========================================================================
// 3. Load current configuration
// =========================================================================
$conf = PluginConfig::get();
$active_provider = $conf['provider_active'] ?? 'openai';
if (!array_key_exists($active_provider, $families)) {
    $active_provider = 'openai';
}

$active_tab = $_GET['tab'] ?? ($saved ?: 'providers');

// =========================================================================
// 4. Render
// =========================================================================
Html::header(__('Configuration AI Suite', 'aisuite'), $_SERVER['PHP_SELF'], 'config', 'plugins');

echo "<div class='card mb-4'>";
echo " <div class='card-header'>";
echo "  <h3 class='card-title mb-0'>" . __('Configuration AI Suite', 'aisuite') . "</h3>";
echo " </div>";
echo " <div class='card-body'>";

// --- Tabs nav ---
$tabs = [
    'providers'  => __('Fournisseurs IA', 'aisuite'),
    'smartcheck' => __('AI Smart Check', 'aisuite'),
    'sorter'     => __('AI Smart Sorter', 'aisuite'),
    'level1'     => __('AI Level 1 Assistant', 'aisuite'),
    'chat'       => __('AI Chatbot', 'aisuite'),
];
echo "<ul class='nav nav-tabs mb-4' role='tablist'>";
foreach ($tabs as $key => $label) {
    $active = ($key === $active_tab) ? ' active' : '';
    echo "<li class='nav-item' role='presentation'>";
    echo "<button class='nav-link{$active}' id='tab-btn-{$key}' data-bs-toggle='tab' data-bs-target='#tab-{$key}' type='button' role='tab'>{$label}</button>";
    echo "</li>";
}
echo "</ul>";

echo "<div class='tab-content'>";

// -------------------------------------------------------------------
// TAB: Providers (fournisseur unique, partagé par tous les modules)
// -------------------------------------------------------------------
$active = ($active_tab === 'providers') ? ' show active' : '';
echo "<div class='tab-pane fade{$active}' id='tab-providers' role='tabpanel'>";
echo "<form method='post' action='config.form.php?tab=providers' id='form-providers'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<p class='text-muted'>" . __('Le fournisseur choisi ci-dessous est utilisé par tous les modules AI Suite (AI Smart Check, AI Smart Sorter, AI Chatbot). Chaque fournisseur conserve ses propres identifiants même si vous changez de sélection.', 'aisuite') . "</p>";

echo " <div class='mb-4 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Fournisseur actif', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo "   <select name='provider_active' id='provider_active' class='form-select' style='max-width:60ch;'>";
foreach ($families as $code => $label) {
    $selected = ($code === $active_provider) ? ' selected' : '';
    echo "<option value='{$code}'$selected>" . Html::entities_deep($label) . "</option>";
}
echo "   </select>";
echo "  </div>";
echo " </div>";

foreach ($families as $family => $label) {
    $display = ($family === $active_provider) ? '' : ' style="display:none;"';
    echo "<div class='provider-family-block' data-family='{$family}'{$display}>";
    echo "<h4 class='subheader mb-3 mt-3'>{$label}</h4>";

    echo " <div class='mb-3 row'>";
    echo "  <label class='col-sm-3 col-form-label'>" . __('URL Endpoint', 'aisuite') . "</label>";
    echo "  <div class='col-sm-9'>";
    echo Html::input("provider_{$family}_url", ['value' => $conf["provider_{$family}_url"] ?? '', 'class' => 'form-control', 'size' => 60]);
    echo "  </div>";
    echo " </div>";

    echo " <div class='mb-3 row'>";
    echo "  <label class='col-sm-3 col-form-label'>" . __('Clé API', 'aisuite') . "</label>";
    echo "  <div class='col-sm-9'>";
    // Technical: the stored key is encrypted at rest (see PluginConfig) and
    // must never be echoed back into the page - the field is always left
    // blank; submitting the form without touching it keeps the saved key.
    $hasStoredKey = !empty($conf["provider_{$family}_key"]);
    $keyPlaceholder = $hasStoredKey
        ? __('Clé déjà enregistrée — laisser vide pour la conserver', 'aisuite')
        : '';
    echo "   <input type='password' name='provider_{$family}_key' value='' autocomplete='new-password' placeholder='" . Html::entities_deep($keyPlaceholder) . "' class='form-control' size='60'>";
    echo "  </div>";
    echo " </div>";

    echo " <div class='mb-3 row'>";
    echo "  <label class='col-sm-3 col-form-label'>" . __('Modèle / Déploiement', 'aisuite') . "</label>";
    echo "  <div class='col-sm-9'>";
    echo Html::input("provider_{$family}_model", ['value' => $conf["provider_{$family}_model"] ?? '', 'class' => 'form-control', 'size' => 40]);
    echo "  </div>";
    echo " </div>";

    // Cost-estimation pricing (USD per 1M tokens). Pre-filled with a sensible
    // default so the field is never empty; the admin can override it here
    // without ever touching the plugin's code.
    $rawPriceInput  = $conf["provider_{$family}_price_input"]  ?? '';
    $rawPriceOutput = $conf["provider_{$family}_price_output"] ?? '';
    $priceInputVal  = ($rawPriceInput  !== '' && (float)$rawPriceInput  > 0) ? $rawPriceInput  : $price_defaults[$family]['input'];
    $priceOutputVal = ($rawPriceOutput !== '' && (float)$rawPriceOutput > 0) ? $rawPriceOutput : $price_defaults[$family]['output'];

    echo " <div class='mb-3 row'>";
    echo "  <label class='col-sm-3 col-form-label'>" . __('Prix entrée ($ / 1M tokens)', 'aisuite') . "</label>";
    echo "  <div class='col-sm-9'>";
    echo "   <input type='number' step='0.01' min='0' name='provider_{$family}_price_input' value='" . Html::entities_deep($priceInputVal) . "' class='form-control' style='max-width:20ch;'>";
    echo "  </div>";
    echo " </div>";

    echo " <div class='mb-3 row'>";
    echo "  <label class='col-sm-3 col-form-label'>" . __('Prix sortie ($ / 1M tokens)', 'aisuite') . "</label>";
    echo "  <div class='col-sm-9'>";
    echo "   <input type='number' step='0.01' min='0' name='provider_{$family}_price_output' value='" . Html::entities_deep($priceOutputVal) . "' class='form-control' style='max-width:20ch;'>";
    echo "   <div class='form-text'>" . __('Sert uniquement à estimer le coût affiché dans AI Smart Check et AI Smart Sorter (n\'affecte pas la facturation réelle du fournisseur).', 'aisuite') . "</div>";
    echo "  </div>";
    echo " </div>";

    echo " <div class='mb-3 row'>";
    echo "  <div class='col-sm-9 offset-sm-3'>";
    echo "   <button type='button' class='btn btn-info btn-sm icon-btn btn-test-provider' data-family='{$family}'><i class='fas fa-plug'></i> " . __('Tester la connexion', 'aisuite') . "</button>";
    echo "   <div class='mt-2 test-result' data-family-result='{$family}'></div>";
    echo "  </div>";
    echo " </div>";
    echo "</div>"; // provider-family-block
}

echo "<div class='text-center mt-4'>";
echo Html::submit(__('Enregistrer', 'aisuite'), ['name' => 'save_providers', 'class' => 'btn btn-primary']);
echo "</div>";
Html::closeForm();
echo "</div>"; // tab-providers

// -------------------------------------------------------------------
// TAB: AI Smart Check
// -------------------------------------------------------------------
$active = ($active_tab === 'smartcheck') ? ' show active' : '';
echo "<div class='tab-pane fade{$active}' id='tab-smartcheck' role='tabpanel'>";
echo "<form method='post' action='config.form.php?tab=smartcheck'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo " <div class='mb-4 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Module activé', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
$smartcheckEnabledChecked = (!isset($conf['smartcheck_enabled']) || !empty($conf['smartcheck_enabled'])) ? 'checked' : '';
echo '<input type="hidden" name="smartcheck_enabled" value="0">';
echo '<input type="checkbox" class="form-check-input" name="smartcheck_enabled" value="1" ' . $smartcheckEnabledChecked . '>';
echo "   <div class='form-text'>" . __('Désactivez ce module si vous ne voulez pas afficher l\'onglet d\'analyse IA sur les tickets.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<h4 class='subheader mb-3'>" . __('Paramètres IA', 'aisuite') . "</h4>";
echo "<p class='text-muted'>" . sprintf(__('Utilise le fournisseur actif configuré dans l\'onglet « Fournisseurs IA » (actuellement : %s).', 'aisuite'), $families[$active_provider]) . "</p>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Prompt Système', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
Html::textarea([
    'name'  => 'smartcheck_system_prompt',
    'value' => $conf['smartcheck_system_prompt'] ?? '',
    'class' => 'form-control font-monospace',
    'rows'  => 5,
]);
echo "   <div class='form-text'>" . __('Instructions globales pour l\'analyse de ticket.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row'>";
$tooltip_text = __("L'IA recherche uniquement les mots clés extraits du titre du ticket et retrouvés dans le titre de l'article.", 'aisuite');
echo "  <label class='col-sm-3 col-form-label'>";
echo __('Chercher dans la base de connaissances', 'aisuite');
echo " <i class='fas fa-info-circle text-info ms-1' style='cursor: help;' title=\"" . Html::entities_deep($tooltip_text) . "\"></i>";
echo "</label>";
echo "  <div class='col-sm-9'>";
$isChecked = !empty($conf['smartcheck_enable_kb_search']) ? 'checked' : '';
echo '<input type="hidden" name="smartcheck_enable_kb_search" value="0">';
echo '<input type="checkbox" class="form-check-input" name="smartcheck_enable_kb_search" value="1" ' . $isChecked . '>';
echo "   <div class='form-text'>" . __('Si activé, l\'IA tentera de trouver des articles pertinents dans la base de connaissances GLPI.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<div class='text-center mt-4'>";
echo Html::submit(__('Enregistrer', 'aisuite'), ['name' => 'save_smartcheck', 'class' => 'btn btn-primary']);
echo "</div>";
Html::closeForm();
echo "</div>"; // tab-smartcheck

// -------------------------------------------------------------------
// TAB: AI Smart Sorter
// -------------------------------------------------------------------
$active = ($active_tab === 'sorter') ? ' show active' : '';
echo "<div class='tab-pane fade{$active}' id='tab-sorter' role='tabpanel'>";
echo "<form method='post' action='config.form.php?tab=sorter'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo " <div class='mb-4 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Module activé', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
$sorterEnabledChecked = (!isset($conf['sorter_enabled']) || !empty($conf['sorter_enabled'])) ? 'checked' : '';
echo '<input type="hidden" name="sorter_enabled" value="0">';
echo '<input type="checkbox" class="form-check-input" name="sorter_enabled" value="1" ' . $sorterEnabledChecked . '>';
echo "   <div class='form-text'>" . __('Désactivez ce module si vous ne voulez pas classer/catégoriser automatiquement les nouveaux tickets.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<h4 class='subheader mb-3'>" . __('Connexion IA', 'aisuite') . "</h4>";
echo "<p class='text-muted'>" . sprintf(__('Utilise le fournisseur actif configuré dans l\'onglet « Fournisseurs IA » (actuellement : %s).', 'aisuite'), $families[$active_provider]) . "</p>";

echo "<h4 class='subheader mb-3 mt-4'>" . __('Paramètres d\'Automatisation', 'aisuite') . "</h4>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Mode Expert (Auto-catégorisation)', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo '<input type="hidden" name="sorter_enable_auto_mode" value="0">';
echo '<input type="checkbox" class="form-check-input" name="sorter_enable_auto_mode" value="1" ' . (!empty($conf['sorter_enable_auto_mode']) ? 'checked' : '') . '>';
echo "   <div class='form-text'>" . __('Si activé, le ticket est qualifié automatiquement sans validation humaine si le seuil de confiance est atteint.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Seuil de confiance minimum (%)', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo "    <input type='number' name='sorter_confidence_threshold' min='50' max='100' value='" . ($conf['sorter_confidence_threshold'] ?? 80) . "' class='form-control' style='width: 100px;'>";
echo "    <div class='form-text'>" . __('Pourcentage de certitude minimal requis pour déclencher l\'automatisation.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Liaison Matérielle', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo '<input type="hidden" name="sorter_enable_hardware_linking" value="0">';
echo '<input type="checkbox" class="form-check-input" name="sorter_enable_hardware_linking" value="1" ' . (!empty($conf['sorter_enable_hardware_linking']) ? 'checked' : '') . '>';
echo "    <div class='form-text'>" . __('Tenter d\'identifier et de lier le matériel de l\'utilisateur cité dans le ticket.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Prompt Système (Context)', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
Html::textarea(['name' => 'sorter_system_prompt_context', 'value' => $conf['sorter_system_prompt_context'] ?? '', 'class' => 'form-control font-monospace', 'rows' => 5]);
echo "    <div class='form-text'>" . __('Instructions spécifiques pour ajuster le comportement de l\'IA.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<div class='text-center mt-4'>";
echo Html::submit(__('Enregistrer', 'aisuite'), ['name' => 'save_sorter', 'class' => 'btn btn-primary']);
echo "</div>";
Html::closeForm();

// --- History modal trigger + modal (analysis log) ---
echo "<div class='text-center mt-3'>";
echo "<button type='button' class='btn btn-secondary btn-sm' data-bs-toggle='modal' data-bs-target='#ai-logs-modal'>";
echo "<i class='fas fa-history'></i> " . __('Afficher l\'historique', 'aisuite');
echo "</button>";
echo "</div>";

echo "<div class='modal fade' id='ai-logs-modal' tabindex='-1' aria-hidden='true'>";
echo "  <div class='modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable'>";
echo "    <div class='modal-content'>";
echo "      <div class='modal-header'>";
echo "        <h5 class='modal-title'><i class='fas fa-history'></i> " . __('Historique des Analyses AI', 'aisuite') . "</h5>";
echo "        <button type='button' class='btn-close' data-bs-dismiss='modal'></button>";
echo "      </div>";
echo "      <div class='modal-body'>";

global $DB, $CFG_GLPI;
$iterator = $DB->request(['FROM' => 'glpi_plugin_aismartsorter_logs', 'ORDER' => 'id DESC', 'LIMIT' => 50]);

if (count($iterator) > 0) {
    echo "<div class='table-responsive'><table class='table table-hover table-striped'><thead><tr>";
    echo "<th>" . __('ID', 'aisuite') . "</th><th>" . __('Ticket', 'aisuite') . "</th><th>" . __('Matériel', 'aisuite') . "</th>";
    echo "<th>" . __('Catégorie', 'aisuite') . "</th><th>" . __('Coût', 'aisuite') . "</th><th>" . __('Confiance', 'aisuite') . "</th><th>" . __('Statut', 'aisuite') . "</th>";
    echo "</tr></thead><tbody>";

    foreach ($iterator as $row) {
        $aiData = json_decode($row['ai_response'], true);
        $ticketLink = "<a href='" . $CFG_GLPI["root_doc"] . "/front/ticket.form.php?id=" . $row['tickets_id'] . "' target='_blank'>#" . $row['tickets_id'] . "</a>";

        $hardware = "-";
        if (!empty($aiData['detected_hardware_display'])) {
            $hardware = "<i class='fas fa-microchip text-primary'></i> " . $aiData['detected_hardware_display'];
        } elseif (!empty($aiData['detected_hardware_name'])) {
            $hardware = $aiData['detected_hardware_name'];
        }

        $category = $aiData['suggested_category_name'] ?? $aiData['suggested_category'] ?? '<span class="text-muted">' . __('N/A', 'aisuite') . '</span>';

        $cost = isset($row['execution_cost']) ? (float)$row['execution_cost'] : 0.0;
        $tokens = isset($row['token_usage']) ? (int)$row['token_usage'] : 0;
        $costFormatted = number_format($cost, 5);
        $costDisplay = "<span title='$tokens " . __('tokens', 'aisuite') . "'>$$costFormatted</span>";
        if ($tokens > 0) {
            $costDisplay .= " <small class='text-muted'>($tokens tks)</small>";
        }

        $score = $row['confidence_score'];
        $badgeClass = "bg-danger text-white";
        if ($score >= 80) $badgeClass = "bg-success text-white";
        elseif ($score >= 50) $badgeClass = "bg-warning text-dark";
        $confidenceBadge = "<span class='badge $badgeClass'>$score%</span>";

        $status = $row['action_taken'];
        $statusIcon = "<i class='fas fa-hourglass-half text-secondary'></i>";
        if ($status === 'applied_by_user') {
            $statusIcon = "<i class='fas fa-check-circle text-success'></i> " . __('Appliqué', 'aisuite');
        } elseif ($status === 'dismissed_by_user') {
            $statusIcon = "<i class='fas fa-times-circle text-danger'></i> " . __('Ignoré', 'aisuite');
        } elseif ($status === 'suggestion_only') {
            $statusIcon = "<span class='badge bg-secondary'>" . __('Suggestion', 'aisuite') . "</span>";
        } elseif ($status === 'auto_applied') {
            $statusIcon = "<i class='fas fa-bolt text-warning'></i> <strong>" . __('Auto', 'aisuite') . "</strong>";
        }

        echo "<tr><td>{$row['id']}</td><td><strong>$ticketLink</strong></td><td>$hardware</td><td>$category</td><td>$costDisplay</td><td>$confidenceBadge</td><td>$statusIcon</td></tr>";
    }
    echo "</tbody></table></div>";
} else {
    echo "<div class='p-5 text-center text-muted'><h4><i class='fas fa-ghost'></i></h4>" . __('Aucun historique disponible pour le moment.', 'aisuite') . "</div>";
}

echo "      </div>";
echo "      <div class='modal-footer'><button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Fermer', 'aisuite') . "</button></div>";
echo "    </div></div></div>";

echo "</div>"; // tab-sorter

// -------------------------------------------------------------------
// TAB: AI Level 1 Assistant
// -------------------------------------------------------------------
$active = ($active_tab === 'level1') ? ' show active' : '';
echo "<div class='tab-pane fade{$active}' id='tab-level1' role='tabpanel'>";
echo "<form method='post' action='config.form.php?tab=level1'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo " <div class='mb-4 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Module activé', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
$level1EnabledChecked = (!isset($conf['level1_enabled']) || !empty($conf['level1_enabled'])) ? 'checked' : '';
echo '<input type="hidden" name="level1_enabled" value="0">';
echo '<input type="checkbox" class="form-check-input" name="level1_enabled" value="1" ' . $level1EnabledChecked . '>';
echo "   <div class='form-text'>" . __("Désactivez ce module si vous ne voulez pas que l'IA réponde automatiquement dans les nouveaux tickets.", 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<h4 class='subheader mb-3'>" . __('Connexion IA', 'aisuite') . "</h4>";
echo "<p class='text-muted'>" . sprintf(__('Utilise le fournisseur actif configuré dans l\'onglet « Fournisseurs IA » (actuellement : %s).', 'aisuite'), $families[$active_provider]) . "</p>";

echo "<p class='text-muted'>" . __("À la création d'un ticket, ce module tente de proposer une solution de niveau 1 (sans droits administrateur ni compétence technique) directement dans le ticket. S'il n'a pas assez d'éléments, il pose des questions de clarification puis réattribue le ticket au groupe défini ci-dessous.", 'aisuite') . "</p>";

echo "<h4 class='subheader mb-3 mt-4'>" . __("Réattribution", 'aisuite') . "</h4>";

$aiGroupId   = (int)($conf['level1_ai_group_id'] ?? 0);
$aiGroupName = '';
if ($aiGroupId > 0) {
    $aiGroupObj = new Group();
    if ($aiGroupObj->getFromDB($aiGroupId)) {
        $aiGroupName = $aiGroupObj->fields['name'];
    }
}

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Groupe "Assistant IA"', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
if ($aiGroupName !== '') {
    echo "<span class='badge bg-azure-lt'>" . Html::entities_deep($aiGroupName) . "</span>";
    echo "   <div class='form-text'>" . __("Créé et géré automatiquement par le plugin : chaque ticket pris en charge par l'IA y est assigné le temps qu'elle s'en occupe, et il en est retiré dès que l'utilisateur désactive l'IA, qu'un technicien répond directement, ou que le ticket est réattribué au groupe d'escalade ci-dessous.", 'aisuite') . "</div>";
} else {
    echo "<span class='text-muted'>" . __("Ce groupe sera créé automatiquement à la prochaine installation/mise à jour du plugin.", 'aisuite') . "</span>";
}
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __("Groupe technicien d'escalade", 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
Dropdown::show('Group', [
    'name'       => 'level1_escalation_group',
    'value'      => (int)($conf['level1_escalation_group'] ?? 0),
    'entity'     => $_SESSION['glpiactive_entity'] ?? 0,
    'emptylabel' => __('Aucun (pas de réattribution automatique)', 'aisuite'),
    'used'       => $aiGroupId > 0 ? [$aiGroupId] : [],
]);
echo "   <div class='form-text'>" . __("Groupe auquel le ticket est réattribué uniquement lorsque l'IA ne peut plus l'aider elle-même (nombre de tours de questions atteint) et qu'elle n'a pas été désactivée manuellement. En cas de désactivation manuelle ou de prise en charge par un technicien, le ticket est simplement désassigné (Tickets entrants).", 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<h4 class='subheader mb-3 mt-4'>" . __('Paramètres IA', 'aisuite') . "</h4>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Prompt Système (Context)', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
Html::textarea(['name' => 'level1_system_prompt', 'value' => $conf['level1_system_prompt'] ?? '', 'class' => 'form-control font-monospace', 'rows' => 5]);
echo "    <div class='form-text'>" . __("Instructions supplémentaires pour ajuster le comportement de l'assistant (ton, procédures internes, cas à toujours escalader, etc.).", 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<div class='text-center mt-4'>";
echo Html::submit(__('Enregistrer', 'aisuite'), ['name' => 'save_level1', 'class' => 'btn btn-primary']);
echo "</div>";
Html::closeForm();

// --- History modal trigger + modal (Level 1 conversation log) ---
echo "<div class='text-center mt-3'>";
echo "<button type='button' class='btn btn-secondary btn-sm' data-bs-toggle='modal' data-bs-target='#ai-level1-logs-modal'>";
echo "<i class='fas fa-history'></i> " . __('Afficher l\'historique', 'aisuite');
echo "</button>";
echo "</div>";

echo "<div class='modal fade' id='ai-level1-logs-modal' tabindex='-1' aria-hidden='true'>";
echo "  <div class='modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable'>";
echo "    <div class='modal-content'>";
echo "      <div class='modal-header'>";
echo "        <h5 class='modal-title'><i class='fas fa-history'></i> " . __('Historique de l\'Assistant IA Niveau 1', 'aisuite') . "</h5>";
echo "        <button type='button' class='btn-close' data-bs-dismiss='modal'></button>";
echo "      </div>";
echo "      <div class='modal-body'>";

global $DB, $CFG_GLPI;
$level1Iterator = $DB->request(['FROM' => 'glpi_plugin_aisuite_level1_logs', 'ORDER' => 'date_mod DESC', 'LIMIT' => 50]);

if (count($level1Iterator) > 0) {
    echo "<div class='table-responsive'><table class='table table-hover table-striped'><thead><tr>";
    echo "<th>" . __('ID', 'aisuite') . "</th><th>" . __('Ticket', 'aisuite') . "</th><th>" . __('Statut', 'aisuite') . "</th>";
    echo "<th>" . __('Tour', 'aisuite') . "</th><th>" . __('Coût', 'aisuite') . "</th><th>" . __('Dernière activité', 'aisuite') . "</th>";
    echo "</tr></thead><tbody>";

    $level1StatusLabels = [
        'pending'              => ['class' => 'bg-info text-white',      'label' => __('En cours', 'aisuite')],
        'resolved'             => ['class' => 'bg-success text-white',   'label' => __('Résolu par l\'IA', 'aisuite')],
        'escalated'            => ['class' => 'bg-warning text-dark',    'label' => __('Escaladé au groupe', 'aisuite')],
        'user_declined'        => ['class' => 'bg-secondary text-white', 'label' => __('IA désactivée (utilisateur)', 'aisuite')],
        'technician_takeover'  => ['class' => 'bg-secondary text-white', 'label' => __('Pris en charge par un technicien', 'aisuite')],
        'ticket_closed'        => ['class' => 'bg-dark text-white',      'label' => __('Ticket clôturé', 'aisuite')],
    ];

    foreach ($level1Iterator as $row) {
        $ticketLink = "<a href='" . $CFG_GLPI["root_doc"] . "/front/ticket.form.php?id=" . $row['tickets_id'] . "' target='_blank'>#" . $row['tickets_id'] . "</a>";

        $statusInfo = $level1StatusLabels[$row['status']] ?? ['class' => 'bg-secondary text-white', 'label' => Html::entities_deep((string)$row['status'])];
        $statusBadge = "<span class='badge " . $statusInfo['class'] . "'>" . $statusInfo['label'] . "</span>";

        $cost = isset($row['execution_cost']) ? (float)$row['execution_cost'] : 0.0;
        $tokens = isset($row['token_usage']) ? (int)$row['token_usage'] : 0;
        $costFormatted = number_format($cost, 5);
        $costDisplay = "<span title='$tokens " . __('tokens', 'aisuite') . "'>$$costFormatted</span>";
        if ($tokens > 0) {
            $costDisplay .= " <small class='text-muted'>($tokens tks)</small>";
        }

        $lastActivity = !empty($row['date_mod']) ? Html::convDateTime($row['date_mod']) : '-';

        echo "<tr><td>{$row['id']}</td><td><strong>$ticketLink</strong></td><td>$statusBadge</td><td>{$row['round']}</td><td>$costDisplay</td><td>$lastActivity</td></tr>";
    }
    echo "</tbody></table></div>";
} else {
    echo "<div class='p-5 text-center text-muted'><h4><i class='fas fa-ghost'></i></h4>" . __('Aucun historique disponible pour le moment.', 'aisuite') . "</div>";
}

echo "      </div>";
echo "      <div class='modal-footer'><button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Fermer', 'aisuite') . "</button></div>";
echo "    </div></div></div>";

echo "</div>"; // tab-level1

// -------------------------------------------------------------------
// TAB: AI Chatbot
// -------------------------------------------------------------------
$active = ($active_tab === 'chat') ? ' show active' : '';
$chat_icon_type = $conf['chat_bot_icon_type'] ?? 'emoji';
echo "<div class='tab-pane fade{$active}' id='tab-chat' role='tabpanel'>";
echo "<form method='post' action='config.form.php?tab=chat' enctype='multipart/form-data'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo " <div class='mb-4 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Module activé', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
$chatEnabledChecked = (!isset($conf['chat_enabled']) || !empty($conf['chat_enabled'])) ? 'checked' : '';
echo '<input type="hidden" name="chat_enabled" value="0">';
echo '<input type="checkbox" class="form-check-input" name="chat_enabled" value="1" ' . $chatEnabledChecked . '>';
echo "   <div class='form-text'>" . __('Désactivez ce module si vous ne voulez pas afficher la bulle de discussion IA.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<h4 class='subheader mb-3'>" . __('Connexion à l\'IA', 'aisuite') . "</h4>";
echo "<p class='text-muted'>" . sprintf(__('Utilise le fournisseur actif configuré dans l\'onglet « Fournisseurs IA » (actuellement : %s).', 'aisuite'), $families[$active_provider]) . "</p>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Numéro de téléphone du support', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo Html::input('chat_support_phone', ['value' => $conf['chat_support_phone'] ?? '', 'class' => 'form-control', 'size' => 60]);
echo "   <div class='form-text'>" . __('Proposé à l\'utilisateur pour les cas urgents (bouton « Appeler le support »).', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Prompt système', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
Html::textarea([
    'name'        => 'chat_system_prompt',
    'value'       => $conf['chat_system_prompt'] ?? '',
    'rows'        => 8,
    'class'       => 'form-control font-monospace',
    'placeholder' => __("Contexte métier, règles de niveau 1, exemples de cas à traiter ou à escalader…", 'aisuite')
]);
echo "   <div class='form-text'>" . __('Définissez ici le comportement du bot : son ton, ses règles métier, les cas à traiter ou à escalader vers un ticket, etc.', 'aisuite') . "</div>";
echo "  </div>";
echo " </div>";

echo "<h4 class='subheader mb-3 mt-4'>" . __('Personnalisation de l\'interface', 'aisuite') . "</h4>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Type d\'icône du bot', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo "   <select name='chat_bot_icon_type' id='chat_bot_icon_type' class='form-select' style='max-width:30ch;'>";
foreach (['emoji' => __('Texte / emoji', 'aisuite'), 'image' => __('Image (upload)', 'aisuite')] as $code => $label) {
    $selected = ($code === $chat_icon_type) ? ' selected' : '';
    echo "<option value='{$code}'$selected>{$label}</option>";
}
echo "   </select>";
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row' id='row_chat_icon_text'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Texte / emoji de l\'icône', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo Html::input('chat_bot_icon_text', ['value' => $conf['chat_bot_icon_text'] ?? '?', 'class' => 'form-control', 'size' => 20]);
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row' id='row_chat_icon_image'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Image d\'icône (upload)', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
echo Html::input('chat_bot_icon_image_file', ['name' => 'chat_bot_icon_image_file', 'type' => 'file', 'class' => 'form-control']);
echo Html::input('chat_bot_icon_image_url_current', ['type' => 'hidden', 'value' => $conf['chat_bot_icon_image_url'] ?? '']);
if (!empty($conf['chat_bot_icon_image_url'])) {
    echo "<div class='mt-2'><img src='" . Html::entities_deep($conf['chat_bot_icon_image_url']) . "' style='max-height:48px;max-width:48px;border-radius:50%;border:1px solid #ddd;background:#fff;'></div>";
}
echo "  </div>";
echo " </div>";

echo " <div class='mb-3 row'>";
echo "  <label class='col-sm-3 col-form-label'>" . __('Couleur principale du bot', 'aisuite') . "</label>";
echo "  <div class='col-sm-9'>";
$use_theme_checked = !empty($conf['chat_bot_color_use_theme']) ? ' checked' : '';
echo "   <div class='form-check mb-2'>";
echo "    <input type='checkbox' class='form-check-input' id='chat_bot_color_use_theme' name='chat_bot_color_use_theme' value='1'{$use_theme_checked}>";
echo "    <label class='form-check-label' for='chat_bot_color_use_theme'>" . __('Utiliser la couleur du thème GLPI (par défaut)', 'aisuite') . "</label>";
echo "   </div>";
echo "   <div id='chat_bot_color_picker_row'>";
echo Html::input('chat_bot_color', ['id' => 'chat_bot_color', 'type' => 'color', 'value' => $conf['chat_bot_color'] ?: '#2563eb', 'class' => 'form-control form-control-color', 'style' => 'padding:0; width:4rem; height:2.5rem;']);
echo "   </div>";
echo "  </div>";
echo " </div>";

echo "<div class='text-center mt-4'>";
echo Html::submit(__('Enregistrer', 'aisuite'), ['name' => 'save_chat', 'class' => 'btn btn-primary']);
echo "</div>";
Html::closeForm();
echo "</div>"; // tab-chat

echo "</div>"; // tab-content
echo " </div>"; // card-body
echo "</div>"; // card

if ($saved) {
    echo "<script>alert('" . __('Configuration sauvegardée', 'aisuite') . "');</script>";
}
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Toggle visible provider-family block based on the active dropdown
    var activeSelect = document.getElementById('provider_active');
    function updateFamilyBlocks() {
        if (!activeSelect) return;
        var val = activeSelect.value;
        document.querySelectorAll('.provider-family-block').forEach(function (block) {
            block.style.display = (block.dataset.family === val) ? '' : 'none';
        });
    }
    if (activeSelect) {
        activeSelect.addEventListener('change', updateFamilyBlocks);
        updateFamilyBlocks();
    }

    // Provider connection test (shared Providers tab)
    document.querySelectorAll('.btn-test-provider').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var family = btn.dataset.family;
            var resultDiv = document.querySelector('[data-family-result="' + family + '"]');
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + <?php echo json_encode(__('Test...', 'aisuite')); ?>;
            resultDiv.innerHTML = '';

            var form = document.getElementById('form-providers');
            var data = new FormData();
            data.append('action', 'test_provider');
            data.append('family', family);
            data.append('api_url', form.querySelector('[name="provider_' + family + '_url"]').value);
            data.append('api_key', form.querySelector('[name="provider_' + family + '_key"]').value);
            data.append('ai_model', form.querySelector('[name="provider_' + family + '_model"]').value);
            data.append('_glpi_csrf_token', form.querySelector('[name="_glpi_csrf_token"]').value);

            fetch('config.form.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (resp) {
                    if (resp.success) {
                        resultDiv.innerHTML = '<div class="alert alert-success p-2"><i class="fas fa-check-circle"></i> ' + resp.message + '</div>';
                    } else {
                        resultDiv.innerHTML = '<div class="alert alert-danger p-2">' + resp.message + '</div>';
                    }
                    // The test call consumes the page's one-time CSRF token: refresh
                    // every form's hidden field so the next "Enregistrer" still works.
                    if (resp.csrf_token) {
                        document.querySelectorAll('[name="_glpi_csrf_token"]').forEach(function (input) {
                            input.value = resp.csrf_token;
                        });
                    }
                })
                .catch(function () {
                    resultDiv.innerHTML = '<div class="alert alert-danger p-2"><?php echo __('Erreur réseau', 'aisuite'); ?></div>';
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.innerHTML = original;
                });
        });
    });

    // AI Chatbot icon type toggle
    var typeSelect = document.getElementById('chat_bot_icon_type');
    var rowText    = document.getElementById('row_chat_icon_text');
    var rowImage   = document.getElementById('row_chat_icon_image');
    function updateIconRows() {
        if (!typeSelect) return;
        var v = typeSelect.value;
        rowText.style.display  = (v === 'image') ? 'none' : '';
        rowImage.style.display = (v === 'image') ? '' : 'none';
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', updateIconRows);
        updateIconRows();
    }

    var useThemeCheckbox = document.getElementById('chat_bot_color_use_theme');
    var colorRow         = document.getElementById('chat_bot_color_picker_row');
    function updateColorState() {
        if (!useThemeCheckbox) return;
        colorRow.style.display = useThemeCheckbox.checked ? 'none' : '';
    }
    if (useThemeCheckbox) {
        useThemeCheckbox.addEventListener('change', updateColorState);
        updateColorState();
    }
});
</script>
<?php
Html::footer();
