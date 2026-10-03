/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Blocking dates straight on an asset's managed calendar (#708, IT-07).
 *
 * **The gesture.** Touch a day and keep the finger down a moment, then slide
 * over other days: every day between the first and the current one is
 * treated together. The long press is what tells a selection from a scroll —
 * a quick swipe scrolls the page as usual. With a mouse the same gesture
 * works without waiting: button down, then drag. A plain tap, a click, or
 * Enter/Space on the focused day toggles that one day; that is also the
 * no-drag alternative accessibility asks for.
 *
 * **The first day decides the mode.** A day not blocked by the unit — free,
 * half-taken, or taken by a booking — makes the whole gesture BLOCK; a day
 * the unit already blocked makes it RELEASE.
 *
 * **It decides nothing durable.** The grid is updated at once and the server
 * is asked once, at release, with the days and the mode; it recomputes the
 * unit's periods (Availability\BlockDayPlanner) and answers with what
 * actually changed. A refusal puts the grid back and says why. « Annuler »
 * sends the opposite gesture over the days that changed, with the reason
 * each one had, which rebuilds exactly the periods there were.
 *
 * Past days and the padding days of the neighbouring months do not react: a
 * gesture reaching the edge of the month stops there.
 */

/** How long a finger must rest on a day before a slide selects rather than scrolls. */
export const LONG_PRESS_MS = 350;

/** How far a finger may wander during the long press before it counts as a scroll. */
export const MOVE_TOLERANCE_PX = 10;

/**
 * The mode a gesture starting on this day runs in.
 *
 * @param {boolean} blockedByUnit Whether the unit already blocks the first day.
 * @returns {'block'|'release'}
 */
export function modeFor(blockedByUnit) {
    return blockedByUnit ? 'release' : 'block';
}

/**
 * Every day from `start` to `current`, in either direction, clamped to the
 * displayed month and stripped of past days.
 *
 * @param {string} start `YYYY-MM-DD`, the day the gesture began on.
 * @param {string} current `YYYY-MM-DD`, the day under the pointer now.
 * @param {string} month `YYYY-MM`, the month the grid shows.
 * @param {string} today `YYYY-MM-DD`.
 * @returns {string[]} Sorted, `YYYY-MM-DD`.
 */
export function gestureDays(start, current, month, today) {
    if (!start || !current) {
        return [];
    }

    let from = start < current ? start : current;
    let to = start < current ? current : start;
    const firstOfMonth = month + '-01';
    const lastOfMonth = lastDayOf(month);

    if (from < firstOfMonth) {
        from = firstOfMonth;
    }
    if (to > lastOfMonth) {
        to = lastOfMonth;
    }
    if (from < today) {
        from = today;
    }

    const days = [];
    for (let day = from; day <= to; day = nextDay(day)) {
        days.push(day);
    }

    return days;
}

/**
 * The days of a gesture that it would actually change: in BLOCK mode the
 * ones the unit does not block yet, in RELEASE mode the ones it does.
 *
 * @param {string[]} days
 * @param {'block'|'release'} mode
 * @param {(day: string) => boolean} isBlocked
 * @returns {string[]}
 */
export function daysToChange(days, mode, isBlocked) {
    return days.filter(function (day) {
        return mode === 'block' ? !isBlocked(day) : isBlocked(day);
    });
}

/**
 * The confirmation a gesture leaves on screen.
 *
 * @param {number} count
 * @param {'block'|'release'} mode
 * @returns {string}
 */
export function summaryFor(count, mode) {
    if (count === 0) {
        return 'Rien n\'a changé : ces dates étaient déjà dans cet état.';
    }
    const days = count === 1 ? '1 jour' : count + ' jours';
    if (mode === 'block') {
        return days + (count === 1 ? ' bloqué' : ' bloqués');
    }

    return days + ' remis en location';
}

/**
 * @param {string} day `YYYY-MM-DD`
 * @returns {string}
 */
export function nextDay(day) {
    const date = new Date(day + 'T12:00:00Z');
    date.setUTCDate(date.getUTCDate() + 1);

    return date.toISOString().slice(0, 10);
}

