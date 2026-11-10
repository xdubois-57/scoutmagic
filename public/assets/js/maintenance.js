/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Configuration > Maintenance — "Sauvegarde automatique" frequency select:
// auto-saves on change (same pattern as the banner module's per-item
// visibility select's JS).
(function () {
    var select = /** @type {HTMLSelectElement} */ (document.getElementById('auto-backup-frequency'));
    if (!select) return;

    select.addEventListener('change', function () {
        void window.ScoutMagicApi.postJson('/config/maintenance/backup/auto-frequency', { frequency: select.value })
            .then(function (res) {
                if (res.data?.success) {
                    window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                }
            });
    });
})();

// Configuration > Maintenance > Sauvegarde manuelle — the one form that
// starts a background backup (issue #619, IT-04): four scopes, one button.
// It submits, then polls GET /api/maintenance/backup-status/{id} via
// ScoutMagicApi.poll until the generation finishes or fails.
//
// The portable archive keeps its own route — it is the one that packages
// the site's keys, and the server checks and journals it on its own — so
// the request is the parameter: which route, and what to post to it.
(function () {
    /**
     * @param {string} prefix element id prefix: `{prefix}-form`, `-submit`, `-progress`, `-error`
     * @param {(form: HTMLFormElement) => ({endpoint: string, payload: Object}|null)} buildRequest
     *        null to abort (invalid input)
     */
    function wireBackupForm(prefix, buildRequest) {
        var form = /** @type {HTMLFormElement} */ (document.getElementById(prefix + '-form'));
        if (!form) return;

        var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById(prefix + '-submit'));
        var progressEl = document.getElementById(prefix + '-progress');
        var errorEl = document.getElementById(prefix + '-error');
        var polling = window.ScoutMagicApi.pollSlot();

        /** @param {string} message */
        function showError(message) {
            polling.stop();
            submitBtn.disabled = false;
            progressEl.classList.add('d-none');
            errorEl.textContent = message;
            errorEl.classList.remove('d-none');
        }

        /** @param {string|number} backupId */
        function pollStatus(backupId) {
            polling.start(window.ScoutMagicApi.poll(function () {
                return window.ScoutMagicApi.getJson('/api/maintenance/backup-status/' + backupId).then(function (res) {
                    if (!res.data) {
                        // Transient network hiccup — keep polling, the next
                        // tick will likely succeed.
                        return undefined;
                    }
                    if (res.data.status === 'completed') {
                        window.location.reload();
                        return false;
                    }
                    if (res.data.status === 'failed') {
                        showError(res.data.error_message || 'La génération de la sauvegarde a échoué.');
                        return false;
                    }
                    // pending / in_progress: keep polling.
                    return undefined;
                });
            }, { intervalMs: 3000 }));
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorEl.classList.add('d-none');

            var request = buildRequest(form);
            if (!request) return;

            submitBtn.disabled = true;
            progressEl.classList.remove('d-none');

            void window.ScoutMagicApi.postJson(request.endpoint, request.payload).then(function (res) {
                if (!res.data) {
                    showError('Erreur réseau.');
                    return;
                }
                if (!res.data.success) {
                    showError(res.data.error || 'Erreur lors du lancement de la sauvegarde.');
                    return;
                }
                pollStatus(res.data.backup_id);
            });
        });
    }

    // No password field (issue #619, IT-03): the site generates one per
    // archive, keeps it, and reveals it at download time.
    wireBackupForm('manual-backup', function (form) {
        var scope = /** @type {HTMLInputElement} */ (form.querySelector('input[name="scope"]:checked'));
        if (!scope) return null;
        if (scope.value === 'portable') {
            return { endpoint: '/config/maintenance/backup/portable', payload: {} };
        }

        return { endpoint: '/config/maintenance/backup/full', payload: { scope: scope.value } };
    });
})();

