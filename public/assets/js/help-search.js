/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Instant search over the help corpus — local, offline, no network, no
// dependency (ARCHITECTURE.md §8.64).
//
// The whole corpus for the visitor's role already ships inside the page
// as #help-search-index (Core\Help\HelpSearchIndex, the same
// server-decides / browser-renders shape as #offline-config-data), so
// this file never fetches anything and works in a field with no signal —
// which is the entire reason the search exists ahead of the assistant
// rather than behind it.
//
// MiniSearch, Lunr and Fuse were evaluated and dropped: BM25, proper
// stemming and typo tolerance are real gains, but not 10-30 KB served on
// every visit and a new line in ARCHITECTURE.md §1's dependency table for
// a corpus of ~120 short records. What is here instead is deliberately
// small and deliberately explainable — every rule below is one a reader
// can predict from a result list.
//
// The `?q=` GET form on /aide (Core\Help\HelpService::search()) remains
// the no-JavaScript fallback and is NOT replaced by this: it is a plain
// substring filter, and it must keep answering with scripting off.
//
// No inline on*= handlers (dead under the CSP — design.md §7.5).
(function () {
    // Weights: what a match is worth, by the field it landed in. A title
    // is what the topic IS, a `question` is what someone actually types
    // (which is why it sits just under the title rather than with the
    // summary), a summary is prose around it, a category is the coarsest
    // signal there is.
    var FIELD_WEIGHTS = { title: 5, questions: 4, summary: 2, category: 1 };

    // A term that only PREFIXES an indexed word ("photo" against
    // "photographie") is worth half of the same match landing whole. A
    // factor rather than a fixed penalty, deliberately: within one field
    // the exact hit always wins, but half a title still outranks a whole
    // summary — which is what you want while somebody is typing a title
    // they half remember, and not what a flat penalty would give.
    var PREFIX_FACTOR = 0.5;

    // Below this, a prefix is not a search term: "a" prefixes half the
    // corpus. Stop-word removal already drops most short tokens.
    var MIN_PREFIX_LENGTH = 3;

    var MAX_RESULTS = 5;

    // French stop words, kept short on purpose — this is the list that
    // stops « comment », « le » and « pour » from matching every topic in
    // the corpus, not an attempt at linguistics. « comment », « où »
    // (folded to « ou »), « quand » and « pourquoi » are in it because a
    // `question:` line opens with one of them; « tou » is there because
    // de-suffixing turns « tous » into it.
    var STOP_WORDS = [
        'a', 'au', 'aux', 'avec', 'ce', 'ces', 'cet', 'cette', 'comment', 'dans',
        'de', 'des', 'du', 'elle', 'en', 'est', 'et', 'eux', 'il', 'ils', 'je',
        'la', 'le', 'les', 'leur', 'lui', 'ma', 'mais', 'mes', 'mon', 'ne', 'nos',
        'notre', 'nous', 'on', 'ont', 'ou', 'par', 'pas', 'plus', 'pour',
        'pourquoi', 'qu', 'quand', 'que', 'qui', 'quoi', 'sa', 'se', 'ses', 'son',
        'sont', 'sur', 'ta', 'te', 'tes', 'toi', 'ton', 'tou', 'tous', 'tout',
        'toute', 'toutes', 'tu', 'un', 'une', 'vos', 'votre', 'vous', 'y'
    ];

    /**
     * Lowercased with the diacritics stripped, so "médaille" and
     * "medaille" are one word. NFD splits a letter from its accent and
     * the range below is exactly the combining marks — the browser's own
     * Unicode tables, not a hand-written character map.
     *
     * @param {string} value
     * @returns {string}
     */
    function normalize(value) {
        return String(value === null || value === undefined ? '' : value)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    /**
     * The one de-suffixing rule, applied SYMMETRICALLY to the index and
     * to the query — that symmetry is the whole trick, and skipping it on
     * one side is how a search stops finding what it just indexed.
     *
     * A trailing 's'/'x' on a word of more than three letters, and
     * nothing else: it folds the plural that actually shows up
     * ("photos"/"photo", "adresses"/"adresse") without a stemmer's
     * appetite for collapsing unrelated words. "bus" keeps its 's'.
     *
     * @param {string} token
     * @returns {string}
     */
    function stem(token) {
        var last = token.slice(-1);
        if (token.length > 3 && (last === 's' || last === 'x')) {
            return token.slice(0, -1);
        }

        return token;
    }

    /**
     * Normalized, split on anything that is not a letter or digit,
     * stop words dropped, each survivor de-suffixed.
     *
     * @param {string} value
     * @returns {string[]}
     */
    function tokenize(value) {
        var tokens = [];
        var raw = normalize(value).split(/[^a-z0-9]+/);
        for (var i = 0; i < raw.length; i++) {
            var token = raw[i];
            if (token === '' || STOP_WORDS.indexOf(token) !== -1) {
                continue;
            }
            tokens.push(stem(token));
        }

        return tokens;
    }

    /**
     * How many of the query's terms a topic has to carry to be shown at
     * all — counted over the terms the CORPUS knows, see search().
     *
     * One or two words means the visitor is being specific, so every one
     * of them must land: « photo camp » answers with the camp album
     * topic, not with every topic mentioning a photo. From three words up
     * they are typing a sentence — which is exactly what the `question:`
     * field invites — and one word this particular topic happens not to
     * use must not throw it away.
     *
     * @param {number} termCount
     * @returns {number}
     */
    function requiredCoverage(termCount) {
        return termCount <= 2 ? termCount : Math.ceil((termCount * 2) / 3);
    }

    /**
     * What one query term is worth against one list of indexed words: the
     * field weight for a whole match, half of it for a prefix, zero for
     * neither.
     *
     * @param {string} term
     * @param {string[]} words
     * @param {number} weight
     * @returns {number}
     */
    function scoreTermAgainstField(term, words, weight) {
        var best = 0;
        for (var i = 0; i < words.length; i++) {
            var word = words[i];
            if (word === term) {
                return weight;
            }
            if (term.length >= MIN_PREFIX_LENGTH && word.indexOf(term) === 0) {
                best = Math.max(best, weight * PREFIX_FACTOR);
            }
        }

        return best;
    }

    // Tokenizing the whole corpus on every keystroke is wasted work — the
    // index never changes within a page. Keyed on the entry object rather
    // than an id so nothing has to be invalidated and nothing leaks: the
    // map holds no entry the page itself has stopped holding.
    var preparedCache = new WeakMap();

    /**
     * One index entry, with its four searchable fields tokenized once.
     *
     * @param {HelpSearchEntry} entry
     * @returns {{entry: HelpSearchEntry, fields: Object<string, string[]>}}
     */
    function prepare(entry) {
        var cached = preparedCache.get(entry);
        if (cached) {
            return cached;
        }

        var questions = Array.isArray(entry.questions) ? entry.questions : [];
        var prepared = {
            entry: entry,
            fields: {
                title: tokenize(entry.title),
                questions: tokenize(questions.join(' ')),
                summary: tokenize(entry.summary),
                category: tokenize(entry.category)
            }
        };
        preparedCache.set(entry, prepared);

        return prepared;
    }

    /**
     * What one term is worth against one prepared entry: the best score
     * any of its four fields gives it, zero when none of them does.
     *
     * @param {string} term
     * @param {{entry: HelpSearchEntry, fields: Object<string, string[]>}} prepared
     * @returns {number}
     */
    function scoreTerm(term, prepared) {
        var best = 0;
        for (var field in FIELD_WEIGHTS) {
            if (!Object.prototype.hasOwnProperty.call(FIELD_WEIGHTS, field)) {
                continue;
            }
            best = Math.max(best, scoreTermAgainstField(term, prepared.fields[field], FIELD_WEIGHTS[field]));
        }

        return best;
    }

    /**
     * Whether any entry in the corpus carries this term at all.
     *
     * @param {string} term
     * @param {Array<{entry: HelpSearchEntry, fields: Object<string, string[]>}>} prepared
     * @returns {boolean}
     */
    function matchesAnywhere(term, prepared) {
        for (var i = 0; i < prepared.length; i++) {
            if (scoreTerm(term, prepared[i]) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ranked answer to one query: at most MAX_RESULTS entries, best
     * first, ties broken by title so the same query always renders the
     * same list.
     *
     * @param {HelpSearchEntry[]} index
     * @param {string} query
     * @returns {HelpSearchEntry[]} the matching entries, in order
     */
    function search(index, query) {
        var typed = tokenize(query);
        if (typed.length === 0) {
            // A query made only of stop words ("comment", "le pour")
            // carries no term to match — answering with the whole corpus
            // ranked arbitrarily would be worse than answering nothing.
            return [];
        }

        var prepared = [];
        for (var p = 0; p < index.length; p++) {
            prepared.push(prepare(index[p]));
        }

        // A term NO topic carries is a word the corpus does not use, and
        // it discriminates nothing: counting it against every topic is
        // how « empreinte digitale » ends up answering nothing at all,
        // when one topic says « se connecter avec l'empreinte ». Dropped
        // before coverage is measured — which is different from a term
        // other topics DO carry and this one does not, and that one still
        // counts against it.
        var terms = [];
        for (var t = 0; t < typed.length; t++) {
            if (matchesAnywhere(typed[t], prepared)) {
                terms.push(typed[t]);
            }
        }
        if (terms.length === 0) {
            return [];
        }

        var needed = requiredCoverage(terms.length);
        var scored = [];

        for (var i = 0; i < prepared.length; i++) {
            var score = 0;
            var covered = 0;

            for (var k = 0; k < terms.length; k++) {
                var best = scoreTerm(terms[k], prepared[i]);
                if (best > 0) {
                    covered++;
                    score += best;
                }
            }

            if (covered >= needed) {
                scored.push({ entry: prepared[i].entry, score: score, title: normalize(prepared[i].entry.title) });
            }
        }

        scored.sort(function (a, b) {
            if (b.score !== a.score) {
                return b.score - a.score;
            }

            return a.title < b.title ? -1 : (a.title > b.title ? 1 : 0);
        });

        return scored.slice(0, MAX_RESULTS).map(function (row) {
            return row.entry;
        });
    }

    // --- Rendering -------------------------------------------------

    // Mirrors the id format Core\Help\HelpFrontMatterParser enforces
    // server-side. The index is DOM text by the time this file reads it,
    // so building a URL from an id needs an explicit allowlist check
    // first — the js/xss-through-dom shape, exactly as help-panel.js
    // already guards it.
    var TOPIC_ID_PATTERN = /^[a-z0-9-]+$/;

    // Same reasoning for a page link, which is a path this page did not
    // write: only a site-relative path, never a scheme, never a
    // protocol-relative '//host' that a browser reads as absolute.
    var PAGE_PATH_PATTERN = /^\/(?!\/)[^\s]*$/;

    /**
     * @param {HelpSearchEntry} entry
     * @returns {HTMLElement}
     */
    function renderResult(entry) {
        var item = document.createElement('div');
        item.className = 'list-group-item list-group-item-action border-0 px-0 py-2';

        var head = document.createElement('div');
        head.className = 'd-flex align-items-center gap-2 flex-wrap';

        var title = document.createElement('a');
        title.className = 'fw-semibold text-decoration-none';
        title.href = TOPIC_ID_PATTERN.test(entry.id) ? '/aide/' + entry.id : '/aide';
        title.textContent = entry.title;
        head.appendChild(title);

        var badge = document.createElement('span');
        badge.className = 'badge text-bg-light border fw-normal';
        badge.textContent = entry.category;
        head.appendChild(badge);

        item.appendChild(head);

        var summary = document.createElement('div');
        summary.className = 'small text-body-secondary';
        summary.textContent = entry.summary;
        item.appendChild(summary);

        if (entry.link && typeof entry.link.path === 'string' && PAGE_PATH_PATTERN.test(entry.link.path)) {
            var page = document.createElement('a');
            page.className = 'small text-decoration-none d-inline-block mt-1';
            page.href = entry.link.path;
            page.textContent = 'Aller sur la page « ' + entry.link.label + ' »';
            item.appendChild(page);
        }

        return item;
    }

    /**
     * The empty state: what did not work, and what to do next — never a
     * mood (design.md §7.7).
     *
     * @param {string} query
     * @returns {HTMLElement}
     */
    function renderEmpty(query) {
        var wrapper = document.createElement('div');
        wrapper.className = 'text-body-secondary small py-2';

        var first = document.createElement('p');
        first.className = 'mb-1';
        first.textContent = 'Aucun sujet ne correspond à « ' + query +' ».';
        wrapper.appendChild(first);

        var second = document.createElement('p');
        second.className = 'mb-0';
        second.textContent = "Cherchez plutôt un mot du problème que le nom d'un écran : « photo », "
            + "« mot de passe », « inscription ». Si vous n'êtes pas connecté, connectez-vous : "
            + "la liste des sujets s'élargit selon votre accès.";
        wrapper.appendChild(second);

        return wrapper;
    }

    // --- Handing over to the assistant -----------------------------

    // Where a question waits while the browser navigates from /aide to
    // /aide/assistant. sessionStorage and not a query string: the text is
    // whatever a human typed and can name a person or an amount, and a
    // query string ends up in browser history and in every access log on
    // the way. Read once and removed (public/assets/js/help-assistant.js).
    // Not a cookie either — nothing about this feature adds one.
    var PENDING_QUESTION_KEY = 'scoutmagic:help-assistant:question';

    /**
     * @param {string} question
     */
    function stashQuestion(question) {
        try {
            window.sessionStorage.setItem(PENDING_QUESTION_KEY, question);
        } catch (e) {
            // Private mode, storage disabled, quota — the visitor simply
            // retypes the question on the page. Never a failure worth an
            // error message.
        }
    }

    /**
     * Binds one surface — the help panel or the /aide page. Both carry
     * the same three markers, so the two surfaces share this file
     * entirely rather than growing a copy each.
     *
     * @param {HTMLElement} scope
     * @param {HelpSearchEntry[]} index
     */
    function bind(scope, index) {
        var input = /** @type {HTMLInputElement|null} */ (scope.querySelector('[data-help-search-input]'));
        var results = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-search-results]'));
        var fallback = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-search-default]'));
        if (!input || !results || !fallback) {
            return;
        }

        // Absent whenever the assistant is not on offer — no connector,
        // or a role below `chief`. The search does not care either way
        // (locked decision D2), so everything about it stays optional
        // here rather than becoming a second code path.
        var inviteZone = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-assistant-invite-zone]'));
        var invite = scope.querySelector('[data-help-assistant-invite]');
        var assistantHost = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-assistant-host]'));

        if (invite) {
            invite.addEventListener('click', function (e) {
                var question = input.value.trim();

                if (!assistantHost) {
                    // /aide: the invite is a link to /aide/assistant, and
                    // the question travels with it. Navigation proceeds.
                    stashQuestion(question);
                    return;
                }

                // The help panel: the assistant is already in this drawer,
                // one include below. Reveal it rather than leaving the page
                // — the results the visitor just read stay above it.
                e.preventDefault();
                assistantHost.hidden = false;
                if (inviteZone) {
                    inviteZone.hidden = true;
                }
                // Dispatched ON the assistant, not on its wrapper: an
                // event bubbles up, and help-assistant.js listens from
                // inside.
                var assistant = assistantHost.querySelector('[data-help-assistant]');
                if (assistant) {
                    assistant.dispatchEvent(new CustomEvent('scoutmagic:help-assistant-ask', {
                        detail: { question: question }
                    }));
                }
            });
        }

        function render() {
            var query = input.value.trim();
            results.replaceChildren();

            if (query === '') {
                // Back to the surface's own content: the panel's topics,
                // or /aide's full listing by category.
                results.hidden = true;
                fallback.hidden = false;
                if (inviteZone) {
                    inviteZone.hidden = true;
                }
                return;
            }

            var found = search(index, query);
            if (found.length === 0) {
                results.appendChild(renderEmpty(query));
            } else {
                var list = document.createElement('div');
                list.className = 'list-group list-group-flush';
                found.forEach(function (entry) {
                    list.appendChild(renderResult(entry));
                });
                results.appendChild(list);
            }

            results.hidden = false;
            fallback.hidden = true;
            // Under the results, and only there: what the search found is
            // the thing the assistant is an alternative to, so the offer
            // reads after them. Once the panel's assistant is open the
            // invite has done its job and does not come back.
            if (inviteZone && !(assistantHost && !assistantHost.hidden)) {
                inviteZone.hidden = false;
            }
        }

        input.addEventListener('input', render);

        // With this script running, submitting reloads the page to show
        // the very same topics the visitor is already looking at. The
        // `?q=` round trip stays wired in the markup for the no-JS case
        // and is simply not taken here.
        var form = input.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                render();
            });
        }

        // A page served with ?q= already carries the value; render once
        // so the instant list and the server-rendered one agree.
        if (input.value.trim() !== '') {
            render();
        }
    }

    function init() {
        var indexEl = document.getElementById('help-search-index');
        if (!indexEl) {
            return;
        }

        var index;
        try {
            index = JSON.parse(indexEl.textContent || '[]');
        } catch (e) {
            return;
        }
        if (!Array.isArray(index)) {
            return;
        }

        document.querySelectorAll('[data-help-search-scope]').forEach(function (scope) {
            bind(/** @type {HTMLElement} */ (scope), index);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // The scoring is the part worth testing without a browser
    // (tests/js/help-search.test.js) — exposed the same way
    // ScoutMagicAttestationsDeposit is, rather than duplicated in the test.
    window.ScoutMagicHelpSearch = { search: search, tokenize: tokenize, normalize: normalize };
})();
