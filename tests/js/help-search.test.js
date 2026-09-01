// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/help-search.js (imported below, never reimplemented
// here): the file exposes its ranking on window.ScoutMagicHelpSearch for
// exactly this reason, the same way attestations-deposit.js does.
//
// The scoring is what deserves a test without a browser. It is a
// hand-written ranking with no library behind it (MiniSearch, Lunr and
// Fuse were evaluated and dropped — ARCHITECTURE.md §1's dependency
// table), so every rule it has is a rule only a test defends: accent
// folding, plural folding applied symmetrically to the index and the
// query, the prefix discount, the coverage threshold, and a query made of
// nothing but stop words.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** One index entry in the shape Core\Help\HelpSearchIndex serializes. */
function topic(id, title, summary, category, questions = [], link = null) {
    return { id, title, summary, category, questions, link };
}

const CORPUS = [
    topic(
        'envoyer-une-photo',
        'Envoyer une photo',
        'Ajouter une photo à la galerie de sa section.',
        'Espace animateurs',
        ['Comment ajouter des photos de camp ?']
    ),
    topic(
        'medailles',
        'Médailles et brevets',
        'Suivre les brevets des animateurs.',
        'Espace chefs d\'U',
        ['Comment savoir qui a son brevet ?']
    ),
    topic(
        'publipostage',
        'Publipostage',
        'Un envoi personnalisé à une liste.',
        'Espace chefs d\'U',
        ['Comment envoyer un mail personnalisé depuis un fichier Excel ?']
    ),
    topic(
        'adresses-email',
        'Adresses e-mail',
        'Tenir à jour ses adresses.',
        'Espace membres',
        ['Comment changer mon adresse e-mail ?'],
        { path: '/account', label: 'Mon compte' }
    ),
];

let helpSearch;

beforeEach(async () => {
    document.body.innerHTML = '';
    vi.resetModules();
    await import('../../public/assets/js/help-search.js');
    helpSearch = window.ScoutMagicHelpSearch;
});

const ids = (results) => results.map((r) => r.id);

