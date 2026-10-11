// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/social-share.js (imported below, never reimplemented
// here): sharing a communication by hand from the history, issue #706,
// IT-05.
//
// **What only a test can see here** is the order of the two requests and
// the one case that must produce neither of them: a share sheet the chief
// dismissed. The chantier says a dismissed sheet is not a share, and the
// consequence is not cosmetic — recording it would freeze the
// communication and write a line in « Ce qui est parti » for something
// that never left.
//
// **And since PR #868 it is also the moment each request leaves.** The
// address is asked for on the PRESS so the sheet can be opened from the
// click handler itself, holding the activation the tap gave it. Note
// that `HTMLElement.click()` fires no `pointerdown`, so every test that
// uses it exercises the slow path — the one where the click outran the
// address — which is why they all kept passing when the press arrived.
// `press()` below is what a finger actually does.
//
// The file runs its work at import, so each test builds the DOM first and
// then imports it with a fresh module registry (`vi.resetModules()`),
// following `tests/js/upload-drop-zone.test.js`.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** The posts the page made, in order. */
let posted;

/**
 * The page the script expects: one entry with a share button, the live
 * region it writes into, and the API double every other page gets from
 * `public/assets/js/api.js`.
 */
function buildPage() {
    document.body.innerHTML = `
        <button type="button" data-share-communication hidden
                data-share-link-url="/medias-sociaux/7/lien"
                data-share-record-url="/medias-sociaux/7/partage"
                data-share-title="Week-end de rentrée">Partager</button>
        <output data-share-feedback hidden></output>
    `;

    posted = [];
    window.ScoutMagicApi = {
        postJson: (url, body) => {
            posted.push({ url, body });

            return Promise.resolve(
                url.endsWith('/lien')
                    ? { ok: true, status: 200, data: { success: true, url: 'https://unite.example/partage/abc' } }
                    : { ok: true, status: 200, data: { success: true, recorded: true } }
            );
        },
        // **Deferred by a microtask, exactly like the real one**
        // (`Promise.resolve().then(run)` in `api.js`). It used to call
        // `run` straight away, which was the convenient shape and the
        // wrong one: the deferral is the boundary a share sheet's user
        // activation cannot be trusted to cross, so a double without it
        // leaves « the sheet opens from the click handler itself »
        // unprovable — the test would pass with the gesture buried
        // inside the helper. It only has to hand the promise back so a
        // test can await it; disabling the control is not this file's
        // subject.
        withDisabled: (control, run) => Promise.resolve().then(run),
    };
}

/** Imports the script fresh, so its import-time work runs again. */
async function load() {
    vi.resetModules();
    await import('../../public/assets/js/social-share.js');
}

const button = () => document.querySelector('[data-share-communication]');
const feedback = () => document.querySelector('[data-share-feedback]');

/** Lets the script's promise chain settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    buildPage();
    // jsdom has neither, and their absence is itself a case below.
    delete navigator.share;
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: vi.fn().mockResolvedValue(undefined) },
        configurable: true,
    });
    // The script reloads the page to show the new history line; jsdom
    // refuses a real navigation, so it is replaced.
    Object.defineProperty(window, 'location', {
        value: { reload: vi.fn() },
        configurable: true,
        writable: true,
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    delete window.ScoutMagicApi;
});

describe('the share button', () => {
    it('stays hidden when nothing could hand the link over', async () => {
        delete navigator.share;
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });

        await load();

        // A button that can do nothing is worse than no button: the page
        // renders it hidden and this file is what unhides it.
        expect(button().hidden).toBe(true);
    });

    it('is unhidden once there is a way to share', async () => {
        await load();

        expect(button().hidden).toBe(false);
    });
});

describe('a page with nothing to share', () => {
    /**
     * « Ce qui est parti » can be empty, or hold only entries with no
     * public page yet. The script must then do nothing at all — not
     * throw, which would take down whatever runs after it.
     */
    it('does nothing when there is no share button', async () => {
        document.body.innerHTML = '<p>Rien n’est encore parti.</p>';

        await load();

        expect(document.querySelectorAll('[data-share-communication]')).toHaveLength(0);
    });

    /**
     * **Without `api.js` there is nothing to share with.** The button
     * needs two POSTs, both through `ScoutMagicApi`; a page where that
     * script did not load must leave the button hidden rather than
     * offer a control that can only throw.
     */
    it('stays hidden when the API helper did not load', async () => {
        delete window.ScoutMagicApi;

        await load();

        expect(button().hidden).toBe(true);
    });

    /**
     * And the live region is the history's, not this script's: a page
     * that renders the button without it still shares, silently.
     */
    it('shares without a live region to write into', async () => {
        document.querySelector('[data-share-feedback]').remove();

        await load();
        button().click();
        await settle();

        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien', '/medias-sociaux/7/partage']);
    });
});

