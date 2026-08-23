// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
// Exercises the REAL public/assets/js/breadcrumb.js (imported below, never
// reimplemented): the file is an IIFE that wires the breadcrumb bar's
// parent buttons at import time.
//
// The behaviour is worth pinning because it is the site's ONLY back
// affordance (design.md §7.3) and because it branches on viewport: the
// desktop path must go through ScoutMagicNav.showDesktopMenu() rather than
// clicking the menu button, since clicking an already-open menu would
// close it — the exact bug the file's header records.
//
// tests/Core/View/BreadcrumbJsTest.php reads this file's source for its
// structural rules; this one runs it.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadBreadcrumb(html, { desktop }) {
    document.body.innerHTML = html;
    window.matchMedia = vi.fn((query) => ({
        matches: desktop && query === '(min-width: 992px)',
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
    vi.resetModules();
    await import('../../public/assets/js/breadcrumb.js');
}

const BAR = `
    <nav>
        <button class="breadcrumb-parent-btn" data-open-menu="espace_chefs">Espace animateurs</button>
        <button class="breadcrumb-parent-btn" id="no-target">Sans cible</button>
    </nav>
    <button class="desktop-menu-btn" data-menu-id="espace_chefs">Espace animateurs</button>
    <div id="navOffcanvas"><div id="mob-espace_chefs" class="collapse"></div></div>
`;

let showDesktopMenu;
let collapseShow;
let offcanvasShow;

beforeEach(() => {
    document.body.innerHTML = '';
    showDesktopMenu = vi.fn();
    collapseShow = vi.fn();
    offcanvasShow = vi.fn();
    window.ScoutMagicNav = { showDesktopMenu };
    globalThis.bootstrap = {
        Collapse: { getOrCreateInstance: vi.fn(() => ({ show: collapseShow })) },
        Offcanvas: { getOrCreateInstance: vi.fn(() => ({ show: offcanvasShow })) },
    };
    window.HTMLElement.prototype.scrollIntoView = vi.fn();
});

describe('breadcrumb.js on desktop', () => {
    it('asks the nav to show the menu rather than clicking its button', async () => {
        await loadBreadcrumb(BAR, { desktop: true });
        const menuButtonClick = vi.fn();
        document.querySelector('.desktop-menu-btn').addEventListener('click', menuButtonClick);

        document.querySelector('.breadcrumb-parent-btn').click();

        expect(showDesktopMenu).toHaveBeenCalledWith('espace_chefs');
        // Clicking the menu button would TOGGLE an already-open menu shut,
        // which is the bug this indirection exists to avoid.
        expect(menuButtonClick).not.toHaveBeenCalled();
        expect(offcanvasShow).not.toHaveBeenCalled();
    });

    it('scrolls the menu button into view so the visitor sees what opened', async () => {
        await loadBreadcrumb(BAR, { desktop: true });

        document.querySelector('.breadcrumb-parent-btn').click();

        expect(document.querySelector('.desktop-menu-btn').scrollIntoView)
            .toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' });
    });

    it('survives a page where nav.js never loaded', async () => {
        delete window.ScoutMagicNav;
        await loadBreadcrumb(BAR, { desktop: true });

        expect(() => document.querySelector('.breadcrumb-parent-btn').click()).not.toThrow();
    });
});

describe('breadcrumb.js on mobile', () => {
    it('opens the offcanvas and expands the matching section', async () => {
        await loadBreadcrumb(BAR, { desktop: false });

        document.querySelector('.breadcrumb-parent-btn').click();

        expect(offcanvasShow).toHaveBeenCalledTimes(1);
        expect(showDesktopMenu).not.toHaveBeenCalled();
        // Not yet: the section expands only once the offcanvas is really
        // open, or it races the opening transition and the focus trap.
        expect(collapseShow).not.toHaveBeenCalled();

        document.getElementById('navOffcanvas')
            .dispatchEvent(new window.Event('shown.bs.offcanvas'));
        expect(collapseShow).toHaveBeenCalledTimes(1);
    });

    it('expands the section once, not once per breadcrumb click', async () => {
        await loadBreadcrumb(BAR, { desktop: false });
        const offcanvas = document.getElementById('navOffcanvas');
        const button = document.querySelector('.breadcrumb-parent-btn');

        button.click();
        offcanvas.dispatchEvent(new window.Event('shown.bs.offcanvas'));
        offcanvas.dispatchEvent(new window.Event('shown.bs.offcanvas'));

        expect(collapseShow).toHaveBeenCalledTimes(1);
    });

    it('does nothing at all without the Bootstrap bundle', async () => {
        delete globalThis.bootstrap;
        await loadBreadcrumb(BAR, { desktop: false });

        expect(() => document.querySelector('.breadcrumb-parent-btn').click()).not.toThrow();
    });
});

describe('breadcrumb.js guards', () => {
    it('ignores a parent button that names no menu', async () => {
        await loadBreadcrumb(BAR, { desktop: true });

        document.getElementById('no-target').click();

        expect(showDesktopMenu).not.toHaveBeenCalled();
    });

    it('wires nothing on a page with no breadcrumb parents', async () => {
        await expect(loadBreadcrumb('<p>Une page sans fil d\'Ariane</p>', { desktop: true }))
            .resolves.not.toThrow();
        expect(showDesktopMenu).not.toHaveBeenCalled();
    });
});
