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
//
// « Programmer… » and « Modifier l'heure » (part 2) open the same dialog's
// form: a date and a native time field, to the minute. What it returns
// goes into the editor form's two hidden fields, and the form is
// submitted through its hidden « schedule » button — the article is saved
// first like with every other button, and the server refuses a time
// already past.
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

    /**
     * The sentence under the dialog's date and time, for this visibility
     * and this title.
     *
     * @param {Object<string, string>} messages
     * @param {string} visibility
     * @param {string} title
     * @returns {string}
     */
    function scheduleMessageFor(messages, visibility, title) {
        const message = messages[visibility] || messages.public || '';
        const name = title.trim() === '' ? 'Cette actualité' : title.trim();

        return message.split('{titre}').join(name);
    }

    function selectedVisibility() {
        const selected = /** @type {HTMLInputElement|null} */ (
            document.querySelector('input[name="visibility"]:checked')
        );
        return selected ? selected.value : 'public';
    }

    /**
     * @param {Object<string, string>} messages
     * @param {HTMLInputElement|null} titleInput
     */
    function initSchedule(messages, titleInput) {
        const form = /** @type {HTMLFormElement|null} */ (document.getElementById('news-editor-form'));
        const date = /** @type {HTMLInputElement|null} */ (document.getElementById('news-schedule-date'));
        const time = /** @type {HTMLInputElement|null} */ (document.getElementById('news-schedule-time'));
        const submit = /** @type {HTMLButtonElement|null} */ (document.getElementById('news-schedule-submit'));
        if (!form || !date || !time || !submit) return;

        document.querySelectorAll('[data-news-schedule]').forEach(function (button) {
            button.addEventListener('click', function () {
                const confirm = /** @type {any} */ (window).ScoutMagicConfirm;
                if (!confirm) return;

                confirm.form({
                    title: 'Programmer la publication',
                    message: scheduleMessageFor(messages, selectedVisibility(), titleInput ? titleInput.value : ''),
                    fields: [
                        { name: 'schedule_date', label: 'Date', inputType: 'date', value: date.value },
                        { name: 'schedule_time', label: 'Heure', inputType: 'time', value: time.value },
                    ],
                    confirmLabel: 'Programmer',
                }).then(function (/** @type {Object<string, string>|null} */ values) {
                    if (!values) return;
                    date.value = values.schedule_date || '';
                    time.value = values.schedule_time || '';
                    form.requestSubmit(submit);
                });
            });
        });
    }

    function init() {
        const data = window.ScoutMagicApi?.pageData('news-editor-data') || {};
        /** @type {Object<string, {title: string, message: string}>} */
        const questions = data.publishQuestions || {};
        const titleInput = /** @type {HTMLInputElement|null} */ (document.getElementById('news-title'));

        initSchedule(data.scheduleMessages || {}, titleInput);

        const button = document.getElementById('news-publish-btn');
        if (!button) return;

        function update() {
            const question = questionFor(
                questions,
                selectedVisibility(),
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

    // Test seam: the pure decisions of this file.
    /** @type {any} */ (globalThis).ScoutMagicNewsPublication = {
        questionFor: questionFor,
        scheduleMessageFor: scheduleMessageFor,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