// "Mise à jour" section — install form(s): submit, then poll
// GET /api/maintenance/update-status/{id} (same ScoutMagicApi.poll shape as
// the full-backup polling above) until installation completes, fails, or is
// rolled back. Wires every matching form found (the always-rendered "Une
// nouvelle version est disponible" form when the webhook's last cached check
// found one, and/or the "Vérifier maintenant" dialog's own form below) since
// both POST to the same endpoint and need identical polling.
(function () {
    /** @param {HTMLElement|null} form */
    function wireInstallForm(form) {
        if (!form) return;

        var submitBtn = /** @type {HTMLButtonElement} */ (form.querySelector('button[type="submit"]'));
        var progressEl = form.querySelector('[id$="-progress"]') || document.getElementById('update-install-progress');
        var errorEl = form.querySelector('[id$="-error"]') || document.getElementById('update-install-error');
        // Filled in while the update sits in `migrating`: that step is the one
        // that can run for minutes, and each poll now drives a slice of it
        // server-side, so the spinner alone would hide real progress.
        var detailEl = progressEl ? progressEl.querySelector('[data-role="migration-detail"]') : null;
        var polling = window.ScoutMagicApi.pollSlot();

        /** @param {unknown} fraction */
        function showMigrationProgress(fraction) {
            if (!detailEl) return;
            // Number.isFinite rather than the global: they differ only for
            // non-numbers, which the typeof guard has already excluded.
            if (typeof fraction !== 'number' || !Number.isFinite(fraction)) {
                detailEl.textContent = '';
                return;
            }
            var percent = Math.max(0, Math.min(100, Math.round(fraction * 100)));
            detailEl.textContent = ' Migration du schéma : ' + percent + ' %.';
        }

        /** @param {string} message */
        function showError(message) {
            polling.stop();
            if (submitBtn) submitBtn.disabled = false;
            if (progressEl) progressEl.classList.add('d-none');
            if (errorEl) {
                errorEl.textContent = message;
                errorEl.classList.remove('d-none');
            }
        }

        /** @param {string|number} historyId */
        function pollStatus(historyId) {
            polling.start(window.ScoutMagicApi.poll(function () {
                return window.ScoutMagicApi.getJson('/api/maintenance/update-status/' + historyId).then(function (res) {
                    // A 200 that is not JSON, at this URL, means one thing:
                    // the schema migration is running, and public/index.php
                    // short-circuits every request before routing to serve
                    // the progress page instead. This poller would otherwise
                    // spin here for the whole window, on the one address that
                    // cannot answer it — observed on 2026-08-30, an admin
                    // watching an install with the tab open and doing
                    // nothing. Reload, so the tab lands on that progress
                    // page, whose own script drives the migration and
                    // reloads back here when it is done. (getJson answers
                    // status 0 on a genuine network failure, so this cannot
                    // be confused with one, and the reload ends this poller
                    // rather than looping it.)
                    if (res.data === null && res.status === 200) {
                        polling.stop();
                        window.location.reload();
                        return false;
                    }
                    if (!res.data) {
                        // Transient network hiccup — keep polling.
                        return undefined;
                    }
                    // `skipped` is terminal too (issue #622): a newer version
                    // replaced this install before it started, and the
                    // reloaded page shows the history saying so.
                    if (res.data.status === 'completed' || res.data.status === 'skipped') {
                        window.location.reload();
                        return false;
                    }
                    if (res.data.status === 'failed' || res.data.status === 'rolled_back') {
                        showError(res.data.error_message || 'L\'installation de la mise à jour a échoué.');
                        return false;
                    }
                    // pending / backing_up / downloading / installing / migrating: keep polling.
                    showMigrationProgress(res.data.status === 'migrating' ? res.data.migration_progress : null);
                    return undefined;
                });
            }, { intervalMs: 3000 }));
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (errorEl) errorEl.classList.add('d-none');
            if (submitBtn) submitBtn.disabled = true;
            if (progressEl) progressEl.classList.remove('d-none');

            void window.ScoutMagicApi.postJson('/config/maintenance/update/install', {})
                .then(function (res) {
                    if (!res.data) {
                        showError('Erreur réseau.');
                        return;
                    }
                    if (!res.data.success) {
                        showError(res.data.error || 'Erreur lors du lancement de la mise à jour.');
                        return;
                    }
                    pollStatus(res.data.history_id);
                });
        });
    }

    wireInstallForm(document.getElementById('update-install-form'));
    wireInstallForm(document.getElementById('update-check-now-install-form'));
})();

// "Mise à jour" section — "Vérifier maintenant" button: on-demand check
// (POST /config/maintenance/update/check-now), since detection is
// otherwise purely webhook-driven with no polling in between. Populates
// #update-check-now-dialog with the result; its own install form is wired
// above alongside the always-rendered one.
(function () {
    var btn = /** @type {HTMLButtonElement} */ (document.getElementById('update-check-now'));
    if (!btn) return;

    var progressEl = document.getElementById('update-check-now-progress');
    var errorEl = document.getElementById('update-check-now-error');
    var dialog = document.getElementById('update-check-now-dialog');
    var messageEl = document.getElementById('update-check-now-message');
    var notesEl = document.getElementById('update-check-now-notes');
    var dismissBtn = document.getElementById('update-check-now-dismiss');

    if (dismissBtn) {
        dismissBtn.addEventListener('click', function () {
            dialog.classList.add('d-none');
        });
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        errorEl.classList.add('d-none');
        dialog.classList.add('d-none');
        progressEl.classList.remove('d-none');

        void window.ScoutMagicApi.postJson('/config/maintenance/update/check-now', {})
            .then(function (res) {
                btn.disabled = false;
                progressEl.classList.add('d-none');

                if (!res.data) {
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.classList.remove('d-none');
                    return;
                }
                var data = res.data;
                if (!data.success) {
                    errorEl.textContent = data.error || 'Erreur lors de la vérification.';
                    errorEl.classList.remove('d-none');
                    return;
                }

                if (!data.update_available) {
                    errorEl.classList.add('d-none');
                    dialog.classList.add('d-none');
                    window.ScoutMagicToast.show('Le site est déjà à jour.', { variant: 'success' });
                    return;
                }

                var message = 'Nouvelle version disponible : ' + data.version;
                // The server proposes the FIRST pending major rather than
                // the newest release, so several majors are installed one
                // at a time (each with its own safety backup and its own
                // migrations). Say so, or the admin reads the older number
                // as a stale check.
                if (data.latest_version && data.latest_version !== data.version) {
                    message += ' — étape intermédiaire vers la version ' + data.latest_version
                        + ', les versions majeures s\'installent une par une.';
                }
                messageEl.textContent = message;
                // notes_html is server-rendered from Markdown by
                // Core\View\MarkdownRenderer (already HTML-escaped there),
                // never built from data.notes client-side.
                notesEl.innerHTML = data.notes_html || '';
                dialog.classList.remove('d-none');
            });
    });
})();

