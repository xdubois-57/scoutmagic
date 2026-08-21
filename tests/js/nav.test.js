// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises the
// REAL implementation in public/assets/js/nav.js (imported below, never
// reimplemented here), specifically the site-wide aria-checked sync for
// Bootstrap's role="switch" pattern (SonarCloud Web:S6807 — a switch's
// accessible state must track its actual .checked, which Bootstrap's CSS
// class alone never does).
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('nav.js — role="switch" aria-checked sync', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = '';
        delete window.ScoutMagicNav;
    });

    it('exposes syncSwitchAriaChecked() on window.ScoutMagicNav', async () => {
        await import('../../public/assets/js/nav.js');
        expect(typeof window.ScoutMagicNav.syncSwitchAriaChecked).toBe('function');
    });

    it('syncSwitchAriaChecked() sets aria-checked to match the current .checked state', async () => {
        await import('../../public/assets/js/nav.js');
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.setAttribute('role', 'switch');
        input.setAttribute('aria-checked', 'false');

        input.checked = true;
        window.ScoutMagicNav.syncSwitchAriaChecked(input);
        expect(input.getAttribute('aria-checked')).toBe('true');

        input.checked = false;
        window.ScoutMagicNav.syncSwitchAriaChecked(input);
        expect(input.getAttribute('aria-checked')).toBe('false');
    });

    it('updates aria-checked automatically on a real user "change" event, delegated site-wide', async () => {
        document.body.innerHTML = '<input type="checkbox" role="switch" id="s1" aria-checked="false">';
        await import('../../public/assets/js/nav.js');

        const input = document.getElementById('s1');
        input.checked = true;
        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(input.getAttribute('aria-checked')).toBe('true');
    });

    it('ignores "change" events from checkboxes without role="switch"', async () => {
        document.body.innerHTML = '<input type="checkbox" id="plain-checkbox">';
        await import('../../public/assets/js/nav.js');

        const input = document.getElementById('plain-checkbox');
        input.checked = true;
        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(input.hasAttribute('aria-checked')).toBe(false);
    });

    it('ignores "change" events from non-checkbox inputs even with role="switch"', async () => {
        document.body.innerHTML = '<select role="switch" id="not-a-checkbox"><option>a</option></select>';
        await import('../../public/assets/js/nav.js');

        const select = document.getElementById('not-a-checkbox');
        select.dispatchEvent(new Event('change', { bubbles: true }));

        expect(select.hasAttribute('aria-checked')).toBe(false);
    });

    it('picks up a switch added to the DOM after nav.js has already loaded (delegated, not a one-time querySelectorAll)', async () => {
        await import('../../public/assets/js/nav.js');

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.setAttribute('role', 'switch');
        input.setAttribute('aria-checked', 'false');
        document.body.appendChild(input);

        input.checked = true;
        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(input.getAttribute('aria-checked')).toBe('true');
    });
});
