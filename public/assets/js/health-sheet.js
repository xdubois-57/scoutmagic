/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The health sheet (modules/official_documents/views/health_sheet.html.twig,
// issue #1346): shows the boxes that depend on an OUI / NON answer only while
// that answer is chosen.
//
// A dependent block carries `data-reveal-when="<field name>=<value>"`. It is
// hidden unless the named field currently holds that value, and follows it as
// it changes. Three rules keep this honest:
//
//  - WITHOUT this script every block stays visible: nothing is hidden by the
//    markup itself, so a browser that cannot run it loses a convenience and
//    never a field.
//  - Hiding never erases. A hidden field is still in the form and still sent,
//    so « Oui → Non → Oui » finds what was typed. What reaches the paper is
//    decided by the SERVER from the saved choices (HealthSheetFilling), never
//    by what this page happened to show.
//  - A hidden field is skipped by the browser's own validation and focus
//    order (the `hidden` attribute), and shown again the moment its answer is.
(function () {
    /**
     * @param {HTMLElement} block
     * @returns {{name: string, value: string}|null}
     */
    function condition(block) {
        var rule = block.dataset.revealWhen || '';
        var cut = rule.indexOf('=');
        if (cut < 1) {
            return null;
        }

        return { name: rule.slice(0, cut), value: rule.slice(cut + 1) };
    }

    /**
     * @param {ParentNode} root
     * @param {string} name
     * @returns {HTMLSelectElement|HTMLInputElement|null}
     */
    function control(root, name) {
        var found = root.querySelector('[name="' + name + '"]');

        return found instanceof HTMLSelectElement || found instanceof HTMLInputElement ? found : null;
    }

    /** @param {ParentNode} root */
    function sync(root) {
        root.querySelectorAll('[data-reveal-when]').forEach(function (element) {
            if (!(element instanceof HTMLElement)) {
                return;
            }
            var rule = condition(element);
            var source = rule ? control(root, rule.name) : null;
            // No such control: nothing to follow, so nothing is hidden.
            if (!rule || !source) {
                element.hidden = false;
                return;
            }
            element.hidden = source.value !== rule.value;
        });
    }

    document.querySelectorAll('form').forEach(function (form) {
        if (!form.querySelector('[data-reveal-when]')) {
            return;
        }
        form.addEventListener('change', function () {
            sync(form);
        });
        sync(form);
    });
})();
