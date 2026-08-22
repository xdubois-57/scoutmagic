// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/groups.js (imported below, never
// reimplemented here). That file is a plain IIFE that reads the DOM at
// import time, so every test builds its DOM first and then imports the
// module through a reset registry — same pattern as gallery.test.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadGroups() {
    vi.resetModules();
    await import('../../public/assets/js/groups.js');
}

// jsdom does not implement the DataTransfer API at all (tracked upstream,
// never fixed): groups.js uses `new DataTransfer()` to populate the hidden
// <input>'s FileList, exactly the way real browsers require. A minimal
// stand-in plus overriding the hidden input's own `.files` property (jsdom's
// native setter strictly validates against its own FileList type, which a
// polyfill can never satisfy) is the standard workaround for testing this
// pattern under jsdom — production code is untouched.
class FakeDataTransfer {
    constructor() {
        this._files = [];
        this.items = { add: (file) => { this._files.push(file); } };
    }
    get files() {
        return this._files;
    }
}

describe('groups.js composer media picker', () => {
    beforeEach(() => {
        localStorage.clear();
        global.DataTransfer = FakeDataTransfer;
        // jsdom does not implement createObjectURL either — stubbed so the
        // image-preview branch (an <img src="blob:...">) can run at all;
        // its actual return value is never asserted on.
        global.URL.createObjectURL = vi.fn(() => 'blob:fake');
        // initComposer() now owns the media picker, the live link preview
        // and the draft cache together (one merged IIFE — see groups.js's
        // own docblock for why) and requires #post-body to exist even for
        // tests that only exercise the media picker.
        document.body.innerHTML = `
            <form id="groups-post-form" data-max-media="2">
                <textarea id="post-body"></textarea>
                <div id="groups-media-previews"></div>
                <input type="file" name="media[]" id="groups-media-hidden" class="d-none" multiple>
                <span id="groups-media-count"></span>
                <p id="groups-media-limit-warning" class="d-none"></p>
                <input type="file" id="groups-media-input" multiple>
            </form>
        `;
        Object.defineProperty(document.getElementById('groups-media-hidden'), 'files', {
            writable: true,
            configurable: true,
            value: [],
        });
    });

    function makeFile(name, type) {
        return new File(['x'], name, { type });
    }

    function fireChange(input, files) {
        Object.defineProperty(input, 'files', { value: files, configurable: true });
        // groups-media-input has its own direct listener (not delegated),
        // so bubbling is irrelevant here — but true matches a real
        // browser's change event and costs nothing.
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    it('adds a selected file to the preview grid and updates the count label', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('a.jpg', 'image/jpeg')]);

        expect(document.getElementById('groups-media-previews').children).toHaveLength(1);
        expect(document.getElementById('groups-media-count').textContent).toBe('1 / 2 médias');
    });

    it('caps selection at data-max-media and shows the limit warning', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('a.jpg', 'image/jpeg'), makeFile('b.jpg', 'image/jpeg'), makeFile('c.jpg', 'image/jpeg')]);

        expect(document.getElementById('groups-media-previews').children).toHaveLength(2);
        expect(document.getElementById('groups-media-count').textContent).toBe('2 / 2 médias');
        expect(document.getElementById('groups-media-limit-warning').classList.contains('d-none')).toBe(false);
    });

    it('resets the input value after each selection so re-picking the same file fires change again', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('a.jpg', 'image/jpeg')]);

        expect(input.value).toBe('');
    });

    it('removing a preview drops it from the count and the hidden input', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');
        fireChange(input, [makeFile('a.jpg', 'image/jpeg'), makeFile('b.jpg', 'image/jpeg')]);

        document.querySelector('#groups-media-previews button').click();

        expect(document.getElementById('groups-media-previews').children).toHaveLength(1);
        expect(document.getElementById('groups-media-count').textContent).toBe('1 / 2 médias');
    });

    it('shows a generic icon rather than an <img> for a non-image file (a video)', async () => {
        await loadGroups();
        const input = document.getElementById('groups-media-input');

        fireChange(input, [makeFile('clip.mp4', 'video/mp4')]);

        const cell = document.querySelector('#groups-media-previews > div');
        expect(cell.querySelector('img')).toBeNull();
        expect(cell.querySelector('.bi-camera-video')).not.toBeNull();
    });
});