describe('scoring', () => {
    it('folds accents on both sides, so "medaille" finds "Médailles"', () => {
        expect(ids(helpSearch.search(CORPUS, 'medaille'))).toContain('medailles');
        expect(ids(helpSearch.search(CORPUS, 'MÉDAILLES'))).toContain('medailles');
    });

    it('folds the plural symmetrically, so a singular query finds a plural index word and back', () => {
        // "photos" is in the topic's question, "photo" in its title.
        expect(ids(helpSearch.search(CORPUS, 'photo'))).toContain('envoyer-une-photo');
        expect(ids(helpSearch.search(CORPUS, 'photos'))).toContain('envoyer-une-photo');
        // Both directions: "brevets" indexed, "brevet" typed, and back.
        expect(ids(helpSearch.search(CORPUS, 'brevet'))).toContain('medailles');
        expect(ids(helpSearch.search(CORPUS, 'brevets'))).toContain('medailles');
    });

    it('matches a prefix while the visitor is still typing', () => {
        expect(ids(helpSearch.search(CORPUS, 'publip'))).toContain('publipostage');
    });

    it('ranks a whole match above a prefix match in the same field', () => {
        const both = [
            topic('longue', 'Photographie de section', 'Résumé.', 'Premiers pas'),
            topic('courte', 'Photo de section', 'Résumé.', 'Premiers pas'),
        ];

        // Same field, same term: the exact hit wins, the prefix follows.
        expect(ids(helpSearch.search(both, 'photo'))).toEqual(['courte', 'longue']);
    });

    it('lets a strong-field prefix outrank a weak-field exact hit', () => {
        // Half of a title (5 × 0.5) still beats a whole summary (2). That
        // is the point of the discount being a factor rather than a floor:
        // it keeps a half-typed title ahead of an incidental mention.
        const both = [
            topic('titre', 'Photographie de section', 'Résumé sans rapport.', 'Premiers pas'),
            topic('resume', 'Sans rapport', 'On y parle de photo.', 'Premiers pas'),
        ];

        expect(ids(helpSearch.search(both, 'photo'))).toEqual(['titre', 'resume']);
    });

    it('drops a topic that covers only part of a two-word query', () => {
        // Both words exist in the corpus, on different topics: with one
        // or two words the visitor is being specific, so both must land
        // on the SAME topic. Only the camp album topic carries each.
        expect(ids(helpSearch.search(CORPUS, 'photo camp'))).toEqual(['envoyer-une-photo']);
        expect(helpSearch.search(CORPUS, 'photo brevet')).toEqual([]);
    });

    it('ignores a word the corpus does not use anywhere, rather than answering nothing', () => {
        // A term NO topic carries discriminates nothing. Counting it
        // against every topic is what made « empreinte digitale » answer
        // nothing at all while one topic said « se connecter avec
        // l'empreinte » — found on the real corpus, not in theory.
        expect(ids(helpSearch.search(CORPUS, 'photo zzzinconnu')))
            .toEqual(ids(helpSearch.search(CORPUS, 'photo')));
        // …but only when nobody has it: a word other topics DO carry and
        // this one does not still counts against it (the case above).
    });

    it('tolerates one unmatched word once the query is a sentence', () => {
        // Every one of these words exists somewhere in the corpus, and
        // publipostage carries most but not all of them — a sentence must
        // not be thrown away because one topic misses one word of it.
        expect(ids(helpSearch.search(CORPUS, 'envoyer un mail personnalise depuis excel brevet')))
            .toContain('publipostage');
    });

    it('answers nothing for a query made only of stop words', () => {
        expect(helpSearch.search(CORPUS, 'comment')).toEqual([]);
        expect(helpSearch.search(CORPUS, 'comment le pour')).toEqual([]);
        expect(helpSearch.search(CORPUS, 'pourquoi tous')).toEqual([]);
        expect(helpSearch.search(CORPUS, '   ')).toEqual([]);
    });

    it('answers nothing when not one word of the query exists in the corpus', () => {
        expect(helpSearch.search(CORPUS, 'zzzinconnu wwwautre')).toEqual([]);
    });

    it('weights the four fields in order — title, questions, summary, category', () => {
        const four = [
            topic('en-categorie', 'Sans rapport', 'Résumé.', 'Photo'),
            topic('en-resume', 'Sans rapport', 'On y parle de photo.', 'Premiers pas'),
            topic('en-question', 'Sans rapport', 'Résumé.', 'Premiers pas', ['Où mettre une photo ?']),
            topic('en-titre', 'Photo de section', 'Résumé.', 'Premiers pas'),
        ];

        expect(ids(helpSearch.search(four, 'photo')))
            .toEqual(['en-titre', 'en-question', 'en-resume', 'en-categorie']);
    });

    it('reads the real corpus the same way — a summary hit beats a category hit', () => {
        // "animateurs" sits in medailles' summary and in
        // envoyer-une-photo's category.
        expect(ids(helpSearch.search(CORPUS, 'animateur'))[0]).toBe('medailles');
    });

    it('never returns more than five results', () => {
        const many = [];
        for (let i = 0; i < 20; i++) {
            many.push(topic('sujet-' + i, 'Photo numéro ' + i, 'Résumé.', 'Premiers pas'));
        }
        expect(helpSearch.search(many, 'photo')).toHaveLength(5);
    });

    it('orders equal scores by title, so the same query always renders the same list', () => {
        const tied = [
            topic('b', 'Bravo photo', 'Résumé.', 'Premiers pas'),
            topic('a', 'Alpha photo', 'Résumé.', 'Premiers pas'),
        ];
        expect(ids(helpSearch.search(tied, 'photo'))).toEqual(['a', 'b']);
    });
});

describe('tokenize', () => {
    it('drops stop words and de-suffixes what is left', () => {
        expect(helpSearch.tokenize('Comment envoyer les photos du camp ?'))
            .toEqual(['envoyer', 'photo', 'camp']);
    });

    it('drops the words every question opens with', () => {
        expect(helpSearch.tokenize('Où ? Quand ? Pourquoi ? Comment ? Tous ? Tout ?')).toEqual([]);
    });

    it('keeps a short word whose final s is not a plural', () => {
        expect(helpSearch.tokenize('bus')).toEqual(['bus']);
    });
});

describe('rendering', () => {
    function buildScope(query) {
        document.body.innerHTML = `
            <script type="application/json" id="help-search-index">${JSON.stringify(CORPUS)}</script>
            <div data-help-search-scope>
                <form><input data-help-search-input value="${query}"></form>
                <div data-help-search-results hidden></div>
                <div data-help-search-default>LISTE COMPLÈTE</div>
            </div>`;
    }

    async function boot() {
        vi.resetModules();
        await import('../../public/assets/js/help-search.js');
    }

    it('renders a result with its title, category badge and page link', async () => {
        buildScope('adresse');
        await boot();

        const results = document.querySelector('[data-help-search-results]');
        expect(results.hidden).toBe(false);
        expect(document.querySelector('[data-help-search-default]').hidden).toBe(true);
        expect(results.textContent).toContain('Adresses e-mail');
        expect(results.textContent).toContain('Espace membres');
        expect(results.querySelector('a[href="/aide/adresses-email"]')).not.toBeNull();
        expect(results.querySelector('a[href="/account"]').textContent).toContain('Mon compte');
    });

    it('says what did not work and what to try instead when nothing matches', async () => {
        buildScope('xyzzy');
        await boot();

        const results = document.querySelector('[data-help-search-results]');
        expect(results.textContent).toContain('Aucun sujet ne correspond');
        expect(results.textContent).toContain('mot du problème');
    });

    it('brings the full listing back when the field is emptied', async () => {
        buildScope('photo');
        await boot();

        const input = document.querySelector('[data-help-search-input]');
        input.value = '';
        input.dispatchEvent(new Event('input'));

        expect(document.querySelector('[data-help-search-results]').hidden).toBe(true);
        expect(document.querySelector('[data-help-search-default]').hidden).toBe(false);
    });

    it('does not reload the page on submit — the ?q= round trip is the no-JS path', async () => {
        buildScope('photo');
        await boot();

        const submit = new Event('submit', { cancelable: true, bubbles: true });
        document.querySelector('form').dispatchEvent(submit);

        expect(submit.defaultPrevented).toBe(true);
    });
});

