// End-to-end: the thresholds box of « Capacités par branche » does not overlap
// itself, on a phone or on a desktop (#1361).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Two overlaps, both invisible to a test that looks for text in the DOM:
//
//   1. THE TITLE. Bootstrap floats every <legend> and clears only the element
//      right after it (`legend + *`). In the common case — waitlists on, valid
//      thresholds — that element is a paragraph hidden with `d-none`: it clears
//      nothing, and the slider below slides up under the title.
//   2. THE 100 % LABEL. « 100 % · toutes les places » was absolutely positioned,
//      centred on its tick, on one line: it reserved no room, so on a phone it
//      ran over « 120 % » and past the right edge of the scale.
//
// The test measures the real boxes, from the real page and the real styles, at
// the widths that matter, with the waitlists on and off. It is a layout test on
// purpose: only getBoundingClientRect() sees an overlap.
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';

const SETTINGS_PATH = '/config/inscriptions/reglages';
const WIDTHS = [320, 390, 430, 1280];
const TOLERANCE = 0.5;

/** The boxes that must not collide, measured in the page. */
async function measureThresholds(page) {
    return page.evaluate(() => {
        const box = document.querySelector('[data-threshold-box]');
        const rect = (element) => {
            const r = element.getBoundingClientRect();
            return { top: r.top, bottom: r.bottom, left: r.left, right: r.right };
        };
        const scale = box.querySelector('.registration-dual-slider-scale');
        const key = box.querySelector('.registration-dual-slider-key');

        return {
            legend: rect(box.querySelector('legend')),
            slider: rect(box.querySelector('[data-dual-slider]')),
            labels: rect(box.querySelector('[data-slider-label="yes"]').parentElement),
            scale: rect(scale),
            ticks: [...scale.querySelectorAll(':scope > span')].map((span) => ({ text: span.textContent.trim(), ...rect(span) })),
            key: key ? rect(key) : null,
            box: rect(box),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    });
}

function expectNoOverlap(m, where) {
    // The title is above the bar and its handles — whatever follows it.
    expect(m.legend.bottom, `${where}: the title ends above the slider`).toBeLessThanOrEqual(m.slider.top + TOLERANCE);

    // The graduations 0 %, 100 % and the maximum: in order, side by side.
    const ticks = [...m.ticks].sort((a, b) => a.left - b.left);
    expect(ticks.length, `${where}: three graduations`).toBe(3);
    for (let i = 0; i < ticks.length - 1; i++) {
        expect(
            ticks[i].right,
            `${where}: « ${ticks[i].text} » does not run into « ${ticks[i + 1].text} »`,
        ).toBeLessThanOrEqual(ticks[i + 1].left + TOLERANCE);
    }
    for (const tick of ticks) {
        expect(tick.left, `${where}: « ${tick.text} » stays inside the scale (left)`).toBeGreaterThanOrEqual(m.scale.left - TOLERANCE);
        expect(tick.right, `${where}: « ${tick.text} » stays inside the scale (right)`).toBeLessThanOrEqual(m.scale.right + TOLERANCE);
    }

    // The note that says what 100 % means, when there is one: under the scale,
    // inside the box.
    if (m.key) {
        expect(m.key.top, `${where}: the 100 % note sits under the scale`).toBeGreaterThanOrEqual(m.scale.bottom - TOLERANCE);
        expect(m.key.right, `${where}: the 100 % note stays inside the box`).toBeLessThanOrEqual(m.box.right + TOLERANCE);
    }

    expect(m.overflow, `${where}: no horizontal scroll`).toBeLessThanOrEqual(0);
}

test('the thresholds box has no overlap on a phone or a desktop, waitlists on or off', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);
    await answerCookieBanner(page, { accept: true });
    await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });

    const waitlistSwitch = page.getByRole('checkbox', { name: "Gérer les listes d'attente" });
    await expect(waitlistSwitch).toBeChecked();
    await expect(page.locator('[data-threshold-box]')).toBeVisible();

    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        await page.locator('[data-threshold-box]').scrollIntoViewIfNeeded();
        expectNoOverlap(await measureThresholds(page), `waitlists on, ${width}px`);
    }

    // The « seuils non utilisables » warning: the server renders it between the
    // title and the slider only when the stored thresholds are unusable, which
    // the E2E instance does not store. It is put there with the server's own
    // markup and classes, so the box is measured with it present as well.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.evaluate(() => {
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning small';
        alert.setAttribute('data-injected-warning', '');
        alert.textContent = "Les seuils enregistrés ne sont pas utilisables (deux nombres entiers de 1 à 120, « Oui » inférieur à « Peut-être ») : les valeurs ci-dessous, celles par défaut, s'appliquent jusqu'à ce que vous les enregistriez.";
        document.querySelector('[data-threshold-box] legend').after(alert);
    });
    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        await page.locator('[data-threshold-box]').scrollIntoViewIfNeeded();
        const m = await measureThresholds(page);
        const warning = await page.locator('[data-injected-warning]').boundingBox();
        expect(warning.y, `warning shown, ${width}px: the warning is below the title`).toBeGreaterThanOrEqual(m.legend.bottom - TOLERANCE);
        expect(warning.y + warning.height, `warning shown, ${width}px: the warning ends above the slider`).toBeLessThanOrEqual(m.slider.top + TOLERANCE);
        expectNoOverlap(m, `warning shown, ${width}px`);
    }
    await page.evaluate(() => document.querySelector('[data-injected-warning]').remove());

    // Waitlists off: the box stays, greyed out, with its explanatory note shown
    // between the title and the bar — the other half of the conditions.
    await page.setViewportSize({ width: 1280, height: 900 });
    const saved = page.waitForResponse((response) => response.url().endsWith('/config/inscriptions/listes-attente'));
    await waitlistSwitch.uncheck();
    expect((await saved).status(), 'the waitlist switch saved').toBe(200);
    try {
        await expect(page.locator('[data-threshold-disabled-note]')).toBeVisible();
        for (const width of WIDTHS) {
            await page.setViewportSize({ width, height: 900 });
            await page.locator('[data-threshold-box]').scrollIntoViewIfNeeded();
            expectNoOverlap(await measureThresholds(page), `waitlists off, ${width}px`);
        }
    } finally {
        // Later scenarios start from the default: waitlists on.
        await page.setViewportSize({ width: 1280, height: 900 });
        const restored = page.waitForResponse((response) => response.url().endsWith('/config/inscriptions/listes-attente'));
        await waitlistSwitch.check();
        expect((await restored).status(), 'the waitlist switch was put back').toBe(200);
    }
});

