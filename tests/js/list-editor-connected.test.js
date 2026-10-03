// Isolated JavaScript unit test — jsdom only, fetch mocked. Exercises the
// REAL sortable.js and list-editor.js (imported below, never reimplemented
// here) on the shape the text pages screen renders: one list_editor per
// menu section, connected through `data-sortable-group` (issue #752).
//
// What is pinned: a page dropped into another section — an empty one
// included — is saved by the list it landed in, with that list's key; the
// empty sentence follows the move both ways; a refused move reloads rather
// than leaving a page shown where it was never saved; and lists WITHOUT
// the attribute stay unconnected, which every other list_editor relies on.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function item(id) {
    return `<div class="list-editor-item" draggable="true" data-id="${id}">
        <button class="list-editor-move-up"></button>
        <button class="list-editor-move-down"></button>
        <p><span data-item-field="group_label">Ancienne colonne · </span><code>/p/${id}</code></p>
    </div>`;
}

function list(key, ids, { group = 'text-pages' } = {}) {
    const groupAttrs = group ? `data-sortable-group="${group}" data-group-key="${key}"` : '';
    return `<div class="list-editor" id="list-${key}" data-reorder-url="/config/pages-de-texte/ordre" ${groupAttrs}>
        <div class="list-editor-items">
            ${ids.map(item).join('')}
            <p class="list-editor-empty${ids.length ? ' d-none' : ''}">Aucune page dans cette section.</p>
        </div>
    </div>`;
}

function jsonResponse(data, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });
}

async function boot() {
    vi.resetModules();
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/sortable.js');
    await import('../../public/assets/js/list-editor.js');
}

const items = (key) => document.querySelector(`#list-${key} .list-editor-items`);
const row = (id) => document.querySelector(`.list-editor-item[data-id="${id}"]`);
const ids = (key) => [...items(key).querySelectorAll('.list-editor-item')].map((el) => el.dataset.id);
const emptyShown = (key) => !items(key).querySelector('.list-editor-empty').classList.contains('d-none');

function dragOver(target, clientY = 0) {
    const event = new Event('dragover', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'clientY', { value: clientY });
    Object.defineProperty(event, 'clientX', { value: 0 });
    target.dispatchEvent(event);
}

function layOut(key) {
    [...items(key).querySelectorAll('.list-editor-item')].forEach((el, i) => {
        el.getBoundingClientRect = () => ({ top: i * 100, left: 0, height: 100, width: 100 });
    });
}

describe('list-editor.js — connected lists (issue #752)', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        delete window.location;
        window.location = { reload: vi.fn() };
        global.fetch = vi.fn(() => jsonResponse({ success: true, items: { 2: { group_label: 'Infos' } } }));
    });

    it('moves a page into the middle of another section and saves it there, with that section as group', async () => {
        document.body.innerHTML = list('membres', [1, 2]) + list('unite', [7, 8]);
        await boot();
        layOut('unite');

        row(2).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(row(8), 10); // upper half of the second row: before it
        row(2).dispatchEvent(new Event('dragend', { bubbles: true }));

        expect(ids('unite')).toEqual(['7', '2', '8']);
        expect(ids('membres')).toEqual(['1']);
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/config/pages-de-texte/ordre');
        expect(JSON.parse(init.body)).toEqual({ ids: ['7', '2', '8'], group: 'unite', _csrf_token: 'tok' });
    });

    it('accepts a drop into an EMPTY section, and the empty sentence follows the move both ways', async () => {
        document.body.innerHTML = list('membres', [3]) + list('animateurs', []);
        await boot();
        expect(emptyShown('animateurs')).toBe(true);

        row(3).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(items('animateurs').querySelector('.list-editor-empty'));
        row(3).dispatchEvent(new Event('dragend', { bubbles: true }));

        expect(ids('animateurs')).toEqual(['3']);
        expect(emptyShown('animateurs')).toBe(false);
        expect(emptyShown('membres')).toBe(true);
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(JSON.parse(fetch.mock.calls[0][1].body).group).toBe('animateurs');
    });

    it('marks every list of the group as a drop zone while dragging, and only then', async () => {
        document.body.innerHTML = list('membres', [1]) + list('unite', []);
        await boot();

        row(1).dispatchEvent(new Event('dragstart', { bubbles: true }));
        expect(items('membres').classList.contains('sortable-drop-zone')).toBe(true);
        expect(items('unite').classList.contains('sortable-drop-zone')).toBe(true);

        row(1).dispatchEvent(new Event('dragend', { bubbles: true }));
        expect(items('unite').classList.contains('sortable-drop-zone')).toBe(false);
    });

    it('writes what the server says changed on the moved row (its column)', async () => {
        document.body.innerHTML = list('membres', [2]) + list('animateurs', [5]);
        await boot();
        layOut('animateurs');

        row(2).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(row(5), 90);
        row(2).dispatchEvent(new Event('dragend', { bubbles: true }));

        await vi.waitFor(() =>
            expect(row(2).querySelector('[data-item-field="group_label"]').textContent).toBe('Infos · '));
    });

    it('reloads after a REFUSED move, so no page is shown in a section it was never saved in', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: "Cette section de menu n'existe pas." }, 422));
        document.body.innerHTML = list('membres', [2]) + list('unite', []);
        await boot();

        row(2).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(items('unite'));
        row(2).dispatchEvent(new Event('dragend', { bubbles: true }));

        await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        expect(document.querySelector('.toast-body').textContent).toBe("Cette section de menu n'existe pas.");
    });

    it('a refused reorder WITHIN a section only says so', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Non.' }));
        document.body.innerHTML = list('membres', [1, 2]);
        await boot();
        layOut('membres');

        row(1).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(row(2), 90);
        row(1).dispatchEvent(new Event('dragend', { bubbles: true }));

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('a moved row answers its new list\'s move buttons', async () => {
        document.body.innerHTML = list('membres', [2]) + list('unite', [7]);
        await boot();
        layOut('unite');
        row(2).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(row(7), 90);
        row(2).dispatchEvent(new Event('dragend', { bubbles: true }));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

        row(2).querySelector('.list-editor-move-up').dispatchEvent(new Event('click', { bubbles: true }));

        expect(ids('unite')).toEqual(['2', '7']);
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ ids: ['2', '7'], group: 'unite' });
    });

    it('leaves lists WITHOUT a group unconnected — the other screens using list_editor', async () => {
        document.body.innerHTML = list('a', [1], { group: '' }) + list('b', [], { group: '' });
        await boot();

        row(1).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(items('b'));
        row(1).dispatchEvent(new Event('dragend', { bubbles: true }));

        expect(ids('a')).toEqual(['1']);
        expect(ids('b')).toEqual([]);
        expect(items('b').classList.contains('sortable-drop-zone')).toBe(false);
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ ids: ['1'], _csrf_token: 'tok' });
    });

    it('does not connect two DIFFERENT groups', async () => {
        document.body.innerHTML = list('a', [1], { group: 'one' }) + list('b', [], { group: 'two' });
        await boot();

        row(1).dispatchEvent(new Event('dragstart', { bubbles: true }));
        dragOver(items('b'));

        expect(ids('b')).toEqual([]);
    });
});
