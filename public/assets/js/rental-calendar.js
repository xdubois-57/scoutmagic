/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Two-tap range selection on an asset's public availability calendar
 * (module spec §6.7): first tap sets the arrival, second sets the departure.
 *
 * **The mobile experience is the point.** The spec is explicit that picking
 * dates on the calendar must not be replaced by two date fields — so this
 * drives the same grid a visitor is already looking at, on touch and on
 * pointer alike, with no hover requirement.
 *
 * **It decides nothing.** Every tap produces a URL and lets the server
 * recompute states, prices and validation. The client never marks a day
 * available, never prices anything, and never decides a range is legal — a
 * calendar that disagreed with the server would be worse than no calendar.
 * The date fields in the summary form remain fully usable with this script
 * absent, which is also the no-JavaScript fallback.
 */

/**
 * Which of the two dates a tap on `date` should set, given what is already
 * selected.
 *
 * The interesting case is the third one: tapping a day *before* the current
 * arrival starts a NEW selection rather than producing a reversed range.
 * That is how a visitor changes their mind, not a mistake to refuse — and
 * refusing it strands them with no way back except reloading the page.
 *
 * @param {string} date The tapped day, `YYYY-MM-DD`.
 * @param {string} arrival Currently selected arrival, or '' when none.
 * @param {string} departure Currently selected departure, or '' when none.
 * @returns {{arrival: string, departure: string}} The selection after the tap.
 */
export function nextSelection(date, arrival, departure) {
    if (!date) {
        return { arrival: arrival, departure: departure };
    }

    // Nothing chosen yet, or a complete range already chosen: start over.
    if (!arrival || (arrival && departure)) {
        return { arrival: date, departure: '' };
    }

    // Tapping the arrival again clears the selection, so a visitor can undo
    // a mis-tap with the same gesture that made it.
    if (date === arrival) {
        return { arrival: '', departure: '' };
    }

    if (date < arrival) {
        return { arrival: date, departure: '' };
    }

    return { arrival: arrival, departure: date };
}

/**
 * The URL a tap should navigate to, preserving whatever else is in the query
 * string (the displayed month, the head count, the category…).
 *
 * Built from the current URL rather than assembled from scratch so a
 * parameter this script does not know about — one a later iteration adds —
 * survives a tap instead of being silently dropped.
 *
 * @param {string} currentUrl
 * @param {{arrival: string, departure: string}} selection
 * @returns {string}
 */
export function selectionUrl(currentUrl, selection) {
    const url = new URL(currentUrl, 'https://placeholder.invalid');

    if (selection.arrival) {
        url.searchParams.set('arrival', selection.arrival);
    } else {
        url.searchParams.delete('arrival');
    }

    if (selection.departure) {
        url.searchParams.set('departure', selection.departure);
    } else {
        url.searchParams.delete('departure');
    }

    return url.pathname + (url.search ? url.search : '');
}

/**
 * @param {HTMLElement} container
 * @returns {void}
 */
export function wireCalendar(container) {
    container.addEventListener('click', function (event) {
        const target = /** @type {HTMLElement} */ (event.target);
        const cell = target && target.closest ? target.closest('[data-date]') : null;
        if (!cell) {
            return;
        }

        // An unselectable day renders as a <div>, not a <button>, so it
        // cannot be clicked at all — but a tap could still land on a cell
        // the server marked occupied, and the server is the authority on
        // that either way.
        const date = cell.getAttribute('data-date');
        if (!date) {
            return;
        }

        const next = nextSelection(
            date,
            container.getAttribute('data-arrival') || '',
            container.getAttribute('data-departure') || ''
        );

        window.location.href = selectionUrl(window.location.href, next);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('rental-calendar');
    if (container) {
        wireCalendar(container);
    }
});
