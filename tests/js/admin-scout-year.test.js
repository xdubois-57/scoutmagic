// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/admin-scout-year.js
// (imported below, never reimplemented here): the Année scoute page sends
// every action as JSON and swaps in the state the server rendered.
//
// The fixture mirrors admin/scout_year/_state.html.twig: a human step's
// disc (a form whose `done` is explicit), a step button that acts at once
// and carries its question (data-ask-*), and the review's confirmation,
// which carries none. ScoutMagicApi, ScoutMagicConfirm and ScoutMagicToast
// are the site's globals, replaced by spies.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const FAILED = "L'enregistrement a échoué. Rien n'a changé ; réessayez.";

function page() {
    document.body.innerHTML = `
        <div id="year-override-banner"></div>
        <div id="scout-year-state">
            <p id="marker">avant</p>
            <form method="post" action="/admin/scout-year/step" class="js-step-mark" id="mark-badges">
                <input type="hidden" name="_csrf_token" value="t">
                <input type="hidden" name="step_key" value="badges">
                <input type="hidden" name="done" value="1">
                <button type="submit" id="disc-badges" data-focus-key="mark:badges">6</button>
            </form>
            <form method="post" action="/admin/scout-year/step" class="js-step-mark" id="mark-fees">
                <input type="hidden" name="_csrf_token" value="t">
                <input type="hidden" name="step_key" value="fees">
                <input type="hidden" name="done" value="0">
                <button type="submit" id="disc-fees" data-focus-key="mark:fees">✓</button>
            </form>
            <form method="post" action="/admin/scout-year/preview" class="d-inline js-step-action" id="preview"
                  data-ask-title="Prévisualiser 2027-2028 ?"
                  data-ask-message="Le site vous montrera 2027-2028. Aucun e-mail n'est envoyé."
                  data-ask-label="Prévisualiser 2027-2028">
                <input type="hidden" name="_csrf_token" value="t">
                <input type="hidden" name="scout_year_id" value="12">
                <button type="submit" id="preview-button" data-focus-key="page:preview">Prévisualiser 2027-2028</button>
            </form>
            <form method="post" action="/admin/scout-year/activate-public" class="js-step-action" id="switch">
                <input type="hidden" name="_csrf_token" value="t">
                <input type="hidden" name="scout_year_id" value="12">
                <button type="submit" id="switch-button">Basculer sur 2027-2028</button>
            </form>
            <form method="post" action="/elsewhere" id="other"><button type="submit" id="other-button">Autre</button></form>
        </div>`;
}

const RENDERED = '<p id="marker">après</p><button id="disc-again" data-focus-key="mark:badges">✓</button>';

let postJson;
let ask;
let show;

beforeEach(() => {
    vi.resetModules();
    page();
    postJson = vi.fn();
    ask = vi.fn().mockResolvedValue(true);
    show = vi.fn();
    window.ScoutMagicApi = { postJson };
    window.ScoutMagicConfirm = { ask };
    window.ScoutMagicToast = { show };
    document.documentElement.classList.remove('scout-year-js');
});

afterEach(() => {
    delete window.ScoutMagicApi;
    delete window.ScoutMagicConfirm;
    delete window.ScoutMagicToast;
});

const boot = () => import('../../public/assets/js/admin-scout-year.js');
const $ = (id) => document.getElementById(id);
const click = (id) => $(id).click();
/** Lets every pending promise of the script settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/** A postJson() answer the test resolves when it wants. */
function deferred() {
    let resolve;
    const promise = new Promise((r) => { resolve = r; });
    postJson.mockReturnValue(promise);
    return (value) => resolve(value);
}

