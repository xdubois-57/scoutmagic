// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network: window.ScoutMagicApi is stubbed, which is
// also how the real thing is reached, so nothing here reimplements the
// script under test (public/assets/js/help-assistant.js).
//
// What deserves a test here is everything that is NOT the model's answer:
// the staged exchange (« Je cherche dans l'aide… », then the topics, then
// the answer — the reason there is no mute spinner), the fact that the
// answer is the only thing ever written as HTML, and each refusal the
// endpoint can return coming back out as its own French sentence rather
// than a generic failure.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * @param {object} data the endpoint's JSON envelope
 * @param {boolean} ok whether the HTTP status was a success
 */
function stubApi(data, ok = true) {
    const calls = [];
    window.ScoutMagicApi = {
        postJson: (url, body) => {
            calls.push({ url, body });
            return Promise.resolve({ ok, status: ok ? 200 : 500, data });
        },
    };

    return calls;
}

/** The full page: the assistant's own field, because there is no other. */
function markup() {
    document.body.innerHTML = `
        <div data-help-assistant>
            <div class="d-none" data-help-assistant-history>Vos échanges avec l'assistant</div>
            <div data-help-assistant-thread></div>
            <p class="d-none" data-help-assistant-offline>hors connexion</p>
            <form data-help-assistant-form>
                <input data-help-assistant-input value="">
                <button type="button" class="d-none" data-help-assistant-clear>×</button>
                <button type="submit" data-help-assistant-submit></button>
            </form>
        </div>`;
}

/** The help panel: no field at all — the search box above is the field. */
function panelMarkup() {
    document.body.innerHTML = `
        <div data-help-assistant>
            <div class="d-none" data-help-assistant-history>Vos échanges avec l'assistant</div>
            <div data-help-assistant-thread></div>
            <p class="d-none" data-help-assistant-offline>hors connexion</p>
        </div>`;
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/help-assistant.js');
}

/** Types a question and submits, resolving once the exchange settled. */
async function ask(question) {
    document.querySelector('[data-help-assistant-input]').value = question;
    document.querySelector('[data-help-assistant-form]')
        .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    // One awaited fetch, so one microtask flush is enough — but a second
    // costs nothing and keeps this from depending on that count.
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

function thread() {
    return document.querySelector('[data-help-assistant-thread]');
}

beforeEach(() => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
    delete window.ScoutMagicApi;
});

