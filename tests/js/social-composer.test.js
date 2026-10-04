// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/social-composer.js (imported below, never reimplemented
// here): the wiring between the composer's page and the card engine
// (issue #706, IT-02).
//
// What this file is really about is the FALLBACK. The page renders two
// things — the card the server composed, as an <img>, and an empty
// <canvas> — and the canvas takes the <img>'s place only once it has
// actually drawn. Every way that draw can fail is a real browser state,
// and in each the <img> has to survive: on a source-backed composer
// « Publier » is the only button, so a page showing no image at all would
// turn the first POST into an irreversible public post of something
// nobody saw.
//
// jsdom gives no 2D context (`getContext('2d')` returns null and logs
// « Not implemented »), so the context is a recording double, installed
// with `vi.spyOn` rather than by assigning the prototype — `vitest.config.js`
// sets no `restoreMocks`, and a bare assignment leaks into the rest of the
// file.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** Every image jsdom's `new Image()` hands out, so a test can fire its events. */
let images = [];

class FakeImage {
    constructor() {
        this.width = 1200;
        this.height = 900;
        this.crossOrigin = null;
        this._src = '';
        this.listeners = {};
        images.push(this);
    }

    set src(value) {
        this._src = value;
    }

    get src() {
        return this._src;
    }

    addEventListener(type, handler) {
        this.listeners[type] = handler;
    }

    fire(type) {
        if (this.listeners[type]) {
            this.listeners[type]();
        }
    }
}

/** A FileList-alike: what a real DataTransfer hands to input.files. */
function fileList(files) {
    const list = { length: files.length, item: (i) => files[i] || null };
    files.forEach((file, i) => {
        list[i] = file;
    });

    return list;
}

/**
 * The DataTransfer the browser has and jsdom does not — the same double
 * `tests/js/finance-receipt-form.test.js` uses, since both files exercise
 * the one way to rewrite an <input type="file">'s selection.
 */
class FakeDataTransfer {
    constructor() {
        this._files = [];
        this.items = { add: (file) => this._files.push(file) };
    }

    get files() {
        return fileList(this._files);
    }
}

/** `input.files` is read-only in jsdom; the production code assigns to it. */
function makeFilesWritable(input) {
    Object.defineProperty(input, 'files', {
        configurable: true,
        writable: true,
        value: fileList([]),
    });
}

/** The page the composer runs on, in the shape the Twig renders it. */
function page({
    background = '/medias-sociaux/7/image',
    blur = '0.025',
    title = 'Week-end',
    slider = false,
} = {}) {
    document.body.innerHTML = `
        <form data-card-form>
          <div data-communication-image data-card-composer>
            <img src="/medias-sociaux/7/apercu" data-card-preview alt="Carte">
            <canvas width="1080" height="1080" hidden
                    data-card-canvas
                    data-card-background="${background}"
                    data-card-address="unite.example"
                    data-card-blur="${blur}"
                    data-card-title="${title}"></canvas>
          </div>
          <input type="text" id="communication-title" value="${title}">
          ${slider ? `
            <input type="range" id="communication-blur" name="blur_ratio"
                   min="0" max="0.2" step="0.005" value="${blur}" data-card-blur-input>
            <p>
              <span data-card-blur-sharp hidden>Cette photo de la galerie part sans flou.</span>
              <span data-card-blur-blurred hidden>On devine l'ambiance, pas les visages.</span>
            </p>
          ` : ''}
          <input type="file" name="card" data-card-file>
          <button type="submit" name="action" value="gallery">Galerie</button>
          <button type="submit" name="action" value="publish">Publier</button>
        </form>
    `;

    const field = document.querySelector('[data-card-file]');
    makeFilesWritable(field);

    return {
        canvas: document.querySelector('[data-card-canvas]'),
        img: document.querySelector('[data-card-preview]'),
        titleField: document.getElementById('communication-title'),
        form: document.querySelector('[data-card-form]'),
        cardField: field,
        publish: document.querySelector('button[value="publish"]'),
        gallery: document.querySelector('button[value="gallery"]'),
        blurField: document.querySelector('[data-card-blur-input]'),
        sharpNote: document.querySelector('[data-card-blur-sharp]'),
        blurredNote: document.querySelector('[data-card-blur-blurred]'),
    };
}

