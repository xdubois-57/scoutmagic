/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Copier pour Desk » on Cotisations > Justesse des tarifs
// (modules/fees/views/partials/_household_card.html.twig).
//
// The site cannot write to Desk and does not pretend to. What it can do is
// put the whole household on the clipboard so a treasurer corrects it in the
// other browser tab without scrolling between two screens. Volontairement
// bête: no formatting decision is taken here.
//
// The blocks are assembled server-side (Modules\Fees\Service\
// DeskClipboardText) and travel in one JSON island, read through
// window.ScoutMagicApi.pageData() — the names are already decrypted there,
// and a second assembly in the browser would be a second place for the
// wording to drift.
(function () {
    var ISLAND_ID = 'fees-clipboard-data';

    /**
     * The block for one household, or '' when the page carries none for it
     * — a stale DOM, or an id nothing was rendered for. The caller treats
     * '' as "nothing to do" rather than copying an empty clipboard.
     *
     * @param {Record<string, string>|null} texts
     * @param {string} key
     * @returns {string}
     */
    function blockFor(texts, key) {
        if (!texts || !key || !Object.hasOwn(texts, key)) {
            return '';
        }
        var block = texts[key];

        return typeof block === 'string' ? block : '';
    }

    window.ScoutMagicFeesCopy = { blockFor: blockFor };

    document.addEventListener('click', async function (event) {
        var target = /** @type {HTMLElement|null} */ (event.target);
        if (!target?.closest) {
            return;
        }
        var button = /** @type {HTMLElement|null} */ (target.closest('[data-fees-copy]'));
        if (!button) {
            return;
        }

        var text = blockFor(window.ScoutMagicApi.pageData(ISLAND_ID), button.dataset.feesCopy || '');
        if (text === '') {
            window.ScoutMagicToast.show('Rien à copier pour ce foyer.', { variant: 'warning' });
            return;
        }

        try {
            if (!navigator.clipboard) {
                throw new Error('clipboard unavailable');
            }
            await navigator.clipboard.writeText(text);
            window.ScoutMagicToast.show('Foyer copié.', { variant: 'success' });
        } catch {
            // navigator.clipboard needs a secure context and is simply
            // absent otherwise — the same fallback as
            // leadership-copy-emails.js: hand the text over, pre-selected,
            // rather than let a button silently do nothing.
            await window.ScoutMagicConfirm.prompt({
                message: 'Copiez ce foyer pour Desk :',
                title: 'Foyer',
                value: text,
                readonly: true,
                multiline: true,
                confirmLabel: 'Fermer',
            });
        }
    });
})();