describe('admin-scout-year.js', () => {
    it('does nothing at all on another page', async () => {
        document.body.innerHTML = '<form class="js-step-mark"><button type="submit" id="b">x</button></form>';
        await boot();

        expect(document.documentElement.classList.contains('scout-year-js')).toBe(false);
        expect(postJson).not.toHaveBeenCalled();
    });

    it('says once its listeners are attached', async () => {
        await boot();

        expect(document.documentElement.classList.contains('scout-year-js')).toBe(true);
    });

    describe('the disc of a human step', () => {
        it('posts the explicit state as a JSON boolean, asks nothing, and swaps in the server state', async () => {
            postJson.mockResolvedValue({
                ok: true,
                status: 200,
                data: { success: true, message: 'Étape marquée comme faite.', html: { state: RENDERED, banner: '' } },
            });
            await boot();

            click('disc-badges');
            await settle();

            expect(ask).not.toHaveBeenCalled();
            expect(postJson).toHaveBeenCalledTimes(1);
            expect(postJson).toHaveBeenCalledWith('/admin/scout-year/step', { step_key: 'badges', done: true });
            expect($('marker').textContent).toBe('après');
            expect(show).toHaveBeenCalledWith('Étape marquée comme faite.', { variant: 'success' });
            // Focus comes back to the same control, by its key.
            expect(document.activeElement).toBe($('disc-again'));
        });

        it('sends done: false from the green disc — never a « toggle »', async () => {
            postJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, message: 'Étape remise à faire.', html: { state: RENDERED } } });
            await boot();

            click('disc-fees');
            await settle();

            expect(postJson).toHaveBeenCalledWith('/admin/scout-year/step', { step_key: 'fees', done: false });
        });

        it('marks the disc busy and disabled while the call runs, and gives it back after a failure', async () => {
            const answer = deferred();
            await boot();

            click('disc-badges');
            await settle();

            expect($('disc-badges').disabled).toBe(true);
            expect($('disc-badges').getAttribute('aria-busy')).toBe('true');
            expect($('disc-badges').classList.contains('is-busy')).toBe(true);
            expect($('scout-year-state').getAttribute('aria-busy')).toBe('true');

            answer({ ok: false, status: 0, data: null });
            await settle();

            expect($('disc-badges').disabled).toBe(false);
            expect($('disc-badges').hasAttribute('aria-busy')).toBe(false);
            expect($('disc-badges').classList.contains('is-busy')).toBe(false);
            expect($('scout-year-state').getAttribute('aria-busy')).toBe('false');
        });

        it('ignores a double click: one request while one is running', async () => {
            const answer = deferred();
            await boot();

            click('disc-badges');
            // The disc itself is disabled now; a second disc, or a
            // synthetic submit, must not slip through either.
            $('mark-badges').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            click('disc-fees');
            await settle();
            expect(postJson).toHaveBeenCalledTimes(1);

            answer({ ok: false, status: 0, data: null });
            await settle();

            // Once it is over, the page takes the next one.
            postJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, message: 'ok', html: { state: RENDERED } } });
            click('disc-fees');
            await settle();
            expect(postJson).toHaveBeenCalledTimes(2);
        });

        it('leaves the page as it was on a network error, and says nothing changed', async () => {
            postJson.mockResolvedValue({ ok: false, status: 0, data: null });
            await boot();

            click('disc-badges');
            await settle();

            expect($('marker').textContent).toBe('avant');
            expect(show).toHaveBeenCalledWith(FAILED, { variant: 'error' });
        });

        it('leaves the page as it was on a server error whose body is not JSON', async () => {
            postJson.mockResolvedValue({ ok: false, status: 500, data: null });
            await boot();

            click('disc-badges');
            await settle();

            expect($('marker').textContent).toBe('avant');
            expect(show).toHaveBeenCalledWith(FAILED, { variant: 'error' });
        });

        it('shows the server\'s own reason for a refusal, with the state it sent back', async () => {
            postJson.mockResolvedValue({
                ok: false,
                status: 422,
                data: { success: false, error: 'La campagne de réinscription est en cours.', html: { state: RENDERED } },
            });
            await boot();

            click('disc-badges');
            await settle();

            expect($('marker').textContent).toBe('après');
            expect(show).toHaveBeenCalledWith('La campagne de réinscription est en cours.', { variant: 'error' });
        });

        it('says « session expirée » as the server words it, and replaces nothing', async () => {
            postJson.mockResolvedValue({
                ok: false,
                status: 403,
                data: { success: false, error: 'Votre session a expiré. Rechargez la page et réessayez.' },
            });
            await boot();

            click('disc-badges');
            await settle();

            expect($('marker').textContent).toBe('avant');
            expect(show).toHaveBeenCalledWith('Votre session a expiré. Rechargez la page et réessayez.', { variant: 'error' });
        });

        it('reports a script failure as a failed save rather than an unhandled rejection', async () => {
            postJson.mockRejectedValue(new Error('boom'));
            await boot();

            click('disc-badges');
            await settle();

            expect(show).toHaveBeenCalledWith(FAILED, { variant: 'error' });
            expect($('disc-badges').disabled).toBe(false);
        });
    });

    describe('a step button that acts at once', () => {
        it('asks first, with the wording the step wrote, naming the action on the button', async () => {
            postJson.mockResolvedValue({
                ok: true,
                status: 200,
                data: {
                    success: true,
                    message: 'Prévisualisation activée pour cette session.',
                    html: { state: RENDERED, banner: '<div role="alert">Vous visualisez</div>' },
                },
            });
            await boot();

            click('preview-button');
            await settle();

            expect(ask).toHaveBeenCalledWith({
                title: 'Prévisualiser 2027-2028 ?',
                message: "Le site vous montrera 2027-2028. Aucun e-mail n'est envoyé.",
                confirmLabel: 'Prévisualiser 2027-2028',
                variant: 'primary',
            });
            // The fields of the form, never its CSRF field (postJson adds the page's own).
            expect(postJson).toHaveBeenCalledWith('/admin/scout-year/preview', { scout_year_id: '12' });
            expect($('year-override-banner').textContent).toBe('Vous visualisez');
        });

        it('sends nothing when the question is declined, and takes the next action', async () => {
            ask.mockResolvedValue(false);
            await boot();

            click('preview-button');
            await settle();

            expect(postJson).not.toHaveBeenCalled();
            expect($('preview-button').disabled).toBe(false);

            ask.mockResolvedValue(true);
            postJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, message: 'ok', html: { state: RENDERED } } });
            click('preview-button');
            await settle();
            expect(postJson).toHaveBeenCalledTimes(1);
        });

        it('asks only once however often it is clicked while the question is open', async () => {
            let answer;
            ask.mockReturnValue(new Promise((r) => { answer = r; }));
            await boot();

            click('preview-button');
            click('preview-button');
            await settle();
            expect(ask).toHaveBeenCalledTimes(1);

            postJson.mockResolvedValue({ ok: true, status: 200, data: { success: true, message: 'ok', html: { state: RENDERED } } });
            answer(true);
            await settle();
            expect(postJson).toHaveBeenCalledTimes(1);
        });

        it('lets the review\'s confirmation through without a second question', async () => {
            postJson.mockResolvedValue({
                ok: true,
                status: 200,
                data: { success: true, message: 'Année 2027-2028 activée pour tout le monde.', html: { state: RENDERED } },
            });
            await boot();

            click('switch-button');
            await settle();

            expect(ask).not.toHaveBeenCalled();
            expect(postJson).toHaveBeenCalledWith('/admin/scout-year/activate-public', { scout_year_id: '12' });
            expect(show).toHaveBeenCalledWith('Année 2027-2028 activée pour tout le monde.', { variant: 'success' });
        });
    });

    it('leaves any other form on the page alone', async () => {
        await boot();
        const submit = new Event('submit', { bubbles: true, cancelable: true });

        $('other').dispatchEvent(submit);

        expect(submit.defaultPrevented).toBe(false);
        expect(postJson).not.toHaveBeenCalled();
    });
});