test('a zone label is shown whole or not at all, even when a zone is a few pixels wide', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);
    await answerCookieBanner(page, { accept: true });
    await page.goto(SETTINGS_PATH, { waitUntil: 'domcontentloaded' });

    // The extremes: « Oui » stops at 1 %, « Peut-être » at 119 % — two of the
    // three zones are a sliver, nothing is saved.
    //
    // EACH FIELD IS COMMITTED ON ITS OWN, in an order its bound allows. A value
    // is only checked when the field is left, against the OTHER field's last
    // committed value (« Oui » below « Peut-être »): filling both and leaving
    // only the last would clamp the first against a stale neighbour and the
    // matrix would test another pair than the one named. So: when « Peut-être »
    // goes up (or stays), it goes first; when it goes down, « Oui » does.
    let current = { yes: 70, maybe: 100 };
    const commit = async (name, value) => {
        const field = page.locator(`#threshold_${name}_percent`);
        await field.fill(String(value));
        await field.blur();
        await expect(field, `${name} was committed as typed`).toHaveValue(String(value));
    };

    for (const [yes, maybe] of [[1, 119], [118, 119], [1, 2], [70, 100]]) {
        if (maybe >= current.maybe) {
            await commit('maybe', maybe);
            await commit('yes', yes);
        } else {
            await commit('yes', yes);
            await commit('maybe', maybe);
        }
        current = { yes, maybe };
        // What the handles say is what the fields say: the pair under test.
        await expect(page.locator('[data-slider-handle="yes"]')).toHaveValue(String(yes));
        await expect(page.locator('[data-slider-handle="maybe"]')).toHaveValue(String(maybe));

        for (const width of WIDTHS) {
            await page.setViewportSize({ width, height: 900 });
            await page.locator('[data-threshold-box]').scrollIntoViewIfNeeded();

            const labels = await page.evaluate(() => [...document.querySelectorAll('[data-threshold-box] [data-slider-label]')].map((label) => {
                const r = label.getBoundingClientRect();
                return {
                    zone: label.dataset.sliderLabel,
                    text: label.textContent.trim(),
                    shown: getComputedStyle(label).visibility !== 'hidden',
                    cut: label.scrollWidth > label.clientWidth,
                    left: r.left,
                    right: r.right,
                };
            }));
            const where = `thresholds ${yes}/${maybe}, ${width}px`;

            for (const label of labels.filter((l) => l.shown)) {
                expect(label.cut, `${where}: « ${label.text} » is shown, so it is not cut to a stub`).toBe(false);
            }
            const shown = labels.filter((l) => l.shown).sort((a, b) => a.left - b.left);
            for (let i = 0; i < shown.length - 1; i++) {
                expect(shown[i].right, `${where}: « ${shown[i].text} » does not run into « ${shown[i + 1].text} »`)
                    .toBeLessThanOrEqual(shown[i + 1].left + TOLERANCE);
            }
            expectNoOverlap(await measureThresholds(page), where);
        }
    }
});
