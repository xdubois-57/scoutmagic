/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Espace chefs d'U › Documents » (modules/documents/views/
// manage.html.twig), issue #731: the address of a document is no longer
// printed in clear on every row; a button copies it. Where the clipboard
// is unavailable (an insecure context, an old browser), the link is shown
// pre-selected in the site's own dialog, ready to copy by hand.
(function () {
    document.addEventListener('click', async function (event) {
        var target = /** @type {HTMLElement|null} */ (event.target);
        var button = /** @type {HTMLElement|null} */ (target?.closest ? target.closest('[data-copy-link]') : null);
        if (!button) {
            return;
        }
        var link = button.dataset.copyLink || '';
        if (link === '') {
            return;
        }

        try {
            if (!navigator.clipboard) {
                throw new Error('clipboard unavailable');
            }
            await navigator.clipboard.writeText(link);
            window.ScoutMagicToast.show('Lien copié.', { variant: 'success' });
        } catch {
            await window.ScoutMagicConfirm.prompt({
                message: 'Copiez le lien du document :',
                title: 'Lien du document',
                value: link,
                readonly: true,
                confirmLabel: 'Fermer',
            });
        }
    });
})();
