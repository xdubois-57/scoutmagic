/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Public calendar page (modules/calendar/views/public.html.twig): the
// event-details dialog behind every bar of the month grid and every
// « Prochains évènements » row, plus the personal ICS link's copy and
// regenerate controls. Extracted from the template's two inline
// <script> blocks so the Vitest suite can exercise the production code
// directly (tests/js/calendar-public.test.js).
//
// The two native boxes are gone: regenerating asks through
// ScoutMagicConfirm — the same danger-variant dialog calendar-config.js
// uses for the very same act on the unit's links — and the failure is a
// toast, with a fallback sentence the inline version did not have (it
// passed `data.error` straight to alert(), so a refusal that carried no
// message showed the word « undefined »).
(function () {
    var api = window.ScoutMagicApi;

    var modalEl = document.getElementById('eventDetailsModal');
    var copyBtn = document.getElementById('copy-personal-link');
    var regenerateBtn = document.getElementById('regenerate-personal-link');

    // A no-op on every other page of the site.
    if (!modalEl && !copyBtn && !regenerateBtn) {
        return;
    }

    // --- Event details ---

    /**
     * @param {string} isoDate `YYYY-MM-DD`
     * @returns {string} `DD/MM/YYYY`
     */
    function formatDateFr(isoDate) {
        var parts = String(isoDate).split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    /**
     * @param {string} id
     * @param {string} text
     */
    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = text;
        }
    }

    /**
     * @param {string} id
     * @param {boolean} shown
     */
    function toggleRow(id, shown) {
        var el = document.getElementById(id);
        if (el) {
            el.classList.toggle('d-none', !shown);
        }
    }

    if (modalEl) {
        var Modal = window.bootstrap?.Modal;
        var detailsModal = Modal ? new Modal(modalEl) : null;

        // Shared by both the month-grid bars and the « Prochains
        // évènements » list items — both emit the identical data-* bag
        // (see partials/month_grid.html.twig and the <li> loop), so
        // there is only ever one place that knows how to fill this
        // dialog.
        //
        // Every field goes in through textContent: these strings are an
        // event's title, place and description as someone typed them.
        var showEventDetails = function (data) {
            // The shared modal embed names its title '{id}-title'.
            setText('eventDetailsModal-title', data.title);

            var dot = document.getElementById('event-details-calendar-dot');
            if (dot) {
                dot.style.backgroundColor = data.color;
            }
            setText('event-details-calendar-label', data.calendarLabel);

            var dates = formatDateFr(data.startDate);
            if (data.endDate && data.endDate !== data.startDate) {
                dates += ' — ' + formatDateFr(data.endDate);
            }
            setText('event-details-dates', dates);

            if (data.startTime) {
                setText('event-details-time', data.endTime
                    ? data.startTime + ' - ' + data.endTime
                    : data.startTime);
            }
            toggleRow('event-details-time-row', !!data.startTime);

            if (data.location) {
                setText('event-details-location', data.location);
            }
            toggleRow('event-details-location-row', !!data.location);

            setText('event-details-description', data.description || '');

            if (detailsModal) {
                detailsModal.show();
            }
        };

        // Both triggers are real <button> elements, so Enter and Space
        // already fire a click — no keyboard handler of our own, and
        // none of the inline onKeyDown attribute this site's CSP would
        // refuse to run anyway.
        /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll(
            '.calendar-event-bar--clickable, .calendar-upcoming-event-trigger'
        )).forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                showEventDetails(trigger.dataset);
            });
        });
    }

    // --- Personal ICS link ---

    // Selecting the field is the fallback: on a browser without the
    // async clipboard API (or without permission), the visitor still has
    // the whole link highlighted and can copy it by hand.
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var input = /** @type {HTMLInputElement|null} */ (
                document.getElementById('personal-ics-link')
            );
            if (!input) {
                return;
            }
            input.select();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value);
            }
        });
    }

    if (regenerateBtn) {
        regenerateBtn.addEventListener('click', async function () {
            var confirmed = await window.ScoutMagicConfirm.ask({
                message: "Régénérer le lien invalidera l'ancien : les abonnements déjà"
                    + ' configurés cesseront de se mettre à jour. Continuer ?',
                confirmLabel: 'Régénérer'
            });
            if (!confirmed) {
                return;
            }

            var res = await api.withDisabled(
                /** @type {HTMLButtonElement} */ (regenerateBtn),
                function () { return api.postJson('/calendar/personal-token/regenerate', {}); }
            );

            if (res.data?.success) {
                // The new link has to replace the old one in the field,
                // in the QR code and in the subscribe button.
                window.location.reload();
                return;
            }
            window.ScoutMagicToast.show(
                res.data?.error || 'Le lien n\'a pas pu être régénéré.',
                { variant: 'error' }
            );
        });
    }
})();