describe('without a share sheet', () => {
    it('copies the link, records a copy, and says so', async () => {
        await load();
        button().click();
        await settle();

        // The clipboard takes the address alone: a title pasted into
        // WhatsApp beside the link would be text nobody asked for.
        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://unite.example/partage/abc');
        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien', '/medias-sociaux/7/partage']);
        expect(posted[1].body).toEqual({ kind: 'copy' });
        expect(feedback().textContent).toBe('Lien copié');
        expect(feedback().hidden).toBe(false);
    });

    /**
     * **The reload waits for « Lien copié » to be read** (`Claude review`
     * on PR #868).
     *
     * It is the only confirmation a copy gets — the share sheet is its
     * own receipt, the clipboard is silent — and the help page promises
     * it in words. An immediate reload took the message away with the
     * rest of the DOM, which no test could see while `reload` is a
     * no-op double: hence the fake timers here, which make the ORDER
     * assertable rather than the fact of reloading.
     */
    it('lets the message be read before taking the page away', async () => {
        vi.useFakeTimers();
        try {
            await load();
            button().click();
            await vi.advanceTimersByTimeAsync(0);

            const beforeTheWait = window.location.reload.mock.calls.length;
            await vi.advanceTimersByTimeAsync(1200);

            expect([beforeTheWait, feedback().textContent, window.location.reload.mock.calls.length])
                .toEqual([0, 'Lien copié', 1]);
        } finally {
            vi.useRealTimers();
        }
    });

    it('records nothing when the clipboard refuses', async () => {
        navigator.clipboard.writeText.mockRejectedValue(new Error('denied'));

        await load();
        button().click();
        await settle();

        // The link was asked for — that is what created the page — but
        // nothing reached the clipboard, so nothing is recorded and the
        // communication is not frozen.
        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien']);
        expect(feedback().textContent).toBe('Le partage n’a pas abouti.');
    });
});

