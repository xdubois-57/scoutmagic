/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Retro module chief-facing create/edit form (retro/config.html.twig):
// vote-mode toggle, event-picker date sync, max-length slider live value.
// Pure JS, no external library — same IIFE/var style as gallery-config.js.
//
// Lives in an external file rather than an inline <script> because this
// site's CSP is nonce-only (script-src 'self' 'nonce-...', no
// 'unsafe-inline') — an inline <script> without a nonce, and an inline
// oninput="..." attribute, are both silently blocked by the browser with
// no visible error beyond the devtools console. This is the actual root
// cause both the event-picker date sync and the slider live value never
// worked, not a logic bug in either handler itself.
(function () {
    document.querySelectorAll('[data-vote-mode]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var field = document.getElementById('retro-vote-budget-field');
            if (field) field.classList.toggle('d-none', this.value !== 'budget');
        });
    });

    var eventSelect = document.getElementById('retro-event');
    var dateInput = document.getElementById('retro-date');
    var titleInput = document.getElementById('retro-title');
    if (eventSelect && dateInput) {
        eventSelect.addEventListener('change', function () {
            var selected = eventSelect.options[eventSelect.selectedIndex];
            var endDate = selected ? selected.dataset.endDate : '';
            if (eventSelect.value !== '' && endDate) {
                dateInput.value = endDate;
                dateInput.disabled = true;
            } else {
                dateInput.disabled = false;
            }

            if (titleInput) {
                if (eventSelect.value !== '' && selected) {
                    var eventTitle = selected.dataset.title || '';
                    var calendarName = selected.dataset.calendarName || '';
                    var startDate = selected.dataset.startDate || '';
                    titleInput.value = 'Rétrospective ' + eventTitle + ' - ' + calendarName + ' - ' + startDate;
                    titleInput.disabled = true;
                } else {
                    titleInput.disabled = false;
                }
            }
        });
    }

    var maxLengthSlider = document.getElementById('retro-max-length');
    var maxLengthValue = document.getElementById('retro-max-length-value');
    if (maxLengthSlider && maxLengthValue) {
        maxLengthSlider.addEventListener('input', function () {
            maxLengthValue.textContent = maxLengthSlider.value;
        });
    }

    var notifyEnabled = document.getElementById('retro-notify-enabled');
    var notifyEmail = document.getElementById('retro-notify-email');
    if (notifyEnabled && notifyEmail) {
        var syncNotifyEmail = function () { notifyEmail.disabled = !notifyEnabled.checked; };
        notifyEnabled.addEventListener('change', syncNotifyEmail);
        syncNotifyEmail();
    }
})();