describe('groups.js dynamic post submit and draft cache', () => {
    beforeEach(() => {
        localStorage.clear();
        global.DataTransfer = FakeDataTransfer;
        global.URL.createObjectURL = vi.fn(() => 'blob:fake');
        document.body.innerHTML = `
            <div id="groups-feed"></div>
            <form id="groups-post-form" action="/groups/1/posts" data-max-media="4" data-group-id="1" data-draft-ttl-minutes="60">
                <textarea id="post-body" name="body"></textarea>
                <input type="text" name="poll_question">
                <input type="text" name="poll_options[]">
                <input type="text" name="poll_options[]">
                <div id="groups-media-previews"></div>
                <input type="file" name="media[]" id="groups-media-hidden" class="d-none" multiple>
                <input type="file" id="groups-media-input" multiple>
                <div class="d-none" id="groups-link-preview" data-preview-url="/groups/1/link-preview"></div>
                <p class="d-none" id="groups-post-error"></p>
                <button type="submit">Publier</button>
            </form>
        `;
        Object.defineProperty(document.getElementById('groups-media-hidden'), 'files', {
            writable: true,
            configurable: true,
            value: [],
        });
    });

    function textarea() {
        return document.getElementById('post-body');
    }

    function submit(form) {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    it('publishing a post inserts it at the top of the feed and resets the composer', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<article id="post-42">Bonjour</article>' })
        }));

        textarea().value = 'Bonjour';
        submit(document.getElementById('groups-post-form'));
        await vi.waitFor(() => expect(document.getElementById('post-42')).not.toBeNull());

        expect(document.getElementById('groups-feed').firstElementChild.id).toBe('post-42');
        expect(textarea().value).toBe('');
        const [url, options] = fetch.mock.calls[0];
        expect(url).toContain('/groups/1/posts');
        expect(options.headers).toEqual({ 'X-Requested-With': 'XMLHttpRequest' });
    });

    // On a phone the composer is tall enough to fill the screen, so the
    // card inserted above the feed lands entirely below the fold and
    // publishing looks like nothing happening. The server names the post
    // it just created and the composer scrolls to it.
    it('brings the published post into view, centred', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<article id="post-42">Bonjour</article>', post_id: 42 })
        }));

        // jsdom implements no scrolling at all, so the method has to
        // exist before the card is inserted — the production code checks
        // for it and stays silent when a browser has none.
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;

        textarea().value = 'Bonjour';
        submit(document.getElementById('groups-post-form'));
        await vi.waitFor(() => expect(scrollIntoView).toHaveBeenCalled());

        expect(scrollIntoView.mock.instances[0].id).toBe('post-42');
        expect(scrollIntoView.mock.calls[0][0]).toMatchObject({ block: 'center' });
    });

    // Controller\PostController::create()'s own comment has always assumed
    // the composer prevents an empty submit ("the composer already
    // disables its own submit button on an empty draft"). It never did:
    // pressing "Publier" on an empty composer spent a round trip to be
    // told « Un message ne peut pas être vide. » about a form the member
    // can see is empty.
    describe('the publish button', () => {
        function publishButton() {
            return document.querySelector('#groups-post-form button[type="submit"]');
        }

        function type(value) {
            textarea().value = value;
            textarea().dispatchEvent(new Event('input', { bubbles: true }));
        }

        it('starts disabled on an empty composer', async () => {
            await loadGroups();

            expect(publishButton().disabled).toBe(true);
        });

        it('enables as soon as there is text, and disables again when it is cleared', async () => {
            await loadGroups();

            type('Bonjour');
            expect(publishButton().disabled).toBe(false);

            type('   ');
            expect(publishButton().disabled).toBe(true, 'whitespace is not a message');
        });

        it('enables on a photo alone — a media-only post is valid', async () => {
            await loadGroups();
            const input = document.getElementById('groups-media-input');
            Object.defineProperty(input, 'files', {
                value: [new File(['x'], 'a.jpg', { type: 'image/jpeg' })],
                configurable: true,
            });

            input.dispatchEvent(new Event('change', { bubbles: true }));

            expect(publishButton().disabled).toBe(false);
        });

        // Service\PollService::normalise() refuses a poll with fewer than
        // two real choices, so a half-filled one must not light the button
        // up either — the server would answer "empty" for it.
        it('stays disabled for a half-filled poll, and enables on the second choice', async () => {
            await loadGroups();
            const options = document.querySelectorAll('input[name="poll_options[]"]');

            document.querySelector('input[name="poll_question"]').value = 'Qui vient ?';
            document.querySelector('input[name="poll_question"]').dispatchEvent(new Event('input', { bubbles: true }));
            expect(publishButton().disabled).toBe(true, 'a question with no choice is not a poll');

            options[0].value = 'Oui';
            options[0].dispatchEvent(new Event('input', { bubbles: true }));
            expect(publishButton().disabled).toBe(true, 'one choice is not a poll either');

            options[1].value = 'Non';
            options[1].dispatchEvent(new Event('input', { bubbles: true }));
            expect(publishButton().disabled).toBe(false);
        });

        it('comes back disabled once the post has published and emptied the composer', async () => {
            await loadGroups();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ html: '<article id="post-3"></article>' })
            }));

            // Value set directly rather than through type(): an 'input'
            // event also starts the draft cache's own 500 ms debounce,
            // whose timer would outlive this test and write to
            // localStorage in the middle of a later one. The submit event
            // is dispatched on the form, so the button's disabled state is
            // not in the way either.
            textarea().value = 'Bonjour';
            submit(document.getElementById('groups-post-form'));
            await vi.waitFor(() => expect(document.getElementById('post-3')).not.toBeNull());

            expect(publishButton().disabled).toBe(true);
        });

        it('comes back ENABLED after a refused post, so the member can resend', async () => {
            await loadGroups();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                json: () => Promise.resolve({ error: 'Vous avez atteint la limite de messages.' })
            }));

            textarea().value = 'Trop de messages';
            submit(document.getElementById('groups-post-form'));

            await vi.waitFor(() => expect(publishButton().disabled).toBe(false));
            expect(textarea().value).toBe('Trop de messages');
        });

        it('is enabled again for a draft restored from the browser', async () => {
            localStorage.setItem('groups-draft-1', JSON.stringify({ body: 'Repris', savedAt: Date.now() }));

            await loadGroups();

            expect(textarea().value).toBe('Repris');
            expect(publishButton().disabled).toBe(false);
        });
    });

    // Regression: the composer used to build its FormData AFTER
    // setBusy(true) had disabled the textarea, and a disabled control
    // contributes nothing to a form's data set. The message never reached
    // the server, which answered {error:'empty'} — the member saw
    // « Un message ne peut pas être vide. » for a message they had
    // plainly just typed. A post that also carried a photo published, but
    // with its text silently dropped.
    it('submits the typed message even though the composer is greyed out during the request', async () => {
        await loadGroups();
        /** @type {FormData|null} */
        let sent = null;
        global.fetch = vi.fn((url, options) => {
            sent = options.body;
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ html: '<article id="post-7"></article>' }) });
        });

        textarea().value = 'Rendez-vous samedi à 9h';
        submit(document.getElementById('groups-post-form'));
        await vi.waitFor(() => expect(document.getElementById('post-7')).not.toBeNull());

        expect(sent).toBeInstanceOf(FormData);
        expect(sent.get('body')).toBe('Rendez-vous samedi à 9h');
        // The composer really was greyed out for the round trip — the fix
        // is the ordering, not dropping the busy state.
        expect(textarea().disabled).toBe(false);
    });

    it('submits a poll question and its options alongside the message', async () => {
        await loadGroups();
        /** @type {FormData|null} */
        let sent = null;
        global.fetch = vi.fn((url, options) => {
            sent = options.body;
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ html: '<article id="post-8"></article>' }) });
        });

        textarea().value = 'On se decide';
        const options = document.querySelectorAll('input[name="poll_options[]"]');
        document.querySelector('input[name="poll_question"]').value = 'Qui vient au week-end ?';
        options[0].value = 'Oui';
        options[1].value = 'Non';

        submit(document.getElementById('groups-post-form'));
        await vi.waitFor(() => expect(document.getElementById('post-8')).not.toBeNull());

        expect(sent.get('poll_question')).toBe('Qui vient au week-end ?');
        expect(sent.getAll('poll_options[]')).toEqual(['Oui', 'Non']);
    });

    // Regression: the empty-state line lives INSIDE #groups-feed, and the
    // composer inserts the new card into that same container without
    // reloading — so the group's very first message used to appear right
    // above "Aucun message dans ce groupe pour le moment."
    it('hides the "no message yet" line when the first post is published', async () => {
        await loadGroups();
        document.getElementById('groups-feed').innerHTML =
            '<p id="groups-feed-empty">Aucun message dans ce groupe pour le moment.</p>';
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<article id="post-11">Premier</article>' })
        }));

        textarea().value = 'Premier';
        submit(document.getElementById('groups-post-form'));
        await vi.waitFor(() => expect(document.getElementById('post-11')).not.toBeNull());

        expect(document.getElementById('groups-feed-empty').classList.contains('d-none')).toBe(true);
    });

    it('closes the poll section again once the post is published', async () => {
        await loadGroups();
        const form = document.getElementById('groups-post-form');
        const section = document.createElement('details');
        section.open = true;
        section.innerHTML = '<summary>Ajouter un sondage</summary>';
        form.appendChild(section);
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<article id="post-12"></article>' })
        }));

        textarea().value = 'Avec un sondage';
        submit(form);
        await vi.waitFor(() => expect(document.getElementById('post-12')).not.toBeNull());

        // form.reset() empties the boxes but leaves the section open, which
        // reads as "a second poll is expected".
        expect(section.open).toBe(false);
    });

    it('disables the textarea and the submit button while the request is in flight', async () => {
        await loadGroups();
        let resolveFetch;
        global.fetch = vi.fn(() => new Promise((resolve) => { resolveFetch = resolve; }));

        textarea().value = 'Bonjour';
        submit(document.getElementById('groups-post-form'));
        await vi.waitFor(() => expect(textarea().disabled).toBe(true));
        expect(document.querySelector('#groups-post-form button[type="submit"]').disabled).toBe(true);

        resolveFetch({ ok: true, json: () => Promise.resolve({ html: '<article id="post-1"></article>' }) });
        await vi.waitFor(() => expect(textarea().disabled).toBe(false));
    });

    it('shows a server refusal inline without clearing the draft', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: false,
            json: () => Promise.resolve({ error: 'Vous avez atteint la limite de messages.' })
        }));

        textarea().value = 'Trop de messages';
        submit(document.getElementById('groups-post-form'));

        const errorBox = document.getElementById('groups-post-error');
        await vi.waitFor(() => expect(errorBox.classList.contains('d-none')).toBe(false));
        expect(errorBox.textContent).toBe('Vous avez atteint la limite de messages.');
        expect(textarea().value).toBe('Trop de messages');
        expect(document.getElementById('groups-feed').children).toHaveLength(0);
    });

    it('shows a connection-lost message on a network failure, keeping the draft', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));

        textarea().value = 'Hors ligne';
        submit(document.getElementById('groups-post-form'));

        const errorBox = document.getElementById('groups-post-error');
        await vi.waitFor(() => expect(errorBox.classList.contains('d-none')).toBe(false));
        expect(errorBox.textContent).toContain('Connexion perdue');
        expect(textarea().value).toBe('Hors ligne');
    });

    it('falls back to a real form submit when the response is not JSON at all', async () => {
        await loadGroups();
        const form = /** @type {HTMLFormElement} */ (document.getElementById('groups-post-form'));
        form.submit = vi.fn();
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, json: () => Promise.reject(new Error('not json')) }));

        textarea().value = 'Jeton périmé';
        submit(form);

        await vi.waitFor(() => expect(form.submit).toHaveBeenCalled());
    });

    it('caches the draft after typing, and restores it on the next load', async () => {
        vi.useFakeTimers();
        try {
            await loadGroups();
            textarea().value = 'Message en cours de frappe';
            textarea().dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(500);

            expect(JSON.parse(localStorage.getItem('groups-draft-1')).body).toBe('Message en cours de frappe');

            // A fresh page load: a new composer instance, over the exact
            // same (still empty) markup — the draft must come back on its
            // own, with no server-side rejected_draft involved.
            await loadGroups();
            expect(textarea().value).toBe('Message en cours de frappe');
        } finally {
            vi.useRealTimers();
        }
    });

    it('does not restore a draft older than the configured TTL, and removes it', async () => {
        localStorage.setItem('groups-draft-1', JSON.stringify({ body: 'Trop vieux', savedAt: Date.now() - 61 * 60000 }));

        await loadGroups();

        expect(textarea().value).toBe('');
        expect(localStorage.getItem('groups-draft-1')).toBeNull();
    });

    it('never overwrites text the server already prefilled (a moderation rejection)', async () => {
        localStorage.setItem('groups-draft-1', JSON.stringify({ body: 'Brouillon local', savedAt: Date.now() }));
        textarea().value = 'Texte déjà refusé par le serveur';

        await loadGroups();

        expect(textarea().value).toBe('Texte déjà refusé par le serveur');
    });

    it('clears the cached draft once the post actually publishes', async () => {
        vi.useFakeTimers();
        try {
            await loadGroups();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ html: '<article id="post-9"></article>' })
            }));

            textarea().value = 'Bientôt publié';
            textarea().dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(500);
            expect(localStorage.getItem('groups-draft-1')).not.toBeNull();

            submit(document.getElementById('groups-post-form'));
            await vi.waitFor(() => expect(document.getElementById('post-9')).not.toBeNull());

            expect(localStorage.getItem('groups-draft-1')).toBeNull();
        } finally {
            vi.useRealTimers();
        }
    });
});

