/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// One booking's file (modules/rental/views/management/booking.html.twig):
// every button on it acts without reloading the page.
//
// The page carries sixteen POST forms — send the contract, cash a deposit,
// change the status, add a price line, record the security deposit's
// return… — and each of them used to be a full navigation: post, redirect,
// re-download the page, lose the scroll position, and land back at the top
// of a long file with a green banner. Pressing « Envoyer » on the contract
// meant losing your place to be told it was sent.
//
// The exchange is deliberately boring:
//
//   1. Submit is intercepted here and the form is posted with fetch, as the
//      very same multipart/urlencoded body the browser would have sent (a
//      `FormData` of the form itself), plus `X-Requested-With`. That header
//      is the whole server-side contract: RentalManagementController::
//      bookingAction() answers `{success, type, message}` instead of
//      redirecting. Every one of those sixteen endpoints already went
//      through that one method, so nothing else changed server-side.
//   2. The flash the handler set comes back in that JSON and becomes a
//      toast.
//   3. The page then re-fetches ITSELF and swaps the contents of every
//      `[data-booking-panel]` wrapper. Not "patch what I think changed":
//      one action moves several panels at once — sending the contract also
//      ticks a milestone and writes a history line — and a client-side
//      guess about which is exactly how a page starts lying. Re-rendering
//      from the same Twig template keeps one renderer and no second
//      description of what the page says.
//
// Without JavaScript every form still posts, redirects and renders the
// flash the way it always did: nothing here is load-bearing for the action
// itself.
(function () {
    var root = /** @type {HTMLElement|null} */ (document.querySelector('[data-rental-booking]'));
    if (!root) return;

    var api = window.ScoutMagicApi;

    /**
     * The flash the server would have rendered on the page it used to
     * redirect to, said here instead. The three flash types map onto the
     * toast variants of the same name (design.md §7.5); anything else the
     * server ever sends reads as neutral rather than as a failure.
     *
     * @param {string} message
     * @param {string} type flash type: success | error | warning
     * @returns {void}
     */
    function toast(message, type) {
        if (!message) return;
        var variant = /** @type {'success'|'error'|'warning'|'info'} */ (
            (type === 'error' || type === 'warning' || type === 'success') ? type : 'info'
        );
        window.ScoutMagicToast.show(message, { variant: variant });
    }

    /**
     * Re-render every panel from a fresh copy of this same page.
     *
     * The wrappers are always present in the template even when what they
     * hold is conditional, so a card that has just appeared (or gone) swaps
     * like any other — matching on the wrapper and replacing its contents,
     * never trying to find a card that is not there any more.
     *
     * @returns {Promise<void>}
     */
    function refreshPanels() {
        return fetch(window.location.href, {
            headers: { Accept: 'text/html' },
            // Re-reading our own page: it must be the state the server has
            // right now, never whatever the browser kept from the load
            // before the action.
            cache: 'no-store'
        }).then(function (response) {
            return response.ok ? response.text() : null;
        }).then(function (html) {
            if (html === null) return;

            var fresh = new DOMParser().parseFromString(html, 'text/html');
            root.querySelectorAll('[data-booking-panel]').forEach(function (panel) {
                var name = /** @type {HTMLElement} */ (panel).dataset.bookingPanel;
                var replacement = fresh.querySelector('[data-booking-panel="' + name + '"]');
                if (replacement) {
                    panel.innerHTML = replacement.innerHTML;
                }
            });

            // A drop zone in a swapped panel is a brand-new element with no
            // listeners; its own binder is idempotent, so re-running it
            // wires the new one and leaves the rest alone.
            if (window.ScoutMagicUploadDropZone) {
                window.ScoutMagicUploadDropZone.bind(document);
            }
        }).catch(function () {
            // A refresh that fails leaves the panels as they were: the
            // action itself already succeeded and was reported, and a page
            // wiped by a network hiccup would be the worse answer.
        });
    }

    /**
     * @param {HTMLFormElement} form
     * @param {HTMLElement|null} submitter the button that was pressed, whose
     *   name/value the browser would have posted and fetch would not
     * @returns {void}
     */
    function submitAsync(form, submitter) {
        var payload = new FormData(form);
        if (submitter && submitter.getAttribute('name')) {
            payload.append(
                /** @type {string} */ (submitter.getAttribute('name')),
                submitter.getAttribute('value') || ''
            );
        }

        var button = /** @type {HTMLButtonElement|null} */ (
            (submitter && submitter.tagName === 'BUTTON')
                ? submitter
                : form.querySelector('button[type="submit"]')
        );

        api.withDisabled(button, function () {
            return fetch(form.action, {
                method: 'POST',
                body: payload,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () { return null; });
            }).then(function (data) {
                // Cleared only now, once the submit event that carried it
                // is long over: confirm.js sets this the instant before it
                // replays the form, and clearing it any earlier — inside
                // the very dispatch it replayed — makes its own delegated
                // handler read the replay as a fresh submit and ask the
                // question again, forever.
                delete form.dataset.confirmed;

                if (data === null) {
                    toast('L\'action n\'a pas abouti. Rechargez la page et réessayez.', 'error');
                    // Explicit rather than a bare `return`: the other path
                    // returns refreshPanels()'s promise, and mixing the two
                    // trips the JS typecheck's noImplicitReturns.
                    return undefined;
                }
                toast(data.message || data.error || '', data.success ? (data.type || 'success') : 'error');

                return refreshPanels();
            }).catch(function () {
                toast('Erreur réseau. Rien n\'a été enregistré.', 'error');
            });
        });
    }

    root.addEventListener('submit', function (e) {
        // confirm.js (delegated on `document`, design.md §7.5) stops the
        // first submit of a form carrying data-confirm and replays it with
        // requestSubmit() once the visitor agrees — which fires this
        // listener again. Standing aside while the event is already
        // prevented is what keeps the confirmation in front of the action
        // instead of racing it.
        if (e.defaultPrevented) return;

        var target = /** @type {HTMLElement|null} */ (e.target);
        var form = /** @type {HTMLFormElement|null} */ (target === null ? null : target.closest('form'));
        if (form === null || form.dataset.async === 'off') return;

        // A form that still has its question to ask belongs to confirm.js
        // for one more round: it will prevent this submit, ask, and replay
        // the form with `confirmed` set — and that replay is the one this
        // file acts on. Checked explicitly rather than relying on listener
        // order, since this listener sits on a descendant of `document` and
        // therefore runs BEFORE the delegated one, not after.
        if (form.dataset.confirm && form.dataset.confirmed !== '1') return;

        e.preventDefault();
        submitAsync(form, /** @type {HTMLElement|null} */ (/** @type {any} */ (e).submitter || null));
    });
})();
