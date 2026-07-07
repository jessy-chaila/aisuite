/**
 * AI SmartSorter - UI Script
 * Technical: Handles the display of AI suggestions within GLPI Ticket forms
 */
$(document).ready(function() {

    /* Technical: Context validation to ensure execution only on specific ticket forms */
    var urlParams = new URLSearchParams(window.location.search);
    var ticketId = urlParams.get('id');
    var isTicketPage = window.location.pathname.indexOf('ticket.form.php') > -1;

    if (!isTicketPage || !ticketId || ticketId <= 0) {
        return;
    }

    /* Technical: Backend AJAX endpoint for suggestion retrieval */
    var ajaxUrl = CFG_GLPI.root_doc + '/plugins/aisuite/front/modal.form.php';

    /**
     * Technical: Fetches a brand-new CSRF token from the server, to be used
     * immediately afterwards in a single dismiss_suggestion/apply_suggestion
     * POST. GLPI's CSRF tokens are consumed as soon as they are used (or
     * otherwise rotated out under busy pages), so a token read once (a
     * static page meta tag) can no longer be valid by the time a click
     * later tries to reuse it - minting one right before each write request
     * avoids that.
     *
     * @return {Promise<string>} A freshly minted CSRF token, or '' on error.
     */
    function fetchFreshCsrfToken() {
        return $.get(ajaxUrl, { action: 'get_csrf_token' }, null, 'json')
            .then(function(resp) {
                return (resp && resp.csrf_token) || '';
            })
            .catch(function() {
                return '';
            });
    }

    $.ajax({
        url: ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'get_suggestion',
            tickets_id: ticketId
        },
        success: function(response) {
            /* Technical: Trigger modal rendering if a suggestion is available */
            if (response.success && response.has_suggestion) {
                showSmartSorterModal(response, ticketId);
            }
        },
        error: function(xhr, status, error) {
            console.error("[AI SmartSorter] AJAX Error:", error);
        }
    });

    /**
     * Technical: Renders the recommendation modal using dynamic HTML injection
     */
    function showSmartSorterModal(data, ticketId) {
        var modalId = 'ai-smartsorter-modal';
        $('#' + modalId).remove();

        var lbl = data.labels;

        /* Technical: Category display logic with fallback for undetermined values */
        var categoryDisplay = data.category;
        if (!categoryDisplay || categoryDisplay === 'N/A' || categoryDisplay === 'null') {
            categoryDisplay = '<span style="color:#999; font-style:italic;">' + lbl.not_determined + '</span>';
        }

        /* Technical: Ticket type display logic with fallback for undetermined values */
        var typeDisplay = data.ticket_type;
        if (!typeDisplay || typeDisplay === 'N/A' || typeDisplay === 'null') {
            typeDisplay = '<span style="color:#999; font-style:italic;">' + lbl.not_determined + '</span>';
        }

        /* Technical: Hardware display logic - handles Free vs Premium UI states */
        var hardwareHtml = '';

        if (data.is_free) {
            /* Technical: Case 1 - Free mode (feature locked) */
            hardwareHtml = `
            <div class="ai-hardware-alert locked" style="background: #e9ecef; border: 1px solid #dee2e6; color: #6c757d; padding: 10px; border-radius: 6px; margin-top: 10px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-lock" style="color: #dc3545;"></i>
                <span>${lbl.free_lock_msg}</span>
            </div>`;
        } else {
            /* Technical: Case 2 - Premium mode (active) */
            if (data.hardware && data.hardware !== 'null' && data.hardware !== 'N/A') {
                hardwareHtml = `
                <div class="ai-hardware-alert success" style="background: #d1e7dd; border: 1px solid #badbcc; color: #0f5132; padding: 10px; border-radius: 6px; margin-top: 10px;">
                    <i class="fas fa-microchip"></i> ${lbl.hardware_found}: <strong>${data.hardware}</strong>
                </div>`;
            } else {
                hardwareHtml = `
                <div class="ai-hardware-alert warning" style="background: #fff3cd; border: 1px solid #ffecb5; color: #664d03; padding: 10px; border-radius: 6px; margin-top: 10px;">
                    <i class="fas fa-search-minus"></i> ${lbl.hardware_none}
                </div>`;
            }
        }

        /* Technical: Estimated execution cost rendering */
        var costHtml = '';
        if (data.cost) {
            costHtml = `
            <div class="ai-cost-display" style="text-align: right; font-size: 11px; color: #adb5bd; margin-top: 5px;">
                <i class="fas fa-coins"></i> ${lbl.cost_info} <strong>${data.cost}</strong>
            </div>`;
        }

        /* Technical: Quota remaining badge for Free tier users */
        var quotaHtml = '';
        if (data.is_free) {
            quotaHtml = `<span style="font-size: 11px; background: #ffc107; color: #000; padding: 3px 8px; border-radius: 10px; font-weight: bold; margin-left: 10px;">${lbl.quota_info}</span>`;
        }

        /* Technical: Modal structure construction */
        var html = `
        <div id="${modalId}" class="ai-modal-overlay">
            <div class="ai-modal-box">
                <div class="ai-modal-header">
                    <span><i class="fas fa-robot ai-pulse"></i> ${lbl.title}</span>
                    ${quotaHtml}
                </div>
                <div class="ai-modal-body">
                    <p class="ai-reasoning">"${data.reasoning}"</p>

                    <div class="ai-suggestion-box">
                        <div class="ai-label">${lbl.suggested_cat}</div>
                        <div class="ai-value">${categoryDisplay}</div>
                        <div class="ai-label" style="margin-top:8px;">${lbl.suggested_type}</div>
                        <div class="ai-value">${typeDisplay}</div>
                        <div class="ai-confidence">${lbl.confidence}: ${data.confidence}%</div>
                    </div>

                    ${hardwareHtml}
                    ${costHtml} </div>
                <div class="ai-modal-footer">
                    <button id="ai-btn-dismiss" class="btn btn-secondary btn-sm">${lbl.btn_ignore}</button>
                    <button id="ai-btn-apply" class="btn btn-primary btn-sm" data-log-id="${data.log_id}">
                        <i class="fas fa-check"></i> ${lbl.btn_apply}
                    </button>
                </div>
            </div>
        </div>`;

        $('body').append(html);

        /* Technical: Event listeners for dismissal and application actions */
        $('#ai-btn-dismiss').on('click', function() {
            $('#' + modalId).fadeOut();
            fetchFreshCsrfToken().then(function(csrfToken) {
                $.post(ajaxUrl, {
                    action: 'dismiss_suggestion',
                    log_id: data.log_id,
                    tickets_id: ticketId,
                    _glpi_csrf_token: csrfToken
                });
            });
        });

        $('#ai-btn-apply').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> ' + lbl.btn_applying);

            fetchFreshCsrfToken().then(function(csrfToken) {
                $.post(ajaxUrl, {
                    action: 'apply_suggestion',
                    log_id: data.log_id,
                    tickets_id: ticketId,
                    _glpi_csrf_token: csrfToken
                }, function(res) {
                    $('#' + modalId).fadeOut();
                    if(res.success) {
                        /* Technical: Reload the page to reflect ITIL category assignment */
                        location.reload();
                    }
                }, 'json');
            });
        });
    }
});
