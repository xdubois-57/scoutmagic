// Isolated JavaScript unit test — jsdom only. Exercises the REAL
// public/assets/js/news-publication.js (issue #845): the question
// « Publier » asks follows the visibility radio and the title field.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/assets/js/api.js';

const QUESTIONS = {
    public: { title: 'Publier pour tout le monde ?', message: '« {titre} » apparaîtra pour tout le monde.' },
    chief: { title: 'Publier pour les animateurs ?', message: '« {titre} » s\'ouvrira aux animateurs.' },
};

async function loadPublication() {
    vi.resetModules();
    await import('../../public/assets/js/news-publication.js?t=' + Math.random());
    return globalThis.ScoutMagicNewsPublication;
}

describe('news-publication.js: questionFor()', () => {
    it('picks the question of the visibility and fills the title in', async () => {
        const { questionFor } = await loadPublication();

        expect(questionFor(QUESTIONS, 'chief', ' Souper ')).toEqual({
            title: 'Publier pour les animateurs ?',
            message: '« Souper » s\'ouvrira aux animateurs.',
        });
    });

    it('names an untitled article « Cette actualité »', async () => {
        const { questionFor } = await loadPublication();

        expect(questionFor(QUESTIONS, 'public', '   ').message).toBe('« Cette actualité » apparaîtra pour tout le monde.');
    });

    it('falls back on the public question, and on nothing without any', async () => {
        const { questionFor } = await loadPublication();

        expect(questionFor(QUESTIONS, 'unknown', 'X').title).toBe('Publier pour tout le monde ?');
        expect(questionFor({}, 'public', 'X')).toBeNull();
    });
});

describe('news-publication.js: scheduleMessageFor()', () => {
    it('picks the visibility\'s sentence and fills the title in', async () => {
        const { scheduleMessageFor } = await loadPublication();
        const messages = { public: 'Pour tous : « {titre} ».', chief: 'Pour les animateurs : « {titre} ».' };

        expect(scheduleMessageFor(messages, 'chief', 'Souper')).toBe('Pour les animateurs : « Souper ».');
        expect(scheduleMessageFor(messages, 'unknown', '')).toBe('Pour tous : « Cette actualité ».');
    });
});

describe('news-publication.js: « Programmer… »', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <script type="application/json" id="news-editor-data">${JSON.stringify({
                publishQuestions: QUESTIONS,
                scheduleMessages: { public: 'À l\'heure choisie, « {titre} » apparaîtra pour tout le monde.' },
            })}</script>
            <form id="news-editor-form">
                <input id="news-title" value="Souper">
                <input type="radio" name="visibility" value="public" checked>
                <input type="hidden" name="schedule_date" id="news-schedule-date" value="2026-10-12">
                <input type="hidden" name="schedule_time" id="news-schedule-time" value="08:00">
                <button type="submit" name="intent" value="schedule" id="news-schedule-submit" hidden></button>
                <button type="button" data-news-schedule>Programmer…</button>
            </form>`;
    });

    it('asks a date and a time, then submits them through the schedule button', async () => {
        const form = vi.fn().mockResolvedValue({ schedule_date: '2026-10-20', schedule_time: '18:30' });
        window.ScoutMagicConfirm = { form };
        await loadPublication();
        const editor = /** @type {HTMLFormElement} */ (document.getElementById('news-editor-form'));
        const replay = vi.fn();
        editor.requestSubmit = replay;

        document.querySelector('[data-news-schedule]').click();
        await Promise.resolve();
        await Promise.resolve();

        const asked = form.mock.calls[0][0];
        expect(asked.title).toBe('Programmer la publication');
        expect(asked.message).toBe('À l\'heure choisie, « Souper » apparaîtra pour tout le monde.');
        expect(asked.fields.map((f) => [f.name, f.inputType, f.value])).toEqual([
            ['schedule_date', 'date', '2026-10-12'],
            ['schedule_time', 'time', '08:00'],
        ]);
        expect(document.getElementById('news-schedule-date').value).toBe('2026-10-20');
        expect(document.getElementById('news-schedule-time').value).toBe('18:30');
        expect(replay).toHaveBeenCalledWith(document.getElementById('news-schedule-submit'));
    });

    it('submits nothing when the dialog is dismissed', async () => {
        window.ScoutMagicConfirm = { form: vi.fn().mockResolvedValue(null) };
        await loadPublication();
        const editor = /** @type {HTMLFormElement} */ (document.getElementById('news-editor-form'));
        const replay = vi.fn();
        editor.requestSubmit = replay;

        document.querySelector('[data-news-schedule]').click();
        await Promise.resolve();
        await Promise.resolve();

        expect(replay).not.toHaveBeenCalled();
        expect(document.getElementById('news-schedule-time').value).toBe('08:00');
    });
});

describe('news-publication.js: the button', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <script type="application/json" id="news-editor-data">${JSON.stringify({ publishQuestions: QUESTIONS })}</script>
            <input id="news-title" value="Souper">
            <input type="radio" name="visibility" value="public" checked>
            <input type="radio" name="visibility" value="chief">
            <button id="news-publish-btn" data-confirm="" data-confirm-title=""></button>`;
    });

    it('carries the question of the radio chosen, again on every change', async () => {
        await loadPublication();
        const button = document.getElementById('news-publish-btn');
        expect(button.dataset.confirmTitle).toBe('Publier pour tout le monde ?');

        const chief = document.querySelector('input[value="chief"]');
        chief.checked = true;
        chief.dispatchEvent(new window.Event('change'));
        expect(button.dataset.confirmTitle).toBe('Publier pour les animateurs ?');

        const title = document.getElementById('news-title');
        title.value = 'Camp';
        title.dispatchEvent(new window.Event('input'));
        expect(button.dataset.confirm).toBe('« Camp » s\'ouvrira aux animateurs.');
    });
});
