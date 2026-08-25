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

// ---------------------------------------------------------------------------
// The interaction layer.
//
// Everything above tests the pure HTML assembly, which is where the file's
// cognitive complexity lives — but it left the half that TALKS TO THE SERVER
// uncovered, and that half carries the decisions worth a regression test: the
// URL each control posts to (a hide button that posts to `unhide` is a
// moderation bug, not a typo), the in-flight disable that stops a double
// vote, and the enforced-moderation gate that must never offer a way past
// itself.
//
// Comment cards are re-rendered wholesale on every poll, so the handlers are
// delegated on the container — a card built by hand here reaches them exactly
// as a polled one does.
// ---------------------------------------------------------------------------

/**
 * Let a stubbed fetch's promise chain finish.
 *
 * A macrotask, not a fixed number of microtask ticks: ScoutMagicApi.postJson
 * is three .then() levels deep (fetch → res.json() → the caller's handler)
 * and counting them here would break the moment that toolbox gains a step.
 */
async function settle() {
    await new Promise((resolve) => { setTimeout(resolve, 0); });
}

/** @param {object} payload what the stubbed endpoint answers */
function respondWith(payload) {
    global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve(payload) }));
}

/**
 * The last POST, specifically — not the last request.
 *
 * A successful vote, hide or post calls refreshBoard(), which GETs
 * /r/{token}/poll straight afterwards, so "the last fetch" is the poll and
 * not the action under test. Selecting the POST also pins the method as
 * part of the contract: none of these actions may become a GET.
 */
function lastPostCall() {
    const posts = global.fetch.mock.calls.filter(([, init]) => init && init.method === 'POST');
    if (posts.length === 0) {
        throw new Error('no POST was made');
    }
    return posts[posts.length - 1];
}

function lastPostUrl() {
    return lastPostCall()[0];
}

function lastPostBody() {
    return JSON.parse(lastPostCall()[1].body);
}

/** Whether the board was re-polled — how a successful moderation action refreshes. */
function polled() {
    return global.fetch.mock.calls.some(([url, init]) => String(url).endsWith('/poll') && !(init && init.method === 'POST'));
}

/**
 * One rendered comment card, as renderColumns() would have produced it.
 * @param {string} inner the buttons under test
 */
function cardWith(inner) {
    const card = document.createElement('div');
    card.className = 'retro-comment';
    card.dataset.commentId = '42';
    card.innerHTML = inner + '<span data-comment-votes>3</span>';
    document.getElementById('retro-board').appendChild(card);
    return card;
}

function click(el) {
    el.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
}

