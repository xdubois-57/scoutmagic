/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// SOS Staff d'U configuration page (modules/sos_staff/views/config.html.twig):
// the three-step OVH setup wizard (Application Key/Secret → Consumer Key →
// phone line), the connection test, and the per-section planning filter.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/sos-config.js's spec,
// tests/js/sos-config.test.js) — an inline template script is invisible to
// both Vitest and `npm run typecheck`.
//
// This page handles telephony provider credentials (AGENTS.md § Security
// checklist, SECURITY.md), so a few rules are absolute here:
//   - no credential and no phone number is ever logged, and none is ever
//     interpolated into a URL — every value travels in a POST body;
//   - nothing on this page is built as markup. The connection-test result
//     used to be assembled with innerHTML around `data.error`, which is a
//     provider-supplied string: an OVH error message containing a tag was
//     rendered as HTML (the audit-M16 shape). Every value that reaches the
//     screen now goes through textContent or a DOM node;
//   - the OVH lines returned by « Récupérer mes lignes » used to be stuffed
//     into each <option value> as JSON and parsed back on selection. The
//     billing account and service name now ride data-* attributes, so no
//     provider string is ever parsed as code.
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data}),
// which also owns the CSRF token this file used to read through a local
// csrf() helper. `ok` is HTTP-level only: the business verdict of every
// endpoint here is `data.success`, and the two are deliberately kept apart —
// an HTML 500 page arrives as data:null, which is a failure, never a success.
//
// The two native dialogs are gone (design.md §7.5): regenerating the Consumer
// Key asks through window.ScoutMagicConfirm (danger — the current key stops
// working and the line has to be picked again), and the alert() that reported
// its failure is a toast.
(function () {
    var credentialsForm = /** @type {HTMLFormElement|null} */ (document.getElementById('ovh-credentials-form'));
    var excludedForm = /** @type {HTMLFormElement|null} */ (document.getElementById('excluded-sections-form'));
    // A no-op on every other page of the site: this file is a page script.
    if (!credentialsForm && !excludedForm) {
        return;
    }

    var api = window.ScoutMagicApi;

    /**
     * The business verdict of one envelope. HTTP `ok` is deliberately not
     * consulted: a 200 carrying {success:false} is a failure just the same,
     * and a non-JSON body (an error page) came back as data:null.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function succeeded(res) {
        return !!(res.data?.success);
    }

    /**
     * What to show for a failed call: the server's own French message when
     * it answered JSON, a generic line when it did not.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function failureMessage(res) {
        return res.data?.error || 'Erreur : réponse serveur invalide.';
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function toastFailure(res) {
        window.ScoutMagicToast.show(failureMessage(res), { variant: 'error' });
    }

    /**
     * Shows one of the page's inline error lines. Always textContent: the
     * text is a provider message, never markup.
     *
     * @param {HTMLElement|null} box
     * @param {string} message
     */
    function showError(box, message) {
        if (!box) {
            return;
        }
        box.textContent = message;
        box.classList.remove('d-none');
    }

    /** @param {HTMLElement|null} box */
    function hideError(box) {
        if (box) {
            box.textContent = '';
            box.classList.add('d-none');
        }
    }

    /**
     * @param {string} id
     * @returns {HTMLInputElement|null}
     */
    function inputEl(id) {
        return /** @type {HTMLInputElement|null} */ (document.getElementById(id));
    }

    // --- Password-style fields: show / hide ---

    /** @type {NodeListOf<HTMLButtonElement>} */ (document.querySelectorAll('.toggle-visibility')).forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = inputEl(btn.dataset.target || '');
            if (!input) {
                return;
            }
            input.type = input.type === 'password' ? 'text' : 'password';
        });
    });

    // --- Step 1: Application Key / Secret ---

    if (credentialsForm) {
        var credentialsError = document.getElementById('ovh-credentials-error');

        credentialsForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            hideError(credentialsError);

            var submitBtn = /** @type {HTMLButtonElement|null} */ (credentialsForm.querySelector('button[type="submit"]'));
            var keyInput = inputEl('ovh-application-key');
            var secretInput = inputEl('ovh-application-secret');

            var res = await api.withDisabled(submitBtn, function () {
                // Both values are secrets: body only, never a query string,
                // never a log line.
                return api.postJson('/config/sos/ovh/credentials', {
                    application_key: keyInput ? keyInput.value : '',
                    application_secret: secretInput ? secretInput.value : ''
                });
            });

            if (succeeded(res)) {
                window.location.reload();
            } else {
                showError(credentialsError, failureMessage(res));
            }
        });
    }

    // --- Step 2: Consumer Key ---

    var consumerKeyError = document.getElementById('consumer-key-error');

    /**
     * OVH answers the generation call with a validation URL the user has to
     * open. It is a link this page writes into an href, so it is checked to
     * be an http(s) URL first — an href is one of the few places where a
     * provider-supplied string could still become executable code.
     *
     * @param {unknown} raw
     * @returns {string|null} the URL, or null when it is not a web link
     */
    function safeHttpUrl(raw) {
        if (typeof raw !== 'string' || raw === '') {
            return null;
        }
        try {
            var parsed = new URL(raw, window.location.href);
            return (parsed.protocol === 'https:' || parsed.protocol === 'http:') ? parsed.href : null;
        } catch {
            // URL() throws on a value that is not a URL — there is none.
            return null;
        }
    }

    var generateBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('generate-consumer-key-btn'));
    if (generateBtn) {
        generateBtn.addEventListener('click', async function () {
            hideError(consumerKeyError);

            var res = await api.withDisabled(generateBtn, function () {
                return api.postJson('/config/sos/ovh/generate-consumer-key', {});
            });
            if (!succeeded(res)) {
                showError(consumerKeyError, failureMessage(res));
                return;
            }

            var url = safeHttpUrl(res.data.validation_url);
            if (!url) {
                showError(consumerKeyError, 'Erreur : réponse serveur invalide.');
                return;
            }

            var link = /** @type {HTMLAnchorElement|null} */ (document.getElementById('consumer-key-validation-link'));
            if (link) {
                link.href = url;
                link.textContent = url;
            }
            document.getElementById('consumer-key-validation')?.classList.remove('d-none');
        });
    }

    var regenerateBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('regenerate-consumer-key-btn'));
    if (regenerateBtn) {
        regenerateBtn.addEventListener('click', async function () {
            // Destructive: the current Consumer Key stops working the moment
            // a new one is requested, and the line has to be selected again —
            // so this keeps the danger variant ScoutMagicConfirm defaults to.
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Régénérer la Consumer Key ? La ligne devra être sélectionnée à nouveau.',
                confirmLabel: 'Régénérer'
            });
            if (!confirmed) {
                return;
            }

            var res = await api.withDisabled(regenerateBtn, function () {
                return api.postJson('/config/sos/ovh/generate-consumer-key', {});
            });
            if (succeeded(res)) {
                window.location.reload();
            } else {
                toastFailure(res);
            }
        });
    }

    var validateBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('validate-consumer-key-btn'));
    if (validateBtn) {
        validateBtn.addEventListener('click', async function () {
            hideError(consumerKeyError);

            var res = await api.withDisabled(validateBtn, function () {
                return api.postJson('/config/sos/ovh/validate-consumer-key', {});
            });
            if (succeeded(res)) {
                window.location.reload();
            } else {
                showError(consumerKeyError, failureMessage(res));
            }
        });
    }

    // --- Step 3: phone line ---

    var linesError = document.getElementById('lines-error');
    var lineSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('line-select'));

    /**
     * Fills the line picker. Each line's billing account and service name
     * ride data-* attributes rather than a JSON blob in the option value:
     * these strings come from OVH, and nothing coming from OVH is parsed.
     * The label carries the line's number, so it is set as text only.
     *
     * @param {Array<{billing_account?: string, service_name?: string, number?: string}>} lines
     */
    function fillLineOptions(lines) {
        if (!lineSelect) {
            return;
        }
        lineSelect.textContent = '';
        lines.forEach(function (line) {
            var option = document.createElement('option');
            option.value = String(line.service_name == null ? '' : line.service_name);
            option.dataset.billingAccount = String(line.billing_account == null ? '' : line.billing_account);
            option.dataset.serviceName = option.value;
            option.textContent = (line.billing_account || '') + ' — ' + (line.number || '');
            lineSelect.appendChild(option);
        });
    }

    var listLinesBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('list-lines-btn'));
    if (listLinesBtn) {
        listLinesBtn.addEventListener('click', async function () {
            hideError(linesError);

            var res = await api.withDisabled(listLinesBtn, function () {
                return api.postJson('/config/sos/ovh/list-lines', {});
            });
            if (!succeeded(res)) {
                showError(linesError, failureMessage(res));
                return;
            }

            fillLineOptions(Array.isArray(res.data.lines) ? res.data.lines : []);
            document.getElementById('line-picker-row')?.classList.remove('d-none');
        });
    }

    var selectLineBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('select-line-btn'));
    if (selectLineBtn) {
        // Selecting the line is a deliberate click, never the <select>'s own
        // change event: browsing the list must not switch the line the unit's
        // SOS number redirects from.
        selectLineBtn.addEventListener('click', async function () {
            var chosen = lineSelect ? lineSelect.selectedOptions[0] : null;
            if (!chosen) {
                return;
            }
            hideError(linesError);

            var res = await api.withDisabled(selectLineBtn, function () {
                return api.postJson('/config/sos/ovh/select-line', {
                    billing_account: chosen.dataset.billingAccount || '',
                    service_name: chosen.dataset.serviceName || ''
                });
            });
            if (succeeded(res)) {
                window.location.reload();
            } else {
                showError(linesError, failureMessage(res));
            }
        });
    }

    // --- Connection test ---

    var testBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('test-connection-btn'));
    var testResult = document.getElementById('test-connection-result');

    /**
     * The one-line verdict next to « Tester la connexion ». Built as DOM
     * nodes: the failure branch carries the provider's own message, which
     * used to be concatenated into innerHTML.
     *
     * @param {boolean} ok
     * @param {string} message
     */
    function renderTestResult(ok, message) {
        if (!testResult) {
            return;
        }
        testResult.textContent = '';

        var wrapper = document.createElement('span');
        wrapper.className = ok ? 'text-success' : 'text-danger';

        var icon = document.createElement('i');
        icon.className = ok ? 'bi bi-check-circle' : 'bi bi-x-circle';
        icon.setAttribute('aria-hidden', 'true');
        wrapper.appendChild(icon);
        wrapper.appendChild(document.createTextNode(' ' + message));

        testResult.appendChild(wrapper);
    }

    if (testBtn) {
        testBtn.addEventListener('click', async function () {
            if (testResult) {
                testResult.textContent = '…';
            }

            var res = await api.withDisabled(testBtn, function () {
                return api.postJson('/config/sos/test-connection', {});
            });
            if (succeeded(res)) {
                renderTestResult(true, 'Connexion réussie');
            } else {
                renderTestResult(false, failureMessage(res));
            }
        });
    }

    // --- Sections included in the planning grid ---

    /** @type {NodeListOf<HTMLInputElement>} */
    var sectionCheckboxes = document.querySelectorAll('.excluded-section-checkbox');
    sectionCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            // Checkboxes represent "included" sections; the endpoint stores
            // the excluded (unchecked, non-disabled) ones.
            /** @type {number[]} */
            var excludedIds = [];
            document.querySelectorAll('.excluded-section-checkbox:not(:checked):not(:disabled)').forEach(function (cb) {
                excludedIds.push(Number.parseInt(/** @type {HTMLInputElement} */ (cb).value, 10));
            });

            var res = await api.withDisabled(checkbox, function () {
                return api.postJson('/config/sos/excluded-sections', { section_ids: excludedIds });
            });
            // This save used to be fire-and-forget: a rejected change left
            // the box looking saved.
            if (!succeeded(res)) {
                toastFailure(res);
            }
        });
    });
})();
