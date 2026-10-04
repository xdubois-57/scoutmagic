/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Passage page (modules/registration/views/passage.html.twig): the
// per-row section picker that assigns a future member to a section, or
// moves an existing one to their destination section. Extracted from the
// template's inline <script> so the Vitest suite can exercise the
// production code directly (tests/js/registration-passage.test.js).
//
// Each row carries its own endpoint and field name in data-* on the
// select, so the two tables on this page — future members by intended
// section, current members by destination section — share one handler.
//
// Every field here saves itself and answers with a ScoutMagicToast
// (design.md §7.13, issue #739): the picker on change, with no
// « Enregistrer » button beside it. A toast per save stacks during a
// passage evening, and that is accepted — the site answers an autosave
// the same way on every page, and the toast leaves by itself.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLSelectElement>} */
    var pickers = document.querySelectorAll('.passage-select');

    // A no-op on every other page of the site. The guard asks for the
    // page's own toolbar as well as its rows: a unit whose two tables are
    // both empty still has « Réinitialiser » to press, and keying the
    // whole file on a per-row picker would have left it dead there.
    if (!pickers.length && !document.getElementById('passage-optimize-feedback')) {
        return;
    }

    /**
     * The result of an autosave, the way every page of the site gives it.
     *
     * @param {string} message
     * @param {boolean} isError
     */
    function toast(message, isError) {
        window.ScoutMagicToast?.show(message, { variant: isError ? 'error' : 'success' });
    }

    /**
     * @param {{status: number, data: any}} res
     * @returns {string} what to say about a save that did not go through
     */
    function failureMessage(res) {
        return res.status === 0 ? 'Erreur réseau.' : res.data?.error || "Erreur lors de l'enregistrement.";
    }

    // ── The statistics box (spec §8) ─────────────────────────────────
    //
    // Its markup is the server's — both scopes rendered at once, one of
    // them hidden — and the save response carries the whole thing back
    // re-rendered (`statistics_html`). Nothing here formats a number: a
    // second formatter in the browser would be a second place for « 3 G ·
    // 2 F » to be written, and the two would drift.
    //
    // The scope switch is therefore pure visibility, and it survives a
    // refresh because it is re-applied to whatever markup just arrived.

    /** @returns {string} the scope currently selected, 'projected' by default */
    function currentScope() {
        var checked = /** @type {HTMLInputElement|null} */ (
            document.querySelector('input[name="passage-stats-scope"]:checked')
        );
        return checked ? checked.value : 'projected';
    }

    function applyScope() {
        var scope = currentScope();

        document.querySelectorAll('.passage-stats-scope').forEach(function (block) {
            /** @type {HTMLElement} */ (block).hidden = /** @type {HTMLElement} */ (block).dataset.scope !== scope;
        });

        var warning = document.getElementById('passage-arrivals-warning');
        if (warning) {
            warning.classList.toggle('d-none', scope !== 'arrivals');
        }
    }

    // Delegated on the document: the radios live inside the box, which is
    // replaced wholesale on every save, so a listener bound to them
    // directly would be gone after the first one.
    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target instanceof HTMLInputElement && target.name === 'passage-stats-scope') {
            applyScope();
        }
    });

    /** @param {string|undefined} html */
    function refreshStatistics(html) {
        if (typeof html !== 'string' || html === '') {
            return;
        }

        var box = document.getElementById('passage-statistics');
        if (!box?.parentElement) {
            return;
        }

        var scope = currentScope();
        box.outerHTML = html;

        // The freshly-rendered box always comes back on « Effectif
        // projeté » — put the reader's own choice back, or a chief working
        // in « Arrivées seules » would be thrown out of it on every save.
        var restored = /** @type {HTMLInputElement|null} */ (
            document.querySelector('input[name="passage-stats-scope"][value="' + scope + '"]')
        );
        if (restored) {
            restored.checked = true;
        }
        applyScope();
    }

    applyScope();

    pickers.forEach(function (select) {
        // The value the server holds. A refused save puts the picker back
        // on it: the screen must never show a placement that was not
        // recorded.
        var saved = select.value;

        select.addEventListener('change', function () {
            /** @type {Record<string, number>} */
            var payload = {};
            payload[select.dataset.field || ''] = Number.parseInt(select.value, 10);
            var chosen = select.value;

            void api.withDisabled(/** @type {HTMLInputElement} */ (/** @type {unknown} */ (select)), function () {
                return api.postJson(select.dataset.endpoint || '', payload);
            }).then(function (res) {
                if (res.data?.success) {
                    saved = chosen;
                    toast('Enregistré.', false);
                    // The box comes back in the save's own answer (one
                    // round trip, no cache to invalidate) — spec §8.
                    refreshStatistics(res.data.statistics_html);
                    return;
                }
                select.value = saved;
                toast(failureMessage(res), true);
            });
        });
    });

    // ── The planning block (spec §11.6, §11.7 — roadmap IT-17) ───────
    //
    // Three fields that save themselves, on the same delegated shape as
    // the picker above: the endpoint is on the element, so one handler
    // serves both tables and any number of rows.

    /**
     * @param {Element|null} box
     * @param {string} message
     * @param {boolean} isError
     */
    function inlineFeedback(box, message, isError) {
        if (!box) {
            return;
        }
        box.textContent = message;
        box.classList.toggle('text-danger', isError);
        box.classList.toggle('text-success', !isError);
    }

    /**
     * A save the chief did not ask for with a button: its result is a
     * toast.
     *
     * @param {HTMLElement} element the field that carries the endpoint
     * @param {Record<string, any>} payload
     * @returns {Promise<boolean>} whether the server recorded it
     */
    function autoSave(element, payload) {
        return api.postJson(element.dataset.endpoint || '', payload).then(function (res) {
            if (res.data?.success) {
                toast('Enregistré.', false);
                return true;
            }
            toast(failureMessage(res), true);
            return false;
        });
    }

    document.querySelectorAll('.passage-wish-select').forEach(function (select) {
        var field = /** @type {HTMLSelectElement} */ (select);
        var saved = field.value;
        field.addEventListener('change', function () {
            /** @type {Record<string, number>} */
            var payload = {};
            payload[field.dataset.field || 'preferred_section_id'] = Number.parseInt(field.value, 10);
            var chosen = field.value;
            // Disabled while in flight, like the picker above: two answers
            // crossing could otherwise revert to a value the server no
            // longer holds.
            void api.withDisabled(/** @type {HTMLInputElement} */ (/** @type {unknown} */ (field)), function () {
                return autoSave(field, payload);
            }).then(function (recorded) {
                if (recorded) {
                    saved = chosen;
                } else {
                    field.value = saved;
                }
            });
        });
    });

    document.querySelectorAll('.passage-note').forEach(function (note) {
        var field = /** @type {HTMLTextAreaElement} */ (note);
        // On blur, like the Départs comment: a note is written in one go,
        // and a save per keystroke would be a request per keystroke. A
        // refused note keeps its text on screen: putting the old one back
        // would throw away what the chief just wrote.
        // A note left as it was sends nothing, and says nothing.
        // Saves of one note run one after the other, in blur order: an
        // older answer can then never overwrite what a newer one recorded.
        var savedNote = field.value;
        /** @type {Promise<void>} */
        var noteQueue = Promise.resolve();
        field.addEventListener('blur', function () {
            var written = field.value;
            noteQueue = noteQueue.then(function () {
                if (written === savedNote) {
                    return undefined;
                }
                return autoSave(field, { note: written }).then(function (recorded) {
                    if (recorded) {
                        savedNote = written;
                    }
                });
            });
        });
    });

    // ── Optimise and reset (spec §14 — roadmap IT-18) ────────────────
    //
    // Both are one round trip and a reload. The page is server-rendered
    // row by row, and a distribution touches dozens of rows at once, so
    // patching them here would be a second renderer for the whole table —
    // the same reasoning that keeps the statistics box server-side.
    //
    // Nothing here polls, and nothing waits: the server answers with the
    // result. api.withDisabled() greys the button for the duration of the
    // request itself, which is not a "calculation in progress" state but
    // the ordinary guard against a double click.

    /** @param {string[]|undefined} warnings */
    function reportWarnings(box, placed, warnings) {
        var sentence = placed + (placed > 1 ? ' personnes réparties.' : ' personne répartie.');
        if (Array.isArray(warnings) && warnings.length) {
            sentence += ' ' + warnings.join(' ');
        }
        inlineFeedback(box, sentence, Array.isArray(warnings) && warnings.length > 0);
    }

    var optimizeButton = /** @type {HTMLButtonElement|null} */ (document.getElementById('passage-optimize-run'));
    if (optimizeButton) {
        optimizeButton.addEventListener('click', function () {
            var box = document.getElementById('passage-optimize-feedback');
            var chosen = /** @type {HTMLInputElement|null} */ (
                document.querySelector('input[name="passage-optimize-method"]:checked')
            );

            inlineFeedback(box, 'Répartition en cours…', false);

            api.withDisabled(optimizeButton, function () {
                return api.postJson(optimizeButton.dataset.endpoint || '', {
                    method: chosen ? chosen.value : 'balanced',
                }).then(function (res) {
                    if (res.data?.success) {
                        // The warnings are the one thing worth carrying
                        // across the reload, and sessionStorage is the
                        // wrong tool for one sentence — so they are shown
                        // first and the reload waits a beat for them.
                        reportWarnings(box, res.data.placed, res.data.warnings);
                        refreshStatistics(res.data.statistics_html);
                        window.setTimeout(function () { window.location.reload(); }, 1200);
                        return;
                    }
                    inlineFeedback(
                        box,
                        res.status === 0 ? 'Erreur réseau.' : res.data?.error || 'La répartition a échoué.',
                        true
                    );
                });
            });
        });
    }

    var resetButton = /** @type {HTMLButtonElement|null} */ (document.getElementById('passage-reset'));
    if (resetButton) {
        resetButton.addEventListener('click', function () {
            var form = resetButton.closest('form');
            var question = form ? form.dataset.confirm || '' : '';

            // The site's own confirmation, never window.confirm()
            // (design.md §7.5). Asked here rather than through the
            // delegated form handler because this button posts JSON and
            // reloads; a real form submit would answer with a page.
            window.ScoutMagicConfirm.ask({ message: question, confirmLabel: 'Réinitialiser' }).then(function (agreed) {
                if (!agreed) {
                    return;
                }
                var box = document.getElementById('passage-optimize-feedback');
                inlineFeedback(box, 'Réinitialisation…', false);

                api.withDisabled(resetButton, function () {
                    return api.postJson(resetButton.dataset.endpoint || '', {}).then(function (res) {
                        if (res.data?.success) {
                            window.location.reload();
                            return;
                        }
                        inlineFeedback(
                            box,
                            res.status === 0 ? 'Erreur réseau.' : res.data?.error || 'La réinitialisation a échoué.',
                            true
                        );
                    });
                });
            });
        });
    }

    // ── The optional AI re-reading ───────────────────────────────────
    //
    // One button for the page, because the call is per COMMENT and the
    // server decides which ones are still unread; and one checkbox per
    // suggestion, because a chief validates one child at a time. Neither
    // is present when the llm_connector module is absent — the server does
    // not render the block at all.

    var reviewButton = /** @type {HTMLButtonElement|null} */ (document.getElementById('passage-ai-review'));
    if (reviewButton) {
        reviewButton.addEventListener('click', function () {
            var box = document.getElementById('passage-ai-review-feedback');
            inlineFeedback(box, 'Relecture en cours…', false);

            api.withDisabled(reviewButton, function () {
                return api.postJson(reviewButton.dataset.endpoint || '', {}).then(function (res) {
                    if (res.data?.success) {
                        // The suggestions are server-rendered, so the page
                        // is reloaded rather than patched: a second
                        // renderer for « à vérifier » in the browser would
                        // be a second place for that wording to live.
                        window.location.reload();
                        return;
                    }
                    inlineFeedback(
                        box,
                        res.status === 0 ? 'Erreur réseau.' : res.data?.error || 'La relecture a échoué.',
                        true
                    );
                });
            });
        });
    }

    document.querySelectorAll('.passage-ai-confirm').forEach(function (input) {
        var checkbox = /** @type {HTMLInputElement} */ (input);
        var block = checkbox.closest('.passage-ai-suggestion');
        if (!block) {
            return;
        }
        var endpoint = /** @type {HTMLElement} */ (block).dataset.endpoint || '';

        checkbox.addEventListener('change', function () {
            api.postJson(endpoint, { confirmed: checkbox.checked }).then(function (res) {
                if (res.data?.success) {
                    toast(checkbox.checked ? 'Confirmé.' : 'Confirmation retirée.', false);
                    block.classList.toggle('alert-success', checkbox.checked);
                    block.classList.toggle('alert-warning', !checkbox.checked);
                    return;
                }
                // Put the box back where the server still has it, exactly
                // as the departures grid does: the screen must never claim
                // a confirmation that was not recorded.
                checkbox.checked = !checkbox.checked;
                toast(failureMessage(res), true);
            });
        });
    });

    document.querySelectorAll('.passage-friend-save').forEach(function (button) {
        var save = /** @type {HTMLButtonElement} */ (button);
        var wish = save.closest('.passage-friend-wish');
        if (!wish) {
            return;
        }
        var picker = /** @type {HTMLSelectElement|null} */ (wish.querySelector('.passage-friend-select'));
        var box = wish.querySelector('.passage-friend-feedback');
        if (!picker) {
            return;
        }

        // A button, so not an autosave: the answer stays beside the name
        // it is about, as on every form the chief submits on purpose.
        save.addEventListener('click', function () {
            inlineFeedback(box, 'Enregistrement…', false);
            api.withDisabled(save, function () {
                return api.postJson(save.dataset.endpoint || '', {
                    matched_member_id: Number.parseInt(picker.value, 10),
                });
            }).then(function (res) {
                inlineFeedback(box, res.data?.success ? 'Enregistré.' : failureMessage(res), !res.data?.success);
            });
        });
    });
})();
