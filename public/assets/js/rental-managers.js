/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Search-as-you-type over the unit's members, replacing the "add a manager"
 * select on « Espace chefs d'U > Locations ».
 *
 * Progressive enhancement, on the same shape as groups.js's own invite box:
 * the server always renders a real <select multiple name="manager_member_ids[]">
 * and this file hides it and drives the SAME field name from a search box, so
 * the form posts identically with or without JavaScript. Nothing here is a
 * boundary — the server re-checks every submitted id against the roster and
 * against the existing grants (RentalConfigController::saveManagers()).
 */
(function () {
    'use strict';

    var SEARCH_DEBOUNCE_MS = 250;
    var MINIMUM_QUERY_LENGTH = 2;

    /**
     * Build the row that represents one retained manager, matching exactly
     * what the server renders for an existing one — same field names, same
     * classes — so a page reload after saving looks like nothing moved.
     *
     * @param {number|string} memberId
     * @param {string} label
     * @returns {HTMLLIElement}
     */
    function buildRow(memberId, label) {
        var row = document.createElement('li');
        row.className = 'list-group-item d-flex flex-wrap align-items-center gap-3';
        row.dataset.memberId = String(memberId);

        var keepWrapper = document.createElement('div');
        keepWrapper.className = 'form-check m-0 me-auto';

        var keep = document.createElement('input');
        keep.className = 'form-check-input';
        keep.type = 'checkbox';
        keep.id = 'manager-' + memberId;
        keep.name = 'manager_member_ids[]';
        keep.value = String(memberId);
        keep.checked = true;

        var keepLabel = document.createElement('label');
        keepLabel.className = 'form-check-label';
        keepLabel.htmlFor = keep.id;
        // textContent, never innerHTML: this string came off the wire.
        keepLabel.textContent = label;

        keepWrapper.appendChild(keep);
        keepWrapper.appendChild(keepLabel);

        var contactWrapper = document.createElement('div');
        contactWrapper.className = 'form-check form-switch m-0';

        var contact = document.createElement('input');
        contact.className = 'form-check-input';
        contact.type = 'checkbox';
        contact.id = 'contact-' + memberId;
        contact.name = 'renter_contact_member_ids[]';
        contact.value = String(memberId);

        var contactLabel = document.createElement('label');
        contactLabel.className = 'form-check-label small';
        contactLabel.htmlFor = contact.id;
        contactLabel.textContent = 'Contact du locataire';

        contactWrapper.appendChild(contact);
        contactWrapper.appendChild(contactLabel);

        row.appendChild(keepWrapper);
        row.appendChild(contactWrapper);

        return row;
    }

    function initManagerSearch() {
        var form = /** @type {HTMLFormElement|null} */ (document.getElementById('rental-managers-form'));
        var select = /** @type {HTMLSelectElement|null} */ (document.getElementById('rental-manager-select'));
        var wrapper = document.getElementById('rental-manager-select-wrapper');
        var input = /** @type {HTMLInputElement|null} */ (document.getElementById('rental-manager-search'));
        var help = document.getElementById('rental-manager-search-help');
        var results = document.getElementById('rental-manager-results');
        var list = document.getElementById('rental-manager-selected-list');
        var empty = document.getElementById('rental-manager-empty');

        if (!form || !select || !wrapper || !input || !help || !results || !list) {
            return;
        }

        var searchUrl = form.dataset.searchUrl;
        if (!searchUrl) {
            return;
        }

        // Only now that everything resolved: swap the controls over. A
        // failure above leaves the plain select working, which is the whole
        // point of rendering it unconditionally.
        wrapper.classList.add('d-none');
        input.classList.remove('d-none');
        help.classList.remove('d-none');

        var searchTimer = null;

        function closeResults() {
            results.innerHTML = '';
            results.classList.add('d-none');
        }

        function refreshEmptyState() {
            if (empty) {
                empty.hidden = list.querySelectorAll('li').length > 0;
            }
        }

        /**
         * @param {number|string} memberId
         * @param {string} label
         */
        function addManager(memberId, label) {
            if (list.querySelector('[data-member-id="' + memberId + '"]')) {
                return;
            }

            list.appendChild(buildRow(memberId, label));

            // Drop the option from the hidden select too: it is still the
            // no-JS control, and leaving a now-listed member in it would
            // post the same id twice.
            var option = select.querySelector('option[value="' + memberId + '"]');
            if (option) {
                option.remove();
            }

            refreshEmptyState();
        }

        input.addEventListener('input', function () {
            var query = input.value.trim();

            if (searchTimer) {
                clearTimeout(searchTimer);
            }

            if (query.length < MINIMUM_QUERY_LENGTH) {
                closeResults();
                return;
            }

            searchTimer = setTimeout(function () {
                fetch(searchUrl + '?q=' + encodeURIComponent(query), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (response) {
                        return response.ok ? response.json() : [];
                    })
                    .then(function (matches) {
                        results.innerHTML = '';

                        if (!Array.isArray(matches) || matches.length === 0) {
                            var none = document.createElement('li');
                            none.className = 'list-group-item text-body-secondary fst-italic';
                            none.textContent = 'Aucun membre trouvé.';
                            results.appendChild(none);
                            results.classList.remove('d-none');
                            return;
                        }

                        matches.forEach(function (match) {
                            var item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'list-group-item list-group-item-action';
                            item.textContent = match.label;

                            if (match.sublabel) {
                                var sub = document.createElement('span');
                                sub.className = 'text-body-secondary small d-block';
                                sub.textContent = match.sublabel;
                                item.appendChild(sub);
                            }

                            item.addEventListener('click', function () {
                                addManager(match.id, match.label);
                                input.value = '';
                                closeResults();
                                input.focus();
                            });

                            results.appendChild(item);
                        });

                        results.classList.remove('d-none');
                    })
                    .catch(function () {
                        closeResults();
                    });
            }, SEARCH_DEBOUNCE_MS);
        });

        refreshEmptyState();
    }

    // Unconditionally on DOMContentLoaded, like cookie-consent.js and
    // settings.js: this file is loaded from the page's own scripts block,
    // so the event has not fired yet when it runs.
    document.addEventListener('DOMContentLoaded', initManagerSearch);

    // A classic <script src> already puts this top-level declaration on
    // window; the assignment changes nothing for the browser and exists so
    // an ES `import` in tests/js/ can reach the real implementation — the
    // precedent password-complexity.js sets.
    globalThis.initRentalManagerSearch = initManagerSearch;
})();
