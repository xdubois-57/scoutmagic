/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The one warning on the article editor that has to arrive BEFORE the
// save, not after it.
//
// An event's date and place are read from the database everywhere they
// are shown — the ticket, the confirmation e-mail, the door screen — so
// correcting them corrects every surface at once. The exception is the
// ICS file: once sent, it lives in the recipient's own calendar with the
// old date and the old address, and nothing this site does later will
// update it. So changing either value while people are already
// registered is not a mistake to prevent, it is a consequence to name
// while somebody can still decide to write to them.
//
// Hence `variant: 'primary'` rather than the default danger styling:
// nothing is destroyed and nothing is refused. The author confirms and
// the save proceeds.
//
// The form carries what it was loaded with in two data- attributes, and
// the dialog only appears when the submitted values actually differ —
// opening the editor, changing a field label and saving must not ask
// about a date nobody touched.
(function () {
    /**
     * Whether saving these values needs the ICS warning.
     *
     * Exported on the global for the unit test and for nothing else: it
     * is the whole decision, and it is the half of this file that is
     * worth testing without a DOM.
     *
     * @param {{originalDate: string, originalLocation: string, date: string, location: string, icsAlreadySent: boolean}} state
     * @returns {boolean}
     */
    function needsIcsWarning(state) {
        // The server answers the whole "was an ICS ever sent for this
        // form" question — it needs the ticket flag, the event date and
        // the response count to do it, and two of those are not on this
        // page. Nothing in anybody's calendar means nothing to
        // contradict.
        if (!state.icsAlreadySent) return false;

        return state.date !== state.originalDate || state.location !== state.originalLocation;
    }

    /** @param {HTMLInputElement|HTMLElement|null} el */
    function valueOf(el) {
        return el && 'value' in el ? String(el.value).trim() : '';
    }

    function init() {
        const form = /** @type {HTMLFormElement|null} */ (document.getElementById('news-editor-form'));
        if (!form) return;

        const dateEl = document.getElementById('form_event_date');
        const locationEl = document.getElementById('form_event_location');
        if (!dateEl || !locationEl) return;

        let confirmed = false;

        form.addEventListener('submit', function (event) {
            if (confirmed) return;

            const needed = needsIcsWarning({
                originalDate: form.dataset.originalEventDate || '',
                originalLocation: form.dataset.originalEventLocation || '',
                date: valueOf(dateEl),
                location: valueOf(locationEl),
                icsAlreadySent: form.dataset.icsAlreadySent === '1',
            });
            if (!needed) return;

            event.preventDefault();

            const ask = window.ScoutMagicConfirm?.ask;
            if (typeof ask !== 'function') {
                // No dialog available (an offline page, a script that
                // failed to load): let the save through rather than
                // trapping the author on a form that will not submit. The
                // warning is a courtesy, never a gate.
                confirmed = true;
                form.requestSubmit();
                return;
            }

            window.ScoutMagicConfirm.ask({
                title: 'Modifier la date ou le lieu ?',
                message: 'Les personnes déjà inscrites ont reçu un fichier d’agenda portant '
                    + 'l’ancienne date et l’ancien lieu. Il ne sera pas corrigé : il faudra '
                    + 'les prévenir autrement.',
                confirmLabel: 'Enregistrer quand même',
                cancelLabel: 'Annuler',
                variant: 'primary',
            }).then(function (ok) {
                if (!ok) return;
                confirmed = true;
                form.requestSubmit();
            });
        });
    }

    window.ScoutMagicNewsEventDetails = { needsIcsWarning: needsIcsWarning };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
