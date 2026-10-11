/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/phone-link.js.
//
// A phone number, rendered by phone() (Core\View\PhoneExtension) as a
// tel: link, opens a sheet of actions on a touch screen and becomes text
// with « Copier » elsewhere (docs/chantiers/CHANTIER-telephone.md, IT-02).
// The four link formats are pinned case by case: each target writes the
// number differently, and a « + » in the wrong place is a link that
// silently does nothing.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** The markup partials/phone_sheet.html.twig renders. */
const SHEET =
    '<div class="offcanvas offcanvas-bottom phone-sheet" id="phone-sheet">' +
    '  <h5 data-phone-sheet-number></h5><div data-phone-sheet-kind></div>' +
    '  <a href="#" data-phone-target="tel"><span>Appeler</span></a>' +
    '  <a href="#" data-phone-target="sms"><span>SMS</span></a>' +
    '  <a href="#" data-phone-target="whatsapp" target="_blank" rel="noopener noreferrer"><span>WhatsApp</span></a>' +
    '  <a href="#" data-phone-target="signal" target="_blank" rel="noopener noreferrer"><span>Signal</span></a>' +
    '  <button type="button" data-phone-copy><span data-phone-copy-label>Copier le numéro</span></button>' +
    '  <p data-phone-sheet-landline hidden>Un numéro fixe ne reçoit ni SMS ni message.</p>' +
    '</div>';

/** What phone() renders for one number. */
function phoneLink(id, international, display, kind) {
    return '<a id="' + id + '" href="tel:' + international + '" class="phone-link" data-phone="' + international
        + '" data-phone-display="' + display + '" data-phone-kind="' + kind + '">' + display + '</a>';
}

