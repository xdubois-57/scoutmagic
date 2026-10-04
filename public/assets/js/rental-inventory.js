/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * « État des lieux » (#708, IT-17): each line saves as it is typed, without
 * reloading the page, and « = » takes the line's reference in one gesture.
 *
 * Each line is a form of its own posting to the same endpoint a browser
 * without JavaScript uses — `X-Requested-With` is the whole contract, the
 * controller then answers `{success, type, message}` instead of
 * redirecting (RentalManagementController::bookingAction()). The answer is
 * said beside the line, where the eye already is, never as a toast over the
 * next field.
 *
 * Delegated on the document, because rental-booking.js re-renders the
 * whole inventory panel after a meter reading or an incident: a listener
 * bound to the old rows would be gone with them.
 *
 * The validation's question counts the lines still empty AS THEY ARE NOW —
 * the count the page was rendered with is stale the moment a line is
 * typed.
 */

/**
 * The validation's question, with the lines still empty said at the end.
 *
 * @param {string} base
 * @param {number} unchecked
 * @returns {string}
 */
export function validationQuestion(base, unchecked) {
    if (unchecked <= 0) {
        return base;
    }

    return base + ' ' + unchecked + ' élément' + (unchecked > 1 ? 's' : '') + ' sans valeur : le PDF '
        + (unchecked > 1 ? 'les' : 'le') + ' marquera « non vérifié ».';
}

/**
 * @param {ParentNode} root
 * @returns {number}
 */
export function countUnchecked(root) {
    let count = 0;
    root.querySelectorAll('[data-inventory-value]').forEach((field) => {
        if (/** @type {HTMLInputElement|HTMLSelectElement} */ (field).value.trim() === '') {
            count++;
        }
    });

    return count;
}

/**
 * @param {Document} doc
 * @returns {void}
 */
function refreshQuestion(doc) {
    const form = /** @type {HTMLFormElement|null} */ (doc.querySelector('form[data-inventory-validate]'));
    if (form === null) {
        return;
    }
    form.dataset.confirm = validationQuestion(form.dataset.confirmBase || '', countUnchecked(doc));
}

/**
 * The save each line has in flight, if any.
 *
 * @type {WeakMap<HTMLFormElement, Promise<void>>}
 */
const inFlight = new WeakMap();

/**
 * Posts the line as it is NOW — read when the request leaves, not when it
 * was queued, so a save queued behind another carries the latest value.
 *
 * @param {HTMLFormElement} form
 * @returns {Promise<{ ok: boolean, message: string }>}
 */
async function post(form) {
    let response;
    try {
        response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
    } catch {
        return { ok: false, message: 'Erreur réseau : rien n\'a été enregistré.' };
    }

    /** @type {{ success?: boolean, message?: string, error?: string } | null} */
    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }
    const ok = data !== null && data.success === true;

    return { ok, message: ok ? 'Enregistré' : ((data && (data.message || data.error)) || 'Non enregistré') };
}

/**
 * Posts one line and says the answer beside it.
 *
 * One request per line at a time: a note typed while the value is still
 * saving waits for it, so two answers can never arrive out of order and
 * leave the older one on screen. Only the last save of a run speaks.
 *
 * While a save is in flight the line carries `data-booking-keep`:
 * rental-booking.js keeps it as it is when it re-renders the panel, since
 * the render it fetched may predate this save. A save that ends while such
 * a re-render is still on its way keeps the mark until it lands — the page
 * says busy meanwhile — or the older render would put the old value back.
 *
 * @param {HTMLFormElement} form
 * @returns {Promise<void>}
 */
export function saveLine(form) {
    const status = /** @type {HTMLOutputElement|null} */ (form.querySelector('[data-inventory-status]'));
    if (status) {
        status.textContent = 'Enregistrement…';
        status.className = 'small d-block text-body-secondary';
    }
    form.dataset.bookingKeep = '';

    const previous = inFlight.get(form) ?? Promise.resolve();
    /** @type {Promise<void>} */
    const run = previous.then(() => post(form)).then(({ ok, message }) => {
        if (inFlight.get(form) !== run) {
            return;
        }
        inFlight.delete(form);
        if (form.closest('[aria-busy="true"]') === null) {
            delete form.dataset.bookingKeep;
        }
        if (status) {
            status.textContent = message;
            status.className = 'small d-block ' + (ok ? 'text-success' : 'text-danger');
        }
        form.querySelector('[data-inventory-value]')?.classList.toggle('is-invalid', !ok);
        refreshQuestion(form.ownerDocument);
    });
    inFlight.set(form, run);

    return run;
}

/**
 * @param {Document} doc
 * @returns {void}
 */
export function wire(doc) {
    // The save buttons are for a browser without JavaScript. Hidden by a
    // class on <html> (app.css), not button by button: rental-booking.js
    // re-renders the panel after a meter reading, and the buttons it brings
    // back would be visible again.
    doc.documentElement.classList.add('inventory-autosave');

    // Once per document: a second wiring would save every line twice.
    if (/** @type {any} */ (doc).scoutMagicInventoryWired === true) {
        return;
    }
    /** @type {any} */ (doc).scoutMagicInventoryWired = true;

    // The re-render that overlapped a save has landed: the lines it kept
    // and that are no longer saving go back to being re-rendered.
    doc.addEventListener('rental-booking:refreshed', () => {
        doc.querySelectorAll('form[data-inventory-line][data-booking-keep]').forEach((element) => {
            const form = /** @type {HTMLFormElement} */ (element);
            if (!inFlight.has(form)) {
                delete form.dataset.bookingKeep;
            }
        });
    });

    doc.addEventListener('change', (event) => {
        const form = /** @type {HTMLElement} */ (event.target).closest?.('form[data-inventory-line]');
        if (form) {
            void saveLine(/** @type {HTMLFormElement} */ (form));
        }
    });

    doc.addEventListener('click', (event) => {
        const button = /** @type {HTMLElement} */ (event.target).closest?.('[data-inventory-copy]');
        if (!button) {
            return;
        }
        const form = /** @type {HTMLFormElement|null} */ (button.closest('form[data-inventory-line]'));
        const field = /** @type {HTMLInputElement|HTMLSelectElement|null} */ (
            form ? form.querySelector('[data-inventory-value]') : null
        );
        if (form === null || field === null) {
            return;
        }
        field.value = /** @type {HTMLElement} */ (button).dataset.inventoryCopy || '';
        void saveLine(form);
    });

    doc.addEventListener('submit', (event) => {
        const form = /** @type {HTMLElement} */ (event.target).closest?.('form[data-inventory-line]');
        if (form) {
            event.preventDefault();
            void saveLine(/** @type {HTMLFormElement} */ (form));
        }
    });
}

wire(document);
