// Isolated JavaScript unit test — jsdom-simulated DOM only, no PHP server,
// no network. Exercises the REAL implementation in
// public/assets/js/nav-rail.js (imported below, never reimplemented here)
// — the component behind partials/nav_rail.html.twig.
//
// nav-rail.js is an IIFE that reads the DOM and runs at import time, so
// each test builds its DOM first and then imports the module via
// vi.resetModules() + await import() (the tests/js/chip-picker.test.js
// pattern).
//
// jsdom implements neither scrollIntoView nor matchMedia, so both are
// stubbed per test — scrollIntoView as the spy the assertions read, and
// matchMedia to control the prefers-reduced-motion branch.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function mockReducedMotion(reduce) {
    window.matchMedia = vi.fn().mockImplementation((query) => ({
        matches: reduce,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
}

function buildRail({ selectedIndex } = { selectedIndex: 1 }) {
    const tabs = ['Tableau de bord', 'Mouvements', 'Reçus'].map((label, i) => {
        const current = i === selectedIndex ? ' aria-current="page"' : '';
        const active = i === selectedIndex ? ' active' : '';
        return `<li class="nav-item"><a class="nav-link tap-target${active}" href="/p${i}" data-id="/p${i}"${current}>${label}</a></li>`;
    }).join('');

    document.body.innerHTML = `
        <nav class="nav-rail" id="finance-page-picker" aria-label="Navigation">
            <ul class="nav nav-underline flex-nowrap overflow-auto">${tabs}</ul>
        </nav>
    `;

    const spy = vi.fn();
    document.querySelectorAll('.nav-link').forEach((el) => { el.scrollIntoView = spy; });
    return spy;
}

const load = () => import('../../public/assets/js/nav-rail.js');

describe('nav-rail.js', () => {
    beforeEach(() => {
        vi.resetModules();
        mockReducedMotion(false);
    });

    it('scrolls the selected tab into view, centred horizontally', async () => {
        const scrollIntoView = buildRail({ selectedIndex: 1 });

        await load();

        expect(scrollIntoView).toHaveBeenCalledTimes(1);
        expect(scrollIntoView).toHaveBeenCalledWith({
            behavior: 'smooth',
            inline: 'center',
            block: 'nearest',
        });
    });

    it('never scrolls the page vertically — block is always "nearest"', async () => {
        const scrollIntoView = buildRail({ selectedIndex: 2 });

        await load();

        expect(scrollIntoView.mock.calls[0][0].block).toBe('nearest');
    });

    it('honours prefers-reduced-motion by scrolling without animation', async () => {
        mockReducedMotion(true);
        const scrollIntoView = buildRail({ selectedIndex: 1 });

        await load();

        expect(scrollIntoView.mock.calls[0][0].behavior).toBe('auto');
    });

    it('does nothing when no tab is selected', async () => {
        const scrollIntoView = buildRail({ selectedIndex: -1 });

        await load();

        expect(scrollIntoView).not.toHaveBeenCalled();
    });

    it('does nothing, and does not throw, when the page holds no rail', async () => {
        document.body.innerHTML = '<p>Rien ici.</p>';

        await expect(load()).resolves.toBeDefined();
    });

    it('scrolls the selected tab of every rail on the page', async () => {
        // /finance carries a rail above a select bar; a page may equally
        // carry two rails. Each one centres its own current tab.
        document.body.innerHTML = `
            <nav class="nav-rail" id="rail-a">
                <ul class="nav"><li><a class="nav-link" href="/a" aria-current="page">A</a></li></ul>
            </nav>
            <nav class="nav-rail" id="rail-b">
                <ul class="nav"><li><a class="nav-link" href="/b" aria-current="page">B</a></li></ul>
            </nav>
        `;
        const spy = vi.fn();
        document.querySelectorAll('.nav-link').forEach((el) => { el.scrollIntoView = spy; });

        await load();

        expect(spy).toHaveBeenCalledTimes(2);
    });

    it('tolerates a browser that does not implement scrollIntoView', async () => {
        buildRail({ selectedIndex: 1 });
        document.querySelectorAll('.nav-link').forEach((el) => { el.scrollIntoView = undefined; });

        await expect(load()).resolves.toBeDefined();
    });

    it('tolerates a browser without matchMedia', async () => {
        buildRail({ selectedIndex: 1 });
        // @ts-expect-error — deliberately removing the API under test
        window.matchMedia = undefined;

        await expect(load()).resolves.toBeDefined();
    });
});
