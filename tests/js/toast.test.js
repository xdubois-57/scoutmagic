// Isolated JavaScript unit test — jsdom DOM only. Exercises the REAL
// public/assets/js/toast.js (imported below, never reimplemented): the
// file is an IIFE that installs window.ScoutMagicToast at import time.
// window.bootstrap is left undefined here on purpose, so the tests cover
// the fallback timer path — the Bootstrap path only changes who runs the
// hide animation.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadToast() {
    vi.resetModules();
    await import('../../public/assets/js/toast.js');
    return window.ScoutMagicToast;
}

beforeEach(() => {
    document.body.innerHTML = '';
    delete window.bootstrap;
    vi.useFakeTimers();
});

describe('ScoutMagicToast.show()', () => {
    it('renders the message as text into a polite live region', async () => {
        const toast = await loadToast();
        toast.show('Enregistré.');

        const container = document.querySelector('.toast-container');
        expect(container.getAttribute('aria-live')).toBe('polite');
        expect(container.querySelector('.toast-body').textContent).toBe('Enregistré.');
        expect(container.querySelector('.toast').className).toContain('text-bg-success');
    });

    it('never interprets the message as markup', async () => {
        const toast = await loadToast();
        toast.show('<img src=x onerror=alert(1)>');

        expect(document.querySelector('.toast-container img')).toBeNull();
        expect(document.querySelector('.toast-body').textContent).toContain('<img');
    });

    it('maps the flash vocabulary onto contrast-guaranteed tones', async () => {
        const toast = await loadToast();
        expect(toast.show('a', { variant: 'error' }).className).toContain('text-bg-danger');
        expect(toast.show('b', { variant: 'warning' }).className).toContain('text-bg-warning');
        expect(toast.show('c', { variant: 'info' }).className).toContain('text-bg-info');
    });

    it('removes itself after its delay, errors lingering longer by default', async () => {
        const toast = await loadToast();
        toast.show('vite');
        toast.show('erreur', { variant: 'error' });
        expect(document.querySelectorAll('.toast')).toHaveLength(2);

        vi.advanceTimersByTime(4000);
        expect(document.querySelectorAll('.toast')).toHaveLength(1);
        vi.advanceTimersByTime(4000);
        expect(document.querySelectorAll('.toast')).toHaveLength(0);
    });

    it('closes on its button without waiting', async () => {
        const toast = await loadToast();
        toast.show('à fermer');
        document.querySelector('.toast .btn-close').click();
        expect(document.querySelectorAll('.toast')).toHaveLength(0);
    });

    it('stacks several toasts in one container', async () => {
        const toast = await loadToast();
        toast.show('un');
        toast.show('deux');
        expect(document.querySelectorAll('.toast-container')).toHaveLength(1);
        expect(document.querySelectorAll('.toast')).toHaveLength(2);
    });
});