describe('retro-board.js: voting — the endpoint contract', () => {
    it('a like posts to that comment\'s vote endpoint and disables the button in flight', async () => {
        await boot({ voteMode: 'unlimited' });
        respondWith({ success: true, liked: true, votes: 4 });
        const card = cardWith('<button class="retro-vote-like btn-outline-danger" data-comment-id="42"><i class="bi bi-heart"></i></button>');
        const btn = card.querySelector('.retro-vote-like');

        click(btn);
        expect(btn.disabled).toBe(true);
        await settle();

        expect(lastPostUrl()).toBe('/r/tok/comments/42/vote');
        expect(btn.disabled).toBe(false);
    });

    it('a successful like flips the heart, the colour and the count', async () => {
        await boot({ voteMode: 'unlimited' });
        respondWith({ success: true, liked: true, votes: 4 });
        const card = cardWith('<button class="retro-vote-like btn-outline-danger" data-comment-id="42"><i class="bi bi-heart"></i></button>');
        const btn = card.querySelector('.retro-vote-like');

        click(btn);
        await settle();

        expect(btn.classList.contains('btn-danger')).toBe(true);
        expect(btn.classList.contains('btn-outline-danger')).toBe(false);
        expect(card.querySelector('i').className).toBe('bi bi-heart-fill');
        expect(card.querySelector('[data-comment-votes]').textContent).toBe('4');
    });

    it('un-liking flips everything back rather than only the colour', async () => {
        await boot({ voteMode: 'unlimited' });
        respondWith({ success: true, liked: false, votes: 3 });
        const card = cardWith('<button class="retro-vote-like btn-danger" data-comment-id="42"><i class="bi bi-heart-fill"></i></button>');

        click(card.querySelector('.retro-vote-like'));
        await settle();

        expect(card.querySelector('.retro-vote-like').classList.contains('btn-outline-danger')).toBe(true);
        expect(card.querySelector('i').className).toBe('bi bi-heart');
    });

    it('a refused vote surfaces the server\'s reason and changes nothing on the card', async () => {
        await boot({ voteMode: 'unlimited' });
        respondWith({ success: false, error: 'Le tableau est clôturé.' });
        const card = cardWith('<button class="retro-vote-like btn-outline-danger" data-comment-id="42"><i class="bi bi-heart"></i></button>');
        const btn = card.querySelector('.retro-vote-like');

        click(btn);
        await settle();

        expect(btn.classList.contains('btn-danger')).toBe(false);
        expect(card.querySelector('[data-comment-votes]').textContent).toBe('3');
        expect(document.body.textContent).toContain('Le tableau est clôturé.');
    });

    it.each([
        ['add', '.retro-vote-add', '/r/tok/comments/42/vote/add'],
        ['remove', '.retro-vote-remove', '/r/tok/comments/42/vote/remove'],
    ])('budget mode: %s posts to its own endpoint', async (_label, selector, expectedUrl) => {
        await boot({ voteMode: 'budget' });
        respondWith({ success: true, votes: 7, remaining: 2 });
        const card = cardWith(
            '<button class="retro-vote-add" data-comment-id="42">+</button>'
            + '<button class="retro-vote-remove" data-comment-id="42">−</button>',
        );

        click(card.querySelector(selector));
        await settle();

        expect(lastPostUrl()).toBe(expectedUrl);
        expect(card.querySelector('[data-comment-votes]').textContent).toBe('7');
    });

    it('a budget vote writes the remaining budget back to the page', async () => {
        vi.resetModules();
        document.body.innerHTML = '';
        buildContainer({ voteMode: 'budget' });
        const budgetEl = document.createElement('span');
        budgetEl.id = 'retro-budget-remaining';
        document.body.appendChild(budgetEl);
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
        await import('../../public/assets/js/retro-board.js');
        respondWith({ success: true, votes: 7, remaining: 2 });
        const card = cardWith('<button class="retro-vote-add" data-comment-id="42">+</button>');

        click(card.querySelector('.retro-vote-add'));
        await settle();

        expect(budgetEl.textContent).toBe('2');
    });
});

describe('retro-board.js: hide / unhide — the moderation boundary', () => {
    it.each([
        ['hide', 'retro-hide', '/r/tok/comments/42/hide'],
        ['unhide', 'retro-unhide', '/r/tok/comments/42/unhide'],
    ])('%s posts to its own endpoint — the two are not interchangeable', async (_label, cls, expectedUrl) => {
        await boot({ isUnitChief: '1' });
        respondWith({ success: true });
        const card = cardWith(`<button class="${cls}" data-comment-id="42">M</button>`);

        click(card.querySelector(`.${cls}`));
        await settle();

        expect(lastPostUrl()).toBe(expectedUrl);
    });

    it('a successful hide re-polls the board, so the card reflects it without a reload', async () => {
        await boot({ isUnitChief: '1' });
        respondWith({ success: true });
        const card = cardWith('<button class="retro-hide" data-comment-id="42">M</button>');

        click(card.querySelector('.retro-hide'));
        await settle();

        expect(polled()).toBe(true);
    });

    it('a refused moderation action neither refreshes nor fails silently', async () => {
        await boot({ isUnitChief: '1' });
        respondWith({ success: false, error: 'Action non autorisée.' });
        const card = cardWith('<button class="retro-hide" data-comment-id="42">M</button>');

        click(card.querySelector('.retro-hide'));
        await settle();

        expect(document.body.textContent).toContain('Action non autorisée.');
        expect(polled()).toBe(false);
    });
});

