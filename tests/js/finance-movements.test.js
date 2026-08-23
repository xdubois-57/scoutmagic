// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/finance-movements.js (imported below,
// never reimplemented here) — the movements list page's details dialog,
// its receipts dialog and the receipt-suggestion buttons.
//
// The page renders every movement TWICE by design: a desktop
// <tr class="movement-row"> and a mobile <div class="movement-row"> card.
// A binding that selected 'tr.movement-row' once left every mobile card
// inert — a phone could not categorize or comment a movement at all — so
// the delegated click/keydown listeners are covered here from BOTH views,
// which is also what tests/Modules/Finance/MovementsListRenderingTest.php
// guards on the server-rendered half.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** One JSON answer as ScoutMagicApi's envelope reads it. */
function jsonResponse(data, { ok = true, status = 200 } = {}) {
    return Promise.resolve({ ok, status, json: () => Promise.resolve(data) });
}

async function settle() {
    // A macrotask hop drains the whole microtask queue first — the
    // ScoutMagicApi envelope adds promise layers a fixed number of
    // Promise.resolve() awaits kept undercounting. Requires real timers.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// The mobile card (#42, uncategorized, no counterparty account, no extra
// details) and the desktop row (#43, auto-categorized, every field filled)
// — one of each so both views and both halves of setDetailRow() are real.
function buildDom() {
    document.body.innerHTML = `
        <div class="d-md-none d-flex flex-column gap-2 mb-3">
            <div class="border rounded-3 p-3 movement-row bg-warning-subtle"
                 role="button" tabindex="0"
                 data-id="42"
                 data-date="2026-08-01"
                 data-label="VIREMENT SEPA BOULANGERIE"
                 data-bank-reference="REF-1"
                 data-amount="-12.5"
                 data-source="import"
                 data-category-id=""
                 data-category-source=""
                 data-comment=""
                 data-fiscal-year-id="1"
                 data-counterparty-name="Boulangerie"
                 data-counterparty-account=""
                 data-extra-details="">
                <span class="badge text-bg-warning">À catégoriser</span>
            </div>
        </div>
        <table class="table"><tbody>
            <tr data-id="43"
                data-date="2026-08-02"
                data-label="ACHAT CARTE"
                data-bank-reference=""
                data-amount="1234.5"
                data-source="manual"
                data-category-id="3"
                data-category-source="auto"
                data-comment="Vieux commentaire"
                data-fiscal-year-id="2"
                data-counterparty-name="Delhaize"
                data-counterparty-account="BE00 1111 2222 3333"
                data-extra-details="Communication : camp"
                class="movement-row">
                <td>ACHAT CARTE</td>
                <td>
                    <button type="button" class="attachment-btn" data-id="43">Reçus</button>
                    <button type="button" class="suggest-associate-btn"
                            data-movement-id="43" data-attachment-id="31">Associer ce reçu ?</button>
                    <a href="/finance/movements/export">Exporter</a>
                </td>
            </tr>
        </tbody></table>
        <div id="attachments-modal">
            <div id="attachments-list"></div>
            <select id="attachments-add-select">
                <option value="">Associer un reçu en attente…</option>
                <option value="31">ticket.jpg</option>
            </select>
            <button type="button" id="attachments-add-btn">Associer</button>
            <input type="file" id="attachments-upload-input">
            <button type="button" id="attachments-upload-btn">Téléverser et associer</button>
            <div class="text-danger small mt-2 d-none" id="attachments-upload-error"></div>
        </div>
        <div id="movement-details-modal">
            <dl class="row small mb-3">
                <dt class="col-4">Date</dt><dd class="col-8" id="md-date"></dd>
                <dt class="col-4">Libellé</dt><dd class="col-8" id="md-label"></dd>
                <dt class="col-4">Référence bancaire</dt><dd class="col-8" id="md-bank-reference"></dd>
                <dt class="col-4">Montant</dt><dd class="col-8" id="md-amount"></dd>
                <dt class="col-4">Origine</dt><dd class="col-8" id="md-source"></dd>
                <dt class="col-4">Contrepartie</dt><dd class="col-8" id="md-counterparty-name"></dd>
                <dt class="col-4">Compte contrepartie</dt><dd class="col-8" id="md-counterparty-account"></dd>
                <dt class="col-4">Autres informations</dt><dd class="col-8" id="md-extra-details"></dd>
            </dl>
            <select id="md-category">
                <option value="">À catégoriser</option>
                <option value="3">Intendance</option>
            </select>
            <div class="form-text small d-none" id="md-category-auto">Catégorie attribuée automatiquement</div>
            <input type="text" id="md-comment">
            <select id="md-fiscal-year">
                <option value="1">2025-2026</option>
                <option value="2">2026-2027</option>
            </select>
            <button type="button" id="md-save-btn">Enregistrer</button>
        </div>
    `;
}

async function boot() {
    buildDom();
    vi.resetModules();
    // The real toolboxes — finance-movements.js aliases
    // window.ScoutMagicApi.escapeHtml at import time and fetches/toasts
    // through the shared globals (base.html.twig guarantees this load
    // order in production). The confirmation dialog is stubbed instead:
    // what this suite owns is *that* the destructive action asks and obeys
    // the answer, not how confirm.js draws its modal (tests/js/confirm.test.js).
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/finance-movements.js');
}

const mobileCard = () => document.querySelector('div.movement-row');
const desktopRow = () => document.querySelector('tr.movement-row');
const detail = (id) => document.getElementById(id);

/** The one toast currently on screen, as text. */
const toastText = () => {
    const body = document.querySelector('.toast-body');
    return body ? body.textContent : null;
};

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
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    // Every success path reloads the page; jsdom has no navigation.
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { reload: vi.fn(), href: 'http://localhost/finance/movements' },
    });
});