// "Mises à jour automatiques" section — toggle/level/schedule are all
// saved together on one "Enregistrer" click (not autosaved per-field, unlike
// the backup frequency select above), plus the webhook secret's
// generate/regenerate (shown in cleartext exactly once) and the semver
// explainer/major-version-warning show/hide.
(function () {
    var enabledCheckbox = /** @type {HTMLInputElement} */ (document.getElementById('auto-update-enabled'));
    if (!enabledCheckbox) return;

    var detailsEl = document.getElementById('auto-update-details');
    enabledCheckbox.addEventListener('change', function () {
        detailsEl.classList.toggle('d-none', !enabledCheckbox.checked);
    });

    var majorWarning = document.getElementById('auto-update-major-warning');
    var devWarning = document.getElementById('auto-update-dev-warning');
    var scheduleSection = document.getElementById('auto-update-schedule-section');
    var branchSection = document.getElementById('auto-update-branch-section');
    var webhookSection = document.getElementById('auto-update-webhook-section');
    var levelRadios = /** @type {NodeListOf<HTMLInputElement>} */ (document.querySelectorAll('input[name="auto-update-level"]'));
    levelRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked) return;
            majorWarning.classList.toggle('d-none', radio.value !== 'major');
            devWarning.classList.toggle('d-none', radio.value !== 'dev');
            scheduleSection.classList.toggle('d-none', radio.value === 'dev');
            branchSection.classList.toggle('d-none', radio.value !== 'dev');
            webhookSection.classList.toggle('d-none', radio.value !== 'dev');
        });
    });

    var semverToggle = document.getElementById('auto-update-semver-toggle');
    var semverExplainer = document.getElementById('auto-update-semver-explainer');
    if (semverToggle && semverExplainer) {
        semverToggle.addEventListener('click', function (e) {
            e.preventDefault();
            semverExplainer.classList.toggle('d-none');
        });
    }

    var saveBtn = document.getElementById('auto-update-save');
    var errorEl = document.getElementById('auto-update-error');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var levelRadio = /** @type {HTMLInputElement} */ (document.querySelector('input[name="auto-update-level"]:checked'));
            errorEl.classList.add('d-none');

            void window.ScoutMagicApi.postJson('/config/maintenance/auto-update/save', {
                enabled: enabledCheckbox.checked,
                level: levelRadio ? levelRadio.value : 'patch',
                day: /** @type {HTMLSelectElement} */ (document.getElementById('auto-update-day')).value,
                time: /** @type {HTMLInputElement} */ (document.getElementById('auto-update-time')).value,
                branch: /** @type {HTMLInputElement} */ (document.getElementById('auto-update-branch')).value
            })
                .then(function (res) {
                    if (!res.data) {
                        errorEl.textContent = 'Erreur réseau.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    if (!res.data.success) {
                        errorEl.textContent = res.data.error || 'Erreur lors de l\'enregistrement.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    window.ScoutMagicToast.show('Enregistré.', { variant: 'success' });
                });
        });
    }

    var webhookBtn = /** @type {HTMLButtonElement} */ (document.getElementById('webhook-generate-secret'));
    if (webhookBtn) {
        webhookBtn.addEventListener('click', function () {
            webhookBtn.disabled = true;
            void window.ScoutMagicApi.postJson('/api/maintenance/webhook-secret', {})
                .then(function (res) {
                    webhookBtn.disabled = false;
                    if (!res.data) {
                        window.ScoutMagicToast.show('Erreur réseau.', { variant: 'error' });
                        return;
                    }
                    if (!res.data.success) {
                        window.ScoutMagicToast.show(res.data.error || 'Erreur lors de la génération du secret.', { variant: 'error' });
                        return;
                    }
                    document.getElementById('webhook-secret-value').textContent = res.data.secret;
                    document.getElementById('webhook-secret-display').classList.remove('d-none');
                    document.getElementById('webhook-setup-instructions').classList.add('d-none');

                    var badge = document.getElementById('webhook-status-badge');
                    badge.textContent = '✓ Configuré';
                    badge.classList.remove('text-bg-secondary');
                    badge.classList.add('text-bg-success');

                    document.getElementById('webhook-generate-secret-label').textContent = 'Régénérer le secret';
                    webhookBtn.classList.remove('btn-outline-primary');
                    webhookBtn.classList.add('btn-outline-secondary');
                    var icon = webhookBtn.querySelector('i');
                    if (icon) {
                        icon.classList.remove('bi-key');
                        icon.classList.add('bi-arrow-repeat');
                    }
                });
        });
    }
})();

