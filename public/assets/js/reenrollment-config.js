/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Réinscriptions configuration (modules/registration/views/
// reenrollment_config.html.twig): asks before a save that would open the
// campaign right now AND write the opening e-mail to every family
// (issue #732).
//
// The server decides whether there is anything to ask — the dates, the
// switch, the markers and the year boundary all live there, and a second
// copy of that arithmetic here would be a second answer to « does this
// open the campaign ». This file only asks /config/reinscription/apercu,
// shows the site's own dialog when the answer is a question, and submits
// with `confirm_opening` once the chief has said yes. save() refuses that
// save without it, so a browser without this script cannot skip the
// question; it is simply told nothing was saved.
(function () {
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('reenrollment-config-form'));
    if (!form) {
        return;
    }
    var api = window.ScoutMagicApi;
    var confirmField = /** @type {HTMLInputElement|null} */ (form.querySelector('input[name="confirm_opening"]'));

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

    form.addEventListener('submit', function (event) {
        if (form.dataset.openingChecked === '1') {
            return;
        }
        event.preventDefault();

        void api.postJson(form.dataset.previewEndpoint || '', values(form)).then(function (res) {
            var question = res.data?.success ? res.data.confirm : null;
            if (!question) {
                // Nothing to ask — or the question could not be asked, in
                // which case the server still guards the save itself.
                form.dataset.openingChecked = '1';
                form.requestSubmit ? form.requestSubmit() : form.submit();
                return;
            }

            // The default, warning-coloured dialog: an e-mail to every
            // family cannot be called back.
            void window.ScoutMagicConfirm.ask({
                message: question,
                confirmLabel: 'Ouvrir et envoyer',
            }).then(function (agreed) {
                if (!agreed) {
                    return;
                }
                if (confirmField) {
                    confirmField.value = '1';
                }
                form.dataset.openingChecked = '1';
                form.requestSubmit ? form.requestSubmit() : form.submit();
            });
        });
    });
})();
