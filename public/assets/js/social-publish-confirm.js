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
     * The text about to be published, **as it stands right now**.
     *
     * This is the half of « avec le même message » that only the browser
     * has: the server rendered its sentence when the page was built, and
     * the chief may have rewritten the caption since — reusing an album
     * with new text is exactly what IT-05 made possible. So the verdict
     * is decided here, against the live field, and the server ships the
     * caption that went rather than its own conclusion (`Claude review`
     * on PR #868).
     *
     * @returns {string}
     */
    function liveCaption() {
        const field = form.querySelector('#communication-body');

        return field instanceof HTMLTextAreaElement ? serverTrim(field.value) : '';
    }

    /**
     * `trim()` as PHP does it — the controller's `body()` is what decides
     * whether two texts are the same communication, and PHP strips only
     * the ASCII whitespace and NUL, where JavaScript also strips the
     * non-breaking space and every other Unicode blank. A text that
     * differs from the one that went by such a character at its edge is
     * another communication for the server, so it must be another one for
     * the dialog too, or the dialog promises a relaunch that does not
     * happen (CodeRabbit on PR #1020).
     *
     * @param {string} value
     * @returns {string}
     */
    function serverTrim(value) {
        return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    }

    /**
     * One block per destination about to receive something, in the order
     * the page lists them.
     *
     * @returns {Array<{kind: 'box', tone: 'muted'|'warning', title: string, text: string}>}
     */
    function blocks() {
        const out = [];
        // Read once per click, not once per destination: the field
        // cannot change while this loop runs, and the comparison must
        // not be able to differ between two blocks of one dialog.
        const caption = liveCaption();

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
                        out.push(blockFor(/** @type {HTMLElement} */ (groupRow), caption));
                    }
                });
                return;
            }

            out.push(blockFor(/** @type {HTMLElement} */ (row), caption));
        });

        return out;
    }

    /**
     * The failed publications the server listed for this destination.
     * A list that cannot be read is no list: the dialog then speaks as it
     * did before, which is never wrong, only less complete.
     *
     * @param {HTMLElement} row
     * @returns {Array<{sentence: string, caption: string}>}
     */
    function failedShares(row) {
        try {
            const parsed = JSON.parse(row.dataset.failedShares || '[]');

            return Array.isArray(parsed)
                ? parsed.filter(function (item) {
                    return item && typeof item.sentence === 'string' && typeof item.caption === 'string';
                })
                : [];
        } catch (error) {
            // Deliberately ignored: the attribute is written by the
            // server, so an unreadable one means the page was altered, and
            // the right answer is the dialog as it was before this list
            // existed — complete enough, and never wrong.
            return [];
        }
    }

    /**
     * @param {HTMLElement} row
     * @param {string} caption the text about to be published, trimmed
     * @returns {{kind: 'box', tone: 'muted'|'warning', title: string, text: string}}
     */
    function blockFor(row, caption) {
        const sentence = row.dataset.sharedLine || '';
        const title = row.dataset.destinationLabel || '';
        const same = serverTrim(row.dataset.sharedCaption || '') === caption;
        // What this source already went through at that destination: the
        // warning the dialog exists for, with its « même message » clause.
        const gone = sentence === '' ? '' : sentence + (same ? ', avec le même message.' : ', avec un autre message.');

        // **A failure of this very message is said in ADDITION to what
        // already went out** (issue #881), never instead of it: the same
        // album can have gone to a destination under another text and
        // failed there under this one, and the click then relaunches the
        // second while the first stays public. Same album, same chief,
        // same text is the communication the click lands on, and only the
        // live text can say it is the same one, which is why the server
        // ships every failed text.
        const relaunch = failedShares(row).find(function (failure) {
            return serverTrim(failure.caption) === caption;
        });
        if (relaunch !== undefined) {
            return {
                kind: 'box',
                tone: 'warning',
                title: title,
                text: gone === '' ? relaunch.sentence : relaunch.sentence + ' ' + gone,
            };
        }

        if (gone === '') {
            // Muted where nothing went: the colour is the first thing
            // read, and the only line worth stopping on is the one that
            // says « already ».
            return { kind: 'box', tone: 'muted', title: title, text: NEVER };
        }

        return { kind: 'box', tone: 'warning', title: title, text: gone };
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
