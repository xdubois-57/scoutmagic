/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Formulaire d'inscription › Réglages, box « Copie des e-mails
// d'inscription » (modules/registration/views/config/_settings_copy.html.twig,
// issue #843 IT-06): the address field follows the switch « Mettre une
// adresse en copie » — usable while it is on, disabled (greyed, and not
// submitted, so the stored address is kept) while it is off. The server
// renders the same state, so without this file the page only catches up
// after « Enregistrer ».
//
// Exposed as window.ScoutMagicRegistrationCopySettings for
// tests/js/registration-copy-settings.test.js.
(function () {
    /**
     * Puts the address field of one box in the state its switch says.
     *
     * @param {HTMLInputElement} toggle
     * @param {HTMLInputElement} address
     */
    function syncAddress(toggle, address) {
        address.disabled = !toggle.checked;
    }

    /** @param {HTMLElement} form */
    function bind(form) {
        var toggle = /** @type {HTMLInputElement|null} */ (form.querySelector('[data-email-copy-switch]'));
        var address = /** @type {HTMLInputElement|null} */ (form.querySelector('[data-email-copy-address]'));
        if (!toggle || !address) {
            return;
        }
        toggle.addEventListener('change', function () { syncAddress(toggle, address); });
        syncAddress(toggle, address);
    }

    function init() {
        var form = document.getElementById('registration-email-copy');
        if (form) {
            bind(form);
        }
    }

    window.ScoutMagicRegistrationCopySettings = { syncAddress: syncAddress, init: init };

    init();
})();
