// End-to-end: every `document.execCommand` this product issues is still
// performed by a real browser engine.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Issue #379. Six files under `public/assets/js/` reach for
// `document.execCommand`, between them issuing twelve distinct commands:
// the shared toolbar in rich-text-link.js, `insertHTML` in
// rich-text-form-field.js, `insertText` in mass-mail-compose.js,
// `insertImage` and its own toolbar in news-form-builder.js, and `copy` in
// retro-board.js and setup.js. The HTML specification deprecated the API
// years ago and specified no replacement — the Editing API meant to
// succeed it was never implemented interoperably.
//
// Nothing is broken today: every engine still implements it. The day one
// removes it, most of that list stops working **silently** — the buttons
// do nothing, no exception is raised, and nobody finds out until a section
// chief reports that they can no longer put a heading in a page. The issue
// counts two occurrences in one file; it was written from the two SonarCloud
// findings open at the time, and the call sites are wider than that.
//
// This spec is the alarm the maintainer asked for on that issue: « assure
// toi d'avoir un test qui échoue si jamais le navigateur arrête de
// supporter ». It goes red on the first CI run against an engine that has
// dropped the API.
//
// WHY NOT UNDER jsdom
// ----------------------------------------------------------------------------
// jsdom does not implement `document.execCommand` at all, so the four
// Vitest suites over these toolbars (tests/js/rich-text-link.test.js and
// its three callers) all replace it with a spy. That is the right test for
// what they assert — "the button asks for `<h2>`, once, not twice" — and it
// is structurally incapable of being this one: a spy answers whatever the
// test told it to, including on an engine where the real API is gone.
// Only a real browser can be asked whether the API still WORKS.
//
// WHAT IT ASSERTS, AND WHAT IT DELIBERATELY DOES NOT
// ----------------------------------------------------------------------------
// For the toolbar it asserts the markup the editor PRODUCES, never the API
// it went through.
// That is what makes it outlive the fix rather than have to be rewritten by
// it: option 1 of the issue — rebuilding the toolbar on `Selection`/`Range`
// — must keep this spec green, and a spec asserting `execCommand` was
// called would instead have to be deleted the day it stopped being.
//
// Bold is accepted as `<b>` OR `<strong>`, italic as `<i>` OR `<em>`.
// Measured, not assumed: Chromium 141 produces `<b>`, `<i>` and `<u>` —
// the issue's own sketch of this test predicted `<strong>` and `<em>`, and
// would have been red on the day it was written. Both spellings are in
// `Core\Security\HtmlSanitizer::ALLOWED`, so either survives the save, and
// an engine that switched from one to the other would be a change of
// spelling rather than a loss of function. Pinning one of them would make
// this alarm ring for the wrong reason, which is how an alarm gets muted.
//
// ONE TEST, EVERY COMMAND, ASSERTED TOGETHER
// ----------------------------------------------------------------------------
// The failure this exists to catch takes every command out at once, so the
// results are collected into one object and asserted in one go. A test per
// command would report the first and hide the rest, and the shape of the
// report — which commands still work and what the engine did instead — is
// the diagnosis. That is not a hypothetical preference: the first version
// of this spec asserted the link command separately, and under a simulated
// removal it reported one line about a link dialog and nothing about the
// eleven other commands that had failed with it.
//
// NO LOGIN, NO SEEDED STATE
// ----------------------------------------------------------------------------
// `rich-text-link.js` is loaded by core/View/templates/base.html.twig on
// every page, for anonymous visitors included, so the public home page
// carries the production toolbox. The fixture is built by this spec inside
// that page and thrown away with it: nothing is saved, so no other spec
// reads different content afterwards. What is exercised is the real
// `wireToolbar()` over the real button markup of
// partials/rich_text_editor.html.twig — not a copy of its behaviour.
import { expect, test } from '@playwright/test';

import { answerConfirmation } from '../support/confirm-dialog.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
import { scaled } from '../support/timeouts.js';

