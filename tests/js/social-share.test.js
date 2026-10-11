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
                data-share-record-url="/medias-sociaux/7/partage">Partager</button>
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
        // The real one disables the control around the promise; here it
        // only has to hand the promise back so a test can await it.
        withDisabled: (control, run) => run(),
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

describe('without a share sheet', () => {
    it('copies the link, records a copy, and says so', async () => {
        await load();
        button().click();
        await settle();

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://unite.example/partage/abc');
        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien', '/medias-sociaux/7/partage']);
        expect(posted[1].body).toEqual({ kind: 'copy' });
        expect(feedback().textContent).toBe('Lien copié');
        expect(feedback().hidden).toBe(false);
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

        expect(navigator.share).toHaveBeenCalledWith({ url: 'https://unite.example/partage/abc' });
        expect(navigator.clipboard.writeText).not.toHaveBeenCalled();
        expect(posted.map((p) => p.url)).toEqual(['/medias-sociaux/7/lien', '/medias-sociaux/7/partage']);
        expect(posted[1].body).toEqual({ kind: 'native' });
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
