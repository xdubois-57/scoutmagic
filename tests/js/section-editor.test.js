// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network. Bootstrap is faked: jsdom runs none
// of it, so `bootstrap.Modal.getOrCreateInstance` is a spy and the
// `shown.bs.modal` event is dispatched by hand — which is exactly what a
// real Bootstrap dialog does once its opening transition ends.
//
// Exercises the REAL implementation in public/assets/js/section-editor.js
// (imported below, never reimplemented here). That file is an IIFE that
// reads the DOM at import time, so each test builds its fixture first and
// then imports the module via vi.resetModules() + await import().
//
// The fixture mirrors what a settings screen renders: a read card, and
// the dialog its « Modifier » opens (partials/modal.html.twig's frame,
// carrying `data-section-editor`).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <div class="card" id="tarification">
        <button type="button" data-bs-toggle="modal" data-bs-target="#tarification-edit">Modifier</button>
        <p>80,00 €</p>
    </div>
    <div class="modal fade" id="tarification-edit" data-section-editor>
        <form id="pricing-form">
            <input type="hidden" name="_csrf_token" value="tok">
            <input type="text" id="default-unit-price" name="default_unit_price">
            <textarea id="pricing-note"></textarea>
        </form>
    </div>
    <div class="modal fade" id="paiements-edit" data-section-editor>
        <form id="payments-form">
            <input type="text" id="deposit-amount" disabled>
            <input type="text" id="balance-due-days" readonly>
            <select id="deposit-mode"></select>
        </form>
    </div>
    <div class="modal fade" id="not-a-section">
        <input type="text" id="stray">
    </div>`;

async function load(hash = '') {
    vi.resetModules();
    document.body.innerHTML = PAGE;
    window.location.hash = hash;
    await import('../../public/assets/js/section-editor.js');
}

describe('section-editor.js', () => {
    let instance;

    beforeEach(() => {
        instance = { show: vi.fn(), hide: vi.fn() };
        window.bootstrap = {
            Modal: { getOrCreateInstance: vi.fn(() => instance) }
        };
    });

    describe('focus', () => {
        it('puts the caret in the first real field when a dialog opens', async () => {
            await load();

            const dialog = document.getElementById('tarification-edit');
            dialog.dispatchEvent(new Event('shown.bs.modal'));

            // Not the CSRF token, which is the first <input> in the DOM.
            expect(document.activeElement.id).toBe('default-unit-price');
        });

        it('skips a control that cannot be typed in', async () => {
            await load();

            const dialog = document.getElementById('paiements-edit');
            dialog.dispatchEvent(new Event('shown.bs.modal'));

            // Focusing a disabled control silently does nothing, which
            // reads as the dialog ignoring the keyboard.
            expect(document.activeElement.id).toBe('deposit-mode');
        });

        it('moves on when the browser refuses the focus', async () => {
            // The real case: the managers dialog ships the plain <select>
            // a no-JavaScript visitor gets, and rental-managers.js hides
            // it in favour of a search box — so the first control in the
            // markup is exactly the one that must not be focused. jsdom
            // has no layout and focuses anything, so the refusal is
            // simulated the only honest way: focus() that does nothing.
            await load();

            const refused = document.getElementById('default-unit-price');
            refused.focus = () => {};

            document.getElementById('tarification-edit').dispatchEvent(new Event('shown.bs.modal'));

            expect(document.activeElement.id).toBe('pricing-note');
        });

        it('leaves a dialog that is not a section editor alone', async () => {
            await load();

            const dialog = document.getElementById('not-a-section');
            dialog.dispatchEvent(new Event('shown.bs.modal'));

            expect(document.activeElement.id).not.toBe('stray');
        });

        it('survives a dialog with nothing focusable in it', async () => {
            vi.resetModules();
            document.body.innerHTML = '<div class="modal" id="empty-edit" data-section-editor></div>';
            await import('../../public/assets/js/section-editor.js');

            expect(() => {
                document.getElementById('empty-edit').dispatchEvent(new Event('shown.bs.modal'));
            }).not.toThrow();
        });
    });

    describe('openFromHash', () => {
        it('opens the dialog a link names', async () => {
            // « Ce bien n'a encore aucun tarif » links straight at the
            // editor; a plain anchor would scroll a hidden .modal into
            // view, which is to say scroll to nothing.
            await load('#tarification-edit');

            expect(window.bootstrap.Modal.getOrCreateInstance).toHaveBeenCalledWith(
                document.getElementById('tarification-edit')
            );
            expect(instance.show).toHaveBeenCalledTimes(1);
        });

        it('leaves an ordinary section anchor to the browser', async () => {
            await load('#tarification');

            expect(instance.show).not.toHaveBeenCalled();
        });

        it('refuses to open a dialog that is not a section editor', async () => {
            await load('#not-a-section');

            expect(instance.show).not.toHaveBeenCalled();
        });

        it('is a no-op on an empty or unknown fragment', async () => {
            await load();

            expect(window.ScoutMagicSectionEditor.openFromHash('')).toBe(false);
            expect(window.ScoutMagicSectionEditor.openFromHash('#nothing-here')).toBe(false);
            expect(instance.show).not.toHaveBeenCalled();
        });

        it('accepts a fragment written without its #', async () => {
            await load();

            expect(window.ScoutMagicSectionEditor.openFromHash('paiements-edit')).toBe(true);
            expect(instance.show).toHaveBeenCalledTimes(1);
        });

        it('does not throw when Bootstrap has not loaded', async () => {
            vi.resetModules();
            document.body.innerHTML = PAGE;
            delete window.bootstrap;

            await expect(import('../../public/assets/js/section-editor.js')).resolves.toBeDefined();
        });
    });
});
