/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Réinscription (modules/registration/views/reenrollment.html.twig): the
// one thing this page needs a browser for — hiding the questions that
// only make sense for a child who is coming back.
//
// Deliberately nothing else. The « avec qui » fields have no
// autocompletion, no validation and no feedback: the form must behave
// exactly the same whether or not a typed name matches somebody, because
// anything else would tell one family who the other families' children
// are. There is no code here to accidentally break that.
//
// It is also not load-bearing: the server ignores the section and the
// friends for a departure regardless, so a browser with no JavaScript
// posts a form that is simply longer, never one that says something
// contradictory.
(function () {
    /** @type {NodeListOf<HTMLFormElement>} */
    var forms = document.querySelectorAll('.reenrollment-form');

    // A no-op on every other page of the site.
    if (!forms.length) {
        return;
    }

    forms.forEach(function (form) {
        var staying = form.querySelector('.reenrollment-when-staying');
        if (!staying) {
            return;
        }

        /** @type {NodeListOf<HTMLInputElement>} */
        var radios = form.querySelectorAll('.reenrollment-decision');

        function apply() {
            var chosen = Array.prototype.find.call(radios, function (radio) { return radio.checked; });
            /** @type {HTMLElement} */ (staying).hidden = chosen ? chosen.value === 'leaving' : false;
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', apply);
        });

        apply();
    });
})();
