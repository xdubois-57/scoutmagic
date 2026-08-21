// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/support-package.js (imported below,
// never reimplemented here).
//
// The file is a small polling state machine, which is exactly the kind of
// logic worth isolating (AGENTS.md § Tests): every branch ends in one of
// three terminal states — download offered, error shown, or give up — and
// the bug worth pinning is a branch that ends in none of them and spins
// forever with the button disabled.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function el(html) {
    const div = document.createElement('div');
    div.innerHTML = html.trim();
    return div.firstElementChild;
}

function buildDom() {
    document.body.innerHTML = '';
    [
        el('<button type="button" id="support-package-generate">Générer</button>'),
        el('<span class="d-none" id="support-package-progress"><span class="small">Génération…</span></span>'),
        el('<a class="btn d-none" id="support-package-download" href="#">Télécharger</a>'),
        el('<p class="d-none" id="support-package-generated-at"></p>'),
        el('<div class="alert d-none" id="support-package-error" role="alert"></div>'),
    ].forEach((node) => document.body.appendChild(node));

    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'a-csrf-token';
    document.head.innerHTML = '';
    document.head.appendChild(meta);
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/support-package.js');
}

function ids() {
    return {
        button: document.getElementById('support-package-generate'),
        progress: document.getElementById('support-package-progress'),
        download: document.getElementById('support-package-download'),
        generatedAt: document.getElementById('support-package-generated-at'),
        error: document.getElementById('support-package-error'),
    };
}

/** A fetch double: first call is the POST, every later call is a status poll. */
function mockFetch(scheduleResponse, statusResponses) {
    let poll = 0;
    return vi.fn((url) => {
        if (String(url).startsWith('/config/support/package')) {
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(scheduleResponse) });
        }
        const next = statusResponses[Math.min(poll, statusResponses.length - 1)];
        poll++;
        if (next instanceof Error) {
            return Promise.reject(next);
        }
        return Promise.resolve({
            ok: (next.httpStatus ?? 200) < 400,
            status: next.httpStatus ?? 200,
            json: () => Promise.resolve(next),
        });
    });
}

/** Advance fake timers and let the mocked promises settle in between. */
async function tick(times) {
    for (let i = 0; i < times; i++) {
        await vi.advanceTimersByTimeAsync(3000);
    }
}

beforeEach(() => {
    vi.restoreAllMocks();
    vi.useFakeTimers();
    buildDom();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('support-package.js: starting a generation', () => {
    it('does nothing at all when the button is absent from the page', async () => {
        document.body.innerHTML = '';
        global.fetch = vi.fn();

        await boot();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('POSTs the CSRF token from the meta tag and shows the spinner', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [{ status: 'pending' }]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);

        expect(fetch).toHaveBeenCalledWith('/config/support/package', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ _csrf_token: 'a-csrf-token' }),
        }));
        expect(ids().button.disabled).toBe(true);
        expect(ids().progress.classList.contains('d-none')).toBe(false);
    });

    it('surfaces the server error and re-enables the button when scheduling is refused', async () => {
        global.fetch = mockFetch({ success: false, error: 'Jeton de sécurité invalide.' }, []);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);

        expect(ids().error.textContent).toBe('Jeton de sécurité invalide.');
        expect(ids().error.classList.contains('d-none')).toBe(false);
        expect(ids().button.disabled).toBe(false);
    });

    it('reports a network failure on the scheduling call', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);

        expect(ids().error.textContent).toBe('Erreur réseau.');
        expect(ids().button.disabled).toBe(false);
    });
});

describe('support-package.js: polling to completion', () => {
    it('keeps polling while the task is pending or processing', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [
            { status: 'pending' },
            { status: 'processing' },
            { status: 'processing' },
        ]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);
        await tick(3);

        expect(ids().button.disabled).toBe(true);
        expect(ids().error.classList.contains('d-none')).toBe(true);
    });

    it('reveals the download link once the archive is done', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [
            { status: 'processing' },
            { status: 'done', download_url: '/files/42' },
        ]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);
        await tick(2);

        expect(ids().download.getAttribute('href')).toBe('/files/42');
        expect(ids().download.classList.contains('d-none')).toBe(false);
        expect(ids().generatedAt.classList.contains('d-none')).toBe(false);
        expect(ids().button.disabled).toBe(false);
    });

    it('stops polling once the download is offered', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [{ status: 'done', download_url: '/files/42' }]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);
        await tick(1);
        const callsAtCompletion = fetch.mock.calls.length;
        await tick(5);

        expect(fetch.mock.calls.length).toBe(callsAtCompletion);
    });

    it('reports a done-but-missing archive rather than silently offering nothing', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [{ status: 'done', download_url: null }]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);
        await tick(1);

        expect(ids().error.classList.contains('d-none')).toBe(false);
        expect(ids().download.classList.contains('d-none')).toBe(true);
    });

    it.each(['failed', 'canceled'])('reports a %s generation', async (status) => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [{ status }]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);
        await tick(1);

        expect(ids().error.textContent).toContain('échoué');
        expect(ids().button.disabled).toBe(false);
    });
});

describe('support-package.js: polling always terminates', () => {
    // The three regressions. Each one used to leave the spinner turning and
    // the button disabled with no way back except reloading the page.

    it('gives up when the status endpoint answers 404', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [{ httpStatus: 404, error: 'introuvable' }]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);
        await tick(1);

        expect(ids().error.textContent).toContain('introuvable');
        expect(ids().button.disabled).toBe(false);
        const callsAtGiveUp = fetch.mock.calls.length;
        await tick(3);
        expect(fetch.mock.calls.length).toBe(callsAtGiveUp);
    });

    it('gives up on a task that never leaves pending, instead of polling forever', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [{ status: 'pending' }]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);

        // Well past the ten-minute deadline.
        await tick(250);

        expect(ids().error.classList.contains('d-none')).toBe(false);
        expect(ids().error.textContent).toContain('Rechargez la page');
        expect(ids().button.disabled).toBe(false);
    });

    it('retries a transient network hiccup but still stops at the deadline', async () => {
        global.fetch = mockFetch({ success: true, action_id: 12 }, [new Error('hiccup')]);
        await boot();

        ids().button.click();
        await vi.advanceTimersByTimeAsync(0);

        await tick(3);
        expect(ids().error.classList.contains('d-none')).toBe(true);
        expect(ids().button.disabled).toBe(true);

        await tick(250);
        expect(ids().button.disabled).toBe(false);
    });
});
