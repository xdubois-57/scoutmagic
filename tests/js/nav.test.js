// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises the
// REAL implementation in public/assets/js/nav.js (imported below, never
// reimplemented here): the desktop mega-menu panel's open/close
// behaviour, and the site-wide aria-checked sync for Bootstrap's
// role="switch" pattern (SonarCloud Web:S6807 — a switch's accessible
// state must track its actual .checked, which Bootstrap's CSS class alone
// never does).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const NAV = `
    <nav id="desktopNav">
        <div>
            <button class="desktop-menu-btn" data-menu-id="espace_chefs" aria-expanded="false" aria-controls="megamenu-espace_chefs">Espace animateurs</button>
            <button class="desktop-menu-btn active" data-menu-id="configuration" aria-expanded="false" aria-controls="megamenu-configuration">Configuration</button>
        </div>
        <div class="desktop-megamenu d-none" id="megamenu-espace_chefs" data-megamenu-id="espace_chefs"></div>
        <div class="desktop-megamenu d-none" id="megamenu-configuration" data-megamenu-id="configuration"></div>
    </nav>
    <main><button id="ailleurs">Ailleurs</button></main>
`;

/** @param {string} menuId */
const panel = (menuId) => document.querySelector(`.desktop-megamenu[data-megamenu-id="${menuId}"]`);
/** @param {string} menuId */
const tab = (menuId) => document.querySelector(`.desktop-menu-btn[data-menu-id="${menuId}"]`);
/** @param {string} menuId */
const isOpen = (menuId) => !panel(menuId).classList.contains('d-none');

async function loadNav() {
    document.body.innerHTML = NAV;
    vi.resetModules();
    delete window.ScoutMagicNav;
    await import('../../public/assets/js/nav.js');
}

describe('nav.js — desktop mega-menu panel', () => {
    beforeEach(loadNav);

    it('opens the matching panel when its tab is clicked', () => {
        tab('espace_chefs').click();

        expect(isOpen('espace_chefs')).toBe(true);
        expect(isOpen('configuration')).toBe(false);
        expect(tab('espace_chefs').getAttribute('aria-expanded')).toBe('true');
    });

    it('closes it again on a second click of the same tab', () => {
        tab('espace_chefs').click();
        tab('espace_chefs').click();

        expect(isOpen('espace_chefs')).toBe(false);
        expect(tab('espace_chefs').getAttribute('aria-expanded')).toBe('false');
    });

    it('switches to the other panel rather than opening both', () => {
        tab('espace_chefs').click();
        tab('configuration').click();

        expect(isOpen('espace_chefs')).toBe(false);
        expect(isOpen('configuration')).toBe(true);
        expect(tab('espace_chefs').getAttribute('aria-expanded')).toBe('false');
        expect(tab('configuration').getAttribute('aria-expanded')).toBe('true');
    });

    it('closes on Escape and hands focus back to the tab that opened it', () => {
        tab('espace_chefs').click();
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(isOpen('espace_chefs')).toBe(false);
        expect(document.activeElement).toBe(tab('espace_chefs'));
    });

    it('leaves Escape alone when nothing is open', () => {
        document.getElementById('ailleurs').focus();
        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(document.activeElement).toBe(document.getElementById('ailleurs'));
    });

    it('closes on a mousedown outside the nav', () => {
        tab('espace_chefs').click();
        document.getElementById('ailleurs')
            .dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true }));

        expect(isOpen('espace_chefs')).toBe(false);
    });

    it('stays open on a mousedown inside the panel itself', () => {
        tab('espace_chefs').click();
        panel('espace_chefs').dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true }));

        expect(isOpen('espace_chefs')).toBe(true);
    });

    // breadcrumb.js depends on this contract: a raw click() would toggle
    // an already-open menu shut, which is the bug the export exists for.
    it('showDesktopMenu() opens a closed menu and leaves an open one open', () => {
        window.ScoutMagicNav.showDesktopMenu('configuration');
        expect(isOpen('configuration')).toBe(true);

        window.ScoutMagicNav.showDesktopMenu('configuration');
        expect(isOpen('configuration')).toBe(true);
        expect(tab('configuration').getAttribute('aria-expanded')).toBe('true');
    });

    it('showDesktopMenu() ignores a menu that has no panel', () => {
        tab('espace_chefs').click();
        window.ScoutMagicNav.showDesktopMenu('pas_un_menu');

        expect(isOpen('espace_chefs')).toBe(true);
    });

    /**
     * `active` marks the menu the current page belongs to — server-
     * rendered, true whatever is open. Opening and closing panels must
     * never wipe the "you are here" underline off the bar.
     */
    it('never touches the tab\'s active class', () => {
        tab('espace_chefs').click();
        expect(tab('configuration').classList.contains('active')).toBe(true);
        expect(tab('espace_chefs').classList.contains('active')).toBe(false);

        document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(tab('configuration').classList.contains('active')).toBe(true);
    });
});

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
