// End-to-end: the calendar — a chief's event through the fetch modals,
// the public grid really rendering as a calendar, and the personal ICS
// token living and dying.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// The calendar's whole interactive surface is inline `nonce`d script in
// its Twig templates — create/update/delete are hand-built fetch+JSON
// round trips no Vitest file touches, and PHPUnit posts arrays it wrote
// itself. Three specific gaps close here:
//
//   1. The month-grid failure mode expectRendersAsACalendar() documents
//      (grids styled only by the opt-in components.css — forget the link
//      and the markup passes every PHP test while a human sees 42
//      stacked squares) applies just as much to this module's own
//      partial (month_grid.html.twig), yet only the rental pages were
//      ever checked. expectRendersAsAnEventCalendar() finally states it
//      for /calendar and /chefs/calendar themselves.
//   2. The chief modal is one form doing three verbs (create, update,
//      delete) against two endpoints, switching identity via a hidden id
//      — exactly the wiring that regresses silently.
//   3. The personal ICS token is a bearer credential for a member's whole
//      agenda, on an unauthenticated route. Regenerating it must kill the
//      old feed IMMEDIATELY — a promise only real HTTP can verify.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { answerConfirmation, autoConfirm } from '../support/confirm-dialog.js';
import { expectRendersAsAnEventCalendar } from '../support/calendar.js';
import { loginAsAdmin, loginAsMember } from '../support/admin-login.js';

const EVENT_TITLE = `Réunion de branche E2E ${Date.now()}`;
const EVENT_TITLE_EDITED = `${EVENT_TITLE} (reportée)`;