describe('with a share sheet', () => {
    it('opens the sheet and records a native share once it went through', async () => {
        navigator.share = vi.fn().mockResolvedValue(undefined);

        await load();
        button().click();
        await settle();

        // **Title and address**, as specifications.md § 47.10 promises:
        // a bare URL tells the chief's own contacts nothing about what
        // they are being sent (`Claude review` on PR #868).
        expect(navigator.share).toHaveBeenCalledWith({
            title: 'Week-end de rentrée',
            url: 'https://unite.example/partage/abc',
        });
        expect(navigator.clipboard.writeText).not.toHaveBeenCalled();
        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien', '/medias-sociaux/7/partage']);
        expect(posted[1].body).toEqual({ kind: 'native' });
        // No wait here, and none wanted: the sheet was its own receipt,
        // so there is no message the reload could wipe.
        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    /**
     * **The case the whole two-request shape exists for.** Every browser
     * rejects with an `AbortError` when the sheet is dismissed, and the
     * only right answer is silence: no history line, no freeze, and no
     * error message for something the chief chose to do.
     */
    it('records nothing and says nothing when the sheet is dismissed', async () => {
        const aborted = new Error('cancelled');
        aborted.name = 'AbortError';
        navigator.share = vi.fn().mockRejectedValue(aborted);

        await load();
        button().click();
        await settle();

        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien']);
        expect(feedback().hidden).toBe(true);
        expect(feedback().textContent).toBe('');
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    /**
     * A communication with no title — the history renders one for a row
     * whose source has lost its own — hands the sheet the address alone
     * rather than an empty title, which some sheets display as a blank
     * line.
     */
    it('leaves the title out rather than sending an empty one', async () => {
        button().removeAttribute('data-share-title');
        navigator.share = vi.fn().mockResolvedValue(undefined);

        await load();
        button().click();
        await settle();

        expect(navigator.share).toHaveBeenCalledWith({ url: 'https://unite.example/partage/abc' });
    });

    /**
     * **Activation lost: the link goes to the clipboard, not nowhere**
     * (`Claude review` on PR #868).
     *
     * `navigator.share()` needs transient user activation, and ours is
     * spent crossing the network — the click mints the public page
     * first, because there is no address to share before it exists.
     * WebKit answers `NotAllowedError` for that gap, which would have
     * surfaced as « Le partage n'a pas abouti. » on the very device the
     * sheet exists for. The fallback is a safety net rather than the
     * answer, and the history records what really happened: a copy.
     */
    it('falls back to the clipboard when the sheet refuses for lost activation', async () => {
        const denied = new Error('not allowed');
        denied.name = 'NotAllowedError';
        navigator.share = vi.fn().mockRejectedValue(denied);

        vi.useFakeTimers();
        try {
            await load();
            button().click();
            await vi.advanceTimersByTimeAsync(0);

            expect([
                navigator.clipboard.writeText.mock.calls.length,
                posted.map((p) => p.url),
                posted[1]?.body,
                feedback().textContent,
            ]).toEqual([
                1,
                ['/medias-sociaux/7/lien', '/medias-sociaux/7/partage'],
                { kind: 'copy' },
                'Lien copié',
            ]);
        } finally {
            vi.useRealTimers();
        }
    });

    /** With no clipboard either, there is nothing to fall back to. */
    it('says so when the sheet refuses and the clipboard is absent', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        const denied = new Error('not allowed');
        denied.name = 'NotAllowedError';
        navigator.share = vi.fn().mockRejectedValue(denied);

        await load();
        button().click();
        await settle();

        expect([posted.map((p) => p.url), feedback().textContent])
            .toEqual([['/medias-sociaux/7/lien'], 'Le partage n’a pas abouti.']);
    });

    it('says so when the sheet fails for any other reason', async () => {
        navigator.share = vi.fn().mockRejectedValue(new Error('boom'));

        await load();
        button().click();
        await settle();

        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien']);
        expect(feedback().textContent).toBe('Le partage n’a pas abouti.');
    });
});

describe('when recording it fails', () => {
    /**
     * **The reload would hide its own failure.** The link is already in
     * the chief's hands, but nothing was recorded and nothing frozen —
     * and a reloaded page says none of that, because it clears the live
     * region on the way out. (CodeRabbit on PR #868.)
     */
    it('says so and does not reload', async () => {
        window.ScoutMagicApi.postJson = (url, body) => {
            posted.push({ url, body });

            return Promise.resolve(
                url.endsWith('/lien')
                    ? { ok: true, status: 200, data: { url: 'https://unite.example/partage/abc' } }
                    : { ok: false, status: 403, data: { error: 'Session expirée.' } }
            );
        };

        await load();
        button().click();
        await settle();

        expect(
            [feedback().textContent, window.location.reload.mock.calls.length]
        ).toEqual(['Le lien est parti, mais le partage n’a pas pu être enregistré.', 0]);
    });
});

describe('when the helper answers nothing at all', () => {
    /**
     * `postJson()` resolves with an envelope even on a network failure,
     * so this is the shape that should not happen — and the guard reads
     * it as a refusal rather than reloading the page over a share it
     * cannot prove was recorded.
     */
    it('is read as a refusal, not as a success', async () => {
        window.ScoutMagicApi.postJson = (url, body) => {
            posted.push({ url, body });

            return Promise.resolve(
                url.endsWith('/lien')
                    ? { ok: true, status: 200, data: { url: 'https://unite.example/partage/abc' } }
                    : undefined
            );
        };

        await load();
        button().click();
        await settle();

        expect(
            [feedback().textContent, window.location.reload.mock.calls.length]
        ).toEqual(['Le lien est parti, mais le partage n’a pas pu être enregistré.', 0]);
    });
});

describe('when the link cannot be prepared', () => {
    it('reports the server’s own reason and hands nothing over', async () => {
        window.ScoutMagicApi.postJson = (url, body) => {
            posted.push({ url, body });

            return Promise.resolve({
                ok: false,
                status: 409,
                data: { success: false, error: 'Renseignez d’abord l’adresse du site.' },
            });
        };

        await load();
        button().click();
        await settle();

        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien']);
        expect(navigator.clipboard.writeText).not.toHaveBeenCalled();
        expect(feedback().textContent).toBe('Renseignez d’abord l’adresse du site.');
    });
});
describe('the page the press mints', () => {
    /**
     * What a finger or a mouse actually does. `HTMLElement.click()`
     * fires no `pointerdown`, so it is not a press — it is the slow path
     * every other test in this file uses.
     */
    const press = () => button().dispatchEvent(new Event('pointerdown', { bubbles: true }));

    const key = (name) => button().dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));

    it('asks for the address on the press, before any click', async () => {
        await load();
        press();
        await settle();

        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien']);
    });

    it('opens the sheet from the click handler itself, with the activation the tap gave it', async () => {
        navigator.share = vi.fn().mockResolvedValue(undefined);

        await load();
        press();
        await settle();

        // **Not awaited, and that is the whole assertion.** `click()`
        // returns as soon as the handler does, so a sheet that has
        // already been asked for by then was asked for synchronously —
        // which is what `navigator.share()` needs and what minting on
        // the press buys. Move the call inside `withDisabled()` and this
        // goes red, because that helper defers by a microtask.
        button().click();

        expect(navigator.share).toHaveBeenCalledWith({
            title: 'Week-end de rentrée',
            url: 'https://unite.example/partage/abc',
        });

        await settle();

        expect(posted.map((p) => p.url)).toEqual([
            '/medias-sociaux/7/lien',
            '/medias-sociaux/7/partage',
        ]);
    });

    it('asks once however many times the button is pressed', async () => {
        await load();
        press();
        press();
        await settle();
        press();
        await settle();

        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien']);
    });

    it('is minted by a keyboard press too, and by Enter or Space alone', async () => {
        await load();
        key('Tab');
        key('a');
        await settle();

        expect(posted).toEqual([]);

        key('Enter');
        await settle();

        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien']);
    });

    it('does not cache a refusal, so the next press asks again', async () => {
        let asked = 0;
        window.ScoutMagicApi.postJson = (url, body) => {
            posted.push({ url, body });
            asked += 1;

            return Promise.resolve(
                asked === 1
                    ? { ok: false, status: 409, data: { success: false, error: 'Pas encore.' } }
                    : { ok: true, status: 200, data: { success: true, url: 'https://unite.example/partage/abc' } }
            );
        };

        await load();
        press();
        await settle();
        press();
        await settle();

        // One request per button is enough because the address is
        // permanent once it exists — but an answer that carried none
        // must not freeze the button on it.
        expect(posted.map((p) => p.url)).toEqual([
            '/medias-sociaux/7/lien',
            '/medias-sociaux/7/lien',
        ]);
        expect(feedback().textContent).toBe('Pas encore.');
    });

    it('does not ask twice when the click outruns the press', async () => {
        navigator.share = vi.fn().mockResolvedValue(undefined);
        let release = () => {};
        window.ScoutMagicApi.postJson = (url, body) => {
            posted.push({ url, body });
            if (!url.endsWith('/lien')) {
                return Promise.resolve({ ok: true, status: 200, data: { success: true, recorded: true } });
            }

            return new Promise((resolve) => {
                release = () => resolve({
                    ok: true,
                    status: 200,
                    data: { success: true, url: 'https://unite.example/partage/abc' },
                });
            });
        };

        await load();
        press();
        button().click();

        // The address is not back yet, so there is nothing to share and
        // nothing to do but wait — this is the gap `sheetRefused()` nets.
        expect(navigator.share).not.toHaveBeenCalled();

        release();
        await settle();

        expect(posted.map((p) => p.url)).toEqual([
            '/medias-sociaux/7/lien',
            '/medias-sociaux/7/partage',
        ]);
        expect(navigator.share).toHaveBeenCalledTimes(1);
    });
});
