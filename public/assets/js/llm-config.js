/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// LLM connector configuration page
// (modules/llm_connector/views/config/index.html.twig): the provider
// dropdown, the save-then-test provider form, and the available-models
// table. Extracted from the template's inline <script> so the Vitest suite
// can exercise the production code directly (tests/js/llm-config.test.js).
// The per-driver model lists come from the server through
// the `llm-config-data` JSON island the template renders, read through
// ScoutMagicApi.pageData() — the site-wide server-data-to-a-page
// pattern.
//
// Consulting the dropdown is NOT activating a provider. The change handler
// used to test the selected provider and then POST it back as the active
// one, so merely looking at another entry in the list silently switched the
// site's AI provider — which is an RGPD sub-processor (AGENTS.md § RGPD,
// ARCHITECTURE.md §7.5), never something a browsing gesture may change.
// The handler now only refreshes what is displayed (hidden fields, API-key
// hint, driver help block, models table) and issues no request at all;
// activation lives solely in the form's « Enregistrer et activer » submit,
// which saves (the save marks the provider active server-side) then tests.
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data}),
// which also owns the CSRF token and the HTML escaping this file used to
// re-implement locally. `ok` is HTTP-level only — the business verdict is
// `data.success`, and the two are deliberately kept apart.
(function () {
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('provider-form'));
    var driverSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('provider-driver'));
    if (!form || !driverSelect) return;

    var api = window.ScoutMagicApi;
    var escapeHtml = api.escapeHtml;
    var pageData = window.ScoutMagicApi.pageData('llm-config-data') || {};
    var MODELS_BY_DRIVER = pageData.modelsByDriver || {};

    /**
     * @param {string} id
     * @returns {HTMLElement}
     */
    function el(id) { return /** @type {HTMLElement} */ (document.getElementById(id)); }
    /**
     * @param {string} id
     * @returns {HTMLInputElement}
     */
    function inputEl(id) { return /** @type {HTMLInputElement} */ (document.getElementById(id)); }

    var messageBox = el('provider-message');

    /** @param {string} html */
    function showMessage(html) {
        messageBox.style.display = 'block';
        messageBox.innerHTML = html;
    }

    /**
     * One alert line in the form's status area.
     *
     * @param {string} tone Bootstrap alert suffix (info / success / danger / warning).
     * @param {string} message Already-escaped or literal French text.
     */
    function showAlert(tone, message) {
        showMessage('<div class="alert alert-' + tone + ' mb-0">' + message + '</div>');
    }

    // --- Display-only refresh (the dropdown's whole job) ---

    // Mirrors the selected option into the hidden fields the form posts, the
    // API-key hint, the driver-specific help block and the models table.
    // Deliberately free of any fetch: see the file header.
    function updateDriverInfo() {
        var option = driverSelect.selectedOptions[0];
        if (!option) return;

        var driverValue = driverSelect.value;
        var providerId = option.dataset.providerId || '';
        var hasKey = option.dataset.hasKey === '1';

        inputEl('provider-id').value = providerId;
        inputEl('provider-name').value = option.dataset.label || option.textContent.trim();
        inputEl('provider-endpoint').value = option.dataset.endpoint || '';
        inputEl('provider-has-key').value = hasKey ? '1' : '0';

        // A provider that already has a stored key is re-activated by
        // submitting with the key field left empty (`api_key: ''` means
        // "keep the current key" server-side) — so this hint is not
        // decoration, it is the instruction for that flow.
        var hintEl = el('api-key-hint');
        hintEl.textContent = hasKey ? 'Laisser vide pour conserver la clé actuelle.' : '';
        inputEl('provider-api-key').placeholder = hasKey ? '••••••••' : '';

        /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('[id^="driver-help-"]')).forEach(function (helpBlock) {
            helpBlock.style.display = helpBlock.id === 'driver-help-' + driverValue ? '' : 'none';
        });

        renderModelsList(driverValue);
    }

    /**
     * One badge cell for a model's tier assignments, or an em dash when the
     * model carries none.
     *
     * @param {{is_tier_cheap?: boolean|number, is_tier_capable?: boolean|number, is_tier_ocr?: boolean|number}} model
     * @returns {HTMLTableCellElement}
     */
    function buildTierCell(model) {
        var cell = document.createElement('td');
        var tiers = [
            { on: model.is_tier_cheap, className: 'text-bg-info', label: 'Économique' },
            { on: model.is_tier_capable, className: 'text-bg-primary', label: 'Performant' },
            { on: model.is_tier_ocr, className: 'text-bg-success', label: 'OCR' }
        ].filter(function (tier) { return !!tier.on; });

        if (tiers.length === 0) {
            var dash = document.createElement('span');
            dash.className = 'text-body-secondary';
            dash.textContent = '—';
            cell.appendChild(dash);
            return cell;
        }

        tiers.forEach(function (tier) {
            var badge = document.createElement('span');
            badge.className = 'badge ' + tier.className;
            badge.textContent = tier.label;
            cell.appendChild(badge);
            cell.appendChild(document.createTextNode(' '));
        });
        return cell;
    }

    /**
     * The available-models table for one driver. Built as DOM nodes rather
     * than concatenated markup: display names and model ids come from the
     * provider's own API, so nothing here may ever be parsed as HTML.
     *
     * @param {string} driver
     */
    function renderModelsList(driver) {
        var container = el('models-container');
        var models = MODELS_BY_DRIVER[driver] || [];
        container.textContent = '';

        if (models.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'text-body-secondary mb-0';
            empty.textContent = 'Aucun modèle découvert. Enregistrez une clé API pour interroger l\'API du fournisseur.';
            container.appendChild(empty);
            return;
        }

        var intro = document.createElement('p');
        intro.className = 'text-body-secondary small mb-3';
        intro.textContent = 'Les modèles économique, performant et OCR sont sélectionnés automatiquement parmi les modèles disponibles du fournisseur.';
        container.appendChild(intro);

        var table = document.createElement('table');
        table.className = 'table table-sm align-middle mb-0';
        table.innerHTML = '<thead><tr><th>Modèle</th><th>Rôle</th></tr></thead>';

        var tbody = document.createElement('tbody');
        models.forEach(function (model) {
            var row = document.createElement('tr');

            var nameCell = document.createElement('td');
            nameCell.appendChild(document.createTextNode(model.display_name == null ? '' : String(model.display_name)));
            var idLine = document.createElement('span');
            idLine.className = 'text-body-secondary small d-block';
            var code = document.createElement('code');
            code.textContent = model.model_id == null ? '' : String(model.model_id);
            idLine.appendChild(code);
            nameCell.appendChild(idLine);

            row.appendChild(nameCell);
            row.appendChild(buildTierCell(model));
            tbody.appendChild(row);
        });
        table.appendChild(tbody);

        // design.md: every table scrolls inside its own wrapper.
        var wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        wrapper.appendChild(table);
        container.appendChild(wrapper);
    }

    // --- Activation (the form, and only the form) ---

    /**
     * Tests one provider's connection, which also refreshes its model list
     * and tier assignments server-side, then reloads so the page shows the
     * stored state rather than a half-patched one.
     *
     * @param {string} providerId
     */
    function testConnection(providerId) {
        if (!providerId) return Promise.resolve();

        showAlert('info', 'Test de connexion en cours…');

        return api.postJson('/config/llm/providers/' + encodeURIComponent(providerId) + '/test', {})
            .then(function (res) {
                var data = res.data;
                if (!data?.success) {
                    showAlert('danger', escapeHtml(data?.error || 'Erreur.'));
                    return;
                }
                updateSummary(data);
                showAlert('success', 'Connexion réussie — ' + escapeHtml(String(data.message || '').replace('Connexion réussie — ', '')));
                setTimeout(function () { window.location.reload(); }, 800);
            });
    }

    /** @param {Object.<string, any>} data */
    function updateSummary(data) {
        var summaryCard = document.querySelector('[data-summary]');
        if (!summaryCard) return;

        var badge = summaryCard.querySelector('.badge');
        if (badge) {
            // Same classes as the status_badge partial's `active` tone.
            badge.className = 'badge text-bg-success';
            badge.textContent = 'Actif';
        }

        var providerStrong = summaryCard.querySelector('[data-provider] strong');
        if (providerStrong && data.provider_name) {
            providerStrong.textContent = data.provider_name;
        }

        var cheapStrong = summaryCard.querySelector('[data-cheap] strong');
        if (cheapStrong) {
            cheapStrong.textContent = data.cheap_model || '—';
        }

        var capableStrong = summaryCard.querySelector('[data-capable] strong');
        if (capableStrong) {
            capableStrong.textContent = data.capable_model || '—';
        }

        var ocrStrong = summaryCard.querySelector('[data-ocr] strong');
        if (ocrStrong) {
            var ocrText = data.ocr_model || '—';
            ocrStrong.textContent = data.ocr_fallback ? ocrText + ' (fallback)' : ocrText;
        }
    }

    updateDriverInfo();

    // Consulting the list changes what is shown, nothing more.
    driverSelect.addEventListener('change', updateDriverInfo);

    // The one deliberate act: save (which activates this provider) then test.
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        showAlert('info', 'Enregistrement en cours…');

        return api.postJson('/config/llm/providers', {
            id: inputEl('provider-id').value || null,
            name: inputEl('provider-name').value,
            driver: driverSelect.value,
            api_endpoint: inputEl('provider-endpoint').value,
            // Empty = keep the stored key (server contract) — the case the
            // « Laisser vide pour conserver la clé actuelle. » hint covers.
            api_key: inputEl('provider-api-key').value
        }).then(function (res) {
            var data = res.data;
            if (!data?.success) {
                showAlert('danger', escapeHtml(data?.error || 'Erreur.'));
                return undefined;
            }

            var providerId = String(data.provider_id);
            inputEl('provider-id').value = providerId;
            return testConnection(providerId);
        });
    });
})();
