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
