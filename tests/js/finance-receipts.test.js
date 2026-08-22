// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/finance-receipts.js (imported below,
// never reimplemented here) — the receipts page's live search, its
// client-side re-rendering of the receipt cards, and the associate /
// linked-movements dialogs.
//
// The client renderer (receiptCardHtml) is one half of a pair: Twig draws
// the identical cards server-side for the first paint. What this suite
// owns is the JS half's behaviour and its escaping (audit M16 — filenames
// and LLM-extracted text land in alt=/title=/data-* attributes); the two
// halves agreeing over a real server is
// tests/e2e/specs/finance-receipts.spec.js's job.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function jsonResponse(data) {
    return Promise.resolve({ json: () => Promise.resolve(data) });
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

function buildDom() {
    document.body.innerHTML = `
        <select id="receipts-account"><option value="7" selected>Compte</option><option value="8">Autre</option></select>
        <input type="text" id="receipts-search" value="">
        <input type="checkbox" id="receipts-pending-only">
        <button type="button" id="receipts-filter-btn">Filtrer</button>
        <button type="button" id="receipts-reset-btn">Réinitialiser</button>
        <div id="receipts-grid" data-account-id="7"></div>
        <div id="associate-modal"><input id="associate-search"><div id="associate-results"></div></div>
        <div id="movements-modal"><div id="movements-list"></div></div>
    `;
}

async function boot() {
    buildDom();
    vi.resetModules();
    await import('../../public/assets/js/finance-receipts.js');
}

/** One receipt row as /finance/receipts/search returns it. */
function receipt(overrides = {}) {
    return {
        id: 31,
        file_id: 99,
        mime_type: 'image/jpeg',
        original_filename: 'ticket.jpg',
        uploaded_at: '01/07/2026 14:00',
        is_pending: true,
        matching_ai_attempted: false,
        movement_count: 0,
        suggested_amount: null,
        suggested_date: null,
        suggested_label: null,
        suggested_description: null,
        suggested_source: null,
        ...overrides,
    };
}

beforeEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'tok';
    document.head.appendChild(meta);
    global.fetch = vi.fn();
    delete window.bootstrap;
});

describe('finance-receipts.js: boot guard', () => {
    it('does nothing on a page without the receipts grid', async () => {
        document.body.innerHTML = '<p>Une autre page.</p>';
        vi.resetModules();
        await import('../../public/assets/js/finance-receipts.js');
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('finance-receipts.js: live search', () => {
    it('debounces typing into ONE search after 300 ms, with the account, query, pending flag and page', async () => {
        await boot();
        vi.useFakeTimers();
        fetch.mockReturnValue(jsonResponse({ success: true, receipts: [], page: 1, total_pages: 1 }));

        const search = document.getElementById('receipts-search');
        for (const value of ['t', 'ti', 'tic']) {
            search.value = value;
            search.dispatchEvent(new Event('input'));
            vi.advanceTimersByTime(100);
        }
        expect(fetch).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledWith('/finance/receipts/search?account_id=7&q=tic&pending=0&page=1');
    });

    it('renders the cards from JSON, with quotes in the filename escaped out of the attributes', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({
            success: true,
            page: 1,
            total_pages: 1,
            receipts: [receipt({ original_filename: 'reçu "urgent" du 12.jpg' })],
        }));

        document.getElementById('receipts-pending-only').checked = true;
        document.getElementById('receipts-pending-only').dispatchEvent(new Event('change'));
        await settle();

        expect(fetch).toHaveBeenCalledWith('/finance/receipts/search?account_id=7&q=&pending=1&page=1');

        const card = document.querySelector('#receipts-grid [data-receipt-id="31"]');
        expect(card).not.toBeNull();
        // The quoted filename made it into the title attribute WHOLE — it
        // did not break out and swallow its neighbours (audit M16).
        const title = card.querySelector('[title]');
        expect(title.getAttribute('title')).toBe('reçu "urgent" du 12.jpg');
        expect(card.querySelector('img').getAttribute('alt')).toBe('reçu "urgent" du 12.jpg');
        expect(card.textContent).toContain('En attente');
    });

    it('says "Aucun reçu." when the search matches nothing', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: true, receipts: [], page: 1, total_pages: 1 }));

        document.getElementById('receipts-filter-btn').click();
        await settle();

        expect(document.getElementById('receipts-grid').textContent).toContain('Aucun reçu.');
    });

    it('renders pagination past one page, and a page link fetches that page', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: true, receipts: [receipt()], page: 1, total_pages: 3 }));

        document.getElementById('receipts-filter-btn').click();
        await settle();

        const links = document.querySelectorAll('#receipts-grid .page-link');
        expect(links).toHaveLength(3);

        fetch.mockClear();
        fetch.mockReturnValue(jsonResponse({ success: true, receipts: [], page: 2, total_pages: 3 }));
        links[1].dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        await settle();
        expect(fetch).toHaveBeenCalledWith('/finance/receipts/search?account_id=7&q=&pending=0&page=2');
    });

    it('reset clears the query and the pending filter, then refetches page 1', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: true, receipts: [], page: 1, total_pages: 1 }));
        document.getElementById('receipts-search').value = 'vieux filtre';
        document.getElementById('receipts-pending-only').checked = true;

        document.getElementById('receipts-reset-btn').click();
        await settle();

        expect(document.getElementById('receipts-search').value).toBe('');
        expect(document.getElementById('receipts-pending-only').checked).toBe(false);
        expect(fetch).toHaveBeenCalledWith('/finance/receipts/search?account_id=7&q=&pending=0&page=1');
    });
});

