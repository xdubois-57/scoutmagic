// Isolated JavaScript unit test — jsdom-simulated DOM only. jsdom does
// not implement form submission, so HTMLFormElement.prototype.submit is
// a spy: what this suite owns is WHICH form gets submitted and when.
// Exercises the REAL implementation in
// public/assets/js/admin-scout-year.js (imported below, never
// reimplemented here).
//
// The fixture mirrors core/View/templates/admin/scout_year.html.twig:
// two workflow steps, each a form whose checkbox carries no name — the
// value posted is the hidden « done » field, already set server-side to
// the opposite of the rendered state.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function step(n, done) {
    return `
        <form method="post" action="/admin/scout-year/step/${n}" class="js-step-form" id="step-${n}">
            <input type="hidden" name="done" value="${done ? '0' : '1'}">
            <input type="checkbox" class="js-step-checkbox" id="cb-${n}" ${done ? 'checked' : ''}>
        </form>`;
}

describe('admin-scout-year.js', () => {
    let submit;

    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = step(1, false) + step(2, true);
        submit = vi.fn();
        HTMLFormElement.prototype.submit = submit;
    });

    const boot = () => import('../../public/assets/js/admin-scout-year.js');
    const tick = (n) => document.getElementById('cb-' + n).dispatchEvent(new Event('change'));

    it('does nothing at all on a page with no workflow steps', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        await expect(boot()).resolves.toBeDefined();
        expect(submit).not.toHaveBeenCalled();
    });

    it('submits the step\'s OWN form when its checkbox is ticked', async () => {
        await boot();
        tick(1);

        expect(submit).toHaveBeenCalledTimes(1);
        expect(submit.mock.instances[0].id).toBe('step-1');
    });

    it('submits on un-ticking too — the hidden field carries the direction', async () => {
        await boot();
        tick(2);

        expect(submit).toHaveBeenCalledTimes(1);
        expect(submit.mock.instances[0].id).toBe('step-2');
        expect(document.querySelector('#step-2 input[name="done"]').value).toBe('0');
    });

    it('submits nothing when the checkbox is not inside a step form', async () => {
        document.body.innerHTML = '<input type="checkbox" class="js-step-checkbox" id="cb-9">';
        await boot();
        document.getElementById('cb-9').dispatchEvent(new Event('change'));

        expect(submit).not.toHaveBeenCalled();
    });

    it('leaves each step to its own form — ticking one submits one', async () => {
        await boot();
        tick(1);
        tick(2);

        expect(submit.mock.instances.map((f) => f.id)).toEqual(['step-1', 'step-2']);
    });
});
