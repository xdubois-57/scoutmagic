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

/** The page the composer runs on, in the shape the Twig renders it. */
function page({ background = '/medias-sociaux/7/image', blur = '0.05', title = 'Week-end' } = {}) {
    document.body.innerHTML = `
        <form>
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
        </form>
    `;

    return {
        canvas: document.querySelector('[data-card-canvas]'),
        img: document.querySelector('[data-card-preview]'),
        titleField: document.getElementById('communication-title'),
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

beforeEach(() => {
    images = [];
    frames = [];
    // rAF collected rather than run, so a test can prove two inputs
    // inside one frame cost ONE draw.
    vi.stubGlobal('requestAnimationFrame', (callback) => {
        frames.push(callback);

        return frames.length;
    });
    vi.stubGlobal('Image', FakeImage);
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
            blurRatio: 0.05,
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