describe('finance-receipts.js: association dialog', () => {
    /** Render one pending receipt so the grid holds a real Associer button. */
    async function bootWithCard(extra = {}) {
        await boot();
        fetch.mockReturnValueOnce(jsonResponse({
            success: true, page: 1, total_pages: 1, receipts: [receipt(extra)],
        }));
        document.getElementById('receipts-filter-btn').click();
        await settle();
        fetch.mockClear();
    }

    it('opens with a near-date search when the receipt carries a suggested date', async () => {
        await bootWithCard({ suggested_date: '2026-07-01' });
        fetch.mockReturnValue(jsonResponse({ success: true, movements: [] }));

        document.querySelector('.associate-btn').click();
        await settle();

        expect(fetch).toHaveBeenCalledWith('/finance/movements/search?q=&account_id=7&near_date=2026-07-01');
        expect(document.getElementById('associate-results').textContent).toContain('Aucun résultat.');
    });

    it('debounces the movement search (250 ms) and associates on pick, then refetches the grid', async () => {
        await bootWithCard();
        fetch.mockReturnValueOnce(jsonResponse({ success: true, movements: [] }));
        document.querySelector('.associate-btn').click();
        await settle();
        fetch.mockClear();

        vi.useFakeTimers();
        fetch.mockReturnValueOnce(jsonResponse({
            success: true,
            movements: [{ id: 55, date: '01/07/2026', description: 'Courses camp', amount: -45.9 }],
        }));
        const search = document.getElementById('associate-search');
        search.value = 'Courses';
        search.dispatchEvent(new Event('input'));
        vi.advanceTimersByTime(250);
        vi.useRealTimers();
        await settle();

        expect(fetch).toHaveBeenCalledWith('/finance/movements/search?q=Courses&account_id=7');
        const choice = document.querySelector('#associate-results button');
        expect(choice.textContent).toBe('01/07/2026 — Courses camp — -45.90 €');

        fetch.mockClear();
        fetch
            .mockReturnValueOnce(jsonResponse({ success: true }))
            .mockReturnValueOnce(jsonResponse({ success: true, receipts: [], page: 1, total_pages: 1 }));
        choice.click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/receipts/31/associate');
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST' });
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ transaction_ids: [55], _csrf_token: 'tok' });
        // The grid refetched itself so the card's status flips.
        expect(fetch.mock.calls[1][0]).toContain('/finance/receipts/search?');
    });
});

describe('finance-receipts.js: delete', () => {
    it('asks confirm() and sends nothing on refusal; deletes with the CSRF token on acceptance', async () => {
        await boot();
        fetch.mockReturnValueOnce(jsonResponse({ success: true, page: 1, total_pages: 1, receipts: [receipt()] }));
        document.getElementById('receipts-filter-btn').click();
        await settle();
        fetch.mockClear();

        vi.spyOn(window, 'confirm').mockReturnValue(false);
        document.querySelector('.delete-btn').click();
        await settle();
        expect(fetch).not.toHaveBeenCalled();

        window.confirm.mockReturnValue(true);
        fetch
            .mockReturnValueOnce(jsonResponse({ success: true }))
            .mockReturnValueOnce(jsonResponse({ success: true, receipts: [], page: 1, total_pages: 1 }));
        document.querySelector('.delete-btn').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/receipts/31');
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'DELETE' });
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ _csrf_token: 'tok' });
    });
});

describe('finance-receipts.js: linked movements dialog', () => {
    it('lists the linked movements and unlinks one with a dissociate POST', async () => {
        await boot();
        fetch.mockReturnValueOnce(jsonResponse({
            success: true, page: 1, total_pages: 1,
            receipts: [receipt({ is_pending: false, movement_count: 1 })],
        }));
        document.getElementById('receipts-filter-btn').click();
        await settle();
        fetch.mockClear();

        fetch.mockReturnValueOnce(jsonResponse({
            success: true,
            movements: [{ id: 55, date: '01/07/2026', description: 'Courses camp', amount: -45.9 }],
        }));
        document.querySelector('.movements-btn').click();
        await settle();

        expect(fetch).toHaveBeenCalledWith('/finance/receipts/31/movements');
        const row = document.querySelector('#movements-list .unlink-movement-btn');
        expect(row).not.toBeNull();

        fetch.mockClear();
        fetch
            .mockReturnValueOnce(jsonResponse({ success: true }))
            .mockReturnValueOnce(jsonResponse({ success: true, movements: [] }))
            .mockReturnValueOnce(jsonResponse({ success: true, receipts: [], page: 1, total_pages: 1 }));
        row.click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/receipts/31/dissociate');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ transaction_id: 55, _csrf_token: 'tok' });
        // Both views refresh: the dialog's list and the grid behind it.
        expect(fetch.mock.calls[1][0]).toBe('/finance/receipts/31/movements');
        expect(fetch.mock.calls[2][0]).toContain('/finance/receipts/search?');
    });
});

describe('finance-receipts.js: PDF thumbnail fallback', () => {
    it('replaces a broken PDF thumbnail with the placeholder icon instead of a broken image', async () => {
        await boot();
        fetch.mockReturnValueOnce(jsonResponse({
            success: true, page: 1, total_pages: 1,
            receipts: [receipt({ mime_type: 'application/pdf', original_filename: 'facture.pdf' })],
        }));
        document.getElementById('receipts-filter-btn').click();
        await settle();

        const thumbnail = document.querySelector('img.pdf-thumbnail');
        expect(thumbnail).not.toBeNull();
        thumbnail.dispatchEvent(new Event('error'));

        expect(document.querySelector('img.pdf-thumbnail')).toBeNull();
        expect(document.querySelector('#receipts-grid .bi-file-earmark-pdf')).not.toBeNull();
    });
});
