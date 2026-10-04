// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/documents-manage.js (imported below, never
// reimplemented): the « copy the link » button of the documents list
// (#731), and its fallback when the clipboard is unavailable.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const LINK = 'https://unite.example/documents/reglement';

beforeEach(async () => {
    document.body.innerHTML = `<button type="button" data-copy-link="${LINK}"><i class="bi bi-link-45deg"></i></button>`;
    window.ScoutMagicToast = { show: vi.fn() };
    window.ScoutMagicConfirm = { prompt: vi.fn(() => Promise.resolve(null)) };
    vi.resetModules();
    await import('../../public/assets/js/documents-manage.js');
});

afterEach(() => {
    vi.restoreAllMocks();
    delete navigator.clipboard;
});

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('documents-manage.js', () => {
    it('copies the link, even when the icon inside the button is what was clicked', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

        document.querySelector('i').click();
        await settle();

        expect(writeText).toHaveBeenCalledWith(LINK);
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Lien copié.', { variant: 'success' });
    });

    it('shows the link ready to copy by hand when there is no clipboard', async () => {
        document.querySelector('button').click();
        await settle();

        expect(window.ScoutMagicConfirm.prompt).toHaveBeenCalledWith(
            expect.objectContaining({ value: LINK, readonly: true })
        );
    });

    it('ignores every other click', async () => {
        document.body.insertAdjacentHTML('beforeend', '<button id="other">Autre</button>');

        document.getElementById('other').click();
        await settle();

        expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        expect(window.ScoutMagicConfirm.prompt).not.toHaveBeenCalled();
    });
});
