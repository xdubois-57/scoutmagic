// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/retro-board.js (imported below, never reimplemented
// here) via the namespaced `globalThis.ScoutMagicRetroBoardInternals`
// test seam that file exposes for exactly this purpose — see the comment
// at the bottom of it.
//
// voteMode/isUnitChief/canVote/status are read once from #retro-board's
// data-* attributes when the module is evaluated and have no setter, so
// a test needing a different combination builds a fresh container and
// re-imports the module (vi.resetModules() + dynamic import) rather than
// mutating shared state — the same pattern tests/js/settings.test.js and
// tests/js/notification-badge.test.js already use for this reason.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function buildContainer(attrs = {}) {
    const defaults = {
        token: 'tok', status: 'open', canVote: '1', voteMode: 'unlimited',
        isUnitChief: '0', maxLength: '140', aiAvailable: '0', remainingBudget: '',
    };
    const merged = { ...defaults, ...attrs };
    const container = document.createElement('div');
    container.id = 'retro-board';
    Object.entries(merged).forEach(([k, v]) => { container.dataset[k] = v; });
    document.body.appendChild(container);
    return container;
}

async function boot(attrs = {}) {
    vi.resetModules();
    document.body.innerHTML = '';
    buildContainer(attrs);
    // The real toolboxes, never stubs — retro-board.js escapes through
    // window.ScoutMagicApi.escapeHtml and posts/toasts through the shared
    // globals (base.html.twig guarantees this load order in production).
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/retro-board.js');
    return globalThis.ScoutMagicRetroBoardInternals;
}

beforeEach(() => {
    vi.restoreAllMocks();
    global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({}) }));
});

describe('retro-board.js: escapeHtml() — the shared ScoutMagicApi.escapeHtml', () => {
    it('escapes the five HTML-significant characters, quotes included (attribute-safe)', async () => {
        const rb = await boot();
        expect(rb.escapeHtml('<b>&"\'</b>')).toBe('&lt;b&gt;&amp;&quot;&#39;&lt;/b&gt;');
    });

    it('renders null and undefined as an empty string, not the literal word', async () => {
        const rb = await boot();
        expect(rb.escapeHtml(null)).toBe('');
        expect(rb.escapeHtml(undefined)).toBe('');
    });

    it('stringifies a number rather than leaving it untouched', async () => {
        const rb = await boot();
        expect(rb.escapeHtml(42)).toBe('42');
    });
});

describe('retro-board.js: commentHtml() — masked and normal comments', () => {
    it('renders the masked-word placeholder when body is null, ignoring every other field', async () => {
        const rb = await boot();
        const html = rb.commentHtml({ id: 7, body: null, hidden: true, votes: 5 });
        expect(html).toContain('data-comment-id="7"');
        expect(html).toContain('data-hidden="1"');
        expect(html).toContain('Mot masqué.');
        expect(html).not.toContain('badge');
    });

    it('escapes a hostile comment body — the injected tag never becomes a live element', async () => {
        const rb = await boot();
        const html = rb.commentHtml({ id: 1, body: '<img src=x onerror=alert(1)>', hidden: false, votes: 0 });
        const div = document.createElement('div');
        div.innerHTML = html;
        expect(div.querySelector('img')).toBeNull();
        expect(div.querySelector('[data-comment-body]').textContent).toBe('<img src=x onerror=alert(1)>');
    });

    it('adds the warning badge and strikethrough styling only when hidden is true', async () => {
        const rb = await boot();
        const hidden = rb.commentHtml({ id: 1, body: 'x', hidden: true, votes: 0 });
        const visible = rb.commentHtml({ id: 2, body: 'x', hidden: false, votes: 0 });
        expect(hidden).toContain('badge text-bg-warning');
        expect(hidden).toContain('text-decoration-line-through');
        expect(hidden).toContain('data-hidden="1"');
        expect(visible).not.toContain('badge');
        expect(visible).not.toContain('text-decoration-line-through');
        expect(visible).toContain('data-hidden="0"');
    });

    it('renders an em dash for null/undefined votes rather than the literal word', async () => {
        const rb = await boot();
        expect(rb.commentHtml({ id: 1, body: 'x', hidden: false, votes: null })).toContain('>—<');
        expect(rb.commentHtml({ id: 1, body: 'x', hidden: false, votes: undefined })).toContain('>—<');
        expect(rb.commentHtml({ id: 1, body: 'x', hidden: false, votes: 0 })).toContain('>0<');
    });

    // Documents a known, deliberate assumption rather than asserting a
    // requirement: unlike comment.body, comment.id and comment.votes are
    // interpolated into the returned HTML string WITHOUT escapeHtml() —
    // safe only because both come from server JSON as integers in
    // practice. Nothing in this file enforces that shape. This pins the
    // current behaviour so a future change to either assumption is a
    // visible, deliberate diff here rather than a silent new HTML-injection
    // path — see docs/js-test-coverage-plan.md's P1.3 section.
    it('characterises comment.id and comment.votes as NOT HTML-escaped (server-controlled integers only)', async () => {
        const rb = await boot();
        const html = rb.commentHtml({ id: '1" data-x="y', body: 'x', hidden: false, votes: '<b>3</b>' });
        expect(html).toContain('data-comment-id="1" data-x="y"');
        expect(html).toContain('<b>3</b>');
    });
});

