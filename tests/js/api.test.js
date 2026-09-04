// Isolated JavaScript unit test — jsdom DOM only, fetch mocked. Exercises
// the REAL public/assets/js/api.js (imported below, never reimplemented):
// the file is an IIFE that installs window.ScoutMagicApi at import time,
// so each test resets modules and re-imports.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

async function loadApi() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    return window.ScoutMagicApi;
}

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
    document.body.innerHTML = '';
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('csrfToken()', () => {
    it('reads the meta tag, and answers empty without one', async () => {
        const api = await loadApi();
        expect(api.csrfToken()).toBe('tok-123');
        document.head.innerHTML = '';
        expect(api.csrfToken()).toBe('');
    });
});

describe('postJson()', () => {
    it('sends the token in body and header, and resolves the envelope', async () => {
        const api = await loadApi();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) }));

        const result = await api.postJson('/x', { a: 1 });

        expect(result).toEqual({ ok: true, status: 200, data: { success: true } });
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/x');
        expect(init.headers['X-CSRF-Token']).toBe('tok-123');
        expect(JSON.parse(init.body)).toEqual({ a: 1, _csrf_token: 'tok-123' });
    });

    it('reports an HTTP error as ok:false while still delivering the JSON body', async () => {
        const api = await loadApi();
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 403, json: () => Promise.resolve({ error: 'Refusé.' }) }));

        const result = await api.postJson('/x', {});

        expect(result.ok).toBe(false);
        expect(result.status).toBe(403);
        expect(result.data).toEqual({ error: 'Refusé.' });
    });

    it('turns a non-JSON error page into ok:false rather than an exception', async () => {
        const api = await loadApi();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 500, json: () => Promise.reject(new Error('bad json')) }));

        await expect(api.postJson('/x', {})).resolves.toEqual({ ok: false, status: 500, data: null });
    });

    it('never rejects on a network failure', async () => {
        const api = await loadApi();
        global.fetch = vi.fn(() => Promise.reject(new TypeError('offline')));

        await expect(api.postJson('/x', {})).resolves.toEqual({ ok: false, status: 0, data: null });
    });
});

describe('withDisabled()', () => {
    it('re-enables the control on success AND on failure', async () => {
        const api = await loadApi();
        const button = document.createElement('button');

        await api.withDisabled(button, () => Promise.resolve('ok'));
        expect(button.disabled).toBe(false);

        await expect(api.withDisabled(button, () => Promise.reject(new Error('no')))).rejects.toThrow('no');
        expect(button.disabled).toBe(false);
    });

    it('disables the control while the promise is pending', async () => {
        const api = await loadApi();
        const button = document.createElement('button');
        let release;
        const pending = api.withDisabled(button, () => new Promise((resolve) => { release = resolve; }));
        await Promise.resolve();
        expect(button.disabled).toBe(true);
        release('done');
        await pending;
        expect(button.disabled).toBe(false);
    });
});

describe('escapeHtml()', () => {
    it('escapes markup and both quote styles (attribute-safe)', async () => {
        const api = await loadApi();
        expect(api.escapeHtml('<b>&</b>')).toBe('&lt;b&gt;&amp;&lt;/b&gt;');
        expect(api.escapeHtml('a"b\'c')).toBe('a&quot;b&#39;c');
        expect(api.escapeHtml(null)).toBe('');
    });
});

describe('debounce()', () => {
    it('fires once on the trailing edge with the last arguments', async () => {
        const api = await loadApi();
        vi.useFakeTimers();
        const spy = vi.fn();
        const debounced = api.debounce(spy, 300);
        debounced(1);
        debounced(2);
        debounced(3);
        vi.advanceTimersByTime(299);
        expect(spy).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(spy).toHaveBeenCalledExactlyOnceWith(3);
    });
});

