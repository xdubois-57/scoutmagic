# JavaScript unit test coverage — gap analysis and implementation plan

**Status:** closed. Every numbered item is implemented or overtaken — see §0 and §0.2.
The last two open ones (P1.3/P1.4, `retro-board.js`) were closed alongside `help-panel.js`;
what remains is not in this plan (§0.3).
**Revision:** §3 and §7 rewritten after a challenge to the original "don't test DOM wiring"
position. That position was wrong; §7 now carries the measurement that settles it.
**Scope:** first-party browser JavaScript (`public/assets/js/*.js` + `public/sw.js`).
**Measured on:** branch `claude/js-test-coverage-plan-hks4bw`, at `b5b9fa4`.

---

## 0. Progress

| Item | State | Result |
| --- | --- | --- |
| §4.1 `coverage.include` widened to `public/sw.js` | **done** | sw.js is measured for the first time |
| **P1.2** `sw.js` whitelist bug + `tests/js/sw.test.js` | **done** | Bug fixed; 33 tests; sw.js 0 % → 63.9 % stmts, 100 % branch |
| **§7** `notification-badge.js`, `settings.js` wiring specs | **done** | 17 tests; 100 % and 97.7 % stmts |
| **P1.1** `news-form-builder.js` sanitizer | **done** | 37 tests, mutation-checked; file 0 % → 27.5 % stmts |
| **P1.4** escaping consolidation | **done** | Overtaken: one `ScoutMagicApi.escapeHtml`, attribute-safe, used by every caller |
| **P1.5** CSRF/endpoint contract sweep (§7.4) | **done** | Overtaken by the convergence work — every script posts through `ScoutMagicApi.postJson` |
| **P1.3** `retro-board.js` | **done** | 42.6 % → 98.4 %; 52 tests, six mutations checked |
| `help-panel.js` (not in the original plan) | **done** | 0 % → 94.8 %; 21 tests |
| **P2.x** | overtaken | Each file named there is now covered by the convergence work's own specs |

**Measured at the time of writing:** 6 spec files, **97 tests**, JS **15.17 %** statements /
**73.39 %** branch / 34.21 % functions — from 2 files, 10 tests, 1.83 % / 46 % / 19.35 % at
the start. **Measured now: 88 spec files, 1 531 tests, 86.62 % statements / 87.15 % branch /
87.87 % functions.**

Statement coverage understates the change here. Branch coverage went 46 % → 73.39 % because
the work targeted decision-dense code; and the denominator grew when `sw.js` entered the
measurement, so the 1.83 % → 15.17 % move is against a *larger* codebase than the baseline
number described.

### 0.2 Superseded by the UX convergence work (branch `claude/refactor-ux-analysis-dc4itj`)

The numbers above are the state this plan was written against; they are kept as the
baseline they describe, not as current fact. The convergence chantier moved most of
`public/assets/js/` for its own reasons (extracting inline template scripts so they
could be tested at all, and putting every script on the shared `api.js`/`toast.js`/
`confirm.js` toolboxes), and wrote a spec for each file it touched.

**Measured after that work:** ~60 spec files, **1 100+ tests**, JS **78 %** statements /
**86 %** branch / **84 %** functions.

That closes P1.5 (the CSRF/endpoint contract sweep) as a side effect: every migrated
script now goes through `ScoutMagicApi.postJson`, which is the contract, and each spec
asserts the token and the URL. **P1.3/P1.4 (`retro-board.js`) remain open** — it is at
42 % and was never in the chantier's path. The other files still under 50 % are
`news-form-builder.js` (26 %, its sanitizer is covered but its builder is not) and
`offline-prefetch.js` (0 %, structurally tested from PHP instead by
`tests/Core/View/OfflinePrefetchScriptTest.php`).

The remaining coverage lever is no longer this plan: it is the **814 lines of JavaScript
still living inside 26 Twig templates**, which no coverage tool can even see. Each one
becomes testable the moment it is extracted, and
`tests/Core/View/UxConventionsTest::NATIVE_DIALOG_ALLOWLIST` tracks the worst of them.

### 0.3 What is actually left, and why it is not in this plan

`retro-board.js` and `help-panel.js` were the last two files this plan could still point at.
Both are now covered, and both were worth it for the same reason rather than for the
percentage:

- **`help-panel.js` was at 0 % and is the file that only runs in the multi-topic case.**
  That case used to be rare. Widening `paths` across the corpus — so every page that renders
  carries help, not only the ones a menu links — made ten-plus module pages land on the
  panel's list view, several of them with three or four topics. The script also builds a URL
  out of a DOM attribute, which is the `js/xss-through-dom` shape; its allowlist now has nine
  hostile inputs behind it.
- **`retro-board.js` was at 42.6 %**, and the covered part was the HTML assembly. The
  uncovered part was the half that talks to the server: which URL each control posts to (a
  hide button posting to `unhide` is a moderation bug, not a typo), the in-flight disable
  that stops a double vote, and the AI moderation gate — where `moderation_mode: 'enforced'`
  must never render a way past itself. Mutating each of those six decisions fails the suite.