/**
 * @param {string} month `YYYY-MM`
 * @returns {string}
 */
export function lastDayOf(month) {
    const [year, monthNumber] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, monthNumber, 0, 12));

    return date.toISOString().slice(0, 10);
}

/**
 * Wire one managed calendar.
 *
 * Expects `#rental-block-calendar` with `data-month` (`YYYY-MM`),
 * `data-today` (`YYYY-MM-DD`) and `data-url` (the POST endpoint), around the
 * shared day grid whose days carry `data-date` and, when the unit blocks
 * them, `data-unit-block="1"`.
 *
 * @param {HTMLElement} root
 * @returns {void}
 */
export function wireBlockCalendar(root) {
    const month = root.dataset.month || '';
    const today = root.dataset.today || '';
    const url = root.dataset.url || '';
    if (!month || !today || !url) {
        return;
    }

    /** @type {{start: string, current: string, mode: 'block'|'release', pointerId: number, x: number, y: number, selecting: boolean, timer: number}|null} */
    let gesture = null;
    let swallowNextClick = false;

    /** @param {string} day */
    const cellFor = function (day) {
        return /** @type {HTMLElement|null} */ (root.querySelector('[data-date="' + day + '"]'));
    };

    /** @param {string} day */
    const isBlocked = function (day) {
        const cell = cellFor(day);
        return cell?.dataset.unitBlock === '1';
    };

    /** @param {EventTarget|null} target */
    const actionableDay = function (target) {
        const element = /** @type {HTMLElement|null} */ (target instanceof Element ? target.closest('[data-date]') : null);
        if (!element || !root.contains(element)) {
            return '';
        }
        const day = element.dataset.date || '';

        return day.startsWith(month) && day >= today ? day : '';
    };

    const paint = function () {
        root.querySelectorAll('.is-gesture').forEach(function (cell) {
            cell.classList.remove('is-gesture');
        });
        if (!gesture?.selecting) {
            return;
        }
        gestureDays(gesture.start, gesture.current, month, today).forEach(function (day) {
            cellFor(day)?.classList.add('is-gesture');
        });
    };

    const end = function () {
        if (gesture) {
            clearTimeout(gesture.timer);
        }
        gesture = null;
        root.classList.remove('is-selecting');
        paint();
    };

    /**
     * @param {string[]} days
     * @param {boolean} blocked
     */
    const mark = function (days, blocked) {
        days.forEach(function (day) {
            const cell = cellFor(day);
            if (!cell) {
                return;
            }
            if (blocked) {
                cell.dataset.unitBlock = '1';
            } else {
                delete cell.dataset.unitBlock;
            }
        });
    };

    /**
     * @param {string[]} days
     * @param {'block'|'release'} mode
     * @param {Object<string, string|null>|null} reasons An undo's reasons, null for a fresh gesture.
     */
    const commit = function (days, mode, reasons) {
        const changing = daysToChange(days, mode, isBlocked);
        if (changing.length === 0) {
            return;
        }

        mark(changing, mode === 'block');

        const body = { mode: mode, days: changing };
        if (reasons) {
            body.reasons = reasons;
        }

        window.ScoutMagicApi.postJson(url, body).then(function (res) {
            if (!res.ok || res.data?.success !== true) {
                mark(changing, mode !== 'block');
                window.ScoutMagicToast.show(
                    res.data?.error || 'Les dates n\'ont pas pu être enregistrées.',
                    { variant: 'error' }
                );

                return;
            }

            const changed = /** @type {Object<string, string|null>} */ (res.data.changed || {});
            const list = document.getElementById('rental-block-list');
            if (list && typeof res.data.list === 'string') {
                list.innerHTML = res.data.list;
            }

            if (reasons) {
                window.ScoutMagicToast.show('Annulé.', { variant: 'info' });

                return;
            }

            const changedDays = Object.keys(changed);
            if (changedDays.length === 0) {
                // Another tab or another manager got there first: nothing
                // to undo, so no « Annuler ».
                window.ScoutMagicToast.show(summaryFor(0, mode), { variant: 'info' });

                return;
            }
            window.ScoutMagicToast.show(summaryFor(changedDays.length, mode), {
                variant: 'success',
                delayMs: 6000,
                action: {
                    label: 'Annuler',
                    onClick: function () {
                        commit(changedDays, mode === 'block' ? 'release' : 'block', changed);
                    },
                },
            });
        }).catch(function () {
            // Nothing reached the server: the days go back to what they were.
            mark(changing, mode !== 'block');
            window.ScoutMagicToast.show('Erreur réseau : les dates n\'ont pas été enregistrées.', { variant: 'error' });
        });
    };

    root.addEventListener('pointerdown', function (event) {
        if (event.button !== 0) {
            return;
        }
        // A flag left by an earlier drag never outlives the next press.
        swallowNextClick = false;
        const day = actionableDay(event.target);
        if (!day) {
            return;
        }

        const current = {
            start: day,
            current: day,
            mode: modeFor(isBlocked(day)),
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            selecting: false,
            timer: 0,
        };
        gesture = current;

        if (event.pointerType !== 'mouse') {
            current.timer = window.setTimeout(function () {
                if (gesture === current) {
                    current.selecting = true;
                    root.classList.add('is-selecting');
                    paint();
                }
            }, LONG_PRESS_MS);
        }
    });

    root.addEventListener('pointermove', function (event) {
        if (event.pointerId !== gesture?.pointerId) {
            return;
        }

        // A mouse released outside the page lost its pointerup: with no
        // button held any more, the gesture is over.
        if (event.pointerType === 'mouse' && (event.buttons & 1) === 0) {
            end();

            return;
        }

        if (!gesture.selecting) {
            const moved = Math.abs(event.clientX - gesture.x) + Math.abs(event.clientY - gesture.y);
            if (event.pointerType !== 'mouse') {
                if (moved > MOVE_TOLERANCE_PX) {
                    // A swipe before the long press is a scroll: let it be one.
                    end();
                }

                return;
            }
            // The mouse needs no long press: dragging onto another day is
            // the gesture.
            const over = actionableDay(document.elementFromPoint(event.clientX, event.clientY));
            if (!over || over === gesture.start) {
                return;
            }
            gesture.selecting = true;
            root.classList.add('is-selecting');
        }

        const over = actionableDay(document.elementFromPoint(event.clientX, event.clientY));
        if (over) {
            gesture.current = over;
            paint();
        }
    });

    // Once a selection has started the finger must not scroll the page —
    // and only a non-passive touchmove can say so.
    root.addEventListener('touchmove', function (event) {
        if (gesture?.selecting) {
            event.preventDefault();
        }
    }, { passive: false });

    // On the document, not the grid: a mouse drag released past the edge
    // of the calendar still ends its gesture, up to the last day it was
    // over. A finger needs none of this — touch is captured implicitly.
    const release = function (/** @type {PointerEvent} */ event) {
        if (event.pointerId !== gesture?.pointerId) {
            return;
        }
        const finished = gesture;
        end();
        if (finished.selecting) {
            // The click a mouse raises after a drag released on a day must
            // not toggle that day. A finger raises no click after a drag,
            // and a release off the grid clicks no day: nothing to swallow.
            swallowNextClick = event.pointerType === 'mouse' && root.contains(/** @type {Node|null} */ (event.target));
            commit(gestureDays(finished.start, finished.current, month, today), finished.mode, null);
        }
    };
    document.addEventListener('pointerup', release);

    document.addEventListener('pointercancel', function (event) {
        if (event.pointerId === gesture?.pointerId) {
            end();
        }
    });

    root.addEventListener('contextmenu', function (event) {
        // A long press opens the system menu on a phone; on this grid it
        // means "select".
        if (actionableDay(event.target)) {
            event.preventDefault();
        }
    });

    // A tap, a click, Enter or Space: one day.
    root.addEventListener('click', function (event) {
        if (swallowNextClick) {
            swallowNextClick = false;

            return;
        }
        const day = actionableDay(event.target);
        if (!day) {
            return;
        }
        commit([day], modeFor(isBlocked(day)), null);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('rental-block-calendar');
    if (root) {
        wireBlockCalendar(root);
    }
});