/** A card engine double: records each draw, and can be made to fail. */
function engine({ drawSucceeds = true } = {}) {
    const draws = [];

    return {
        draws,
        wrapTitle: () => [],
        fitLine: (line) => line,
        toJpeg: () => Promise.resolve(null),
        cardConstants: () => ({}),
        drawCard: (canvas, card) => {
            draws.push({ canvas, ...card });

            return drawSucceeds;
        },
    };
}

/** Runs the production file against the page as it stands. */
async function run() {
    vi.resetModules();
    await import('../../public/assets/js/social-composer.js');
}

let frames = [];
/** Every form.submit() the production code performed. */
let submits = 0;

beforeEach(() => {
    submits = 0;
    images = [];
    frames = [];
    // rAF collected rather than run, so a test can prove two inputs
    // inside one frame cost ONE draw.
    vi.stubGlobal('requestAnimationFrame', (callback) => {
        frames.push(callback);

        return frames.length;
    });
    vi.stubGlobal('Image', FakeImage);
    // jsdom has no form.submit() implementation and no DataTransfer, the
    // two things the publish path is built on — both stubbed, and the
    // absence of DataTransfer is itself covered below, because it is a
    // real browser state (Safari had no constructor until 14.1).
    HTMLFormElement.prototype.submit = function () {
        submits += 1;
    };
    vi.stubGlobal('DataTransfer', FakeDataTransfer);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    delete window.ScoutMagicCard;
});

/** Runs every frame queued so far. */
function flush() {
    const queued = frames.slice();
    frames = [];
    queued.forEach((callback) => callback());
}

describe('once the background has loaded', () => {
    it('draws the card and gives the canvas the server image\'s place', async () => {
        const dom = page();
        const card = engine();
        window.ScoutMagicCard = card;
        await run();

        expect(dom.canvas.hidden).toBe(true);
        expect(images).toHaveLength(1);
        expect(images[0].src).toBe('/medias-sociaux/7/image');

        images[0].fire('load');
        flush();

        expect(card.draws).toHaveLength(1);
        expect(card.draws[0]).toMatchObject({
            title: 'Week-end',
            address: 'unite.example',
            // The shipped starting position, halved in IT-02.
            blurRatio: 0.025,
        });
        expect(card.draws[0].image).toBe(images[0]);
        expect(dom.canvas.hidden).toBe(false);
        // Removed, not hidden: two images of the same card, one of them
        // stale, is what a screen reader would announce twice.
        expect(document.querySelector('[data-card-preview]')).toBeNull();
    });

    it('asks for the image anonymously, so the canvas stays exportable', async () => {
        page();
        window.ScoutMagicCard = engine();
        await run();

        // A tainted canvas throws on toBlob(), and « Publier » will need
        // that export.
        expect(images[0].crossOrigin).toBe('anonymous');
    });
});

describe('when the draw cannot happen', () => {
    it('leaves the server\'s image in place when the browser gives no context', async () => {
        const dom = page();
        const card = engine({ drawSucceeds: false });
        window.ScoutMagicCard = card;
        await run();

        images[0].fire('load');
        flush();

        expect(card.draws).toHaveLength(1);
        expect(dom.canvas.hidden).toBe(true);
        expect(document.querySelector('[data-card-preview]')).not.toBeNull();
    });

    it('leaves the server\'s image in place when the background will not load', async () => {
        const dom = page();
        const card = engine();
        window.ScoutMagicCard = card;
        await run();

        images[0].fire('error');
        flush();

        expect(card.draws).toHaveLength(0);
        expect(dom.canvas.hidden).toBe(true);
        expect(document.querySelector('[data-card-preview]')).not.toBeNull();
    });

    it('does nothing at all when the engine did not load', async () => {
        const dom = page();
        // No window.ScoutMagicCard: the script tag order broke, or the
        // file 404'd.
        await run();

        expect(images).toHaveLength(0);
        expect(dom.canvas.hidden).toBe(true);
        expect(document.querySelector('[data-card-preview]')).not.toBeNull();
    });

    it('is a no-op on a page that has no composer', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        window.ScoutMagicCard = engine();

        await expect(run()).resolves.toBeUndefined();
        expect(images).toHaveLength(0);
    });

    it('is a no-op when the page carries no canvas', async () => {
        document.body.innerHTML = '<div data-card-composer><img data-card-preview></div>';
        const card = engine();
        window.ScoutMagicCard = card;
        await run();

        expect(card.draws).toHaveLength(0);
        expect(document.querySelector('[data-card-preview]')).not.toBeNull();
    });
});