test('a chief creates, edits and deletes an event through the modal, the grids render as calendars, and the personal ICS token regenerates', async ({ page, browser }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    /** @type {string[]} */
    const serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) {
            serverErrors.push(`HTTP ${response.status()} on ${response.url()}`);
        }
    });
    // The chief's "Supprimer" asks through the site's own modal
    // (public/assets/js/calendar-chief.js → window.ScoutMagicConfirm);
    // installed here, before the first navigation, because the observer
    // rides in on an init script.
    await autoConfirm(page);

    await loginAsAdmin(page);
    await answerCookieBanner(page);

    // ---------------------------------------------------------------
    // Provisioned calendars are all chief-only. Opening the section
    // calendar to the public — through the config page's auto-saving
    // visibility select, itself one of this module's untested fetch
    // endpoints — is what makes the rest of this scenario observable by
    // an anonymous visitor and by the member's personal feed.
    // ---------------------------------------------------------------
    await page.goto('/config/calendar', { waitUntil: 'load' });
    const visibilitySelect = page.getByLabel('Visibilité du calendrier Meute E2E');
    const visibilitySave = page.waitForResponse((response) => response.url().includes('/config/calendar/visibility'));
    await visibilitySelect.selectOption('public');
    expect((await visibilitySave).ok()).toBe(true);

    // ---------------------------------------------------------------
    // The chief's grid is a calendar, not a stack of squares.
    // ---------------------------------------------------------------
    await page.goto('/chefs/calendar', { waitUntil: 'load' });
    await expectRendersAsAnEventCalendar(page.locator('main'));

    // ---------------------------------------------------------------
    // Create: click an empty mid-month day, fill the modal, submit.
    // ---------------------------------------------------------------
    // Through the keyboard, deliberately: the cell is a real <button>
    // (month_grid.html.twig renders it as one exactly so Enter works),
    // and the day-number overlay drawn over the cell's centre makes a
    // pointer click ambiguous where Enter is not.
    await page.locator('.calendar-day-cell--clickable[data-date$="-15"]').first().focus();
    await page.keyboard.press('Enter');
    const modal = page.locator('#eventModal');
    await expect(modal).toBeVisible();
    await expect(modal.getByText('Ajouter un évènement')).toBeVisible();

    await modal.getByLabel('Titre').fill(EVENT_TITLE);
    await modal.getByLabel('Calendrier').selectOption({ label: 'Meute E2E' });
    await modal.getByLabel('Heure de début').fill('14:00');
    await modal.getByLabel('Heure de fin').fill('17:30');
    await modal.getByRole('button', { name: 'Ajouter' }).click();

    // The script reloads the page once the fetch reports success; the
    // event's bar on the grid is the proof the round trip stored it. The
    // load-state wait keeps the next click from racing the fresh page's
    // own script re-attaching its listeners.
    await expect(page.locator('.calendar-event-bar', { hasText: EVENT_TITLE })).toBeVisible();
    await page.waitForLoadState('load');

    // ---------------------------------------------------------------
    // Update: the same modal, opened from the bar, in its other
    // identity — delete button revealed, submit relabelled.
    // ---------------------------------------------------------------
    await page.locator('.calendar-event-bar--clickable', { hasText: EVENT_TITLE }).click();
    await expect(modal).toBeVisible();
    await expect(modal.locator('#event-form-delete')).toBeVisible();
    await expect(modal.getByLabel('Titre')).toHaveValue(EVENT_TITLE);

    await modal.getByLabel('Titre').fill(EVENT_TITLE_EDITED);
    await modal.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.locator('.calendar-event-bar', { hasText: EVENT_TITLE_EDITED })).toBeVisible();
    await page.waitForLoadState('load');

    // ---------------------------------------------------------------
    // The public page, as a genuinely ANONYMOUS visitor: also a real
    // calendar, the now-public event visible on it, and its details
    // modal fed from the bar's data-* payload.
    // ---------------------------------------------------------------
    const anonymousVisitor = await browser.newContext();
    try {
        const visitorPage = await anonymousVisitor.newPage();
        await visitorPage.goto('/calendar', { waitUntil: 'load' });
        await answerCookieBanner(visitorPage);
        await expectRendersAsAnEventCalendar(visitorPage.locator('main'));

        await visitorPage.locator('.calendar-event-bar--clickable', { hasText: EVENT_TITLE_EDITED }).click();
        const details = visitorPage.locator('#eventDetailsModal');
        await expect(details).toBeVisible();
        // The shared modal embed names its own title '{id}-title', which is
        // the id public.html.twig's showEventDetails() fills in.
        await expect(details.locator('#eventDetailsModal-title')).toHaveText(EVENT_TITLE_EDITED);
        await expect(details.getByText('14:00', { exact: false })).toBeVisible();
        // Scoped to the footer: partials/modal.html.twig also gives the
        // header a ✕ whose aria-label is « Fermer », so the name alone now
        // matches two controls. The footer's is the one the dialog offers
        // as its own action.
        await details.locator('.modal-footer').getByRole('button', { name: 'Fermer' }).click();
        await expect(details).toBeHidden();
    } finally {
        await anonymousVisitor.close();
    }

    // ---------------------------------------------------------------
    // The personal ICS feed, as the ordinary member: the link works
    // unauthenticated (it is a bearer token), and regenerating kills
    // the old token on the spot.
    // ---------------------------------------------------------------
    const memberContext = await browser.newContext();
    try {
        const memberPage = await memberContext.newPage();
        await loginAsMember(memberPage);
        await memberPage.goto('/calendar', { waitUntil: 'load' });

        const feedUrl = await memberPage.locator('#personal-ics-link').inputValue();
        expect(feedUrl).toMatch(/\/calendar\/feed\/personal\/[a-f0-9]+\.ics$/);

        // Fetched from an ANONYMOUS context: the token alone is the key.
        // An unknown token deliberately still answers 200 with an EMPTY
        // calendar (CalendarPublicController::personalFeed() — no token
        // enumeration oracle), so invalidation is asserted on CONTENT:
        // the event travels with the live token and abandons the dead one.
        const anonymous = await browser.newContext();
        try {
            const feed = await anonymous.request.get(feedUrl);
            expect(feed.status()).toBe(200);
            const feedText = await feed.text();
            expect(feedText).toContain('BEGIN:VCALENDAR');
            expect(feedText, 'the member\'s personal feed must carry the event').toContain(EVENT_TITLE_EDITED);

            // Regenerating asks through the site's own dialog now
            // (public/assets/js/calendar-public.js), which Playwright
            // cannot see as a dialog at all.
            await memberPage.locator('#regenerate-personal-link').click();
            await answerConfirmation(memberPage);
            // The script reloads the page with the fresh token.
            await expect(memberPage.locator('#personal-ics-link')).not.toHaveValue(feedUrl);

            const deadFeed = await anonymous.request.get(feedUrl);
            expect(
                await deadFeed.text(),
                'the regenerated-away token must stop serving the agenda',
            ).not.toContain(EVENT_TITLE_EDITED);

            const newFeedUrl = await memberPage.locator('#personal-ics-link').inputValue();
            const freshFeed = await anonymous.request.get(newFeedUrl);
            expect(freshFeed.status()).toBe(200);
            expect(await freshFeed.text()).toContain(EVENT_TITLE_EDITED);
        } finally {
            await anonymous.close();
        }
    } finally {
        await memberContext.close();
    }

    // ---------------------------------------------------------------
    // Delete, through the modal's third verb — and the grid forgets it.
    // ---------------------------------------------------------------
    await page.goto('/chefs/calendar', { waitUntil: 'load' });
    await page.locator('.calendar-event-bar--clickable', { hasText: EVENT_TITLE_EDITED }).click();
    await expect(modal).toBeVisible();
    await modal.locator('#event-form-delete').click();
    await expect(page.locator('.calendar-event-bar', { hasText: EVENT_TITLE_EDITED })).toHaveCount(0);

    // And the section calendar goes back to its provisioned visibility,
    // so the calendar surface later scenarios meet is untouched.
    await page.goto('/config/calendar', { waitUntil: 'load' });
    const visibilityRestore = page.waitForResponse((response) => response.url().includes('/config/calendar/visibility'));
    await page.getByLabel('Visibilité du calendrier Meute E2E').selectOption('chief');
    expect((await visibilityRestore).ok()).toBe(true);

    // ---------------------------------------------------------------
    // The second select on the same row: who may WRITE, as opposed to
    // who may see. Another auto-saving fetch endpoint with no other
    // browser coverage, and the one that decides whether the modal
    // above opens at all. Driven there and straight back, so the
    // calendar the later scenarios meet keeps its provisioned value.
    // ---------------------------------------------------------------
    const editRoleSelect = page.getByLabel('Modification du calendrier Meute E2E');
    const editRoleSave = page.waitForResponse((response) => response.url().includes('/config/calendar/edit-role'));
    await editRoleSelect.selectOption('admin');
    expect((await editRoleSave).ok()).toBe(true);
    await expect(editRoleSelect).toHaveValue('admin');

    const editRoleRestore = page.waitForResponse((response) => response.url().includes('/config/calendar/edit-role'));
    await editRoleSelect.selectOption('chief');
    expect((await editRoleRestore).ok()).toBe(true);
    await expect(editRoleSelect).toHaveValue('chief');

    expect(serverErrors, 'the application returned a server error').toEqual([]);
    expect(pageErrors, 'uncaught JavaScript error in the browser').toEqual([]);
});
