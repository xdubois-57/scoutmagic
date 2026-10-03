// Isolated JavaScript unit test — jsdom-simulated DOM only; fetch is
// mocked. Exercises the REAL public/assets/js/rental-inventory-template.js
// (#708, IT-10): an inventory item's sort and expected count, edited in
// place in its row.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

function row(id, kind, count) {
    return `<div data-inventory-item="${id}">
        <select data-inventory-field="kind">
            <option value="quantity" ${kind === 'quantity' ? 'selected' : ''}>Quantité</option>
            <option value="yes_no" ${kind === 'yes_no' ? 'selected' : ''}>Oui / Non</option>
        </select>
        <input type="number" data-inventory-field="expected_count" value="${count}" ${kind === 'quantity' ? '' : 'hidden'}>
    </div>`;
}

async function boot() {
    vi.resetModules();
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    return import('../../public/assets/js/rental-inventory-template.js');
}

beforeEach(() => {
    document.body.innerHTML = `<div class="inventory-template" data-update-url="/update">
        ${row(4, 'quantity', 40)}
        <form class="inventory-add-form">
            <select name="kind"><option value="quantity">Quantité</option><option value="yes_no">Oui / Non</option></select>
            <div data-inventory-count-field><input name="expected_count" value="1"></div>
        </form>
    </div>`;
    global.fetch = vi.fn(() => jsonResponse({ success: true }));
});

describe('rental-inventory-template.js', () => {
    it('only a quantity has an expected count', async () => {
        const { countApplies } = await boot();

        expect(countApplies('quantity')).toBe(true);
        expect(countApplies('yes_no')).toBe(false);
    });

    it('saves a changed count in place', async () => {
        await boot();
        const count = document.querySelector('[data-inventory-field="expected_count"]');

        count.value = '42';
        count.dispatchEvent(new Event('change', { bubbles: true }));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(fetch.mock.calls[0][0]).toBe('/update');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ id: 4, kind: 'quantity', expected_count: '42' });
    });

    it('a yes/no item hides its count and sends none', async () => {
        await boot();
        const kind = document.querySelector('[data-inventory-item] [data-inventory-field="kind"]');

        kind.value = 'yes_no';
        kind.dispatchEvent(new Event('change', { bubbles: true }));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ id: 4, kind: 'yes_no', expected_count: null });
        expect(document.querySelector('[data-inventory-field="expected_count"]').hidden).toBe(true);
    });

    it('shows a refusal', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: "Le nombre attendu doit être un nombre entier d'au moins 1." }));
        await boot();
        const count = document.querySelector('[data-inventory-field="expected_count"]');

        count.value = '0';
        count.dispatchEvent(new Event('change', { bubbles: true }));

        await vi.waitFor(() => expect(document.querySelector('.toast-body')?.textContent)
            .toBe("Le nombre attendu doit être un nombre entier d'au moins 1."));
    });

    it('says so when the request itself fails', async () => {
        await boot();
        window.ScoutMagicApi.postJson = vi.fn(() => Promise.reject(new Error('boom')));
        const count = document.querySelector('[data-inventory-field="expected_count"]');

        count.value = '42';
        count.dispatchEvent(new Event('change', { bubbles: true }));

        await vi.waitFor(() => expect(document.querySelector('.toast-body')?.textContent)
            .toBe("Erreur réseau : l'élément n'a pas été enregistré."));
    });

    it('the add form shows the count only for a quantity, and again after a reset', async () => {
        await boot();
        const form = document.querySelector('.inventory-add-form');
        const kind = form.querySelector('[name="kind"]');
        const field = form.querySelector('[data-inventory-count-field]');

        kind.value = 'yes_no';
        kind.dispatchEvent(new Event('change'));
        expect(field.hidden).toBe(true);

        form.reset();
        form.dispatchEvent(new Event('list-editor:added'));
        expect(field.hidden).toBe(false);
        expect(fetch).not.toHaveBeenCalled();
    });
});
