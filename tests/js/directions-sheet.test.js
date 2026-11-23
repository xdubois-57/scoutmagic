/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/directions-sheet.js.
//
// The carpool page's « Itinéraire » button opens a sheet of three map
// applications (issue #1345). Bootstrap opens it from data attributes; the
// script only gets the sheet out of the way once an application was chosen,
// like phone-link.js does for a phone number — and remembers nothing.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** The markup modules/covoiturage/views/show.html.twig renders. */
const SHEET =
    '<div class="offcanvas offcanvas-bottom action-sheet" id="directions-sheet" data-directions-sheet>' +
    '  <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>' +
    '  <a id="plans" href="https://maps.apple.com/?daddr=1" target="_blank" data-directions-choice="apple"><span>Plans</span></a>' +
    '  <a id="google" href="https://www.google.com/maps/dir/?api=1&destination=1" target="_blank" data-directions-choice="google"><span>Google Maps</span></a>' +
    '  <a id="waze" href="https://waze.com/ul?q=1&navigate=yes" target="_blank" data-directions-choice="waze"><span>Waze</span></a>' +
    '</div>' +
    '<a id="outside" href="https://example.org/">ailleurs</a>';

function click(element) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    element.dispatchEvent(event);
    return event;
}

let loaded = false;
let offcanvas;

async function load() {
    // Once, as a page loads it once: the click listener is document-wide.
    if (!loaded) {
        vi.resetModules();
        await import('../../public/assets/js/directions-sheet.js');
        loaded = true;
    }
}

describe('directions-sheet.js', () => {
    beforeEach(async () => {
        await load();
        offcanvas = { hide: vi.fn() };
        /** @type {any} */ (window).bootstrap = { Offcanvas: { getInstance: vi.fn(() => offcanvas), getOrCreateInstance: vi.fn(() => offcanvas) } };
        document.body.innerHTML = SHEET;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it.each(['plans', 'google', 'waze'])('closes the sheet when %s is chosen', (id) => {
        click(document.getElementById(id));

        expect(offcanvas.hide).toHaveBeenCalledTimes(1);
    });

    it('lets the link open: the click is never cancelled', () => {
        const event = click(document.getElementById('google'));

        expect(event.defaultPrevented).toBe(false);
    });

    it('does nothing for a click outside the sheet', () => {
        click(document.getElementById('outside'));

        expect(offcanvas.hide).not.toHaveBeenCalled();
    });

    it('does not break when Bootstrap is not loaded: the link still opens', () => {
        /** @type {any} */ (window).bootstrap = undefined;

        const event = click(document.getElementById('waze'));

        expect(event.defaultPrevented).toBe(false);
    });

    it('does not break when the sheet has no Offcanvas instance yet', () => {
        /** @type {any} */ (window).bootstrap = { Offcanvas: { getInstance: vi.fn(() => null), getOrCreateInstance: vi.fn(() => null) } };

        expect(() => click(document.getElementById('plans'))).not.toThrow();
    });

    it('remembers nothing of the choice: no storage, no cookie', () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem');
        const before = document.cookie;

        click(document.getElementById('google'));

        expect(setItem).not.toHaveBeenCalled();
        expect(document.cookie).toBe(before);
        setItem.mockRestore();
    });
});
