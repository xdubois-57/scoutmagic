/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The composer's confirmation, destination by destination (issue #706,
// IT-05).
//
// **Why it is not `data-confirm` any more.** That attribute carries one
// fixed sentence, and the chantier asks the dialog to say a different
// thing for each destination the chief actually ticked: « Cet album est
// déjà parti sur Facebook le 12 septembre, avec le même message » for
// one, « Jamais partagé ici. » for the next. Which boxes are ticked is
// known only in the browser, and only at the moment of the click.
//
// **« avec le même message » is the point of the whole dialog.** Sharing
// the same album again with a different text is allowed on purpose since
// IT-05 — permis, avec avertissement. The one case that almost always
// means somebody forgot they had already shared it is the identical text,
// so that is the phrase the server works out
// (`Service\ShareHistory::byDestination()`) and this puts in front of
// them before anything leaves.
//
// It keeps the two things the fixed sentence carried: that a publication
// is public and outside the site, and the gallery photo leaving sharp —
// read from the hint the composer already shows under the slider, so
// there is one rule for « is this photo about to leave unblurred » and
// not two.
//
// A separate file rather than a block inside `social-composer.js`: that
// one returns at once on a page with no card to draw, and the
// confirmation has to stand whether there is a canvas or not.
(function () {
    const button = document.querySelector('[data-publish-confirm]');
    const form = button?.closest('form');
    const confirmer = window.ScoutMagicConfirm;
    // With no dialog to show, the button stays an ordinary submit: the
    // form posts and the server decides, exactly as it did before any
    // confirmation existed.
    if (!(button instanceof HTMLButtonElement) || !(form instanceof HTMLFormElement) || !confirmer) {
        return;
    }

    const NEVER = 'Jamais partagé ici.';

    /**
     * One block per destination about to receive something, in the order
     * the page lists them.
     *
     * @returns {Array<{kind: 'box', tone: 'muted'|'warning', title: string, text: string}>}
     */
    function blocks() {
        const out = [];

        form.querySelectorAll('[data-destination]').forEach(function (row) {
            const box = row.querySelector('input[name="destinations[]"]');
            if (!(box instanceof HTMLInputElement) || !box.checked || box.disabled) {
                return;
            }

            // The groups box is a door, not a destination: what receives
            // something is each group ticked behind it.
            if (box.value === 'groups') {
                form.querySelectorAll('[data-group]').forEach(function (groupRow) {
                    const groupBox = groupRow.querySelector('input[name="groups[]"]');
                    if (groupBox instanceof HTMLInputElement && groupBox.checked && !groupBox.disabled) {
                        out.push(blockFor(groupRow));
                    }
                });
                return;
            }

            out.push(blockFor(row));
        });

        return out;
    }

    /**
     * @param {Element} row
     * @returns {{kind: 'box', tone: 'muted'|'warning', title: string, text: string}}
     */
    function blockFor(row) {
        const line = row.getAttribute('data-shared-line') || '';

        return {
            kind: 'box',
            // Warning where something already went, muted where nothing
            // did: the colour is the first thing read, and the only line
            // worth stopping on is the one that says « already ».
            tone: line === '' ? 'muted' : 'warning',
            title: row.getAttribute('data-destination-label') || '',
            text: line === '' ? NEVER : line,
        };
    }

    /**
     * The composer's own sharp-photo hint, when it is showing.
     *
     * @returns {Array<{kind: 'box', tone: 'warning', title: string, text: string}>}
     */
    function sharpWarning() {
        const hint = form.querySelector('[data-card-blur-sharp]');

        return hint instanceof HTMLElement && !hint.hidden
            ? [{ kind: 'box', tone: 'warning', title: '', text: (hint.textContent || '').trim() }]
            : [];
    }

    button.addEventListener('click', function (event) {
        event.preventDefault();

        void confirmer.ask({
            title: 'Publier cette communication ?',
            message: 'Sur Facebook et Instagram, une fois publié, c’est public et hors du site :'
                + ' personne ne pourra le reprendre d’ici.',
            confirmLabel: 'Publier',
            variant: 'primary',
            content: blocks().concat(sharpWarning()),
        }).then(function (yes) {
            if (!yes) {
                return;
            }
            // `requestSubmit(button)` and not `submit()`: the button
            // carries `name="action" value="publish"`, which is how the
            // controller tells publishing from the other buttons of this
            // form, and `submit()` sends no submitter at all.
            form.requestSubmit(button);
        });
    });
})();