describe('the exchange', () => {
    it('shows the question, then the topics, then the answer', async () => {
        markup();
        stubApi({
            success: true,
            found_nothing: false,
            answer_html: '<p>Ouvrez <strong>Nouveau message</strong>.</p>',
            topics: [{ id: 'envoi-de-mails', title: 'Envoyer un e-mail groupé' }],
        });
        await boot();

        await ask('Comment écrire aux parents ?');

        expect(thread().textContent).toContain('Comment écrire aux parents ?');
        expect(thread().textContent).toContain('Sujets consultés');
        expect(thread().querySelector('a[href="/aide/envoi-de-mails"]').textContent)
            .toBe('Envoyer un e-mail groupé');
        expect(thread().querySelector('strong').textContent).toBe('Nouveau message');
    });

    it('says it is looking before the answer arrives, rather than nothing at all', async () => {
        markup();
        let release;
        window.ScoutMagicApi = {
            postJson: () => new Promise((resolve) => { release = resolve; }),
        };
        await boot();

        document.querySelector('[data-help-assistant-input]').value = 'Comment écrire aux parents ?';
        document.querySelector('[data-help-assistant-form]')
            .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        expect(thread().textContent).toContain("Je cherche dans l'aide");
        // And the field is already clear, so the next question can be typed.
        expect(document.querySelector('[data-help-assistant-input]').value).toBe('');

        release({ ok: true, status: 200, data: { success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] } });
    });

    it('sends the page it was asked from, so « comment je fais ça ? » has an anchor', async () => {
        markup();
        const calls = stubApi({ success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] });
        await boot();

        await ask('Comment je fais ça ?');

        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/api/aide/assistant');
        expect(calls[0].body.question).toBe('Comment je fais ça ?');
        expect(calls[0].body.path).toBe(window.location.pathname);
    });

    it('does nothing at all on an empty question', async () => {
        markup();
        const calls = stubApi({ success: true, found_nothing: false, answer_html: '', topics: [] });
        await boot();

        await ask('   ');

        expect(calls).toHaveLength(0);
        expect(thread().children).toHaveLength(0);
    });

    it('writes a topic title as text, never as markup', async () => {
        markup();
        stubApi({
            success: true,
            found_nothing: false,
            answer_html: '<p>Voilà.</p>',
            // A title cannot contain this — but the id and the title come
            // back over HTTP, and this file treats everything but
            // answer_html as text on principle.
            topics: [{ id: 'envoi-de-mails', title: '<img src=x onerror=alert(1)>' }],
        });
        await boot();

        await ask('Comment écrire aux parents ?');

        expect(thread().querySelector('img')).toBeNull();
        expect(thread().textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('refuses to build a link out of an id it does not recognise', async () => {
        markup();
        stubApi({
            success: true,
            found_nothing: false,
            answer_html: '<p>Voilà.</p>',
            topics: [{ id: 'javascript:alert(1)', title: 'Sujet' }],
        });
        await boot();

        await ask('Comment écrire aux parents ?');

        // Falls back to the help index rather than concatenating whatever
        // arrived into an href.
        expect(thread().querySelector('a').getAttribute('href')).toBe('/aide');
    });
});

describe('what comes back when there is no answer', () => {
    it('says the corpus does not cover it, and what to do instead', async () => {
        markup();
        stubApi({ success: true, found_nothing: true, answer_html: '', topics: [] });
        await boot();

        await ask('Quel temps fera-t-il au camp ?');

        expect(thread().textContent).toContain("Je n'ai rien trouvé sur ce point");
        expect(thread().textContent).toContain('page Aide');
    });

    it("shows the server's own sentence — the quota, the connector, the provider", async () => {
        markup();
        stubApi({ success: false, error: 'Vous avez posé beaucoup de questions. Réessayez dans une heure.' }, false);
        await boot();

        await ask('Comment écrire aux parents ?');

        expect(thread().textContent).toContain('Réessayez dans une heure');
    });

    it('still says something when the request never reached a server', async () => {
        markup();
        window.ScoutMagicApi = { postJson: () => Promise.resolve({ ok: false, status: 0, data: null }) };
        await boot();

        await ask('Comment écrire aux parents ?');

        expect(thread().textContent).toContain("L'assistant n'a pas pu répondre");
    });
});

describe('the question handed over by the local search', () => {
    // Pressing « Demander à l'assistant » IS the request. Prefilling a
    // field and waiting for a second press was the friction this replaced:
    // it also meant two boxes on the panel, and no reader could say which
    // one searched and which one asked.
    it('is sent on arrival from /aide, and consumed from sessionStorage', async () => {
        window.sessionStorage.setItem('scoutmagic:help-assistant:question', 'changer mon adresse');
        markup();
        const calls = stubApi({ success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] });
        await boot();
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(calls.map((c) => c.body.question)).toEqual(['changer mon adresse']);
        expect(window.sessionStorage.getItem('scoutmagic:help-assistant:question')).toBeNull();
        expect(thread().textContent).toContain('changer mon adresse');
    });

    it('is sent on the panel event too, with one behaviour for both surfaces', async () => {
        panelMarkup();
        const calls = stubApi({ success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] });
        await boot();

        document.querySelector('[data-help-assistant]').dispatchEvent(
            new CustomEvent('scoutmagic:help-assistant-ask', { detail: { question: 'changer mon adresse' } })
        );
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(calls.map((c) => c.body.question)).toEqual(['changer mon adresse']);
        expect(thread().querySelector('strong, .help-content')).not.toBeNull();
    });

    it('tells whoever asked that the exchange is over', async () => {
        panelMarkup();
        stubApi({ success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] });
        await boot();

        let idle = 0;
        document.querySelector('[data-help-assistant]')
            .addEventListener('scoutmagic:help-assistant-idle', () => { idle += 1; });

        document.querySelector('[data-help-assistant]').dispatchEvent(
            new CustomEvent('scoutmagic:help-assistant-ask', { detail: { question: 'une question' } })
        );
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        // help-search.js re-enables « Demander à l'assistant » on this.
        expect(idle).toBe(1);
    });
});

