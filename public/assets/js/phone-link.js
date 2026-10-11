/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// A phone number you can call, text, or write to on WhatsApp or Signal
// (docs/chantiers/CHANTIER-telephone.md, issue #757).
//
// The server renders every number as a plain `tel:` link through the
// `phone()` Twig function (Core\View\PhoneExtension), with what the number
// is in data-* attributes: `data-phone` (the international form),
// `data-phone-display` and `data-phone-kind` (mobile, landline, foreign).
// This script never reads a number again — Core\Phone\PhoneNumber did.
//
// - On a touch screen, a tap opens the sheet (partials/phone_sheet.html.twig):
//   Appeler, SMS, WhatsApp, Signal, Copier le numéro. A Belgian landline is
//   offered the call and the copy only (D7).
// - Elsewhere, the link becomes the number as text with a « Copier » button
//   (D8): a text message makes no sense there, and WhatsApp or Signal
//   would open their web versions.
// - Without JavaScript, the `tel:` link stays and works (D9).
//
// `linksFor()` below is the only place the four link formats exist (D4):
// each target expects the number written differently, and having them in
// one function is what lets the test suite pin every one of them.
(function () {
    'use strict';

    /** @type {Record<string, string>} */
    var KIND_LABELS = {
        mobile: 'Mobile',
        landline: 'Téléphone fixe',
        foreign: 'Numéro étranger',
    };

    /** How long « Numéro copié » / « Copié » stays before going back. */
    var COPIED_DELAY = 1500;

    /** The sheet closes a moment after a copy, once the label was read. */
    var SHEET_CLOSE_DELAY = 900;

    /**
     * The link each target opens, from the number's international form
     * (`+32478123456`):
     *
     * | target   | link                     | number          |
     * |----------|--------------------------|-----------------|
     * | call     | tel:                     | +32478123456    |
     * | text     | sms:                     | +32478123456    |
     * | WhatsApp | https://wa.me/…          | 32478123456 — without the « + » |
     * | Signal   | https://signal.me/#p/…   | +32478123456 — the « + » is required |
     *
     * No message body on `sms:` (D6): iOS and Android do not write the
     * parameter the same way. WhatsApp and Signal take their https:// links
     * rather than their own schemes (D5), so a phone without the app lands
     * on an explanation instead of a silent failure.
     *
     * @param {string} international
     * @returns {{tel: string, sms: string, whatsapp: string, signal: string}}
     */
    function linksFor(international) {
        var digits = international.replace(/^\+/, '');
        return {
            tel: 'tel:+' + digits,
            sms: 'sms:+' + digits,
            whatsapp: 'https://wa.me/' + digits,
            signal: 'https://signal.me/#p/+' + digits,
        };
    }

    /** @returns {boolean} */
    function isTouchScreen() {
        return typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
    }

    /**
     * @param {string} text
     * @returns {Promise<void>}
     */
    function copy(text) {
        var clipboard = navigator.clipboard;
        if (!clipboard || typeof clipboard.writeText !== 'function') {
            return Promise.reject(new Error('Clipboard unavailable'));
        }
        return clipboard.writeText(text);
    }

    /**
     * Swap a label for a moment, then put it back.
     *
     * @param {HTMLElement} element
     * @param {string} text
     * @param {number} delay
     */
    function flash(element, text, delay) {
        var original = element.dataset.phoneOriginalLabel || element.textContent || '';
        element.dataset.phoneOriginalLabel = original;
        element.textContent = text;
        window.setTimeout(function () {
            element.textContent = original;
        }, delay);
    }

    /**
     * On a screen without touch, the number as text and a « Copier » button.
     *
     * @param {HTMLAnchorElement} link
     */
    function replaceWithCopy(link) {
        var display = link.dataset.phoneDisplay || link.textContent || '';

        var wrapper = document.createElement('span');
        wrapper.className = 'phone-text';

        var number = document.createElement('span');
        number.textContent = display;

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link btn-sm p-0 ms-2 align-baseline phone-copy';
        button.dataset.phoneCopyInline = display;
        button.setAttribute('aria-label', 'Copier le numéro ' + display);
        button.textContent = 'Copier';

        wrapper.append(number, button);
        link.replaceWith(wrapper);
    }

    /**
     * Make the phone links under `root` what this screen can use. Safe to
     * call again on content added later: a link is only handled once.
     *
     * @param {ParentNode} [root]
     */
    function enhance(root) {
        var scope = root || document;
        var touch = isTouchScreen();
        scope.querySelectorAll('a[data-phone]').forEach(function (element) {
            var link = /** @type {HTMLAnchorElement} */ (element);
            if (link.dataset.phoneReady) {
                return;
            }
            link.dataset.phoneReady = '1';
            if (touch) {
                link.setAttribute('aria-haspopup', 'dialog');
                link.setAttribute('aria-controls', 'phone-sheet');
                return;
            }
            replaceWithCopy(link);
        });
    }

    /** @returns {HTMLElement|null} */
    function sheetElement() {
        return document.getElementById('phone-sheet');
    }

    /**
     * Fill the sheet for one number.
     *
     * @param {HTMLElement} sheet
     * @param {string} international
     * @param {string} display
     * @param {string} kind
     */
    function fillSheet(sheet, international, display, kind) {
        var links = linksFor(international);
        var landline = kind === 'landline';

        var number = sheet.querySelector('[data-phone-sheet-number]');
        if (number) {
            number.textContent = display;
        }
        var kindLabel = sheet.querySelector('[data-phone-sheet-kind]');
        if (kindLabel) {
            kindLabel.textContent = KIND_LABELS[kind] || '';
        }
        var note = /** @type {HTMLElement|null} */ (sheet.querySelector('[data-phone-sheet-landline]'));
        if (note) {
            note.hidden = !landline;
        }

        /** @type {Record<string, string>} */
        var labels = {
            tel: 'Appeler le ' + display,
            sms: 'Envoyer un SMS au ' + display,
            whatsapp: 'Écrire sur WhatsApp au ' + display,
            signal: 'Écrire sur Signal au ' + display,
        };
        sheet.querySelectorAll('[data-phone-target]').forEach(function (element) {
            var item = /** @type {HTMLAnchorElement} */ (element);
            var target = /** @type {'tel'|'sms'|'whatsapp'|'signal'} */ (item.dataset.phoneTarget || '');
            item.href = links[target] || '#';
            item.setAttribute('aria-label', labels[target] || '');
            item.hidden = landline && target !== 'tel';
        });

        var copyButton = /** @type {HTMLElement|null} */ (sheet.querySelector('[data-phone-copy]'));
        if (copyButton) {
            copyButton.dataset.phoneCopyValue = display;
            copyButton.setAttribute('aria-label', 'Copier le numéro ' + display);
        }
    }

    /**
     * @param {HTMLElement} sheet
     * @returns {{show: () => void, hide: () => void}|null}
     */
    function offcanvas(sheet) {
        var bootstrap = /** @type {any} */ (window).bootstrap;
        if (!bootstrap?.Offcanvas) {
            return null;
        }
        return bootstrap.Offcanvas.getOrCreateInstance(sheet);
    }

    document.addEventListener('click', function (event) {
        var target = /** @type {Element|null} */ (event.target);
        if (!target?.closest) {
            return;
        }

        // A number on a touch screen: the sheet instead of the call. When
        // the sheet cannot open, the tel: link is left to do its job.
        var link = /** @type {HTMLAnchorElement|null} */ (target.closest('a[data-phone]'));
        if (link && isTouchScreen()) {
            var sheet = sheetElement();
            var panel = sheet ? offcanvas(sheet) : null;
            if (!sheet || !panel) {
                return;
            }
            event.preventDefault();
            fillSheet(sheet, link.dataset.phone || '', link.dataset.phoneDisplay || link.textContent || '', link.dataset.phoneKind || '');
            panel.show();
            return;
        }

        // A choice in the sheet: the app opens, the sheet gets out of the way.
        var choice = target.closest('[data-phone-target]');
        if (choice) {
            var openSheet = sheetElement();
            var openPanel = openSheet ? offcanvas(openSheet) : null;
            if (openPanel) {
                openPanel.hide();
            }
            return;
        }

        var sheetCopy = /** @type {HTMLElement|null} */ (target.closest('[data-phone-copy]'));
        if (sheetCopy) {
            var label = /** @type {HTMLElement|null} */ (sheetCopy.querySelector('[data-phone-copy-label]'));
            copy(sheetCopy.dataset.phoneCopyValue || '').then(function () {
                if (label) {
                    flash(label, 'Numéro copié', COPIED_DELAY);
                }
                window.setTimeout(function () {
                    var copiedSheet = sheetElement();
                    var copiedPanel = copiedSheet ? offcanvas(copiedSheet) : null;
                    if (copiedPanel) {
                        copiedPanel.hide();
                    }
                }, SHEET_CLOSE_DELAY);
            }, function () {
                if (label) {
                    flash(label, 'Copie impossible', COPIED_DELAY);
                }
            });
            return;
        }

        var inlineCopy = /** @type {HTMLElement|null} */ (target.closest('[data-phone-copy-inline]'));
        if (inlineCopy) {
            copy(inlineCopy.dataset.phoneCopyInline || '').then(function () {
                flash(inlineCopy, 'Copié', COPIED_DELAY);
            }, function () {
                flash(inlineCopy, 'Copie impossible', COPIED_DELAY);
            });
        }
    });

    /** @type {any} */ (window).ScoutMagicPhone = { linksFor: linksFor, enhance: enhance };

    enhance(document);
})();
