/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Lire le message » on a mail triage screen — the camps one and the
// rentals one, which share their template (@inbound_mail/partials/triage).
//
// The list used to carry a 220-character excerpt as a <details> summary:
// three lines per message, and a page of decisions reading as a wall of
// text. The excerpt is now one truncated line, and the whole message opens
// in a dialog.
//
// ONE dialog for the page, not one per message: a screen showing a hundred
// subjects would otherwise hold a hundred message bodies' worth of markup.
//
// **The body is MOVED, never re-parsed.** Each row renders its own body
// hidden, and opening the dialog appends that element to the dialog's body;
// closing puts it back where it came from. No HTML string travels through a
// data attribute and nothing is assigned to innerHTML — the body is already
// sanitised and stripped of remote images at storage (ARCHITECTURE.md
// §8.58), and that is not a reason to hand it to a second parser in the
// browser.
(function () {
    'use strict';

    /** Where the body was before the dialog borrowed it. */
    /** @type {HTMLElement|null} */
    var origin = null;
    /** @type {HTMLElement|null} */
    var borrowed = null;

    /** Put the borrowed body back, so a second opening finds it. */
    function giveBack() {
        if (borrowed && origin) {
            origin.appendChild(borrowed);
            borrowed.classList.add('d-none');
        }
        borrowed = null;
        origin = null;
    }

    // ONE listener, on the document, and the dialog looked up when it is
    // needed rather than when the page loaded: a screen that re-renders its
    // list in place (the rentals' Courrier page, rental-booking.js) brings
    // buttons nobody bound, and « Lire le message » must open on them too.
    document.addEventListener('click', function (e) {
        var target = /** @type {HTMLElement|null} */ (e.target);
        var button = /** @type {HTMLElement|null} */ (
            target === null ? null : target.closest('[data-mail-message-open]')
        );
        var modal = document.getElementById('mail-message-modal');
        var host = document.getElementById('mail-message-modal-body');
        if (button === null || !modal || !host) {
            return;
        }

        var body = document.getElementById(button.dataset.mailMessageOpen || '');
        if (!body) {
            return;
        }

        giveBack();
        origin = body.parentElement;
        borrowed = body;
        body.classList.remove('d-none');
        host.appendChild(body);

        var title = document.getElementById('mail-message-modal-title');
        var meta = document.getElementById('mail-message-modal-meta');
        if (title) {
            // textContent, not innerHTML: a subject is somebody else's text.
            title.textContent = button.dataset.mailMessageTitle || 'Message';
        }
        if (meta) {
            // Who wrote, and when: a dialog showing a body with no sender
            // is a body nobody can act on.
            meta.textContent = button.dataset.mailMessageMeta || '';
        }

        // Bootstrap builds its own instance on a data-bs-toggle click; this
        // button has none, so the dialog is opened here. Absent Bootstrap,
        // the body stays where it was and the page is merely as terse as
        // before.
        if (typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    });

    // Bootstrap's modal events bubble, so closing is heard here too.
    document.addEventListener('hidden.bs.modal', function (e) {
        var target = /** @type {HTMLElement|null} */ (e.target);
        if (target !== null && target.id === 'mail-message-modal') {
            giveBack();
        }
    });
})();