**The remaining lever is unchanged and is still not this plan's:** the JavaScript that lives
inside Twig templates, which no coverage tool can see. `offline-prefetch.js` (0 %) is a
deliberate exception — it is tested structurally from PHP by
`tests\Core\View\OfflinePrefetchScriptTest`. `news-form-builder.js` (26 %) is the one
genuine file-level gap left: its sanitizer is covered, its builder is not.

### 0.1 Practice worth carrying into the remaining steps: mutation-check security tests

The sanitizer suite passed 36/36 on first run, which for security-critical code is a reason
for suspicion rather than confidence. Eight deliberate breaks were introduced one at a time
to see whether the suite could actually fail. Seven were caught. The eighth was not:

Deleting the `name.indexOf('on') === 0` guard in `sanitizeHtmlAttributes()` broke **nothing**,
because no per-tag allowlist contains an attribute starting with `on`, so the allowlist check
already rejects every handler. The guard is pure defence-in-depth, and therefore invisible to
any test that goes through `sanitizeHtml()`. A test now drives `sanitizeHtmlAttributes()`
against a deliberately doctored allowlist to pin that layer on its own merits, so it cannot be
deleted as "redundant" without a failure.

The general lesson for P1.3 and P1.5: **a green security suite proves nothing until you have
watched it go red.** Break the thing on purpose first. It costs minutes and it is the only way
to find an assertion that cannot fail.

---

## 1. How this was measured

Two independent sources, cross-checked:

- **Local Vitest + `@vitest/coverage-v8`** — `npm run test:coverage`. Authoritative for
  statement/branch/function coverage, because `vitest.config.js` declares
  `coverage.include: ['public/assets/js/**/*.js']`, so every first-party file appears in
  the report whether or not a test touches it.
- **SonarCloud Web API** (`xdubois-57_scoutmagic`, `main`, via `SONAR_TOKEN`) — used for
  `uncovered_lines` and `cognitive_complexity` per file, which Vitest does not report.
  Sonar reads the same `coverage/js/lcov.info` the CI `javascript-tests` job uploads.

Both agree: JavaScript is by far the weakest-covered part of the codebase.

| Metric | Value |
| --- | --- |
| First-party JS files | **28** (27 under `public/assets/js/` + `public/sw.js`) |
| First-party JS lines | **5,919** |
| Vitest spec files | **2** (`cookie-consent`, `password-complexity`), 10 tests total |
| JS statement coverage (Vitest) | **1.83 %** |
| JS function coverage (Vitest) | **19.35 %** |
| Files at exactly 0 % | **26 of 28** |
| Uncovered JS lines (Sonar) | **~4,945** |
| Project-wide coverage (Sonar, all languages) | 19.8 % — 1,561 tests, nearly all PHPUnit |

The gap is not "a few thin spots". It is that **two files are tested and twenty-six are
not**, including every large, complex, and security-relevant one.

---

## 2. Current state, per file

Sorted by Sonar `uncovered_lines`. **Coverage column updated for the four files done in §0**;
the Sonar `Uncov.` column is the pre-work baseline. `cog` = Sonar cognitive complexity. `import-safe` =
whether `import`ing the file into a bare jsdom document succeeds (verified empirically —
see §4.2).

| File | Lines | Uncov. | cog | Cov. | import-safe |
| --- | --- | --- | --- | --- | --- |
| `news-form-builder.js` | 1053 | 942 | **323** | **27.5 %** | yes |
| `maintenance.js` | 602 | 552 | **150** | 0 % | yes |
| `setup.js` | 467 | 433 | – | 0 % | **no** |
| `retro-board.js` | 385 | 346 | **168** | 0 % | yes |
| `gallery.js` | 366 | 324 | 95 | 0 % | yes |
| `auth.js` | 355 | 313 | – | 0 % | **no** |
| `gallery-storage-location.js` | 205 | 189 | 72 | 0 % | yes |
| `upload.js` | 207 | 183 | – | 0 % | yes |
| `offline-nav.js` | 177 | 162 | 49 | 0 % | yes |
| `list-editor.js` | 159 | 149 | 62 | 0 % | yes |
| `offline-prefetch.js` | 161 | 147 | 38 | 0 % | yes |
| `push-notifications.js` | 144 | 130 | 40 | 0 % | yes |
| `sw.js` | 477 | 123 | 43 | **63.9 %** | yes (verified) |
| `editable.js` | 108 | 99 | – | 0 % | yes |
| `settings.js` | 99 | 88 | 18 | **97.7 %** | yes |
| `offline-cache.js` | 95 | 86 | 12 | 0 % | yes |
| `rich-text-field.js` | 87 | 79 | 25 | 0 % | yes |
| `notification-preferences.js` | 85 | 78 | 20 | 0 % | yes |
| `notification-badge.js` | 77 | 70 | 31 | **100 %** | yes |
| `retro-config.js` | 68 | 63 | 26 | 0 % | yes |
| `breadcrumb.js` | 70 | 62 | 17 | 0 % | yes |
| `nav.js` | 55 | 47 | – | 0 % | yes |
| `unit-logo-notify-ios.js` | 32 | 28 | 8 | 0 % | yes |
| `unit-logo.js` | 31 | 27 | 8 | 0 % | yes |
| `offline-page.js` | 26 | 24 | 2 | 0 % | yes |
| `cookie-consent.js` | 69 | 22 | 44 | 65.6 % | yes |
| `password-complexity.js` | 55 | 0 | 7 | 98.4 % | yes |