/** What the fixture's surface is reset to before each command. */
const TEXT = 'Bonjour';

/**
 * What a command that still works is reported as. Every entry of the
 * summary is this string when the engine performs the command, so the
 * failure diff names the commands that stopped and shows what the engine
 * did instead for each.
 */
const STILL_PERFORMED = 'still performed';

/** The URL typed into the link dialog. */
const LINK = 'https://example.org/';

/**
 * The commands issued BY THE SHARED TOOLBAR, exercised through the
 * production `wireToolbar()` over the production button markup.
 *
 * The buttons are those of `partials/rich_text_editor.html.twig` and
 * `partials/rich_text_form_field.html.twig`.
 *
 * @type {{command: string, value?: string, seed?: string, produces: RegExp}[]}
 */
const TOOLBAR_COMMANDS = [
    { command: 'bold', produces: /<(b|strong)>Bonjour<\/(b|strong)>/ },
    { command: 'italic', produces: /<(i|em)>Bonjour<\/(i|em)>/ },
    { command: 'underline', produces: /<u>Bonjour<\/u>/ },
    { command: 'insertUnorderedList', produces: /<ul>\s*<li>Bonjour<\/li>\s*<\/ul>/ },
    { command: 'insertOrderedList', produces: /<ol>\s*<li>Bonjour<\/li>\s*<\/ol>/ },
    { command: 'formatBlock', value: 'h2', produces: /<h2>Bonjour<\/h2>/ },
    { command: 'formatBlock', value: 'h3', produces: /<h3>Bonjour<\/h3>/ },
    { command: 'formatBlock', value: 'p', produces: /<p>Bonjour<\/p>/ },
    // The one command that removes markup instead of adding it, so it is
    // seeded with something to remove. Asserted as "no element left",
    // which is the effect, rather than as the empty string.
    { command: 'removeFormat', seed: '<b>Bonjour</b>', produces: /^Bonjour$/ },
];

/**
 * The one toolbar command that opens a dialog before it runs, so it cannot
 * sit in the table above: it is clicked, answered, and then waited for.
 *
 * Declared here rather than built inline, so that
 * tests/Architecture/DeprecatedBrowserApiIsWatchedTest.php can read it —
 * that test greps this file for `command: '…'` and told the truth about
 * the first version of this spec, which exercised the link button without
 * ever naming it.
 *
 * @type {{command: string, usedBy: string}}
 */
const LINK_COMMAND = { command: 'createLink', usedBy: 'rich-text-link.js, news-form-builder.js' };

/**
 * The commands the rest of the product issues DIRECTLY, each from its own
 * page and its own module — so each is issued here the way its module
 * issues it, rather than through a toolbar that never carries it.
 *
 * Driving these through their real interfaces would mean four more
 * scenarios across four features (a mass-mail composer, a news form
 * builder, a retro board, the installer). The question this spec asks is
 * narrower and the same for all of them — does the engine still perform
 * the command — so they are asked it directly, on the same surface.
 *
 * @type {{command: string, argument?: string, clipboard?: true,
 *         produces?: RegExp, usedBy: string}[]}
 */
