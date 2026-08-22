// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch and window.confirm are mocked.
// Exercises the REAL implementation in public/assets/js/list-editor.js
// (imported below, never reimplemented here), on top of the real
// api.js/toast.js toolboxes. No test seam needed — every function's effect
// is reachable through a real click/dragend event and observable in the
// DOM (error feedback is a toast now) or the mocked fetch calls.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function el(html) {
    const div = document.createElement('div');
    div.innerHTML = html.trim();
    return div.firstElementChild;
}

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

/** The text of the last toast currently shown, or null when there is none. */
function lastToastText() {
    const bodies = document.querySelectorAll('.toast-body');
    return bodies.length ? bodies[bodies.length - 1].textContent : null;
}

// updateMoveButtons() is only ever called from INSIDE the move-up/
// move-down click handlers (lines 90/101 of the source) — never once at
// setup time. So the initial disabled state of these buttons is entirely
// the server-rendered HTML's responsibility, same finding as
// maintenance.js's wireKeywordGate() earlier in this plan. `first`/`last`
// let each fixture set that initial state explicitly, matching what the
// real Twig template does, rather than asserting this JS sets it.
function buildItem(id, { first = false, last = false } = {}) {
    return `<div class="list-editor-item" data-id="${id}">
        <button class="list-editor-move-up" ${first ? 'disabled' : ''}></button>
        <button class="list-editor-move-down" ${last ? 'disabled' : ''}></button>
        <button class="list-editor-active-toggle" data-id="${id}" data-active="1"><i class="bi bi-toggle-on"></i></button>
        <button class="list-editor-delete-btn" data-id="${id}"></button>
    </div>`;
}

function buildEditor({ items = [1, 2, 3], reorderUrl = '/reorder', activeUrl = '/active', deleteUrl = '/delete', addMode = '' } = {}) {
    const attrs = [
        reorderUrl ? `data-reorder-url="${reorderUrl}"` : '',
        activeUrl ? `data-active-url="${activeUrl}"` : '',
        deleteUrl ? `data-delete-url="${deleteUrl}"` : '',
        addMode ? `data-add-mode="${addMode}"` : '',
    ].join(' ');
    const container = el(`<div class="list-editor" ${attrs}>
        <div class="list-editor-items">${items.map((id, i) => buildItem(id, { first: i === 0, last: i === items.length - 1 })).join('')}</div>
        <button class="list-editor-add-btn" data-url="/add"></button>
    </div>`);
    document.body.appendChild(container);
    return container;
}

async function boot() {
    vi.resetModules();
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'tok';
    document.head.innerHTML = '';
    document.head.appendChild(meta);
    // The real site-wide toolboxes, loaded by base.html.twig before every
    // page script — same order here.
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/list-editor.js');
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    global.fetch = vi.fn(() => jsonResponse({ success: true }));
    window.confirm = vi.fn(() => true);
});

