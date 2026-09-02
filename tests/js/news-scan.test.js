// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP
// server, no network. Exercises the REAL implementation in
// public/assets/js/news-scan.js (imported below, never reimplemented
// here) — the door screen of « Scanner un billet ».
//
// The file is an IIFE that reads the DOM at import time, so each test
// builds its DOM and its ScoutMagicApi stub first, then imports the module
// via vi.resetModules() + await import() (the tests/js/nav-rail.test.js
// pattern).
//
// window.ScoutMagicApi is stubbed rather than the global fetch: the
// production file is required to go through it, and a stub at that seam is
// what proves it does.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** @type {{getJson: any, postJson: any}} */
let api;

function buildScreen({ sold = 6, entered = 0, expected = 6 } = {}) {
    document.body.innerHTML = `
        <div id="news-scan-counters">
            <p data-counter="sold">${sold}</p>
            <p data-counter="entered">${entered}</p>
            <p data-counter="expected">${expected}</p>
        </div>
        <input id="news-scan-query" type="search">
        <div id="news-scan-matches"></div>
        <div id="news-scan-verdict"></div>
        <script type="application/json" id="news-scan-data">
        {"formId":7,"lookupUrl":"/news/scan/7/lookup","validateUrl":"/news/scan/7/validate","csrfToken":"tok","expectsPayment":true}
        </script>
    `;
}

function stubApi() {
    api = {
        getJson: vi.fn().mockResolvedValue({ ok: true, status: 200, data: { success: true, verdict: null, matches: [] } }),
        postJson: vi.fn().mockResolvedValue({ ok: true, status: 200, data: { success: true } }),
        escapeHtml: (v) => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'),
        // Immediate rather than timed: the debounce belongs to api.js and
        // has its own test there; what matters here is what gets called.
        debounce: (fn) => fn,
        pageData: (id) => JSON.parse(document.getElementById(id).textContent),
        withDisabled: (control, run) => run(),
    };
    // @ts-ignore — the site's frontend toolbox.
    window.ScoutMagicApi = api;
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/news-scan.js');
}

function verdict(overrides = {}) {
    return Object.assign({
        status: 'valid',
        response_id: 42,
        reference: 'X7K2-9QMF-A3',
        holder: 'Famille Roskam',
        article_title: 'Souper spaghetti',
        event_date: '2026-03-14',
        seats: [{ label: 'Repas adulte', quantity: '2' }, { label: 'Repas enfant', quantity: '2' }],
        seat_total: 4,
        payment: null,
        used_at: null,
    }, overrides);
}

/** @returns {HTMLInputElement} */
function queryInput() {
    return /** @type {HTMLInputElement} */ (document.getElementById('news-scan-query'));
}

function verdictHtml() {
    return document.getElementById('news-scan-verdict').innerHTML;
}

beforeEach(() => {
    buildScreen();
    stubApi();
});

describe('resolving what was typed', () => {
    it('asks the lookup route and never a bare fetch', async () => {
        await load();
        queryInput().value = 'X7K2-9QMF-A3';
        queryInput().dispatchEvent(new Event('input'));

        await vi.waitFor(() => expect(api.getJson).toHaveBeenCalled());
        expect(api.getJson.mock.calls[0][0]).toBe('/news/scan/7/lookup?q=X7K2-9QMF-A3');
    });

    it('resolves at once on Enter rather than waiting out the debounce', async () => {
        // A hardware scanner and the on-screen keyboard both end on Enter,
        // and a quarter second is a quarter second of a queue.
        await load();
        queryInput().value = 'ROSKAM';
        queryInput().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));

        await vi.waitFor(() => expect(api.getJson).toHaveBeenCalled());
    });

    it('clears the screen on an empty field instead of asking', async () => {
        await load();
        queryInput().value = '   ';
        queryInput().dispatchEvent(new Event('input'));

        await Promise.resolve();
        expect(api.getJson).not.toHaveBeenCalled();
        expect(verdictHtml()).toBe('');
    });
});

