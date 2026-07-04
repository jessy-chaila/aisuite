/**
 * AI Level 1 Assistant - UI Script
 * Technical: Injects a "Disable AI" button into GLPI's native ticket timeline
 * button bar (#itil-footer .timeline-buttons), visible only on the Helpdesk
 * (self-service) interface (this file is only loaded there, see setup.php),
 * letting the end user explicitly request a human technician instead of the AI.
 */
$(document).ready(function() {

    /* Technical: Context validation to ensure execution only on specific ticket forms */
    var urlParams = new URLSearchParams(window.location.search);
    var ticketId = urlParams.get('id');
    var isTicketPage = window.location.pathname.indexOf('ticket.form.php') > -1;

    if (!isTicketPage || !ticketId || ticketId <= 0) {
        return;
    }

    var ajaxUrl = CFG_GLPI.root_doc + '/plugins/aisuite/public/ajax.level1.php';
    var csrfToken = null;
    var labels = null;

    /* Technical: The native timeline footer can be rendered/replaced asynchronously
       (e.g. after the timeline itself loads), so poll for it before injecting. */
    waitForFooter(function() {
        $.get(ajaxUrl, { action: 'get_csrf_token', tickets_id: ticketId }, function(resp) {
            if (resp && resp.csrf_token && resp.labels) {
                // The AI has already been permanently stepped back on this
                // ticket (manual opt-out or technician takeover): the button
                // no longer serves any purpose, so simply don't show it
                // instead of leaving a stale control that would just error
                // out if clicked again.
                if (resp.ai_disabled) {
                    return;
                }
                csrfToken = resp.csrf_token;
                labels = resp.labels;
                injectButton();
            }
        }, 'json');
    });

    function waitForFooter(callback) {
        var tries = 0;
        var interval = setInterval(function() {
            tries++;
            if ($('#itil-footer .timeline-buttons').length) {
                clearInterval(interval);
                callback();
            } else if (tries > 40) {
                clearInterval(interval);
            }
        }, 250);
    }

    function injectButton() {
        var container = $('#itil-footer .timeline-buttons');
        if (!container.length || container.find('#ai-level1-request-human').length) {
            return;
        }

        var html = '<button type="button" id="ai-level1-request-human" class="btn" aria-label="' + escapeHtml(labels.button) + '">'
            + '<i class="ti ti-headset"></i>'
            + '<span>' + escapeHtml(labels.button) + '</span>'
            + '</button>';

        // Place it right after the "Reply" button group, at the start of the
        // bar: putting it at the far right (e.g. after ".ms-auto") crowds out
        // native right-aligned actions such as "Cancel ticket".
        var mainActions = container.find('.btn-group.main-actions').first();
        if (mainActions.length) {
            mainActions.after(html);
        } else {
            container.prepend(html);
        }

        $('#ai-level1-request-human').on('click', onButtonClick);
    }

    /**
     * Technical: Fetches a brand-new CSRF token from the server, to be used
     * immediately afterwards in the request_human POST. GLPI's CSRF tokens
     * are consumed as soon as they are used (or otherwise rotated out under
     * busy pages), so the token cached at page load in csrfToken - possibly
     * long before the button is actually clicked - can no longer be valid
     * by then. Minting one right before the write request avoids that.
     *
     * @return {Promise<string>} A freshly minted CSRF token, or the
     * page-load one as a last-resort fallback if the fetch fails.
     */
    function fetchFreshCsrfToken() {
        return $.get(ajaxUrl, { action: 'get_csrf_token', tickets_id: ticketId }, null, 'json')
            .then(function(resp) {
                return (resp && resp.csrf_token) || csrfToken;
            })
            .catch(function() {
                return csrfToken;
            });
    }

    function onButtonClick() {
        if (!window.confirm(labels.confirm)) {
            return;
        }

        var btn = $('#ai-level1-request-human').prop('disabled', true);
        setButtonContent(btn, 'ti-loader-2', labels.button);

        fetchFreshCsrfToken().then(function(freshToken) {
            $.post(ajaxUrl, {
                action: 'request_human',
                tickets_id: ticketId,
                _glpi_csrf_token: freshToken
            }, function(resp) {
                if (resp && resp.success) {
                    setButtonContent(btn, 'ti-check', labels.done);
                } else {
                    window.alert((resp && resp.message) || labels.error);
                    btn.prop('disabled', false);
                    setButtonContent(btn, 'ti-headset', labels.button);
                }
            }, 'json').fail(function() {
                window.alert(labels.error);
                btn.prop('disabled', false);
                setButtonContent(btn, 'ti-headset', labels.button);
            });
        });
    }

    function setButtonContent(btn, iconClass, text) {
        btn.html('<i class="ti ' + iconClass + '"></i><span>' + escapeHtml(text) + '</span>');
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
});