describe('finance-movements.js: boot guard', () => {
    it('does nothing on a page without the two dialogs', async () => {
        // The handlers used to dereference document.getElementById('md-save-btn')
        // unconditionally, which throws on any page this file reaches
        // without the modal markup (the "Aucun compte visible" empty state).
        document.body.innerHTML = '<div class="movement-row" data-id="1">Une autre page.</div>';
        vi.resetModules();
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
        await expect(import('../../public/assets/js/finance-movements.js')).resolves.toBeDefined();

        expect(() => document.querySelector('.movement-row').click()).not.toThrow();
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('finance-movements.js: opening the details dialog', () => {
    it('opens from a desktop <tr class="movement-row">', async () => {
        await boot();

        desktopRow().dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(detail('md-date').textContent).toBe('2026-08-02');
        expect(detail('md-label').textContent).toBe('ACHAT CARTE');
        expect(detail('md-source').textContent).toBe('Manuel');
        expect(document.getElementById('md-category').value).toBe('3');
        expect(document.getElementById('md-comment').value).toBe('Vieux commentaire');
        expect(document.getElementById('md-fiscal-year').value).toBe('2');
        // categorySource === 'auto' — the "attribuée automatiquement" note shows.
        expect(detail('md-category-auto').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('movement-details-modal').classList.contains('show')).toBe(true);
    });

    it('opens from a mobile <div class="movement-row"> card — the regression that motivated the delegated listener', async () => {
        await boot();

        mobileCard().dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(detail('md-date').textContent).toBe('2026-08-01');
        expect(detail('md-label').textContent).toBe('VIREMENT SEPA BOULANGERIE');
        expect(detail('md-bank-reference').textContent).toBe('REF-1');
        expect(detail('md-source').textContent).toBe('Import bancaire');
        expect(document.getElementById('md-category').value).toBe('');
        expect(document.getElementById('md-fiscal-year').value).toBe('1');
        expect(detail('md-category-auto').classList.contains('d-none')).toBe(true);
    });

    it('opens on Enter and on Space from the mobile card, and swallows the Space scroll', async () => {
        await boot();

        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        mobileCard().dispatchEvent(enter);
        expect(detail('md-date').textContent).toBe('2026-08-01');
        expect(enter.defaultPrevented).toBe(true);

        detail('md-date').textContent = '';
        const space = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
        mobileCard().dispatchEvent(space);
        expect(detail('md-date').textContent).toBe('2026-08-01');
        expect(space.defaultPrevented).toBe(true);
    });

    it('ignores any other key on the card', async () => {
        await boot();

        mobileCard().dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));

        expect(detail('md-date').textContent).toBe('');
    });

    it('leaves the dialog closed when the click landed on a control inside the row', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: true, attachments: [] }));

        // The receipts button and the export link keep their own behavior.
        document.querySelector('.attachment-btn').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        const link = document.querySelector('tr.movement-row a');
        link.addEventListener('click', (e) => e.preventDefault()); // jsdom cannot navigate
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        await settle();

        expect(detail('md-date').textContent).toBe('');
    });
});

