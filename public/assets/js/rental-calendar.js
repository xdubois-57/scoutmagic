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
 *
 * **What it does instead of reloading.** A tap, a month arrow and the
 * « Estimer » button all used to navigate: the page flashed, the scroll
 * position was lost, and on a phone the calendar jumped out from under the
 * thumb that had just tapped it. They now fetch `/locations/{slug}/apercu`,
 * which returns the very same two Twig partials the full page renders, and
 * swap them in. The address bar is kept in step with history.replaceState,
 * so reloading, sharing or bookmarking the page still lands on exactly what
 * the visitor is looking at.
 *
 * Every one of those controls is still a real link or a real GET form
 * underneath. If this script is absent, blocked, or has not run yet, they
 * navigate — which is the same answer, one page load slower.
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
 * The fragment endpoint for a page URL: same query string, `/apercu`
 * appended to the asset's path.
 *
 * Derived from the URL rather than rendered into the page so that a
 * parameter added later travels to the fragment exactly as it travels to
 * the full page — the two must be given identical input or they will answer
 * differently.
 *
 * @param {string} pageUrl
 * @returns {string}
 */
export function fragmentUrl(pageUrl) {
    const url = new URL(pageUrl, 'https://placeholder.invalid');
    const path = url.pathname.replace(/\/+$/, '');

    return path + '/apercu' + (url.search ? url.search : '');
}

/**
 * Fetch $url's fragments and swap them into the page.
 *
 * Falls back to a plain navigation on any failure — a network error, a 404,
 * a payload that is not what we expect. The visitor gets the page they asked
 * for either way; the only difference is whether it flashed.
 *
 * @param {string} pageUrl
 * @returns {Promise<void>}
 */
export function refresh(pageUrl) {
    const calendar = document.getElementById('rental-calendar-fragment');
    const estimate = document.getElementById('rental-estimate-fragment');

    if (!calendar || !estimate) {
        window.location.href = pageUrl;

        return Promise.resolve();
    }

    calendar.setAttribute('aria-busy', 'true');

    return fetch(fragmentUrl(pageUrl), { headers: { Accept: 'application/json' } })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('fragment');
            }

            return response.json();
        })
        .then(function (payload) {
            if (typeof payload.calendar !== 'string' || typeof payload.estimate !== 'string') {
                throw new Error('fragment');
            }

            calendar.innerHTML = payload.calendar;
            estimate.innerHTML = payload.estimate;
            calendar.removeAttribute('aria-busy');

            // The address bar follows what is on screen, so a reload or a
            // shared link lands on the same thing.
            window.history.replaceState({}, '', pageUrl);

            wirePage();
        })
        .catch(function () {
            window.location.href = pageUrl;
        });
}

/**
 * @param {HTMLElement} container
 * @returns {void}
 */
export function wireCalendar(container) {
    container.addEventListener('click', function (event) {
        const target = /** @type {HTMLElement} */ (event.target);
        const cell = /** @type {HTMLElement|null} */ (target && target.closest ? target.closest('[data-date]') : null);
        if (!cell) {
            return;
        }

        // An unselectable day renders as a <div>, not a <button>, so it
        // cannot be clicked at all — but a tap could still land on a cell
        // the server marked occupied, and the server is the authority on
        // that either way.
        const date = cell.dataset.date;
        if (!date) {
            return;
        }

        const next = nextSelection(
            date,
            container.dataset.arrival || '',
            container.dataset.departure || ''
        );

        refresh(selectionUrl(window.location.href, next));
    });
}

/**
 * Wire whatever is currently in the two fragment containers.
 *
 * Called again after every swap: the elements below are replaced wholesale,
 * so listeners bound to the old ones go with them.
 *
 * @returns {void}
 */
export function wirePage() {
    const container = document.getElementById('rental-calendar');
    if (container) {
        wireCalendar(container);
    }

    // The month arrows are relative links ("?month=…"), resolved against the
    // page URL exactly as the browser would.
    document.querySelectorAll('#rental-calendar-fragment a[href^="?"]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            refresh(new URL(
                /** @type {HTMLAnchorElement} */ (link).getAttribute('href') || '',
                window.location.href
            ).toString());
        });
    });

    const form = document.querySelector('#rental-estimate-fragment form[method="get"]');
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const params = new URLSearchParams();
            // A blank field means "not chosen", not "chosen as empty":
            // carrying it would put ?persons= in the address bar and read
            // back as a head count of zero.
            new FormData(/** @type {HTMLFormElement} */ (form)).forEach(function (value, key) {
                if (typeof value === 'string' && value !== '') {
                    params.append(key, value);
                }
            });

            const url = new URL(window.location.href);
            url.search = params.toString();
            refresh(url.toString());
        });
    }
}

document.addEventListener('DOMContentLoaded', wirePage);
