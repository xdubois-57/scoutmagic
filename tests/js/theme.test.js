// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network. Exercises the REAL implementation in
// public/assets/js/theme.js (imported below, never reimplemented here).
// Each test re-imports a fresh module instance (vi.resetModules + dynamic
// import) so the module-scope preference read and the matchMedia
// registration run against that test's own environment, then dispatches
// DOMContentLoaded manually (jsdom's document is already 'complete' when
// the import runs, so the real event never fires on its own).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const KEY = 'theme_preference';

function clearConsentCookie() {
    document.cookie = 'cookie_consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
}

function giveConsent(functional) {
    document.cookie = 'cookie_consent=' + encodeURIComponent(JSON.stringify({ functional, analytics: false }));
}

function stubMatchMedia(matches) {
    const listeners = [];
    const mql = {
        matches,
        addEventListener: (type, fn) => { listeners.push(fn); },
        fire() { listeners.forEach((fn) => fn({ matches: this.matches })); },
    };
    vi.stubGlobal('matchMedia', vi.fn(() => mql));
    return mql;
}

function renderToggle() {
    document.body.innerHTML = `
        <button type="button" data-theme-toggle aria-label="Thème : automatique (cliquer pour changer)">
            <i class="bi bi-circle-half" data-theme-toggle-icon></i>
        </button>
    `;
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/theme.js');
    document.dispatchEvent(new Event('DOMContentLoaded'));
    return window.ScoutMagicTheme;
}

describe('theme.js', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
        localStorage.clear();
        clearConsentCookie();
        document.documentElement.removeAttribute('data-bs-theme');
        document.body.innerHTML = '';
    });

    // The whole point of the offline page loading THIS file from its head:
    // it has no inline nonce'd script to set the attribute (a nonce baked
    // into a service-worker-precached response goes stale immediately), so
    // the theme has to be applied by this script's own evaluation, before
    // DOMContentLoaded and before the first paint. It used to be applied
    // only on DOMContentLoaded, and pwa/offline.html.twig was served in
    // light mode to every dark-mode reader.
    it('applies the theme as soon as it is evaluated, before DOMContentLoaded', async () => {
        stubMatchMedia(true);
        document.documentElement.removeAttribute('data-bs-theme');

        vi.resetModules();
        await import('../../public/assets/js/theme.js');

        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
    });

    it('defaults to automatique and applies light when the system has no dark preference', async () => {
        const theme = await boot();

        expect(theme.getPreference()).toBe('auto');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
    });

    it('applies dark in automatique when the system prefers dark', async () => {
        stubMatchMedia(true);
        const theme = await boot();

        expect(theme.getPreference()).toBe('auto');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
    });

    it('reads a stored preference and applies it regardless of the system scheme', async () => {
        stubMatchMedia(false);
        localStorage.setItem(KEY, 'dark');
        const theme = await boot();

        expect(theme.getPreference()).toBe('dark');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
    });

    it('treats an invalid stored value as automatique', async () => {
        localStorage.setItem(KEY, 'blue');
        const theme = await boot();

        expect(theme.getPreference()).toBe('auto');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
    });

    it('cycles clair → sombre → automatique → clair on toggle clicks and updates icon and aria-label', async () => {
        giveConsent(true);
        localStorage.setItem(KEY, 'light');
        renderToggle();
        const theme = await boot();
        const button = document.querySelector('[data-theme-toggle]');
        const icon = document.querySelector('[data-theme-toggle-icon]');

        expect(theme.getPreference()).toBe('light');
        expect(icon.classList.contains('bi-sun')).toBe(true);

        button.click();
        expect(theme.getPreference()).toBe('dark');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
        expect(icon.classList.contains('bi-moon-stars')).toBe(true);
        expect(button.getAttribute('aria-label')).toContain('sombre');

        button.click();
        expect(theme.getPreference()).toBe('auto');
        expect(icon.classList.contains('bi-circle-half')).toBe(true);
        expect(button.getAttribute('aria-label')).toContain('automatique');

        button.click();
        expect(theme.getPreference()).toBe('light');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
        expect(button.getAttribute('aria-label')).toContain('clair');
    });

    it('persists the preference with functional consent, and stores automatique as an absence', async () => {
        giveConsent(true);
        const theme = await boot();

        theme.setPreference('dark');
        expect(localStorage.getItem(KEY)).toBe('dark');

        theme.setPreference('auto');
        expect(localStorage.getItem(KEY)).toBeNull();
    });

    it('still applies but never persists without any consent cookie', async () => {
        const theme = await boot();

        theme.setPreference('dark');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
        expect(localStorage.getItem(KEY)).toBeNull();
        expect(theme.hasFunctionalConsent()).toBe(false);
    });

    it('still applies but never persists when functional consent was refused', async () => {
        giveConsent(false);
        const theme = await boot();

        theme.setPreference('dark');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
        expect(localStorage.getItem(KEY)).toBeNull();
    });

    it('ignores an invalid preference value', async () => {
        giveConsent(true);
        const theme = await boot();

        theme.setPreference('blue');
        expect(theme.getPreference()).toBe('auto');
        expect(localStorage.getItem(KEY)).toBeNull();
    });

    it('follows a live system scheme change while in automatique, and stops following once explicit', async () => {
        const mql = stubMatchMedia(false);
        const theme = await boot();
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');

        mql.matches = true;
        mql.fire();
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');

        theme.setPreference('light');
        mql.matches = false;
        mql.fire();
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
        mql.matches = true;
        mql.fire();
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('light');
    });
});