describe('finance-movements.js: details rendering', () => {
    it('formats the amount as fr-BE money with two decimals and a euro sign', async () => {
        await boot();

        mobileCard().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(detail('md-amount').textContent).toBe('-12,50 €');

        desktopRow().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        // Narrow no-break space as the thousands separator, comma as the
        // decimal mark — the same rendering as the |money Twig filter.
        expect(detail('md-amount').textContent).toBe('1 234,50 €');
    });

    it('hides a detail row — its <dd> AND its <dt> — when the movement has no value for it', async () => {
        await boot();

        mobileCard().dispatchEvent(new MouseEvent('click', { bubbles: true }));

        // Empty: "Compte contrepartie" and "Autres informations".
        for (const id of ['md-counterparty-account', 'md-extra-details']) {
            expect(detail(id).classList.contains('d-none')).toBe(true);
            expect(detail(id).previousElementSibling.classList.contains('d-none')).toBe(true);
            expect(detail(id).textContent).toBe('');
        }
        // Filled: "Contrepartie" stays visible with its label.
        expect(detail('md-counterparty-name').textContent).toBe('Boulangerie');
        expect(detail('md-counterparty-name').classList.contains('d-none')).toBe(false);
        expect(detail('md-counterparty-name').previousElementSibling.classList.contains('d-none')).toBe(false);
    });

    it('shows a hidden row again when the next movement does fill it', async () => {
        await boot();

        mobileCard().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(detail('md-extra-details').classList.contains('d-none')).toBe(true);

        desktopRow().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(detail('md-extra-details').textContent).toBe('Communication : camp');
        expect(detail('md-extra-details').classList.contains('d-none')).toBe(false);
        // An absent bank reference keeps its em dash rather than disappearing.
        expect(detail('md-bank-reference').textContent).toBe('—');
    });
});

describe('finance-movements.js: saving the details dialog', () => {
    it('PATCHes the movement with the category, comment, exercice and CSRF token', async () => {
        await boot();
        desktopRow().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        document.getElementById('md-category').value = '';
        document.getElementById('md-comment').value = 'Payé par la trésorière';
        document.getElementById('md-fiscal-year').value = '1';

        fetch.mockReturnValue(jsonResponse({ success: true }));
        document.getElementById('md-save-btn').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/movements/43');
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'PATCH' });
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
            category_id: null,
            comment: 'Payé par la trésorière',
            fiscal_year_id: 1,
            _csrf_token: 'tok',
        });
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('sends the picked category as a number', async () => {
        await boot();
        mobileCard().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        document.getElementById('md-category').value = '3';

        fetch.mockReturnValue(jsonResponse({ success: true }));
        document.getElementById('md-save-btn').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/movements/42');
        expect(JSON.parse(fetch.mock.calls[0][1].body).category_id).toBe(3);
    });

    it('shows an error toast and does NOT reload when the server refuses', async () => {
        await boot();
        desktopRow().dispatchEvent(new MouseEvent('click', { bubbles: true }));

        fetch.mockReturnValue(jsonResponse({ success: false, error: 'Exercice clôturé.' }));
        document.getElementById('md-save-btn').click();
        await settle();

        expect(toastText()).toBe('Exercice clôturé.');
        expect(document.querySelector('.toast').className).toContain('text-bg-danger');
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('treats an HTTP 200 carrying success:false as a failure, not a success', async () => {
        // `ok` is HTTP-level, `data.success` is the business answer — the
        // two must never be conflated (api.js § envelope).
        await boot();
        desktopRow().dispatchEvent(new MouseEvent('click', { bubbles: true }));

        fetch.mockReturnValue(jsonResponse({ success: false }, { ok: true, status: 200 }));
        document.getElementById('md-save-btn').click();
        await settle();

        expect(window.location.reload).not.toHaveBeenCalled();
        expect(toastText()).toBe('Une erreur est survenue.');
    });
});