const DIRECT_COMMANDS = [
    // The merge-field chip of a mass-mail template.
    {
        command: 'insertHTML',
        argument: '<strong>chip</strong>&nbsp;',
        produces: /<strong>chip<\/strong>/,
        usedBy: 'rich-text-form-field.js',
    },
    // The « {{ prénom }} » token dropped into a message being composed.
    {
        command: 'insertText',
        argument: 'TOKEN',
        produces: /^TOKEN$/,
        usedBy: 'mass-mail-compose.js',
    },
    // « Insérer une image » in the news form builder's own toolbar, which
    // is built in JavaScript rather than in a template.
    {
        command: 'insertImage',
        argument: 'https://example.org/i.png',
        produces: /<img src="https:\/\/example\.org\/i\.png">/,
        usedBy: 'news-form-builder.js',
    },
    // Copy-to-clipboard. THE ONE ENTRY WHOSE LOSS IS NOT SILENT BREAKAGE:
    // both call sites reach it only after `navigator.clipboard` has been
    // found missing — retro-board.js tests `navigator.clipboard?.writeText`
    // and setup.js documents the non-secure-context install as the case it
    // covers. An engine dropping this one degrades an HTTP install's copy
    // button, where the async API is unavailable; everywhere else the
    // modern path is already the one taken.
    //
    // Asserted on the RETURN VALUE rather than on the DOM, because that is
    // what production branches on (`if (document.execCommand('copy'))` in
    // setup.js) and because what the command produces is a clipboard, not
    // markup.
    {
        command: 'copy',
        clipboard: true,
        usedBy: 'retro-board.js, setup.js — both as a fallback',
    },
];

/**
 * Builds the toolbar and the editing surface inside the page, and wires
 * them with the production `wireToolbar()`.
 *
 * The button markup mirrors the templates: `type="button"` (a bare
 * `<button>` inside a form would submit it) and the `data-command` /
 * `data-value` pair the toolbox reads.
 *
 * @param {import('@playwright/test').Page} page
 */
async function buildFixture(page) {
    await page.evaluate(([commands, text]) => {
        const holder = document.createElement('div');
        holder.id = 'e2e-toolbox';

        const toolbar = document.createElement('div');
        toolbar.id = 'e2e-toolbar';
        for (const { command, value } of commands) {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.command = command;
            if (value !== undefined) {
                button.dataset.value = value;
            }
            button.textContent = command + (value ? ':' + value : '');
            toolbar.appendChild(button);
        }
        // The link button is not in the table: it answers a dialog, so the
        // test drives it on its own further down.
        const link = document.createElement('button');
        link.type = 'button';
        link.dataset.command = 'createLink';
        link.textContent = 'createLink';
        toolbar.appendChild(link);

        const surface = document.createElement('div');
        surface.id = 'e2e-surface';
        surface.contentEditable = 'true';
        surface.textContent = text;

        holder.append(toolbar, surface);
        document.body.prepend(holder);

        window.ScoutMagicRichText.wireToolbar(toolbar, surface);
    }, [TOOLBAR_COMMANDS.map(({ command, value }) => ({ command, value })), TEXT]);

    await expect(page.locator('#e2e-surface')).toBeVisible();
}

/**
 * Puts the surface back to a known state and selects all of it — the
 * gesture of an author who selects their paragraph before clicking.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} html what the surface holds before the command runs
 */
async function seedAndSelect(page, html) {
    await page.evaluate((seed) => {
        const surface = /** @type {HTMLElement} */ (document.getElementById('e2e-surface'));
        surface.innerHTML = seed;
        surface.focus();
        const range = document.createRange();
        range.selectNodeContents(surface);
        const selection = window.getSelection();
        if (selection === null) {
            throw new Error('no Selection object — the engine cannot be asked this question');
        }
        selection.removeAllRanges();
        selection.addRange(range);
    }, html);
}

/** @param {import('@playwright/test').Page} page */
function surfaceHtml(page) {
    return page.locator('#e2e-surface').evaluate((surface) => surface.innerHTML.trim());
}

/**
 * The label a command is reported under, so a failure names the button
 * rather than an index.
 *
 * @param {{command: string, value?: string}} entry
 */
function label(entry) {
    return entry.value === undefined ? entry.command : `${entry.command}:${entry.value}`;
}

/**
 * Issues one of the DIRECT_COMMANDS the way its own module issues it, and
 * says whether the engine performed it.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{command: string, argument?: string, clipboard?: true, produces?: RegExp}} entry
 * @returns {Promise<string>} STILL_PERFORMED, or what the engine did instead
 */
