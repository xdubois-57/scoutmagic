# JavaScript unit test coverage — gap analysis and implementation plan

**Status:** plan only, no code changes.
**Scope:** first-party browser JavaScript (`public/assets/js/*.js` + `public/sw.js`).
**Measured on:** branch `claude/js-test-coverage-plan-hks4bw`, at `b5b9fa4`.

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

Sorted by Sonar `uncovered_lines`. `cog` = Sonar cognitive complexity. `import-safe` =
whether `import`ing the file into a bare jsdom document succeeds (verified empirically —
see §4.2).

| File | Lines | Uncov. | cog | Cov. | import-safe |
| --- | --- | --- | --- | --- | --- |
| `news-form-builder.js` | 1053 | 942 | **323** | 0 % | yes |
| `maintenance.js` | 602 | 552 | **150** | 0 % | yes |
| `setup.js` | 467 | 433 | – | 0 % | **no** |
| `retro-board.js` | 385 | 346 | **168** | 0 % | yes |
| `gallery.js` | 366 | 324 | 95 | 0 % | yes |
| `auth.js` | 355 | 313 | – | 0 % | **no** |
| `gallery-storage-location.js` | 205 | 189 | 72 | 0 % | yes |
| `upload.js` | 207 | 183 | – | 0 % | yes |
| `chip-picker.js` | 204 | 179 | 36 | 0 % | yes |
| `offline-nav.js` | 177 | 162 | 49 | 0 % | yes |
| `list-editor.js` | 159 | 149 | 62 | 0 % | yes |
| `offline-prefetch.js` | 161 | 147 | 38 | 0 % | yes |
| `push-notifications.js` | 144 | 130 | 40 | 0 % | yes |
| `sw.js` | 477 | 123 | 43 | 0 % | n/a (see §4.1) |
| `editable.js` | 108 | 99 | – | 0 % | yes |
| `settings.js` | 99 | 88 | 18 | 0 % | yes |
| `offline-cache.js` | 95 | 86 | 12 | 0 % | yes |
| `rich-text-field.js` | 87 | 79 | 25 | 0 % | yes |
| `notification-preferences.js` | 85 | 78 | 20 | 0 % | yes |
| `notification-badge.js` | 77 | 70 | 31 | 0 % | yes |
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

Raw uncovered-line count is the wrong ranking on its own — it would put us straight into
DOM wiring, which `AGENTS.md` § Tests explicitly tells us **not** to unit-test:

> a thin script whose entire job is gluing a handful of DOM elements together with no
> independent logic of its own is often not worth the isolation cost

So each candidate is scored on four axes, and only the ones that win on **all** of them
go into P1:

1. **Blast radius if wrong** — does a silent regression cause an XSS, an auth failure, a
   destructive action firing without confirmation, or a data-loss? Or just a cosmetic glitch?
2. **Logic density** — is there real deterministic logic (string transforms, allowlists,
   state machines, arithmetic), or is it `addEventListener` → `fetch` → `classList.toggle`?
3. **Testability in jsdom** — pure functions and DOM-tree work test cleanly. Anything
   needing canvas rendering, real layout (`offsetTop`), or a live Service Worker does not.
4. **Cost to reach** — is the logic already reachable, or does the production file need a
   test seam added first?

That reordering matters. `gallery.js` has 324 uncovered lines but is mostly lightbox and
upload wiring; `sw.js` has 123 but they are the offline-correctness decisions the whole
PWA rests on. Sorting by line count alone would get this backwards.

---

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
follow the precedent set by `window.ChipPicker` and `window.ScoutMagicNav`, which already
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
- **Logic density:** highest cognitive complexity in the codebase (323).
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

That makes this item **fix, then pin**, and it is a good argument for the whole plan: three
implementations of one rule, no JS test on any of them, and the odd one out went unnoticed.
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

Three separate `escapeHtml` implementations, none covered, written three different ways:

```js
// retro-board.js               — explicit null/undefined guard, explicit String()
div.textContent = str === null || str === undefined ? '' : String(str);
// setup.js                     — loose == null guard, no String()
div.textContent = text == null ? '' : text;
// gallery-storage-location.js  — no guard at all
div.textContent = str;
```

The differing guards *look* like a divergence. They are not: swept against `undefined`,
`null`, numbers, booleans, `NaN`, strings, objects, and arrays, all three produce identical
output — `textContent` is a nullable `DOMString` in the IDL, so both `null` and `undefined`
convert to the empty string before the guards ever matter, and the implicit stringification
matches `String()` for every other case.

So this is a **triplication to consolidate, not a bug to fix**. The value is still real,
just smaller than it first appears: one implementation instead of three means one place to
cover, and no risk that a future edit to one copy silently changes escaping behaviour on
one page only. Pick the `retro-board.js` form (the guard is redundant but self-documenting),
use it in all three places, and cover it once as part of P1.3 rather than as its own PR.

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

## 7. Deliberately not prioritised

Per `AGENTS.md` § Tests, these are thin DOM glue or jsdom-hostile, and unit tests would
cost more than they return:

- `nav.js`, `breadcrumb.js`, `editable.js`, `unit-logo.js`, `unit-logo-notify-ios.js`,
  `offline-page.js`, `settings.js`, `notification-preferences.js` — wire elements to
  `fetch` calls with no independent logic.
- `chip-picker.js` — `rowsFor()` and `truncate()` look pure but depend on `offsetTop`.
  jsdom reports `0` for every element with no layout engine, so every chip collapses into
  one row and the test would assert a jsdom artefact. Only worth doing with stubbed
  geometry, and then it is testing the stub. `isDesktop()` (a `matchMedia` read) is
  trivially mockable if we ever want it.
- `gallery.js` — mostly lightbox and upload wiring. The one genuinely interesting piece is
  the bounded-concurrency worker pool in `uploadAll`/`worker`; if gallery uploads ever
  misbehave in the field, that is the part to test, not the lightbox.
- `push-notifications.js`, `setup.js` — dominated by browser-permission and installer
  flows that mock out to near-tautology. `setup.js`'s `escapeHtml`/`escapeAttr` are picked
  up by P1.4 instead.

This list is a judgement call, not a rule. If any of these files starts producing
regressions, it earns a test.

---

## 8. Sequencing

| Step | Work | Outcome |
| --- | --- | --- |
| 0 | §4.1 widen `coverage.include` to `public/sw.js` | Honest baseline. Headline % drops — say so in the PR |
| 1 | **P1.1** `news-form-builder.js` sanitizer | Closes the only client-side XSS surface at 0 % |
| 2 | **P1.3 + P1.4** `retro-board.js` + `escapeHtml` consolidation | Covers cog-168 rendering; de-duplicates `escapeHtml` three ways |
| 3 | **P1.2** `sw.js` + `offline-nav.js` whitelist | Fixes a confirmed offline bug; builds the reusable `caches` fake |
| 4 | **P2.1–2.2** `maintenance.js` gate + pollers | Protects destructive actions |
| 5 | **P2.3–2.8** as capacity allows | Steady grind on the long tail |

Suggested PR granularity: one step per PR. Step 0 is a one-line config change and should
go in on its own so the coverage-number drop is unambiguous and reviewable in isolation.

**Reasonable target:** these steps do not get JS to parity with PHP, and should not be sold
that way. Steps 0–3 put the *security-relevant and offline-correctness* logic under test —
which is the part where a silent regression is expensive and manual verification is
weakest. Line coverage will land somewhere in the 20–30 % range for JS; the number is a
side effect, not the goal.

---

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