describe('finance-movements.js: receipts dialog', () => {
    async function openReceipts(attachments) {
        await boot();
        fetch.mockReturnValueOnce(jsonResponse({ success: true, attachments }));
        document.querySelector('.attachment-btn').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await settle();
        fetch.mockClear();
    }

    const attachment = (overrides = {}) => ({
        id: 31,
        file_id: 99,
        mime_type: 'image/jpeg',
        original_filename: 'ticket.jpg',
        suggested_amount: null,
        suggested_date: null,
        suggested_label: null,
        suggested_description: null,
        ...overrides,
    });

    it('lists the associated receipts of the clicked movement', async () => {
        await openReceipts([attachment({ suggested_amount: 45.9, suggested_date: '01/07/2026' })]);

        expect(document.getElementById('attachments-modal').classList.contains('show')).toBe(true);
        const list = document.getElementById('attachments-list');
        expect(list.querySelector('a').getAttribute('href')).toBe('/files/99');
        expect(list.textContent).toContain('ticket.jpg');
        expect(list.textContent).toContain('45,90 € — 01/07/2026');
        expect(list.querySelector('.dissociate-btn').dataset.attachmentId).toBe('31');
    });

    it('says "Aucun reçu associé." for a movement with none', async () => {
        await openReceipts([]);

        expect(document.getElementById('attachments-list').textContent).toContain('Aucun reçu associé.');
    });

    it('asks the site confirmation before dissociating, and sends nothing on refusal', async () => {
        await openReceipts([attachment()]);
        window.ScoutMagicConfirm.ask.mockResolvedValue(false);
        const nativeConfirm = vi.fn(() => true);
        window.confirm = nativeConfirm;

        document.querySelector('.dissociate-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith(expect.objectContaining({
            message: 'Délier ce reçu de ce mouvement ?',
            confirmLabel: 'Délier',
        }));
        expect(fetch).not.toHaveBeenCalled();
        // Never the native box this site replaced (design.md §7.5).
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it('POSTs the dissociation with the movement id and the CSRF token once confirmed', async () => {
        await openReceipts([attachment()]);
        window.ScoutMagicConfirm.ask.mockResolvedValue(true);
        fetch.mockReturnValue(jsonResponse({ success: true }));

        document.querySelector('.dissociate-btn').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/receipts/31/dissociate');
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST' });
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ transaction_id: '43', _csrf_token: 'tok' });
    });

    it('replaces a broken PDF thumbnail with the placeholder icon', async () => {
        await openReceipts([attachment({ mime_type: 'application/pdf', original_filename: 'facture.pdf' })]);

        const thumbnail = document.querySelector('#attachments-list img.pdf-thumbnail');
        expect(thumbnail.getAttribute('src')).toBe('/files/99/thumbnail');
        thumbnail.dispatchEvent(new Event('error'));

        expect(document.querySelector('#attachments-list img.pdf-thumbnail')).toBeNull();
        expect(document.querySelector('#attachments-list .bi-file-earmark-pdf')).not.toBeNull();
    });

    it('associates a pending receipt picked from the select', async () => {
        await openReceipts([]);
        document.getElementById('attachments-add-select').value = '31';
        fetch.mockReturnValue(jsonResponse({ success: true }));

        document.getElementById('attachments-add-btn').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/receipts/31/associate');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ transaction_ids: ['43'], _csrf_token: 'tok' });
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('sends nothing when no pending receipt is picked', async () => {
        await openReceipts([]);

        document.getElementById('attachments-add-btn').click();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('uploads a receipt as multipart, keeping the button disabled for the whole request', async () => {
        await openReceipts([]);
        const input = document.getElementById('attachments-upload-input');
        Object.defineProperty(input, 'files', {
            configurable: true,
            value: [new File(['x'], 'ticket.jpg', { type: 'image/jpeg' })],
        });

        let finish;
        fetch.mockReturnValueOnce(new Promise((resolve) => { finish = resolve; }));
        const btn = document.getElementById('attachments-upload-btn');
        btn.click();
        await settle();

        expect(btn.disabled).toBe(true);
        expect(fetch.mock.calls[0][0]).toBe('/finance/movements/43/attachments');
        expect(fetch.mock.calls[0][1].method).toBe('POST');
        const body = fetch.mock.calls[0][1].body;
        expect(body).toBeInstanceOf(FormData);
        expect(body.get('_csrf_token')).toBe('tok');
        expect(body.get('receipt').name).toBe('ticket.jpg');

        finish({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) });
        await settle();

        expect(window.location.reload).toHaveBeenCalled();
        // Re-enabled on every path — the half the hand-written try/finally
        // pairs kept forgetting.
        expect(btn.disabled).toBe(false);
    });

    it('shows a refused upload in the dialog error box and re-enables the button', async () => {
        await openReceipts([]);
        Object.defineProperty(document.getElementById('attachments-upload-input'), 'files', {
            configurable: true,
            value: [new File(['x'], 'ticket.jpg', { type: 'image/jpeg' })],
        });
        fetch.mockReturnValueOnce(jsonResponse({ success: false, error: 'Format non accepté.' }));

        const btn = document.getElementById('attachments-upload-btn');
        btn.click();
        await settle();

        const errorBox = document.getElementById('attachments-upload-error');
        expect(errorBox.textContent).toBe('Format non accepté.');
        expect(errorBox.classList.contains('d-none')).toBe(false);
        expect(btn.disabled).toBe(false);
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});

describe('finance-movements.js: receipt suggestion button', () => {
    it('associates the suggested receipt without opening the details dialog', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: true }));

        document.querySelector('.suggest-associate-btn').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/finance/receipts/31/associate');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ transaction_ids: ['43'], _csrf_token: 'tok' });
        expect(window.location.reload).toHaveBeenCalled();
        expect(detail('md-date').textContent).toBe('');
    });

    it('shows an error toast instead of reloading when the association is refused', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: false, error: 'Reçu déjà associé.' }));

        document.querySelector('.suggest-associate-btn').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await settle();

        expect(toastText()).toBe('Reçu déjà associé.');
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});