describe('retro-board.js: posting a word', () => {
    /** The markup of one column's form, as the Twig template emits it. */
    function addForm(column = 'good') {
        const form = document.createElement('form');
        form.className = 'retro-comment-form';
        form.dataset.column = column;
        form.innerHTML = '<textarea data-draft-input></textarea>'
            + '<span data-draft-counter></span>'
            + '<p class="d-none" data-draft-error></p>'
            + '<div class="d-none" data-moderation-alert></div>'
            + '<button type="button" class="d-none" data-shorten-btn>Raccourcir</button>'
            + '<button type="submit">Envoyer</button>';
        document.getElementById('retro-board').appendChild(form);
        return form;
    }

    async function bootWithForm(attrs = {}) {
        vi.resetModules();
        document.body.innerHTML = '';
        buildContainer(attrs);
        const form = addForm();
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
        await import('../../public/assets/js/retro-board.js');
        return form;
    }

    function submit(form) {
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    }

    it('posts the column and the trimmed body', async () => {
        const form = await bootWithForm();
        respondWith({ success: true });
        form.querySelector('[data-draft-input]').value = '  Merci à tous  ';

        submit(form);
        await settle();

        expect(lastPostUrl()).toBe('/r/tok/comments');
        expect(lastPostBody()).toMatchObject({ column: 'good', body: 'Merci à tous', accepted_warning: false });
    });

    it('refuses an empty word without asking the server', async () => {
        const form = await bootWithForm();
        respondWith({ success: true });
        form.querySelector('[data-draft-input]').value = '   ';

        submit(form);
        await settle();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(form.querySelector('[data-draft-error]').textContent).toContain('ne peut pas être vide');
    });

    it('refuses an over-long word locally and says by how much', async () => {
        const form = await bootWithForm({ maxLength: '10' });
        respondWith({ success: true });
        form.querySelector('[data-draft-input]').value = 'x'.repeat(13);

        submit(form);
        await settle();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(form.querySelector('[data-draft-error]').textContent).toContain('3 caractère(s)');
    });

    it('clears the field on success', async () => {
        const form = await bootWithForm();
        respondWith({ success: true });
        const input = form.querySelector('[data-draft-input]');
        input.value = 'Bien joué';

        submit(form);
        await settle();

        expect(input.value).toBe('');
    });

    it('counts characters as they are typed, and flags going over', async () => {
        const form = await bootWithForm({ maxLength: '10' });
        const input = form.querySelector('[data-draft-input]');
        const counter = form.querySelector('[data-draft-counter]');
        expect(counter.textContent).toBe('0/10');

        input.value = 'x'.repeat(12);
        input.dispatchEvent(new window.Event('input'));

        expect(counter.textContent).toBe('12/10');
        expect(counter.classList.contains('text-danger')).toBe(true);
    });

    it('offers the AI shortener only when it is available AND the word is too long', async () => {
        const form = await bootWithForm({ maxLength: '10', aiAvailable: '1' });
        const input = form.querySelector('[data-draft-input]');
        const shorten = form.querySelector('[data-shorten-btn]');

        input.value = 'court';
        input.dispatchEvent(new window.Event('input'));
        expect(shorten.classList.contains('d-none')).toBe(true);

        input.value = 'x'.repeat(12);
        input.dispatchEvent(new window.Event('input'));
        expect(shorten.classList.contains('d-none')).toBe(false);
    });

    it('never offers the shortener when the AI is switched off, however long the word', async () => {
        const form = await bootWithForm({ maxLength: '10', aiAvailable: '0' });
        const input = form.querySelector('[data-draft-input]');

        input.value = 'x'.repeat(40);
        input.dispatchEvent(new window.Event('input'));

        expect(form.querySelector('[data-shorten-btn]').classList.contains('d-none')).toBe(true);
    });

    it('the shortener replaces the draft with what the server returns', async () => {
        const form = await bootWithForm({ maxLength: '10', aiAvailable: '1' });
        respondWith({ success: true, body: 'Version courte' });
        const input = form.querySelector('[data-draft-input]');
        input.value = 'une phrase beaucoup trop longue';

        click(form.querySelector('[data-shorten-btn]'));
        await settle();

        expect(lastPostUrl()).toBe('/r/tok/shorten');
        expect(input.value).toBe('Version courte');
    });
});