describe('the title follows the typing', () => {
    it('redraws with the field\'s current value', async () => {
        const dom = page();
        const card = engine();
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();

        dom.titleField.value = 'Week-end de rentrée';
        dom.titleField.dispatchEvent(new Event('input'));
        flush();

        expect(card.draws).toHaveLength(2);
        expect(card.draws[1].title).toBe('Week-end de rentrée');
    });

    it('costs ONE draw for several keystrokes inside one frame', async () => {
        const dom = page();
        const card = engine();
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();

        dom.titleField.value = 'W';
        dom.titleField.dispatchEvent(new Event('input'));
        dom.titleField.value = 'We';
        dom.titleField.dispatchEvent(new Event('input'));
        dom.titleField.value = 'Wee';
        dom.titleField.dispatchEvent(new Event('input'));

        // Three inputs, one frame queued — a timer-based debounce would
        // instead show the card lagging behind the typing.
        expect(frames).toHaveLength(1);
        flush();
        expect(card.draws).toHaveLength(2);
        expect(card.draws[1].title).toBe('Wee');
    });

    it('does not keep swapping the image once it has swapped', async () => {
        const dom = page();
        const card = engine();
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();
        expect(document.querySelector('[data-card-preview]')).toBeNull();

        dom.titleField.dispatchEvent(new Event('input'));
        flush();

        expect(card.draws).toHaveLength(2);
        expect(dom.canvas.hidden).toBe(false);
    });
});

describe('the blur slider', () => {
    it('draws with the slider\'s position rather than the server\'s', async () => {
        const dom = page({ slider: true, blur: '0.025' });
        const card = engine();
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();
        expect(card.draws[0].blurRatio).toBe(0.025);

        dom.blurField.value = '0.1';
        dom.blurField.dispatchEvent(new Event('input'));
        flush();

        expect(card.draws[1].blurRatio).toBe(0.1);
    });

    it('draws a gallery photo SHARP at « Net », with no floor under it', async () => {
        const dom = page({ slider: true, blur: '0.025' });
        const card = engine();
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();

        dom.blurField.value = '0';
        dom.blurField.dispatchEvent(new Event('input'));
        flush();

        // The floor (CardService::MIN_BLUR_RATIO) is gone: « Net » means
        // net, and a slider whose left end did nothing would be a lie
        // told in an interface.
        expect(card.draws[card.draws.length - 1].blurRatio).toBe(0);
    });

    it('warns, in words, only when the photo is about to leave sharp', async () => {
        const dom = page({ slider: true, blur: '0.025' });
        window.ScoutMagicCard = engine();
        await run();

        // Blurred to start with: the reassuring half shows, the warning
        // does not.
        expect(dom.sharpNote.hidden).toBe(true);
        expect(dom.blurredNote.hidden).toBe(false);

        dom.blurField.value = '0';
        dom.blurField.dispatchEvent(new Event('input'));

        expect(dom.sharpNote.hidden).toBe(false);
        expect(dom.blurredNote.hidden).toBe(true);

        dom.blurField.value = '0.05';
        dom.blurField.dispatchEvent(new Event('input'));

        expect(dom.sharpNote.hidden).toBe(true);
        expect(dom.blurredNote.hidden).toBe(false);
    });

    it('says which it is before the first frame, not after it', async () => {
        // The sentence is set on the input event itself rather than in the
        // draw, so it is right even on a frame the browser skips.
        const dom = page({ slider: true, blur: '0' });
        window.ScoutMagicCard = engine();
        await run();

        expect(frames).toHaveLength(0);
        expect(dom.sharpNote.hidden).toBe(false);
    });

    it('falls back to the server\'s strength when the page offers no slider', async () => {
        // An uploaded image has nothing to blur, and a frozen card has
        // nothing left to choose: no slider is rendered in either case.
        const dom = page({ slider: false, blur: '0.05' });
        const card = engine();
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();

        expect(dom.blurField).toBeNull();
        expect(card.draws[0].blurRatio).toBe(0.05);
    });
});

