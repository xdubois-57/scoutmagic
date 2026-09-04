/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The door screen of « Scanner un billet » (/news/scan/{form_id}).
//
// One field typed into over and over for two hours, and a verdict redrawn
// under it each time — which is why the verdict is client-rendered from
// the lookup route's JSON rather than reloaded as a page. The counters and
// the empty frames are server-rendered, so the page is legible and says
// what it is before any of this runs.
//
// Everything goes through window.ScoutMagicApi (getJson/postJson), never a
// bare fetch(): that is where the CSRF header, the JSON Accept header and
// the never-throws error shape live.
//
// The one write this screen makes MARKS THE ENTRY AND NEVER TOUCHES THE
// RECEIVABLE. No extra confirmation is asked for on an unpaid ticket: it
// would slow the door at the exact moment it must not, to produce a trace
// the responses screen gives for free. The payment state is SHOWN so the
// staff can ask out loud; the gesture stays single.
(function () {
    const api = window.ScoutMagicApi;
    if (!api) return;

    const config = api.pageData('news-scan-data');
    const queryInput = /** @type {HTMLInputElement|null} */ (document.getElementById('news-scan-query'));
    const verdictEl = document.getElementById('news-scan-verdict');
    const matchesEl = document.getElementById('news-scan-matches');
    const countersEl = document.getElementById('news-scan-counters');
    if (!config || !queryInput || !verdictEl || !matchesEl) return;

    /**
     * Redraws the three head-of-screen counters after a validation.
     *
     * @param {{sold: number, entered: number, expected: number}} counters
     */
    function renderCounters(counters) {
        if (!countersEl) return;
        ['sold', 'entered', 'expected'].forEach(function (key) {
            const el = countersEl.querySelector('[data-counter="' + key + '"]');
            if (el) el.textContent = String(counters[key]);
        });
    }

    /**
     * What the holder booked — « Repas adulte × 2 » — or a bare seat
     * count on a form that declares no quantity field at all.
     *
     * @param {{seats: Array<{label: string, quantity: string}>, seat_total: number}} verdict
     * @returns {string}
     */
    function seatsHtml(verdict) {
        if (!verdict.seats || verdict.seats.length === 0) {
            const plural = verdict.seat_total > 1 ? 's' : '';
            return '<li class="d-flex justify-content-between gap-3"><span>Réservation</span>'
                + '<span class="fw-semibold">' + verdict.seat_total + ' place' + plural + '</span></li>';
        }

        return verdict.seats.map(function (seat) {
            return '<li class="d-flex justify-content-between gap-3">'
                + '<span>' + api.escapeHtml(seat.label) + '</span>'
                + '<span class="fw-semibold">× ' + api.escapeHtml(seat.quantity) + '</span></li>';
        }).join('');
    }

    /**
     * The payment block — and nothing at all when no payment is expected.
     *
     * A ticketed event can be free, and then no receivable exists.
     * Showing a green « payé » on one would invite somebody to go looking
     * for a receivable that was never created.
     *
     * @param {{payment: ({status: string, amount_due: number, amount_received: number, receivable_id: number}|null)}} verdict
     * @returns {string}
     */
    function paymentHtml(verdict) {
        if (!verdict.payment) return '';

        const due = formatMoney(verdict.payment.amount_due);
        if (verdict.payment.status === 'paid') {
            return '<div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-0 mt-3" role="status">'
                + '<i class="bi bi-check-circle" aria-hidden="true"></i>'
                + '<span>Payé — ' + due + '</span></div>';
        }

        const received = formatMoney(verdict.payment.amount_received);
        const heading = verdict.payment.status === 'partial'
            ? 'Paiement partiel — ' + received + ' reçus sur ' + due
            : 'Pas de paiement reçu — ' + due;

        // The visitor is standing there: it is the best moment to pay.
        // The same full-screen QR as an unpaid receivable on a member's
        // page — never a second layout of the same thing. That page
        // enforces its own account visibility, which is the boundary; a
        // link here is a convenience, not a grant.
        const payButton = '<a class="btn btn-sm btn-outline-secondary w-100 mt-2"'
            + ' href="/finance/receivables/' + encodeURIComponent(String(verdict.payment.receivable_id)) + '/qr">'
            + '<i class="bi bi-qr-code" aria-hidden="true"></i> Faire payer maintenant</a>';

        return '<div class="alert alert-warning py-2 mb-0 mt-3" role="status">'
            + '<div class="d-flex align-items-start gap-2">'
            + '<i class="bi bi-exclamation-triangle mt-1" aria-hidden="true"></i>'
            + '<div><p class="fw-semibold mb-1">' + heading + '</p>'
            + '<p class="small mb-0">Un virement récent peut ne pas encore apparaître. '
            + "Vous pouvez valider l'entrée malgré tout : la créance reste ouverte.</p></div></div>"
            + payButton + '</div>';
    }

    /**
     * Cents to « 46,00 € », the way the money_cents Twig filter writes it.
     *
     * @param {number} cents
     * @returns {string}
     */
    function formatMoney(cents) {
        return (cents / 100).toFixed(2).replace('.', ',') + ' €';
    }

    /**
     * The verdict card.
     *
     * Reading order is fixed and is the point: the holder, what they
     * booked, THE PAYMENT, then the ticket's own state. Payment before
     * ticket because it is the only piece of information that can change
     * the decision — letting somebody in who has not paid is a choice,
     * not an accident.
     *
     * @param {Object} verdict
     * @returns {string}
     */
    function verdictHtml(verdict) {
        if (verdict.status === 'not_found') {
            return card('danger', 'Billet introuvable', 'Aucune réservation ne porte cette référence',
                'Vérifiez la saisie — les caractères ambigus sont exclus des références. '
                + 'Ou cherchez la personne par son nom ou son adresse e-mail dans le champ ci-dessus.');
        }

        if (verdict.status === 'other_event') {
            // Naming the event rather than answering « introuvable »: an
            // « introuvable » would send somebody looking for a fault that
            // does not exist.
            const other = api.escapeHtml(verdict.article_title || 'un autre évènement');
            const when = verdict.event_date ? ' du ' + api.escapeHtml(frenchDate(verdict.event_date)) : '';
            return card('warning', 'Autre évènement', 'Ce billet est valide, mais pour ' + other + when,
                "Il ne donne pas accès à la soirée de ce soir. Vérifiez que la personne ne s'est pas "
                + "trompée d'e-mail — elle a peut-être aussi réservé ici.");
        }

        const used = verdict.status === 'used';
        const tone = used ? 'secondary' : 'success';
        const heading = used ? 'Déjà utilisé à ' + api.escapeHtml(timeOf(verdict.used_at)) : 'Billet valide';

        return '<article class="card border-' + tone + '">'
            + '<div class="card-header bg-' + tone + '-subtle">'
            + '<p class="small text-uppercase fw-bold mb-1">' + heading + '</p>'
            + '<p class="h5 mb-0">' + api.escapeHtml(verdict.holder || '') + '</p>'
            + '<p class="small font-monospace text-body-secondary mb-0">' + api.escapeHtml(verdict.reference || '') + '</p>'
            + '</div>'
            + '<div class="card-body">'
            + '<p class="small text-body-secondary mb-1">Réservé</p>'
            + '<ul class="list-unstyled mb-0">' + seatsHtml(verdict) + '</ul>'
            + paymentHtml(verdict)
            + '</div>'
            + '<div class="card-footer">'
            + '<button type="button" class="btn w-100 ' + (used ? 'btn-outline-secondary' : 'btn-primary btn-lg')
            + '" data-scan-validate="' + (used ? '0' : '1') + '" data-response-id="' + verdict.response_id + '">'
            + '<i class="bi ' + (used ? 'bi-arrow-counterclockwise' : 'bi-check-lg') + '" aria-hidden="true"></i> '
            + (used ? 'Annuler cette entrée' : "Valider l'entrée")
            + '</button>'
            + '</div></article>';
    }

    /**
     * @param {string} tone
     * @param {string} kicker
     * @param {string} title
     * @param {string} body
     * @returns {string}
     */
    function card(tone, kicker, title, body) {
        return '<div class="card border-' + tone + '"><div class="card-body bg-' + tone + '-subtle">'
            + '<p class="small text-uppercase fw-bold mb-1">' + kicker + '</p>'
            + '<p class="h6 mb-1">' + title + '</p>'
            + '<p class="small mb-0">' + body + '</p>'
            + '</div></div>';
    }

    /**
     * `2026-03-14 19:42:00` → `19:42`.
     *
     * @param {string|null} stamp
     * @returns {string}
     */
    function timeOf(stamp) {
        const parts = /(\d{2}):(\d{2})/.exec(stamp || '');

        return parts ? parts[1] + 'h' + parts[2] : '';
    }

    const MONTHS = [
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /**
     * `2026-03-14` → `14 mars 2026`, the SAME words core's `french_date`
     * Twig filter writes: this page already prints its own event's date
     * through that filter, and two spellings on one screen read as two
     * different dates.
     *
     * Not a `Date`: the value is a calendar day, and parsing it as an
     * instant shifts it by one west of Greenwich.
     *
     * @param {string} isoDate
     * @returns {string}
     */
    function frenchDate(isoDate) {
        const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);
        if (!parts) return isoDate;

        return Number.parseInt(parts[3], 10) + ' ' + MONTHS[Number.parseInt(parts[2], 10) - 1] + ' ' + parts[1];
    }

    /**
     * Several people match what was typed — a list to choose from rather
     * than a guess. « Quelqu'un venu à la place de celui qui a réservé »
     * is an ordinary evening.
     *
     * @param {Array<{response_id: number, label: string, seat_total: number, used_at: (string|null)}>} matches
     * @returns {string}
     */
    function matchesHtml(matches) {
        return matches.map(function (match) {
            const state = match.used_at
                ? '<span class="badge text-bg-secondary">Entré ' + api.escapeHtml(timeOf(match.used_at)) + '</span>'
                : '';
            return '<button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2"'
                + ' data-scan-pick="' + match.response_id + '">'
                + '<span class="flex-grow-1 text-start">' + api.escapeHtml(match.label)
                + ' <span class="small text-body-secondary">— ' + match.seat_total + ' place'
                + (match.seat_total > 1 ? 's' : '') + '</span></span>' + state + '</button>';
        }).join('');
    }

    /** @param {Object|null} verdict */
    function showVerdict(verdict) {
        verdictEl.innerHTML = verdict ? verdictHtml(verdict) : '';
    }

    /** @param {Array<Object>} matches */
    function showMatches(matches) {
        matchesEl.innerHTML = matches.length > 0 ? matchesHtml(matches) : '';
    }

    /**
     * Resolves whatever is in the field — a reference, a name, an
     * address. Exposed on the global so the QR reader can hand a scanned
     * payload straight to it rather than re-implementing the round trip.
     *
     * @param {string} query
     */
    async function lookup(query) {
        if (query.trim() === '') {
            showVerdict(null);
            showMatches([]);
            return;
        }

        const res = await api.getJson(config.lookupUrl + '?q=' + encodeURIComponent(query));
        if (!res.data?.success) {
            showMatches([]);
            showVerdict({ status: 'not_found' });
            return;
        }

        showMatches(res.data.matches || []);
        showVerdict(res.data.verdict);
    }

    /**
     * @param {number} responseId
     * @param {boolean} used
     */
    async function validate(responseId, used) {
        // postJson carries the CSRF token itself, in the body and in the
        // X-CSRF-Token header — nothing to add here.
        const res = await api.postJson(config.validateUrl, {
            response_id: responseId,
            used: used ? '1' : '0',
        });

        if (!res.data?.success) {
            window.ScoutMagicToast?.show(
                res.data?.error || "L'entrée n'a pas pu être enregistrée. Vérifiez la connexion.",
                { variant: 'error' }
            );
            return;
        }

        showVerdict(res.data.verdict);
        renderCounters(res.data.counters);
    }

    queryInput.addEventListener('input', api.debounce(function () {
        lookup(queryInput.value);
    }, 250));

    // A hardware scanner and the on-screen keyboard both end on Enter, and
    // waiting out the debounce after that is a quarter second of a queue.
    queryInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        lookup(queryInput.value);
    });

    verdictEl.addEventListener('click', function (event) {
        const button = /** @type {HTMLElement|null} */ (event.target instanceof Element
            ? event.target.closest('[data-scan-validate]')
            : null);
        if (!button) return;

        const responseId = Number.parseInt(button.dataset.responseId || '0', 10);
        api.withDisabled(/** @type {HTMLButtonElement} */ (button), function () {
            return validate(responseId, button.dataset.scanValidate === '1');
        });
    });

    matchesEl.addEventListener('click', async function (event) {
        const button = /** @type {HTMLElement|null} */ (event.target instanceof Element
            ? event.target.closest('[data-scan-pick]')
            : null);
        if (!button) return;

        const res = await api.getJson(config.lookupUrl + '?response_id=' + encodeURIComponent(button.dataset.scanPick || ''));
        if (res.data?.success) {
            showMatches([]);
            showVerdict(res.data.verdict);
        }
    });

    // What the QR reader (IT-04) hands its scanned payload to, rather than
    // re-implementing the round trip on its own.
    window.ScoutMagicNewsScan = {
        lookup: lookup,
        setQuery: function (value) {
            queryInput.value = value;
            return lookup(value);
        },
    };
})();
