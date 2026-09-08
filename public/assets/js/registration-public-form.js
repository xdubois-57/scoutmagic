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

    // The branches that have more than one visible, active section — the
    // only ones where « avec qui » is a real question. Decided on the
    // server (Controller\PublicRegistrationController), never counted
    // here: the browser has no section list and must not grow one.
    var friendWishBranchLabels = data.friendWishBranchLabels || [];
    var friendWishesZone = document.getElementById('friend-wishes-zone');

    // « Section souhaitée ». Every <option> the template writes carries
    // the branch it belongs to (`data-branch-id`), so the list can be
    // narrowed to the branch the birth date lands in without asking the
    // server anything — the same reasoning as the hint above.
    var desiredSection = /** @type {HTMLSelectElement|null} */ (
        document.getElementById('desired_section_id')
    );

    /**
     * Keep only the sections of the branch the child would join, and put
     * the whole list back when no branch is known.
     *
     * WHY THIS IS NOT COSMETIC. `Controller\PublicRegistrationController::
     * resolveDesiredSectionId()` refuses a section outside the branch the
     * birth date falls into and stores « Aucune préférence » instead —
     * the right guard against a forged POST, and silent. So a family who
     * picked a section from the full list, in good faith, had their
     * choice dropped with nothing said. Narrowing the list here is what
     * makes that branch unreachable for anybody filling the form
     * honestly (issue #264).
     *
     * `hidden` AND `disabled`, not one of them: `hidden` is what removes
     * an option from the dropdown, and Safari — the browser this was
     * reported on — has shipped versions that ignore it on an <option>.
     * A disabled option cannot be picked in any of them.
     *
     * A selection that has just become invalid is reset rather than left
     * standing: an <option> that is hidden and still selected shows the
     * family a section the server is about to refuse.
     *
     * @param {number|null} branchId
     */
    function updateDesiredSections(branchId) {
        if (!desiredSection) return;

        var options = desiredSection.options;

        for (var i = 0; i < options.length; i++) {
            var option = options[i];
            var optionBranch = option.getAttribute('data-branch-id');

            // « Aucune préférence » carries no branch and is always on
            // offer: not choosing is a valid answer at every age.
            var offered = branchId === null
                || optionBranch === null
                || Number(optionBranch) === branchId;

            option.hidden = !offered;
            option.disabled = !offered;

            if (!offered && option.selected) {
                desiredSection.value = '';
            }
        }
    }

    /**
     * Show the « avec qui » fields for a branch that has a choice to
     * offer, hide them otherwise.
     *
     * Hidden rather than removed, and never cleared: a family who typed
     * two names and then corrected the birth date should not silently
     * lose them, and the server ignores the field for a branch that has
     * one section anyway.
     *
     * @param {string|null} branchLabel
     */
    function updateFriendWishes(branchLabel) {
        if (!friendWishesZone) return;

        var offers = branchLabel !== null && friendWishBranchLabels.includes(branchLabel);
        friendWishesZone.classList.toggle('d-none', !offers);
    }

    function updateHint() {
        var year = Number.parseInt((birthDateInput.value || '').slice(0, 4), 10);
        if (!year || String(year).length !== 4) {
            hint.textContent = '';
            updateFriendWishes(null);
            updateDesiredSections(null);
            return;
        }

        var match = slots.find(function (slot) {
            return slot.birth_year === year;
        });
        if (!match) {
            hint.textContent = "Hors des tranches d'âge de l'unité pour l'année "
                + targetYearLabel + '.';
            updateFriendWishes(null);
            // The whole list comes back rather than emptying: a birth year
            // outside every branch is usually a typo being corrected, and
            // a dropdown that has gone blank reads as a broken page.
            updateDesiredSections(null);
            return;
        }

        var text = 'Branche prévue : ' + match.branch_label
            + ' — ' + match.year_in_branch + 'ᵉ année.';
        if (waitlistEnabled && match.tier && TIER_LABELS[match.tier]) {
            text += ' ' + TIER_LABELS[match.tier] + '.';
        }
        // textContent, not innerHTML: a branch label is configured text.
        hint.textContent = text;
        updateFriendWishes(match.branch_label);
        updateDesiredSections(
            typeof match.age_branch_id === 'number' ? match.age_branch_id : null
        );
    }

    birthDateInput.addEventListener('change', updateHint);
    // A browser that restored a previously typed date on a reload must
    // get its line back too.
    updateHint();
})();
