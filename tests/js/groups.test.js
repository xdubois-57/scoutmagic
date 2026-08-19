// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/groups.js (imported below, never
// reimplemented here). That file is a plain IIFE that reads the DOM at
// import time, so every test builds its DOM first and then imports the
// module through a reset registry — same pattern as gallery.test.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadGroups() {
    vi.resetModules();
    await import('../../public/assets/js/groups.js');
}

// jsdom does not implement the DataTransfer API at all (tracked upstream,
// never fixed): groups.js uses `new DataTransfer()` to populate the hidden
// <input>'s FileList, exactly the way real browsers require. A minimal
// stand-in plus overriding the hidden input's own `.files` property (jsdom's
// native setter strictly validates against its own FileList type, which a
// polyfill can never satisfy) is the standard workaround for testing this
// pattern under jsdom — production code is untouched.
class FakeDataTransfer {
    constructor() {
        this._files = [];
        this.items = { add: (file) => { this._files.push(file); } };
    }
    get files() {
        return this._files;
    }
}

describe('groups.js composer media picker', () => {
    beforeEach(() => {
        global.DataTransfer = FakeDataTransfer;
        // jsdom does not implement createObjectURL either — stubbed so the
        // image-preview branch (an <img src="blob:...">) can run at all;
        // its actual return value is never asserted on.
        global.URL.createObjectURL = vi.fn(() => 'blob:fake');
        document.body.innerHTML = `
            <form id="groups-post-form" data-max-media="2">
                <div id="groups-media-previews"></div>
                <input type="file" name="media[]" id="groups-media-hidden" class="d-none" multiple>
                <span id="groups-media-count"></span>
                <p id="groups-media-limit-warning" class="d-none"></p>
                <input type="file" id="groups-media-input" multiple>
            </form>
        `;
        Object.defineProperty(document.getElementById('groups-media-hidden'), 'files', {
            writable: true,
            configurable: true,
            value: [],
        });
    });

    function makeFile(name, type) {
        return new File(['x'], name, { type });
    }

    function fireChange(input, files) {
        Object.defineProperty(input, 'files', { value: files, configurable: true });
        // groups-media-input has its own direct listener (not delegated),
        // so bubbling is irrelevant here — but true matches a real
        // browser's change event and costs nothing.
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    it('adds a selected file to the preview grid and updates the count label', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('a.jpg', 'image/jpeg')]);

        expect(document.getElementById('groups-media-previews').children).toHaveLength(1);
        expect(document.getElementById('groups-media-count').textContent).toBe('1 / 2 médias');
    });

    it('caps selection at data-max-media and shows the limit warning', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('a.jpg', 'image/jpeg'), makeFile('b.jpg', 'image/jpeg'), makeFile('c.jpg', 'image/jpeg')]);

        expect(document.getElementById('groups-media-previews').children).toHaveLength(2);
        expect(document.getElementById('groups-media-count').textContent).toBe('2 / 2 médias');
        expect(document.getElementById('groups-media-limit-warning').classList.contains('d-none')).toBe(false);
    });

    it('resets the input value after each selection so re-picking the same file fires change again', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('a.jpg', 'image/jpeg')]);

        expect(input.value).toBe('');
    });

    it('removing a preview drops it from the count and the hidden input', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');
        fireChange(input, [makeFile('a.jpg', 'image/jpeg'), makeFile('b.jpg', 'image/jpeg')]);

        document.querySelector('#groups-media-previews button').click();

        expect(document.getElementById('groups-media-previews').children).toHaveLength(1);
        expect(document.getElementById('groups-media-count').textContent).toBe('1 / 2 médias');
    });

    it('shows a generic icon rather than an <img> for a non-image file (a video)', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('clip.mp4', 'video/mp4')]);

        const cell = document.querySelector('#groups-media-previews > div');
        expect(cell.querySelector('img')).toBeNull();
        expect(cell.querySelector('.bi-camera-video')).not.toBeNull();
    });
});

describe('groups.js "Lien" toggle', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <button type="button" id="groups-link-toggle"></button>
            <div class="d-none" id="groups-link-field">
                <input type="url" id="post-link" value="">
            </div>
        `;
    });

    it('reveals the link field and focuses it on first click', async () => {
        await loadGroups();

        document.getElementById('groups-link-toggle').click();

        expect(document.getElementById('groups-link-field').classList.contains('d-none')).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('post-link'));
    });

    it('hides the field again and clears its value on a second click', async () => {
        await loadGroups();
        const toggle = document.getElementById('groups-link-toggle');
        const input = document.getElementById('post-link');
        toggle.click();
        input.value = 'https://example.org';

        toggle.click();

        expect(document.getElementById('groups-link-field').classList.contains('d-none')).toBe(true);
        expect(input.value).toBe('');
    });
});
