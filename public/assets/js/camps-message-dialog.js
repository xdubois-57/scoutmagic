/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Lire le message » on the camps mail screen.
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
// sanitised and stripped of remote images at storage (§7.9), and that is
// not a reason to hand it to a second parser in the browser.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('camps-message-modal');
        var host = document.getElementById('camps-message-modal-body');
        if (!modal || !host) {
            return;
        }

        var title = document.getElementById('camps-message-modal-title');
        /** Where the body was before the dialog borrowed it. */
        var origin = null;
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

        var buttons = /** @type {NodeListOf<HTMLElement>} */ (
            document.querySelectorAll('[data-camps-message-open]')
        );
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var body = document.getElementById(button.dataset.campsMessageOpen || '');
                if (!body) {
                    return;
                }

                giveBack();
                origin = body.parentElement;
                borrowed = body;
                body.classList.remove('d-none');
                host.appendChild(body);

                if (title) {
                    // textContent, not innerHTML: a subject is somebody
                    // else's text.
                    title.textContent = button.dataset.campsMessageTitle || 'Message';
                }

                // Bootstrap builds its own instance on a data-bs-toggle
                // click; this button has none, so the dialog is opened
                // here. Absent Bootstrap, the body stays where it was and
                // the page is merely as terse as before.
                if (typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            });
        });

        modal.addEventListener('hidden.bs.modal', giveBack);
    });
})();