describe('showing that something is happening', () => {
    it('turns a spinner while waiting, and drops it with the answer', async () => {
        markup();
        let release;
        window.ScoutMagicApi = { postJson: () => new Promise((resolve) => { release = resolve; }) };
        await boot();

        document.querySelector('[data-help-assistant-input]').value = 'Comment écrire aux parents ?';
        document.querySelector('[data-help-assistant-form]')
            .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        // Two server-side calls happen and the second is the slow one; a
        // still line of text for three seconds reads as a stuck page.
        expect(thread().querySelector('.spinner-border')).not.toBeNull();

        release({ ok: true, status: 200, data: { success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] } });
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(thread().querySelector('.spinner-border')).toBeNull();
    });

    it('drops the spinner on a refusal too', async () => {
        markup();
        stubApi({ success: false, error: 'Réessayez dans une heure.' }, false);
        await boot();

        await ask('Comment écrire aux parents ?');

        expect(thread().querySelector('.spinner-border')).toBeNull();
        expect(thread().textContent).toContain('Réessayez dans une heure');
    });

    it('names the conversation once there is one', async () => {
        markup();
        stubApi({ success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] });
        await boot();

        // An unlabelled stack of question-and-answer blocks reads as
        // something the page decided to show; it is a conversation.
        expect(document.querySelector('[data-help-assistant-history]').classList.contains('d-none')).toBe(true);

        await ask('Comment écrire aux parents ?');

        expect(document.querySelector('[data-help-assistant-history]').classList.contains('d-none')).toBe(false);
    });

    it('empties a long question in one click', async () => {
        markup();
        stubApi({ success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] });
        await boot();

        const input = document.querySelector('[data-help-assistant-input]');
        const clear = document.querySelector('[data-help-assistant-clear]');
        expect(clear.classList.contains('d-none')).toBe(true);

        input.value = 'je dois prévenir les parents que la réunion est annulée';
        input.dispatchEvent(new Event('input'));
        expect(clear.classList.contains('d-none')).toBe(false);

        clear.dispatchEvent(new Event('click', { bubbles: true }));
        expect(input.value).toBe('');
        expect(clear.classList.contains('d-none')).toBe(true);
    });

    it('refuses a second question while the first is in flight', async () => {
        markup();
        let release;
        const calls = [];
        window.ScoutMagicApi = {
            postJson: (url, body) => {
                calls.push(body);
                return new Promise((resolve) => { release = resolve; });
            },
        };
        await boot();

        await ask('première question');
        await ask('deuxième question');

        expect(calls.map((c) => c.question)).toEqual(['première question']);

        release({ ok: true, status: 200, data: { success: true, found_nothing: false, answer_html: '<p>Voilà.</p>', topics: [] } });
    });
});

describe('offline', () => {
    it('says so and disables sending, since the answer comes from the internet', async () => {
        markup();
        const online = Object.getOwnPropertyDescriptor(Navigator.prototype, 'onLine');
        Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false });
        await boot();

        expect(document.querySelector('[data-help-assistant-offline]').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-help-assistant-submit]').disabled).toBe(true);

        Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => true });
        window.dispatchEvent(new Event('online'));

        expect(document.querySelector('[data-help-assistant-offline]').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('[data-help-assistant-submit]').disabled).toBe(false);

        if (online) {
            Object.defineProperty(Navigator.prototype, 'onLine', online);
        }
    });
});