describe('handing a question over to the assistant', () => {
    // The invite is the ONE place the assistant is offered (locked
    // decision D2): under the results of a search that already ran, never
    // above them and never instead of them. These tests pin that
    // placement, since it is a product decision a stylesheet cannot hold.
    function buildScope(markup) {
        document.body.innerHTML = `
            <script type="application/json" id="help-search-index">${JSON.stringify(CORPUS)}</script>
            <div data-help-search-scope>
                <form><input data-help-search-input value=""></form>
                <div data-help-search-results hidden></div>
                ${markup}
                <div data-help-search-default>LISTE COMPLÈTE</div>
            </div>`;
    }

    const LINK_INVITE = `
        <div data-help-assistant-invite-zone hidden>
            <a href="/aide/assistant" data-help-assistant-invite>Demander à l'assistant</a>
        </div>`;

    const PANEL_INVITE = `
        <div data-help-assistant-invite-zone hidden>
            <p data-help-assistant-invite-preamble>Aucun de ces sujets ne répond ?</p>
            <button type="button" data-help-assistant-invite>Demander à l'assistant</button>
        </div>
        <div data-help-assistant-host hidden><div data-help-assistant></div></div>`;

    async function boot() {
        vi.resetModules();
        await import('../../public/assets/js/help-search.js');
    }

    function type(value) {
        const input = document.querySelector('[data-help-search-input]');
        input.value = value;
        input.dispatchEvent(new Event('input'));
    }

    it('stays hidden until a search has actually run', async () => {
        buildScope(LINK_INVITE);
        await boot();

        const zone = document.querySelector('[data-help-assistant-invite-zone]');
        expect(zone.hidden).toBe(true);

        type('photo');
        expect(zone.hidden).toBe(false);

        type('');
        expect(zone.hidden).toBe(true);
    });

    it('is offered even when the search found nothing — that is when it is useful', async () => {
        buildScope(LINK_INVITE);
        await boot();

        type('xyzzy');

        expect(document.querySelector('[data-help-assistant-invite-zone]').hidden).toBe(false);
    });

    it('carries the typed question to /aide/assistant through sessionStorage', async () => {
        buildScope(LINK_INVITE);
        await boot();

        type('changer mon adresse');
        const click = new Event('click', { cancelable: true, bubbles: true });
        document.querySelector('[data-help-assistant-invite]').dispatchEvent(click);

        // A link, so the navigation is allowed to happen…
        expect(click.defaultPrevented).toBe(false);
        // …and the question travels in sessionStorage rather than in the
        // URL: it is free text a human typed, and a query string ends up
        // in history and in every access log on the way.
        expect(window.sessionStorage.getItem('scoutmagic:help-assistant:question'))
            .toBe('changer mon adresse');
    });

    it('sends the question straight away inside the panel — the click is the question', async () => {
        buildScope(PANEL_INVITE);
        await boot();

        const received = [];
        document.querySelector('[data-help-assistant]')
            .addEventListener('scoutmagic:help-assistant-ask', (e) => received.push(e.detail.question));

        type('changer mon adresse');
        const click = new Event('click', { cancelable: true, bubbles: true });
        document.querySelector('[data-help-assistant-invite]').dispatchEvent(click);

        expect(click.defaultPrevented).toBe(true);
        expect(document.querySelector('[data-help-assistant-host]').hidden).toBe(false);
        // Asked, not prefilled into a second field for the visitor to
        // press send on.
        expect(received).toEqual(['changer mon adresse']);
        // And nothing else can be asked until this one comes back.
        expect(document.querySelector('[data-help-assistant-invite]').disabled).toBe(true);
        // The line above the button was an opening question; it has been
        // answered by the fact that they pressed it.
        expect(document.querySelector('[data-help-assistant-invite-preamble]').hidden).toBe(true);
    });

    it('offers the button again once the exchange comes back', async () => {
        buildScope(PANEL_INVITE);
        await boot();

        type('changer mon adresse');
        document.querySelector('[data-help-assistant-invite]')
            .dispatchEvent(new Event('click', { cancelable: true, bubbles: true }));
        expect(document.querySelector('[data-help-assistant-invite]').disabled).toBe(true);

        document.querySelector('[data-help-assistant]').dispatchEvent(
            new CustomEvent('scoutmagic:help-assistant-idle', { bubbles: true })
        );

        // With one field on this screen, this button is also how the
        // second question is asked — so it stays, and it stays usable.
        expect(document.querySelector('[data-help-assistant-invite]').disabled).toBe(false);
        type('photo');
        expect(document.querySelector('[data-help-assistant-invite-zone]').hidden).toBe(false);
    });

    it('asks nothing on an empty field', async () => {
        buildScope(PANEL_INVITE);
        await boot();

        const received = [];
        document.querySelector('[data-help-assistant]')
            .addEventListener('scoutmagic:help-assistant-ask', (e) => received.push(e.detail.question));

        const click = new Event('click', { cancelable: true, bubbles: true });
        document.querySelector('[data-help-assistant-invite]').dispatchEvent(click);

        expect(received).toEqual([]);
        expect(click.defaultPrevented).toBe(true);
    });

    it('clears a long question in one click, so the next one can be typed', async () => {
        document.body.innerHTML = `
            <script type="application/json" id="help-search-index">${JSON.stringify(CORPUS)}</script>
            <div data-help-search-scope>
                <form>
                    <input data-help-search-input value="">
                    <button type="button" class="d-none" data-help-search-clear>×</button>
                </form>
                <div data-help-search-results hidden></div>
                <div data-help-search-default>LISTE COMPLÈTE</div>
            </div>`;
        await boot();

        const clear = document.querySelector('[data-help-search-clear]');
        // Nothing to clear, nothing shown.
        expect(clear.classList.contains('d-none')).toBe(true);

        type('je dois prévenir les parents que la réunion est annulée');
        expect(clear.classList.contains('d-none')).toBe(false);

        clear.dispatchEvent(new Event('click', { bubbles: true }));

        expect(document.querySelector('[data-help-search-input]').value).toBe('');
        expect(clear.classList.contains('d-none')).toBe(true);
        // And the surface is back to its own content, as if never typed.
        expect(document.querySelector('[data-help-search-results]').hidden).toBe(true);
        expect(document.querySelector('[data-help-search-default]').hidden).toBe(false);
    });

    it('says the assistant needs a connection rather than vanishing offline', async () => {
        const online = Object.getOwnPropertyDescriptor(Navigator.prototype, 'onLine');
        Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false });
        document.body.innerHTML = `
            <script type="application/json" id="help-search-index">${JSON.stringify(CORPUS)}</script>
            <div data-help-search-scope>
                <form><input data-help-search-input value=""></form>
                <div data-help-search-results hidden></div>
                <div data-help-assistant-invite-zone hidden>
                    <p class="d-none" data-help-assistant-invite-offline>hors connexion</p>
                    <button type="button" data-help-assistant-invite>Demander à l'assistant</button>
                </div>
                <div data-help-assistant-host hidden><div data-help-assistant></div></div>
                <div data-help-search-default>LISTE COMPLÈTE</div>
            </div>`;
        await boot();

        const received = [];
        document.querySelector('[data-help-assistant]')
            .addEventListener('scoutmagic:help-assistant-ask', (e) => received.push(e.detail.question));

        type('changer mon adresse');

        // The results are there — they are ranked on the device. The
        // assistant is not, and the button says which.
        expect(document.querySelector('[data-help-search-results]').hidden).toBe(false);
        expect(document.querySelector('[data-help-assistant-invite-offline]').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-help-assistant-invite]').disabled).toBe(true);

        document.querySelector('[data-help-assistant-invite]')
            .dispatchEvent(new Event('click', { cancelable: true, bubbles: true }));
        expect(received).toEqual([]);

        Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => true });
        window.dispatchEvent(new Event('online'));
        expect(document.querySelector('[data-help-assistant-invite]').disabled).toBe(false);

        if (online) {
            Object.defineProperty(Navigator.prototype, 'onLine', online);
        }
    });

    it('binds nothing when the assistant is not on offer', async () => {
        // No connector, or a role below chief: the partial renders
        // nothing at all and the search must not notice.
        buildScope('');
        await boot();

        type('photo');

        expect(document.querySelector('[data-help-search-results]').hidden).toBe(false);
        expect(document.querySelector('[data-help-assistant-invite-zone]')).toBeNull();
    });
});