---

## 3. How the work is prioritised

### 3.1 The wrong cut: "logic vs. DOM glue"

An earlier draft of this plan ranked candidates on "logic density" and excluded DOM wiring
outright, citing `AGENTS.md` § Tests:

> a thin script whose entire job is gluing a handful of DOM elements together with no
> independent logic of its own is often not worth the isolation cost

That reading was too broad, and it produced wrong answers. Two checks kill it:

- **The repo already tests DOM wiring, deliberately.** `cookie-consent.test.js` does nothing
  else: build the banner, dispatch `DOMContentLoaded`, click, assert `fetch` was called with
  the right URL and the CSRF header. It is the second-best-covered JS file at 65.6 %. And
  `AGENTS.md` itself *mandates* that test — "Cookie consent: test that non-essential cookies
  are not set when consent is missing." A rubric that excludes wiring excludes the one wiring
  test the guidelines require.
- **It misclassified real logic as glue.** The earlier §7 dismissed `settings.js` as "wires
  elements to `fetch` calls with no independent logic". `settings.js` in fact contains
  `buildInput(type, value, regex, options)` — a five-branch HTML string builder that
  interpolates server-supplied `data-*` values into `value="…"` and `pattern="…"` attributes,
  with its own `escapeAttr` for attribute context. That is an XSS surface, and it is exactly
  what P1 is supposed to catch. Note also that the quoted sentence continues "**use
  judgment**" — it is a caution against tautological tests, not a ban on a whole category.

### 3.2 The right cut: does the assertion pin a contract outside this file?

The useful question is not *what kind of code* is under test, but *what the assertion is
anchored to*:

| | Worth testing | Not worth testing |
| --- | --- | --- |
| **Anchor** | A contract with something outside the file | The file's own DOM manipulation |
| **Examples** | request URL, method, payload shape; CSRF transport; an event a sibling script listens for; a role-gated affordance; a destructive-action gate; escaping of server-supplied data | a spinner class toggling; a label's text changing; a `d-none` added next to the line that adds it |
| **Breaks when** | the contract genuinely changes — which is when you *want* a failure | you refactor the markup, which is when a failure is pure cost |
| **Reads as** | a specification | a restatement of the implementation |

A test that asserts `POST /config/settings/update` carries `_csrf_token` in the body is
valuable *because* the server owns the other half of that contract and nothing else checks
it. A test that asserts `errorEl.classList.contains('d-none') === false` right after the
implementation calls `classList.remove('d-none')` is a tautology — it can only fail when
you edit it.

So the four scoring axes are **blast radius**, **contract density** (not logic density),
**jsdom feasibility**, and **cost to reach**. "It's wiring" is not a disqualifier; "the
assertion has no anchor outside this file" is.

### 3.3 Why line count still isn't the ranking

`gallery.js` has 324 uncovered lines but they are largely lightbox and upload wiring with
few external contracts. `sw.js` has 123 and they are the offline-correctness decisions the
whole PWA rests on. Sorting by uncovered lines gets that backwards, which is why the tiers
below don't.

## 4. Enabling work to do first

Two small blockers that make everything after them cheaper. Both are prerequisites, not
tests.

### 4.1 `public/sw.js` is invisible to coverage

`vitest.config.js` sets `coverage.include: ['public/assets/js/**/*.js']`. `public/sw.js`
sits outside that glob, so it is measured by neither Vitest nor — via the lcov it feeds —
SonarCloud's JS coverage. 477 lines of offline-correctness logic are not merely untested,
they are **not counted as untested**, which is worse: the coverage number flatters us.

Fix: widen the glob to include `public/sw.js`. Expect the headline JS coverage % to *drop*
when this lands, before any test is written. That drop is the measurement getting honest,
and it is worth calling out in the PR so it does not read as a regression.

### 4.2 No production JS file exports anything

Every file is an IIFE (`(function () { ... })();`) with zero `export` statements —
confirmed across all 28. There are exactly two working idioms in the repo today:

- **`cookie-consent.test.js`** — `import` the file for its side effects, build the DOM it
  expects, dispatch `DOMContentLoaded` manually, then drive it through real clicks.
  Works for DOM-wiring scripts; cannot reach an inner helper directly.
- **`password-complexity.test.js`** — the production file ends with
  `globalThis.initPasswordComplexityChecklist = initPasswordComplexityChecklist;` and a
  comment explaining that a classic `<script src>` already puts that top-level declaration
  on `window`, so **the line changes nothing for the browser** and exists purely so an ES
  `import` can reach the real implementation.

The second is the pattern to extend, and it is the only one that reaches the P1 targets.
It costs no build step, no bundler, and no runtime dependency — which keeps it inside the
hard constraint in `AGENTS.md` § CSS / frontend. One caveat: for a helper nested *inside*
an IIFE the assignment is not a no-op the way `password-complexity.js`'s is, it genuinely
adds a global. Keep those namespaced under a single object per file (e.g.
`globalThis.ScoutMagicNewsFormBuilderInternals`), guarded and documented as test-only, and
follow the precedent set by `window.SelectBar` and `window.ScoutMagicNav`, which already
expose namespaced objects.