describe('retro-board.js: the AI moderation gate', () => {
    function addForm() {
        const form = document.createElement('form');
        form.className = 'retro-comment-form';
        form.dataset.column = 'good';
        form.innerHTML = '<textarea data-draft-input></textarea>'
            + '<span data-draft-counter></span>'
            + '<p class="d-none" data-draft-error></p>'
            + '<div class="d-none" data-moderation-alert></div>'
            + '<button type="submit">Envoyer</button>';
        document.getElementById('retro-board').appendChild(form);
        return form;
    }

    async function bootWithForm() {
        vi.resetModules();
        document.body.innerHTML = '';
        buildContainer();
        const form = addForm();
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
        await import('../../public/assets/js/retro-board.js');
        return form;
    }

    function submit(form) {
        form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    }

    async function refuse(form, payload) {
        respondWith({ success: false, type: 'offensive', ...payload });
        form.querySelector('[data-draft-input]').value = 'un mot déplacé';
        submit(form);
        await settle();
        return form.querySelector('[data-moderation-alert]');
    }

    it('shows the server\'s reason when a word is refused as offensive', async () => {
        const form = await bootWithForm();

        const alert = await refuse(form, { error: 'Ce mot vise quelqu\'un.', moderation_mode: 'warning' });

        expect(alert.classList.contains('d-none')).toBe(false);
        expect(alert.textContent).toContain('Ce mot vise quelqu\'un.');
    });

    it('escapes the reason and the suggestion — both are model output', async () => {
        const form = await bootWithForm();

        const alert = await refuse(form, {
            error: '<img src=x onerror=alert(1)>',
            suggestion: '<script>alert(2)</script>',
            moderation_mode: 'warning',
        });

        expect(alert.querySelector('img')).toBeNull();
        expect(alert.querySelector('script')).toBeNull();
        expect(alert.textContent).toContain('<img src=x onerror=alert(1)>');
    });

    it('warning mode offers a way past the gate', async () => {
        const form = await bootWithForm();

        const alert = await refuse(form, { error: 'Attention.', moderation_mode: 'warning' });

        expect(alert.querySelector('[data-moderation-proceed]')).not.toBeNull();
    });

    /**
     * The one that matters. 'enforced' is the mode a unit picks when it does
     * NOT want a member to be able to publish over the model's objection —
     * so the escape hatch must be absent from the DOM, not merely hidden.
     */
    it('enforced mode offers no way past the gate at all', async () => {
        const form = await bootWithForm();

        const alert = await refuse(form, { error: 'Reformule.', moderation_mode: 'enforced' });

        expect(alert.querySelector('[data-moderation-proceed]')).toBeNull();
        expect(alert.textContent).toContain('reformuler');
    });

    it('« Publier quand même » re-posts the same word with the warning accepted', async () => {
        const form = await bootWithForm();
        const alert = await refuse(form, { error: 'Attention.', moderation_mode: 'warning' });

        respondWith({ success: true });
        click(alert.querySelector('[data-moderation-proceed]'));
        await settle();

        expect(lastPostBody()).toMatchObject({ body: 'un mot déplacé', accepted_warning: true });
    });

    it('« Utiliser la suggestion » posts the suggestion, and does NOT accept the warning', async () => {
        const form = await bootWithForm();
        const alert = await refuse(form, {
            error: 'Attention.', suggestion: 'Une formulation plus douce', moderation_mode: 'warning',
        });

        respondWith({ success: true });
        click(alert.querySelector('[data-moderation-accept]'));
        await settle();

        // The rewritten word is a new word: it goes back through moderation
        // on its own merits rather than riding the previous acceptance.
        expect(lastPostBody()).toMatchObject({ body: 'Une formulation plus douce', accepted_warning: false });
    });

    it('enforced mode still offers the suggestion when the model provided one', async () => {
        const form = await bootWithForm();

        const alert = await refuse(form, {
            error: 'Reformule.', suggestion: 'Autre chose', moderation_mode: 'enforced',
        });

        expect(alert.querySelector('[data-moderation-accept]')).not.toBeNull();
        expect(alert.querySelector('[data-moderation-proceed]')).toBeNull();
    });

    it('typing again clears the gate, so a stale verdict never lingers', async () => {
        const form = await bootWithForm();
        const alert = await refuse(form, { error: 'Attention.', moderation_mode: 'warning' });
        expect(alert.classList.contains('d-none')).toBe(false);

        const input = form.querySelector('[data-draft-input]');
        input.value = 'tout autre chose';
        input.dispatchEvent(new window.Event('input'));

        expect(alert.classList.contains('d-none')).toBe(true);
        expect(alert.innerHTML).toBe('');
    });
});

