/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Formulaire d'inscription › Réglages, sub-box « Décisions automatiques »
// (modules/registration/views/config/_settings_automation.html.twig, issue
// #843 IT-10): « Accepter automatiquement les demandes « Oui » » can be on
// only while waitlists are managed and the automatic sending of the decision
// e-mails is on — the order
// Modules\Registration\Service\AutomaticAcceptancePolicy checks them in,
// and the server refuses the save otherwise. This file keeps the switch in
// step without a reload: disabled and unchecked while one is missing, with
// the help text that says which, usable again once both are there —
// when « Envoyer automatiquement les e-mails de décision » is ticked, or
// when « Gérer les listes d'attente » is saved (the event
// registration-capacity-settings.js dispatches once the server agreed).
//
// Exposed as window.ScoutMagicRegistrationAutomationSettings for
// tests/js/registration-automation-settings.test.js.
(function () {
    /**
     * What the automatic acceptance lacks, or null — the first missing
     * prerequisite, in the server's order.
     *
     * @param {boolean} waitlist « Gérer les listes d'attente » is on
     * @param {boolean} autoSend « Envoyer automatiquement les e-mails de décision » is ticked
     * @returns {string|null} 'waitlist', 'auto_send' or null
     */
    function missingPrerequisite(waitlist, autoSend) {
        if (!waitlist) return 'waitlist';
        if (!autoSend) return 'auto_send';
        return null;
    }

    /**
     * Puts the acceptance switch of one sub-box, and its help text, in the
     * state its prerequisites say. A switch that becomes unavailable is
     * unchecked: disabled, it would not be submitted, and saving would
     * switch it off anyway — the page must not show it on until then.
     *
     * @param {HTMLFormElement} form
     */
    function sync(form) {
        var acceptSwitch = /** @type {HTMLInputElement|null} */ (form.querySelector('[data-auto-accept-switch]'));
        var sendSwitch = /** @type {HTMLInputElement|null} */ (form.querySelector('[data-auto-send-switch]'));
        if (!acceptSwitch || !sendSwitch) {
            return;
        }
        var missing = missingPrerequisite(
            form.dataset.waitlistEnabled === '1',
            sendSwitch.checked
        );
        acceptSwitch.disabled = missing !== null;
        if (missing !== null) {
            acceptSwitch.checked = false;
        }
        form.querySelectorAll('[data-auto-accept-help]').forEach(
            /** @param {HTMLElement} help */
            function (help) { help.hidden = help.dataset.autoAcceptHelp !== (missing ?? 'ready'); }
        );
    }

    /** @param {HTMLFormElement} form */
    function bind(form) {
        var sendSwitch = form.querySelector('[data-auto-send-switch]');
        if (sendSwitch) {
            sendSwitch.addEventListener('change', function () { sync(form); });
        }
        document.addEventListener('registration:waitlist-changed', function (event) {
            var detail = /** @type {CustomEvent<{enabled: boolean}>} */ (event).detail;
            form.dataset.waitlistEnabled = detail?.enabled ? '1' : '0';
            sync(form);
        });
        sync(form);
    }

    function init() {
        var form = /** @type {HTMLFormElement|null} */ (document.querySelector('form[data-decision-automation]'));
        if (form) {
            bind(form);
        }
    }

    window.ScoutMagicRegistrationAutomationSettings = {
        missingPrerequisite: missingPrerequisite,
        sync: sync,
        init: init,
    };

    init();
})();
