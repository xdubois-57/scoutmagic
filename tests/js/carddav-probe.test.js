// Isolated JavaScript unit test — jsdom only, fetch mocked, exercising
// the REAL public/assets/js/carddav-probe.js (imported below, never
// reimplemented here). The file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// What is under test is the verdict, and the verdict is the whole point
// of the feature: a chef d'unité whose phone says « impossible de se
// connecter » has no way of telling a wrong password from a web server
// that ate the request, and this button is what tells them which it is.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** core/View/templates/account/devices.html.twig, reduced to what the script reads. */
const PAGE = `
    <div id="carddav-probe-section">
        <button type="button" id="carddav-probe-btn">Vérifier</button>
        <output id="carddav-probe-result" class="d-block small mt-2"></output>
    </div>`;

function answer(status) {
    return Promise.resolve({ ok: status < 400, status });
}

describe('carddav-probe.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => answer(200));
    });

    const boot = () => import('../../public/assets/js/carddav-probe.js');
    const el = (id) => document.getElementById(id);

    it('does nothing at all on a page without its button', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        await boot();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('sends the two unusual methods at the probe endpoint', async () => {
        await boot();
        el('carddav-probe-btn').click();
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));

        const methods = global.fetch.mock.calls.map((call) => call[1].method).sort();
        expect(methods).toEqual(['PROPFIND', 'REPORT']);
        expect(global.fetch.mock.calls[0][0]).toBe('/api/carddav/probe');
    });

    it('says so when both methods reach the site', async () => {
        await boot();
        el('carddav-probe-btn').click();

        await vi.waitFor(() => expect(el('carddav-probe-result').textContent).toContain('laisse passer'));
        expect(el('carddav-probe-result').className).toContain('text-success');
    });

    // The failure this whole button exists for: the web server answers
    // before PHP does, and the message has to name the method so the
    // reader can ask their host about that method by name.
    it('names the method the host blocks', async () => {
        global.fetch = vi.fn((_url, options) => answer(options.method === 'PROPFIND' ? 405 : 200));
        await boot();
        el('carddav-probe-btn').click();

        await vi.waitFor(() => expect(el('carddav-probe-result').textContent).toContain('PROPFIND'));
        expect(el('carddav-probe-result').textContent).not.toContain('REPORT arrive');
        expect(el('carddav-probe-result').className).toContain('text-danger');
    });

    it('names both when both are blocked', async () => {
        global.fetch = vi.fn(() => answer(403));
        await boot();
        el('carddav-probe-btn').click();

        await vi.waitFor(() => expect(el('carddav-probe-result').className).toContain('text-danger'));
        expect(el('carddav-probe-result').textContent).toContain('PROPFIND et REPORT');
    });

    // A request that never arrives is the same verdict as one that was
    // refused: in both cases the method does not reach the application,
    // which is the only thing this button claims to measure.
    it('treats a network failure as a blocked method rather than crashing', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('network')));
        await boot();
        el('carddav-probe-btn').click();

        await vi.waitFor(() => expect(el('carddav-probe-result').className).toContain('text-danger'));
    });

    it('re-enables the button so a reader can try again after fixing their host', async () => {
        await boot();
        const button = el('carddav-probe-btn');
        button.click();

        expect(button.disabled).toBe(true);
        await vi.waitFor(() => expect(button.disabled).toBe(false));
    });

    it('does not tell the reader the site is misconfigured when the host is', async () => {
        global.fetch = vi.fn(() => answer(405));
        await boot();
        el('carddav-probe-btn').click();

        await vi.waitFor(() => expect(el('carddav-probe-result').className).toContain('text-danger'));
        expect(el('carddav-probe-result').textContent).toContain('le site lui-même est correctement configuré');
    });
});
