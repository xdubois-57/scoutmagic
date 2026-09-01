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
        } catch (e) {
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
     * @param {string} question
     * @returns {{block: HTMLElement, status: HTMLElement, answer: HTMLElement, topics: HTMLElement}}
     */
    function openExchange(question) {
        var block = document.createElement('div');
        block.className = 'help-assistant-exchange mb-3 pb-3 border-bottom';

        var asked = paragraph('fw-semibold mb-2', question);
        block.appendChild(asked);

        var status = paragraph('small text-body-secondary mb-2', "Je cherche dans l'aide…");
        block.appendChild(status);

        var topics = document.createElement('div');
        topics.className = 'small text-body-secondary mb-2 d-none';
        block.appendChild(topics);

        var answer = document.createElement('div');
        answer.className = 'help-content rich-text';
        block.appendChild(answer);

        return { block: block, status: status, answer: answer, topics: topics };
    }

    /**
     * The topics the assistant actually read, as links to them — so the
     * reader can check the answer against its source, which is the whole
     * reason the ids travel back at all.
     *
     * @param {HTMLElement} target
     * @param {Array<{id: string, title: string}>} topics
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
        target.classList.remove('d-none');
    }

    /**
     * @param {HTMLElement} scope
     */
    function bind(scope) {
        var form = /** @type {HTMLFormElement|null} */ (scope.querySelector('[data-help-assistant-form]'));
        var input = /** @type {HTMLInputElement|null} */ (scope.querySelector('[data-help-assistant-input]'));
        var thread = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-assistant-thread]'));
        var submit = /** @type {HTMLButtonElement|null} */ (scope.querySelector('[data-help-assistant-submit]'));
        var offline = /** @type {HTMLElement|null} */ (scope.querySelector('[data-help-assistant-offline]'));
        if (!form || !input || !thread) {
            return;
        }

        function refreshOnlineState() {
            if (!offline) {
                return;
            }
            var isOffline = navigator.onLine === false;
            offline.classList.toggle('d-none', !isOffline);
            if (submit) {
                submit.disabled = isOffline;
            }
        }

        refreshOnlineState();
        window.addEventListener('online', refreshOnlineState);
        window.addEventListener('offline', refreshOnlineState);

        /**
         * The question the local search handed over, put in the field as
         * it was typed — and left there. Sending it is the visitor's
         * move: an automatic submit would spend a quota unit and reach a
         * provider on a click meant to open a form.
         *
         * @param {string} question
         */
        function adopt(question) {
            if (question === '') {
                input.focus();
                return;
            }
            input.value = question;
            input.focus();
            input.setSelectionRange(question.length, question.length);
        }

        // The panel reveals this block in place and says so (help-search.js
        // dispatches on the host); the page gets the same question through
        // sessionStorage, having just been navigated to.
        scope.addEventListener('scoutmagic:help-assistant-ask', function (e) {
            var detail = /** @type {CustomEvent} */ (e).detail;
            adopt(detail && typeof detail.question === 'string' ? detail.question : '');
        });

        var handedOver = takePendingQuestion();
        if (handedOver !== '') {
            adopt(handedOver);
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            var question = input.value.trim();
            if (question === '') {
                return;
            }

            var exchange = openExchange(question);
            thread.appendChild(exchange.block);
            input.value = '';
            if (submit) {
                submit.disabled = true;
            }

            var api = window.ScoutMagicApi;
            var res = api
                ? await api.postJson(ENDPOINT, { question: question, path: window.location.pathname })
                : { ok: false, status: 0, data: null };

            if (submit) {
                submit.disabled = navigator.onLine === false;
            }

            if (!res.ok || !res.data || res.data.success !== true) {
                // The server's own sentence when it wrote one — every
                // refusal this endpoint produces is French and meant for
                // the reader (quota, connector absent, provider failure).
                var message = (res.data && typeof res.data.error === 'string' && res.data.error !== '')
                    ? res.data.error
                    : "L'assistant n'a pas pu répondre. Réessayez dans un instant.";
                exchange.status.textContent = message;
                exchange.status.classList.add('text-danger');
                return;
            }

            if (res.data.found_nothing === true) {
                exchange.status.textContent = "Je n'ai rien trouvé sur ce point dans l'aide de ce site. "
                    + "Reformulez votre question, ou parcourez les sujets depuis la page Aide.";
                return;
            }

            renderTopics(exchange.topics, res.data.topics || []);
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
        });
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
