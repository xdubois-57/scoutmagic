// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/text-page-form.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors what core/View/templates/config/text_pages/
// form.html.twig renders: the section picker, the column picker inside
// the element that hides it, and the hidden input carrying the column to
// preselect.
import { beforeEach, describe, expect, it, vi } from 'vitest';

// The real MenuBuilder shape, trimmed to three sections: one public menu
// with no columns at all, and two with columns — which is the whole
// reason the second picker is conditional.
const GROUPS = {
    notre_unite: {},
    espace_animes: { pages: 'Pages', vie: 'Vie de section' },
    configuration: { site: 'Site', unite_donnees: 'Unité et données' },
};

// The server renders the section already selected — the page's own on an
// edit, « Notre unité » on a creation — so the fixture takes it too.
function page(selectedGroup = '', selectedSection = 'notre_unite') {
    const option = (value, label) =>
        `<option value="${value}"${value === selectedSection ? ' selected' : ''}>${label}</option>`;

    return `
        <form data-text-page-form data-groups='${JSON.stringify(GROUPS)}'>
            <select name="menu_id">
                ${option('notre_unite', 'Notre unité')}
                ${option('espace_animes', 'Espace membres')}
                ${option('configuration', 'Configuration')}
            </select>
            <div data-text-page-group-field>
                <select name="menu_group"></select>
            </div>
            <input type="hidden" data-text-page-selected-group value="${selectedGroup}">
        </form>`;
}

async function load(html) {
    document.body.innerHTML = html;
    vi.resetModules();
    await import('../../public/assets/js/text-page-form.js');
}

function section() {
    return /** @type {HTMLSelectElement} */ (document.querySelector('[name="menu_id"]'));
}

function group() {
    return /** @type {HTMLSelectElement} */ (document.querySelector('[name="menu_group"]'));
}

function wrapper() {
    return /** @type {HTMLElement} */ (document.querySelector('[data-text-page-group-field]'));
}

function choose(value) {
    section().value = value;
    section().dispatchEvent(new Event('change'));
}

describe('text-page-form.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('hides the column picker for a section that declares no columns', async () => {
        await load(page());

        expect(wrapper().hidden).toBe(true);
        expect(group().options.length).toBe(0);
    });

    // Hidden is not enough: a hidden <select> still submits its value, and
    // an empty string is not the null the server expects for « Notre
    // unité » — TextPageService::assertMenuPlacement() refuses a column on
    // a menu that has none.
    it('disables the column picker too, so nothing is submitted for it', async () => {
        await load(page());

        expect(group().disabled).toBe(true);
    });

    it('fills and shows the picker when a section with columns is chosen', async () => {
        await load(page());

        choose('espace_animes');

        expect(wrapper().hidden).toBe(false);
        expect(group().disabled).toBe(false);
        expect([...group().options].map((o) => o.value)).toEqual(['pages', 'vie']);
        expect([...group().options].map((o) => o.textContent)).toEqual(['Pages', 'Vie de section']);
    });

    // The edit flow: the server renders the page's own section and its own
    // column, and opening the form must not quietly move the page to the
    // first column of its section.
    it('preselects the column the server asked for, without a change event', async () => {
        await load(page('unite_donnees', 'configuration'));

        expect(wrapper().hidden).toBe(false);
        expect(group().value).toBe('unite_donnees');
    });

    // Switching sections cannot leave the old column selected: « site »
    // exists in Configuration and not in Espace membres, and submitting it
    // there is exactly the pair the server refuses.
    it('falls back to the first column when the current one does not exist in the new section', async () => {
        await load(page('site', 'configuration'));

        expect(group().value).toBe('site');

        choose('espace_animes');
        expect(group().value).toBe('pages');
    });

    it('keeps the current column when the new section also declares it', async () => {
        const groups = { a: { shared: 'Partagée', only_a: 'A' }, b: { shared: 'Partagée' } };
        await load(`
            <form data-text-page-form data-groups='${JSON.stringify(groups)}'>
                <select name="menu_id"><option value="a" selected>A</option><option value="b">B</option></select>
                <div data-text-page-group-field><select name="menu_group"></select></div>
                <input type="hidden" data-text-page-selected-group value="shared">
            </form>`);

        choose('b');

        expect(group().value).toBe('shared');
    });

    // A malformed payload must not leave the form unusable — the server
    // decides the pair anyway, which is where the decision belongs.
    it('survives a data-groups attribute that is not JSON', async () => {
        await load(`
            <form data-text-page-form data-groups='not json at all'>
                <select name="menu_id"><option value="configuration" selected>Configuration</option></select>
                <div data-text-page-group-field><select name="menu_group"></select></div>
                <input type="hidden" data-text-page-selected-group value="site">
            </form>`);

        expect(wrapper().hidden).toBe(true);
        expect(group().disabled).toBe(true);
    });
});
