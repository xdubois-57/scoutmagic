// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises the
// REAL public/assets/js/audit-timeline.js.
//
// Its reason to exist beyond "the button works": that file builds the same
// <li> as the entry loop in
// core/View/templates/partials/audit_timeline.html.twig, and nothing but a
// test notices when the two drift. The structural assertions
// below (marker classes, the person/robot icon, the struck-through previous
// value) are that contract written down.
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

// Imported ONCE for the whole file, on purpose. The module is an IIFE that
// registers a delegated click listener on `document`; re-importing it per
// test through a reset registry would stack a fresh listener each time, and
// a single click would then append the page as many times as tests had run
// so far. One import, one listener — which is also what a real page does.
beforeAll(async () => {
    await import('../../public/assets/js/audit-timeline.js');
});

function buildDom({ total = 12, shown = 10, labels = { price: 'Prix' } } = {}) {
    document.body.innerHTML = `
        <div class="audit-timeline" data-entity-type="camp_camp" data-entity-id="7"
             data-labels='${JSON.stringify(labels)}'>
            <ol class="audit-timeline-list">
                ${'<li class="audit-entry"></li>'.repeat(shown)}
            </ol>
            <div class="audit-timeline-more-wrapper">
                <button type="button" class="btn audit-timeline-more" data-next-page="2">
                    Afficher plus (${total - shown})
                </button>
            </div>
        </div>`;
}

function entry(overrides = {}) {
    return {
        id: 1,
        field_key: 'price',
        from_value: '2 450 €',
        to_value: '2 650 €',
        summary: null,
        source: 'human',
        actor_name: 'Camille Wauters',
        is_automatic: false,
        created_at: '2026-08-14 09:12:00',
        ...overrides,
    };
}

function respondWith(payload, ok = true) {
    globalThis.fetch = vi.fn().mockResolvedValue({
        ok,
        json: async () => payload,
    });
}

async function clickMore() {
    document.querySelector('.audit-timeline-more').click();
    // Let the awaited fetch chain settle.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('audit-timeline', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('requests the next page of the timeline it belongs to', async () => {
        buildDom();
        respondWith({ page: 2, has_more: false, total: 12, entries: [] });
        await clickMore();

        expect(globalThis.fetch).toHaveBeenCalledWith(
            '/api/audit/camp_camp/7?page=2',
            expect.objectContaining({ headers: { 'X-Requested-With': 'fetch' } }),
        );
    });

    it('appends the returned entries to the existing list', async () => {
        buildDom({ total: 12, shown: 10 });
        respondWith({ page: 2, has_more: false, total: 12, entries: [entry(), entry({ id: 2 })] });
        await clickMore();

        expect(document.querySelectorAll('.audit-timeline-list > .audit-entry')).toHaveLength(12);
    });

    it('renders a human entry with the person icon and a primary marker', async () => {
        buildDom();
        respondWith({ page: 2, has_more: false, total: 11, entries: [entry()] });
        await clickMore();

        const li = document.querySelectorAll('.audit-entry')[10];
        expect(li.querySelector('.audit-entry-marker').className).toContain('bg-primary');
        expect(li.querySelector('.bi-person')).not.toBeNull();
        expect(li.textContent).toContain('Camille Wauters');
        expect(li.textContent).toContain('14/08/2026 à 09:12');
    });

    it('renders an automatic entry differently, and by more than colour alone', async () => {
        buildDom();
        respondWith({
            page: 2,
            has_more: false,
            total: 11,
            entries: [entry({ actor_name: null, is_automatic: true, source: 'email' })],
        });
        await clickMore();

        const li = document.querySelectorAll('.audit-entry')[10];
        expect(li.querySelector('.audit-entry-marker').className).toContain('bg-warning');
        expect(li.querySelector('.bi-robot')).not.toBeNull();
        expect(li.textContent).toContain('Détecté dans un message reçu');
    });

    it('uses the labels the server used, so a field reads the same either side of the click', async () => {
        buildDom({ labels: { price: 'Prix' } });
        respondWith({ page: 2, has_more: false, total: 11, entries: [entry()] });
        await clickMore();

        expect(document.querySelectorAll('.audit-entry')[10].textContent).toContain('Prix');
    });

    it('falls back to the field key when no label was given', async () => {
        buildDom({ labels: {} });
        respondWith({ page: 2, has_more: false, total: 11, entries: [entry()] });
        await clickMore();

        // An unlabelled field is a missing translation, not a missing
        // change — hiding it would hide the change too.
        expect(document.querySelectorAll('.audit-entry')[10].textContent).toContain('price');
    });

    it('shows the previous value struck through and omits it when there is none', async () => {
        buildDom();
        respondWith({
            page: 2,
            has_more: false,
            total: 12,
            entries: [entry(), entry({ id: 2, from_value: null })],
        });
        await clickMore();

        const entries = document.querySelectorAll('.audit-entry');
        expect(entries[10].querySelector('.text-decoration-line-through').textContent).toBe('2 450 €');
        expect(entries[11].querySelector('.text-decoration-line-through')).toBeNull();
    });

    it('inserts values as text, never as markup', async () => {
        buildDom();
        respondWith({
            page: 2,
            has_more: false,
            total: 11,
            entries: [entry({ to_value: '<img src=x onerror="alert(1)">' })],
        });
        await clickMore();

        const li = document.querySelectorAll('.audit-entry')[10];
        expect(li.querySelector('img')).toBeNull();
        expect(li.textContent).toContain('<img src=x');
    });

    it('keeps the button and counts down while more pages remain', async () => {
        buildDom({ total: 30, shown: 10 });
        respondWith({ page: 2, has_more: true, total: 30, entries: Array.from({ length: 10 }, (_, i) => entry({ id: i })) });
        await clickMore();

        const button = document.querySelector('.audit-timeline-more');
        expect(button).not.toBeNull();
        expect(button.dataset.nextPage).toBe('3');
        expect(button.disabled).toBe(false);
        expect(button.textContent).toContain('10');
    });

    it('removes the button once the last page has been appended', async () => {
        buildDom({ total: 12, shown: 10 });
        respondWith({ page: 2, has_more: false, total: 12, entries: [entry(), entry({ id: 2 })] });
        await clickMore();

        expect(document.querySelector('.audit-timeline-more-wrapper')).toBeNull();
    });

    it('leaves the button usable when the request fails', async () => {
        buildDom();
        respondWith({}, false);
        await clickMore();

        // Disabling the only control would read as "that was the end of
        // the history" rather than "that did not work".
        const button = document.querySelector('.audit-timeline-more');
        expect(button).not.toBeNull();
        expect(button.disabled).toBe(false);
    });
});