async function runDirect(page, entry) {
    if (entry.clipboard === true) {
        // retro-board.js's own shape: a throwaway input, selected, copied.
        const accepted = await page.evaluate(() => {
            const field = document.createElement('input');
            field.value = 'https://example.org/retro/abc';
            document.body.appendChild(field);
            field.select();
            let done = false;
            try {
                done = document.execCommand('copy');
            } finally {
                field.remove();
            }
            return done;
        });
        return accepted ? STILL_PERFORMED : 'the engine refused the command';
    }

    await seedAndSelect(page, TEXT);
    const html = await page.evaluate(([command, argument]) => {
        const surface = /** @type {HTMLElement} */ (document.getElementById('e2e-surface'));
        document.execCommand(command, false, argument ?? null);
        return surface.innerHTML.trim();
    }, [entry.command, entry.argument]);

    return entry.produces !== undefined && entry.produces.test(html) ? STILL_PERFORMED : html;
}

test('every execCommand the product issues is still performed by this browser engine', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto('/', { waitUntil: 'load' });
    await answerCookieBanner(page);
    await buildFixture(page);

    /** @type {Record<string, string>} */
    const produced = {};
    /** @type {Record<string, string>} */
    const expected = {};

    for (const entry of TOOLBAR_COMMANDS) {
        const name = label(entry);
        expected[name] = STILL_PERFORMED;

        await seedAndSelect(page, entry.seed ?? TEXT);
        await page.locator(`#e2e-toolbar [data-command="${entry.command}"]`
            + (entry.value === undefined ? ':not([data-value])' : `[data-value="${entry.value}"]`)).click();

        const html = await surfaceHtml(page);
        // The actual markup is reported for a command that failed, so the
        // diff says what the engine did instead of merely that it differed
        // — "Bonjour" unchanged is an engine that did nothing at all.
        produced[name] = entry.produces.test(html) ? STILL_PERFORMED : html;
    }

    // createLink goes through the site's own prompt dialog, which takes
    // focus — and a contenteditable that loses focus loses its selection,
    // which is why rich-text-link.js captures and restores the range. That
    // round trip is part of what has to keep working, so it is exercised
    // here rather than stubbed out.
    expected[LINK_COMMAND.command] = STILL_PERFORMED;
    await seedAndSelect(page, TEXT);
    await page.locator(`#e2e-toolbar [data-command="${LINK_COMMAND.command}"]`).click();
    await answerConfirmation(page, { note: LINK });

    // The insertion lands when the dialog's promise resolves, a task after
    // the click that closed it — so it is waited for rather than read
    // straight away. The wait's FAILURE is recorded instead of thrown: a
    // throw here would abort the test before the summary below, which is
    // the one place that says which commands still work. That
    // is not hypothetical — it is how the first version of this spec
    // reported an engine with no execCommand at all: one line about a link
    // dialog, and nothing about the other commands that had just failed
    // for the same reason.
    await page.waitForFunction(
        (link) => (document.getElementById('e2e-surface')?.innerHTML ?? '').includes(`<a href="${link}">`),
        LINK,
        { timeout: scaled(5_000) },
    ).catch(() => undefined);

    const linkHtml = await surfaceHtml(page);
    produced[LINK_COMMAND.command] = new RegExp(`<a href="${LINK}">Bonjour</a>`).test(linkHtml)
        ? STILL_PERFORMED
        : linkHtml;

    // ————— The commands the rest of the product issues itself —————
    for (const entry of DIRECT_COMMANDS) {
        expected[entry.command] = STILL_PERFORMED;
        produced[entry.command] = await runDirect(page, entry);
    }

    // One assertion for all of them: the failure this guards against takes
    // the whole toolbar out at once, and the list of what still works is
    // the diagnosis. If this fails on every line, read
    // https://caniuse.com/document-execcommand before anything else — the
    // engine has dropped the API and issue #379's option 1 (rebuild on
    // Selection/Range) is now due.
    expect(produced).toEqual(expected);

    expect(pageErrors).toEqual([]);
});
