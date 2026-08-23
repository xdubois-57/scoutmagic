// Isolated JavaScript unit test — jsdom-simulated DOM only. jsdom
// implements no layout and no scrolling, so Element.scrollIntoView is a
// spy: what this suite owns is WHICH element the page scrolls to and
// how, not the scrolling itself. Exercises the REAL implementation in
// public/assets/js/scroll-into-focus.js (imported below, never
// reimplemented here).
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('scroll-into-focus.js', () => {
    let scrollIntoView;

    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = '';
        scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;
    });

    const boot = () => import('../../public/assets/js/scroll-into-focus.js');

    it('does nothing on a page that marked no focus', async () => {
        document.body.innerHTML = '<div id="a"></div><div id="b"></div>';
        await boot();

        expect(scrollIntoView).not.toHaveBeenCalled();
    });

    it('scrolls to the marked element, centred and smoothly', async () => {
        document.body.innerHTML = '<div id="a"></div><div id="b" data-scroll-focus></div>';
        await boot();

        expect(scrollIntoView).toHaveBeenCalledTimes(1);
        expect(scrollIntoView.mock.instances[0].id).toBe('b');
        expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' });
    });

    it('scrolls to the FIRST marked element when a page marks several', async () => {
        document.body.innerHTML =
            '<div id="a" data-scroll-focus></div><div id="b" data-scroll-focus></div>';
        await boot();

        expect(scrollIntoView).toHaveBeenCalledTimes(1);
        expect(scrollIntoView.mock.instances[0].id).toBe('a');
    });

    it('throws nothing on a completely empty page', async () => {
        await expect(boot()).resolves.toBeDefined();
    });
});
