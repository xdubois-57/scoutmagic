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
        // base.html.twig ships ONE delegated confirmation handler for the
        // whole site — every form carrying data-confirm goes through it,
        // and groups.js reads its verdict rather than asking again (it
        // used to ask again, and prompted twice).
        //
        // Reproduced here byte-for-byte in behaviour, because groups.js's
        // own listener is written against it: the dialog is asynchronous,
        // so this handler stops EVERY data-confirm submit and replays it
        // (data-confirmed="1", requestSubmit so the submitter and the
        // native validation survive) once the visitor has confirmed. The
        // replayed submit is the only one groups.js ever acts on.
        //
        // Registered BEFORE the import, because that is the order
        // production guarantees: base.html.twig's is an inline classic
        // script and groups.js is loaded with `defer`, which the HTML
        // standard runs after every inline script in the document. A test
        // that registered these the other way round would be exercising an
        // ordering no browser produces.
        document.addEventListener('submit', function (event) {
            var form = /** @type {HTMLFormElement} */ (
                /** @type {HTMLElement} */ (event.target).closest('form[data-confirm]')
            );
            if (!form || form.dataset.confirmed === '1') {
                return;
            }
            event.preventDefault();
            var submitter = /** @type {any} */ (event).submitter;
            window.ScoutMagicConfirm.ask({
                message: form.dataset.confirm,
                confirmLabel: form.dataset.confirmLabel || 'Confirmer'
            }).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }
                form.dataset.confirmed = '1';
                if (form.requestSubmit) {
                    form.requestSubmit(submitter);
                } else {
                    form.submit();
                }
            });
        });

        // The real toast toolbox — groups.js reports a server refusal
        // through window.ScoutMagicToast, and base.html.twig loads it
        // before every page script.
        await import('../../public/assets/js/toast.js');
        await import('../../public/assets/js/groups.js');
    });

    beforeEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        // The site's confirmation dialog, stubbed: what these tests own is
        // that data-confirm forms are gated by it and act only on its
        // "yes" — the dialog's own rendering is tests/js/confirm.test.js.
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    });

    /** The text of the last toast currently shown, or null when there is none. */
    function lastToastText() {
        const bodies = document.querySelectorAll('.toast-body');
        return bodies.length ? bodies[bodies.length - 1].textContent : null;
    }

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
                    json: () => Promise.resolve([{ id: 42, status: 'done', media_type: 'photo' }])
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
            // Built from the id, not from any markup the server sent:
            // the media-status endpoint returns data only.
            var thumb = cell.querySelector('img');
            expect(thumb).not.toBeNull();
            expect(thumb.getAttribute('src')).toBe('/gallery/media/42/thumb');
        } finally {
            vi.useRealTimers();
        }
    });

    /**
     * The media-status poll used to drop server-rendered markup into
     * `innerHTML`. Nothing user-supplied ever travelled through it — the
     * partial holds an integer id and an enum — but the sink was real
     * whatever flowed through it, and it would have become exploitable
     * the first time somebody put a caption in that template. SonarQube
     * rated it a blocker, and main's quality gate had been red on it.
     *
     * The endpoint returns data now. This asserts the property that
     * matters: markup arriving in the response is never interpreted.
     */
    it('never interprets markup sent by the media-status endpoint', async () => {
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
                    text: () => Promise.resolve('<a class="groups-media-cell" data-media-id="42" data-status="pending"></a>')
                })
                .mockResolvedValue({
                    ok: true,
                    json: () => Promise.resolve([{
                        id: 42,
                        status: 'done',
                        media_type: 'photo',
                        // A server that started sending markup again, or
                        // one that had been compromised, must change
                        // nothing here.
                        html: '<img src=x onerror="window.__xss = true">'
                    }])
                });

            document.querySelector('.groups-load-more').click();
            await vi.advanceTimersByTimeAsync(0);
            await vi.advanceTimersByTimeAsync(2000);

            var cell = document.querySelector('[data-media-id="42"]');
            expect(cell.querySelector('img').getAttribute('src')).toBe('/gallery/media/42/thumb');
            expect(cell.innerHTML).not.toContain('onerror');
            expect(window.__xss).toBeUndefined();
        } finally {
            vi.useRealTimers();
        }
    });

    it('shows the failure notice rather than a broken thumbnail when processing failed', async () => {
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
                .mockResolvedValue({
                    ok: true,
                    json: () => Promise.resolve([{ id: 9, status: 'failed' }])
                });

            document.querySelector('.groups-load-more').click();
            await vi.advanceTimersByTimeAsync(0);
            await vi.advanceTimersByTimeAsync(2000);

            var cell = document.querySelector('[data-media-id="9"]');
            expect(cell.dataset.status).toBe('failed');
            expect(cell.querySelector('img')).toBeNull();
            expect(cell.querySelector('.bi-exclamation-triangle')).not.toBeNull();
        } finally {
            vi.useRealTimers();
        }
    });

    it('marks a finished video with its play badge', async () => {
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
                    text: () => Promise.resolve('<a class="groups-media-cell" data-media-id="11" data-status="processing"></a>')
                })
                .mockResolvedValue({
                    ok: true,
                    json: () => Promise.resolve([{ id: 11, status: 'done', media_type: 'video' }])
                });

            document.querySelector('.groups-load-more').click();
            await vi.advanceTimersByTimeAsync(0);
            await vi.advanceTimersByTimeAsync(2000);

            var cell = document.querySelector('[data-media-id="11"]');
            expect(cell.querySelector('img')).not.toBeNull();
            expect(cell.querySelector('.bi-play-circle-fill')).not.toBeNull();
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

    it('a reaction tally click fetches and shows who reacted in the shared modal', async () => {
        document.body.innerHTML = `
            <button type="button" class="groups-reaction-tally" data-reactors-url="/groups/1/posts/9/reactions"></button>
            <div class="modal" id="groups-detail-modal"></div>
            <div id="groups-detail-modal-body"></div>
        `;
        var modal = { show: vi.fn() };
        global.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => modal) } };
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<p>Akéla, Baloo</p>' })
        }));

        document.querySelector('.groups-reaction-tally').click();
        await vi.waitFor(() => expect(document.getElementById('groups-detail-modal-body').innerHTML).toContain('Akéla, Baloo'));

        expect(fetch).toHaveBeenCalledWith(
            '/groups/1/posts/9/reactions',
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );
        expect(bootstrap.Modal.getOrCreateInstance).toHaveBeenCalledWith(document.getElementById('groups-detail-modal'));
        expect(modal.show).toHaveBeenCalled();
    });

    it('shows an error in the modal when the reactors request fails', async () => {
        document.body.innerHTML = `
            <button type="button" class="groups-reaction-tally" data-reactors-url="/groups/1/posts/9/reactions"></button>
            <div class="modal" id="groups-detail-modal"></div>
            <div id="groups-detail-modal-body"></div>
        `;
        global.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => ({ show: vi.fn() })) } };
        global.fetch = vi.fn(() => Promise.resolve({ ok: false }));

        document.querySelector('.groups-reaction-tally').click();
        await vi.waitFor(() => expect(document.getElementById('groups-detail-modal-body').textContent).toContain('Impossible de charger'));
    });

    it('shows the error rather than hanging on the spinner when the answer is not JSON', async () => {
        document.body.innerHTML = `
            <button type="button" class="groups-reaction-tally" data-reactors-url="/groups/1/posts/9/reactions"></button>
            <div class="modal" id="groups-detail-modal"></div>
            <div id="groups-detail-modal-body"></div>
        `;
        global.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => ({ show: vi.fn() })) } };
        // A 200 whose body is a page rather than the expected {html} — what
        // the tally used to get when its URL was empty, and what a captive
        // portal or a session-expiry redirect returns for real. The parse
        // rejected, nothing caught it, and the dialog span forever.
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.reject(new SyntaxError('Unexpected token <'))
        }));

        document.querySelector('.groups-reaction-tally').click();
        await vi.waitFor(() => expect(document.getElementById('groups-detail-modal-body').textContent).toContain('Impossible de charger'));
    });

    it('never opens the dialog at all for a trigger carrying no URL', async () => {
        document.body.innerHTML = `
            <button type="button" class="groups-reaction-tally" data-reactors-url=""></button>
            <div class="modal" id="groups-detail-modal"></div>
            <div id="groups-detail-modal-body">déjà là</div>
        `;
        var modal = { show: vi.fn() };
        global.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => modal) } };
        global.fetch = vi.fn();

        document.querySelector('.groups-reaction-tally').click();
        await Promise.resolve();

        // fetch('') is a request for the CURRENT page, which answers HTML —
        // so this must not fire at all.
        expect(fetch).not.toHaveBeenCalled();
        expect(modal.show).not.toHaveBeenCalled();
        expect(document.getElementById('groups-detail-modal-body').textContent).toBe('déjà là');
    });

    it('a "vu par" click fills the same shared dialog and retitles it', async () => {
        document.body.innerHTML = `
            <button type="button" class="groups-seen-by" data-url="/groups/1/posts/9/seen-by" data-dialog-title="Vu par"></button>
            <div class="modal" id="groups-detail-modal"></div>
            <h2 id="groups-detail-modal-title">Réactions</h2>
            <div id="groups-detail-modal-body"></div>
        `;
        global.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => ({ show: vi.fn() })) } };
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<li>Akéla</li>' })
        }));

        document.querySelector('.groups-seen-by').click();
        await vi.waitFor(() => expect(document.getElementById('groups-detail-modal-body').innerHTML).toContain('Akéla'));

        // The dialog is shared with the reaction tallies, so the title has
        // to follow the trigger or a "vu par" list would open under
        // "Réactions".
        expect(document.getElementById('groups-detail-modal-title').textContent).toBe('Vu par');
        expect(fetch).toHaveBeenCalledWith(
            '/groups/1/posts/9/seen-by',
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        );
    });

    // The member-scoped poll's "Vous répondez pour" dialog. The <select>
    // it reads from is the server's no-JavaScript fallback
    // (@groups/partials/poll.html.twig): the script hides it and asks the
    // same question in the shared dialog, so what the two offer can never
    // drift apart — headings included.
    function pollWithVoterPicker(pickerMarkup) {
        return `
            <div class="groups-poll">
                <div class="groups-poll-voter">
                    <select id="poll-voter-9">${pickerMarkup}</select>
                </div>
                <form class="groups-poll-form" action="/groups/1/posts/9/vote" method="post">
                    <input type="hidden" name="option_id" value="4">
                    <input type="hidden" name="voter_member_id" value="3" class="groups-poll-voter-input">
                    <button type="submit">Samedi</button>
                </form>
            </div>
            <div class="modal" id="groups-detail-modal"></div>
            <h2 id="groups-detail-modal-title">Réactions</h2>
            <div id="groups-detail-modal-body"></div>
        `;
    }

    it('asks whose answer it is, carrying the two headings the server grouped the members under', async () => {
        document.body.innerHTML = pollWithVoterPicker(`
            <optgroup label="Dans ce groupe">
                <option value="3">Marie Dupont (Akéla)</option>
                <option value="4">Marie Dupont (Baloo)</option>
            </optgroup>
            <optgroup label="Hors de ce groupe">
                <option value="5">Marie Dupont (Chil)</option>
            </optgroup>
        `);
        global.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => ({ show: vi.fn(), hide: vi.fn() })) } };
        global.fetch = vi.fn();

        document.querySelector('.groups-poll-form button').click();
        await vi.waitFor(() => expect(document.querySelectorAll('[data-member-id]').length).toBe(3));

        // Every row of the dialog, in order: a heading is a plain div, a
        // member is a button carrying the id the vote will be recorded
        // against.
        const rows = [...document.querySelectorAll('#groups-detail-modal-body .list-group > *')].map((row) => [
            row.tagName,
            row.dataset.memberId ?? null,
            row.textContent,
        ]);
        expect(rows).toEqual([
            ['DIV', null, 'Dans ce groupe'],
            ['BUTTON', '3', 'Marie Dupont (Akéla)'],
            ['BUTTON', '4', 'Marie Dupont (Baloo)'],
            ['DIV', null, 'Hors de ce groupe'],
            ['BUTTON', '5', 'Marie Dupont (Chil)'],
        ]);
        // Nothing is sent until somebody has answered the question.
        expect(fetch).not.toHaveBeenCalled();
    });

    it('draws no heading at all when the server grouped nobody', async () => {
        document.body.innerHTML = pollWithVoterPicker(`
            <option value="3">Marie Dupont (Akéla)</option>
            <option value="4">Marie Dupont (Baloo)</option>
        `);
        global.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => ({ show: vi.fn(), hide: vi.fn() })) } };
        global.fetch = vi.fn();

        document.querySelector('.groups-poll-form button').click();
        await vi.waitFor(() => expect(document.querySelectorAll('[data-member-id]').length).toBe(2));

        const rows = [...document.querySelectorAll('#groups-detail-modal-body .list-group > *')];
        expect(rows.every((row) => row.tagName === 'BUTTON')).toBe(true);
    });

    it('records the member picked in the dialog and only then sends the vote', async () => {
        document.body.innerHTML = pollWithVoterPicker(`
            <optgroup label="Dans ce groupe">
                <option value="3">Marie Dupont (Akéla)</option>
            </optgroup>
            <optgroup label="Hors de ce groupe">
                <option value="5">Marie Dupont (Chil)</option>
            </optgroup>
        `);
        const modalEl = () => document.getElementById('groups-detail-modal');
        global.bootstrap = {
            Modal: {
                getOrCreateInstance: vi.fn(() => ({
                    show: vi.fn(),
                    // The real dialog resolves on Bootstrap's own hidden
                    // event; the stub fires it the same way.
                    hide: vi.fn(() => modalEl().dispatchEvent(new Event('hidden.bs.modal'))),
                })),
            },
        };
        // Read at the moment of the send: the response replaces the whole
        // poll block, picker included, so afterwards there is nothing left
        // to inspect.
        let pickerAtSend = null;
        global.fetch = vi.fn(() => {
            pickerAtSend = document.getElementById('poll-voter-9').value;

            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ html: '<div class="groups-poll">1 membre a répondu</div>' }),
            });
        });

        document.querySelector('.groups-poll-form button').click();
        await vi.waitFor(() => expect(document.querySelectorAll('[data-member-id]').length).toBe(2));
        expect(fetch).not.toHaveBeenCalled();

        document.querySelector('[data-member-id="5"]').click();

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        const body = fetch.mock.calls[0][1].body;
        expect(body.get('voter_member_id')).toBe('5');
        // Written back into the fallback <select> too, so a submit that
        // never opens the dialog sends the same person.
        expect(pickerAtSend).toBe('5');
    });

    it('a poll vote swaps the poll block in place without reloading', async () => {
        document.body.innerHTML = `
            <div class="groups-poll">
                <form class="groups-poll-form" action="/groups/1/posts/9/vote" method="post">
                    <input type="hidden" name="option_id" value="4">
                    <button type="submit">Samedi</button>
                </form>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<div class="groups-poll">3 votes</div>' })
        }));

        document.querySelector('.groups-poll-form button').click();

        await vi.waitFor(() => expect(document.body.innerHTML).toContain('3 votes'));
        // jsdom resolves form.action to an absolute URL.
        expect(fetch).toHaveBeenCalledWith(
            expect.stringContaining('/groups/1/posts/9/vote'),
            expect.objectContaining({ method: 'POST' })
        );
    });

    // The "@" autocomplete. It only ever types plain text into the field:
    // no id travels with the message, because the server resolves the
    // names back out of the stored body (Service\MentionService).
    describe('the @ autocomplete', () => {
        function composer(value) {
            document.body.innerHTML =
                '<textarea id="post-body" data-mention-url="/groups/1/mention-search"></textarea>';
            var field = /** @type {HTMLTextAreaElement} */ (document.getElementById('post-body'));
            field.value = value;
            field.setSelectionRange(value.length, value.length);

            return field;
        }

        function type(field) {
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        it('queries the group for what is typed after an @ and inserts the chosen name', async () => {
            vi.useFakeTimers();
            try {
                global.fetch = vi.fn(() => Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve([{ id: 4, label: 'Marie Dupont' }])
                }));
                var field = composer('Merci @Mar');

                type(field);
                await vi.advanceTimersByTimeAsync(300);

                expect(fetch).toHaveBeenCalledWith(
                    '/groups/1/mention-search?q=Mar',
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
                );

                var option = document.querySelector('.groups-mention-option');
                expect(option.textContent).toBe('Marie Dupont');

                option.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                expect(field.value).toBe('Merci @Marie Dupont ');
            } finally {
                vi.useRealTimers();
            }
        });

        // The row SHOWS the account and its memberships so two people can
        // be told apart, and INSERTS the account's name alone: nobody
        // wants "@Marie Dupont (Akéla, Baloo)" mid-sentence, and
        // Service\MentionService::resolve() reads the short form back out
        // of the stored body.
        it('shows the memberships in the menu but types only the account name', async () => {
            vi.useFakeTimers();
            try {
                global.fetch = vi.fn(() => Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve([
                        { id: 4, label: 'Marie Dupont (Akéla, Baloo)', mention: 'Marie Dupont' }
                    ])
                }));
                var field = composer('Merci @Mar');

                type(field);
                await vi.advanceTimersByTimeAsync(300);

                var option = document.querySelector('.groups-mention-option');
                expect(option.textContent).toBe('Marie Dupont (Akéla, Baloo)');

                option.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                expect(field.value).toBe('Merci @Marie Dupont ');
            } finally {
                vi.useRealTimers();
            }
        });

        it('does not query on a bare @ — one character is not a search', async () => {
            vi.useFakeTimers();
            try {
                global.fetch = vi.fn();
                var field = composer('Merci @');

                type(field);
                await vi.advanceTimersByTimeAsync(300);

                expect(fetch).not.toHaveBeenCalled();
            } finally {
                vi.useRealTimers();
            }
        });

        it('ignores a field that does not opt in', async () => {
            vi.useFakeTimers();
            try {
                global.fetch = vi.fn();
                document.body.innerHTML = '<textarea id="plain"></textarea>';
                var field = /** @type {HTMLTextAreaElement} */ (document.getElementById('plain'));
                field.value = 'Merci @Marie';
                field.setSelectionRange(field.value.length, field.value.length);

                type(field);
                await vi.advanceTimersByTimeAsync(300);

                expect(fetch).not.toHaveBeenCalled();
            } finally {
                vi.useRealTimers();
            }
        });
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

    // A photo attached to a comment is SHOWN before the comment is sent,
    // not merely named: "did the picker work?" was the only question the
    // old filename label answered, and "is this the right photo?" is the
    // one people actually have.
    describe('the photo attached to a comment', () => {
        function composer() {
            document.body.innerHTML = `
                <form class="groups-reply-form">
                    <input type="file" class="groups-reply-image">
                    <div class="groups-reply-image-preview d-none">
                        <span class="groups-reply-image-thumb">
                            <img alt="" class="groups-reply-image-thumb-img">
                            <button type="button" class="groups-reply-image-remove">×</button>
                        </span>
                        <span class="groups-reply-image-name"></span>
                    </div>
                </form>
            `;
        }

        function pick(name) {
            const input = document.querySelector('.groups-reply-image');
            Object.defineProperty(input, 'files', {
                value: name === null ? [] : [new File(['x'], name, { type: 'image/jpeg' })],
                configurable: true,
            });
            // Delegated on `document`, so only a BUBBLING event reaches it —
            // which `new Event('change')` is not by default, unlike a real
            // browser's native change event.
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        beforeEach(() => {
            // jsdom implements neither, and the preview is built entirely
            // out of them. The URL is never asserted on, only its lifecycle.
            global.URL.createObjectURL = vi.fn(() => 'blob:preview');
            global.URL.revokeObjectURL = vi.fn();
        });

        it('draws the picked photo straight away, from the file itself', async () => {
            composer();

            pick('photo.jpg');

            const preview = document.querySelector('.groups-reply-image-preview');
            expect(preview.classList.contains('d-none')).toBe(false);
            expect(document.querySelector('.groups-reply-image-thumb-img').getAttribute('src')).toBe('blob:preview');
            expect(document.querySelector('.groups-reply-image-name').textContent).toBe('photo.jpg');
            // Drawn from the File itself: nothing is uploaded until the
            // comment is, so abandoning the composer uploads nothing.
            expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
        });

        it('replaces the preview when another photo is picked, and lets the first one go', async () => {
            composer();

            pick('photo.jpg');
            pick('autre.jpg');

            expect(document.querySelector('.groups-reply-image-name').textContent).toBe('autre.jpg');
            // An object URL keeps its File alive until it is revoked.
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview');
        });

        it('hides the preview again when the picker is cleared', async () => {
            composer();

            pick('photo.jpg');
            pick(null);

            const preview = document.querySelector('.groups-reply-image-preview');
            expect(preview.classList.contains('d-none')).toBe(true);
            expect(document.querySelector('.groups-reply-image-thumb-img').hasAttribute('src')).toBe(false);
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview');
        });

        // Emptying the input's own .files is what actually detaches the
        // photo; clearing only the preview would send a photo nothing on
        // screen still showed.
        it('really detaches the photo when the remove button is used', async () => {
            composer();
            pick('photo.jpg');

            document.querySelector('.groups-reply-image-remove').click();

            expect(document.querySelector('.groups-reply-image-preview').classList.contains('d-none')).toBe(true);
            expect(document.querySelector('.groups-reply-image').value).toBe('');
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview');
        });
    });

    it('replying fetches and appends the new reply under the post, then resets the form', async () => {
        document.body.innerHTML = `
            <article>
                <div class="groups-replies"></div>
                <form class="groups-reply-form" action="/groups/1/posts/9/replies" method="post">
                    <input type="text" name="body">
                    <div class="groups-reply-image-preview d-flex">
                        <img alt="" class="groups-reply-image-thumb-img" src="blob:preview">
                        <span class="groups-reply-image-name">photo.jpg</span>
                    </div>
                    <button type="submit">Envoyer</button>
                </form>
            </article>
        `;
        document.querySelector('input[name="body"]').value = 'Bien reçu';
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<div class="groups-reply" id="reply-5">Bien reçu</div>' })
        }));

        document.querySelector('.groups-reply-form button').click();
        await vi.waitFor(() => expect(document.getElementById('reply-5')).not.toBeNull());

        expect(document.querySelector('.groups-replies').lastElementChild.id).toBe('reply-5');
        expect(document.querySelector('input[name="body"]').value).toBe('');
        // The photo goes with the comment it was attached to.
        const preview = document.querySelector('.groups-reply-image-preview');
        expect(preview.classList.contains('d-none')).toBe(true);
        expect(document.querySelector('.groups-reply-image-name').textContent).toBe('');
        expect(document.querySelector('.groups-reply-image-thumb-img').hasAttribute('src')).toBe(false);
        const [url, options] = fetch.mock.calls[0];
        expect(url).toContain('/groups/1/posts/9/replies');
        expect(options.headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
    });

    // partials/post_card.html.twig folds a message's conversation behind a
    // <details>; its summary carries the count, and the count has to stay
    // honest as comments come and go without a reload. The number lives in
    // data-count and the sentence is rewritten from it.
    describe('the collapsed conversation count', () => {
        function threadMarkup(count, label) {
            return `
                <article>
                    <details class="groups-thread" id="post-thread-9" open>
                        <summary class="groups-thread-summary">
                            <span class="groups-thread-count" data-count="${count}">${label}</span>
                        </summary>
                        <div class="groups-replies"></div>
                        <form class="groups-reply-form" action="/groups/1/posts/9/replies" method="post">
                            <input type="text" name="body">
                            <button type="submit">Envoyer</button>
                        </form>
                    </details>
                </article>
            `;
        }

        function count() {
            return document.querySelector('.groups-thread-count');
        }

        it('goes from "Commenter" to "1 commentaire" when the first one is posted', async () => {
            document.body.innerHTML = threadMarkup(0, 'Commenter');
            document.querySelector('input[name="body"]').value = 'Bien reçu';
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ html: '<div class="groups-reply" id="reply-5">Bien reçu</div>' })
            }));

            document.querySelector('.groups-reply-form button').click();
            await vi.waitFor(() => expect(document.getElementById('reply-5')).not.toBeNull());

            expect(count().textContent).toBe('1 commentaire');
            expect(count().dataset.count).toBe('1');
        });

        it('pluralises past the first one', async () => {
            document.body.innerHTML = threadMarkup(1, '1 commentaire');
            document.querySelector('input[name="body"]').value = 'Moi aussi';
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ html: '<div class="groups-reply" id="reply-6">Moi aussi</div>' })
            }));

            document.querySelector('.groups-reply-form button').click();
            await vi.waitFor(() => expect(document.getElementById('reply-6')).not.toBeNull());

            expect(count().textContent).toBe('2 commentaires');
        });

        it('counts back down when a comment is deleted, and back to "Commenter" at zero', async () => {
            document.body.innerHTML = `
                <article>
                    <details class="groups-thread" open>
                        <summary><span class="groups-thread-count" data-count="1">1 commentaire</span></summary>
                        <div class="groups-replies">
                            <div class="groups-reply" id="reply-3">
                                <form class="groups-reply-delete-form" action="/groups/1/replies/3/delete" data-confirm="Supprimer ?">
                                    <button type="submit">Supprimer</button>
                                </form>
                            </div>
                        </div>
                    </details>
                </article>
            `;
            global.fetch = vi.fn(() => Promise.resolve({ ok: true }));
    
            document.querySelector('.groups-reply-delete-form button').click();
            await vi.waitFor(() => expect(document.getElementById('reply-3')).toBeNull());

            expect(count().textContent).toBe('Commenter');
            expect(count().dataset.count).toBe('0');
        });

        it('leaves the count alone when the whole message is deleted', async () => {
            document.body.innerHTML = `
                <article id="post-9">
                    <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                        <button type="submit">Supprimer</button>
                    </form>
                    <details class="groups-thread" open>
                        <summary><span class="groups-thread-count" data-count="2">2 commentaires</span></summary>
                    </details>
                </article>
            `;
            global.fetch = vi.fn(() => Promise.resolve({ ok: true }));
    
            document.querySelector('.groups-post-delete-form button').click();
            await vi.waitFor(() => expect(document.getElementById('post-9')).toBeNull());

            // Nothing to assert on the count itself — the point is that
            // removing the article must not throw on a detached form.
            expect(document.querySelector('.groups-thread-count')).toBeNull();
        });
    });

    it('shows a refused reply inline, without touching the replies list', async () => {
        document.body.innerHTML = `
            <article>
                <div class="groups-replies"></div>
                <form class="groups-reply-form" action="/groups/1/posts/9/replies" method="post">
                    <input type="text" name="body" value="Bien reçu">
                    <p class="d-none groups-reply-error"></p>
                    <button type="submit">Envoyer</button>
                </form>
            </article>
        `;
        global.fetch = vi.fn(() => Promise.resolve({
            ok: false,
            json: () => Promise.resolve({ error: 'Trop de réponses pour le moment.' })
        }));

        document.querySelector('.groups-reply-form button').click();

        const errorBox = document.querySelector('.groups-reply-error');
        await vi.waitFor(() => expect(errorBox.classList.contains('d-none')).toBe(false));
        expect(errorBox.textContent).toBe('Trop de réponses pour le moment.');
        expect(document.querySelector('.groups-replies').children).toHaveLength(0);
    });

    it('deleting a post asks for confirmation, and does nothing at all when cancelled', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                    <button type="submit">Supprimer</button>
                </form>
            </article>
        `;
        global.fetch = vi.fn();
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));

        document.querySelector('.groups-post-delete-form button').click();
        await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
        await Promise.resolve();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith(expect.objectContaining({
            message: 'Supprimer ?',
        }));
        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('post-9')).not.toBeNull();
    });

    // Regression: groups.js called confirm() itself AND left
    // base.html.twig's site-wide handler to call it too, on the belief
    // that its own listener ran first and could stopImmediatePropagation()
    // the other away. `defer` means it never ran first, so every
    // data-confirm form in this module asked twice — and answering the
    // second prompt with "Annuler" left the first one's request already
    // sent.
    it('asks for confirmation exactly once, not twice', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                    <button type="submit">Supprimer</button>
                </form>
            </article>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));

        document.querySelector('.groups-post-delete-form button').click();
        await vi.waitFor(() => expect(document.getElementById('post-9')).toBeNull());

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledTimes(1);
    });

    it('sends nothing at all when the one confirmation is declined', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                    <button type="submit">Supprimer</button>
                </form>
            </article>
        `;
        global.fetch = vi.fn();
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));

        document.querySelector('.groups-post-delete-form button').click();
        await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
        await Promise.resolve();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledTimes(1);
        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('post-9')).not.toBeNull();
    });

    it('deleting a post removes its <article> immediately once confirmed', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                    <button type="submit">Supprimer</button>
                </form>
            </article>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));

        document.querySelector('.groups-post-delete-form button').click();
        await vi.waitFor(() => expect(document.getElementById('post-9')).toBeNull());

        const [url] = fetch.mock.calls[0];
        expect(url).toContain('/groups/1/posts/9/delete');
    });

    it('copies a message\'s absolute link and confirms briefly', async () => {
        vi.useFakeTimers();
        try {
            document.body.innerHTML =
                '<button class="groups-copy-link" data-url="/groups/1/posts/9">Copier le lien</button>';
            const writeText = vi.fn(() => Promise.resolve());
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

            document.querySelector('.groups-copy-link').click();
            await vi.waitFor(() => expect(writeText).toHaveBeenCalled());

            // Absolute, so the copied link works when pasted anywhere.
            expect(writeText).toHaveBeenCalledWith(window.location.origin + '/groups/1/posts/9');
            expect(document.querySelector('.groups-copy-link').textContent).toBe('Lien copié');

            await vi.advanceTimersByTimeAsync(2000);
            expect(document.querySelector('.groups-copy-link').textContent).toBe('Copier le lien');
        } finally {
            vi.useRealTimers();
        }
    });

    it('shows the link when the clipboard API is unavailable, never silently doing nothing', async () => {
        document.body.innerHTML =
            '<button class="groups-copy-link" data-url="/groups/1/posts/9">Copier le lien</button>';
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        const nativePrompt = vi.spyOn(window, 'prompt').mockReturnValue(null);
        const dialog = vi.fn(() => Promise.resolve(null));
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)), prompt: dialog };

        document.querySelector('.groups-copy-link').click();
        await vi.waitFor(() => expect(dialog).toHaveBeenCalled());

        // The site's own dialog, not the native box: read-only and
        // pre-selected, so the member can copy the link by hand.
        expect(nativePrompt).not.toHaveBeenCalled();
        expect(dialog.mock.calls[0][0]).toMatchObject({
            message: 'Copiez le lien de ce message :',
            value: window.location.origin + '/groups/1/posts/9',
            readonly: true,
        });
    });

    it('deleting a reply removes its own .groups-reply, not the whole post', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <div class="groups-reply" id="reply-3">
                    <form class="groups-reply-delete-form" action="/groups/1/replies/3/delete" data-confirm="Supprimer ?">
                        <button type="submit">Supprimer</button>
                    </form>
                </div>
            </article>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));

        document.querySelector('.groups-reply-delete-form button').click();
        await vi.waitFor(() => expect(document.getElementById('reply-3')).toBeNull());

        expect(document.getElementById('post-9')).not.toBeNull();
        expect(fetch.mock.calls[0][0]).toContain('/groups/1/replies/3/delete');
    });

    // The six-emoji picker folds behind one "Réagir" button on a phone
    // (components.css). The fold is CSS gated on `.groups-js`, so a page
    // whose JavaScript never loaded keeps the picker visible and this
    // button hidden — the toggle is never the only way to react.
    describe('the reaction picker toggle', () => {
        it('marks the document as able to fold the picker away', async () => {
            expect(document.documentElement.classList.contains('groups-js')).toBe(true);
        });

        it('opens the picker in place of the button, and says so for assistive tech', async () => {
            document.body.innerHTML = `
                <div class="groups-reactions">
                    <button type="button" class="groups-reaction-toggle" aria-expanded="false">Réagir</button>
                    <div class="groups-reaction-picker">
                        <form class="groups-reaction-form" action="/groups/1/posts/9/react"><button>👍</button></form>
                    </div>
                </div>
            `;

            document.querySelector('.groups-reaction-toggle').click();

            const block = document.querySelector('.groups-reactions');
            expect(block.classList.contains('groups-reactions-open')).toBe(true);
            expect(document.querySelector('.groups-reaction-toggle').getAttribute('aria-expanded')).toBe('true');
        });

        it('only ever opens the block it was clicked in', async () => {
            document.body.innerHTML = `
                <div class="groups-reactions" id="first">
                    <button type="button" class="groups-reaction-toggle" aria-expanded="false">Réagir</button>
                </div>
                <div class="groups-reactions" id="second">
                    <button type="button" class="groups-reaction-toggle" aria-expanded="false">Réagir</button>
                </div>
            `;

            document.querySelector('#first .groups-reaction-toggle').click();

            expect(document.getElementById('first').classList.contains('groups-reactions-open')).toBe(true);
            expect(document.getElementById('second').classList.contains('groups-reactions-open')).toBe(false);
        });

        it('leaves the reaction itself working — the toggle only reveals', async () => {
            document.body.innerHTML = `
                <div class="groups-reactions">
                    <button type="button" class="groups-reaction-toggle" aria-expanded="false">Réagir</button>
                    <div class="groups-reaction-picker">
                        <form class="groups-reaction-form" action="/groups/1/posts/9/react" method="post">
                            <input type="hidden" name="reaction" value="heart">
                            <button type="submit">❤️</button>
                        </form>
                    </div>
                </div>
            `;
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ html: '<div class="groups-reactions">à jour</div>' })
            }));

            document.querySelector('.groups-reaction-toggle').click();
            document.querySelector('.groups-reaction-form button').click();

            await vi.waitFor(() => expect(document.querySelector('.groups-reactions').textContent).toContain('à jour'));
            expect(fetch.mock.calls[0][0]).toContain('/groups/1/posts/9/react');
        });
    });

    it('brings the "no message yet" line back when the last message is deleted', async () => {
        document.body.innerHTML = `
            <div id="groups-feed">
                <p id="groups-feed-empty" class="d-none">Aucun message dans ce groupe pour le moment.</p>
                <article id="post-9">
                    <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                        <button type="submit">Supprimer</button>
                    </form>
                </article>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));

        document.querySelector('.groups-post-delete-form button').click();
        await vi.waitFor(() => expect(document.getElementById('post-9')).toBeNull());

        expect(document.getElementById('groups-feed-empty').classList.contains('d-none')).toBe(false);
    });

    // A pinned message lives OUTSIDE #groups-feed, so counting the stream
    // alone would announce an empty group that still has one at the top.
    it('leaves the line hidden while a pinned message is still there', async () => {
        document.body.innerHTML = `
            <article id="post-1">épinglé</article>
            <div id="groups-feed">
                <p id="groups-feed-empty" class="d-none">Aucun message dans ce groupe pour le moment.</p>
                <article id="post-9">
                    <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                        <button type="submit">Supprimer</button>
                    </form>
                </article>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));

        document.querySelector('.groups-post-delete-form button').click();
        await vi.waitFor(() => expect(document.getElementById('post-9')).toBeNull());

        expect(document.getElementById('groups-feed-empty').classList.contains('d-none')).toBe(true);
    });

    it('deleting a comment never touches the empty-feed line', async () => {
        document.body.innerHTML = `
            <div id="groups-feed">
                <p id="groups-feed-empty" class="d-none">Aucun message.</p>
                <article id="post-9">
                    <details class="groups-thread" open>
                        <summary><span class="groups-thread-count" data-count="1">1 commentaire</span></summary>
                        <div class="groups-reply" id="reply-3">
                            <form class="groups-reply-delete-form" action="/groups/1/replies/3/delete" data-confirm="Supprimer ?">
                                <button type="submit">Supprimer</button>
                            </form>
                        </div>
                    </details>
                </article>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));

        document.querySelector('.groups-reply-delete-form button').click();
        await vi.waitFor(() => expect(document.getElementById('reply-3')).toBeNull());

        expect(document.getElementById('groups-feed-empty').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('.groups-thread-count').textContent).toBe('Commenter');
    });

    // Reporting used to reload the whole group to show one line of
    // reassurance; the line now lands next to the item that was reported.
    describe('reporting without a reload', () => {
        function cardWithReportEntry() {
            document.body.innerHTML = `
                <article id="post-9">
                    <ul>
                        <li>
                            <form class="groups-report-form" action="/groups/1/posts/9/report" data-confirm="Signaler ?">
                                <button type="submit">Signaler</button>
                            </form>
                        </li>
                        <li><button type="button">Supprimer</button></li>
                    </ul>
                    <p class="groups-report-confirmation d-none"></p>
                </article>
            `;
            }

        it('shows the server confirmation on the card and takes the entry out of the menu', async () => {
            cardWithReportEntry();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ reported: true, message: 'Merci, votre signalement a bien été transmis.' })
            }));

            document.querySelector('.groups-report-form button').click();

            const note = document.querySelector('.groups-report-confirmation');
            await vi.waitFor(() => expect(note.classList.contains('d-none')).toBe(false));
            expect(note.textContent).toBe('Merci, votre signalement a bien été transmis.');
            expect(document.querySelector('.groups-report-form')).toBeNull();
            // The menu itself stays — only the entry went.
            expect(document.querySelectorAll('article li')).toHaveLength(1);
            expect(fetch.mock.calls[0][1].headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
        });

        it('nothing happens at all when the confirmation is declined', async () => {
            cardWithReportEntry();
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            global.fetch = vi.fn();

            document.querySelector('.groups-report-form button').click();
            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            await Promise.resolve();

            expect(fetch).not.toHaveBeenCalled();
            expect(document.querySelector('.groups-report-form')).not.toBeNull();
        });

        it('falls back to a real form submit when the answer is not the JSON it expects', async () => {
            cardWithReportEntry();
            const form = document.querySelector('.groups-report-form');
            form.submit = vi.fn();
            global.fetch = vi.fn(() => Promise.resolve({ ok: false, json: () => Promise.reject(new Error('not json')) }));

            form.querySelector('button').click();

            await vi.waitFor(() => expect(form.submit).toHaveBeenCalled());
        });
    });

    // "Rétablir": nothing about the item changes except that it stops
    // being hidden, so the warning and the dimming come off where it
    // stands rather than the group reloading around them.
    describe('restoring a hidden item without a reload', () => {
        function hiddenCard() {
            document.body.innerHTML = `
                <article id="post-9" class="card groups-post-hidden">
                    <div class="groups-hidden-banner">
                        Message masqué.
                        <form class="groups-restore-form" action="/groups/1/posts/9/restore">
                            <button type="submit">Rétablir</button>
                        </form>
                    </div>
                    <p>le message</p>
                </article>
            `;
        }

        it('takes the warning and the dimming off the item', async () => {
            hiddenCard();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ restored: true })
            }));

            document.querySelector('.groups-restore-form button').click();

            await vi.waitFor(() => expect(document.querySelector('.groups-hidden-banner')).toBeNull());
            const article = document.getElementById('post-9');
            expect(article).not.toBeNull();
            expect(article.classList.contains('groups-post-hidden')).toBe(false);
        });

        // The one refusal this endpoint has: a moderator may not restore
        // content they wrote themselves. It has to be read, not swallowed.
        it('shows the refusal when a moderator tries to restore their own content', async () => {
            hiddenCard();
            const nativeAlert = vi.fn();
            window.alert = nativeAlert;
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                json: () => Promise.resolve({ error: 'Vous ne pouvez pas rétablir un contenu que vous avez écrit.' })
            }));

            document.querySelector('.groups-restore-form button').click();

            await vi.waitFor(() => expect(lastToastText()).toBe(
                'Vous ne pouvez pas rétablir un contenu que vous avez écrit.'
            ));
            expect(document.querySelector('.toast').className).toContain('text-bg-danger');
            expect(nativeAlert).not.toHaveBeenCalled();
            expect(document.querySelector('.groups-hidden-banner')).not.toBeNull();
        });
    });

    it('editing a post swaps only its body, leaving the replies underneath alone', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <p id="post-body-9" class="groups-post-body">Ancien texte</p>
                <div class="groups-replies"><div class="groups-reply" id="reply-3">une réponse</div></div>
                <form class="groups-edit-form" id="post-edit-9" action="/groups/1/posts/9/edit">
                    <textarea name="body">Nouveau texte</textarea>
                    <button type="submit">Enregistrer</button>
                </form>
            </article>
        `;
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<p id="post-body-9" class="groups-post-body">Nouveau texte</p>' })
        }));

        document.querySelector('.groups-edit-form button').click();
        await vi.waitFor(() => expect(document.getElementById('post-body-9').textContent).toBe('Nouveau texte'));

        expect(document.getElementById('reply-3')).not.toBeNull();
        expect(document.getElementById('post-edit-9').classList.contains('d-none')).toBe(true);
    });

    it('editing a reply swaps its whole card', async () => {
        document.body.innerHTML = `
            <div class="groups-reply" id="reply-3">
                <p id="reply-body-3">Ancien</p>
                <form class="groups-reply-edit-form" id="reply-edit-3" action="/groups/1/replies/3/edit">
                    <textarea name="body">Nouveau</textarea>
                    <button type="submit">Enregistrer</button>
                </form>
            </div>
        `;
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<div class="groups-reply" id="reply-3"><p id="reply-body-3">Nouveau</p></div>' })
        }));

        document.querySelector('.groups-reply-edit-form button').click();
        await vi.waitFor(() => expect(document.getElementById('reply-body-3').textContent).toBe('Nouveau'));

        expect(document.getElementById('reply-edit-3')).toBeNull();
    });

    it('shows a refused edit without closing the form, so the text can be revised', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <p id="post-body-9">Ancien texte</p>
                <form class="groups-edit-form" id="post-edit-9" action="/groups/1/posts/9/edit">
                    <textarea name="body">Grossier</textarea>
                    <button type="submit">Enregistrer</button>
                </form>
            </article>
        `;
        const nativeAlert = vi.fn();
        window.alert = nativeAlert;
        global.fetch = vi.fn(() => Promise.resolve({
            ok: false,
            json: () => Promise.resolve({ error: 'Ce message a été refusé.', type: 'offensive' })
        }));

        document.querySelector('.groups-edit-form button').click();
        await vi.waitFor(() => expect(lastToastText()).toBe('Ce message a été refusé.'));
        expect(nativeAlert).not.toHaveBeenCalled();

        expect(document.getElementById('post-body-9').textContent).toBe('Ancien texte');
        expect(document.getElementById('post-edit-9').classList.contains('d-none')).toBe(false);
    });

    it('falls back to a real form submit when the delete request fails', async () => {
        document.body.innerHTML = `
            <article id="post-9">
                <form class="groups-post-delete-form" action="/groups/1/posts/9/delete" data-confirm="Supprimer ?">
                    <button type="submit">Supprimer</button>
                </form>
            </article>
        `;
        const form = document.querySelector('.groups-post-delete-form');
        form.submit = vi.fn();
        global.fetch = vi.fn(() => Promise.resolve({ ok: false }));

        document.querySelector('.groups-post-delete-form button').click();
        await vi.waitFor(() => expect(form.submit).toHaveBeenCalled());

        expect(document.getElementById('post-9')).not.toBeNull();
    });
});
