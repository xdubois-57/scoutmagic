/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The article editor's publication buttons (issue #845).
//
// « Publier » asks first, in the site's own dialog (confirm.js, through
// the button's data-confirm attributes), FOR WHOM it publishes — and that
// depends on the visibility chosen a few cards above, which the author can
// still change before clicking. So the question is not printed once by
// the server: the server hands every possible question over in the page's
// data island (Service\PublicationWording::publishQuestions()), and this
// file puts the one matching the selected radio on the button, again on
// every change. The article's title, which the author may be typing, is
// filled into it the same way.
(function () {
    /**
     * The question « Publier » asks for this visibility and this title —
     * the whole decision, exported for the unit test.
     *
     * @param {Object<string, {title: string, message: string}>} questions
     * @param {string} visibility
     * @param {string} title
     * @returns {{title: string, message: string}|null}
     */
    function questionFor(questions, visibility, title) {
        const question = questions[visibility] || questions.public;
        if (!question) return null;

        const name = title.trim() === '' ? 'Cette actualité' : title.trim();

        return {
            title: question.title,
            message: question.message.split('{titre}').join(name),
        };
    }

    function init() {
        const button = document.getElementById('news-publish-btn');
        if (!button) return;

        const data = window.ScoutMagicApi?.pageData('news-editor-data') || {};
        /** @type {Object<string, {title: string, message: string}>} */
        const questions = data.publishQuestions || {};
        const titleInput = /** @type {HTMLInputElement|null} */ (document.getElementById('news-title'));

        function update() {
            const selected = /** @type {HTMLInputElement|null} */ (
                document.querySelector('input[name="visibility"]:checked')
            );
            const question = questionFor(
                questions,
                selected ? selected.value : 'public',
                titleInput ? titleInput.value : ''
            );
            if (!question || !button) return;

            button.dataset.confirm = question.message;
            button.dataset.confirmTitle = question.title;
        }

        document.querySelectorAll('input[name="visibility"]').forEach(function (input) {
            input.addEventListener('change', update);
        });
        titleInput?.addEventListener('input', update);
        update();
    }

    // Test seam: the one pure decision of this file.
    /** @type {any} */ (globalThis).ScoutMagicNewsPublication = { questionFor: questionFor };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
