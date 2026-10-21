// Isolated JavaScript unit test — jsdom DOM only, fetch mocked. Exercises
// the REAL public/assets/js/rental-transfer-reconcile.js (imported below,
// never reimplemented here): the search of « Rapprocher un virement »
// (#980, chantier §2.6) on a booking's « Finances » page.
//
// Bootstrap is not loaded: the module listens to `hidden.bs.modal`, which
// Bootstrap dispatches (bubbling) on the modal element, so the tests
// dispatch it directly.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const MARKUP = `
    <div class="modal" id="rapprocher-virement">
        <form method="post" action="/mes-locations/virement-chercher" data-transfer-search data-async="off">
            <input type="hidden" name="booking_id" value="7">
            <input type="text" name="amount" value="1000">
            <input type="text" name="payer_name" value="Ethias">
            <button type="submit">Chercher</button>
        </form>
        <div data-transfer-results></div>
    </div>
    <form method="post" action="/mes-locations/statut" id="other"><button type="submit">Autre</button></form>`;

// Imported once: the module's listeners live on the document, so a second
// import would only stack a second set of them.
let imported = false;

async function load() {
    document.body.innerHTML = MARKUP;
    if (!imported) {
        await import('../../public/assets/js/rental-transfer-reconcile.js');
        imported = true;
    }
}

/** @param {string} selector */
function submit(selector) {
    const form = /** @type {HTMLFormElement} */ (document.querySelector(selector));
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);

    return event;
}

function results() {
    return /** @type {HTMLElement} */ (document.querySelector('[data-transfer-results]'));
}

/** @param {unknown} body */
function answer(body) {
    return vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify(body)));
}

describe('rental-transfer-reconcile.js', () => {
    beforeEach(async () => {
        await load();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('posts the search as a fetch, the amount and the name in the body, never in the URL', async () => {
        const fetchMock = answer({ success: true, type: 'success', message: null, html: '<p>Liste</p>' });

        const event = submit('[data-transfer-search]');

        expect(event.defaultPrevented).toBe(true);
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        const [url, init] = fetchMock.mock.calls[0];
        expect(new URL(String(url)).pathname).toBe('/mes-locations/virement-chercher');
        expect(new URL(String(url)).search).toBe('');
        expect(init?.method).toBe('POST');
        expect(/** @type {Record<string, string>} */ (init?.headers)['X-Requested-With']).toBe('XMLHttpRequest');
        const body = /** @type {FormData} */ (init?.body);
        expect(body.get('amount')).toBe('1000');
        expect(body.get('payer_name')).toBe('Ethias');
        expect(body.get('booking_id')).toBe('7');
    });

    it('places the list the server rendered in the dialog', async () => {
        answer({
            success: true,
            type: 'success',
            message: null,
            html: '<table data-transfer-candidates><tr><td>ETHIAS SA</td></tr></table>',
        });

        submit('[data-transfer-search]');

        await vi.waitFor(() => expect(results().querySelector('[data-transfer-candidates]')).not.toBeNull());
        expect(results().textContent).toContain('ETHIAS SA');
        expect(results().hasAttribute('aria-busy')).toBe(false);
    });

    it('says the refusal as text, never as markup', async () => {
        answer({ success: false, type: 'error', message: '<b>Indiquez le montant reçu.</b>' });

        submit('[data-transfer-search]');

        await vi.waitFor(() => expect(results().querySelector('[role="alert"]')).not.toBeNull());
        expect(results().querySelector('b')).toBeNull();
        expect(results().textContent).toBe('<b>Indiquez le montant reçu.</b>');
    });

    it('says an answer that is not JSON, and a network failure, without leaving the button disabled', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response('<html>Connexion</html>'));
        const button = /** @type {HTMLButtonElement} */ (document.querySelector('[data-transfer-search] button'));

        submit('[data-transfer-search]');
        await vi.waitFor(() => expect(results().textContent).toContain('Rechargez la page'));
        expect(button.disabled).toBe(false);

        vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new TypeError('offline'));
        submit('[data-transfer-search]');
        await vi.waitFor(() => expect(results().textContent).toContain('Erreur réseau'));
        expect(button.disabled).toBe(false);
    });

    it('leaves every other form of the page alone', () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch');

        const event = submit('#other');

        expect(event.defaultPrevented).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('forgets the list once the dialog is closed', async () => {
        answer({ success: true, type: 'success', message: null, html: '<p data-listed>Liste</p>' });
        submit('[data-transfer-search]');
        await vi.waitFor(() => expect(results().querySelector('[data-listed]')).not.toBeNull());

        document.getElementById('rapprocher-virement')?.dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));

        expect(results().innerHTML).toBe('');
    });
});
