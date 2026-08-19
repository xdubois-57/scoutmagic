// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/groups.js (imported below, never
// reimplemented here).
//
// Deliberately its OWN file, separate from groups.test.js: this block
// imports the module exactly ONCE (see the beforeAll below) so the
// document-level click/change delegation groups.js registers is attached
// only once, matching how it actually runs in a real page load. Vitest
// gives each TEST FILE its own fresh jsdom document but shares one within
// a file across all its describe/it blocks — groups.test.js's other
// blocks re-import per test (to reset the media picker's closured
// selectedFiles[] state) which would otherwise stack a fresh
// document-level listener on top of every earlier one in the same file, so
// a single click fires N handlers and the Nth tries to remove a wrapper an
// earlier one already detached.
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

describe('groups.js dynamic reactions, in-place pagination and inline edit toggles', () => {
    // Imported ONCE for this whole block, deliberately — not per test.
    // groups.js delegates click/change on `document` itself so it can
    // catch content a fetch() inserts after the page loaded; that is
    // correct in a real browser, where the script runs exactly once. Under
    // Vitest, re-importing per test (vi.resetModules() + import, the
    // pattern the other two describe blocks use for their own reasons)
    // would re-run the IIFE and stack a fresh document-level listener on
    // top of every earlier test's, so a single click would fire N handlers
    // — the Nth one trying to remove a wrapper an earlier handler had
    // already detached. This block's handlers hold no closured state
    // (unlike the media picker's selectedFiles[]), so importing once and
    // just swapping document.body.innerHTML per test is both safe and
    // matches how the script actually runs in production.
    beforeAll(async () => {
        await import('../../public/assets/js/groups.js');
    });

    beforeEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('"Charger plus" fetches the next page and replaces its wrapper with the response', async () => {
        document.body.innerHTML = `
            <div id="groups-feed">
                <div class="groups-load-more-wrapper">
                    <button class="groups-load-more" data-url="/groups/1/feed?cursor=abc">Charger plus</button>
                </div>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('<p id="new-post">Nouveau</p>') }));

        document.querySelector('.groups-load-more').click();
        await vi.waitFor(() => expect(document.getElementById('new-post')).not.toBeNull());

        expect(fetch).toHaveBeenCalledWith('/groups/1/feed?cursor=abc', { headers: { 'X-Requested-With': 'fetch' } });
        expect(document.querySelector('.groups-load-more-wrapper')).toBeNull();
    });

    it('"Charger plus" re-enables the button on a failed fetch rather than leaving it stuck', async () => {
        document.body.innerHTML = `
            <div class="groups-load-more-wrapper">
                <button class="groups-load-more" data-url="/groups/1/feed?cursor=abc">Charger plus</button>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: false }));

        document.querySelector('.groups-load-more').click();
        await vi.waitFor(() => expect(document.querySelector('.groups-load-more').disabled).toBe(false));

        expect(document.querySelector('.groups-load-more-wrapper')).not.toBeNull();
    });

    it('a reaction click fetches and swaps the reactions container with the response fragment, sending the form fields and the XHR header', async () => {
        document.body.innerHTML = `
            <div class="groups-reactions" id="post-9-reactions">
                <form class="groups-reaction-form" action="/groups/1/posts/9/react" method="post">
                    <input type="hidden" name="_csrf_token" value="tok">
                    <input type="hidden" name="reaction" value="heart">
                    <button type="submit">reagir</button>
                </form>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ outcome: 'added', html: '<div class="groups-reactions" id="post-9-reactions-updated"></div>' })
        }));

        document.querySelector('.groups-reaction-form button').click();
        await vi.waitFor(() => expect(document.getElementById('post-9-reactions-updated')).not.toBeNull());

        expect(document.getElementById('post-9-reactions')).toBeNull();
        const [url, options] = fetch.mock.calls[0];
        expect(url).toContain('/groups/1/posts/9/react');
        expect(options.method).toBe('POST');
        expect(options.headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
        expect(options.body.get('reaction')).toBe('heart');
        expect(options.body.get('_csrf_token')).toBe('tok');
    });

    it('falls back to a plain form submit when the reaction fetch fails, so a stale session still does something', async () => {
        document.body.innerHTML = `
            <div class="groups-reactions">
                <form class="groups-reaction-form" action="/groups/1/posts/9/react" method="post">
                    <input type="hidden" name="reaction" value="heart">
                    <button type="submit">reagir</button>
                </form>
            </div>
        `;
        const form = document.querySelector('.groups-reaction-form');
        form.submit = vi.fn();
        global.fetch = vi.fn(() => Promise.resolve({ ok: false }));

        document.querySelector('.groups-reaction-form button').click();
        await vi.waitFor(() => expect(form.submit).toHaveBeenCalled());
    });

    it('falls back to a plain form submit when the reaction response is not valid JSON', async () => {
        document.body.innerHTML = `
            <div class="groups-reactions">
                <form class="groups-reaction-form" action="/groups/1/posts/9/react" method="post">
                    <input type="hidden" name="reaction" value="heart">
                    <button type="submit">reagir</button>
                </form>
            </div>
        `;
        const form = document.querySelector('.groups-reaction-form');
        form.submit = vi.fn();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.reject(new Error('bad json')) }));

        document.querySelector('.groups-reaction-form button').click();
        await vi.waitFor(() => expect(form.submit).toHaveBeenCalled());
    });

    it('polls for a still-pending photo after "Charger plus" inserts it, and swaps it in place once processing finishes', async () => {
        vi.useFakeTimers();
        try {
            document.body.innerHTML = `
                <div id="groups-feed" data-group-id="7">
                    <div class="groups-load-more-wrapper">
                        <button class="groups-load-more" data-url="/groups/7/feed?cursor=abc">Charger plus</button>
                    </div>
                </div>
            `;
            global.fetch = vi.fn()
                .mockResolvedValueOnce({
                    ok: true,
                    text: () => Promise.resolve(
                        '<a class="groups-media-cell" data-media-id="42" data-status="pending">' +
                        '<span class="spinner-border spinner-border-sm"></span></a>'
                    )
                })
                .mockResolvedValueOnce({
                    ok: true,
                    json: () => Promise.resolve([{ id: 42, status: 'done', html: '<img alt="" src="/gallery/media/42/thumb">' }])
                });

            document.querySelector('.groups-load-more').click();
            await vi.advanceTimersByTimeAsync(0);
            expect(document.querySelector('[data-media-id="42"]')).not.toBeNull();
            expect(document.querySelector('[data-media-id="42"]').dataset.status).toBe('pending');

            // The first backoff tick: groups.js checks in on whatever is
            // still pending or processing.
            await vi.advanceTimersByTimeAsync(2000);

            expect(fetch).toHaveBeenNthCalledWith(
                2,
                '/groups/7/media-status?ids=42',
                { headers: { 'X-Requested-With': 'fetch' } }
            );
            var cell = document.querySelector('[data-media-id="42"]');
            expect(cell.dataset.status).toBe('done');
            expect(cell.innerHTML).toContain('/gallery/media/42/thumb');
        } finally {
            vi.useRealTimers();
        }
    });

    it('keeps polling on the next backoff step when a photo is still processing, and stops once nothing is pending', async () => {
        vi.useFakeTimers();
        try {
            document.body.innerHTML = `
                <div id="groups-feed" data-group-id="7">
                    <div class="groups-load-more-wrapper">
                        <button class="groups-load-more" data-url="/groups/7/feed?cursor=abc">Charger plus</button>
                    </div>
                </div>
            `;
            global.fetch = vi.fn()
                .mockResolvedValueOnce({
                    ok: true,
                    text: () => Promise.resolve('<a class="groups-media-cell" data-media-id="9" data-status="processing"></a>')
                })
                .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve([{ id: 9, status: 'processing', html: null }]) })
                .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve([{ id: 9, status: 'done', html: '<img alt="" src="/gallery/media/9/thumb">' }]) });

            document.querySelector('.groups-load-more').click();
            await vi.advanceTimersByTimeAsync(0);

            await vi.advanceTimersByTimeAsync(2000);
            expect(fetch).toHaveBeenCalledTimes(2);
            expect(document.querySelector('[data-media-id="9"]').dataset.status).toBe('processing');

            // Still pending, so it tries again on the NEXT backoff step
            // rather than giving up after one miss.
            await vi.advanceTimersByTimeAsync(2000);
            expect(fetch).toHaveBeenCalledTimes(3);
            expect(document.querySelector('[data-media-id="9"]').dataset.status).toBe('done');

            // Nothing left to poll for — one more backoff step must not
            // fire a fourth request.
            await vi.advanceTimersByTimeAsync(20000);
            expect(fetch).toHaveBeenCalledTimes(3);
        } finally {
            vi.useRealTimers();
        }
    });

    it('toggles a post into edit mode and back', async () => {
        document.body.innerHTML = `
            <p id="post-body-42">Texte original</p>
            <form id="post-edit-42" class="d-none"></form>
            <button class="groups-edit-toggle" data-post="42"></button>
            <button class="groups-edit-cancel" data-post="42"></button>
        `;

        document.querySelector('.groups-edit-toggle').click();
        expect(document.getElementById('post-edit-42').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('post-body-42').classList.contains('d-none')).toBe(true);

        document.querySelector('.groups-edit-cancel').click();
        expect(document.getElementById('post-edit-42').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('post-body-42').classList.contains('d-none')).toBe(false);
    });

    it('toggles a reply into edit mode and back', async () => {
        document.body.innerHTML = `
            <p id="reply-body-7">Texte original</p>
            <form id="reply-edit-7" class="d-none"></form>
            <button class="groups-reply-edit-toggle" data-reply="7"></button>
            <button class="groups-reply-edit-cancel" data-reply="7"></button>
        `;

        document.querySelector('.groups-reply-edit-toggle').click();
        expect(document.getElementById('reply-edit-7').classList.contains('d-none')).toBe(false);

        document.querySelector('.groups-reply-edit-cancel').click();
        expect(document.getElementById('reply-edit-7').classList.contains('d-none')).toBe(true);
    });

    it('"Voir plus de réponses" fetches and inserts the next reply page', async () => {
        document.body.innerHTML = `
            <div class="groups-replies-more-wrapper">
                <button class="groups-replies-more" data-url="/groups/1/posts/9/replies?after=3">Voir plus</button>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('<div id="new-reply"></div>') }));

        document.querySelector('.groups-replies-more').click();
        await vi.waitFor(() => expect(document.getElementById('new-reply')).not.toBeNull());

        expect(fetch).toHaveBeenCalledWith('/groups/1/posts/9/replies?after=3', { headers: { 'X-Requested-With': 'fetch' } });
    });

    it('shows the picked filename next to a reply image input, and hides it again when cleared', async () => {
        document.body.innerHTML = `
            <form>
                <input type="file" class="groups-reply-image">
                <span class="groups-reply-image-name d-none"></span>
            </form>
        `;
        const input = document.querySelector('.groups-reply-image');
        const label = document.querySelector('.groups-reply-image-name');

        // This listener is delegated on `document` (groups.js has no
        // per-element listener for a reply's image input, unlike the
        // composer's own picker) — only a BUBBLING event reaches it, which
        // `new Event('change')` is not by default, unlike a real browser's
        // native change event or the .click()-driven ones used elsewhere in
        // this file.
        Object.defineProperty(input, 'files', { value: [new File(['x'], 'photo.jpg')], configurable: true });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(label.textContent).toBe('photo.jpg');
        expect(label.classList.contains('d-none')).toBe(false);

        Object.defineProperty(input, 'files', { value: [], configurable: true });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(label.textContent).toBe('');
        expect(label.classList.contains('d-none')).toBe(true);
    });
});
