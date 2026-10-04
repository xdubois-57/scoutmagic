/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Réinscriptions configuration (modules/registration/views/
// reenrollment_config.html.twig): every save that changes something is
// confirmed, and the dialog always answers one question — does an e-mail
// leave? (issue #796, D2).
//
// The server decides everything: the plan of what the save does — what
// changes, which e-mails leave, for which campaign, to how many families —
// is computed by ReenrollmentSavePlanner and worded by
// ReenrollmentSavePlanPresenter (D1). This file asks
// /config/reinscription/apercu for it, shows it in the site's dialog, and
// submits with the plan's fingerprint once the chief agrees. save() writes
// nothing unless that fingerprint still matches the plan it computes then,
// so a stale dialog cannot be agreed to; and a browser without this file —
// or a preview that could not be reached — is shown the same confirmation
// as a page by the server (D7). Nobody is ever stuck, and nobody ever saves
// without being asked.
(function () {
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('reenrollment-config-form'));
    if (!form) {
        return;
    }
    var api = window.ScoutMagicApi;
    var fingerprintField = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="plan_fingerprint"]'));

    /**
     * The form as the server reads it: text fields by name, and the two
     * switches as '1' / '0'.
     *
     * @param {HTMLFormElement} source
     * @returns {Record<string, string>}
     */
    function values(source) {
        /** @type {Record<string, string>} */
        var out = {};
        source.querySelectorAll('input[name]').forEach(function (node) {
            var input = /** @type {HTMLInputElement} */ (node);
            if (input.type === 'hidden' && input.name !== 'registration_reenrollment_emails_enabled') {
                return;
            }
            if (input.type === 'checkbox') {
                out[input.name] = input.checked ? '1' : '0';
            } else if (input.type !== 'hidden' || out[input.name] === undefined) {
                out[input.name] = input.value;
            }
        });
        return out;
    }

    /**
     * The plan's dialog as the site's confirmation blocks: the campaign
     * concerned, what changes, then the answer — e-mails that leave, in
     * the warning colour, or none, with the reason.
     *
     * @param {any} dialog
     * @returns {Array<{kind?: 'box'|'list', tone?: 'muted'|'warning'|'success', title?: string,
     *                  text?: string, items?: string[], note?: string}>}
     */
    function blocks(dialog) {
        /** @type {Array<{kind?: 'box'|'list', tone?: 'muted'|'warning'|'success', title?: string,
         *                 text?: string, items?: string[], note?: string}>} */
        var out = [];
        if (dialog.campaign) {
            out.push({ kind: 'box', tone: 'muted', title: dialog.campaign.title, text: dialog.campaign.detail });
        }
        out.push({ kind: 'list', title: 'Ce qui change', items: dialog.changes || [] });
        if (dialog.mail) {
            out.push({
                kind: 'box',
                tone: 'warning',
                title: dialog.mail.headline,
                items: dialog.mail.lines || [],
                note: dialog.mail.note,
            });
        } else if (dialog.none) {
            out.push({ kind: 'box', tone: 'success', title: dialog.none.headline, text: dialog.none.reason });
        }
        return out;
    }

    function submitForReal() {
        form.dataset.planChecked = '1';
        form.requestSubmit ? form.requestSubmit() : form.submit();
    }

    form.addEventListener('submit', function (event) {
        if (form.dataset.planChecked === '1') {
            return;
        }
        event.preventDefault();

        void api.postJson(form.dataset.previewEndpoint || '', values(form)).then(function (res) {
            var data = res.data?.success ? res.data : null;
            if (!data || !data.changed || !data.dialog) {
                // Nothing changes — the server says so after the submit —
                // or the plan could not be read, in which case the server
                // shows the confirmation itself rather than save unasked.
                submitForReal();
                return;
            }

            var dialog = data.dialog;
            void window.ScoutMagicConfirm.ask({
                title: dialog.title,
                message: '',
                content: blocks(dialog),
                confirmLabel: dialog.confirm_label,
                // Warning colour only when an e-mail leaves: it cannot be
                // called back. A save that writes to nobody is ordinary.
                variant: dialog.sends ? 'danger' : 'primary',
            }).then(function (agreed) {
                if (!agreed) {
                    return;
                }
                if (fingerprintField) {
                    fingerprintField.value = String(data.fingerprint || '');
                }
                submitForReal();
            });
        }, function () {
            submitForReal();
        });
    });
})();