describe('« Publier » sends the card the page drew', () => {
    /** The page, drawn, with a card engine whose export resolves to `blob`. */
    async function ready(blob) {
        const dom = page({ slider: true });
        const card = engine();
        card.toJpeg = () => Promise.resolve(blob);
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();

        return { dom, card };
    }

    it('attaches the exported card to the form and posts it', async () => {
        const blob = { size: 1234, type: 'image/jpeg' };
        const { dom } = await ready(blob);

        dom.publish.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(dom.cardField.files).toHaveLength(1);
        expect(dom.cardField.files[0].name).toBe('carte.jpg');
        expect(dom.cardField.files[0].type).toBe('image/jpeg');
        expect(submits).toBe(1);
    });

    /**
     * **`form.submit()` does not include the button that submitted.**
     * Without a hidden field carrying it, `action=publish` is simply
     * absent and the controller falls through to « no action »: nothing
     * saved, nothing published, and a page that looks like it worked.
     */
    it('carries action=publish in a field, since the button is not submitted', async () => {
        const { dom } = await ready({ size: 1, type: 'image/jpeg' });

        dom.publish.click();
        await Promise.resolve();
        await Promise.resolve();

        const hidden = Array.from(dom.form.querySelectorAll('input[type="hidden"][name="action"]'));
        expect(hidden).toHaveLength(1);
        expect(hidden[0].value).toBe('publish');
    });

    it('holds the publish button from the click to the navigation', async () => {
        const { dom } = await ready({ size: 1, type: 'image/jpeg' });

        dom.publish.click();

        // Held synchronously, before the export has resolved: the click
        // that starts the export is the click that closes the button.
        expect(dom.publish.disabled).toBe(true);
        expect(dom.publish.textContent).toContain('Publication en cours');
    });

    it('refuses a second « Publier » while the first is still going', async () => {
        const { dom } = await ready({ size: 1, type: 'image/jpeg' });

        dom.publish.click();
        await Promise.resolve();
        await Promise.resolve();
        expect(submits).toBe(1);

        // The button is disabled, so a real second click cannot happen —
        // but a submit can still be dispatched (a keyboard Enter, a
        // script), and a second public post is what this prevents.
        dom.form.dispatchEvent(new Event('submit', { cancelable: true }));
        await Promise.resolve();

        expect(submits).toBe(1);
    });

    it('does not intercept the gallery and upload round trips', async () => {
        const { dom } = await ready({ size: 1, type: 'image/jpeg' });

        dom.gallery.click();
        await Promise.resolve();

        // Those two are plain submits that keep the draft: no card, and
        // nothing prevented, so the browser posts them itself.
        expect(submits).toBe(0);
        expect(dom.cardField.files).toHaveLength(0);
        expect(dom.form.querySelectorAll('input[type="hidden"][name="action"]')).toHaveLength(0);
    });

    it('still posts when the export fails, so the server composes the card', async () => {
        const dom = page({ slider: true });
        const card = engine();
        card.toJpeg = () => Promise.reject(new Error('tainted canvas'));
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();

        dom.publish.click();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        // No card travelled, and that is the same path a share made
        // before IT-02 takes: the publication composes one rather than
        // refusing to happen.
        expect(dom.cardField.files).toHaveLength(0);
        expect(submits).toBe(1);
    });

    it('posts without a card when the browser has no DataTransfer', async () => {
        // Safari had no DataTransfer constructor until 14.1.
        vi.stubGlobal('DataTransfer', undefined);
        const { dom } = await ready({ size: 1, type: 'image/jpeg' });

        dom.publish.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(submits).toBe(1);
        expect(dom.cardField.files).toHaveLength(0);
    });

    it('lets the form post as it is when nothing was ever drawn', async () => {
        const dom = page({ slider: true });
        const card = engine({ drawSucceeds: false });
        window.ScoutMagicCard = card;
        await run();
        images[0].fire('load');
        flush();

        dom.publish.click();
        await Promise.resolve();

        // Not intercepted — the browser posts it, carrying the button —
        // but the button is still held, because the request is still
        // several seconds of waiting.
        expect(submits).toBe(0);
        expect(dom.publish.disabled).toBe(true);
    });
});

describe('a canvas with an empty background attribute', () => {
    // Not a state the page can reach: `edit.html.twig` renders the canvas
    // only inside `{% if has_image and preview_path %}`, so a composer
    // with no image shows the « Choisissez une image » alert and no
    // canvas at all. Kept as a guard on the one thing that WOULD bite if
    // the template changed — `new Image()` with `src = ''` is a request
    // for the current page in several browsers, which would fetch the
    // composer's own HTML and decode it as a photo.
    it('fetches nothing rather than requesting the page itself', async () => {
        page({ background: '' });
        const card = engine();
        window.ScoutMagicCard = card;
        await run();

        expect(images).toHaveLength(0);
        expect(card.draws).toHaveLength(0);
        // And the server's card is still there, which is the whole point.
        expect(document.querySelector('[data-card-preview]')).not.toBeNull();
    });
});