describe('list-editor.js: move up/down — button-state logic (off-by-one bugs live here)', () => {
    it('leaves the server-rendered initial disabled state untouched — this JS never sets it eagerly', async () => {
        buildEditor({ items: [1, 2, 3] });
        await boot();

        const items = document.querySelectorAll('.list-editor-item');
        expect(items[0].querySelector('.list-editor-move-up').disabled).toBe(true);
        expect(items[0].querySelector('.list-editor-move-down').disabled).toBe(false);
        expect(items[1].querySelector('.list-editor-move-up').disabled).toBe(false);
        expect(items[1].querySelector('.list-editor-move-down').disabled).toBe(false);
        expect(items[2].querySelector('.list-editor-move-up').disabled).toBe(false);
        expect(items[2].querySelector('.list-editor-move-down').disabled).toBe(true);
    });

    it("moving the middle item up re-orders the DOM and re-evaluates every button's own disabled state", async () => {
        buildEditor({ items: [1, 2, 3] });
        await boot();

        document.querySelectorAll('.list-editor-item')[1].querySelector('.list-editor-move-up').dispatchEvent(new Event('click'));

        const idsAfter = Array.from(document.querySelectorAll('.list-editor-item')).map((i) => i.dataset.id);
        expect(idsAfter).toEqual(['2', '1', '3']);
        // '2' is now first: its own up button must be disabled.
        expect(document.querySelectorAll('.list-editor-item')[0].querySelector('.list-editor-move-up').disabled).toBe(true);
    });

    it('moving the last item down is a no-op — there is no next sibling', async () => {
        buildEditor({ items: [1, 2, 3] });
        await boot();
        document.querySelectorAll('.list-editor-item')[2].querySelector('.list-editor-move-down').dispatchEvent(new Event('click'));
        const idsAfter = Array.from(document.querySelectorAll('.list-editor-item')).map((i) => i.dataset.id);
        expect(idsAfter).toEqual(['1', '2', '3']);
    });

    it('moving the first item up is a no-op — there is no previous sibling', async () => {
        buildEditor({ items: [1, 2, 3] });
        await boot();
        document.querySelectorAll('.list-editor-item')[0].querySelector('.list-editor-move-up').dispatchEvent(new Event('click'));
        const idsAfter = Array.from(document.querySelectorAll('.list-editor-item')).map((i) => i.dataset.id);
        expect(idsAfter).toEqual(['1', '2', '3']);
    });

    it("a real move persists the new order via POST, with string (not parseInt'd) ids", async () => {
        buildEditor({ items: ['finance', 'gallery', 'news'] });
        await boot();
        document.querySelectorAll('.list-editor-item')[1].querySelector('.list-editor-move-up').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/reorder', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ ids: ['gallery', 'finance', 'news'], _csrf_token: 'tok' }),
        })));
    });

    it('does nothing (no persist, no throw) when no reorder URL is configured', async () => {
        buildEditor({ items: [1, 2], reorderUrl: '' });
        await boot();
        document.querySelectorAll('.list-editor-item')[1].querySelector('.list-editor-move-up').dispatchEvent(new Event('click'));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('alerts the server error message when persisting a move fails', async () => {
        buildEditor({ items: [1, 2] });
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Verrou déjà pris.' }));
        await boot();
        document.querySelectorAll('.list-editor-item')[1].querySelector('.list-editor-move-up').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(lastToastText()).toBe('Verrou déjà pris.'));
    });
});

describe('list-editor.js: drag-and-drop reorder', () => {
    it('dragend persists the order currently in the DOM', async () => {
        const container = buildEditor({ items: [1, 2, 3] });
        await boot();

        // Simulate a completed drag: item 3 moved to the front, THEN
        // dragend fires (matching the real sequence: dragover mutates the
        // DOM live, dragend is what persists it).
        const itemsEl = container.querySelector('.list-editor-items');
        const items = itemsEl.querySelectorAll('.list-editor-item');
        itemsEl.insertBefore(items[2], items[0]);
        items[2].dispatchEvent(new Event('dragend'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/reorder', expect.objectContaining({
            body: JSON.stringify({ ids: ['3', '1', '2'], _csrf_token: 'tok' }),
        })));
    });

    it('dragend removes the dragging class', async () => {
        const container = buildEditor({ items: [1, 2] });
        await boot();
        const item = container.querySelector('.list-editor-item');
        item.dispatchEvent(new Event('dragstart'));
        expect(item.classList.contains('list-editor-item--dragging')).toBe(true);
        item.dispatchEvent(new Event('dragend'));
        expect(item.classList.contains('list-editor-item--dragging')).toBe(false);
    });
});

