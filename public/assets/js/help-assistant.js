/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The help assistant's conversation (ARCHITECTURE.md §8.87), on both of
// its surfaces: the help panel and the /aide/assistant page. One script
// binds every [data-help-assistant] on the page, so the two never drift.
//
// The exchange is deliberately staged rather than a spinner: « Je cherche
// dans l'aide… » goes up immediately, then the titles the assistant
// retained, then the answer. Two calls happen server-side and the second
// is the slow one, so a mute wait would be the whole latency with nothing
// to read. Only one HTTP round trip happens — the staging is honest about
// what is going on, not a fake progress bar.
//
// The answer arrives as HTML already escaped by MarkdownRenderer
// server-side (it is a language model's output, and therefore untrusted
// content). Everything ELSE this file puts on the page — the question the
// visitor typed, a topic title, an error message — is written with
// textContent, never innerHTML.
//
// No inline on*= handlers (dead under the CSP — design.md §7.5).
(function () {
    var ENDPOINT = '/api/aide/assistant';

    // Where help-search.js leaves a question when the visitor pressed
    // « Demander à l'assistant » on /aide and the browser then navigated
    // here. Read once and removed: it is free text a human typed, it has
    // no business outliving the trip.
    var PENDING_QUESTION_KEY = 'scoutmagic:help-assistant:question';

    /**
     * @returns {string}
     */
    function takePendingQuestion() {
        try {
            var pending = window.sessionStorage.getItem(PENDING_QUESTION_KEY);
            window.sessionStorage.removeItem(PENDING_QUESTION_KEY);

            return typeof pending === 'string' ? pending : '';
        } catch {
            // sessionStorage disabled or partitioned away — no pending
            // question, which is the same as never having stored one.
            return '';
        }
    }

    // Mirrors the id format Core\Help\HelpFrontMatterParser enforces
    // server-side: a topic id becomes a URL here, and a bare
    // concatenation of DOM text into an href is the js/xss-through-dom
    // shape help-panel.js and help-search.js already guard against.
    var TOPIC_ID_PATTERN = /^[a-z0-9-]+$/;

    /**
     * @param {string} className
     * @param {string} text
     * @returns {HTMLParagraphElement}
     */
    function paragraph(className, text) {
        var p = document.createElement('p');
        p.className = className;
        p.textContent = text;

        return p;
    }

    /**
     * One exchange: what was asked, and the room the answer will land in.
     *
     * The status line carries a turning spinner and not only its sentence.
     * Two calls happen server-side and the second is the slow one, so a
     * line of still text for two or three seconds reads as a page that has
     * stopped responding — the spinner is what says the wait is the site
     * working rather than the site stuck.
     *
     * @param {string} question
     * @returns {{block: HTMLElement, status: HTMLElement, statusText: HTMLElement, spinner: HTMLElement, answer: HTMLElement, topics: HTMLElement}}
     */
    function openExchange(question) {
        var block = document.createElement('div');
        block.className = 'help-assistant-exchange mb-3 pb-3 border-bottom';

        var asked = paragraph('fw-semibold mb-2', question);
        block.appendChild(asked);

        var status = document.createElement('p');
        status.className = 'small text-body-secondary mb-2 d-flex align-items-center gap-2';

        var spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm flex-shrink-0';
        spinner.setAttribute('role', 'status');
        spinner.setAttribute('aria-hidden', 'true');
        status.appendChild(spinner);

        var statusText = document.createElement('span');
        statusText.textContent = "Je cherche dans l'aide…";
        status.appendChild(statusText);
        block.appendChild(status);

        var topics = document.createElement('div');
        topics.className = 'small text-body-secondary mb-2 d-none';
        block.appendChild(topics);

        var answer = document.createElement('div');
        answer.className = 'help-content rich-text';
        block.appendChild(answer);

        return {
            block: block,
            status: status,
            statusText: statusText,
            spinner: spinner,
            answer: answer,
            topics: topics
        };
    }

    /**
     * The wait is over, whatever the outcome — the spinner goes with it.
     *
     * @param {{spinner: HTMLElement, statusText: HTMLElement}} exchange
     * @param {string} message
     */
    function settle(exchange, message) {
        exchange.spinner.remove();
        exchange.statusText.textContent = message;
    }

    /**
     * The topics the assistant actually read, as links to them — so the
     * reader can check the answer against its source, which is the whole
     * reason the ids travel back at all.
     *
     * And, when the server resolved one, the link to the PAGE each topic
     * documents. The answer says « ouvrez Finances > Reçus » because that
     * is where the thing is, and the reader was then left to find
     * Finances > Reçus through the menus. `page_path` is
     * Core\Help\HelpPageLinkResolver's answer, already role-checked and
     * already suppressed for the page the reader is on — this file only
     * decides whether to print it.
     *
     * @param {HTMLElement} target
     * @param {Array<{id: string, title: string, page_path?: string|null, page_label?: string|null}>} topics
     */
    function renderTopics(target, topics) {
        if (!topics || topics.length === 0) {
            return;
        }

        target.replaceChildren();
        target.appendChild(document.createTextNode('Sujets consultés : '));
        topics.forEach(function (topic, index) {
            if (index > 0) {
                target.appendChild(document.createTextNode(', '));
            }
            var link = document.createElement('a');
            link.className = 'text-decoration-none';
            link.href = TOPIC_ID_PATTERN.test(topic.id) ? '/aide/' + topic.id : '/aide';
            link.textContent = topic.title;
            target.appendChild(link);
        });

        var pages = topics.filter(function (topic) {
            // Only a path the SERVER produced is ever followed: an
            // absolute one, starting with a single slash, is a page of
            // this site and nothing else.
            return typeof topic.page_path === 'string'
                && topic.page_path.startsWith('/')
                && !topic.page_path.startsWith('//');
        });

        if (pages.length > 0) {
            var line = document.createElement('div');
            line.appendChild(document.createTextNode('Aller sur la page : '));
            pages.forEach(function (topic, index) {
                if (index > 0) {
                    line.appendChild(document.createTextNode(', '));
                }
                var pageLink = document.createElement('a');
                pageLink.className = 'text-decoration-none';
                pageLink.href = topic.page_path;
                pageLink.textContent = topic.page_label || topic.title;
                line.appendChild(pageLink);
            });
            target.appendChild(line);
        }

        target.classList.remove('d-none');
    }

    /**
     * @param {HTMLElement} scope
     */
    function bind(scope) {
        // The form is the FULL PAGE's; the panel has none — its search box
        // is the field, and « Demander à l'assistant » is the send. So
        // everything below treats the form and the input as optional, and
        // only the thread is required.
        var form = /** @type {HTMLFormElement|null} */ (scope.querySelector('[data-help-assistant-form]'));
        var input = /** @type {HTMLInputElement|null} */ (scope.querySelector('[data-help-assistant-input]'));
        var thread = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-assistant-thread]'));
        var history = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-assistant-history]'));
        var submit = /** @type {HTMLButtonElement|null} */ (scope.querySelector('[data-help-assistant-submit]'));
        var clear = /** @type {HTMLButtonElement|null} */ (scope.querySelector('[data-help-assistant-clear]'));
        var offline = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-assistant-offline]'));
        if (!thread) {
            return;
        }

        var busy = false;

        function refreshOnlineState() {
            var isOffline = navigator.onLine === false;
            if (offline) {
                offline.classList.toggle('d-none', !isOffline);
            }
            if (submit) {
                submit.disabled = isOffline || busy;
            }
        }

        refreshOnlineState();
        window.addEventListener('online', refreshOnlineState);
        window.addEventListener('offline', refreshOnlineState);

        function refreshClearState() {
            if (clear && input) {
                clear.classList.toggle('d-none', input.value === '');
            }
        }

        if (clear && input) {
            clear.addEventListener('click', function () {
                input.value = '';
                refreshClearState();
                input.focus();
            });
            input.addEventListener('input', refreshClearState);
            refreshClearState();
        }

        /**
         * One question, from the moment it leaves to the moment it is
         * answered. Reached three ways — the page's own form, the panel's
         * « Demander à l'assistant », and a question handed over from
         * /aide — and all three go through here, so the three behave the
         * same.
         *
         * @param {string} raw
         */
        async function ask(raw) {
            var question = raw.trim();
            if (question === '' || busy) {
                return;
            }

            busy = true;
            if (input) {
                input.value = '';
                refreshClearState();
            }
            if (submit) {
                submit.disabled = true;
            }
            // The conversation is named as soon as there is one to name.
            if (history) {
                history.classList.remove('d-none');
            }

            var exchange = openExchange(question);
            thread.appendChild(exchange.block);

            var api = window.ScoutMagicApi;
            var res = api
                ? await api.postJson(ENDPOINT, { question: question, path: window.location.pathname })
                : { ok: false, status: 0, data: null };

            busy = false;
            refreshOnlineState();
            // Whoever asked can offer to ask again — in the panel that is
            // help-search.js re-enabling its button.
            scope.dispatchEvent(new CustomEvent('scoutmagic:help-assistant-idle', { bubbles: true }));

            if (!res.ok || !res.data || res.data.success !== true) {
                // The server's own sentence when it wrote one — every
                // refusal this endpoint produces is French and meant for
                // the reader (quota, connector absent, provider failure).
                var message = (res.data && typeof res.data.error === 'string' && res.data.error !== '')
                    ? res.data.error
                    : "L'assistant n'a pas pu répondre. Réessayez dans un instant.";
                settle(exchange, message);
                exchange.status.classList.add('text-danger');
                return;
            }

            if (res.data.found_nothing === true) {
                settle(
                    exchange,
                    "Je n'ai rien trouvé sur ce point dans l'aide de ce site. "
                        + "Reformulez votre question, ou parcourez les sujets depuis la page Aide."
                );
                return;
            }

            renderTopics(exchange.topics, res.data.topics || []);
            settle(exchange, '');
            exchange.status.classList.add('d-none');
            // Already escaped server-side by Core\View\MarkdownRenderer —
            // this is the ONE place this file writes HTML, and the reason
            // it may is that the server did the escaping.
            exchange.answer.innerHTML = res.data.answer_html || '';
            // Guarded like every other scrollIntoView here (groups.js,
            // nav-rail.js): older browsers and jsdom do not have it, and a
            // courtesy scroll must never be what breaks the answer.
            if (typeof exchange.block.scrollIntoView === 'function') {
                exchange.block.scrollIntoView({ block: 'nearest' });
            }
        }

        // Handed over by the local search: the panel dispatches this on
        // the assistant it just revealed, and /aide leaves the question in
        // sessionStorage before navigating here. Sent straight away in
        // both cases — pressing « Demander à l'assistant » IS the request,
        // and making someone press a second button to confirm what they
        // just asked for is the friction this replaced.
        scope.addEventListener('scoutmagic:help-assistant-ask', function (e) {
            var detail = /** @type {CustomEvent} */ (e).detail;
            void ask(detail && typeof detail.question === 'string' ? detail.question : '');
        });

        var handedOver = takePendingQuestion();
        if (handedOver !== '') {
            void ask(handedOver);
        }

        if (form && input) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                void ask(input.value);
            });
        }
    }

    function init() {
        document.querySelectorAll('[data-help-assistant]').forEach(function (scope) {
            bind(/** @type {HTMLElement} */ (scope));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Exposed for tests/js/help-assistant.test.js, which exercises the
    // real rendering rather than a copy of it.
    window.ScoutMagicHelpAssistant = { openExchange: openExchange, renderTopics: renderTopics };
})();