describe('groups.js live link preview', () => {
    beforeEach(() => {
        localStorage.clear();
        global.DataTransfer = FakeDataTransfer;
        document.head.innerHTML = '<meta name="csrf-token" content="tok">';
        // initComposer() now owns the media picker, the live link preview
        // and the draft cache together — the form/media elements are
        // required for it to run at all, even though these tests only
        // exercise the link-preview part.
        document.body.innerHTML = `
            <form id="groups-post-form" data-max-media="4">
                <textarea id="post-body"></textarea>
                <div id="groups-media-previews"></div>
                <input type="file" name="media[]" id="groups-media-hidden" class="d-none" multiple>
                <input type="file" id="groups-media-input" multiple>
                <div class="d-none" id="groups-link-preview" data-preview-url="/groups/1/link-preview"></div>
                <button type="submit">Publier</button>
            </form>
        `;
    });

    function textarea() {
        return document.getElementById('post-body');
    }

    function preview() {
        return document.getElementById('groups-link-preview');
    }

    function submitButton() {
        return document.querySelector('#groups-post-form button[type="submit"]');
    }

    it('does nothing for plain text with no URL — no fetch, stays hidden', async () => {
        vi.useFakeTimers();
        try {
            await loadGroups();
            global.fetch = vi.fn();

            textarea().value = 'Un message sans lien';
            textarea().dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(800);

            expect(fetch).not.toHaveBeenCalled();
            expect(preview().classList.contains('d-none')).toBe(true);
        } finally {
            vi.useRealTimers();
        }
    });

    it('debounces on typing: fetches 800ms after the last keystroke, sending the CSRF header and the body form-encoded', async () => {
        vi.useFakeTimers();
        try {
            await loadGroups();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ url: 'https://example.org', title: 'Titre', description: null, image_data_uri: null })
            }));

            textarea().value = 'Regarde https://example.org';
            textarea().dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(799);
            expect(fetch).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(1);
            expect(fetch).toHaveBeenCalledWith('/groups/1/link-preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': 'tok',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'body=' + encodeURIComponent('Regarde https://example.org')
            });
        } finally {
            vi.useRealTimers();
        }
    });

    it('fires immediately on blur, bypassing the debounce', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ url: 'https://example.org', title: null, description: null, image_data_uri: null })
        }));

        textarea().value = 'https://example.org';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    });

    // Regression: the preview container sits directly ABOVE the publish
    // button, and pressing that button moves focus, which fires this very
    // blur. Revealing the container (spinner first) from inside the blur
    // handler moved the button down between mousedown and mouseup, so no
    // `click` was ever produced and pressing "Publier" on a message
    // containing a link silently did nothing the first time.
    it('does not fire on the blur caused by pressing the publish button', async () => {
        await loadGroups();
        global.fetch = vi.fn();

        textarea().value = 'Regarde https://example.org';
        textarea().dispatchEvent(new FocusEvent('blur', { relatedTarget: submitButton() }));

        expect(fetch).not.toHaveBeenCalled();
        expect(preview().classList.contains('d-none')).toBe(true);
    });

    // …and leaving the field for anything else still previews immediately,
    // which is what the blur trigger exists for.
    it('still fires on the blur caused by leaving the field for another control', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ url: 'https://example.org', title: null, description: null, image_data_uri: null })
        }));

        textarea().value = 'Regarde https://example.org';
        textarea().dispatchEvent(new FocusEvent('blur', { relatedTarget: document.getElementById('groups-media-input') }));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    });

    it('fires on paste, once the pasted text has actually landed in the field', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ url: 'https://example.org', title: null, description: null, image_data_uri: null })
        }));

        textarea().dispatchEvent(new Event('paste'));
        // A real paste event fires before the browser inserts the text —
        // simulated here by setting .value only after the event, exactly
        // like a real paste would land one tick later.
        textarea().value = 'https://example.org';
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    });

    it('renders the resolved title, description and image as a real link card, and hostname-only when the URL cannot be parsed', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                url: 'https://example.org/page',
                title: 'Un titre',
                description: 'Une description',
                image_data_uri: 'data:image/png;base64,AAAA'
            })
        }));

        textarea().value = 'https://example.org/page';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(preview().querySelector('a.groups-link-preview')).not.toBeNull());

        const card = preview().querySelector('a.groups-link-preview');
        expect(card.href).toBe('https://example.org/page');
        expect(card.target).toBe('_blank');
        expect(card.querySelector('img').src).toBe('data:image/png;base64,AAAA');
        expect(card.textContent).toContain('example.org');
        expect(card.textContent).toContain('Un titre');
        expect(card.textContent).toContain('Une description');
    });

    it('falls back to a plain-link card when nothing could be resolved', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ url: 'https://example.org/page', title: null, description: null, image_data_uri: null })
        }));

        textarea().value = 'https://example.org/page';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(preview().querySelector('a.groups-link-preview')).not.toBeNull());

        expect(preview().querySelector('img')).toBeNull();
        expect(preview().textContent).toContain('https://example.org/page');
    });

    it('never interprets a remote title/description as HTML — textContent only, no injected tag', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                url: 'https://evil.example/page',
                title: '<img src=x onerror=alert(1)>',
                description: '<script>alert(2)</script>',
                image_data_uri: null
            })
        }));

        textarea().value = 'https://evil.example/page';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(preview().querySelector('a.groups-link-preview')).not.toBeNull());

        expect(preview().querySelector('img')).toBeNull();
        expect(preview().querySelector('script')).toBeNull();
        expect(preview().textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('hides the card again once the URL is edited away', async () => {
        await loadGroups();
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ url: 'https://example.org', title: 'Titre', description: null, image_data_uri: null })
            });

        textarea().value = 'https://example.org';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(preview().querySelector('a.groups-link-preview')).not.toBeNull());

        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ url: null }) }));
        textarea().value = 'Plus de lien ici';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(preview().classList.contains('d-none')).toBe(true));
    });

    it('discards a stale response that resolves after a newer request already answered', async () => {
        await loadGroups();
        let resolveFirst;
        const first = new Promise((resolve) => { resolveFirst = resolve; });
        global.fetch = vi.fn()
            .mockImplementationOnce(() => first)
            .mockImplementationOnce(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ url: 'https://second.example', title: 'Second', description: null, image_data_uri: null })
            }));

        textarea().value = 'https://first.example';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

        textarea().value = 'https://second.example';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        await vi.waitFor(() => expect(preview().textContent).toContain('Second'));

        // The first request finally resolves — its (now stale) answer
        // must not overwrite the second, newer one already on screen.
        resolveFirst({
            ok: true,
            json: () => Promise.resolve({ url: 'https://first.example', title: 'First', description: null, image_data_uri: null })
        });
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(preview().textContent).toContain('Second');
        expect(preview().textContent).not.toContain('First');
    });

    it('a later no-URL edit wins even if an earlier fetch for a real URL is still in flight when it resolves', async () => {
        await loadGroups();
        let resolveFirst;
        const first = new Promise((resolve) => { resolveFirst = resolve; });
        global.fetch = vi.fn(() => first);

        textarea().value = 'https://example.org';
        textarea().dispatchEvent(new Event('blur'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

        // Edited away before the first fetch ever answers — no second
        // fetch() at all, since there is no URL left to ask about.
        textarea().value = 'Plus de lien ici';
        textarea().dispatchEvent(new Event('blur'));
        expect(preview().classList.contains('d-none')).toBe(true);

        // The abandoned first fetch resolves late — its answer must stay
        // discarded, not reopen the card the second edit already closed.
        resolveFirst({
            ok: true,
            json: () => Promise.resolve({ url: 'https://example.org', title: 'Stale', description: null, image_data_uri: null })
        });
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(preview().classList.contains('d-none')).toBe(true);
        expect(preview().textContent).not.toContain('Stale');
    });
});

