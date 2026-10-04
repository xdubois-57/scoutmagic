// Isolated JavaScript unit test — jsdom only, fetch mocked. Exercises the
// REAL public/assets/js/leadership-configuration.js (imported below, never
// reimplemented here) on the markup the Encadrement > Configuration page
// renders (modules/leadership/views/configuration.html.twig, #727).
//
// Pinned: a select saves itself as JSON and says « Enregistré. »; a refusal
// puts the last saved step back and says why; a wording attached from
// « À configurer » moves to « Correspondances existantes » with its trash;
// the trash asks first, then removes the row and says « Rattachement
// supprimé. ».
import { beforeEach, describe, expect, it, vi } from 'vitest';

function row(raw, selected, holders = 0) {
    const options = ['t1', 't2', 't3'].map((v) => `<option value="${v}"${v === selected ? ' selected' : ''}>${v.toUpperCase()}</option>`).join('');
    return `<li class="list-group-item leadership-mapping-row" data-raw-value="${raw}" data-holders="${holders}">
        <code>${raw}</code>
        <select class="form-select leadership-mapping-select" aria-label="Étape pour « ${raw} »">
            ${selected ? '' : '<option value="" selected>Choisir une étape…</option>'}${options}
        </select>
        <button type="button" class="btn leadership-mapping-delete${selected ? '' : ' d-none'}" title="Supprimer le rattachement"></button>
    </li>`;
}

function page({ unresolved = ['Zorglub'], decided = [['Wording maison', 't2']], holders = 0 } = {}) {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <ul id="leadership-mapping-unresolved">${unresolved.map((r) => row(r, null)).join('')}</ul>
        <p class="leadership-mapping-empty${unresolved.length ? ' d-none' : ''}" data-empty-for="leadership-mapping-unresolved">Tout est reconnu.</p>
        <ul id="leadership-mapping-decided">${decided.map(([r, s]) => row(r, s, holders)).join('')}</ul>
        <p class="leadership-mapping-empty${decided.length ? ' d-none' : ''}" data-empty-for="leadership-mapping-decided">Aucun rattachement.</p>`;
}

function jsonResponse(data, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/leadership-configuration.js');
}

const rowOf = (raw) => document.querySelector(`.leadership-mapping-row[data-raw-value="${raw}"]`);
const selectOf = (raw) => rowOf(raw).querySelector('select');
const emptyShown = (id) => !document.querySelector(`[data-empty-for="${id}"]`).classList.contains('d-none');

describe('leadership-configuration.js (#727)', () => {
    beforeEach(() => {
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    it('saves a changed step at once, as JSON, and says « Enregistré. »', async () => {
        page();
        await boot();

        selectOf('Wording maison').value = 't3';
        selectOf('Wording maison').dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Enregistré.', { variant: 'success' }));
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/admin/leadership/configuration/mapping');
        expect(JSON.parse(init.body)).toEqual({ raw_value: 'Wording maison', step: 't3', _csrf_token: 'tok' });
    });

    it('puts the last saved step back and says why when the save is refused', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Étape de formation inconnue.' }, 422));
        page();
        await boot();

        selectOf('Wording maison').value = 't3';
        selectOf('Wording maison').dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(window.ScoutMagicToast.show)
            .toHaveBeenCalledWith('Étape de formation inconnue.', { variant: 'error' }));
        expect(selectOf('Wording maison').value).toBe('t2');
    });

    it('says « Erreur réseau. » when the request never answered', async () => {
        global.fetch = vi.fn(() => Promise.reject(new TypeError('offline')));
        page();
        await boot();

        selectOf('Wording maison').value = 't1';
        selectOf('Wording maison').dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Erreur réseau.', { variant: 'error' }));
        expect(selectOf('Wording maison').value).toBe('t2');
    });

    it('moves an attached wording from « À configurer » to the correspondences, trash shown', async () => {
        page();
        await boot();

        selectOf('Zorglub').value = 't1';
        selectOf('Zorglub').dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(rowOf('Zorglub').parentElement.id).toBe('leadership-mapping-decided'));
        expect(selectOf('Zorglub').querySelector('option[value=""]')).toBeNull();
        expect(rowOf('Zorglub').querySelector('.leadership-mapping-delete').classList.contains('d-none')).toBe(false);
        expect(emptyShown('leadership-mapping-unresolved')).toBe(true);
    });

    it('confirms, removes the row and says « Rattachement supprimé. »', async () => {
        page({ unresolved: [] });
        await boot();

        rowOf('Wording maison').querySelector('.leadership-mapping-delete').click();

        await vi.waitFor(() => expect(window.ScoutMagicToast.show)
            .toHaveBeenCalledWith('Rattachement supprimé.', { variant: 'success' }));
        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith(expect.objectContaining({ confirmLabel: 'Supprimer' }));
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ raw_value: 'Wording maison', step: '', _csrf_token: 'tok' });
        expect(rowOf('Wording maison')).toBeNull();
        expect(emptyShown('leadership-mapping-decided')).toBe(true);
    });

    it('sends a wording someone still carries back to « À configurer », placeholder restored', async () => {
        page({ unresolved: [], holders: 3 });
        await boot();

        rowOf('Wording maison').querySelector('.leadership-mapping-delete').click();

        await vi.waitFor(() => expect(window.ScoutMagicToast.show)
            .toHaveBeenCalledWith('Rattachement supprimé.', { variant: 'success' }));
        expect(rowOf('Wording maison').parentElement.id).toBe('leadership-mapping-unresolved');
        expect(selectOf('Wording maison').value).toBe('');
        expect(rowOf('Wording maison').querySelector('.leadership-mapping-delete').classList.contains('d-none')).toBe(true);
        expect(emptyShown('leadership-mapping-unresolved')).toBe(false);
        expect(emptyShown('leadership-mapping-decided')).toBe(true);
    });

    it('removes nothing when the confirmation is declined', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        page();
        await boot();

        rowOf('Wording maison').querySelector('.leadership-mapping-delete').click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetch).not.toHaveBeenCalled();
        expect(rowOf('Wording maison')).not.toBeNull();
    });

    it('keeps a row whose removal is refused, and says so', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Non.' }));
        page();
        await boot();

        rowOf('Wording maison').querySelector('.leadership-mapping-delete').click();

        await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Non.', { variant: 'error' }));
        expect(rowOf('Wording maison')).not.toBeNull();
    });

    it('does nothing on another page', async () => {
        document.body.innerHTML = '<p>Autre page</p>';
        await expect(boot()).resolves.not.toThrow();
    });
});
