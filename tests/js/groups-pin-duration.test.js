// Isolated JavaScript unit test — jsdom only, exercising the REAL
// public/assets/js/groups.js (imported below, never reimplemented).
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadGroups() {
    vi.resetModules();
    await import('../../public/assets/js/groups.js');
}

/**
 * A Bootstrap modal stand-in whose hide() does NOTHING and fires no
 * 'hidden.bs.modal' — which is not a caricature but exactly what Bootstrap
 * 5 does when hide() is called while the modal is still transitioning in:
 * `if (!this._isShown || this._isTransitioning) { return; }`.
 *
 * A click can land in that window. Playwright waits for an element to be
 * stable in POSITION, and a fading modal is already at its final position
 * while its opacity is still animating; a quick human on a loaded server
 * hits the same gap.
 */
function stubBootstrapWithADeadHide() {
    globalThis.bootstrap = {
        Modal: {
            getOrCreateInstance: () => ({
                show: () => {},
                hide: () => {},
            }),
        },
    };
}

function buildPinDom() {
    document.body.innerHTML = `
        <div id="groups-feed"></div>
        <form class="groups-pin-form" action="/groups/1/posts/2/pin" method="post">
            <input type="hidden" name="duration" value="">
            <button type="submit">Épingler</button>
        </form>
        <div id="groups-detail-modal">
            <h2 id="groups-detail-modal-title"></h2>
            <div id="groups-detail-modal-body"></div>
        </div>
    `;

    const form = /** @type {HTMLFormElement} */ (document.querySelector('.groups-pin-form'));
    // jsdom does not implement form.submit(); it is also precisely the
    // effect under test, so it is the thing to observe.
    form.submit = vi.fn();

    return form;
}

describe('pinning a message: the duration dialog', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        delete globalThis.bootstrap;
        document.body.innerHTML = '';
    });

    /**
     * The regression. The dialog used to resolve its promise from
     * 'hidden.bs.modal', so the form was submitted only once the fade-out
     * had finished — and when hide() was a no-op because the modal was
     * still coming in, the promise never settled at all. The message was
     * simply never pinned, with no error and no feedback anywhere.
     */
    it('submits the form on the choice, even when the modal never reports being hidden', async () => {
        stubBootstrapWithADeadHide();
        const form = buildPinDom();
        await loadGroups();

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await Promise.resolve();

        const weekButton = [...document.querySelectorAll('#groups-detail-modal-body [data-duration]')]
            .find((b) => b.dataset.duration === 'week');
        expect(weekButton, 'the dialog must offer a one-week choice').toBeTruthy();

        weekButton.click();
        await Promise.resolve();
        await Promise.resolve();

        expect(form.submit).toHaveBeenCalledTimes(1);
        expect(form.querySelector('input[name="duration"]').value).toBe('week');
    });

    /**
     * The counterpart: closing the dialog without choosing must submit
     * nothing. That path still hangs off 'hidden.bs.modal', which is the
     * right signal for it — an event that never arrives simply leaves the
     * message unpinned, which is what dismissing means.
     */
    it('submits nothing when the dialog is dismissed without a choice', async () => {
        stubBootstrapWithADeadHide();
        const form = buildPinDom();
        await loadGroups();

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await Promise.resolve();

        document.getElementById('groups-detail-modal')
            .dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));
        await Promise.resolve();
        await Promise.resolve();

        expect(form.submit).not.toHaveBeenCalled();
    });

    /**
     * And a late 'hidden.bs.modal' arriving after a choice must not
     * resolve the promise a second time — the form is submitted once.
     */
    it('does not submit twice when the modal reports being hidden after a choice', async () => {
        stubBootstrapWithADeadHide();
        const form = buildPinDom();
        await loadGroups();

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await Promise.resolve();

        document.querySelector('#groups-detail-modal-body [data-duration="week"]').click();
        document.getElementById('groups-detail-modal')
            .dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));
        await Promise.resolve();
        await Promise.resolve();

        expect(form.submit).toHaveBeenCalledTimes(1);
    });
});