### 4.3 `auth.js` and `setup.js` throw on import

Verified: importing either into a bare jsdom document throws
`TypeError: Cannot read properties of null (reading 'addEventListener')` —
`auth.js:94` (`sendBtn.addEventListener`) and `setup.js:95`
(`mailMode.addEventListener`) both dereference a `getElementById` result with no null
guard, unlike the guarded style used elsewhere in the same files.

This is **not** a production bug: both scripts only ship on pages where those elements
always exist. But it does mean a test must build the full DOM *before* the module is
evaluated (`vi.resetModules()` + dynamic `await import()`), which is more setup than the
other 23 files need. It is the reason `auth.js` sits in P2 rather than P1 despite its 313
uncovered lines.

---

## 5. The plan — P1: highest value, do these first

### P1.1 — `news-form-builder.js`: the client-side HTML sanitizer

**The single highest-value JS test in the codebase.** `news-form-builder.js` contains a
hand-rolled XSS sanitizer at 0 % coverage:

- `isSafeUrlScheme(value)` — scheme allowlist (`http`, `https`, `mailto`, `tel`), with
  tab/CR/LF stripping and lowercasing before the scheme is read.
- `sanitizeHtmlAttributes(el, tagName)` — per-tag attribute allowlist, drops every `on*`
  handler, drops unsafe `href`, forces `rel="noopener noreferrer"` on `target="_blank"`.
- `sanitizeHtmlChildren(parent)` — recursive walk; strips `script`/`style`/`iframe`/
  `object`/`embed`/`form`/`textarea`/`select` **with** their content, unwraps any other
  non-allowlisted tag while keeping its children, removes comment nodes.
- `sanitizeHtml(html)` — parses via `DOMParser` (no browsing context, so nothing loads or
  executes during the walk) and returns the cleaned `innerHTML`.

Why this ranks first on every axis:

- **Blast radius:** its output is written straight back to `editable.innerHTML`
  (`news-form-builder.js:168`) and passed to `onChange` on every edit (`:170`, `:241`).
  The file's own comment states it exists to close the contenteditable DOM-to-DOM
  round-trip that the server-side pass alone does not cover. A silent regression here is a
  stored-XSS path, and it is exactly the kind of regression a unit test catches and a
  manual visual check never will.
- **Contract density:** highest cognitive complexity in the codebase (323), and every
  assertion is anchored to a security property rather than to its own markup.
- **Testability:** pure input-string → output-string. `DOMParser` works in jsdom. The file
  is import-safe. No network, no canvas, no layout.
- **Cost:** one namespaced test seam (§4.2).

Test cases to write (`tests/js/news-form-builder.test.js`):

| Input | Expected |
| --- | --- |
| `<script>alert(1)</script>` | removed with content |
| `<p onclick="alert(1)">x</p>` | `<p>x</p>` — handler dropped |
| `<a href="javascript:alert(1)">x</a>` | `href` dropped, text kept |
| `<a href="java&#9;script:alert(1)">` | `href` dropped — tab normalisation |
| `<a href="JaVaScRiPt:x">` | `href` dropped — case folding |
| `<a href="/local">`, `<a href="#frag">`, `<a href="?q=1">` | kept — no scheme is safe |
| `<a href="mailto:a@b.c">`, `tel:`, `https:` | kept |
| `<a href="vbscript:x">`, `data:text/html,…` | dropped — allowlist, not denylist |
| `<a href="https://x" target="_blank">` | gains `rel="noopener noreferrer"` |
| `<div><p>keep</p></div>` | `div` unwrapped, `<p>keep</p>` survives |
| `<span>a<script>b</script>c</span>` | unwrap + strip compose correctly |
| `<!-- c -->text` | comment removed, text kept |
| `<style>body{}</style>` | removed with content |
| `<form><textarea>x</textarea></form>` | removed with content |
| deeply nested unwrap, e.g. `<div><div><b>x</b></div></div>` | recursion terminates, `<b>x</b>` survives |
| `''`, `null`, `undefined` | empty string, no throw |
| `<p title="t" class="c">` | `title` kept, `class` dropped (per-tag allowlist) |

The unwrap branch deserves particular care: `sanitizeHtmlChildren` reassigns
`next = firstMoved || next` after moving children, so the moved subtree is re-walked. That
is correct, and it is precisely the sort of pointer manipulation that a future refactor
could break invisibly. Pin it.

Also worth pinning while here, cheaply and in the same spec:

- `collectFieldsContentText()` — deterministic string join over form fields.
- `isPublicAccess()` / `hasTitleOrContent()` — small pure predicates gating the visibility
  UI and the AI buttons.

**Explicitly out of scope:** `processFeaturedImage()` does real canvas resizing. jsdom has
no canvas backend, so it would need heavy stubbing that tests the stub, not the code.
Leave it to manual verification.

**Effort:** ~1 seam + ~25 assertions. Highest value-per-hour in this plan by a wide margin.

---

### P1.2 — `sw.js` + `offline-nav.js`: the offline whitelist, which has already diverged

These are one work item because they contain **two different implementations of the same
question** — "is this page available offline?" — and they do not agree:

```js
// public/sw.js — exact path match only; entry.match is never read
function isWhitelisted(pathname, whitelist) {
    if (!whitelist) { return false; }
    for (let i = 0; i < whitelist.length; i++) {
        if (whitelist[i].path === pathname) { return true; }
    }
    return false;
}
```

```js
// public/assets/js/offline-nav.js — additionally honours entry.match === 'child',
// treating any single-segment child of entry.path as whitelisted
function isWhitelisted(pathname) {
    for (var i = 0; i < whitelist.length; i++) {
        var entry = whitelist[i];
        if (entry.match === 'child') {
            if (pathname.indexOf(entry.path) !== 0) { continue; }
            var remainder = pathname.slice(entry.path.length).replace(/^\/+|\/+$/g, '');
            if (remainder !== '' && remainder.indexOf('/') === -1) { return true; }
        } else if (pathname === entry.path) { return true; }
    }
    return false;
}
```

**This is a confirmed live bug, not a hypothetical.** Traced end to end:

1. `core/Offline/OfflineWhitelist.php:62` ships a real `match: 'child'` core entry —
   `['path' => '/members/', 'label' => 'Membre', 'match' => 'child', ...]` — and modules may
   declare more (`ModuleManifest::VALID_OFFLINE_MATCH_VALUES = ['exact', 'child']`).
2. `base.html.twig:185` serialises that list as-is (`whitelist: offline_whitelist`), and
   `offline-cache.js`'s `sendConfig()` `postMessage`s it to the service worker as
   `whitelist: config.whitelist` — **passed straight through, with `match` intact and no
   server-side expansion into concrete paths**.
3. `sw.js`'s `isWhitelisted()` never reads `entry.match`, so `/members/12` fails the check.
4. `handleNavigate()` therefore routes member pages down the non-whitelisted branch —
   `fetch(request).catch(() => caches.match('/offline'))` — instead of
   `networkFirstWithCacheFallback()`.

Net effect: `offline-nav.js` and the PHP matcher both present member detail pages as
offline-available, and they are never cached or served from cache by the service worker.
Offline, the user gets the generic `/offline` page on a link the UI showed as available.

**Why it survived is the instructive part.** `tests/Core/View/OfflineNavDialogTest.php:80`
already asserts `entry.match === 'child'` — but it asserts it against `offline-nav.js` only.
The PHP suite pins one copy of the duplicated matching algorithm by grepping its source text,
and never checks the other. A JS unit test on the actual function is what closes that.

That makes this item **fix, then pin**, and it is a good argument for the whole plan: three
implementations of one rule, a source-grep test on one of them, and the odd one out went
unnoticed.
The docblock in `OfflineWhitelist.php` even anticipates the risk — it accepts that the
matching algorithm is "necessarily reimplemented in JS", which is exactly the kind of
deliberate duplication that needs a test to hold it together.

Also in `sw.js`, all pure and all at 0 %:

- `formatOfflineTimestamp(dateHeader)` — `Date` header → user-facing string. Test a valid
  header, a malformed one, an absent one.
- `injectOfflineBanner(response, dateHeader)` — HTML injection into a cached response.
  Test that the banner lands once, and that a response with no `<body>` does not corrupt.
- `networkFirstWithCacheFallback(request, url, config)` — the core caching decision. Test
  network-OK → cached-and-returned; network-throws → cache hit returned with banner;
  network-throws → cache miss → offline page.
- `handleNavigate(request, url)` — whitelisted vs not, routing to the branches above.
- `purgeAllContentCaches()`, `storeOfflineConfig`/`getOfflineConfig` — round-trip.