// "Réinitialisation" section — three danger actions. The typed keyword is
// only a client-side UX gate (enabling the submit button); the real check
// always happens server-side (Core\Http\Controller\MaintenanceController).
(function () {
    /**
     * @param {string} inputId
     * @param {string} expected
     * @param {(() => boolean)} [extraCondition]
     * @returns {(() => void)|null}
     */
    function wireKeywordGate(inputId, expected, extraCondition) {
        var input = /** @type {HTMLInputElement} */ (document.getElementById(inputId));
        if (!input) return null;
        var submitBtn = /** @type {HTMLButtonElement} */ (input.closest('form').querySelector('button[type="submit"]'));
        function update() {
            var ok = input.value === expected && (!extraCondition || extraCondition());
            submitBtn.disabled = !ok;
        }
        input.addEventListener('input', update);
        return update;
    }

    // Generic ScoutMagicApi.poll wrapper for
    // GET /api/maintenance/reset-status/{id}. onDone/onFailed receive the
    // error message (may be null); onNotFound is only meaningfully different
    // for full reset (see below), where a 404 means "the operation wiped its
    // own tracking row — that's success".
    /**
     * @param {string|number} actionId
     * @param {() => void} onDone
     * @param {(message: string|null) => void} onFailed
     * @param {() => void} onNotFound
     * @returns {void}
     */
    function pollResetStatus(actionId, onDone, onFailed, onNotFound) {
        window.ScoutMagicApi.poll(function () {
            return window.ScoutMagicApi.getJson('/api/maintenance/reset-status/' + actionId).then(function (res) {
                if (res.status === 404) {
                    onNotFound();
                    return false;
                }
                if (!res.data) {
                    // Transient network hiccup — keep polling.
                    return undefined;
                }
                if (res.data.status === 'done') {
                    onDone();
                    return false;
                }
                if (res.data.status === 'failed' || res.data.status === 'canceled') {
                    onFailed(res.data.error_message);
                    return false;
                }
                // pending / processing: keep polling.
                return undefined;
            });
        }, { intervalMs: 3000 });
    }

    // --- Paramètres par défaut ---
    var resetSettingsUpdate = wireKeywordGate('reset-settings-keyword', 'REINITIALISER');
    var resetSettingsForm = document.getElementById('reset-settings-form');
    if (resetSettingsForm) {
        resetSettingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('reset-settings-submit'));
            var progressEl = document.getElementById('reset-settings-progress');
            var errorEl = document.getElementById('reset-settings-error');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;
            progressEl.classList.remove('d-none');

            void window.ScoutMagicApi.postJson('/config/maintenance/reset/settings', {
                confirm_keyword: /** @type {HTMLInputElement} */ (document.getElementById('reset-settings-keyword')).value
            })
                .then(function (res) {
                    if (!res.data) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = 'Erreur réseau.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    if (!res.data.success) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = res.data.error || 'Erreur lors du lancement de la réinitialisation.';
                        errorEl.classList.remove('d-none');
                        if (resetSettingsUpdate) resetSettingsUpdate();
                        return;
                    }
                    pollResetStatus(
                        res.data.action_id,
                        function () { window.location.reload(); },
                        function (message) {
                            progressEl.classList.add('d-none');
                            errorEl.textContent = message || 'La réinitialisation a échoué.';
                            errorEl.classList.remove('d-none');
                        },
                        function () { window.location.reload(); }
                    );
                });
        });
    }

    // --- Réinitialisation complète ---
    var fullResetCheckbox = /** @type {HTMLInputElement} */ (document.getElementById('full-reset-checkbox'));
    // Present only where the host can encrypt (issue #619, IT-03b): the
    // safety copy's password must be shown, then confirmed as noted, before
    // the reset can be submitted — the reset erases it.
    var fullResetNoted = /** @type {HTMLInputElement|null} */ (document.getElementById('full-reset-password-noted'));
    var fullResetUpdate = wireKeywordGate('full-reset-keyword', 'EFFACER', function () {
        return fullResetCheckbox?.checked && fullResetNoted?.checked !== false;
    });
    if (fullResetCheckbox && fullResetUpdate) {
        fullResetCheckbox.addEventListener('change', fullResetUpdate);
    }
    wireFullResetPassword(fullResetNoted, fullResetUpdate);

    /**
     * The reveal button and the « noté » box of the full reset's safety-copy
     * password. Its own function so the section above stays readable.
     *
     * @param {HTMLInputElement|null} notedBox
     * @param {(() => void)|null} update
     * @returns {void}
     */
    function wireFullResetPassword(notedBox, update) {
        if (!notedBox) return;
        if (update) notedBox.addEventListener('change', update);
        var revealButton = /** @type {HTMLButtonElement|null} */ (document.getElementById('full-reset-password-reveal'));
        var revealOutput = document.getElementById('full-reset-password');
        if (!revealButton || !revealOutput) return;
        revealButton.addEventListener('click', function () {
            revealButton.disabled = true;
            void window.ScoutMagicApi.postJson('/config/maintenance/reset/full/password', {}).then(function (res) {
                var data = res.data || {};
                revealButton.disabled = false;
                revealOutput.textContent = '';
                if (!data.success || !data.password) {
                    revealOutput.textContent = data.error || 'Le mot de passe n\'a pas pu être lu.';
                } else {
                    var value = document.createElement('code');
                    value.className = 'user-select-all';
                    value.textContent = data.password;
                    revealOutput.append('Mot de passe : ', value);
                    notedBox.disabled = false;
                }
                revealOutput.classList.remove('d-none');
            });
        });
    }

    var fullResetForm = document.getElementById('full-reset-form');
    if (fullResetForm) {
        fullResetForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: 'Cette action est irréversible : toutes les données du site seront définitivement supprimées. Continuer ?',
                confirmLabel: 'Tout supprimer'
            });
            if (!confirmed) {
                return;
            }
            var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('full-reset-submit'));
            var progressEl = document.getElementById('full-reset-progress');
            var errorEl = document.getElementById('full-reset-error');
            errorEl.classList.add('d-none');
            submitBtn.disabled = true;
            progressEl.classList.remove('d-none');

            void window.ScoutMagicApi.postJson('/config/maintenance/reset/full', {
                confirm_keyword: /** @type {HTMLInputElement} */ (document.getElementById('full-reset-keyword')).value,
                confirm_checkbox: true,
                password_noted: fullResetNoted?.checked === true
            })
                .then(function (res) {
                    if (!res.data) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = 'Erreur réseau.';
                        errorEl.classList.remove('d-none');
                        return;
                    }
                    if (!res.data.success) {
                        progressEl.classList.add('d-none');
                        errorEl.textContent = res.data.error || 'Erreur lors du lancement de la réinitialisation.';
                        errorEl.classList.remove('d-none');
                        if (fullResetUpdate) fullResetUpdate();
                        return;
                    }
                    // A 404 here means the operation wiped scheduled_actions
                    // along with everything else — that IS success for a
                    // full reset, not an error, so onNotFound also redirects.
                    pollResetStatus(
                        res.data.action_id,
                        function () { window.location.href = '/'; },
                        function (message) {
                            progressEl.classList.add('d-none');
                            errorEl.textContent = message || 'La réinitialisation a échoué.';
                            errorEl.classList.remove('d-none');
                        },
                        function () { window.location.href = '/'; }
                    );
                });
        });
    }

    // --- Restaurer un backup ---
    wireKeywordGate('restore-backup-keyword', 'RESTAURER');
    var sourceServerRadio = document.getElementById('restore-source-server');
    var sourceUploadRadio = /** @type {HTMLInputElement} */ (document.getElementById('restore-source-upload'));
    var serverPicker = document.getElementById('restore-server-picker');
    var uploadPicker = document.getElementById('restore-upload-picker');
    var passwordField = document.getElementById('restore-password-field');
    var backupSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('restore-backup-id'));
    // The password field: always for an uploaded file, and for an archive
    // of this server only when its option says the site never kept its
    // password — a full backup from before IT-03 (issue #619, IT-06).
    function toggleRestoreSource() {
        var isUpload = !!sourceUploadRadio?.checked;
        var chosen = backupSelect?.selectedOptions[0];
        var needsPassword = isUpload || chosen?.dataset.needsPassword === '1';
        if (serverPicker) serverPicker.classList.toggle('d-none', isUpload);
        if (uploadPicker) uploadPicker.classList.toggle('d-none', !isUpload);
        if (passwordField) passwordField.classList.toggle('d-none', !needsPassword);
        var configNote = document.getElementById('restore-config-note');
        if (configNote) {
            var note = !isUpload ? (chosen?.dataset.configNote || '') : '';
            configNote.textContent = note;
            configNote.classList.toggle('d-none', note === '');
        }
    }
    if (sourceServerRadio) sourceServerRadio.addEventListener('change', toggleRestoreSource);
    if (sourceUploadRadio) sourceUploadRadio.addEventListener('change', toggleRestoreSource);
    if (backupSelect) backupSelect.addEventListener('change', toggleRestoreSource);
    toggleRestoreSource();

    var restoreForm = /** @type {HTMLFormElement | null} */ (document.getElementById('restore-backup-form'));
    if (restoreForm) {
        // The dialog is asynchronous, so the browser's own submission can
        // never be allowed to continue underneath it: every submit is
        // stopped here, and the confirmed one is re-sent by hand below.
        restoreForm.addEventListener('submit', function (e) {
            e.preventDefault();
            // From this server with nothing to choose: a disabled select is
            // left out of the POST, and the controller would answer with an
            // error after a confirmation that promised a restore.
            var serverSelect = /** @type {HTMLSelectElement|null} */ (document.getElementById('restore-backup-id'));
            if (!sourceUploadRadio?.checked && (!serverSelect || serverSelect.disabled || serverSelect.value === '')) {
                var noBackupEl = document.getElementById('restore-backup-error');
                if (noBackupEl) {
                    noBackupEl.textContent = 'Aucune sauvegarde de ce serveur à restaurer : choisissez un fichier.';
                    noBackupEl.classList.remove('d-none');
                }
                return;
            }
            void window.ScoutMagicConfirm.ask({
                message: 'Cette action va remplacer les données actuelles par celles de la sauvegarde sélectionnée. Continuer ?',
                confirmLabel: 'Restaurer'
            }).then(function (confirmed) {
                if (confirmed) {
                    startRestore();
                }
            });
        });

        /**
         * The confirmed restore (issue #753): send the archive — as chunks
         * when it is large, with the share received shown —, then POST the
         * form from here and read the answer, so that a wrong password or
         * a restore already running is said under the form at once rather
         * than after a reload. Only a restore actually queued is polled.
         *
         * @returns {void}
         */
        function startRestore() {
            var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('restore-backup-submit'));
            var errorEl = document.getElementById('restore-backup-error');
            if (errorEl) errorEl.classList.add('d-none');
            submitBtn.disabled = true;

            // A large uploaded archive can't ride the classic multipart POST
            // (the document-root-wide post_max_size is small now — audit M2):
            // send it first as ~8 MB chunks to the dedicated superadmin
            // route, then POST the form referencing the assembled file by
            // upload_id, with the file input cleared. Small archives keep
            // the plain multipart POST, which has no share to show.
            var chunker = window.ScoutMagicChunkedUpload;
            var fileInput = /** @type {HTMLInputElement} */ (document.getElementById('restore-backup-file'));
            var uploadIdInput = /** @type {HTMLInputElement} */ (document.getElementById('restore-upload-id'));
            var isUpload = !!sourceUploadRadio?.checked;
            var chunkFile = isUpload && fileInput?.files?.[0] ? fileInput.files[0] : null;
            if (chunker && uploadIdInput && chunkFile?.size > chunker.CHUNK_THRESHOLD) {
                showRestoreStage('upload', 0);
                chunker.uploadInChunks(chunkFile, '/config/maintenance/restore-upload-chunk', {
                    csrfToken: window.ScoutMagicApi.csrfToken(),
                    onProgress: function (sentBytes, totalBytes) {
                        showRestoreStage('upload', totalBytes > 0 ? (sentBytes * 100) / totalBytes : 0);
                    }
                }).then(function (result) {
                    uploadIdInput.value = result.uploadId;
                    fileInput.value = '';
                    submitRestore();
                }).catch(function (err) {
                    restoreFailed(err?.message || 'Le téléversement a échoué.');
                });
                return;
            }

            showRestoreStage(isUpload ? 'upload' : 'verify', null);
            submitRestore();
        }

        /** @returns {void} */
        function submitRestore() {
            if (sourceUploadRadio?.checked) {
                showRestoreStage('verify', null);
            }
            fetch(restoreForm.action, {
                method: 'POST',
                body: new FormData(restoreForm),
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                return response.json().catch(function () { return null; });
            }).then(function (data) {
                if (!data?.success) {
                    restoreFailed(data?.error || 'La restauration n\'a pas pu être lancée.');
                    return;
                }
                window.history.replaceState(null, '', '?restore_id=' + data.action_id);
                followRestore(data.action_id);
            }, function () {
                restoreFailed('La restauration n\'a pas pu être lancée : vérifiez la connexion, puis réessayez.');
            });
        }

        /**
         * Nothing was queued: the archive the server refused is already
         * deleted, so the form is reset to choose it again.
         *
         * @param {string} message
         * @returns {void}
         */
        function restoreFailed(message) {
            var progressEl = document.getElementById('restore-backup-progress');
            var errorEl = document.getElementById('restore-backup-error');
            var uploadIdInput = /** @type {HTMLInputElement|null} */ (document.getElementById('restore-upload-id'));
            var submitBtn = /** @type {HTMLButtonElement} */ (document.getElementById('restore-backup-submit'));
            if (progressEl) progressEl.classList.add('d-none');
            if (uploadIdInput) uploadIdInput.value = '';
            submitBtn.disabled = false;
            if (errorEl) {
                errorEl.textContent = message;
                errorEl.classList.remove('d-none');
            }
        }
    }

    /**
     * What the progress line says: sending (with its share when there is
     * one to measure), checking the archive, or restoring — never
     * « Restauration en cours… » before anything is being restored.
     *
     * @param {'upload'|'verify'|'restore'} stage
     * @param {number|null} percent the share sent, null when unmeasured
     * @returns {void}
     */
    function showRestoreStage(stage, percent) {
        var progressEl = document.getElementById('restore-backup-progress');
        var label = document.getElementById('restore-backup-progress-label');
        var bar = document.getElementById('restore-backup-upload-bar');
        if (!progressEl) return;
        progressEl.classList.remove('d-none');
        if (!label) return;

        var measured = stage === 'upload' && percent !== null;
        var rounded = measured ? Math.min(100, Math.max(0, Math.floor(percent))) : 0;
        if (stage === 'upload') {
            label.textContent = measured ? 'Envoi de la sauvegarde… ' + rounded + ' %' : 'Envoi de la sauvegarde…';
        } else if (stage === 'verify') {
            label.textContent = 'Vérification de l\'archive…';
        } else {
            label.textContent = 'Restauration en cours…';
        }
        if (bar) {
            bar.classList.toggle('d-none', !measured);
            bar.setAttribute('aria-valuenow', String(rounded));
            var fill = /** @type {HTMLElement|null} */ (bar.querySelector('.progress-bar'));
            if (fill) fill.style.width = rounded + '%';
        }
    }

    /**
     * A queued restore, polled until it ends.
     *
     * @param {string|number} actionId
     * @returns {void}
     */
    function followRestore(actionId) {
        var restoreErrorEl = document.getElementById('restore-backup-error');
        showRestoreStage('restore', null);
        pollResetStatus(
            actionId,
            function () { window.location.href = '/config/maintenance/sauvegardes-recentes'; },
            function (message) {
                var progressEl = document.getElementById('restore-backup-progress');
                if (progressEl) progressEl.classList.add('d-none');
                if (restoreErrorEl) {
                    restoreErrorEl.textContent = message || 'La restauration a échoué.';
                    restoreErrorEl.classList.remove('d-none');
                }
            },
            function () { window.location.href = '/config/maintenance/sauvegardes-recentes'; }
        );
    }

    // ——— Off-site destination: showing the passphrase ———
    //
    // Fetched on demand rather than rendered into the page, and that is
    // the whole security of it: a page an administrator opened to look at
    // the disk usage never carries the key to every archive this site has
    // ever sent off-server. The POST is CSRF-guarded and journaled on the
    // server side.
    var passphraseBox = document.getElementById('remote-backup-passphrase');
    var passphraseReveal = /** @type {HTMLButtonElement|null} */ (
        document.getElementById('remote-backup-passphrase-reveal')
    );
    var passphraseCopy = /** @type {HTMLButtonElement|null} */ (
        document.getElementById('remote-backup-passphrase-copy')
    );
    if (passphraseBox && passphraseReveal && passphraseCopy) {
        var passphraseMask = passphraseBox.dataset.mask || '';
        var revealed = false;

        passphraseReveal.addEventListener('click', function () {
            if (revealed) {
                passphraseBox.textContent = passphraseMask;
                passphraseReveal.textContent = 'Afficher la phrase de passe';
                passphraseCopy.classList.add('d-none');
                revealed = false;
                return;
            }

            passphraseReveal.disabled = true;
            void window.ScoutMagicApi.postJson('/config/maintenance/remote/passphrase/reveal', {})
                .then(function (res) {
                    var data = res.data || {};
                    passphraseReveal.disabled = false;
                    if (!data.success || !data.passphrase) {
                        passphraseBox.textContent = data.message || 'La phrase de passe n\'a pas pu être lue.';
                        return;
                    }
                    passphraseBox.textContent = data.passphrase;
                    passphraseReveal.textContent = 'Masquer';
                    passphraseCopy.classList.remove('d-none');
                    revealed = true;
                });
        });

        passphraseCopy.addEventListener('click', function () {
            // navigator.clipboard needs a secure context, which a site
            // served in clear does not have — and this phrase is
            // selectable by hand either way (user-select-all on the box),
            // so a failure here costs nothing worth a message.
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(passphraseBox.textContent || '').then(function () {
                passphraseCopy.textContent = 'Copié';
                window.setTimeout(function () { passphraseCopy.textContent = 'Copier'; }, 1500);
            }, function () { /* nothing to say: the text is selectable */ });
        });
    }

    // ——— « Télécharger » asks first (#847) ———
    //
    // The button is only drawn when the archive is on the disk, but it can
    // leave between the page and the click. Followed blindly, the link
    // would save a 404 under the archive's name in a tab, or strand the
    // installed app on it. So the presence is asked first, and the link is
    // followed only when the archive is there — or when the question
    // itself fails, which is never taken for an answer.
    //
    // The listener is on the wrapper, below the document: it runs before
    // file-viewer.js's, which leaves a click already prevented alone, and
    // the link it re-clicks afterwards goes through file-viewer.js as any
    // other — in a tab and in the installed app alike.
    document.querySelectorAll('[data-backup-download]').forEach(function (wrapper) {
        var backupWrapper = /** @type {HTMLElement} */ (wrapper);
        backupWrapper.addEventListener('click', function (event) {
            var link = /** @type {HTMLAnchorElement|null} */ (
                /** @type {Element} */ (event.target).closest('a')
            );
            if (!link) return;
            if (link.dataset.presenceChecked === '1') {
                delete link.dataset.presenceChecked;
                return;
            }
            // Another handler took this click — the password reveal, which
            // clicks the link again once the password shows. That later
            // click is the one checked: checking this one too would serve
            // the file twice.
            if (event.defaultPrevented) return;
            event.preventDefault();
            var id = backupWrapper.dataset.backupDownload || '';
            fetch('/config/maintenance/backup/' + encodeURIComponent(id) + '/presence', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (response) {
                return response.ok ? response.json().catch(function () { return null; }) : null;
            }).then(function (data) {
                if (data?.archive === false) {
                    archiveVanished(backupWrapper, id);
                    return;
                }
                followDownload(link);
            }, function () {
                followDownload(link);
            });
        });
    });

    /**
     * @param {HTMLAnchorElement} link
     * @returns {void}
     */
    function followDownload(link) {
        link.dataset.presenceChecked = '1';
        link.click();
    }

    /**
     * The row says what happened, without a reload: the archive badge, no
     * more « Télécharger », the password kept — the key to a copy already
     * downloaded.
     *
     * @param {HTMLElement} wrapper
     * @param {string} id
     * @returns {void}
     */
    function archiveVanished(wrapper, id) {
        var row = wrapper.closest('[data-backup-row]');
        var badges = row?.querySelector('[data-backup-badges]');
        if (badges) {
            badges.querySelector('[data-backup-integrity]')?.remove();
            var badge = badges.querySelector('[data-backup-missing]');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'badge text-bg-danger';
                badge.setAttribute('data-backup-missing', '');
                badges.children[0]?.after(badge);
            }
            badge.textContent = 'Archive absente';
            badge.setAttribute('title', 'L\'archive de cette sauvegarde n\'est plus sur ce serveur. Elle ne peut plus '
                + 'être téléchargée ni restaurée ; son mot de passe reste affiché, pour une copie déjà téléchargée.');
        }
        var message = document.getElementById('backup-vanished-' + id);
        if (message) {
            message.textContent = 'L\'archive de cette sauvegarde n\'est plus sur ce serveur. Elle ne peut plus '
                + 'être téléchargée ni restaurée ; vous pouvez supprimer la ligne.';
            message.classList.remove('d-none');
        }
        wrapper.remove();
        document.querySelector('#restore-backup-id option[value="' + id + '"]')?.remove();
    }

    // « Sauvegardes récentes »: the generated password of one archive
    // (issue #619, IT-03), revealed on demand beside its download button.
    // Same rules as the off-site passphrase above: a POST, CSRF-guarded and
    // journaled on the server, never rendered into the page.
    //
    // Since IT-06 the download reveals it too, then serves the file at
    // once: an archive taken off the server without its password is an
    // archive nobody will open, and the key button alone was easy to miss.
    document.querySelectorAll('[data-backup-password]').forEach(function (button) {
        var backupButton = /** @type {HTMLButtonElement} */ (button);
        var id = backupButton.dataset.backupPassword || '';
        var output = document.getElementById('backup-password-' + id);
        if (!output) return;
        var passwordOutput = output;

        backupButton.addEventListener('click', function () {
            if (!passwordOutput.classList.contains('d-none')) {
                passwordOutput.classList.add('d-none');
                passwordOutput.textContent = '';
                return;
            }
            void revealBackupPassword(id, backupButton, passwordOutput);
        });

        var row = backupButton.closest('li');
        var download = /** @type {HTMLAnchorElement|null} */ (row?.querySelector('a[href^="/files/"]') ?? null);
        if (!download) return;
        var downloadLink = download;
        var revealing = false;
        downloadLink.addEventListener('click', function (e) {
            if (!passwordOutput.classList.contains('d-none')) return;
            e.preventDefault();
            // One reveal per download: a second click while the first is
            // still asking would ask again and serve the file twice.
            if (revealing) return;
            revealing = true;
            // The file is served whatever the answer: a password that
            // could not be read says so in the row, and must not also
            // cost the download.
            //
            // In a browser tab, by clicking the link again rather than
            // navigating: `download` and `target="_blank"` keep this
            // window — the one now showing the password — where it is. The
            // second click finds the output visible and returns at the top
            // of this listener.
            //
            // Not in the installed app: there, file-viewer.js sends EVERY
            // file link to the viewer in this same window (on iOS neither
            // attribute keeps it still), so an automatic second click
            // would take the password off the screen the moment it
            // appeared. The operator notes it, then taps again.
            void revealBackupPassword(id, backupButton, passwordOutput).then(function () {
                revealing = false;
                if (isInstalledApp()) {
                    var again = document.createElement('span');
                    again.className = 'd-block';
                    again.textContent = 'Notez-le, puis touchez de nouveau le bouton de téléchargement.';
                    passwordOutput.append(again);
                    return;
                }
                downloadLink.click();
            });
        });
    });

    /**
     * Whether this page runs as the installed application, where
     * file-viewer.js turns every file link into a navigation of this very
     * window — the same test it uses.
     *
     * @returns {boolean}
     */
    function isInstalledApp() {
        return window.matchMedia?.('(display-mode: standalone)').matches === true
            || /** @type {{standalone?: boolean}} */ (window.navigator).standalone === true;
    }

    /**
     * Asks the server for one archive's password and writes it, or why it
     * could not be read, into the row's output.
     *
     * @param {string} id
     * @param {HTMLButtonElement} button
     * @param {HTMLElement} output
     * @returns {Promise<void>}
     */
    function revealBackupPassword(id, button, output) {
        button.disabled = true;
        return window.ScoutMagicApi.postJson('/config/maintenance/backup/' + id + '/password', {})
            .then(function (res) {
                var data = res.data || {};
                button.disabled = false;
                output.textContent = '';
                if (!data.success || !data.password) {
                    output.textContent = data.error || 'Le mot de passe n\'a pas pu être lu.';
                } else {
                    var label = document.createElement('span');
                    label.textContent = 'Mot de passe de l\'archive : ';
                    var value = document.createElement('code');
                    value.className = 'user-select-all';
                    value.textContent = data.password;
                    var note = document.createElement('span');
                    note.className = 'd-block text-body-secondary';
                    note.textContent = 'Notez-le : le serveur qui pourrait vous le redire n\'existera '
                        + 'peut-être plus le jour où vous en aurez besoin.';
                    output.append(label, value, note);
                }
                output.classList.remove('d-none');
            }, function () {
                button.disabled = false;
                output.textContent = 'Le mot de passe n\'a pas pu être lu.';
                output.classList.remove('d-none');
            });
    }

    // Resume polling after a reload of the page while a restore runs, or
    // after the redirect of a plain form POST.
    var restoreIdMatch = /[?&]restore_id=(\d+)/.exec(window.location.search);
    if (restoreIdMatch) {
        followRestore(Number.parseInt(restoreIdMatch[1], 10));
    }
})();
