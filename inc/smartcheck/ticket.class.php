<?php

namespace GlpiPlugin\Aisuite\SmartCheck;

use CommonGLPI;
use Session;
use Ticket as GlpiTicket;
use GlpiPlugin\Aisuite\SmartCheck\Suggestion;
use Html;

class Ticket extends CommonGLPI {

    /**
     * Define the tab name for the Ticket object.
     * @param CommonGLPI $item
     * @param int $withtemplate
     * @return string
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() !== 'Ticket') {
            return '';
        }

        if (Session::getCurrentInterface() !== 'central') {
            return '';
        }

        return '<span class="d-flex align-items-center"><i class="fas fa-robot me-2"></i> ' . __('AI Smart Check', 'aisuite') . '</span>';
    }

    /**
     * Display the content of the tab.
     * Includes CSS and JS injection for dynamic functionality.
     * @param CommonGLPI $item
     * @param int $tabnum
     * @param int $withtemplate
     * @return bool
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        // Double sécurité : vérification des droits et de l'interface
        if ($item->getType() !== 'Ticket' 
            || !Session::haveRight("ticket", READ)
            || Session::getCurrentInterface() !== 'central') {
            return false;
        }

        $ticketId = $item->getID();
        $savedData = Suggestion::getSavedAnalysis($ticketId);

        $hasAnalysis = !empty($savedData);
        $content = $hasAnalysis ? $savedData['content'] : '';
        $dateMod = $hasAnalysis ? Html::convDateTime($savedData['date_mod']) : '';

        // ---------------------------------------------------------
        // 1. CSS Injection (Scoped)
        // ---------------------------------------------------------
        echo "<style>
            #aismartcheck_container { padding: 0; background: #fff; border: 1px solid #dce1e7; border-radius: 4px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); margin-top: 15px; overflow: hidden; }
            .ai-header { padding: 15px 20px; background: #f1f4f9; border-bottom: 1px solid #dce1e7; display: flex; justify-content: space-between; align-items: center; }
            .ai-body { padding: 20px; }
            .ai-footer { background: #fafbfc; border-top: 1px solid #eee; padding: 8px 20px; font-size: 0.8rem; color: #6c757d; text-align: right; }

            .saving-indicator { transition: opacity 0.5s; opacity: 0; font-size: 0.85em; color: #28a745; margin-right: 15px; font-weight: 600; }
            .saving-indicator.show { opacity: 1; }

            .task-done { text-decoration: line-through; color: #adb5bd !important; font-style: italic; }
            .ai-checkbox { cursor: pointer; transform: scale(1.1); margin-top: 0.3em; }

            /* Badge Styling */
            .ai-priority-badge { 
                display: inline-block; 
                min-width: 80px; 
                text-align: center; 
                margin-right: 12px; 
                font-size: 0.75rem; 
                padding: 6px 0; 
                border-radius: 4px; 
                font-weight: 700; 
                text-transform: uppercase; 
                letter-spacing: 0.5px; 
                color: #fff !important; 
            }
            .bg-danger { background-color: #dc3545 !important; }
            .bg-warning { background-color: #ffc107 !important; color: #212529 !important; }
            .bg-secondary { background-color: #6c757d !important; }
        </style>";

        echo "<div id='aismartcheck_container'>";

        // --- HEADER ---
        echo "<div class='ai-header'>";
            echo "<h3 class='mb-0' style='font-size: 1.1rem;'><i class='fas fa-sparkles text-warning me-2'></i>" . __('Analyse Intelligente', 'aisuite') . "</h3>";
            
            echo "<div id='ai-toolbar' style='" . ($hasAnalysis ? '' : 'display:none;') . "'>";
                echo "<span id='auto-save-status' class='saving-indicator'><i class='fas fa-check'></i> " . __('Sauvegardé', 'aisuite') . "</span>";
                echo "<button type='button' class='btn btn-sm btn-outline-danger me-2' id='btn-reset-ai' title='" . __('Relancer', 'aisuite') . "'>";
                echo "<i class='fas fa-redo'></i>";
                echo "</button>";
                echo "<button type='button' class='btn btn-sm btn-success text-white' id='btn-save-ai-note' title='" . __('Enregistrer en Note', 'aisuite') . "'>";
                echo "<i class='fas fa-file-export me-1'></i> " . __('Note', 'aisuite');
                echo "</button>";
            echo "</div>";
        echo "</div>";

        // --- BODY ---
        echo "<div class='ai-body' id='ai-content-body'>";

        if ($hasAnalysis) {
            echo $content;
        } else {
            echo "<div class='text-center py-5' id='ai-welcome-screen'>";
            echo "<div class='mb-3'><i class='fas fa-robot fa-3x text-muted'></i></div>";
            echo "<h4 class='text-muted'>" . __('Aucune analyse disponible', 'aisuite') . "</h4>";
            echo "<p class='text-muted mb-4'>" . __('L\'IA va analyser le titre et la description pour proposer un diagnostic.', 'aisuite') . "</p>";
            echo "<button type='button' class='btn btn-primary btn-lg shadow-sm' id='btn-launch-ai-analysis' data-ticket-id='" . $ticketId . "'>";
            echo "<i class='fas fa-play me-2'></i> " . __('Lancer l\'analyse', 'aisuite');
            echo "</button>";
            echo "</div>";
        }
        echo "</div>";

        // --- FOOTER ---
        echo "<div class='ai-footer' id='ai-footer' style='" . ($hasAnalysis ? '' : 'display:none;') . "'>";
        echo "<i class='fas fa-clock me-1'></i> " . __('Dernier changement :', 'aisuite') . " <span id='ai-last-saved'>" . $dateMod . "</span>";
        echo "</div>";

        echo "</div>";

        // ---------------------------------------------------------
        // 2. JavaScript Logic
        // ---------------------------------------------------------
        global $CFG_GLPI;
        $jsConfig = json_encode([
            'endpoint'    => $CFG_GLPI['root_doc'] . '/plugins/aisuite/front/suggestion.form.php',
            'csrf_token' => Session::getNewCSRFToken(),
        ]);

        $loadingText = __('Analyse en cours...', 'aisuite');

        echo <<<JAVASCRIPT
        <script>
        (function() {
            const config = {$jsConfig};

            // --------------------------------------------------------
            // CORE FUNCTIONALITY
            // --------------------------------------------------------
            function applyStyles() {
                document.querySelectorAll('.ai-checkbox').forEach(cb => {
                    const label = cb.closest('label');
                    if(label) cb.checked ? label.classList.add('task-done') : label.classList.remove('task-done');
                });
            }

            function updateFooter() {
                const footer = document.getElementById('ai-footer');
                const span = document.getElementById('ai-last-saved');
                if (footer && span) {
                    const now = new Date();
                    span.innerText = now.toLocaleDateString() + ' ' + now.toLocaleTimeString();
                    footer.style.display = 'block';
                }
            }

            function callApi(action, data, onSuccess, onError) {
                const formData = new FormData();
                formData.append('action', action);
                const btnLaunch = document.getElementById('btn-launch-ai-analysis');
                formData.append('tickets_id', btnLaunch ? btnLaunch.dataset.ticketId : '{$ticketId}');
                formData.append('_glpi_csrf_token', config.csrf_token);
                for (const key in data) formData.append(key, data[key]);

                fetch(config.endpoint, { 
                    method: 'POST', body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': config.csrf_token }
                })
                .then(r => r.json()).then(onSuccess).catch(onError);
            }

            // --- Handlers ---

            function handleLaunch(e) {
                const btn = e.target.closest('#btn-launch-ai-analysis') || e.target.closest('#btn-reset-ai');
                if (!btn) return;
                e.preventDefault();

                if (btn.id === 'btn-reset-ai' && !confirm('Relancer effacera la liste actuelle. Continuer ?')) return;

                const body = document.getElementById('ai-content-body');
                const toolbar = document.getElementById('ai-toolbar');
                
                body.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><div class="mt-3 text-muted">{$loadingText}</div></div>';

                callApi('analyze_ticket', {},
                    (data) => {
                        if (data.success) {
                            body.innerHTML = data.html;
                            toolbar.style.display = 'block';
                            applyStyles();
                            updateFooter();
                        } else {
                            body.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                        }
                    },
                    (err) => { body.innerHTML = '<div class="alert alert-danger">Erreur: ' + err.message + '</div>'; }
                );
            }

            function handleCheckboxChange(e) {
                if (!e.target.classList.contains('ai-checkbox')) return;
                const container = document.getElementById('ai-content-body');
                const label = e.target.closest('label');

                if (e.target.checked) {
                    e.target.setAttribute('checked', 'checked');
                    if(label) label.classList.add('task-done');
                } else {
                    e.target.removeAttribute('checked');
                    if(label) label.classList.remove('task-done');
                }

                const statusSpan = document.getElementById('auto-save-status');
                statusSpan.classList.add('show');
                statusSpan.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';

                callApi('update_content', { content: container.innerHTML },
                    (data) => {
                        if(data.success) {
                            statusSpan.innerHTML = '<i class="fas fa-check"></i> Sauvegardé';
                            setTimeout(() => statusSpan.classList.remove('show'), 2000);
                            updateFooter();
                        }
                    },
                    (err) => console.error(err)
                );
            }

            function handleExportNote(e) {
                const btn = e.target.closest('#btn-save-ai-note');
                if (!btn) return;
                e.preventDefault();

                const container = document.getElementById('ai-content-body');
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

                callApi('save_note', { content: container.innerHTML },
                    (data) => {
                        btn.innerHTML = originalHtml;
                        if(data.success) alert('Note ajoutée !');
                        else alert('Erreur: ' + data.message);
                    },
                    (err) => { btn.innerHTML = originalHtml; alert('Erreur réseau'); }
                );
            }

            // --------------------------------------------------------
            // TAB MOVING LOGIC (Triggers on Tab Load/Click)
            // --------------------------------------------------------
            function moveTabLogic() {
                var attempts = 0;
                var maxAttempts = 50; 

                var moveInterval = setInterval(function() {
                    attempts++;
                    var moved = false;

                    // Chercher tous les conteneurs d'onglets (cas GLPI standard et responsive)
                    $('.nav.nav-tabs').each(function() {
                        var \$container = $(this);
                        var \$aiTab = \$container.find('li').has('.fa-robot');
                        var \$firstTab = \$container.children('li').first();

                        if (\$aiTab.length > 0 && \$firstTab.length > 0) {
                            // Si l'onglet n'est pas en 2ème position (index 1)
                            if (\$aiTab.index() !== 1) {
                                // On le déplace juste après le premier onglet
                                \$aiTab.insertAfter(\$firstTab);
                                moved = true;
                            } else {
                                moved = true; // Déjà bien placé
                            }
                        }
                    });

                    if (moved || attempts >= maxAttempts) {
                        clearInterval(moveInterval);
                    }
                }, 50);
            }

            // --- Initialization ---
            if (!window.aiSmartCheckInitialized) {
                document.body.addEventListener('click', (e) => { handleLaunch(e); handleExportNote(e); });
                document.body.addEventListener('change', (e) => handleCheckboxChange(e));
                applyStyles();
                moveTabLogic(); // Tentative de déplacement au chargement du contenu
                window.aiSmartCheckInitialized = true;
            } else {
                applyStyles();
                moveTabLogic();
            }
        })();
        </script>
JAVASCRIPT;

        return true;
    }
}