Testing `sw.js` needs `caches`, `fetch`, and `Response` stubs, which jsdom does not
provide — mock them in the spec, the way `AGENTS.md` already anticipates
("`fetch`, the Service Worker, WebAuthn, etc. are mocked where a script under test touches
them"). Budget setup time for a small `caches` fake; it is reusable across P1.2 and P2.3.

**Why high:** offline behaviour is the hardest thing in this codebase to verify by hand —
it needs a real device, a real network transition, and a warm cache. It is exactly what
unit tests are for. Requires §4.1 first.

---

### P1.3 — `retro-board.js`: HTML string assembly from server JSON

`escapeHtml`, `buildVoteButtonsHtml`, `buildHideButtonHtml`, `commentHtml`,
`renderColumns`, `updateBudgetDisplay` — cognitive complexity 168, 0 % coverage. Unlike
the sanitizer these build HTML by **string concatenation** and assign it to
`list.innerHTML`, so escaping correctness is load-bearing.

One thing a test should pin deliberately: in `commentHtml`, only `comment.body` is passed
through `escapeHtml`. `comment.id` and `comment.votes` are interpolated raw into an
attribute and a text node:

```js
'<div class="border rounded p-2 retro-comment" data-comment-id="' + comment.id + '" ...
```

Those come from server JSON and are integers in practice, so this is a latent sharp edge
rather than a live hole — but nothing in this file enforces it. A characterisation test
documents the assumption, and fails loudly if the shape ever changes.

Test cases:

- `escapeHtml` with `<`, `>`, `&`, `"`, `'`, and with `null`/`undefined`.
- `commentHtml` with a body containing `<img src=x onerror=alert(1)>` → escaped, inert.
- `commentHtml` with `body === null` → the "Mot masqué." placeholder branch.
- `commentHtml` with `hidden: true` → warning badge + strikethrough classes.
- `votes` of `null`/`undefined` → renders `—`, not `"null"`.
- `buildVoteButtonsHtml` across each `voteMode` (`unlimited` and the budget modes).
- `buildHideButtonHtml` with `isUnitChief` true and false — this is a **visibility
  boundary**, and the PHP suite's RBAC tests do not cover the client-side render.
- `renderColumns` — groups by `good`/`improve`/`suggestion`, ignores an unknown column,
  renders the empty-state string, updates each count badge.

---

### P1.4 — Consolidate and test `escapeHtml` once

**Four** separate `escapeHtml` implementations, none covered, written three different ways
(the earlier draft of this plan counted three and missed `settings.js`) — plus three separate
`escapeAttr` implementations, which `escapeHtml` alone cannot substitute for because it does
not escape quotes:

```js
// retro-board.js               — explicit null/undefined guard, explicit String()
div.textContent = str === null || str === undefined ? '' : String(str);
// setup.js                     — loose == null guard, no String()
div.textContent = text == null ? '' : text;
// gallery-storage-location.js  — no guard at all
div.textContent = str;
// settings.js                  — same as gallery-storage-location.js
div.textContent = text;
```

`escapeAttr` (attribute context, escapes `&`, `"`, `<`, `>`) is duplicated across
`settings.js`, `setup.js`, and `rich-text-field.js`. Seven copies of escaping helpers across
five files, none of them covered.

The differing guards *look* like a divergence. They are not: swept against `undefined`,
`null`, numbers, booleans, `NaN`, strings, objects, and arrays, all four produce identical
output — `textContent` is a nullable `DOMString` in the IDL, so both `null` and `undefined`
convert to the empty string before the guards ever matter, and the implicit stringification
matches `String()` for every other case.

So this is a **duplication to consolidate, not a bug to fix**. The value is still real, just
smaller than it first appears: one implementation instead of four means one place to cover,
and no risk that a future edit to one copy silently changes escaping behaviour on one page
only. Pick the `retro-board.js` form (the guard is redundant but self-documenting), use it
everywhere, and cover it once as part of P1.3 rather than as its own PR.

`escapeAttr` is the more interesting half: it is the one that guards attribute context, it is
duplicated three ways, and `settings.js`'s `buildInput()` depends on it for exactly the
payload the P1 experiment fired at it (`"><img src=x onerror=alert(1)>` into `value="…"`).
Cover `escapeAttr` on its own merits, not as an afterthought to `escapeHtml`.

---

## 6. P2: valuable, once P1 lands

| # | Target | What to test | Notes |
| --- | --- | --- | --- |
| 2.1 | `maintenance.js` — `wireKeywordGate(inputId, expected, extraCondition)` | Submit stays disabled until the typed keyword matches **exactly**; `extraCondition` gating; a near-miss (case, whitespace) keeps it disabled | This is the confirmation gate in front of destructive maintenance actions (settings reset, full reset). Highest consequence-per-line in the file. Pure, deterministic, easy |
| 2.2 | `maintenance.js` — `pollStatus` / `pollResetStatus` | `completed` → done; `failed` → error surfaced; `404` → the "wiped its own tracking row = success" branch; timer cleanup on stop | State machine over mocked `fetch` + fake timers. cog 150 overall |
| 2.3 | `offline-prefetch.js` / `offline-cache.js` | `fetchAndCacheImage`, `prefetchImages`, `prefetchPages`, `refreshCachedDate`, `isStandalone`, `sendConfig(consent)` | Reuses the `caches` fake from P1.2. `sendConfig` has a **consent** dimension — worth pinning against `AGENTS.md`'s cookie-consent rule |
| 2.4 | `auth.js` | `base64ToBuffer` / `bufferToBase64` round-trip (WebAuthn, pure, security-relevant); `hasRgpdConsent(tab)`; `humanCheckParams()` (token + honeypot trap); `startPolling` with fake timers | Needs the pre-import DOM setup from §4.3. High value, higher setup cost |
| 2.5 | `upload.js` — `shouldConsiderDownscale(file)` | Each of the four `&&` terms independently: context not in `DOWNSCALE_CONTEXTS`, no `HTMLCanvasElement`, non-string `type`, non-`image/` prefix | Cheap and fully pure. `downscaleToWebp` itself is **out of scope** — real canvas encoding |
| 2.6 | `list-editor.js` — `updateMoveButtons`, `persistOrder` | First item's "up" and last item's "down" disabled; reorder produces the right payload | Pure ordering logic; off-by-one bugs live here |
| 2.7 | `notification-badge.js` (cog 31), `retro-config.js` (cog 26), `rich-text-field.js` (cog 25) | Whatever independent logic each holds | Good complexity-to-size ratio; scope each after a read |
| 2.8 | `gallery-storage-location.js` — `syncType`, `syncProviderHelp` | Provider/type combinations show the right help text and required fields | cog 72; the logic is a state table, which tests well |

---

## 7. DOM wiring: feasibility, measured

Since §3.2 admits wiring tests, the question becomes how far jsdom actually gets. This was
settled empirically rather than by argument: two specs were written against two files the
earlier draft had dismissed, then discarded (they live outside the repo, ready to land if
wanted). Results, in roughly 40 minutes of work:

| File | Before | After | Tests |
| --- | --- | --- | --- |
| `notification-badge.js` | 0 % | **100 %** stmts, 100 % funcs, 90 % branch | 8 |
| `settings.js` | 0 % | **97.7 %** stmts, 100 % funcs, 82.8 % branch | 8 |
| **Project JS total** | 1.83 % stmts / 19.35 % funcs / 46 % branch | **5.0 % / 34.28 % / 67.01 %** | 26 |

Every one of those 16 tests is pure wiring — `fetch` mocking, event dispatch, fake timers,
`navigator` stubs, a `bootstrap.Modal` stub. None of them is a tautology, because each is
anchored per §3.2: the request URL and payload shape, the CSRF transport, the `99+` clamp,
`clearAppBadge()` on zero (the exact stale-OS-badge bug `notification-badge.js`'s own
docblock says it exists to fix), the service-worker `push-received` message contract, and
the escaping of hostile `data-*` values.

**Conclusion: wiring tests are both feasible and cheap here, and the earlier blanket
exclusion was costing real coverage.** Wiring should be a first-class part of this plan, not
an excluded category.

### 7.1 What jsdom handles fine

Event dispatch (`click`, `input`, `change`, `submit`), the `DOMContentLoaded` re-dispatch
idiom already used by `cookie-consent.test.js`, `fetch` mocking, `classList`/`dataset`/
`textContent`/`disabled`, `setInterval`/`setTimeout` under `vi.useFakeTimers()`, and
`DOMParser`.

### 7.2 Friction worth budgeting for, with the fix

| Friction | Affects | Fix |
| --- | --- | --- |
| `bootstrap` is a global from a vendored `<script>`, absent in jsdom | `settings.js`, `editable.js`, `breadcrumb.js`, `offline-nav.js`, `rich-text-field.js` | `global.bootstrap = { Modal: vi.fn(() => ({ show, hide })) }` — 3 lines, and asserting `hide()` was or wasn't called is itself a useful contract |
| An IIFE's `setInterval` has **no teardown hook**, so timers accumulate across imports in one spec and poll-count assertions read high | `notification-badge.js`, `maintenance.js`, `auth.js` | `vi.clearAllTimers()` in `beforeEach`. Found the hard way — a "re-polls every 60s" test saw 5 calls instead of 2 |
| `window.location.reload()` / `href` assignment prints `Not implemented: navigation` to stderr | `settings.js`, `auth.js`, `setup.js` | Stub `window.location` (as `cookie-consent.test.js` already does). Noisy but non-fatal if skipped |
| Hostile payloads in an `innerHTML` fixture string silently truncate the attribute and defuse themselves | any XSS test | Set `dataset`/attributes programmatically. Also found the hard way — a passing-looking test proved nothing |
| Modules must be imported **after** the DOM exists | `auth.js`, `setup.js` (§4.3) | `vi.resetModules()` + `await import(...)` inside the test |

### 7.3 The real feasibility blockers

These are genuine, not fixable with a stub, and are the honest boundary of this approach:

- **Layout.** jsdom has no layout engine: `offsetTop`, `offsetWidth`, `getBoundingClientRect()`
  all return `0`. This killed `chip-picker.js`'s `rowsFor()`/`truncate()` (every chip collapsed
  into one row, so the test would have asserted a jsdom artefact) and still kills the geometry
  parts of `gallery.js` and `list-editor.js`. Stubbing geometry means testing the stub.
  `chip-picker.js` no longer exists: the components that replaced it, `select-bar.js` and
  `nav-rail.js`, measure no geometry at all, which is what made them testable — see
  `tests/js/select-bar.test.js` and `tests/js/nav-rail.test.js`.
