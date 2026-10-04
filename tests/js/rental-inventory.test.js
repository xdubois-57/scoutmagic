// Isolated JavaScript unit test — jsdom DOM only, fetch mocked. Exercises
// the REAL public/assets/js/rental-inventory.js: « État des lieux » (#708,
// IT-17), each line saved as it is typed, « = » taking the reference.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { countUnchecked, saveLine, validationQuestion, wire } from '../../public/assets/js/rental-inventory.js';

function page() {
    document.body.innerHTML = `
        <form method="post" action="/mes-locations/etat-des-lieux/ligne" data-inventory-line>
            <input type="number" name="value" value="" data-inventory-value>
            <button type="button" data-inventory-copy="40">=</button>
            <input type="text" name="note" value="">
            <button type="submit" data-inventory-save>Enregistrer</button>
            <output data-inventory-status></output>
        </form>
        <form method="post" action="/mes-locations/etat-des-lieux/ligne" data-inventory-line>
            <select name="value" data-inventory-value>
                <option value="">—</option><option value="yes">Oui</option><option value="no">Non</option>
            </select>
            <output data-inventory-status></output>
        </form>
        <form data-inventory-validate data-confirm-base="Valider ?" data-confirm="Valider ?"></form>`;
}

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('validationQuestion()', () => {
    it('says how many lines are still empty, and nothing when none is', () => {
        expect(validationQuestion('Valider ?', 0)).toBe('Valider ?');
        expect(validationQuestion('Valider ?', 1)).toBe('Valider ? 1 élément sans valeur : le PDF le marquera « non vérifié ».');
        expect(validationQuestion('Valider ?', 2)).toBe('Valider ? 2 éléments sans valeur : le PDF les marquera « non vérifié ».');
    });
});

describe('countUnchecked()', () => {
    it('counts the fields nobody filled in — nothing is pre-filled', () => {
        page();
        expect(countUnchecked(document)).toBe(2);
    });
});

describe('the page', () => {
    it('hides the save buttons, saves on change, and recounts the question', async () => {
        page();
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ success: true, type: 'success', message: 'Enregistré.' }))
        );
        wire(document);

        expect(document.documentElement.classList.contains('inventory-autosave')).toBe(true);

        const select = /** @type {HTMLSelectElement} */ (document.querySelector('select'));
        select.value = 'no';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls[0][1]?.headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
        await vi.waitFor(() => expect(
            /** @type {HTMLFormElement} */ (document.querySelector('[data-inventory-validate]')).dataset.confirm
        ).toContain('1 élément sans valeur'));
    });

    it('« = » takes the reference and saves it', async () => {
        page();
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ success: true }))
        );
        wire(document);

        /** @type {HTMLElement} */ (document.querySelector('[data-inventory-copy]')).click();

        expect(/** @type {HTMLInputElement} */ (document.querySelector('input[name="value"]')).value).toBe('40');
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
    });

    /**
     * One request per line at a time: the note typed while the value is
     * still saving waits, so the older answer can never be the one left
     * on screen — and the line is kept from a panel re-render meanwhile.
     */
    it('queues a second save behind the one in flight and lets only the last one speak', async () => {
        page();
        /** @type {Array<(response: Response) => void>} */
        const answers = [];
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(
            () => new Promise((resolve) => { answers.push(resolve); })
        );
        const form = /** @type {HTMLFormElement} */ (document.querySelector('form[data-inventory-line]'));
        const status = /** @type {HTMLOutputElement} */ (form.querySelector('[data-inventory-status]'));
        const note = /** @type {HTMLInputElement} */ (form.querySelector('input[name="note"]'));

        const first = saveLine(form);
        note.value = 'Deux clés';
        const second = saveLine(form);

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(form.hasAttribute('data-booking-keep')).toBe(true);

        answers[0](new Response(JSON.stringify({ success: false, message: 'Refusé' })));
        await first;
        // The older answer is not said: the newer save is still on its way.
        expect(status.textContent).toBe('Enregistrement…');
        expect(form.hasAttribute('data-booking-keep')).toBe(true);
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(/** @type {FormData} */ (fetchMock.mock.calls[1][1]?.body).get('note')).toBe('Deux clés');

        answers[1](new Response(JSON.stringify({ success: true })));
        await second;
        expect(status.textContent).toBe('Enregistré');
        expect(form.hasAttribute('data-booking-keep')).toBe(false);
    });

    /**
     * A save that ends while a panel re-render is still on its way (the
     * page says busy): the re-render fetched may predate it, so the line
     * stays kept until rental-booking.js says the panels are in.
     */
    it('keeps a line whose save ended during a re-render until that re-render lands', async () => {
        page();
        document.body.innerHTML = `<div data-rental-booking aria-busy="true">${document.body.innerHTML}</div>`;
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ success: true })));
        wire(document);
        const form = /** @type {HTMLFormElement} */ (document.querySelector('form[data-inventory-line]'));

        await saveLine(form);

        expect(form.querySelector('[data-inventory-status]')?.textContent).toBe('Enregistré');
        expect(form.hasAttribute('data-booking-keep')).toBe(true);

        const root = /** @type {HTMLElement} */ (document.querySelector('[data-rental-booking]'));
        root.removeAttribute('aria-busy');
        root.dispatchEvent(new CustomEvent('rental-booking:refreshed', { bubbles: true }));

        expect(form.hasAttribute('data-booking-keep')).toBe(false);
    });

    it('says a refusal beside the line', async () => {
        page();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ success: false, message: 'Indiquez un nombre entier, 0 ou plus.' }))
        );
        const form = /** @type {HTMLFormElement} */ (document.querySelector('form[data-inventory-line]'));

        await saveLine(form);

        expect(form.querySelector('[data-inventory-status]')?.textContent).toBe('Indiquez un nombre entier, 0 ou plus.');
        expect(form.querySelector('[data-inventory-value]')?.classList.contains('is-invalid')).toBe(true);
    });
});
