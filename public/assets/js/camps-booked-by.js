/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Réservation faite par » on the camps stay form
// (modules/camps/views/camp_form.html.twig).
//
// The field is a plain text input and stays one: a camp booked eight years
// ago was booked by somebody who has left, and a picker that refused them
// would refuse the normal case. What this adds is the other half — when the
// name typed IS one of this year's staff, the hidden `booked_by_member_id`
// is filled, so the stay points at a real member instead of at a string
// somebody spelled two ways across two camps.
//
// The rule is symmetric and that is the point: the id is set on an exact
// match and CLEARED on anything else. Without the clearing half, editing
// "Thomas Dupont" into "le père de Thomas" would leave Thomas attached to a
// booking he did not make — a wrong link nobody can see, which is worse than
// no link at all.
//
// The list arrives in a <script type="application/json"> island rather than
// through a search endpoint: opening one would mean a member search for a
// role that has none, and the staff of a unit is a list of thirty names.
(function () {
    /**
     * The member id whose name matches, or '' when none does.
     *
     * Comparison is trimmed, case-insensitive and whitespace-collapsed —
     * "  thomas   dupont " is the same person as "Thomas Dupont", and a
     * chief who picked the name from the suggestion list must not lose the
     * link to a trailing space. An ambiguous name (two members spelled
     * identically) matches NOTHING: attaching the booking to whichever of
     * the two sorted first would be a guess the reader cannot see.
     *
     * @param {Array<{id: number, name: string}>} members
     * @param {string} typed
     * @returns {string} the member id as a string, '' when there is no single match
     */
    function matchMemberId(members, typed) {
        var needle = normalise(typed);
        if (needle === '') {
            return '';
        }

        var found = '';
        for (const member of members) {
            if (normalise(member.name) !== needle) {
                continue;
            }
            if (found !== '') {
                return '';
            }
            found = String(member.id);
        }

        return found;
    }

    /**
     * @param {string} value
     * @returns {string}
     */
    function normalise(value) {
        return String(value == null ? '' : value)
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    /**
     * Wires one input/hidden pair against one list of members, and returns
     * the datalist it built so the caller can place it.
     *
     * @param {HTMLInputElement} input
     * @param {HTMLInputElement} hidden
     * @param {Array<{id: number, name: string}>} members
     * @returns {HTMLDataListElement}
     */
    function bind(input, hidden, members) {
        var list = document.createElement('datalist');
        list.id = 'camps-booked-by-list';
        members.forEach(function (member) {
            var option = document.createElement('option');
            // textContent, never innerHTML: these are member names.
            option.value = member.name;
            list.appendChild(option);
        });
        input.setAttribute('list', list.id);

        function sync() {
            hidden.value = matchMemberId(members, input.value);
        }

        input.addEventListener('input', sync);
        input.addEventListener('change', sync);

        return list;
    }

    window.ScoutMagicCampsBookedBy = { matchMemberId: matchMemberId, bind: bind };

    var input = /** @type {HTMLInputElement|null} */ (document.getElementById('booked-by'));
    var hidden = /** @type {HTMLInputElement|null} */ (document.getElementById('booked-by-member-id'));
    if (!input || !hidden) {
        return;
    }

    var members = window.ScoutMagicApi.pageData('camps-booked-by-members');
    if (!Array.isArray(members) || members.length === 0) {
        // No suggestions to offer — the field is a plain text input, which
        // is exactly what it was before this script existed.
        return;
    }

    input.parentNode.appendChild(bind(input, hidden, members));
})();