describe('retro-board.js: sharing the board', () => {
    async function bootWithShareControls() {
        vi.resetModules();
        document.body.innerHTML = '';
        buildContainer();
        document.body.insertAdjacentHTML('beforeend', `
            <button id="retro-qr-toggle">QR</button>
            <div id="retro-qr-container" class="d-none"><img alt="QR"></div>
            <button id="retro-copy-link" data-url="https://unite.example/r/tok">
                <i class="bi bi-link"></i> Copier le lien
            </button>
        `);
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
        await import('../../public/assets/js/retro-board.js');
    }

    it('the QR control toggles the code in and out of view', async () => {
        await bootWithShareControls();
        const box = document.getElementById('retro-qr-container');

        click(document.getElementById('retro-qr-toggle'));
        expect(box.classList.contains('d-none')).toBe(false);

        click(document.getElementById('retro-qr-toggle'));
        expect(box.classList.contains('d-none')).toBe(true);
    });

    it('copies the board link and confirms on the button itself', async () => {
        await bootWithShareControls();
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const btn = document.getElementById('retro-copy-link');

        click(btn);
        await settle();

        expect(writeText).toHaveBeenCalledWith('https://unite.example/r/tok');
        // Inline feedback, deliberately not a toast — it belongs where the
        // finger just was.
        expect(btn.textContent).toContain('Copié');
    });

    it('restores the button label rather than leaving it saying "Copié"', async () => {
        vi.useFakeTimers();
        try {
            await bootWithShareControls();
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: () => Promise.resolve() },
                configurable: true,
            });
            const btn = document.getElementById('retro-copy-link');

            click(btn);
            await vi.advanceTimersByTimeAsync(0);
            expect(btn.textContent).toContain('Copié');

            await vi.advanceTimersByTimeAsync(2000);
            expect(btn.textContent).toContain('Copier le lien');
        } finally {
            vi.useRealTimers();
        }
    });

    it('falls back to execCommand where the clipboard API is unavailable', async () => {
        await bootWithShareControls();
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        const execCommand = vi.fn(() => true);
        document.execCommand = execCommand;
        const btn = document.getElementById('retro-copy-link');

        click(btn);

        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(btn.textContent).toContain('Copié');
        // The scratch input used to carry the value must not be left behind.
        expect(document.querySelectorAll('input').length).toBe(0);
    });
});