describe('retro-board.js: buildVoteButtonsHtml()', () => {
    it('renders nothing when the board is not open', async () => {
        const rb = await boot({ status: 'closed', canVote: '1' });
        expect(rb.buildVoteButtonsHtml({ id: 1 })).toBe('');
    });

    it('renders nothing when the visitor cannot vote', async () => {
        const rb = await boot({ status: 'open', canVote: '0' });
        expect(rb.buildVoteButtonsHtml({ id: 1 })).toBe('');
    });

    it('unlimited mode: renders a single like button, filled when already voted', async () => {
        const rb = await boot({ status: 'open', canVote: '1', voteMode: 'unlimited' });
        const notVoted = rb.buildVoteButtonsHtml({ id: 1, youVoted: false });
        const voted = rb.buildVoteButtonsHtml({ id: 1, youVoted: true });
        expect(notVoted).toContain('btn-outline-danger');
        expect(notVoted).toContain('bi-heart"');
        expect(notVoted).not.toContain('retro-vote-add');
        expect(voted).toContain('btn-danger"');
        expect(voted).toContain('bi-heart-fill');
    });

    it('budget mode: renders separate add/remove buttons, not a like button', async () => {
        const rb = await boot({ status: 'open', canVote: '1', voteMode: 'budget' });
        const html = rb.buildVoteButtonsHtml({ id: 9 });
        expect(html).toContain('retro-vote-add');
        expect(html).toContain('retro-vote-remove');
        expect(html).not.toContain('retro-vote-like');
        expect(html).toContain('data-comment-id="9"');
    });
});

describe('retro-board.js: buildHideButtonHtml() — the RBAC-adjacent boundary', () => {
    it('renders nothing for a non-unit-chief visitor, regardless of hidden state', async () => {
        const rb = await boot({ isUnitChief: '0' });
        expect(rb.buildHideButtonHtml({ id: 1, hidden: false })).toBe('');
        expect(rb.buildHideButtonHtml({ id: 1, hidden: true })).toBe('');
    });

    it('a unit chief gets a "Masquer" (hide) button on a visible comment', async () => {
        const rb = await boot({ isUnitChief: '1' });
        const html = rb.buildHideButtonHtml({ id: 3, hidden: false });
        expect(html).toContain('retro-hide');
        expect(html).toContain('Masquer');
        expect(html).toContain('btn-outline-warning');
        expect(html).not.toContain('retro-unhide');
    });

    it('a unit chief gets a "Réafficher" (unhide) button on a hidden comment', async () => {
        const rb = await boot({ isUnitChief: '1' });
        const html = rb.buildHideButtonHtml({ id: 3, hidden: true });
        expect(html).toContain('retro-unhide');
        expect(html).toContain('Réafficher');
        expect(html).toContain('btn-outline-success');
        expect(html).not.toContain('retro-hide"');
    });
});

