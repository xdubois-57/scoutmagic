/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Passage page (modules/registration/views/passage.html.twig): the
// per-row « Enregistrer » that assigns a future member to a section, or
// moves an existing one to their destination section. Extracted from the
// template's inline <script> so the Vitest suite can exercise the
// production code directly (tests/js/registration-passage.test.js).
//
// Each row carries its own endpoint and field name in data-* on the
// select, so the two tables on this page — future members by intended
// section, current members by destination section — share one handler
// and one feedback line.
//
// The feedback is an inline `role="status" aria-live="polite"` line, not
// a toast: this page has one of them per row, and a passage evening
// means dozens of saves in a row. A toast per row would stack, and the
// answer belongs beside the select it answers for.
(function () {
    var api = window.ScoutMagicApi;

    /** @type {NodeListOf<HTMLButtonElement>} */
    var buttons = document.querySelectorAll('.passage-save');

    // A no-op on every other page of the site. The guard asks for the
    // page's own toolbar as well as its rows: a unit whose two tables are
    // both empty still has « Réinitialiser » to press, and keying the
    // whole file on a per-row button would have left it dead there.
    if (!buttons.length && !document.getElementById('passage-optimize-feedback')) {
        return;
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

    /**
     * @param {Element} cell
     * @param {string} message
     * @param {boolean} isError
     */
    function feedback(cell, message, isError) {
        var box = cell.querySelector('.passage-feedback');
        if (!box) {
            return;
        }
        box.textContent = message;
        box.classList.toggle('text-danger', isError);
        box.classList.toggle('text-success', !isError);
    }

    buttons.forEach(function (button) {
        var cell = button.closest('td');
        if (!cell) {
            return;
        }
        var select = /** @type {HTMLSelectElement|null} */ (cell.querySelector('.passage-select'));
        if (!select) {
            return;
        }

        button.addEventListener('click', function () {
            /** @type {Record<string, number>} */
            var payload = {};
            payload[select.dataset.field || ''] = Number.parseInt(select.value, 10);

            feedback(cell, 'Enregistrement…', false);

            api.withDisabled(button, function () {
                return api.postJson(select.dataset.endpoint || '', payload);
            }).then(function (res) {
                if (res.data?.success) {
                    feedback(cell, 'Enregistré.', false);
                    // The box comes back in the save's own answer (one
                    // round trip, no cache to invalidate) — spec §8.
                    refreshStatistics(res.data.statistics_html);
                    return;
                }
                if (res.status === 0) {
                    feedback(cell, 'Erreur réseau.', true);
                    return;
                }
                feedback(cell, res.data?.error || "Erreur lors de l'enregistrement.", true);
            });
        });

        // A pick that hasn't been saved yet shouldn't still show the
        // previous line's « Enregistré. » confirmation.
        select.addEventListener('change', function () {
            feedback(cell, '', false);
        });
    });

    // ── The planning block (spec §11.6, §11.7 — roadmap IT-17) ───────
    //
    // Three fields that save themselves, on the same delegated shape as
    // the two above: the endpoint is on the element, so one handler serves
    // both tables and any number of rows.
    //
    // No « Enregistrer » button for the two staff fields. This is a page a
    // chief goes down line by line during a passage evening, and a button
    // per field would be three more clicks per child; the destination
    // picker keeps its button because it is the DECISION, and a decision
    // deserves a deliberate gesture.

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
     * @param {HTMLElement} element the field that carries the endpoint
     * @param {Element|null} box
     * @param {Record<string, any>} payload
     */
    function autoSave(element, box, payload) {
        inlineFeedback(box, 'Enregistrement…', false);

        return api.postJson(element.dataset.endpoint || '', payload).then(function (res) {
            if (res.data?.success) {
                inlineFeedback(box, 'Enregistré.', false);
                return true;
            }
            inlineFeedback(
                box,
                res.status === 0 ? 'Erreur réseau.' : res.data?.error || "Erreur lors de l'enregistrement.",
                true
            );
            return false;
        });
    }

    document.querySelectorAll('.passage-wish-select').forEach(function (select) {
        var field = /** @type {HTMLSelectElement} */ (select);
        field.addEventListener('change', function () {
            /** @type {Record<string, number>} */
            var payload = {};
            payload[field.dataset.field || 'preferred_section_id'] = Number.parseInt(field.value, 10);
            autoSave(field, field.parentElement?.querySelector('.passage-wish-feedback'), payload);
        });
    });

    document.querySelectorAll('.passage-note').forEach(function (note) {
        var field = /** @type {HTMLTextAreaElement} */ (note);
        // On blur, like the Départs comment: a note is written in one go,
        // and a save per keystroke would be a request per keystroke.
        field.addEventListener('blur', function () {
            autoSave(field, field.parentElement?.querySelector('.passage-note-feedback'), {
                note: field.value,
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
        var box = block.querySelector('.passage-ai-feedback');

        checkbox.addEventListener('change', function () {
            inlineFeedback(box, 'Enregistrement…', false);
            api.postJson(endpoint, { confirmed: checkbox.checked }).then(function (res) {
                if (res.data?.success) {
                    inlineFeedback(box, checkbox.checked ? 'Confirmé.' : 'Confirmation retirée.', false);
                    block.classList.toggle('alert-success', checkbox.checked);
                    block.classList.toggle('alert-warning', !checkbox.checked);
                    return;
                }
                // Put the box back where the server still has it, exactly
                // as the departures grid does: the screen must never claim
                // a confirmation that was not recorded.
                checkbox.checked = !checkbox.checked;
                inlineFeedback(
                    box,
                    res.status === 0 ? 'Erreur réseau.' : res.data?.error || "Erreur lors de l'enregistrement.",
                    true
                );
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

        save.addEventListener('click', function () {
            api.withDisabled(save, function () {
                return autoSave(save, box, { matched_member_id: Number.parseInt(picker.value, 10) });
            });
        });
    });
})();