describe('the verdict', () => {
    async function show(v) {
        api.getJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, verdict: v, matches: [] } });
        await load();
        // @ts-ignore — the entry point the QR reader also uses.
        await window.ScoutMagicNewsScan.lookup('anything');
    }

    it('names the holder, the reference and every seat booked', async () => {
        await show(verdict());

        const html = verdictHtml();
        expect(html).toContain('Famille Roskam');
        expect(html).toContain('X7K2-9QMF-A3');
        expect(html).toContain('Repas adulte');
        expect(html).toContain('× 2');
        expect(html).toContain("Valider l'entrée");
    });

    it('shows nothing about payment when none is expected', async () => {
        // A ticketed event can be free, and then no receivable exists.
        // A green « payé » would invite somebody to go looking for one.
        await show(verdict({ payment: null }));

        expect(verdictHtml()).not.toContain('Payé');
        expect(verdictHtml()).not.toContain('Faire payer');
    });

    it('shows the amount received and the way to pay on an unpaid ticket', async () => {
        await show(verdict({ payment: { status: 'unpaid', amount_due: 4600, amount_received: 0, receivable_id: 55 } }));

        const html = verdictHtml();
        expect(html).toContain('46,00 €');
        // The same full-screen QR as an unpaid receivable on a member's
        // page — never a second layout of the same thing.
        expect(html).toContain('/finance/receivables/55/qr');
        // And still one gesture: validating is offered without any extra
        // confirmation, because a confirmation would slow the door.
        expect(html).toContain("Valider l'entrée");
    });

    it('confirms a paid ticket without offering to charge again', async () => {
        await show(verdict({ payment: { status: 'paid', amount_due: 4600, amount_received: 4600, receivable_id: 55 } }));

        expect(verdictHtml()).toContain('Payé — 46,00 €');
        expect(verdictHtml()).not.toContain('Faire payer');
    });

    it('says when a used ticket was used, and offers to take it back', async () => {
        await show(verdict({ status: 'used', used_at: '2026-03-14 19:42:00' }));

        const html = verdictHtml();
        // What distinguishes a mis-scan from an attempted double entry.
        expect(html).toContain('19h42');
        expect(html).toContain('Annuler cette entrée');
    });

    it('names the other event rather than saying « introuvable »', async () => {
        await show(verdict({ status: 'other_event', article_title: 'Marché de Noël', event_date: '2026-12-08' }));

        const html = verdictHtml();
        expect(html).toContain('Marché de Noël');
        expect(html).toContain('08/12/2026');
        expect(html).not.toContain('Aucune réservation');
    });

    it('tells somebody what to try next when nothing matches', async () => {
        await show(verdict({ status: 'not_found' }));

        expect(verdictHtml()).toContain('Aucune réservation ne porte cette référence');
    });

    it('escapes what a visitor typed into the form', async () => {
        await show(verdict({ holder: '<img src=x onerror=alert(1)>' }));

        expect(verdictHtml()).not.toContain('<img src=x');
        expect(verdictHtml()).toContain('&lt;img');
    });
});

describe('several people matching', () => {
    it('offers a list to choose from rather than guessing', async () => {
        api.getJson.mockResolvedValue({
            ok: true,
            status: 200,
            data: {
                success: true,
                verdict: null,
                matches: [
                    { response_id: 1, label: 'Famille Roskam', seat_total: 4, used_at: null },
                    { response_id: 2, label: 'Famille Delvaux', seat_total: 2, used_at: '2026-03-14 19:31:00' },
                ],
            },
        });
        await load();
        // @ts-ignore
        await window.ScoutMagicNewsScan.lookup('famille');

        const html = document.getElementById('news-scan-matches').innerHTML;
        expect(html).toContain('Famille Roskam');
        expect(html).toContain('Famille Delvaux');
        expect(html).toContain('Entré 19h31');
        expect(verdictHtml()).toBe('');
    });
});

describe('validating', () => {
    it('posts the entry and redraws the counters', async () => {
        api.getJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, verdict: verdict(), matches: [] } });
        api.postJson.mockResolvedValue({
            ok: true,
            status: 200,
            data: {
                success: true,
                verdict: verdict({ status: 'used', used_at: '2026-03-14 19:42:00' }),
                counters: { sold: 6, entered: 4, expected: 2 },
            },
        });
        await load();
        // @ts-ignore
        await window.ScoutMagicNewsScan.lookup('X7K2-9QMF-A3');

        /** @type {HTMLButtonElement} */
        (document.querySelector('[data-scan-validate]')).click();

        await vi.waitFor(() => expect(api.postJson).toHaveBeenCalled());
        expect(api.postJson.mock.calls[0][0]).toBe('/news/scan/7/validate');
        expect(api.postJson.mock.calls[0][1]).toEqual({ response_id: 42, used: '1' });

        await vi.waitFor(() => {
            expect(document.querySelector('[data-counter="entered"]').textContent).toBe('4');
        });
        expect(document.querySelector('[data-counter="expected"]').textContent).toBe('2');
    });

    it('posts the un-marking when the ticket was already used', async () => {
        api.getJson.mockResolvedValue({
            ok: true,
            status: 200,
            data: { success: true, verdict: verdict({ status: 'used', used_at: '2026-03-14 19:42:00' }), matches: [] },
        });
        api.postJson.mockResolvedValue({
            ok: true,
            status: 200,
            data: { success: true, verdict: verdict(), counters: { sold: 6, entered: 0, expected: 6 } },
        });
        await load();
        // @ts-ignore
        await window.ScoutMagicNewsScan.lookup('X7K2-9QMF-A3');

        /** @type {HTMLButtonElement} */
        (document.querySelector('[data-scan-validate]')).click();

        await vi.waitFor(() => expect(api.postJson).toHaveBeenCalled());
        expect(api.postJson.mock.calls[0][1].used).toBe('0');
    });

    it('says so out loud when the write did not go through', async () => {
        // The one thing this screen cannot do offline, and the animateur
        // has to know the entry was not recorded.
        const show = vi.fn();
        // @ts-ignore
        window.ScoutMagicToast = { show };
        api.getJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, verdict: verdict(), matches: [] } });
        api.postJson.mockResolvedValue({ ok: false, status: 0, data: null });
        await load();
        // @ts-ignore
        await window.ScoutMagicNewsScan.lookup('X7K2-9QMF-A3');

        /** @type {HTMLButtonElement} */
        (document.querySelector('[data-scan-validate]')).click();

        await vi.waitFor(() => expect(show).toHaveBeenCalled());
        expect(show.mock.calls[0][1]).toEqual({ variant: 'error' });
    });
});