describe('retro-board.js: renderColumns()', () => {
    function buildLists() {
        ['good', 'improve', 'suggestion'].forEach((col) => {
            const list = document.createElement('div');
            list.setAttribute('data-comment-list', col);
            document.getElementById('retro-board').appendChild(list);
            const badge = document.createElement('span');
            badge.setAttribute('data-count-for', col);
            document.getElementById('retro-board').appendChild(badge);
        });
    }

    it('groups comments into their matching column list and updates each count badge', async () => {
        const rb = await boot({ isUnitChief: '0', canVote: '0' });
        buildLists();

        rb.renderColumns([
            { id: 1, column: 'good', body: 'a', hidden: false, votes: 0 },
            { id: 2, column: 'good', body: 'b', hidden: false, votes: 0 },
            { id: 3, column: 'improve', body: 'c', hidden: false, votes: 0 },
        ]);

        const goodList = document.querySelector('[data-comment-list="good"]');
        expect(goodList.querySelectorAll('.retro-comment')).toHaveLength(2);
        expect(document.querySelector('[data-count-for="good"]').textContent).toBe('2');
        expect(document.querySelector('[data-count-for="improve"]').textContent).toBe('1');
        expect(document.querySelector('[data-count-for="suggestion"]').textContent).toBe('0');
    });

    it('drops a comment whose column does not match any known list, even when a matching list element exists', async () => {
        const rb = await boot({ isUnitChief: '0', canVote: '0' });
        buildLists();
        // An UNKNOWN-to-the-function column, but WITH its own list/badge in
        // the DOM (unlike a plain missing-element case) — this is what
        // actually distinguishes "genuinely not grouped" from "grouped, but
        // nothing rendered it": byColumn only ever starts with
        // good/improve/suggestion keys, so a comment tagged with any other
        // column must never reach this list even though the list exists and
        // is ready to receive content.
        const extraList = document.createElement('div');
        extraList.setAttribute('data-comment-list', 'extra');
        const extraBadge = document.createElement('span');
        extraBadge.setAttribute('data-count-for', 'extra');
        document.getElementById('retro-board').append(extraList, extraBadge);

        expect(() => rb.renderColumns([{ id: 1, column: 'extra', body: 'x', hidden: false, votes: 0 }]))
            .not.toThrow();

        expect(extraList.querySelectorAll('.retro-comment')).toHaveLength(0);
        expect(extraBadge.textContent).toBe('');
        expect(document.querySelector('[data-count-for="good"]').textContent).toBe('0');
    });

    it('renders the empty-state message for a column with no comments', async () => {
        const rb = await boot({ isUnitChief: '0', canVote: '0' });
        buildLists();

        rb.renderColumns([]);

        expect(document.querySelector('[data-comment-list="good"]').textContent).toContain('Aucun mot pour l’instant.');
    });

    it('tolerates a missing list or badge element for a column instead of throwing', async () => {
        const rb = await boot({ isUnitChief: '0', canVote: '0' });
        // No buildLists() call: none of the data-comment-list/data-count-for
        // elements exist in the DOM at all.
        expect(() => rb.renderColumns([{ id: 1, column: 'good', body: 'x', hidden: false, votes: 0 }]))
            .not.toThrow();
    });
});

describe('retro-board.js: updateBudgetDisplay()', () => {
    it('writes the new value into #retro-budget-remaining when it exists', async () => {
        vi.resetModules();
        document.body.innerHTML = '';
        buildContainer();
        const budgetEl = document.createElement('span');
        budgetEl.id = 'retro-budget-remaining';
        document.body.appendChild(budgetEl);
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
        await import('../../public/assets/js/retro-board.js');
        const rb = globalThis.ScoutMagicRetroBoardInternals;

        rb.updateBudgetDisplay(5);

        expect(budgetEl.textContent).toBe('5');
    });

    it('does not throw when #retro-budget-remaining is absent from the page', async () => {
        const rb = await boot();
        expect(() => rb.updateBudgetDisplay(3)).not.toThrow();
    });
});
