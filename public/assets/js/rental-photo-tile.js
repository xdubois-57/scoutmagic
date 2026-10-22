/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The « + Photo » tile of an incident and of a meter reading (#989, lot B):
 * a menu of two choices — « Prendre une photo » opens the camera
 * (`capture="environment"`), « Choisir dans la galerie » the phone's photos
 * — each photo reduced in the browser (photo-reduce.js), shown as a
 * thumbnail before the tile, and sent with the form it sits in.
 *
 * The markup is the template's (`views/management/_photo_tile.html.twig`):
 * the tile, its menu (a Bootstrap dropdown), the two pickers, and a third
 * file input, the CARRIER, which is the only one with a `name`. What the
 * pickers return is reduced, added to the photos waiting to go, and the
 * carrier's files are replaced by them through `DataTransfer` — on
 * `change`, not on `submit`: rental-booking.js posts the form as
 * `new FormData(form)` and never fires anything this script could wait
 * on.
 *
 * Without JavaScript the carrier is a plain visible file field and the
 * tile is hidden: `photo-tile-ready` on <html> swaps the two (app.css),
 * the same way rental-inventory.js hides its save buttons. A browser that
 * cannot build a `DataTransfer` keeps that plain field too.
 *
 * The limit is the server's (5 photos per incident, 1 per reading) and is
 * said and held here as well: the tile disappears once the photos already
 * attached and those waiting reach it, and a gallery pick of more than
 * the room left keeps the first ones and says how many were left out.
 *
 * Delegated on the document, because rental-booking.js re-renders the
 * panel after every action: a listener bound to a tile would be gone with
 * it. A tile's waiting photos live in a WeakMap keyed by the element, so a
 * re-rendered tile starts empty — which is right, its form was sent.
 *
 * Thumbnails are built with createElement and an object URL of the File
 * itself, never with innerHTML: a file name is the visitor's text.
 */

/** @typedef {{ files: File[], busy: boolean }} TileState */

/** @type {WeakMap<HTMLElement, TileState>} */
const states = new WeakMap();

/**
 * The object URL each waiting thumbnail shows, revoked once it is gone.
 *
 * @type {Map<HTMLImageElement, string>}
 */
const previews = new Map();

/** The two pickers a menu entry may name — anything else is ignored. */
const SOURCES = new Set(['camera', 'gallery']);

/**
 * @param {HTMLElement} tile
 * @returns {TileState}
 */
export function stateOf(tile) {
    let state = states.get(tile);
    if (state === undefined) {
        state = { files: [], busy: false };
        states.set(tile, state);
    }

    return state;
}

/**
 * @param {HTMLElement} tile
 * @param {string} name
 * @returns {number}
 */
function count(tile, name) {
    const value = Number.parseInt(tile.dataset[name] || '0', 10);

    return Number.isFinite(value) && value > 0 ? value : 0;
}

/**
 * How many more photos this tile takes: its limit, less those already
 * attached on the server and those waiting to be sent.
 *
 * @param {HTMLElement} tile
 * @returns {number}
 */
export function remaining(tile) {
    return Math.max(0, count(tile, 'photoMax') - count(tile, 'photoAttached') - stateOf(tile).files.length);
}

/**
 * What a pick that did not fit says.
 *
 * @param {number} max
 * @param {number} dropped
 * @returns {string}
 */
export function droppedMessage(max, dropped) {
    if (dropped <= 0) {
        return '';
    }
    if (max <= 1) {
        return 'Une seule photo ici : les autres n\'ont pas été gardées.';
    }

    return max + ' photos au plus : ' + dropped
        + (dropped > 1 ? ' n\'ont pas été gardées.' : ' n\'a pas été gardée.');
}

/**
 * @param {HTMLElement} tile
 * @param {string} message
 * @returns {void}
 */
function say(tile, message) {
    const status = tile.querySelector('[data-photo-status]');
    if (status) {
        status.textContent = message;
    }
}

/**
 * Locks the form's submit buttons while photos are being reduced: a form
 * sent then would leave without them.
 *
 * @param {HTMLElement} tile
 * @param {boolean} busy
 * @returns {void}
 */
function setBusy(tile, busy) {
    stateOf(tile).busy = busy;
    tile.setAttribute('aria-busy', busy ? 'true' : 'false');
    const form = tile.closest('form');
    if (!form) {
        return;
    }
    form.querySelectorAll('button[type="submit"]').forEach((element) => {
        const button = /** @type {HTMLButtonElement} */ (element);
        // A thumbnail's cross sits inside but belongs to its own form.
        if (button.form !== form) {
            return;
        }
        if (busy) {
            if (!button.disabled) {
                button.disabled = true;
                button.dataset.photoLocked = '';
            }
        } else if (button.dataset.photoLocked !== undefined) {
            button.disabled = false;
            delete button.dataset.photoLocked;
        }
    });
}

/**
 * @param {HTMLImageElement} img
 * @returns {void}
 */
function forget(img) {
    const url = previews.get(img);
    if (url !== undefined) {
        URL.revokeObjectURL(url);
        previews.delete(img);
    }
}

/**
 * One waiting photo: its thumbnail and the cross that takes it back. It
 * has not left the phone, so taking it back asks nothing.
 *
 * @param {File} file
 * @param {number} index its place among the waiting photos
 * @param {number} number its number among all the photos of the tile
 * @returns {HTMLElement}
 */
function pendingThumb(file, index, number) {
    const figure = document.createElement('figure');
    figure.className = 'photo-thumb';
    figure.dataset.photoPending = '';

    const img = document.createElement('img');
    const url = URL.createObjectURL(file);
    previews.set(img, url);
    img.src = url;
    img.alt = 'Photo ' + number + ', pas encore envoyée';
    img.width = 72;
    img.height = 72;
    figure.appendChild(img);

    const cross = document.createElement('button');
    cross.type = 'button';
    cross.className = 'btn btn-sm btn-outline-secondary photo-thumb-remove';
    cross.dataset.photoDrop = String(index);
    cross.setAttribute('aria-label', 'Ne pas envoyer la photo ' + number);
    cross.textContent = '×';
    figure.appendChild(cross);

    return figure;
}

/**
 * Puts the tile in line with its waiting photos: their thumbnails before
 * the « + », the carrier holding exactly them, the tile hidden once the
 * limit is reached.
 *
 * @param {HTMLElement} tile
 * @returns {void}
 */
export function render(tile) {
    const state = stateOf(tile);
    tile.querySelectorAll('[data-photo-pending]').forEach((node) => {
        node.querySelectorAll('img').forEach((img) => forget(/** @type {HTMLImageElement} */ (img)));
        node.remove();
    });

    const add = tile.querySelector('[data-photo-add]');
    const attached = count(tile, 'photoAttached');
    state.files.forEach((file, index) => {
        tile.insertBefore(pendingThumb(file, index, attached + index + 1), add);
    });

    const carrier = /** @type {HTMLInputElement|null} */ (tile.querySelector('[data-photo-carrier]'));
    if (carrier) {
        const transfer = new DataTransfer();
        state.files.forEach((file) => transfer.items.add(file));
        carrier.files = transfer.files;
    }

    if (add instanceof HTMLElement) {
        add.hidden = remaining(tile) <= 0;
    }
}

/**
 * Reduces the photos just picked and adds them to those waiting, as many
 * as the limit leaves room for. A form marked `data-photo-autosubmit` —
 * an incident already observed — is then sent at once.
 *
 * @param {HTMLElement} tile
 * @param {ArrayLike<File>} picked
 * @returns {Promise<void>}
 */
export async function addFiles(tile, picked) {
    const files = Array.from(picked);
    if (files.length === 0) {
        return;
    }
    const max = count(tile, 'photoMax');
    const kept = files.slice(0, remaining(tile));
    let dropped = files.length - kept.length;
    if (kept.length === 0) {
        say(tile, droppedMessage(max, dropped));
        return;
    }

    setBusy(tile, true);
    say(tile, kept.length > 1 ? 'Préparation des photos…' : 'Préparation de la photo…');
    const reducer = window.ScoutMagicPhotoReduce;
    const reduced = await Promise.all(kept.map((file) => (reducer ? reducer.reducePhoto(file) : Promise.resolve(file))));

    // Two picks in quick succession: the room is counted again now.
    const room = remaining(tile);
    dropped += Math.max(0, reduced.length - room);
    stateOf(tile).files.push(...reduced.slice(0, room));
    render(tile);
    setBusy(tile, false);
    say(tile, droppedMessage(max, dropped));

    const form = tile.closest('form');
    if (form?.dataset.photoAutosubmit !== undefined && stateOf(tile).files.length > 0) {
        form.requestSubmit();
        // The submission read the form's entries synchronously — through
        // rental-booking.js's FormData, or the browser's own — so the tile
        // can forget them now: kept, they would ride along with the next
        // photo if the panel did not reload, and be stored twice.
        stateOf(tile).files = [];
        render(tile);
    }
}

/**
 * Takes one waiting photo back.
 *
 * @param {HTMLElement} tile
 * @param {number} index
 * @returns {void}
 */
export function removePending(tile, index) {
    const state = stateOf(tile);
    if (index < 0 || index >= state.files.length) {
        return;
    }
    state.files.splice(index, 1);
    render(tile);
    say(tile, '');
}

/**
 * Whether this browser can put files into an input — without it the plain
 * field stays.
 *
 * @returns {boolean}
 */
export function canCarry() {
    try {
        return typeof DataTransfer === 'function' && new DataTransfer().files !== undefined;
    } catch {
        return false;
    }
}

/**
 * @param {EventTarget|null} target
 * @returns {HTMLElement|null}
 */
function tileOf(target) {
    const element = /** @type {HTMLElement|null} */ (target);

    return /** @type {HTMLElement|null} */ (element?.closest?.('[data-photo-tile]') ?? null);
}

/**
 * @param {Document} doc
 * @returns {void}
 */
export function wire(doc) {
    if (!canCarry()) {
        return;
    }
    doc.documentElement.classList.add('photo-tile-ready');

    // Once per document: a second wiring would add every photo twice.
    if (/** @type {any} */ (doc).scoutMagicPhotoTileWired === true) {
        return;
    }
    /** @type {any} */ (doc).scoutMagicPhotoTileWired = true;

    doc.addEventListener('click', (event) => {
        const target = /** @type {HTMLElement} */ (event.target);
        const tile = tileOf(target);
        if (tile === null) {
            return;
        }

        const pick = /** @type {HTMLElement|null} */ (target.closest('[data-photo-pick]'));
        if (pick) {
            const source = pick.dataset.photoPick || '';
            if (!SOURCES.has(source) || stateOf(tile).busy) {
                return;
            }
            const input = /** @type {HTMLInputElement|null} */ (tile.querySelector('[data-photo-source="' + source + '"]'));
            input?.click();
            return;
        }

        const drop = /** @type {HTMLElement|null} */ (target.closest('[data-photo-drop]'));
        if (drop) {
            removePending(tile, Number.parseInt(drop.dataset.photoDrop || '-1', 10));
        }
    });

    doc.addEventListener('change', (event) => {
        const input = /** @type {HTMLInputElement} */ (event.target);
        if (!input.matches?.('[data-photo-source]')) {
            return;
        }
        const tile = tileOf(input);
        if (tile === null || !input.files) {
            return;
        }
        const picked = Array.from(input.files);
        // Emptied at once, so that picking the same photo again is a change.
        input.value = '';
        void addFiles(tile, picked);
    });

    // A panel re-rendered by rental-booking.js took its waiting thumbnails
    // with it: their object URLs are let go.
    doc.addEventListener('rental-booking:refreshed', () => {
        Array.from(previews.keys()).forEach((img) => {
            if (!img.isConnected) {
                forget(img);
            }
        });
    });
}

wire(document);
