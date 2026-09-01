/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// RGPD configuration page (core/View/templates/config/rgpd.html.twig):
// generation-mode radios (default / custom / AI), the AI prompt + generate
// flow with its elapsed-seconds ticker, the custom save button grafted
// onto the shared rich-text modal, and the reset-to-default action.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/rgpd-config.test.js).
//
// Fetches ride the site-wide ScoutMagicApi envelope ({ok, status, data}) —
// it replaces the local parseJsonResponse() debug helper: a non-JSON body
// (e.g. an HTML error page) now comes back as data:null instead of an
// opaque parse exception.
(function () {
    var aiPromptCard = document.getElementById('ai-prompt-card');
    var generateBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('generate-btn'));
    if (!aiPromptCard || !generateBtn) return;

    var api = window.ScoutMagicApi;
    var escapeHtml = api.escapeHtml;

    var modeRadios = /** @type {NodeListOf<HTMLInputElement>} */ (document.querySelectorAll('input[name="mode"]'));
    var resetBtn = document.getElementById('reset-btn');
    var editBtn = document.getElementById('edit-content-btn');
    var preview = document.querySelector('.rich-text-field-preview[data-key="rgpd.text"]');
    var aiPromptTextarea = /** @type {HTMLTextAreaElement} */ (document.getElementById('ai-prompt'));
    var generateStatus = document.getElementById('generate-status');

    var generationTimer = null;

    function getMode() {
        var checked = /** @type {HTMLInputElement|null} */ (document.querySelector('input[name="mode"]:checked'));
        return checked ? checked.value : 'default';
    }

    function updateUI() {
        var mode = getMode();
        aiPromptCard.style.display = mode === 'ai' ? 'block' : 'none';
        editBtn.style.display = (mode === 'custom' || mode === 'ai') ? 'inline-block' : 'none';
    }

    /**
     * The generation error line for one envelope result — the server's own
     * error when it answered JSON, a generic invalid-response line when it
     * did not (postJson already folded network errors and non-JSON bodies
     * into data:null).
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function generateErrorHtml(res) {
        var message = res.data && res.data.error
            ? escapeHtml(res.data.error)
            : 'Erreur : réponse serveur invalide.';
        return '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ' + message + '</span>';
    }

    /** @param {string} baseMessage */
    function startGenerationTimer(baseMessage) {
        var seconds = 0;
        if (generationTimer !== null) {
            clearInterval(generationTimer);
        }
        generateStatus.textContent = baseMessage + ' (' + seconds + 's)';
        generationTimer = setInterval(function () {
            seconds += 1;
            generateStatus.textContent = baseMessage + ' (' + seconds + 's)';
        }, 1000);
    }

    function stopGenerationTimer() {
        if (generationTimer !== null) {
            clearInterval(generationTimer);
            generationTimer = null;
        }
    }

    /**
     * @param {string} mode
     * @param {string} content
     * @param {string} prompt
     * @param {(success: boolean, result?: any) => void} [callback]
     */
    function saveMode(mode, content, prompt, callback) {
        api.postJson('/config/rgpd/save', { mode: mode, content: content, prompt: prompt })
            .then(function (res) {
                if (callback) callback(!!(res.data && res.data.success), res.data);
            });
    }

    /** How often the page asks whether the background run has finished. */
    var POLL_INTERVAL_MS = 3000;

    /**
     * Waits for the queued generation to finish, then shows the outcome.
     *
     * The work happens in a scheduled task now — the document takes
     * minutes to write and the provider timeout was what an administrator
     * used to see instead (Core\View\RgpdGenerationRunner) — so the
     * result arrives by asking rather than by returning. The state lives
     * in the settings table, not in this page: reloading mid-run picks the
     * ticker back up rather than losing the run.
     *
     * @param {string} successMessage
     */
    function pollGeneration(successMessage) {
        return api.getJson('/config/rgpd/generate/status').then(function (res) {
            var data = res.data;

            if (!data || data.success !== true) {
                stopGenerationTimer();
                generateStatus.innerHTML = generateErrorHtml(res);
                generateBtn.disabled = false;
                return undefined;
            }

            if (data.running === true) {
                return new Promise(function (resolve) {
                    setTimeout(function () { resolve(pollGeneration(successMessage)); }, POLL_INTERVAL_MS);
                });
            }

            stopGenerationTimer();
            generateBtn.disabled = false;

            if (data.status === 'done') {
                if (typeof data.content === 'string') {
                    preview.innerHTML = data.content;
                }
                generateStatus.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> ' + successMessage + '</span>';
                setTimeout(function () { generateStatus.textContent = ''; }, 5000);
                return undefined;
            }

            generateStatus.innerHTML = generateErrorHtml({ ok: false, status: 0, data: data });

            return undefined;
        });
    }

    /**
     * The generate call shared by the automatic (mode switch) and manual
     * (button) paths — same request, same status line, different base
     * message and success wording.
     *
     * The POST only QUEUES the run: what comes back is « c'est parti »,
     * and the outcome is polled for.
     *
     * @param {string} prompt
     * @param {string} tickerMessage
     * @param {string} successMessage
     */
    function runGeneration(prompt, tickerMessage, successMessage) {
        startGenerationTimer(tickerMessage);
        generateBtn.disabled = true;

        return api.postJson('/config/rgpd/generate', { prompt: prompt })
            .then(function (res) {
                if (!res.data || res.data.success !== true) {
                    stopGenerationTimer();
                    generateStatus.innerHTML = generateErrorHtml(res);
                    generateBtn.disabled = false;

                    return undefined;
                }

                return pollGeneration(successMessage);
            });
    }

    // A run queued before this page was opened — or before it was
    // reloaded — is still a run: pick its ticker back up rather than
    // showing an idle button beside a job in flight.
    //
    // Read off the server-rendered attribute rather than fetched, so the
    // page is right on its first paint and so opening this page costs no
    // request of its own.
    if (aiPromptCard.dataset.generationRunning === '1') {
        generateBtn.disabled = true;
        startGenerationTimer('Génération en cours');
        pollGeneration('Contenu généré et enregistré.');
    }

    // Auto-save on mode change
    modeRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            var mode = getMode();
            updateUI();

            if (mode === 'default') {
                // Reset to default content, then persist mode + content
                api.postJson('/config/rgpd/reset', {}).then(function (res) {
                    if (res.data && res.data.success) {
                        preview.innerHTML = res.data.content;
                        saveMode('default', res.data.content, '', function () {
                            // Silent save
                        });
                    }
                });
            } else if (mode === 'ai') {
                // Reset to default first, then auto-generate if prompt exists
                api.postJson('/config/rgpd/reset', {}).then(function (res) {
                    if (res.data && res.data.success) {
                        preview.innerHTML = res.data.content;

                        // Auto-generate if prompt exists
                        var prompt = aiPromptTextarea.value.trim();
                        if (prompt) {
                            runGeneration(prompt, 'Génération automatique en cours…', 'Contenu généré automatiquement');
                        }
                    }
                });
            } else if (mode === 'custom') {
                // Keep current version, save mode
                saveMode(mode, preview.innerHTML, '', function () {
                    // Silent save
                });
            }
        });
    });

    updateUI();

    // Override the rich-text-field edit button: open the shared modal but
    // do NOT save via the default URL — we handle save ourselves below.
    editBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        var modalEl = document.getElementById('richTextEditorModal');
        var editorContent = document.getElementById('richTextEditorContent');
        if (!modalEl || !editorContent) return;
        editorContent.innerHTML = preview.innerHTML;

        // Hide the default Save button and show our custom one
        var defaultSaveBtn = document.getElementById('richTextEditorSave');
        var customBtn = document.getElementById('rgpd-modal-save');
        if (defaultSaveBtn) defaultSaveBtn.style.display = 'none';
        if (customBtn) customBtn.style.display = 'inline-block';

        var modal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        if (modal) modal.show();
    });

    // Custom save button for RGPD modal
    var customSaveBtn = document.createElement('button');
    customSaveBtn.id = 'rgpd-modal-save';
    customSaveBtn.className = 'btn btn-primary';
    customSaveBtn.textContent = 'Enregistrer';
    customSaveBtn.style.display = 'none';
    customSaveBtn.addEventListener('click', function () {
        var modalEl = document.getElementById('richTextEditorModal');
        var editorContent = document.getElementById('richTextEditorContent');
        var newContent = editorContent.innerHTML;
        preview.innerHTML = newContent;
        var modal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        if (modal) modal.hide();

        // Auto-save the new content
        var mode = getMode();
        var prompt = aiPromptTextarea.value;
        saveMode(mode, newContent, prompt, function () {
            // Silent save - content already updated in preview
        });

        // Restore default Save button
        var defaultSaveBtn = document.getElementById('richTextEditorSave');
        if (defaultSaveBtn) defaultSaveBtn.style.display = 'inline-block';
        customSaveBtn.style.display = 'none';
    });

    // Add custom save button to modal footer
    var modalFooter = document.querySelector('#richTextEditorModal .modal-footer');
    if (modalFooter) {
        var defaultSaveBtn = document.getElementById('richTextEditorSave');
        if (defaultSaveBtn && defaultSaveBtn.parentNode) {
            defaultSaveBtn.parentNode.insertBefore(customSaveBtn, defaultSaveBtn);
        }
    }

    // Manual generate button (when in AI mode)
    generateBtn.addEventListener('click', function () {
        runGeneration(aiPromptTextarea.value, 'Génération en cours…', 'Contenu généré et enregistré');
    });

    // Reset button - resets to default content and persists it for the current mode
    resetBtn.addEventListener('click', async function () {
        // Destructive: whatever was written or generated is replaced by the
        // default text, so this keeps the danger variant.
        var confirmed = await window.ScoutMagicConfirm.ask({
            message: 'Réinitialiser au contenu par défaut ?',
            confirmLabel: 'Réinitialiser'
        });
        if (!confirmed) return;

        api.postJson('/config/rgpd/reset', {}).then(function (res) {
            if (res.data && res.data.success) {
                preview.innerHTML = res.data.content;
                saveMode(getMode(), res.data.content, aiPromptTextarea.value, function () {
                    // Silent save
                });
            }
        });
    });
})();
