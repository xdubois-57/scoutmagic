// Isolated JavaScript unit test — jsdom-simulated DOM only, no network.
// Exercises the REAL implementation in public/assets/js/documents-form.js
// (imported below, never reimplemented here). That file is an IIFE that
// reads the DOM at import time, so each test builds its fixture first and
// then imports the module via vi.resetModules() + await import().
//
// The fixture mirrors modules/documents/views/form.html.twig in edit mode.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function page(visibility) {
    const radio = (value) => `<input type="radio" name="visibility" value="${value}"`
        + `${value === visibility ? ' checked' : ''}>`;
    return `
        <form id="document-form">
            ${radio('public')}${radio('identified')}${radio('direct_link')}
            <div id="document-direct-link-help" class="d-none"></div>
            <input type="file" id="document-file">
            <div id="document-replace-warning" class="d-none"></div>
        </form>`;
}

describe('documents-form.js', () => {
    beforeEach(() => {
        vi.resetModules();
    });

    const boot = () => import('../../public/assets/js/documents-form.js');
    const hidden = (id) => document.getElementById(id).classList.contains('d-none');

    function pick(value) {
        const radio = document.querySelector(`input[name="visibility"][value="${value}"]`);
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    }

    function chooseFile(count) {
        const input = document.getElementById('document-file');
        const files = Array.from({ length: count }, (_, i) => new File(['x'], `f${i}.pdf`));
        Object.defineProperty(input, 'files', { value: files, configurable: true });
        input.dispatchEvent(new Event('change'));
    }

    it('does nothing at all on a page without the document form', async () => {
        document.body.innerHTML = '<p>Une autre page</p>';
        await expect(boot()).resolves.toBeDefined();
    });

    it('explains an unlisted document only once « Lien direct » is picked', async () => {
        document.body.innerHTML = page('public');
        await boot();
        expect(hidden('document-direct-link-help')).toBe(true);

        pick('direct_link');
        expect(hidden('document-direct-link-help')).toBe(false);

        pick('identified');
        expect(hidden('document-direct-link-help')).toBe(true);
    });

    it('shows the explanation at once for a document that is already unlisted', async () => {
        document.body.innerHTML = page('direct_link');
        await boot();
        expect(hidden('document-direct-link-help')).toBe(false);
    });

    it('warns about replacing the file only once a file has been chosen', async () => {
        document.body.innerHTML = page('public');
        await boot();
        expect(hidden('document-replace-warning')).toBe(true);

        chooseFile(1);
        expect(hidden('document-replace-warning')).toBe(false);

        chooseFile(0);
        expect(hidden('document-replace-warning')).toBe(true);
    });

    it('does not warn when a title or a visibility changes without a file', async () => {
        document.body.innerHTML = page('public');
        await boot();
        pick('identified');
        expect(hidden('document-replace-warning')).toBe(true);
    });
});
