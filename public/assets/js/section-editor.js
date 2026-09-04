/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The « Modifier » dialogs of a settings screen (design.md §1.9, §7.4).
//
// A page made of six independent forms shows six `btn-primary`, which is
// six ways of saying « the main action is here » on one screen. The cure
// the site already uses on Réglages is to make each section a READ card
// with a « Modifier » that opens a dialog: the values are text until
// somebody asks to change them, and the one primary belongs to whichever
// dialog is open.
//
// Bootstrap opens the dialog itself, through `data-bs-toggle="modal"`, so
// this file exists for the two things it does NOT do:
//
//  - **Focus the first field.** Bootstrap focuses the dialog element, so a
//    keyboard visitor lands on the frame and has to tab into the form. A
//    settings dialog is a form and nothing else; its first field is where
//    the visitor is going.
//  - **Open the dialog a link names.** « Ce bien n'a encore aucun tarif »
//    links straight at the tariff editor, and a plain `#tarification-edit`
//    anchor would scroll a hidden `.modal` into view — that is, scroll to
//    nothing at all.
//
// Usage: mark each dialog `data-section-editor` and load this file. It is
// inert on a page that has none.

(function () {
    /**
     * Focuses the first control a visitor would actually type in.
     *
     * Hidden inputs (the CSRF token, the asset id) and buttons are not
     * fields, and a disabled control cannot be focused at all. Neither can
     * a control inside a hidden container — which is not a hypothetical:
     * the managers dialog ships the plain `<select>` a no-JavaScript
     * visitor gets and `rental-managers.js` hides it in favour of a search
     * box, so the first control in the markup is precisely the one that
     * must not be focused.
     *
     * Rather than guess at visibility — every cheap test for it
     * (`offsetParent`, `getClientRects()`) answers "hidden" in a DOM with
     * no layout, which is every unit test — this asks the browser: focus
     * a candidate, and keep going if the focus did not land on it.
     *
     * @param {Element|null} modal
     * @returns {void}
     */
    function focusFirstField(modal) {
        if (!modal) {
            return;
        }

        var controls = modal.querySelectorAll('input, select, textarea');
        for (const node of controls) {
            const control = /** @type {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} */ (node);
            if (control.type === 'hidden' || control.disabled || control.hasAttribute('readonly')) {
                continue;
            }

            control.focus();
            if (document.activeElement === control) {
                return;
            }
        }
    }

    /**
     * Opens the section dialog a URL fragment names, if it names one.
     *
     * Deliberately narrow: only an element that is itself a section
     * editor is opened, so `#tarification` (the read card) keeps its
     * ordinary anchor behaviour and `#anything-else` is left alone.
     *
     * @param {string} hash The location fragment, with or without its `#`.
     * @param {ParentNode & {getElementById?: (id: string) => Element|null}} [root]
     * @returns {boolean} whether a dialog was opened.
     */
    function openFromHash(hash, root) {
        var id = String(hash || '').replace(/^#/, '');
        if (id === '' || typeof bootstrap === 'undefined' || bootstrap.Modal === undefined) {
            return false;
        }

        // By id rather than by selector: a fragment is an id, and a raw
        // one dropped into querySelector() throws on anything a CSS
        // identifier does not allow.
        var scope = root && typeof root.getElementById === 'function' ? root : document;
        var target = /** @type {HTMLElement|null} */ (scope.getElementById(id));
        if (target === null || target.dataset.sectionEditor === undefined) {
            return false;
        }

        bootstrap.Modal.getOrCreateInstance(target).show();

        return true;
    }

    /**
     * @param {ParentNode} [root]
     * @returns {void}
     */
    function bind(root) {
        var scope = root || document;
        var dialogs = scope.querySelectorAll('[data-section-editor]');

        for (const dialog of dialogs) {
            dialog.addEventListener('shown.bs.modal', function () {
                focusFirstField(dialog);
            });
        }
    }

    window.ScoutMagicSectionEditor = {
        bind: bind,
        focusFirstField: focusFirstField,
        openFromHash: openFromHash
    };

    bind(document);
    openFromHash(window.location.hash, document);
})();