- **Canvas.** No rendering backend, so `upload.js`'s `downscaleToWebp()` and
  `news-form-builder.js`'s `processFeaturedImage()` are out. Their *decision* functions
  (`shouldConsiderDownscale`) are fine.
- **Drag and drop.** `DataTransfer` support is thin; the reorder paths in `gallery.js`,
  `list-editor.js`, and `news-form-builder.js` are better reached by calling the persist
  handler directly than by simulating a drag.
- **Real navigation, clipboard, permission prompts.** Mockable, but the test often ends up
  asserting the mock — judge case by case.

### 7.4 Where wiring tests are cheapest and most valuable: the CSRF contract

Ten files carry their own copy of a `csrf()` helper and five their own `postJson()`. More
importantly, **the CSRF transport is not consistent**, and the one file that has a test uses
the minority convention:

- `cookie-consent.js` sends the token as an **`X-CSRF-Token` header** — and it is the only
  file that does so exclusively.
- Fourteen files (`settings.js`, `maintenance.js`, `retro-board.js`, `gallery.js`,
  `news-form-builder.js`, `setup.js`, `push-notifications.js`, `notification-preferences.js`,
  `list-editor.js`, `gallery-storage-location.js`, `editable.js`, `rich-text-field.js`,
  `unit-logo.js`, `unit-logo-notify-ios.js`) send **`_csrf_token` in the request body**.