function pretendTouch(on) {
    window.matchMedia = /** @type {any} */ ((query) => ({
        matches: on && query === '(pointer: coarse)',
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
}

function click(element) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    element.dispatchEvent(event);
    return event;
}

let loaded = false;
let offcanvas;
let writeText;

async function load() {
    // Once, as a page loads it once: the click listener is document-wide.
    if (!loaded) {
        pretendTouch(true);
        vi.resetModules();
        await import('../../public/assets/js/phone-link.js');
        loaded = true;
    }
}

/** @returns {any} */
function api() {
    return /** @type {any} */ (window).ScoutMagicPhone;
}

describe('phone-link.js', () => {
    beforeEach(async () => {
        await load();
        offcanvas = { show: vi.fn(), hide: vi.fn() };
        /** @type {any} */ (window).bootstrap = { Offcanvas: { getOrCreateInstance: vi.fn(() => offcanvas) } };
        writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    describe('linksFor — one number, four ways of writing it (D4)', () => {
        it('calls and texts the international number with its « + »', () => {
            const links = api().linksFor('+32478123456');
            expect(links.tel).toBe('tel:+32478123456');
            expect(links.sms).toBe('sms:+32478123456');
        });

        it('gives WhatsApp the number without its « + »', () => {
            expect(api().linksFor('+32478123456').whatsapp).toBe('https://wa.me/32478123456');
        });

        it('gives Signal the number with its « + », which its app requires', () => {
            expect(api().linksFor('+32478123456').signal).toBe('https://signal.me/#p/+32478123456');
        });

        it('writes a foreign number the same way', () => {
            expect(api().linksFor('+352691123456')).toEqual({
                tel: 'tel:+352691123456',
                sms: 'sms:+352691123456',
                whatsapp: 'https://wa.me/352691123456',
                signal: 'https://signal.me/#p/+352691123456',
            });
        });

        it('puts no message body on sms: (D6)', () => {
            expect(api().linksFor('+32478123456').sms).not.toContain('body');
        });
    });

    describe('on a touch screen', () => {
        beforeEach(() => pretendTouch(true));

        it('announces the number as opening a dialog', () => {
            document.body.innerHTML = phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');
            api().enhance(document);

            const link = document.getElementById('n');
            expect(link.getAttribute('aria-haspopup')).toBe('dialog');
            expect(link.getAttribute('href')).toBe('tel:+32472546771');
        });

        it('opens the sheet with every action for a mobile', () => {
            document.body.innerHTML = SHEET + phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');

            const event = click(document.getElementById('n'));

            expect(event.defaultPrevented).toBe(true);
            expect(offcanvas.show).toHaveBeenCalledTimes(1);
            expect(document.querySelector('[data-phone-sheet-number]').textContent).toBe('+32 472 54 67 71');
            expect(document.querySelector('[data-phone-sheet-kind]').textContent).toBe('Mobile');
            expect(document.querySelector('[data-phone-target="tel"]').getAttribute('href')).toBe('tel:+32472546771');
            expect(document.querySelector('[data-phone-target="sms"]').getAttribute('href')).toBe('sms:+32472546771');
            expect(document.querySelector('[data-phone-target="whatsapp"]').getAttribute('href')).toBe('https://wa.me/32472546771');
            expect(document.querySelector('[data-phone-target="signal"]').getAttribute('href')).toBe('https://signal.me/#p/+32472546771');
            document.querySelectorAll('[data-phone-target]').forEach((item) => expect(item.hidden).toBe(false));
            expect(document.querySelector('[data-phone-sheet-landline]').hidden).toBe(true);
        });

        it('offers only the call and the copy for a landline, and says why (D7)', () => {
            document.body.innerHTML = SHEET + phoneLink('n', '+3221234567', '+32 2 123 45 67', 'landline');

            click(document.getElementById('n'));

            expect(document.querySelector('[data-phone-sheet-kind]').textContent).toBe('Téléphone fixe');
            expect(document.querySelector('[data-phone-target="tel"]').hidden).toBe(false);
            expect(document.querySelector('[data-phone-target="sms"]').hidden).toBe(true);
            expect(document.querySelector('[data-phone-target="whatsapp"]').hidden).toBe(true);
            expect(document.querySelector('[data-phone-target="signal"]').hidden).toBe(true);
            expect(document.querySelector('[data-phone-copy]').hidden).toBe(false);
            expect(document.querySelector('[data-phone-sheet-landline]').hidden).toBe(false);
        });

        it('offers everything for a foreign number, whose kind nobody can tell', () => {
            document.body.innerHTML = SHEET + phoneLink('n', '+33612345678', '+33 6 12 34 56 78', 'foreign');

            click(document.getElementById('n'));

            expect(document.querySelector('[data-phone-sheet-kind]').textContent).toBe('Numéro étranger');
            expect(document.querySelector('[data-phone-target="signal"]').hidden).toBe(false);
        });

        it('a landline after a mobile does not keep the mobile\'s actions', () => {
            document.body.innerHTML = SHEET
                + phoneLink('m', '+32472546771', '+32 472 54 67 71', 'mobile')
                + phoneLink('l', '+3221234567', '+32 2 123 45 67', 'landline');

            click(document.getElementById('m'));
            click(document.getElementById('l'));

            expect(document.querySelector('[data-phone-target="whatsapp"]').hidden).toBe(true);
            click(document.getElementById('m'));
            expect(document.querySelector('[data-phone-target="whatsapp"]').hidden).toBe(false);
        });

        it('names each action with the number it acts on', () => {
            document.body.innerHTML = SHEET + phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');

            click(document.getElementById('n'));

            expect(document.querySelector('[data-phone-target="sms"]').getAttribute('aria-label'))
                .toBe('Envoyer un SMS au +32 472 54 67 71');
            expect(document.querySelector('[data-phone-copy]').getAttribute('aria-label'))
                .toBe('Copier le numéro +32 472 54 67 71');
        });

        it('closes the sheet once an action is chosen', () => {
            document.body.innerHTML = SHEET + phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');
            click(document.getElementById('n'));

            const event = click(document.querySelector('[data-phone-target="whatsapp"] span'));

            expect(event.defaultPrevented).toBe(false);
            expect(offcanvas.hide).toHaveBeenCalledTimes(1);
        });

        it('copies the number, says so, then closes the sheet', async () => {
            vi.useFakeTimers();
            document.body.innerHTML = SHEET + phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');
            click(document.getElementById('n'));

            click(document.querySelector('[data-phone-copy]'));
            await Promise.resolve();
            await Promise.resolve();

            expect(writeText).toHaveBeenCalledWith('+32 472 54 67 71');
            expect(document.querySelector('[data-phone-copy-label]').textContent).toBe('Numéro copié');
            vi.advanceTimersByTime(1000);
            expect(offcanvas.hide).toHaveBeenCalledTimes(1);
            vi.advanceTimersByTime(1000);
            expect(document.querySelector('[data-phone-copy-label]').textContent).toBe('Copier le numéro');
        });

        it('leaves the tel: link to call when the sheet cannot open', () => {
            /** @type {any} */ (window).bootstrap = undefined;
            document.body.innerHTML = SHEET + phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');

            const event = click(document.getElementById('n'));

            expect(event.defaultPrevented).toBe(false);
        });
    });

    describe('on a screen without touch (D8)', () => {
        beforeEach(() => pretendTouch(false));

        it('shows the number as text with a « Copier » button', () => {
            document.body.innerHTML = '<table><tbody><tr><td>' + phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile') + '</td></tr></tbody></table>';

            api().enhance(document);

            expect(document.querySelector('a[data-phone]')).toBeNull();
            expect(document.querySelector('td').textContent).toContain('+32 472 54 67 71');
            const button = document.querySelector('[data-phone-copy-inline]');
            expect(button.textContent).toBe('Copier');
            expect(button.getAttribute('aria-label')).toBe('Copier le numéro +32 472 54 67 71');
        });

        it('copies the number and confirms it briefly', async () => {
            vi.useFakeTimers();
            document.body.innerHTML = phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');
            api().enhance(document);
            const button = document.querySelector('[data-phone-copy-inline]');

            click(button);
            await Promise.resolve();
            await Promise.resolve();

            expect(writeText).toHaveBeenCalledWith('+32 472 54 67 71');
            expect(button.textContent).toBe('Copié');
            vi.advanceTimersByTime(2000);
            expect(button.textContent).toBe('Copier');
        });

        it('says so when the clipboard refuses', async () => {
            writeText.mockImplementation(() => Promise.reject(new Error('denied')));
            document.body.innerHTML = phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');
            api().enhance(document);
            const button = document.querySelector('[data-phone-copy-inline]');

            click(button);
            await Promise.resolve();
            await Promise.resolve();

            expect(button.textContent).toBe('Copie impossible');
        });

        it('handles a number only once, however often enhance runs', () => {
            document.body.innerHTML = phoneLink('n', '+32472546771', '+32 472 54 67 71', 'mobile');

            api().enhance(document);
            api().enhance(document);

            expect(document.querySelectorAll('[data-phone-copy-inline]')).toHaveLength(1);
        });
    });
});