describe('groups.js invite-member search (members.html.twig)', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <input type="text" class="d-none" id="invite-member-search" data-search-url="/groups/1/member-search">
            <ul class="d-none" id="invite-member-results"></ul>
            <select id="invite-member">
                <option value="" selected>— Choisir —</option>
                <option value="7">Akéla</option>
            </select>
        `;
    });

    it('swaps the plain dropdown for the search box on load', async () => {
        await loadGroups();

        expect(document.getElementById('invite-member-search').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('invite-member-results').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('invite-member').classList.contains('d-none')).toBe(true);
    });

    it('does nothing for a one-character query, without calling fetch', async () => {
        await loadGroups();
        global.fetch = vi.fn();

        const input = document.getElementById('invite-member-search');
        input.value = 'a';
        input.dispatchEvent(new Event('input'));
        await vi.waitFor(() => expect(document.getElementById('invite-member-results').classList.contains('d-none')).toBe(true));

        expect(fetch).not.toHaveBeenCalled();
    });

    it('fetches and lists matches, debounced, with the XHR header', async () => {
        vi.useFakeTimers();
        try {
            await loadGroups();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 7, label: 'Akéla (Marie Dupont)' }])
            }));

            const input = document.getElementById('invite-member-search');
            input.value = 'ak';
            input.dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(250);

            expect(fetch).toHaveBeenCalledWith(
                '/groups/1/member-search?q=ak',
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );
            const results = document.getElementById('invite-member-results');
            expect(results.children).toHaveLength(1);
            expect(results.children[0].textContent).toBe('Akéla (Marie Dupont)');
            expect(results.classList.contains('d-none')).toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    it('clicking a result selects it on the real <select> and fills the search box', async () => {
        vi.useFakeTimers();
        try {
            await loadGroups();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 7, label: 'Akéla (Marie Dupont)' }])
            }));

            const input = document.getElementById('invite-member-search');
            input.value = 'ak';
            input.dispatchEvent(new Event('input'));
            await vi.advanceTimersByTimeAsync(250);

            document.querySelector('#invite-member-results li').click();

            expect(document.getElementById('invite-member').value).toBe('7');
            expect(input.value).toBe('Akéla (Marie Dupont)');
            expect(document.getElementById('invite-member-results').classList.contains('d-none')).toBe(true);
        } finally {
            vi.useRealTimers();
        }
    });

    it('typing again after a pick clears the stale selection', async () => {
        await loadGroups();
        document.getElementById('invite-member').value = '7';

        // A single character stays below the two-character search
        // threshold, so this only exercises the reset — no fetch() to mock.
        const input = document.getElementById('invite-member-search');
        input.value = 'b';
        input.dispatchEvent(new Event('input'));

        expect(document.getElementById('invite-member').value).toBe('');
    });
});

// partials/post_card.html.twig folds each message's conversation behind a
// <details>. groups.js opens it in the two cases where the reader has
// already said they want it — and, just as importantly, in no other.
describe('groups.js opening a collapsed conversation', () => {
    beforeEach(() => {
        // jsdom implements neither scrollIntoView nor a layout, and the
        // production code only uses it to bring a now-taller card back
        // into view. Stubbed rather than guarded in production, where
        // every browser has it.
        Element.prototype.scrollIntoView = vi.fn();
        window.location.hash = '';
    });

    function feedWithThreads() {
        document.body.innerHTML = `
            <div id="groups-feed">
                <article id="post-8">
                    <details class="groups-thread" id="post-thread-8">
                        <summary><span class="groups-thread-count" data-count="4">4 commentaires</span></summary>
                        <form class="groups-reply-form"><input type="text" name="body"></form>
                    </details>
                </article>
                <article id="post-9">
                    <details class="groups-thread" id="post-thread-9">
                        <summary><span class="groups-thread-count" data-count="0">Commenter</span></summary>
                        <form class="groups-reply-form"><input type="text" name="body" id="reply-input-9"></form>
                    </details>
                </article>
            </div>
        `;
    }

    // Every group notification deep-links to /groups/{id}/posts/{postId},
    // which redirects to the feed anchored on #post-{postId}. For a
    // "somebody answered you" notification the answer is inside the
    // fold, so a closed thread would show the reader only the message
    // they already knew about.
    it('opens the thread a deep link points at, and only that one', async () => {
        feedWithThreads();
        window.location.hash = '#post-9';

        await loadGroups();

        expect(document.getElementById('post-thread-9').open).toBe(true);
        expect(document.getElementById('post-thread-8').open).toBe(false);
    });

    it('opens nothing when there is no deep link', async () => {
        feedWithThreads();

        await loadGroups();

        expect(document.getElementById('post-thread-9').open).toBe(false);
        expect(document.getElementById('post-thread-8').open).toBe(false);
    });

    it('ignores a hash that is not a message anchor', async () => {
        feedWithThreads();
        window.location.hash = '#post-9x';

        await loadGroups();

        expect(document.getElementById('post-thread-9').open).toBe(false);
    });

    // "Commenter" can only mean one thing; "4 commentaires" means the
    // reader wants to read, and stealing focus there would scroll past
    // what they opened it for (and raise a phone keyboard over it).
    it('focuses the box when a conversation with no comments is opened', async () => {
        feedWithThreads();
        await loadGroups();

        const thread = document.getElementById('post-thread-9');
        thread.open = true;
        thread.dispatchEvent(new Event('toggle'));

        expect(document.activeElement.id).toBe('reply-input-9');
    });

    it('does not steal focus when a conversation that has comments is opened', async () => {
        feedWithThreads();
        await loadGroups();

        const thread = document.getElementById('post-thread-8');
        thread.open = true;
        thread.dispatchEvent(new Event('toggle'));

        expect(document.activeElement).toBe(document.body);
    });
});

// The comment box gets the same protection the message composer has had
// since the module shipped: a lost connection or a refused send must not
// cost the member what they typed. Its own describe because, like the
// composer's draft cache, it has to be exercised across a fresh page
// load — which here means re-importing the module.
describe('groups.js comment draft cache', () => {
    beforeEach(() => {
        localStorage.clear();
        document.body.innerHTML = `
            <form id="groups-post-form" data-draft-ttl-minutes="60" data-max-media="4">
                <textarea id="post-body" name="body"></textarea>
                <div id="groups-media-previews"></div>
                <input type="file" name="media[]" id="groups-media-hidden" class="d-none" multiple>
            </form>
            <div id="groups-feed">
                <article id="post-9">
                    <details class="groups-thread" id="post-thread-9">
                        <summary><span class="groups-thread-count" data-count="0">Commenter</span></summary>
                        <div class="groups-replies"></div>
                        <form class="groups-reply-form" action="/groups/1/posts/9/replies"
                              method="post" data-group-id="1" data-post-id="9">
                            <input type="text" name="body">
                            <button type="submit">Envoyer</button>
                        </form>
                    </details>
                </article>
            </div>
        `;
        Object.defineProperty(document.getElementById('groups-media-hidden'), 'files', {
            writable: true,
            configurable: true,
            value: [],
        });
        global.DataTransfer = FakeDataTransfer;
    });

    function replyInput() {
        return document.querySelector('.groups-reply-form input[name="body"]');
    }

    it('caches what is typed, per post, after the debounce', async () => {
        vi.useFakeTimers();
        try {
            await loadGroups();

            replyInput().value = 'Je serai là';
            replyInput().dispatchEvent(new Event('input', { bubbles: true }));
            await vi.advanceTimersByTimeAsync(499);
            expect(localStorage.getItem('groups-reply-draft-1-9')).toBeNull();

            await vi.advanceTimersByTimeAsync(1);
            expect(JSON.parse(localStorage.getItem('groups-reply-draft-1-9')).body).toBe('Je serai là');
        } finally {
            vi.useRealTimers();
        }
    });

    it('restores it on the next load, and opens the thread so it is actually seen', async () => {
        localStorage.setItem('groups-reply-draft-1-9', JSON.stringify({ body: 'Repris', savedAt: Date.now() }));

        await loadGroups();

        expect(replyInput().value).toBe('Repris');
        expect(document.getElementById('post-thread-9').open).toBe(true);
    });

    it('forgets a draft older than the composer TTL', async () => {
        localStorage.setItem(
            'groups-reply-draft-1-9',
            JSON.stringify({ body: 'Trop vieux', savedAt: Date.now() - 61 * 60000 })
        );

        await loadGroups();

        expect(replyInput().value).toBe('');
        expect(localStorage.getItem('groups-reply-draft-1-9')).toBeNull();
    });

    // The server hands a moderation-refused reply back in this very box
    // (partials/post_card.html.twig) — that one is more specific than a
    // merely locally-cached draft, exactly as in the message composer.
    it('never overwrites text the server already put in the box', async () => {
        localStorage.setItem('groups-reply-draft-1-9', JSON.stringify({ body: 'Brouillon local', savedAt: Date.now() }));
        replyInput().value = 'Texte refusé par le serveur';

        await loadGroups();

        expect(replyInput().value).toBe('Texte refusé par le serveur');
    });

    it('clears the draft once the comment actually sends', async () => {
        localStorage.setItem('groups-reply-draft-1-9', JSON.stringify({ body: 'Je serai là', savedAt: Date.now() }));
        await loadGroups();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ html: '<div class="groups-reply" id="reply-4">Je serai là</div>' })
        }));

        document.querySelector('.groups-reply-form button').click();
        await vi.waitFor(() => expect(document.getElementById('reply-4')).not.toBeNull());

        expect(localStorage.getItem('groups-reply-draft-1-9')).toBeNull();
    });

    it('keeps the draft when the send is refused, so the retry costs nothing', async () => {
        await loadGroups();
        global.fetch = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));

        replyInput().value = 'Hors ligne';
        replyInput().dispatchEvent(new Event('input', { bubbles: true }));
        document.querySelector('.groups-reply-form button').click();

        await vi.waitFor(() => expect(replyInput().value).toBe('Hors ligne'));
        await vi.waitFor(() => expect(localStorage.getItem('groups-reply-draft-1-9')).not.toBeNull());
    });

    // Two boxes on the same page must not share one key, or answering one
    // message would refill the box under another.
    it('keeps each message\'s draft to itself', async () => {
        localStorage.setItem('groups-reply-draft-1-9', JSON.stringify({ body: 'Pour le 9', savedAt: Date.now() }));
        document.getElementById('groups-feed').insertAdjacentHTML('beforeend', `
            <article id="post-10">
                <form class="groups-reply-form" action="/groups/1/posts/10/replies"
                      method="post" data-group-id="1" data-post-id="10">
                    <input type="text" name="body">
                </form>
            </article>
        `);

        await loadGroups();

        const inputs = document.querySelectorAll('.groups-reply-form input[name="body"]');
        expect(inputs[0].value).toBe('Pour le 9');
        expect(inputs[1].value).toBe('');
    });
});

// The poll's option boxes: always exactly one empty one waiting at the
// end, never more than the server's own maximum, and never fewer than the
// two a poll needs. Deterministic logic over a small DOM — the kind
// AGENTS.md § Tests asks for a Vitest spec on, and the kind an E2E run
// would only ever check one path of.
describe('groups.js poll option boxes', () => {
    beforeEach(() => {
        localStorage.clear();
        global.DataTransfer = FakeDataTransfer;
        global.URL.createObjectURL = vi.fn(() => 'blob:fake');
        document.body.innerHTML = `
            <form id="groups-post-form" data-max-media="2">
                <textarea id="post-body"></textarea>
                <div id="groups-media-previews"></div>
                <input type="file" name="media[]" id="groups-media-hidden" class="d-none" multiple>
                <span id="groups-media-count"></span>
                <p id="groups-media-limit-warning" class="d-none"></p>
                <input type="file" id="groups-media-input" multiple>
                <details id="groups-poll-details" data-max-options="4">
                    <input type="text" name="poll_question">
                    <div id="groups-poll-options">
                        <div class="groups-poll-option">
                            <input type="text" name="poll_options[]">
                            <button type="button" class="groups-poll-option-remove"></button>
                        </div>
                        <div class="groups-poll-option">
                            <input type="text" name="poll_options[]">
                            <button type="button" class="groups-poll-option-remove"></button>
                        </div>
                        <div class="groups-poll-option">
                            <input type="text" name="poll_options[]">
                            <button type="button" class="groups-poll-option-remove"></button>
                        </div>
                    </div>
                </details>
            </form>
        `;
        Object.defineProperty(document.getElementById('groups-media-hidden'), 'files', {
            writable: true,
            configurable: true,
            value: [],
        });
    });

    function boxes() {
        return Array.from(document.querySelectorAll('[name="poll_options[]"]'));
    }

    function type(index, value) {
        const input = boxes()[index];
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    it('adds an empty box as soon as the last one is typed into', async () => {
        await loadGroups();
        expect(boxes()).toHaveLength(3);

        type(2, 'Peut-être');

        expect(boxes()).toHaveLength(4);
        expect(boxes()[3].value).toBe('');
    });

    it('never grows past the maximum the server accepts', async () => {
        await loadGroups();

        type(0, 'A');
        type(1, 'B');
        type(2, 'C');
        type(3, 'D');

        // data-max-options is 4 in this fixture, so no fifth box appears
        // however much is typed into the fourth.
        expect(boxes()).toHaveLength(4);
    });

    it('leaves exactly one empty box when a filled one is cleared', async () => {
        await loadGroups();
        type(2, 'Peut-être');
        expect(boxes()).toHaveLength(4);

        type(2, '');

        expect(boxes()).toHaveLength(3);
        expect(boxes()[2].value).toBe('');
    });

    it('removes a row on demand but never below two answers plus the spare', async () => {
        await loadGroups();
        type(2, 'Peut-être');
        expect(boxes()).toHaveLength(4);

        // The filled row, not the spare: removing the empty one at the
        // end just brings it back, since the rule is "one waiting".
        document.querySelectorAll('.groups-poll-option-remove')[2].click();
        expect(boxes()).toHaveLength(3);
        expect(boxes().map((box) => box.value)).toEqual(['', '', '']);

        // At the floor, every remove button is disabled rather than
        // offering a click that would leave a poll with one answer.
        document.querySelectorAll('.groups-poll-option-remove').forEach((button) => {
            expect(button.disabled).toBe(true);
        });
    });
});
