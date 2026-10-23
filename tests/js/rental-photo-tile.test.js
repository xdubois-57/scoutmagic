// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/rental-photo-tile.js
// (imported below, never reimplemented here): the « + Photo » tile of an
// incident and of a meter reading (#989, lot B).
//
// The markup mirrors views/management/_photo_tile.html.twig, whose own
// rendering is pinned by Tests\Modules\Rental\Controller\PhotoTileTemplateTest.
// jsdom has no DataTransfer and no writable `files`, so both are stubbed;
// the reduction is photo-reduce.js's and is replaced by a recording stub.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

let tile;

function stubBrowser() {
    globalThis.DataTransfer = class {
        constructor() {
            this._files = [];
            this.items = { add: (file) => this._files.push(file) };
        }
        get files() {
            return this._files;
        }
    };
    URL.createObjectURL = vi.fn((file) => 'blob:http://localhost/' + file.name);
    URL.revokeObjectURL = vi.fn();
    window.ScoutMagicPhotoReduce = {
        reducePhoto: vi.fn((file) => Promise.resolve(
            new File(['jpeg'], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' })
        )),
    };
}

/** Makes an input's `files` writable and observable, as a browser has it. */
function shadowFiles(input) {
    let files = [];
    Object.defineProperty(input, 'files', {
        configurable: true,
        get: () => files,
        set: (value) => { files = value; },
    });
}

function build({ max = 5, attached = 0, autosubmit = false } = {}) {
    document.body.innerHTML = `
        <form id="host" action="/mes-locations/incident" ${autosubmit ? 'data-photo-autosubmit' : ''}>
            <div data-photo-tile data-photo-max="${max}" data-photo-attached="${attached}">
                ${Array.from({ length: attached }, (_, i) => `
                    <figure class="photo-thumb">
                        <img src="/files/${i + 10}/thumb" alt="Vue ${i + 1} — Vitre cassée">
                        <button type="submit" form="remove-${i}" class="photo-thumb-remove">×</button>
                    </figure>`).join('')}
                <div class="dropdown photo-tile-add" data-photo-add>
                    <button type="button" data-bs-toggle="dropdown">+ Photo</button>
                    <ul class="dropdown-menu">
                        <li><button type="button" data-photo-pick="camera">Prendre une photo</button></li>
                        <li><button type="button" data-photo-pick="gallery">Choisir dans la galerie</button></li>
                    </ul>
                </div>
                <input type="file" hidden data-photo-source="camera" capture="environment">
                <input type="file" hidden data-photo-source="gallery" multiple>
                <input type="file" name="photos[]" multiple data-photo-carrier>
                <output data-photo-status></output>
            </div>
            <button type="submit" id="send">Ajouter</button>
        </form>
        ${Array.from({ length: attached }, (_, i) => `<form id="remove-${i}" data-confirm="Retirer ?"></form>`).join('')}`;
    tile = document.querySelector('[data-photo-tile]');
    tile.querySelectorAll('input[type="file"]').forEach(shadowFiles);
}

function input(source) {
    return tile.querySelector(`[data-photo-source="${source}"]`);
}

function carried() {
    return Array.from(tile.querySelector('[data-photo-carrier]').files);
}

function photo(name) {
    return new File(['raw'], name, { type: 'image/jpeg' });
}

/** Picks files through one of the two pickers, and waits for the reduction. */
async function pick(source, ...files) {
    const picker = input(source);
    picker.files = files;
    picker.dispatchEvent(new Event('change', { bubbles: true }));
    await vi.waitFor(() => expect(tile.getAttribute('aria-busy')).toBe('false'));
}

async function load() {
    vi.resetModules();
    delete document.scoutMagicPhotoTileWired;
    return import('../../public/assets/js/rental-photo-tile.js');
}

describe('rental-photo-tile', () => {
    const listeners = [];

    beforeEach(() => {
        stubBrowser();
        const register = document.addEventListener.bind(document);
        vi.spyOn(document, 'addEventListener').mockImplementation((type, handler, options) => {
            listeners.push([type, handler, options]);
            register(type, handler, options);
        });
    });

    afterEach(() => {
        listeners.splice(0).forEach(([type, handler, options]) =>
            document.removeEventListener(type, handler, options));
        vi.restoreAllMocks();
        document.documentElement.classList.remove('photo-tile-ready');
        delete globalThis.DataTransfer;
        delete window.ScoutMagicPhotoReduce;
    });

    it('marks the page ready, which swaps the plain file field for the tile', async () => {
        build();
        await load();

        expect(document.documentElement.classList.contains('photo-tile-ready')).toBe(true);
    });

    it('keeps the plain field where the browser cannot fill an input', async () => {
        delete globalThis.DataTransfer;
        build();
        await load();

        expect(document.documentElement.classList.contains('photo-tile-ready')).toBe(false);
    });

    it('opens the camera for « Prendre une photo » and the gallery for the other choice', async () => {
        build();
        await load();
        const camera = vi.spyOn(input('camera'), 'click');
        const gallery = vi.spyOn(input('gallery'), 'click');

        tile.querySelector('[data-photo-pick="camera"]').click();
        expect(camera).toHaveBeenCalledTimes(1);
        expect(gallery).not.toHaveBeenCalled();
        expect(input('camera').getAttribute('capture')).toBe('environment');

        tile.querySelector('[data-photo-pick="gallery"]').click();
        expect(gallery).toHaveBeenCalledTimes(1);
    });

    it('reduces each photo picked and puts the reduced files in the one named field', async () => {
        build();
        await load();

        await pick('gallery', photo('vue.HEIC'), photo('detail.png'));

        expect(window.ScoutMagicPhotoReduce.reducePhoto).toHaveBeenCalledTimes(2);
        expect(carried().map((file) => file.name)).toEqual(['vue.jpg', 'detail.jpg']);
        expect(carried().every((file) => file.type === 'image/jpeg')).toBe(true);
    });

    it('adds a camera photo to those already waiting rather than replacing them', async () => {
        build();
        await load();

        await pick('camera', photo('un.jpg'));
        await pick('camera', photo('deux.jpg'));

        expect(carried().map((file) => file.name)).toEqual(['un.jpg', 'deux.jpg']);
        expect(input('camera').value).toBe('');
    });

    it('shows each waiting photo as a thumbnail before the tile, from an object URL', async () => {
        build({ attached: 1 });
        await load();

        await pick('camera', photo('vitre.jpg'));

        const pending = tile.querySelectorAll('[data-photo-pending]');
        expect(pending).toHaveLength(1);
        expect(pending[0].nextElementSibling).toBe(tile.querySelector('[data-photo-add]'));
        const img = pending[0].querySelector('img');
        expect(img.getAttribute('src')).toBe('blob:http://localhost/vitre.jpg');
        // Numbered after the photo already attached.
        expect(img.alt).toBe('Photo 2, pas encore envoyée');
    });

    it('builds the thumbnail without parsing the file name as markup', async () => {
        build();
        await load();

        await pick('camera', photo('<img src=x onerror=alert(1)>.jpg'));

        expect(tile.querySelectorAll('img[onerror]')).toHaveLength(0);
    });

    it('takes a waiting photo back with its cross, without asking', async () => {
        build();
        await load();
        await pick('gallery', photo('un.jpg'), photo('deux.jpg'));

        tile.querySelector('[data-photo-pending] [data-photo-drop="0"]').click();

        expect(carried().map((file) => file.name)).toEqual(['deux.jpg']);
        expect(tile.querySelectorAll('[data-photo-pending]')).toHaveLength(1);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/un.jpg');
    });

    it('holds the limit: five photos, those attached counted, the tile gone once reached', async () => {
        build({ max: 5, attached: 3 });
        const { remaining } = await load();
        expect(remaining(tile)).toBe(2);

        await pick('gallery', photo('a.jpg'), photo('b.jpg'), photo('c.jpg'), photo('d.jpg'));

        expect(carried().map((file) => file.name)).toEqual(['a.jpg', 'b.jpg']);
        expect(tile.querySelector('[data-photo-status]').textContent)
            .toBe('5 photos au plus : 2 n\'ont pas été gardées.');
        expect(tile.querySelector('[data-photo-add]').hidden).toBe(true);
        expect(window.ScoutMagicPhotoReduce.reducePhoto).toHaveBeenCalledTimes(2);
    });

    it('gives the tile back when a waiting photo is taken back below the limit', async () => {
        build({ max: 1 });
        await load();
        await pick('camera', photo('compteur.jpg'));
        expect(tile.querySelector('[data-photo-add]').hidden).toBe(true);

        tile.querySelector('[data-photo-drop="0"]').click();

        expect(tile.querySelector('[data-photo-add]').hidden).toBe(false);
        expect(carried()).toEqual([]);
    });

    it('says a reading takes one photo', async () => {
        const { droppedMessage } = await load();

        expect(droppedMessage(1, 1)).toBe('Une seule photo ici : les autres n\'ont pas été gardées.');
        expect(droppedMessage(5, 1)).toBe('5 photos au plus : 1 n\'a pas été gardée.');
        expect(droppedMessage(5, 0)).toBe('');
    });

    it('locks « Ajouter » while the photos are reduced, and leaves a cross of another form alone', async () => {
        let release;
        window.ScoutMagicPhotoReduce.reducePhoto = vi.fn((file) => new Promise((resolve) => { release = () => resolve(file); }));
        build({ attached: 1 });
        await load();
        const picker = input('camera');
        picker.files = [photo('un.jpg')];
        picker.dispatchEvent(new Event('change', { bubbles: true }));

        expect(document.getElementById('send').disabled).toBe(true);
        expect(tile.querySelector('.photo-thumb-remove').disabled).toBe(false);
        expect(tile.querySelector('[data-photo-status]').textContent).toBe('Préparation de la photo…');

        release();
        await vi.waitFor(() => expect(document.getElementById('send').disabled).toBe(false));
    });

    it('sends the form of an incident already observed as soon as its photo is ready', async () => {
        build({ autosubmit: true, attached: 2 });
        await load();
        const form = document.getElementById('host');
        /** @type {string[][]} */
        const sent = [];
        const submit = vi.spyOn(form, 'requestSubmit').mockImplementation(() => {
            sent.push(carried().map((file) => file.name));
        });

        await pick('camera', photo('gros-plan.jpg'));

        expect(submit).toHaveBeenCalledTimes(1);
        expect(sent).toEqual([['gros-plan.jpg']]);
        expect(carried()).toEqual([]);

        // The panel did not reload: the next photo goes alone, never with
        // the one already sent.
        await pick('camera', photo('vue.jpg'));
        expect(sent).toEqual([['gros-plan.jpg'], ['vue.jpg']]);
    });

    it('waits for « Ajouter » on the report form', async () => {
        build();
        await load();
        const submit = vi.spyOn(document.getElementById('host'), 'requestSubmit');

        await pick('camera', photo('vue.jpg'));

        expect(submit).not.toHaveBeenCalled();
    });

    it('ignores a menu entry naming another source', async () => {
        build();
        await load();
        const camera = vi.spyOn(input('camera'), 'click');
        const entry = tile.querySelector('[data-photo-pick="camera"]');
        entry.dataset.photoPick = 'camera"],[data-photo-carrier';

        entry.click();

        expect(camera).not.toHaveBeenCalled();
    });

    it('lets go of the object URLs of a panel re-rendered by rental-booking.js', async () => {
        build();
        await load();
        await pick('camera', photo('vue.jpg'));

        document.body.innerHTML = '<div data-photo-tile></div>';
        document.dispatchEvent(new CustomEvent('rental-booking:refreshed'));

        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/vue.jpg');
    });
});
