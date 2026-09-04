/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The event search of « Scanner un billet » (/news/scan).
//
// Same shape as associating a receipt in finance: a JSON route through
// window.ScoutMagicApi.getJson(), never a bare fetch(), results redrawn as
// one types. But picking a result NAVIGATES — a plain <a href> this file
// never intercepts — because changing event is a rare gesture, once an
// evening, and the shareable, reload-proof address is the whole point of
// putting the event in the URL.
//
// The rows this rebuilds mirror @news/scan/_event_rows.html.twig, which is
// what the server renders on first load. The two are changed together.
(function () {
    const input = /** @type {HTMLInputElement|null} */ (document.getElementById('news-scan-event-search'));
    const results = document.getElementById('news-scan-event-results');
    if (!input || !results || !window.ScoutMagicApi) return;

    const api = window.ScoutMagicApi;

    /**
     * One row, in the same shape as the server-rendered partial.
     *
     * @param {{form_id: number, title: string, event_date: (string|null), event_location: (string|null), seats: number}} event
     * @returns {string}
     */
    function rowHtml(event) {
        const when = event.event_date ? frenchDate(event.event_date) : 'date non renseignée';
        const where = event.event_location ? ' — ' + api.escapeHtml(event.event_location) : '';
        const plural = event.seats > 1 ? 's' : '';

        return '<a href="/news/scan/' + encodeURIComponent(String(event.form_id)) + '"'
            + ' class="list-group-item list-group-item-action d-flex align-items-center gap-3">'
            + '<i class="bi bi-ticket-perforated fs-4 text-body-secondary" aria-hidden="true"></i>'
            + '<span class="flex-grow-1 min-w-0">'
            + '<span class="d-block fw-semibold text-truncate">' + api.escapeHtml(event.title) + '</span>'
            + '<span class="d-block small text-body-secondary text-truncate">' + api.escapeHtml(when) + where + '</span>'
            + '</span>'
            + '<span class="small text-body-secondary text-nowrap">' + event.seats + ' place' + plural + '</span>'
            + '</a>';
    }

    const MONTHS = [
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /**
     * `2026-03-14` → `14 mars 2026`, the SAME words core's `french_date`
     * Twig filter writes: this function redraws rows the server rendered
     * with that filter on first load, and two spellings of one date in
     * one list reads as two different dates.
     *
     * Deliberately not a `Date`: the value is a calendar day, and parsing
     * it as an instant is how a date shifts by one west of Greenwich.
     *
     * @param {string} isoDate
     * @returns {string}
     */
    function frenchDate(isoDate) {
        const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);
        if (!parts) return isoDate;

        return Number.parseInt(parts[3], 10) + ' ' + MONTHS[Number.parseInt(parts[2], 10) - 1] + ' ' + parts[1];
    }

    async function search() {
        const res = await api.getJson('/news/scan/events?q=' + encodeURIComponent(input.value));
        if (!res.data?.success) return;

        results.innerHTML = res.data.events.length === 0
            ? '<p class="text-body-secondary fst-italic mb-0 px-1">Aucun évènement ne correspond.</p>'
            : res.data.events.map(rowHtml).join('');
    }

    input.addEventListener('input', api.debounce(search, 300));
})();
