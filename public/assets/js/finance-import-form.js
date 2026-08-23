/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Bank-statement import form (modules/finance/views/import/form.html.twig):
// the opening balance is required for an account's FIRST import and
// optional after it. Extracted from the template's inline <script> so
// the Vitest suite can exercise the production code directly
// (tests/js/finance-import-form.test.js).
//
// Why it matters enough to test: on a first import there is nothing to
// recompute the balance FROM, so an empty field would silently anchor
// the account's whole history at zero. Which accounts are in that state
// is server-knowledge, carried per option in `data-first-import`.
(function () {
    var accountSelect = /** @type {HTMLSelectElement|null} */ (
        document.getElementById('import-account')
    );
    var balanceInput = /** @type {HTMLInputElement|null} */ (
        document.getElementById('import-balance')
    );
    var balanceHint = document.getElementById('import-balance-hint');

    // A no-op on every other page of the site.
    if (!accountSelect || !balanceInput || !balanceHint) {
        return;
    }

    var REQUIRED_HINT = 'Premier import pour ce compte : le solde est obligatoire.';
    var OPTIONAL_HINT = 'Facultatif — laissez vide pour recalculer le solde'
        + ' automatiquement depuis les mouvements.';

    function updateBalanceRequirement() {
        var option = accountSelect.options[accountSelect.selectedIndex];
        var isFirstImport = !!option && option.dataset.firstImport === '1';

        balanceInput.required = isFirstImport;
        balanceHint.textContent = isFirstImport ? REQUIRED_HINT : OPTIONAL_HINT;
    }

    accountSelect.addEventListener('change', updateBalanceRequirement);
    // The form opens on whichever account is selected — the hint has to
    // describe that one, not the state of an empty select.
    updateBalanceRequirement();
})();