describe('poll()', () => {
    it('never overlaps ticks and stops when the tick answers false', async () => {
        const api = await loadApi();
        vi.useFakeTimers();
        let calls = 0;
        api.poll(() => {
            calls++;
            return calls >= 2 ? false : undefined;
        }, { intervalMs: 1000, resumeOnVisible: false });

        await vi.advanceTimersByTimeAsync(1000);
        expect(calls).toBe(1);
        await vi.advanceTimersByTimeAsync(1000);
        expect(calls).toBe(2);
        // Stopped: no further ticks however long we wait.
        await vi.advanceTimersByTimeAsync(10000);
        expect(calls).toBe(2);
    });

    it('keeps polling through a failing tick', async () => {
        const api = await loadApi();
        vi.useFakeTimers();
        let calls = 0;
        api.poll(() => {
            calls++;
            return Promise.reject(new Error('transient'));
        }, { intervalMs: 1000, resumeOnVisible: false });

        await vi.advanceTimersByTimeAsync(3000);
        expect(calls).toBe(3);
    });

    it('walks a backoff delay ladder and stays on its last rung', async () => {
        const api = await loadApi();
        vi.useFakeTimers();
        let calls = 0;
        api.poll(() => { calls++; }, { delaysMs: [100, 500], resumeOnVisible: false });

        await vi.advanceTimersByTimeAsync(100);
        expect(calls).toBe(1);
        await vi.advanceTimersByTimeAsync(500);
        expect(calls).toBe(2);
        await vi.advanceTimersByTimeAsync(500);
        expect(calls).toBe(3);
    });

    it('fires onExpire once at the deadline and stops', async () => {
        const api = await loadApi();
        vi.useFakeTimers();
        const onExpire = vi.fn();
        let calls = 0;
        api.poll(() => { calls++; }, { intervalMs: 1000, maxMs: 2500, resumeOnVisible: false, onExpire });

        await vi.advanceTimersByTimeAsync(10000);
        expect(calls).toBe(2);
        expect(onExpire).toHaveBeenCalledOnce();
    });

    it('stop() prevents any further tick', async () => {
        const api = await loadApi();
        vi.useFakeTimers();
        let calls = 0;
        const handle = api.poll(() => { calls++; }, { intervalMs: 1000, resumeOnVisible: false });
        handle.stop();
        await vi.advanceTimersByTimeAsync(5000);
        expect(calls).toBe(0);
    });
});

describe('pollSlot()', () => {
    it('stops the poll it holds, and a second stop is harmless', async () => {
        const api = await loadApi();
        vi.useFakeTimers();
        let calls = 0;
        const slot = api.pollSlot();

        expect(slot.isRunning()).toBe(false);
        slot.start(api.poll(() => { calls++; }, { intervalMs: 1000, resumeOnVisible: false }));
        expect(slot.isRunning()).toBe(true);

        await vi.advanceTimersByTimeAsync(2500);
        expect(calls).toBe(2);

        slot.stop();
        slot.stop();
        expect(slot.isRunning()).toBe(false);
        await vi.advanceTimersByTimeAsync(5000);
        expect(calls).toBe(2);
    });

    it('stops the previous poll when a new one takes the slot', async () => {
        // What two hand-written copies of this got wrong in turn: starting a
        // second poll without stopping the first left both running.
        const api = await loadApi();
        vi.useFakeTimers();
        let first = 0;
        let second = 0;
        const slot = api.pollSlot();

        slot.start(api.poll(() => { first++; }, { intervalMs: 1000, resumeOnVisible: false }));
        await vi.advanceTimersByTimeAsync(1500);
        slot.stop();
        slot.start(api.poll(() => { second++; }, { intervalMs: 1000, resumeOnVisible: false }));
        await vi.advanceTimersByTimeAsync(2500);

        expect(first).toBe(1);
        expect(second).toBe(2);
    });

    it('an empty slot stops without a handle', async () => {
        const api = await loadApi();

        expect(() => api.pollSlot().stop()).not.toThrow();
    });
});
