// Isolated JavaScript unit test — jsdom only, fetch mocked throughout.
// Exercises the REAL public/assets/js/rental-booking.js (imported below,
// never reimplemented here).
//
// What is under test is the exchange the booking page relies on: a form is
// posted with fetch instead of navigating, the flash comes back as JSON and
// becomes a toast, and the page's `[data-booking-panel]` wrappers are then
// re-filled from a fresh render of the same page. The confirmation dialog's
// own behaviour is tests/js/confirm.test.js's; what matters here is that
// this file stands aside until confirm.js has replayed the form, since both
// listen for the same submit.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function boot() {
    vi.resetModules();
    // The real toolboxes and the real confirmation, in base.html.twig's own
    // load order: this file leans on all three at event time.
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/confirm.js');
    await import('../../public/assets/js/rental-booking.js');
}

/** The booking page, reduced to what this file touches. */
function pageHtml(milestone = 'À faire') {
    return `
        <div class="page-medium" data-rental-booking>
            <div data-booking-panel="milestones"><span id="milestone">${milestone}</span></div>
            <div data-booking-panel="documents">
                <form method="post" action="/mes-locations/document-envoyer" id="send-form">
                    <input type="hidden" name="document_id" value="4">
                    <button type="submit">Envoyer</button>
                </form>
                <form method="post" action="/mes-locations/document-supprimer" id="delete-form"
                      data-confirm="Supprimer ce document ?">
                    <input type="hidden" name="document_id" value="4">
                    <button type="submit">Supprimer</button>
                </form>
            </div>
        </div>`;
}

function jsonResponse(data, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });
}

function htmlResponse(html) {
    return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(html) });
}

/**
 * The two calls the page makes for one action, in order: the POST, then the
 * page re-read that feeds the panels.
 */
function actionThenRefresh(json, refreshedHtml) {
    let call = 0;
    return vi.fn(() => {
        call += 1;
        return call === 1 ? jsonResponse(json) : htmlResponse(refreshedHtml);
    });
}

function submit(id) {
    document.getElementById(id).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

/**
 * What a browser does when confirm.js replays a form: dispatch the submit
 * event again. jsdom's own requestSubmit() refuses the null submitter a
 * synthetic event carries (a real browser accepts it) and would never fire
 * the event anyway, so the replay is modelled here rather than mocked away
 * — the point of these two cases is that the replayed submit is the one
 * this file acts on.
 *
 * @param {string} id
 */
function allowReplay(id) {
    const form = document.getElementById(id);
    form.requestSubmit = () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
});

describe('rental-booking.js: posting a form without leaving the page', () => {
    it('does nothing at all on a page that is not a booking file', async () => {
        document.body.innerHTML = '<form id="other" action="/x"><button type="submit">Go</button></form>';
        global.fetch = vi.fn();
        await boot();
        submit('other');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts the form with fetch and the X-Requested-With header instead of navigating', async () => {
        document.body.innerHTML = pageHtml();
        global.fetch = actionThenRefresh({ success: true, type: 'success', message: 'Contrat envoyé.' }, pageHtml('Fait'));
        await boot();

        submit('send-form');

        // withDisabled() starts the request on a microtask, so the call is
        // never in flight in the same tick as the submit.
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        // `form.action` resolves to an absolute URL, exactly as a browser
        // would post it.
        expect(fetch).toHaveBeenCalledWith(
            expect.stringContaining('/mes-locations/document-envoyer'),
            expect.objectContaining({
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }),
        );
        expect(fetch.mock.calls[0][1].body).toBeInstanceOf(FormData);
        expect(fetch.mock.calls[0][1].body.get('document_id')).toBe('4');
    });

    it('shows the server flash as a toast', async () => {
        document.body.innerHTML = pageHtml();
        global.fetch = actionThenRefresh({ success: true, type: 'success', message: 'Contrat envoyé.' }, pageHtml('Fait'));
        await boot();

        submit('send-form');

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Contrat envoyé.');
    });

    /**
     * The whole point of the refresh: the checklist is rendered server-side
     * from the booking's records, so the panel that carries it has to come
     * back from the server rather than be patched here.
     */
    it('re-fills every panel from a fresh render of the same page', async () => {
        document.body.innerHTML = pageHtml('À faire');
        global.fetch = actionThenRefresh({ success: true, message: 'Contrat envoyé.' }, pageHtml('Fait'));
        await boot();

        submit('send-form');

        await vi.waitFor(() => expect(document.getElementById('milestone').textContent).toBe('Fait'));
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('reports a refused action as an error and still refreshes nothing away', async () => {
        document.body.innerHTML = pageHtml('À faire');
        global.fetch = actionThenRefresh(
            { success: false, type: 'error', message: 'Cette demande n\'existe pas.' },
            pageHtml('À faire'),
        );
        await boot();

        submit('send-form');

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Cette demande n\'existe pas.');
        expect(document.getElementById('milestone').textContent).toBe('À faire');
    });

    it('says so when the answer is not JSON at all, and leaves the panels alone', async () => {
        document.body.innerHTML = pageHtml('À faire');
        global.fetch = vi.fn(() => Promise.resolve({
            ok: false, status: 500, json: () => Promise.reject(new Error('not json')),
        }));
        await boot();

        submit('send-form');

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toContain('Rechargez la page');
        expect(document.getElementById('milestone').textContent).toBe('À faire');
    });

    it('keeps a panel as it was when the refresh itself fails', async () => {
        document.body.innerHTML = pageHtml('À faire');
        let call = 0;
        global.fetch = vi.fn(() => {
            call += 1;
            return call === 1
                ? jsonResponse({ success: true, message: 'Fait.' })
                : Promise.reject(new Error('offline'));
        });
        await boot();

        submit('send-form');

        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        expect(document.getElementById('milestone').textContent).toBe('À faire');
    });
});

describe('rental-booking.js: the confirmation still comes first', () => {
    it('does not post a form carrying an unanswered data-confirm', async () => {
        document.body.innerHTML = pageHtml();
        global.fetch = vi.fn();
        await boot();

        submit('delete-form');

        // confirm.js has opened its dialog; nothing has been sent.
        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('.modal')).not.toBeNull();
    });

    it('posts it — once — after the visitor agrees', async () => {
        document.body.innerHTML = pageHtml();
        global.fetch = actionThenRefresh({ success: true, message: 'Document supprimé.' }, pageHtml());
        await boot();
        allowReplay('delete-form');

        submit('delete-form');
        await vi.waitFor(() => expect(document.querySelector('.modal .btn-danger')).not.toBeNull());
        document.querySelector('.modal .btn-danger').dispatchEvent(new Event('click', { bubbles: true }));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(fetch.mock.calls[0][0]).toContain('/mes-locations/document-supprimer');
        expect(fetch.mock.calls[0][1].method).toBe('POST');
    });

    /**
     * The marker confirm.js sets to replay a form is cleared once the
     * request is over — the page does not reload any more, so a second
     * press of the same button has to ask the question again.
     */
    it('asks again the next time the same button is pressed', async () => {
        document.body.innerHTML = pageHtml();
        global.fetch = actionThenRefresh({ success: true, message: 'Document supprimé.' }, pageHtml());
        await boot();
        allowReplay('delete-form');

        submit('delete-form');
        await vi.waitFor(() => expect(document.querySelector('.modal .btn-danger')).not.toBeNull());
        document.querySelector('.modal .btn-danger').dispatchEvent(new Event('click', { bubbles: true }));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

        // The panel was re-rendered from HTML that has the form again.
        await vi.waitFor(() => expect(document.getElementById('delete-form').dataset.confirmed).toBeUndefined());
    });
});
