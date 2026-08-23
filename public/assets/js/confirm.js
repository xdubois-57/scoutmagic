/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The site's one modal question — window.ScoutMagicConfirm.
//
// Destructive actions were confirmed by window.confirm() in 91 places: the
// delegated form handler in base.html.twig, thirty-one calls across
// public/assets/js/, and sixty more inside templates' inline scripts. A
// native box is the one surface of this site that is not this site — it
// renders the origin ("127.0.0.1:8000 dit :") above a French sentence,
// labels its buttons in the browser's language rather than the page's, and
// gives « Supprimer définitivement cet album » exactly the same two grey
// buttons as « Recréer les catégories par défaut ». On iOS it is a system
// sheet that steals the whole screen. Nothing about it says which action is
// dangerous, and nothing about it can.
//
// Two modes, one dialog:
//   ask(...)    → Promise<boolean>       « Supprimer ce reçu ? »
//   prompt(...) → Promise<string|null>   « URL du lien : » [______]
//
// prompt() exists so that the ban on native dialogs can be total. Two call
// sites needed a one-field answer rather than a yes/no — the rich-text
// toolbar's link URL, and the clipboard fallback that hands the visitor a
// pre-selected link to copy when navigator.clipboard is unavailable. Both
// were window.prompt(), which on mobile is the worst of the three native
// boxes.
//
// It renders the same modal the rest of the site uses (partials/modal.html.twig's
// conventions, down to the '{id}-title' id), in French, with the danger
// styling design.md §7.4 reserves for a destructive confirmation. On a
// confirmation, focus lands on « Annuler », never on the destructive
// button: a stray Enter after a mis-click must not delete anything. On a
// prompt, focus lands in the field, because there is nothing to destroy and
// the visitor has something to type.
//
// Loaded by base.html.twig before {% block scripts %} on every page, and
// precached by the service worker (public/sw.js) so an offline page still
// has it. IIFE exposing a global, same reasoning as api.js and toast.js.
(function () {
    var MODAL_ID = 'sm-confirm-modal';

    // The dialog currently on screen, if any: its element, its Bootstrap
    // instance when there is one, the resolve callback still owed an
    // answer, the value a dismissal resolves to, and the Escape listener to
    // unbind. Null whenever no dialog is open.
    var open = null;

    /**
     * Builds the modal element. Not cached between calls: a dialog is
     * short-lived, and rebuilding keeps the DOM free of a hidden modal on
     * every page that never asks anything.
     *
     * @param {{message: string, title: string, confirmLabel: string, cancelLabel: string,
     *          variant: string, input: ({value: string, placeholder: string, inputType: string,
     *          readonly: boolean}|null)}} opts
     * @returns {{root: HTMLElement, confirmBtn: HTMLButtonElement,
     *            cancelBtn: HTMLButtonElement, field: (HTMLInputElement|null)}}
     */
    function buildModal(opts) {
        var root = document.createElement('div');
        root.className = 'modal fade';
        root.id = MODAL_ID;
        root.tabIndex = -1;
        root.setAttribute('aria-labelledby', MODAL_ID + '-title');
        root.setAttribute('aria-describedby', MODAL_ID + '-body');

        var dialog = document.createElement('div');
        dialog.className = 'modal-dialog modal-dialog-centered';
        var content = document.createElement('div');
        content.className = 'modal-content';

        var header = document.createElement('div');
        header.className = 'modal-header';
        var title = document.createElement('h2');
        title.className = 'h5 modal-title';
        title.id = MODAL_ID + '-title';
        title.textContent = opts.title;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('aria-label', 'Fermer');
        header.appendChild(title);
        header.appendChild(close);

        var body = document.createElement('div');
        body.className = 'modal-body';

        var messageEl = document.createElement('div');
        messageEl.id = MODAL_ID + '-body';
        // Plain text, always: the message is a sentence, never markup.
        // Newlines in it stay visible as line breaks.
        messageEl.style.whiteSpace = 'pre-line';
        messageEl.textContent = opts.message;
        body.appendChild(messageEl);

        var field = null;
        if (opts.input) {
            field = document.createElement('input');
            field.type = opts.input.inputType;
            field.className = 'form-control mt-2';
            field.id = MODAL_ID + '-input';
            field.value = opts.input.value;
            field.placeholder = opts.input.placeholder;
            field.readOnly = opts.input.readonly;
            // The message is the field's label — there is no second
            // sentence to read, so pointing at it is more useful than an
            // invented one.
            field.setAttribute('aria-labelledby', MODAL_ID + '-body');
            body.appendChild(field);
        }

        var footer = document.createElement('div');
        footer.className = 'modal-footer';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-outline-secondary';
        cancelBtn.textContent = opts.cancelLabel;
        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'btn btn-' + opts.variant;
        confirmBtn.textContent = opts.confirmLabel;
        // « Annuler » first in the DOM so it is the first thing a screen
        // reader and a Tab press reach, matching design.md §7.4's ordering.
        footer.appendChild(cancelBtn);
        footer.appendChild(confirmBtn);

        content.appendChild(header);
        content.appendChild(body);
        content.appendChild(footer);
        dialog.appendChild(content);
        root.appendChild(dialog);

        close.addEventListener('click', dismiss);
        cancelBtn.addEventListener('click', dismiss);
        confirmBtn.addEventListener('click', function () {
            settle(field === null ? true : field.value);
        });
        if (field !== null) {
            field.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    settle(field.value);
                }
            });
        }

        return { root: root, confirmBtn: confirmBtn, cancelBtn: cancelBtn, field: field };
    }

    /**
     * Answers the open dialog once and tears it down. Calling it again
     * after the first answer is a no-op — Escape landing on the same frame
     * as a click must not resolve the promise twice.
     *
     * @param {boolean|string|null} result
     * @returns {void}
     */
    function settle(result) {
        if (open === null) {
            return;
        }
        var closing = open;
        open = null;

        document.removeEventListener('keydown', closing.onKeydown);
        if (closing.instance) {
            closing.root.addEventListener('hidden.bs.modal', function () { closing.root.remove(); });
            closing.instance.hide();
        } else {
            if (closing.backdrop) {
                closing.backdrop.remove();
            }
            closing.root.remove();
        }
        closing.resolve(result);
    }

    /**
     * Closes the dialog the way every "no" closes it — the close button,
     * « Annuler », Escape, the backdrop. What that resolves to depends on
     * the mode: false for a confirmation, null for a prompt.
     *
     * @returns {void}
     */
    function dismiss() {
        if (open !== null) {
            settle(open.cancelValue);
        }
    }

    /**
     * Opens the dialog and returns the promise for its answer.
     *
     * Opening a second dialog while one is on screen dismisses the first —
     * two stacked dialogs would leave the visitor unable to tell which
     * question they are answering.
     *
     * @param {{message: string, title: string, confirmLabel: string, cancelLabel: string,
     *          variant: string, input: ({value: string, placeholder: string, inputType: string,
     *          readonly: boolean, selectOnOpen: boolean}|null)}} opts
     * @param {boolean|null} cancelValue what a dismissal resolves to
     * @returns {Promise<boolean|string|null>}
     */
    function show(opts, cancelValue) {
        dismiss();

        var built = buildModal(opts);
        document.body.appendChild(built.root);
        var field = built.field;
        var input = opts.input;

        return new Promise(function (resolve) {
            function onKeydown(e) {
                if (e.key === 'Escape') {
                    dismiss();
                }
            }

            function takeFocus() {
                if (field === null) {
                    // Never the destructive button.
                    built.cancelBtn.focus();
                    return;
                }
                field.focus();
                if (input && input.selectOnOpen) {
                    field.select();
                }
            }

            var Modal = window.bootstrap && window.bootstrap.Modal;
            var instance = null;
            var backdrop = null;
            if (Modal) {
                instance = new Modal(built.root);
            } else {
                // No Bootstrap bundle (a stripped page, or jsdom): show the
                // same markup by hand rather than fall back to the native
                // box this file exists to remove.
                built.root.classList.add('show');
                built.root.style.display = 'block';
                backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop fade show';
                document.body.appendChild(backdrop);
            }

            open = {
                root: built.root,
                instance: instance,
                backdrop: backdrop,
                resolve: resolve,
                onKeydown: onKeydown,
                cancelValue: cancelValue
            };

            document.addEventListener('keydown', onKeydown);
            // Bootstrap dismisses on backdrop click for us; without it, the
            // hand-shown dialog wires the same gesture itself.
            if (!instance) {
                built.root.addEventListener('click', function (e) {
                    if (e.target === built.root) {
                        dismiss();
                    }
                });
            }

            if (instance) {
                built.root.addEventListener('hidden.bs.modal', dismiss);
                built.root.addEventListener('shown.bs.modal', takeFocus);
                instance.show();
            } else {
                takeFocus();
            }
        });
    }

    /**
     * Asks the visitor to confirm, and resolves to their answer.
     *
     * @param {string|{message: string, title?: string, confirmLabel?: string,
     *                 cancelLabel?: string, variant?: 'danger'|'primary'}} input
     *        A bare string is the message, which is the common case.
     * @returns {Promise<boolean>} true when confirmed, false on cancel,
     *          Escape, backdrop click or the close button.
     */
    function ask(input) {
        /** @type {{message?: string, title?: string, confirmLabel?: string, cancelLabel?: string, variant?: string}} */
        var raw = typeof input === 'string' ? { message: input } : (input || {});

        return /** @type {Promise<boolean>} */ (show({
            message: raw.message || '',
            title: raw.title || 'Confirmation',
            confirmLabel: raw.confirmLabel || 'Confirmer',
            cancelLabel: raw.cancelLabel || 'Annuler',
            // Destructive by default: data-confirm exists for POSTs that
            // delete, remove, refuse or revoke (design.md §7.5), which is
            // also what nearly every call site here is doing.
            variant: raw.variant === 'primary' ? 'primary' : 'danger',
            input: null
        }, false));
    }

    /**
     * Asks the visitor for one short value, and resolves to what they
     * typed — or null if they dismissed the dialog. The replacement for
     * window.prompt().
     *
     * @param {string|{message: string, title?: string, value?: string, placeholder?: string,
     *                 inputType?: string, readonly?: boolean, selectOnOpen?: boolean,
     *                 confirmLabel?: string, cancelLabel?: string, variant?: 'danger'|'primary'}} input
     * @returns {Promise<string|null>} the value (possibly an empty string)
     *          when confirmed, null on cancel, Escape, backdrop click or
     *          the close button.
     */
    function prompt(input) {
        /** @type {{message?: string, title?: string, value?: string, placeholder?: string,
         *          inputType?: string, readonly?: boolean, selectOnOpen?: boolean,
         *          confirmLabel?: string, cancelLabel?: string, variant?: string}} */
        var raw = typeof input === 'string' ? { message: input } : (input || {});
        var value = raw.value || '';

        return /** @type {Promise<string|null>} */ (show({
            message: raw.message || '',
            title: raw.title || 'Saisie',
            confirmLabel: raw.confirmLabel || 'Valider',
            cancelLabel: raw.cancelLabel || 'Annuler',
            // A prompt destroys nothing; danger styling would be a lie.
            variant: raw.variant === 'danger' ? 'danger' : 'primary',
            input: {
                value: value,
                placeholder: raw.placeholder || '',
                inputType: raw.inputType || 'text',
                readonly: raw.readonly === true,
                // A pre-filled value is there to be replaced, or — in the
                // clipboard fallback — to be copied. Selecting it serves
                // both.
                selectOnOpen: raw.selectOnOpen !== false && value !== ''
            }
        }, null));
    }

    window.ScoutMagicConfirm = { ask: ask, prompt: prompt };
})();