describe('list-editor.js: active toggle', () => {
    it('POSTs the id and the NEW state, then updates classes/icon/title/dataset on success', async () => {
        buildEditor({ items: [5] });
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();
        const toggle = document.querySelector('.list-editor-active-toggle');

        toggle.dispatchEvent(new Event('click'));
        expect(toggle.disabled).toBe(true);
        expect(fetch).toHaveBeenCalledWith('/active', expect.objectContaining({
            body: JSON.stringify({ id: 5, active: false, _csrf_token: 'tok' }),
        }));

        // fetch having been CALLED is not the same as its .then() chain
        // having RUN yet — wait for the state that only exists after the
        // promise actually resolves, not just after the call was made.
        await vi.waitFor(() => expect(toggle.disabled).toBe(false));
        expect(toggle.dataset.active).toBe('0');
        expect(toggle.classList.contains('btn-outline-secondary')).toBe(true);
        expect(toggle.classList.contains('btn-outline-success')).toBe(false);
        expect(toggle.title).toBe('Inactif — cliquer pour activer');
        expect(toggle.querySelector('i').classList.contains('bi-toggle-off')).toBe(true);
    });

    it('toggling back from inactive to active flips the state the other way', async () => {
        buildEditor({ items: [5] });
        const toggle = document.querySelector('.list-editor-active-toggle');
        toggle.dataset.active = '0';
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();

        toggle.dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(toggle.disabled).toBe(false));
        expect(fetch).toHaveBeenCalledWith('/active', expect.objectContaining({
            body: JSON.stringify({ id: 5, active: true, _csrf_token: 'tok' }),
        }));
        expect(toggle.dataset.active).toBe('1');
    });

    it('re-enables the button and leaves state untouched on a rejected toggle', async () => {
        buildEditor({ items: [5] });
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Verrouillé.' }));
        await boot();
        const toggle = document.querySelector('.list-editor-active-toggle');

        toggle.dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(lastToastText()).toBe('Verrouillé.'));
        expect(toggle.disabled).toBe(false);
        expect(toggle.dataset.active).toBe('1'); // unchanged
    });

    it('does nothing when no active URL is configured', async () => {
        buildEditor({ items: [5], activeUrl: '' });
        global.fetch = vi.fn();
        await boot();
        document.querySelector('.list-editor-active-toggle').dispatchEvent(new Event('click'));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('list-editor.js: delete', () => {
    it('asks for confirmation before deleting; declining never calls fetch', async () => {
        buildEditor({ items: [5] });
        window.confirm = vi.fn(() => false);
        global.fetch = vi.fn();
        await boot();
        document.querySelector('.list-editor-delete-btn').dispatchEvent(new Event('click'));
        expect(window.confirm).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('accepting confirmation POSTs the id and reloads on success', async () => {
        buildEditor({ items: [5] });
        window.confirm = vi.fn(() => true);
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        await boot();

        document.querySelector('.list-editor-delete-btn').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/delete', expect.objectContaining({
            body: JSON.stringify({ id: 5, _csrf_token: 'tok' }),
        })));
        await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
    });

    it('alerts the server error and does not reload on a rejected delete', async () => {
        buildEditor({ items: [5] });
        window.confirm = vi.fn(() => true);
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Élément référencé ailleurs.' }));
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        await boot();
        document.querySelector('.list-editor-delete-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(lastToastText()).toBe('Élément référencé ailleurs.'));
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('a disabled delete button ignores clicks entirely — never even prompts', async () => {
        buildEditor({ items: [5] });
        document.querySelector('.list-editor-delete-btn').disabled = true;
        window.confirm = vi.fn(() => true);
        global.fetch = vi.fn();
        await boot();
        document.querySelector('.list-editor-delete-btn').dispatchEvent(new Event('click'));
        expect(window.confirm).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('list-editor.js: add', () => {
    it('POSTs to the button\'s own data-url and reloads on success', async () => {
        buildEditor({ items: [] });
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        await boot();

        document.querySelector('.list-editor-add-btn').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/add', expect.objectContaining({
            body: JSON.stringify({ _csrf_token: 'tok' }),
        })));
        await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
    });

    it('alerts the server error on a rejected add, without reloading', async () => {
        buildEditor({ items: [] });
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Quota atteint.' }));
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        await boot();
        document.querySelector('.list-editor-add-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(lastToastText()).toBe('Quota atteint.'));
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    // data-add-mode="custom": the caller wires its own handler for a
    // dialog-based add flow instead — this file must step aside entirely,
    // not just skip the reload.
    it('is never wired at all when data-add-mode="custom" — the caller owns the click handler instead', async () => {
        buildEditor({ items: [], addMode: 'custom' });
        global.fetch = vi.fn();
        await boot();
        document.querySelector('.list-editor-add-btn').dispatchEvent(new Event('click'));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
    });
});
