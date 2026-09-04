/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Copier les adresses » on the Encadrement lists
// (modules/leadership/views/partials/_person_list.html.twig).
//
// These pages answer "who do I have to go and talk to", and the answer was
// a list of names with no way to reach any of them: a chef d'unité read
// sixteen names here and then looked up sixteen addresses in Desk. Each row
// now carries its own `mailto:`, and this button turns a whole list into
// one address string ready to paste into a To: field.
//
// The addresses come off the rows themselves (`data-email`), not from a
// separate data island: they are already in the page as `mailto:` links, and
// a second copy would be a second thing to keep in step.
//
// Rows Desk holds no address for simply carry no attribute, and the template
// counts them out loud underneath — silently shipping a shorter list than
// the one on screen is exactly how somebody concludes everybody was written
// to.
(function () {
    /**
     * The addresses of one list, in the order the rows appear, without
     * duplicates.
     *
     * Duplicates are real: somebody who is an animateur and an intendant
     * appears on a list once per function, and pasting their address twice
     * into a To: field is how a mail client starts warning about it.
     * Comparison for that is case-insensitive and trimmed, but what is
     * COPIED is the address as it was typed into Desk — lower-casing
     * somebody's address is a change this page has no business making.
     *
     * @param {ParentNode} list
     * @returns {string[]}
     */
    function collect(list) {
        var seen = {};
        var addresses = [];

        list.querySelectorAll('[data-email]').forEach(function (row) {
            var address = (/** @type {HTMLElement} */ (row).dataset.email || '').trim();
            var key = address.toLowerCase();
            if (address === '' || Object.hasOwn(seen, key)) {
                return;
            }
            seen[key] = true;
            addresses.push(address);
        });

        return addresses;
    }

    /**
     * The one string a mail client understands: addresses separated by
     * "; ". Empty for a list with nothing to copy, which the caller treats
     * as "nothing to do" rather than copying an empty clipboard.
     *
     * @param {string[]} addresses
     * @returns {string}
     */
    function format(addresses) {
        return addresses.join('; ');
    }

    window.ScoutMagicLeadershipEmails = { collect: collect, format: format };

    document.addEventListener('click', async function (event) {
        var target = /** @type {HTMLElement|null} */ (event.target);
        if (!target || !target.closest) {
            return;
        }
        var button = /** @type {HTMLElement|null} */ (target.closest('[data-copy-emails]'));
        if (!button) {
            return;
        }

        var list = document.getElementById(button.dataset.copyEmails || '');
        var text = list ? format(collect(list)) : '';
        if (text === '') {
            window.ScoutMagicToast.show('Aucune adresse à copier dans cette liste.', { variant: 'warning' });
            return;
        }

        try {
            if (!navigator.clipboard) {
                throw new Error('clipboard unavailable');
            }
            await navigator.clipboard.writeText(text);
            window.ScoutMagicToast.show('Adresses copiées.', { variant: 'success' });
        } catch {
            // navigator.clipboard needs a secure context and is simply
            // absent otherwise. Handing the addresses over, pre-selected,
            // is the honest fallback — a button that silently does nothing
            // is worse than no button.
            await window.ScoutMagicConfirm.prompt({
                message: 'Copiez les adresses de cette liste :',
                title: 'Adresses',
                value: text,
                readonly: true,
                multiline: true,
                confirmLabel: 'Fermer',
            });
        }
    });
})();
