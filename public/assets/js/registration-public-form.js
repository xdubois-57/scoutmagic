/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Public registration form (modules/registration/views/public.html.twig):
// the line under « Date de naissance » that tells a parent, as they fill
// it in, which branch the child would join. Extracted from the
// template's inline <script> so the Vitest suite can exercise the
// production code directly (tests/js/registration-public-form.test.js).
//
// The branch table comes from a `<script type="application/json">`
// island (ScoutMagicApi.pageData()) the template fills with the very same
// Service\SlotService::birthYearsByBranch() rows the « nés en… » table
// above renders (base.html.twig's `offline-config-data` precedent).
// Reusing that exact data avoids a round trip and keeps this preview
// trivially in sync with the branch capacities and waitlist tiers shown
// above it.
//
// Birth YEAR only, deliberately: that is the precision the server-side
// computation commits to as well (Service\SlotMath's own docblock — a
// not-yet-registered child's exact birth day is unknown ahead of the
// parent filling in this very form).
(function () {
    var birthDateInput = /** @type {HTMLInputElement|null} */ (document.getElementById('birth_date'));
    var hint = document.getElementById('birth-date-branch-hint');
    var data = window.ScoutMagicApi.pageData('registration-slots-data');

    // A no-op on every other page of the site.
    if (!birthDateInput || !hint || !data) {
        return;
    }

    /** @type {Record<string, string>} */
    var TIER_LABELS = {
        available: 'Disponible',
        limited: 'Limitée',
        heavy: 'Complet'
    };

    var slots = data.birthYearSlots || [];
    var waitlistEnabled = data.waitlistEnabled === true;
    var targetYearLabel = data.targetYearLabel || '';

    function updateHint() {
        var year = parseInt((birthDateInput.value || '').slice(0, 4), 10);
        if (!year || String(year).length !== 4) {
            hint.textContent = '';
            return;
        }

        var match = slots.find(function (slot) {
            return slot.birth_year === year;
        });
        if (!match) {
            hint.textContent = "Hors des tranches d'âge de l'unité pour l'année "
                + targetYearLabel + '.';
            return;
        }

        var text = 'Branche prévue : ' + match.branch_label
            + ' — ' + match.year_in_branch + 'ᵉ année.';
        if (waitlistEnabled && match.tier && TIER_LABELS[match.tier]) {
            text += ' ' + TIER_LABELS[match.tier] + '.';
        }
        // textContent, not innerHTML: a branch label is configured text.
        hint.textContent = text;
    }

    birthDateInput.addEventListener('change', updateHint);
    // A browser that restored a previously typed date on a reload must
    // get its line back too.
    updateHint();
})();