- `auth.js` uses both.

**Checked on the server side: the split is intentional and correct, not drift.** `CsrfGuard`
has two entry points:

- `validateRequest()` — reads `$_POST['_csrf_token']` first, then falls back to the
  `HTTP_X_CSRF_TOKEN` header. Used by `/cookies/accept-all` and `/cookies/reject-all`, the
  two endpoints `cookie-consent.js` calls header-only. So the header transport works.
- `validateToken($token)` — validates one caller-supplied string. Used by the majority of
  controllers as `validateToken($request->getBody('_csrf_token', ''))` — **body only**.

So there is no live CSRF bug. But the pairing is **implicit and asymmetric**, which is the
actual reason to test it:

- A `validateRequest()` endpoint accepts either transport, so a client switching to body-only
  keeps working.
- A `validateToken(getBody(...))` endpoint accepts **only** the body. Any script that switched
  to header-only would start returning 403 with no client-side error path distinguishing it
  from an expired session — `settings.js`, for instance, surfaces `data.error` and leaves the
  modal open, which reads to the user as a validation failure rather than a broken request.

Nothing enforces which transport a given file uses. The existing suite's single CSRF assertion
pins `cookie-consent.js`'s header convention — the one convention fourteen other files don't
use — so it guards the case that was already safe and none of the fragile ones.

A small parameterised wiring spec asserting *"this file's write path carries a CSRF token, by
the transport its endpoint requires"* is therefore high value per line and reaches many files
at once. Recommend promoting this to **P1.5**.

### 7.5 Still not worth it

Narrowed considerably, and on the §3.2 test rather than on "it's glue":

- `nav.js`, `breadcrumb.js`, `unit-logo.js`, `unit-logo-notify-ios.js`, `offline-page.js` —
  genuinely no contract outside themselves beyond a single `fetch` URL. Pick those up free
  via §7.4 rather than writing per-file specs.
- The geometry halves of `gallery.js` and `list-editor.js` — blocked by §7.3, not by
  judgment. (`chip-picker.js` was listed here too; it was deleted rather than tested, and
  its replacements measure nothing and are covered.)
- `push-notifications.js`, `setup.js` — dominated by permission and installer flows that mock
  out to near-tautology. `setup.js`'s `escapeHtml`/`escapeAttr` are covered by P1.4 instead.

`editable.js` and `notification-preferences.js` were on the earlier exclusion list and should
come off it: both have server-request contracts, and `notification-preferences.js` has a
consent dimension worth pinning.

## 8. Sequencing

| Step | Work | Outcome |
| --- | --- | --- |
| 0 | §4.1 widen `coverage.include` to `public/sw.js` | Honest baseline. Headline % drops — say so in the PR |
| 1 | **P1.1** `news-form-builder.js` sanitizer | Closes the only client-side XSS surface at 0 % |
| 2 | **P1.3 + P1.4** `retro-board.js` + escaping-helper consolidation | Covers cog-168 rendering; collapses 4 `escapeHtml` + 3 `escapeAttr` copies |
| 3 | **P1.5** the CSRF/endpoint contract sweep (§7.4) | Highest coverage-per-line in the plan; touches ~14 files; pins each transport against a silent 403 |
| 4 | **P1.2** `sw.js` + `offline-nav.js` whitelist | Fixes a confirmed offline bug; builds the reusable `caches` fake |
| 5 | **P2.1–2.2** `maintenance.js` gate + pollers | Protects destructive actions |
| 6 | **P2.3–2.8**, plus the wiring specs per §7 | Steady grind on the long tail |

P1.5 is new, and it is placed third deliberately: §7's experiment showed wiring tests are the
cheapest coverage available, and the CSRF sweep is the cheapest of those.

Suggested PR granularity: one step per PR. Step 0 is a one-line config change and should go
in on its own so the coverage-number drop is unambiguous and reviewable in isolation.

**Revised target.** The earlier draft projected 20–30 % line coverage for JS and treated
wiring as out of scope. With wiring in scope that is too pessimistic: two files alone moved
the project from 1.83 % to 5.0 % statements and from 19.35 % to 34.28 % *functions* in about
40 minutes. Steps 0–3 plausibly land JS statement coverage in the 35–50 % range, with function
and branch coverage substantially higher — those two move faster than statement coverage
because wiring tests tend to hit many small handlers.

The number is still a side effect rather than the goal. What steps 0–4 actually buy is that
the security-relevant logic, the offline-correctness decisions, and the client-server request
contracts are all under test — the three places where a silent regression is expensive and
manual verification is weakest.

## 9. Constraints this plan respects

- **No production build step.** Every technique here is plain ES-module `import` of the
  real file, plus at most a namespaced `globalThis` test seam that a classic
  `<script src>` tag is unaffected by. No bundler, no transpiler, no Sass —
  `AGENTS.md` § CSS / frontend.
- **Never reimplement logic in the test.** Every spec exercises the real production file,
  as `AGENTS.md` requires and as both existing specs already do.
- **Complement, not replacement.** These do not substitute for the PHP integration tests
  or the manual mobile/desktop verification in `ARCHITECTURE.md` § 15.
- **`npm run test:coverage` stays green** and remains part of the release tests gate.
