# Agent rules

This file is automatically loaded by Devin, Cursor, Copilot, and other AI coding agents. These rules are non-negotiable. Before making any change, also read `SECURITY.md`.

## Language

- All code, comments, variable names, function names, class names, table names, column names, commits, PR titles and descriptions: **English**.
- All user-facing text (Twig templates, labels, messages, descriptions, settings labels): **French**.
- No exceptions. A French variable name or an English UI label is always a bug.

## Architecture

Read `ARCHITECTURE.md` in full before any task. Key rules:

- **Layered MVC**: Controller → Service → Repository. No SQL in Controllers. No business logic in Controllers or Views. No `$_SESSION`/`$_POST` access in Services.
- **RBAC guard**: called by the Router, never by a Controller. Every route has `role_min`.
- **Modules**: self-contained under `modules/<name>/`. Never modify `schema/core.sql` for a module-specific need. Each module has its own `schema.sql` (complete current state, not incremental migrations).
- **Strict `Api\` contract** (ARCHITECTURE.md §7.5): outside a module's own code, the ONLY part of it anything may name is its `Api\` namespace — interfaces, immutable value objects, its user-facing exception. Never import another module's `Service\`/`Repository\`/`Task\` classes, from core or from a module; `tests/Architecture/ModuleBoundariesTest.php` fails the build on the first such reference, with zero exceptions. Cross-module capabilities are consumed as nullable `Api\` constructor deps (§7.5), core hooks register in `Core\Module\HookRegistry` (§7.4), mutual dependencies go through a mutable registry (§7.6), and scheduled tasks resolve capabilities via `TaskContext::getOptional()`.
- **Single file per concern**: one Controller class per file, one Service, one Repository.

## Security checklist (every PR)

Before submitting any code:

1. ☐ All SQL uses prepared statements (no concatenation).
2. ☐ Every new route has `role_min` in `module.json`.
3. ☐ Personal data fields are `BLOB` + encrypted via `EncryptionService`. Banking data (IBAN, account holder, transaction labels) are always `BLOB` + encrypted too.
4. ☐ No personal data in log entries, error messages, or journal.
5. ☐ File access goes through `FileAccessGuard` (`file_url()` helper).
6. ☐ No uploaded files stored under `public/`.
7. ☐ CSRF token on every form.
8. ☐ Rich text content sanitized before storage.
9. ☐ No secrets in source code.
10. ☐ Sensitive actions logged via `JournalService`.
11. ☐ Non-essential cookies checked via `CookieConsentService::isAllowed()` before being set.
12. ☐ A change under `public/assets/js/` had its CodeQL results checked after pushing — see § CodeQL below. Nothing run locally sees `js/xss-through-dom`, and a value is not safe for having come from your own template.
13. ☐ No unit data in a help-assistant prompt — not a member, not a section, not an amount, not aggregated, not anonymised. The assistant answers from help topics only (ARCHITECTURE.md §8.87), it has no tool-calling and no SQL, and the day it can reach the data every prompt injection in a topic or a member's name becomes an exfiltration path.

## A problem you decide not to fix now becomes a GitHub issue

The moment a real problem is identified and the decision is taken **not** to
fix it in the change at hand, open an issue in `xdubois-57/scoutmagic` before
that change is considered done. A commit message, a PR review thread, a
walkthrough summary or a chat reply is not a backlog: nothing is ever read
back out of them, and the next agent starts from a clean context. The issue is
the only artefact that survives the session.

This applies to any deliberately deferred finding, whatever found it: a review
bot's report you verified and accepted but judged out of scope, a limitation
you discovered yourself while implementing, a trap you documented in a comment
rather than removed, a schema decision that is the repository owner's to make.

### One exception, and it is absolute: a security vulnerability

A deferred **security** finding does not become a public issue. Everything this
rule asks an issue to contain — the symptom, the reproduction, the mechanism
with the vulnerable code quoted, the precondition that triggers it — is exactly
what an exploit needs, and a GitHub issue on a public repository publishes it to
everyone, indexed, before the fix exists. That is the disclosure this project
already refuses: `SECURITY.md` § Reporting a vulnerability says to report
privately, *not via public GitHub issues*, and this rule does not get to
contradict it.

So a security finding you are not fixing now goes to the maintainer through that
private channel, with the same completeness a good issue would have had. If a
public trace is needed so the work is not forgotten, it may name the affected
area and nothing else — no reproduction, no mechanism, no code.

When you cannot tell whether a finding is a security one, treat it as one: the
cost of a private report about an ordinary bug is an email, and the cost of a
public issue about a real vulnerability cannot be taken back.

It does **not** apply to a finding you fixed, nor to one you examined and
rejected as incorrect — reply on the thread with the reasoning and leave no
issue behind. Do not open issues for style preferences or for hypothetical
problems you have not confirmed in the code.

Verify before you file. Never open an issue from a bot's assertion alone: read
the code, reproduce the reasoning, and file what you actually established. An
issue that turns out to describe behaviour the code does not have is worse than
no issue, because the next agent will act on it.

### Say what kind of thing it is, in words and not only in a label

Every issue states its nature on its **first line**, before anything else —
one of these two, verbatim:

- `**Type: bug**` — the site does something wrong.
- `**Type: enhancement**` — the site behaves as designed; the design should be
  better.

That line is the part you always write, because it is the part a reader
working from the issue body alone sees, and an issue whose nature has to be
inferred from its prose gets triaged wrong. If you genuinely cannot tell which
of the two it is, that is a sign you have not finished establishing the problem
— go back to the code.

The **labels** are a different matter, and mostly not yours. They are the
issue's state, `scripts/sync-issue-labels.sh` is the taxonomy's only source,
and `.claude/skills/triage` is what sets them: `issue-triage.yml` fires on
every issue opened, including the one you just filed, and applies the verdict
plus `triage:done`. So:

- Apply `bug:confirmed` when you filed a defect — it means "A real defect,
  understood", which is what this rule required you to establish before
  filing. Nothing else needs to go on.
- Apply **no** `bug:*` label to an enhancement. The taxonomy deliberately has
  no verdict for a request that is neither a defect nor a misunderstanding
  (`.claude/skills/triage/SKILL.md` § A feature request is not a bug); your
  type line carries that meaning instead.
- Never the older `bug` label, and never one you invent. `bug` "stays what it
  has always been: something the maintainer applies by hand"
  (`.github/ISSUE_TEMPLATE/bug.yml`), and a label outside the script's table
  is a finding to state in the issue, not something to create at runtime.
- Leave `triage:*` and `status:*` alone — the triage pass owns the first, the
  maintainer the second.

### Write it so it can be fixed from the issue alone

Assume the only thing whoever picks this up has is the issue text and a fresh
checkout of a later `main`. No session context, no PR thread open in another
tab, no memory of this conversation, and no guarantee the line numbers still
point where they did. Everything needed to make the fix has to be *in the
issue*. A link is a courtesy; it is never where a required fact lives.

Concretely, an issue must carry:

- **The type line** and the matching label, as above.
- **The symptom in user terms** — who is doing what, and what they see instead
  of what they expect. Name the role (`intendant`, site admin, parent) and the
  page or route, so the reader can picture it without the code.
- **Reproduction**, as steps or as the exact precondition that triggers it
  ("an account whose linked members are all outside the group"). If it can
  only be reached by a state that is awkward to set up, say how to set it up.
- **The mechanism**, with `file:line` references **and the relevant lines
  quoted inline**. Quote them — line numbers drift, quoted code survives. Name
  the commit you verified them against, so a reader who finds them moved knows
  what to search for and how stale the reference is.
- **The second-order effects**, if any (a cascade, a stale display, an audit
  gap, a permission that silently widens) — these are what make a deferred
  problem expensive later, and they are invisible to someone reading only the
  symptom.
- **The options, costed**, cheapest first, each saying what it does and does
  not fix, whether it needs a schema migration, and which files it touches.
  End with a recommendation; a reader who agrees can start immediately.
- **What "done" looks like** — the behaviour that must hold afterwards and the
  test that must exist to pin it. This repository requires a test for every
  fix, so name where it goes (`tests/…`), or the issue is not finishable.
- **The trail** — a link back to the PR or review thread where the decision to
  defer was made, for context that is nice to have but that nothing in the fix
  depends on.

The bar to hold yourself to: could a competent agent, handed nothing but this
issue, produce the fix and its test? If any answer lives only in your current
context, it is missing from the issue.

Then close the loop in both directions: the review thread or PR description
names the issue number, and the issue links the thread. Leave the thread that
raised it open when the decision is the owner's to make — resolving it hides
the question they still have to answer.

## Exception messages that reach a visitor

A caught exception's message is shown to a visitor **only** when its class
implements `Core\Exception\UserFacingException`. Everywhere else, display it
through `Core\Exception\UserFacingMessage::from($e, '<French fallback>')`,
which substitutes the fallback you wrote and leaves the real text to the
journal.

- Implementing the marker is a claim about **every** message that class is
  ever constructed with: French, a full sentence, naming nothing internal
  (no file path, SQL fragment, class name, or library text). Read every
  `throw new` of the class before adding it.
- Never `throw new SomeUserFacingException($e->getMessage(), 0, $e)`. That
  re-labels a technical message as user-facing and defeats the marker
  entirely — write a French sentence at the wrap site and let `$previous`
  carry the detail. This is checked by
  `tests/Core/Exception/UserFacingMessageTest.php` and enforced by review;
  it has already gone wrong three times (`SettingException`,
  `ModuleException` — which leaked a filesystem path onto a config page —
  and `MailException`, which is raw PHPMailer English by construction).
- A value written now and rendered later (a `last_error` column a template
  shows) is gated at the **write** site, not the read site.

## Cookie consent

- Every cookie used by the site (core or module) must be declared: name, category, purpose (in French), and duration.
- Core cookies are declared in `core/Cookie/CookieRegistry.php`. Module cookies are declared in their `module.json` under the `cookies` section.
- The cookie preferences page and the consent banner must both display the **complete and current** list of cookies, aggregated from the core registry and all active modules. Both surfaces pull from the same source of truth (`CookieConsentService::getAllDeclaredCookies()`). The RGPD public page does not display this list inline — it links to the preferences page.
- When adding, removing, or modifying any cookie anywhere in the codebase, you **must** verify that the declaration is updated accordingly. The cookie preferences page and the consent banner will then reflect the change automatically.
- Never set a non-essential cookie without first checking `CookieConsentService::isAllowed($category)`.

## RGPD page maintenance

The default RGPD content is defined in `Core\View\RgpdContentService::getDefaultContent()`. It must be kept in sync with the actual data processing performed by the codebase. Specifically:

- When adding a new data field to any table that stores personal data → update the "Données collectées" section.
- When adding a new cookie → the cookie list is generated dynamically from declarations (see Cookie consent above).
- When adding a new module that processes personal data → update the AI prompt in `RgpdContentService::buildSystemPrompt()` to describe the module's data processing.
- When adding a new external service integration (API, email relay, etc.) → update the "Sous-traitants" section of the default content, **and declare it through the `Core\Module\SubProcessorProvider` hook** (see `docs/module-development.md` § Declaring your sub-processors): a module whose configuration can engage an external processor implements the hook, inspecting its REAL configuration and answering only what is effectively active — that is what feeds the AI generation prompt's sub-processor facts, so the generated document states what is actually configured rather than what somebody once updated by hand. This includes a module that only *optionally* sends data to an external service via another module's public API (e.g. a module calling `llm_connector` — see §7.5 of `ARCHITECTURE.md`): the AI provider(s) reachable through it are still a real sous-traitant relationship whenever that path is exercised, regardless of which module initiated the call — and it is the *providing* module (`llm_connector`) that declares them, once, for every consumer.
- When changing data retention logic → update the "Durée de conservation" section.

This is not optional. A PR that adds personal data processing without updating the RGPD documentation is incomplete.

## Pipeline documentation maintenance

`docs/quality-pipeline.md` is the map of everything between a change and production: the test layers and what each is blind to, the CI jobs, the AI reviewers, `scripts/release.sh`'s gates, and the GitHub configuration that lives outside this repository. It is kept current the same way the RGPD content above is — as part of the change, not afterwards.

Update it in the same PR when you:

- Add, rename or remove a CI job, or change its commands, its database engine, or its environment. `.claude/skills/steward/SKILL.md` carries the narrower reproduction table and goes stale from the same change; both, or neither.
- Add or remove a release gate, a `--skip-*` flag, or change the order the gates run in.
- Change which AI reviewer runs, or a setting of one that alters *when* it reviews or *what* it can read — the trigger list, the quota, the guideline files it loads.
- Add or remove a PHPUnit testsuite, a Vitest directory, an E2E tier, or a DAST profile.
- Depend on a new piece of GitHub configuration: a secret, an App, a ruleset rule, a label, a CODEOWNERS entry, a required status check. `.github/CODEOWNERS` and the workflows are in the repository and get reviewed; secrets, Apps, rulesets, labels and required checks are not, and **that part has no other home**. Either way nothing warns you when one is missing or wrong — a CODEOWNERS entry naming a non-collaborator is reviewed, merged, and still matches nothing.

Two rules about how it is written:

- **It points, it does not copy.** The rule itself lives in this file, `ARCHITECTURE.md`, `SECURITY.md`, `CONTRIBUTING.md` or `design.md`; the map only says which layer covers what. A rule restated there drifts from its original, and the original wins. If you find yourself pasting a rule into it, put the rule in its own file and link it.
- **A check that can be green without having run belongs in its last section.** That failure mode has cost this project real time more than once — tests that skip rather than fail, a review job that exits success when it declines to run, a CODEOWNERS entry ignored in silence. When you find another, write it down there; it is the one part of that document nothing else in the repository records.

## Module creation checklist

When creating a new module:

1. ☐ `module.json` with `id`, `name`, `version`, `routes` (each with `role_min` and `menu`; each route that carries a `label` also declares `menu_group`, the named column it belongs to — see `Core\View\MenuBuilder::MENU_GROUPS` and `docs/module-development.md`).
2. ☐ `schema.sql` with complete table definitions.
3. ☐ `settings` section with `description` (NOT NULL) on every parameter.
4. ☐ `cookies` section declaring every cookie the module uses, with category, purpose, and duration.
4bis. ☐ `emails` section declaring every automatic e-mail the module sends, with a French description of *when* it goes out and the variables an administrator may insert (`docs/module-development.md` § E-mails). An authentication e-mail declares `editable: false`.
5. ☐ Controllers in `src/Controller/`, Services in `src/Service/`, Repositories in `src/Repository/`.
6. ☐ Views in `views/` with `@module_name` namespace.
7. ☐ Scheduled tasks declared in `scheduled_tasks` section with handler class.
8. ☐ Storage folders declared in `storage` section with `role_min`.
9. ☐ No duplicate of core functionality (auth, session, encryption, journal, mail, scheduler, cookie consent).
10. ☐ RGPD documentation updated if the module processes personal data.
11. ☐ Automated tests written for all module functionality.
12. ☐ If the module has an optional dependency on another module, it must degrade gracefully when that other module is absent or disabled — never a hard coupling (see `ARCHITECTURE.md` §7.5).
13. ☐ **Does this module have an attention point to report?** — a current state of the unit it alone can see (a household whose tariff has become wrong, a section no longer supervised in sufficient numbers). If yes, implement `Core\Attention\AttentionPointProvider` and append it to `$attentionProviders` in the composition root; see `docs/module-development.md`. **The answer is usually no, and no is a complete answer** — never add an empty implementation for consistency, which a reviewer cannot tell apart from "not done yet".
14. ☐ Every new page meant for an end user is covered by a help topic, existing or new — a `.md` file in the module's `help/` directory (or `docs/help/` for a core page), per `design.md` §7.11's charter and `docs/module-development.md` § Help topics. This applies to core pages too, not only modules. **The topic carries two to four `question:` lines**, written the way somebody would type them into the search box rather than as a table of contents — they are what the instant search and the help assistant match on, and `tests/Core/Help/HelpInvariantsTest` fails without them. If a second genuine question cannot be written, the topic is describing a screen instead of documenting a task. **Every control the body quotes must exist**: `tests/Core/Help/HelpLabelDriftTest` fails on a « libellé » that appears nowhere in the interface.

## Tests

Automated tests are **mandatory** for every feature, without exception.

- Write tests alongside the code, never as a separate follow-up task.
- `tests/` mirrors the structure of `core/` and `modules/` for PHP; `tests/js/` holds Vitest specs for first-party browser JavaScript (`public/assets/js/`), one `<name>.test.js` per script under test; `tests/e2e/` holds the Playwright end-to-end specs, and `tests/dast/` the OWASP ZAP plans the dynamic security scan runs (`scripts/dast.sh`, README.md § Analyse de sécurité dynamique) — see ARCHITECTURE.md § 15. `tests/dast/` holds configuration, not tests: nothing in it is run by `vendor/bin/phpunit`, and it needs no `<testsuite>` entry.
- **A new PHP test directory must be added to `phpunit.xml` as a `<testsuite>` in the same change.** `vendor/bin/phpunit` runs the suites that file lists and nothing else, so a directory nobody listed is a directory nobody runs — which is exactly what happened to `tests/Security/` and `tests/Integration/` for months, audits included.
- Every new Service method must have at least one test.
- Every new Controller route must have at least one integration test verifying the correct response and the RBAC boundary (access allowed at `role_min`, denied one level below).
- Every Repository method must be tested against a test database.
- **When adding or changing frontend (`public/assets/js/`) behavior that is deterministic and reasonably decoupled from the DOM it ships with** (form validation, complexity/strength checks, client-side computed state, anything not primarily "wire two DOM elements together") **write or update a Vitest unit test in `tests/js/`** exercising the real production file (`import` it — never copy/reimplement its logic in the test). Not every script needs this: a thin script whose entire job is gluing a handful of DOM elements together with no independent logic of its own is often not worth the isolation cost — use judgment, the same way "every Service method" above doesn't mean every one-line getter. `npm run test:coverage` is part of the tests gate either way (see § Releases) regardless of whether a given change added new JS tests.
- Frontend JavaScript unit tests exist to catch regressions in that isolated logic fast and without a browser — they are a complement to, never a replacement for, this project's PHP integration tests or the manual mobile/desktop visual verification ARCHITECTURE.md § 15 already requires. Production JavaScript itself must never acquire a Node/runtime/build dependency because it is now unit-tested — see § CSS / frontend above.
- When modifying existing code, update the corresponding tests to match the new behavior.
- When fixing a bug, write a test that reproduces the bug first, then fix it.
- **End-to-end (`tests/e2e/`, Playwright + headless Chromium)**: one canonical command, `npm run e2e` (`scripts/e2e.sh`), which provisions a throwaway install + database, serves it through the real `public/index.php`, drives it with a real browser, and tears everything down. It exists to catch what PHPUnit structurally cannot: the application failing to boot at all (a broken composition root, a failed dependency wiring, a bootstrap that throws before any route runs). Keep it to a small number of high-value scenarios — this is a release gate, not a coverage tool; a flaky or slow E2E suite is worse than none. Prove a new scenario is deterministic (run it repeatedly, from a clean state) before adding it. Canonical documentation lives in README.md § Tests de bout en bout; do not duplicate it elsewhere.
- **The E2E suite has two tiers, `confidence` and `full`, and `full` is a strict superset.** `npm run e2e` runs the confidence tier — every scenario NOT tagged `@full` — and is what CI's `e2e-tests` job runs on every push. `npm run e2e:full` runs everything, `@full` scenarios included, and is what `scripts/release.sh`'s E2E gate runs (the dynamic security scan replays the confidence tier, for the reason written at its call site in `scripts/dast.sh`). **A new scenario lands in `confidence` by default** — an untagged spec is a confidence spec, so the default is self-enforcing — and is relegated to `full` (tagged `{ tag: '@full' }` on the test) only when it is costly *by nature*: a matrix, a combinatorial sweep, a long unavoidable wait. Never demote a scenario to `full` because it is slow through inefficiency — fix it — and never tag one `@full` to get a flaky test out of CI's way — fix it or delete it. The only `@full` content today is `specs/zz-module-boot-matrix.spec.js`, the per-module boot matrix (one boot per shipped module with that module disabled). **Budget**: the confidence tier measured **481 s wall clock (~8 min, provisioning included, 41 scenarios)** on the reference environment (a Claude Code container, `php -S`, no coverage) when the tiers were introduced; treat **12 minutes** (the measured figure plus a ~50% margin) as the ceiling — when a confidence run first exceeds it, re-examine the tier's contents (a scenario to move to `full`, a scenario that got slow, a scenario whose value no longer covers its cost) instead of raising the number.
- **A scenario whose specification is a page in the application must stay tied to it.** `tests/e2e/specs/scout-year-transition.spec.js` replays the four-step workflow described on `/admin/scout-year`; that page (`core/View/templates/admin/scout_year.html.twig`) and the step wording it renders (`Core\Http\Controller\ScoutYearController::buildTransitionSteps()`) each carry a reminder saying so. Changing the workflow — a step added, removed or reordered, a new blocking condition, a new control, or just rewording a label the test reads — means updating that test in the same change. The test reads labels off the page rather than copying them, so it survives a new year; it cannot survive a change of plan.
- **End-to-end runs can also report PHP coverage**: `E2E_COVERAGE=1 npm run e2e` writes `coverage-e2e.xml`, which SonarQube Cloud merges with PHPUnit's `coverage.xml` (CI sets it; it is off by default locally). This does not make the E2E suite a coverage tool — the rule above still holds — it just stops the composition root, which only the browser ever executes, from reading as 0%. Collection never affects the verdict: a failed merge is reported, never fatal.
- Tests must pass before any PR is submitted. CI runs the full test suite (PHP, JavaScript, and end-to-end) and blocks merge on failure.
- RBAC guard: explicit test coverage on every role boundary.
- Cookie consent: test that non-essential cookies are not set when consent is missing.

## Static analysis — run before every commit that touches PHP

`vendor/bin/phpstan analyse` (no path arguments — `phpstan.neon` already declares them) **must** be run and pass before committing any PHP change, not just before opening a PR. This is not optional, and it is not the same guarantee as `phpunit` passing.

**Why this exists**: a production incident where a controller's constructor signature was changed (a parameter removed) as part of a refactor. Every direct instantiation in `tests/` was updated and passing. The one call site that was *not* updated was `public/index.php`'s composition root, where every controller is wired up with a long, hand-written argument list — nothing there is under test, because no test boots the app's full dependency-injection wiring. The result: a `TypeError` fatal on literally every request, caught only when a live server was actually exercised, well after the change had been committed, pushed, and merged. `phpstan.neon`'s `paths` used to be `core/` only, which is exactly why this slipped through: PHPStan compares every constructor call's argument types against the class's declared parameter types — it would have flagged this instantly — but the one file where the bug lived (`public/index.php`) was outside its scope. `paths` now covers `core/`, `modules/`, and both `public/` entry points (`index.php`, `cron.php`) for exactly this reason. Do not narrow it back down.

**The takeaway that generalizes beyond this one bug**: whenever a class's constructor, a function's signature, or a method's parameters change, `grep` for every call site is not enough to trust by itself — a call site can be textually far from the class definition (a composition root, a factory, a DI container) and easy to miss by eye. Run PHPStan and read its output; do not assume "I updated everywhere I could find" is equivalent to "I updated everywhere."

Pre-existing findings unrelated to your change are captured in `phpstan-baseline.neon` — a clean run means no *new* errors, not zero findings ever. Never add a new finding to the baseline to make a change "pass"; fix the finding or, if it is a genuine pre-existing issue you are not touching, leave it in the baseline as-is. Regenerating the baseline (`--generate-baseline`) is only for intentionally accepting new pre-existing debt you are not fixing right now — never to hide an error your own change just introduced.

## Static analysis — run before every commit that touches `public/assets/js/`

`npm run typecheck` **must** be run and pass before committing any change to `public/assets/js/`, exactly the same requirement as `vendor/bin/phpstan analyse` above, and for the same reason: it is the only check that catches an unresolved identifier, a wrong argument count, a signature that drifted from a stale call site, or a statically-detectable invalid property access, *before* runtime — none of which Vitest (behavior at runtime) or SonarQube Cloud (general code quality, duplication, complexity, security) are designed to catch. It is the JavaScript equivalent of the PHPStan requirement above, applied to `public/assets/js/` instead of `core/`/`modules/`.

Mechanism: the TypeScript compiler used purely as a development-time checker over the existing plain JavaScript (`allowJs`/`checkJs`/`noEmit` — see `tsconfig.json`) — no transpilation, no build, nothing generated, nothing new served to the browser or shipped in a release (see § CSS / frontend below). `scripts/js-typecheck.mjs` (the script behind `npm run typecheck`) wraps `tsc` with a baseline mechanism — `js-typecheck-baseline.json` — modeled directly on `phpstan-baseline.neon`, so pre-existing debt can be accepted incrementally instead of blocking on a wholesale rewrite: a clean run means no *new* finding beyond what the baseline accepts, not zero findings ever. As of this writing the baseline is empty (`{}`) — every finding surfaced when the gate was introduced (mostly `document.getElementById()`/`querySelector()`'s generic return type not matching the specific element the code actually used) was fixed with a JSDoc type cast rather than accepted as debt — but treat that as the current state, not a guarantee: new debt can legitimately enter the baseline over time. The same rule as the PHPStan baseline applies regardless: never add a new finding to `js-typecheck-baseline.json` to make your own change "pass" — fix it, or if it is genuine pre-existing debt you are not touching, leave it as-is. Regenerate it (`node scripts/js-typecheck.mjs --generate-baseline`) only to intentionally accept new pre-existing debt, never to hide a finding your own change just introduced.

**JSDoc matters here more than it does for readability.** TypeScript's `checkJs` only enforces argument-count and argument-type checking on a function once it has JSDoc `@param` types — a plain, untyped JS function parameter is treated as effectively optional and its call sites go unchecked. Add `@param`/`@returns` JSDoc to a function when it has more than one parameter and is called from more than one place within its file (the exact shape of the constructor-signature-drift bug described above) — that is where an unannotated function silently gives up the one guarantee this gate exists to provide. Don't annotate a single-parameter DOM event handler or a one-off callback just to "look typed" — see § CSS / frontend for the general rule against turning this codebase into disguised TypeScript.

## CodeQL — check the scan after every push that touches JavaScript

**Nothing you run locally sees this class of defect.** PHPStan, PHPUnit, `npm run typecheck`, Vitest and the end-to-end suite all passed on a change that shipped two HIGH `js/xss-through-dom` alerts — a `data-file-viewer` attribute read straight into `image.src` and `download.href`, both navigable sinks, one of them handed to `win.open()` by the same file. The code was correct, tested and reviewed; it was also exploitable by any page that renders that attribute from user-controlled content. It was found days later, by hand, because a release refused.

So: **after pushing a change that touches `public/assets/js/`, check the repository's code scanning results before calling the work done.** Not at release time — then, a finding is weeks of other work away from the change that caused it.

How, in order of preference:

1. `gh api "repos/{owner}/{repo}/code-scanning/alerts" --paginate --jq '.[] | select(.state == "open")'`, or the same endpoint with `curl` and a token carrying `security_events` read. This is the only way to see the alerts themselves.
2. **When that returns `403 Resource not accessible by integration`** — the ordinary case for an agent whose token was never granted that permission, and the same gap `check_security_gate` already tolerates at release time — fall back to the commit's own check runs: `GET /repos/{owner}/{repo}/commits/{sha}/check-runs`, where GitHub's default CodeQL setup appears as `Analyze (javascript-typescript)` and `Analyze (actions)`. Be honest about what that proves: it says the scan **ran and completed**, not that it found nothing. Say exactly that to the user, and give them the Security tab so a human can read the alerts.
3. Read your own diff for the sinks CodeQL flags, because you can always do that: anything written into `src`, `href`, `action`, `formaction`, `srcdoc`, `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `location`, `window.open()`, `eval`, `setTimeout`/`setInterval` with a string, or `new Function`. **A value is not safe because it came from your own template**: a template renders user-controlled content, which is precisely how the alert above was reachable. Validate at the sink, not at the call sites — a later caller arrives without the check and nothing says so.

An alert that is genuinely a false positive is dismissed in the Security tab with a written justification, the same standard as a Dependabot alert (see § Releases). Never left open and unmentioned.

## CSS / frontend

- **Mobile-first**: write for mobile by default, add `min-width` breakpoints for larger screens.
- Use Bootstrap 5 components before writing custom CSS.
- Never duplicate a Bootstrap component in custom CSS.
- **Production frontend assets still require no build step.** No Sass, no webpack, no application bundler, no transpiler — `public/assets/js/*.js` is always plain, unbundled browser JavaScript, loaded via a classic `<script src="...">` tag, exactly as before. Any new vendored front-end library goes under `public/assets/vendor/<name>/` and must be added to `scripts/release.sh`'s dependency freshness gate (a new `check_vendored_asset_freshness` call — see that function's docblock) in the same change.
- **npm/Node are permitted, but strictly as development/test tooling** — narrowly reconciling this repo's older, blanket "no npm" rule. `package.json`/`package-lock.json`/`node_modules/` exist solely to run TypeScript's `checkJs` static analysis (`tsconfig.json`, `scripts/js-typecheck.mjs`, § Static analysis above) and the two Node-based test stacks — Vitest (`tests/js/`) and Playwright (`tests/e2e/`, ARCHITECTURE.md § 15) — locally and in CI; none of it is ever required to run, build, or deploy ScoutMagic itself, and none of it ships in a release artifact (`scripts/release.sh` excludes it, and `node_modules/`/`coverage/`/`tests/e2e/` output are gitignored — see `.gitignore`). **A browser automation runtime is test infrastructure, not frontend architecture**: Playwright downloads a Chromium binary to *drive* the site the way a visitor's browser does, and compiles, bundles, transpiles, and minifies exactly nothing — `public/assets/js/*.js` is still shipped byte-for-byte as written, loaded by a plain `<script src="...">`. The same is true of `tsc`: `--noEmit` means it only reads `public/assets/js/*.js` and reports, it never writes a compiled/transpiled copy anywhere, and TypeScript itself never becomes the production source language. Do not let this permission creep into introducing an actual frontend build pipeline (bundler, Sass compiler, transpiler) — that remains banned unless this architecture is deliberately revisited.
- Touch targets: 44px is a comfort goal for small controls (icon-only buttons, `.btn-sm`, checkbox labels), handled centrally in `app.css`'s `pointer: coarse` block — never a universal minimum, and never via inline `min-height` styles in templates. WCAG 2.2 AA requires 24×24; Bootstrap's 38px defaults pass. Do not inflate standard inputs to 44px. See `design.md` §7.2.
- UI conventions (lexicon, back navigation, button variants, feedback, page structure, empty states) live in `design.md` §7 and are enforced by `tests/Core/View/UxConventionsTest.php` — read §7 before adding any template.
- HTML5 input types (`tel`, `date`, `email`) for appropriate keyboard on mobile.

## Database

- Table and column names in English, snake_case.
- Every table that holds member-related data: include `scout_year_id` foreign key, unless the data itself genuinely isn't scout-year-scoped (e.g. `calendar_events`, `sos_oncall_assignments` — a duty date or calendar event isn't tied to a school year the way a member's function/badge/photo is). Default to including it; only omit with a clear reason.
- Personal data columns: `BLOB` type, encrypted/decrypted only in Repository layer.
- Blind index column alongside any encrypted field that needs exact-match search.
- `schema.sql` is the single source of truth — no incremental migration files.
- **Two engines are supported, and only one of them is what production runs.** The reference installation is **MariaDB 10.11** on shared hosting; CI's full suite runs **MySQL 8**. They disagree on how `INFORMATION_SCHEMA` reports what they store — display widths, `CURRENT_TIMESTAMP` spelling, JSON as an alias for LONGTEXT, and above all column defaults, where MariaDB returns a SQL *expression* (a bare `NULL` for "no default", string literals quoted) and MySQL returns a value (a real SQL NULL, literals unquoted). Each engine is internally unambiguous; together they contradict each other, which is why `SchemaIntrospector::decodeDefault()` reads the server version. Anything touching introspection, type normalisation or default handling has to be **checked against both**, and the asymmetry to keep in mind is that the dangerous direction is silent: code correct on MySQL and wrong on MariaDB passes the `test` job and reaches production. Locally, `npm run test:engines` (`scripts/test-engines.sh`) runs the suite against both: the MariaDB the session hook already started, and a throwaway MySQL 8 — a Docker container normally, a native `mysqld` where one exists. **`mysql-server` and `mariadb-server` conflict as Debian/Ubuntu packages** — apt removes one to install the other — so a container is what makes "both, locally" possible at all, the same mechanism `scripts/e2e.sh` already uses. An engine it could not start is reported as such and the script exits non-zero: "green on both engines" and "green on the one engine I could find" are different sentences. The `database-mariadb` CI job is the other half: the **whole** suite against MariaDB 10.11, no coverage. Whole rather than `--group=database` on purpose — every file reading `TEST_DB_*` carries that group today, but only until someone adds one that does not, and the failure mode of that omission is the silent one. Do not narrow it, do not drop it, and do not assume the `test` job covers this.
- **Indexes are auto-migrated, but matched by NAME only.** `Core\Database\SchemaComparator` creates any declared index absent from the database (`ADD INDEX`/`ADD UNIQUE INDEX`) — but an index that already exists under the same name is never compared column-by-column, so **changing an existing index's columns in `schema.sql` is silently a no-op on every installed site**. To redefine an index, declare it under a NEW name; the old one lingers on installed sites (nothing is ever auto-dropped, and `drops.sql` only handles columns and foreign keys — a stale index stays until someone drops it by hand, which is usually fine). Primary-key changes are skipped entirely. See ARCHITECTURE.md §10.
- **A module's `schema.sql` no longer needs a `module.json` version bump to take effect.** It used to, and that was a rule nothing enforced: `ModuleManager::loadEnabledModules()` re-applied a module's schema only when the declared `version` exceeded the one in the registry, so editing `schema.sql` alone was silently a no-op on every already-enabled install, and produced real `Unknown column`/`PDOException` errors in production. The whole declared schema — `schema/core.sql` plus every `modules/*/schema.sql`, enabled or not — is now migrated as one set by whatever deploys the code (`Core\Database\SchemaFiles`, ARCHITECTURE.md §10). Editing a module's `schema.sql` is enough. Bump the module `version` when the module itself changes in a way its users should see, or when the new manifest stops declaring a setting the old one did — that pruning is still what the version comparison drives.
- **A module's table must never carry a foreign key into another module's table.** The whole schema is migrated in one pass, core first and then modules in alphabetical order, so such a constraint would work or fail depending on how the two module names happen to sort — and fail on a fresh install, where neither table exists yet. Put the shared table in `schema/core.sql`, or drop the constraint. `Tests\Architecture\ModuleSchemaBoundariesTest` enforces this.

### Setting types

`settings.setting_type` drives validation (`Core\Config\SettingService`) and rendering. Beyond the usual `text`/`textarea`/`boolean`/`number`/`select`/`email`/`url`/`tel`/`date`/`color`, one type carries a security meaning:

- **`secret`** — a setting whose *value* must never be displayed or exported. It is filtered out of Configuration > Réglages entirely (`SettingsController::index()`), and the support package's `configuration-parameters.xlsx` writes `[REDACTED]` in its place while keeping the key and label visible (ARCHITECTURE.md §8.48). Use it for any setting that is a credential, a token, or anything a screenshot of the settings page must not reveal. **No setting carries it today** — every real credential lives outside `settings` (`secrets.enc`, or an encrypted BLOB column) and should keep doing so; `secret` is the safety net for the case where that isn't practical, not an invitation to start storing credentials in `settings`.

## Reference dataset — a change to the import pipeline is a change to it

`tests/fixtures/reference-dataset/` holds a reproducible dataset for a test
instance: three scout years of a fictional Belgian unit, its Desk exports, its
bank statements, its photos, and a CLI builder that replays all of it through
the application's own services. Its own `README.md` is the manual.

**Changing any of the following means checking that dataset in the same
change**, not in a follow-up:

- the Desk export format, or `Core\Import\DeskCsvParser` — its
  `EXPECTED_HEADERS`, its delimiter detection, its boolean parsing, the
  one-row-per-(function × address) shape;
- the bank statement parser, `Modules\Finance\Parser\BnpParser` — its column
  map, its amount parsing, the `REFERENCE BANQUE` deduplication key;
- the import pipeline itself (`Core\Import\DeskImportService`,
  `MappingResolver`, `MemberYearRepository`), including anything about how
  sections are deactivated, how `scout_year_offset` is inherited, or how
  Staff d'U membership is synced;
- the schema of a member-related table (`members`, `member_years`,
  `member_functions`, `member_addresses`, `sections`, `functions`,
  `age_branches`, `member_photos`, `section_staff_photos`).

Two tests hold the line and will tell you: `Tests\Integration\
ReferenceDatasetFormatTest` (every committed file still goes through the real
parsers, and still matches its generator byte for byte) and `Tests\Integration\
ReferenceDatasetImportTest` (the exports still MEAN what they say — the branch
passages happened, the emptied section went inactive, the returning member
inherited their offset). `Tests\Integration\ReferenceDatasetBuilderTest`
covers what the builder writes on top.

The generated files are committed. If you change the generator, re-run
`php tests/fixtures/reference-dataset/generate.php` and commit what it wrote —
`--check` compares byte for byte and fails otherwise, the same mechanism as
`js-typecheck-baseline.json`.

The directory is in `phpstan.neon`'s `paths` on purpose: the builder composes
core and module services by hand, exactly like the composition roots, and
breaks the same way. Do not remove it from there.

`build.php --reset` empties an instance that has already served, then builds
into it (README §8.4, `InstanceReset`). It deliberately spares `settings` and
`module_registry` — the same two tables as
`Core\Maintenance\BackupService::CONFIG_ONLY_TABLES`, so that the site stays
installed and its modules stay enabled. **If that whitelist grows a third
table, the reset must follow**; a test pins the two lists to each other and
fails until it does.

## RGPD — a new outbound flow is a documentation change

Any new feature that sends data to a third party — an API call, a mail relay, a usage report, anything leaving the hosting network — requires updating `Core\View\RgpdContentService`'s default content **and** its AI system prompt in the same change, exactly as § RGPD page maintenance already requires for a new sub-processor. This holds even when the data is aggregated and carries no personal data: the site's own URL leaving the installation is a fact the RGPD page has to state (ARCHITECTURE.md §8.47), and describing it as "anonymous" when it isn't would be worse than not mentioning it at all.

## Display name convention

Everywhere a member name is shown: `totem ?? first_name`. Use `{{ member|display_name }}` Twig filter. Never hardcode the logic.

## Email

All email sent via `MailService::send()`. Never send email directly. The service handles subject prefix, DKIM signing, multipart, and delivery mode.

## Scheduler

Use `SchedulerService` for any delayed or timed action. Never use `sleep()`, cron-specific code, or ad-hoc timing logic. Declare task handlers in `module.json`.

## Merging a pull request

**The maintainer's instruction is the only authorization to merge**, and
nothing substitutes for it — not a green pipeline, not this file, not your
reading of what they would probably want. Without it, green and
merge-ready is where your work stops and you say so.

With it, arm **auto-merge** rather than watching the pull request:

```
gh pr merge <number> --squash --auto
```

Where `gh` is not installed — a Claude Code session running on the web has
no GitHub CLI — the same thing is the GitHub MCP server's
`enable_pr_auto_merge` with `mergeMethod: SQUASH`. It fails with *"Auto-merge
is not enabled for this repository"* if the setting below ever gets turned
off, which is the one error worth recognising rather than working around.

GitHub then merges the moment the ruleset on `main` is satisfied, and the
instruction is carried out without the maintainer being called back. Polling
a pull request for an hour is not diligence — on 2026-09-05 that cost four
CI cycles and three interruptions on #152, and auto-merge is the answer
this repository chose (`docs/quality-pipeline.md` § Auto-merge).

Arming it is *merging*, so everything that must be true before a merge must
be true before you arm it: every review thread replied to and resolved on
purpose rather than to clear the way, the PR template's checklist honestly
filled, and no finding of your own left unfiled. What you must never do is
arm it and walk away from a pull request you have not finished — auto-merge
does not wait for you to come back.

Two things it does not do, and both have bitten this project. It does
**not** update a branch that has fallen behind `main` — the ruleset does not
demand that any more, but the day it does again, an armed pull request just
sits there. And it is **disarmed in silence** by a change of base branch or
a push from an account without write access.

## Releases

When the user asks to release a new version (`scripts/release.sh`), do this **in this order**:

1. **Fix first, release later.** Before running the script, query, fix (or dismiss, only when truly not applicable) every open GitHub security item in the `xdubois-57/scoutmagic` repository:
   - open CodeQL scanning findings (`gh api "repos/{owner}/{repo}/code-scanning/alerts" --paginate --jq '.[] | select(.state == "open")'`)
   - open Dependabot alerts (`gh api "repos/{owner}/{repo}/dependabot/alerts" --paginate --jq '.[] | select(.state == "open")'`)
   - active SonarQube Cloud findings for `main` — **every** unresolved issue, plus unreviewed Security Hotspots. The one exemption is a pure convention nit: see § SonarQube Cloud release gate below for the exact rule (project `xdubois-57_scoutmagic`, https://sonarcloud.io/project/overview?id=xdubois-57_scoutmagic)
2. Only after all of them are resolved, run the release script. The script's **deployment gate** (www.scoutmagic.be is on the previous release and responds normally — via the public `GET /api/version`, `Core\Http\Controller\VersionController`), **security gate** (`composer audit` + `npm audit` — always mandatory and blocking, queried directly against public advisory databases, no GitHub permission of any kind involved — plus the CodeQL/Dependabot query described above; see § A gate that cannot be verified from the current environment below for what happens when that query alone hits a permission gap), **tests gate** (`vendor/bin/phpstan analyse` + `vendor/bin/phpunit` + `npm run typecheck` + `npm run test:coverage` for `tests/js/`), **end-to-end gate** (`npm run e2e` — the public home page must boot through the real `public/index.php` and render in a headless Chromium; see README.md § Tests de bout en bout), **dynamic security gate** (`scripts/dast.sh --profile=standard` then `--profile=passive` — the authorization matrix, then OWASP ZAP's passive rules over the browser suite; see SECURITY.md §§ 35-36), **dependency freshness gate** (`composer outdated --direct` + every vendored front-end library — Bootstrap, Bootstrap Icons, Chart.js, Leaflet, html5-qrcode — each vs. its latest upstream GitHub release), and **SonarQube Cloud gate** (`scripts/check-sonar-release.sh` — see below) are the final checks, not the fix: if any of them still finds a problem, the script aborts before creating any commit or tag. Do not bypass or disable any gate to make a release "pass" — `--skip-deployment-check`, `--skip-security-gate`, `--skip-tests-gate`, `--skip-e2e-gate`, `--skip-dast-gate`, `--skip-dependency-check`, and `--skip-sonar-gate` (see below) exist only for genuine emergencies, not to route around a real finding, a real test failure, a real outdated dependency, or a real production problem.

### SonarQube Cloud release gate

`scripts/check-sonar-release.sh` (invoked automatically by `release.sh` unless `--skip-sonar-gate` is passed) queries the SonarQube Cloud Web API for the `main` branch and blocks the release, fail-closed, when:

- **any unresolved issue at all survives the one exemption below** — see *The rule, in one sentence*;
- any Security Hotspot is still `TO_REVIEW`;
- the project's Quality Gate is not `OK`;
- SonarQube Cloud cannot be reached, `SONAR_TOKEN` is missing, authentication fails, the API returns an unexpected status or invalid JSON, or no analysis can be confirmed for the exact commit being released.

#### The rule, in one sentence

**100% of SonarCloud findings must be fixed, except those that are — all three at once — software quality `MAINTAINABILITY`, severity `LOW`, and tagged `convention`.**

Everything else blocks a release: every `SECURITY` and `RELIABILITY` impact at every severity, every `MAINTAINABILITY` impact at `MEDIUM` or above, and every `MAINTAINABILITY` `LOW` that is *not* tagged `convention`. There is no severity floor and no "only HIGH and BLOCKER" any more — that was the old rule, and it let hundreds of real findings accumulate under it.

Three things about the exemption are easy to get wrong, and the gate is written to get them right:

- **All three conditions, never two.** A `LOW` `convention` finding whose impact is `RELIABILITY` blocks. A `MAINTAINABILITY` `LOW` with no `convention` tag blocks. A `MAINTAINABILITY` `convention` finding at `MEDIUM` blocks.
- **An issue carries a LIST of impacts, not one.** The same finding can be `MAINTAINABILITY`/`LOW` *and* `RELIABILITY`/`MEDIUM`. It is exempt only when **every** impact it carries is `MAINTAINABILITY`/`LOW`; one impact outside that and it blocks. This is why `check-sonar-release.sh` filters the issue list and counts what remains, rather than subtracting a count of exempt issues from a total — subtraction would silently excuse exactly those mixed-impact findings.
- **An issue with no impacts at all is not exempt.** Absence of evidence is not an exemption; it blocks and gets looked at.

An exempt finding is still a finding. The exemption exists so that formatting and naming preferences do not hold a release hostage, not to make them acceptable — fix them in the normal flow when touching the file.

`SONAR_TOKEN` is read from the environment, or from `.sonar-token` at the repo root if the environment doesn't have it (gitignored, one line, never committed — see `.gitignore`). With no token in either place and a real terminal attached, the script prompts for it (hidden input) and offers to save it to `.sonar-token`, but only after confirming with `git check-ignore` that the file is actually gitignored — it refuses to write the token and fails closed otherwise. Without a terminal (this is normally how Claude runs it), a missing token fails closed exactly as before this convenience existed — never assume or fabricate a token.

`--skip-sonar-gate` bypasses this gate the same way `--skip-security-gate` bypasses CodeQL/Dependabot — see § Bypass flags below. Prefer fixing the finding, or resolving/dismissing it in SonarQube Cloud with a real justification (same standard as a Dependabot alert, see below), over bypassing. Test the gate's own logic with `scripts/check-sonar-release.test.sh` (mocked, no live API calls) rather than by manipulating real findings.

Fix upgrades/dependency alerts as code changes in the normal flow (with tests), not by blindly dismissing them — but for alerts with demonstrably no fix or clear false positives, dismissing with a justification is acceptable so the gate can pass.

### Release notes — mandatory when releasing from Claude

`scripts/release.sh` accepts `--notes-file <path>`. Every time a release is started from Claude, you **must** write a release-notes file and pass it via `--notes-file` — never rely on the auto-generated commit-list notes (the default when the flag is omitted, intended for manual/human-triggered releases only). Write the file to a temp path (e.g. `mktemp`) since the notes are multi-line Markdown; do not attempt to pass this inline.

The notes file must be a human-readable Markdown document, in French, covering (omit a section if genuinely empty, but check first):

- A short summary of the release in plain language.
- The complete list of new features.
- The complete list of bug fixes.
- The complete list of security fixes.
- Any updated open-source dependency (name, old → new version), including transitive bumps pulled in by `composer update`.
- Any backward-compatibility issue or warning (schema changes requiring manual action, config changes, deprecated behavior, etc.). State explicitly if there are none.

Derive this from the actual diff/commit list since the last tag — do not guess or copy the commit subjects verbatim; summarize what changed and why it matters to someone deciding whether to update.

### Bypass flags — emergency use only

- `--skip-deployment-check`: skips checking that www.scoutmagic.be already has the previous release installed and responds normally. Only use this if the user explicitly asks for an urgent release despite production not being confirmed healthy/up to date; the script prints a warning, and you must tell the user the same and follow up to verify production right after.
- `--skip-security-gate`: skips composer audit, npm audit, AND the CodeQL/Dependabot check — all of it. Only use this if the user explicitly asks for an urgent release despite a real, known finding in one of these; the script prints a warning, and you must tell the user the same and follow up to resolve them right after. This is **not** what a CodeQL/Dependabot permission gap needs — see the next section, `check_security_gate` already turns that specific case into a non-blocking warning on its own, while composer audit/npm audit still run and still block for real.
- `--skip-tests-gate`: skips PHPStan, PHPUnit, the JavaScript static analysis (`npm run typecheck`), AND the JavaScript unit tests (`npm run test:coverage`). Only use this if the user explicitly asks for an urgent release despite failing tests/analysis; the script prints a warning, and you must tell the user the same and follow up to fix them right after.
- `--skip-e2e-gate`: skips the end-to-end browser test (`npm run e2e` — see README.md § Tests de bout en bout). It is a separate flag from `--skip-tests-gate` on purpose: it is the only gate needing a reachable MySQL server and a Chromium binary, and a releaser missing either must not be pushed into dropping PHPStan/PHPUnit/the JavaScript static analysis/Vitest as well. Only use this if the user explicitly asks for an urgent release despite the end-to-end test failing or being unrunnable; the script prints a warning, and you must tell the user the same and follow up right after. Note what skipping it actually costs: nothing else in the release verifies that the application boots at all.
- `--skip-dependency-check`: skips the dependency freshness gate (outdated direct Composer packages, outdated vendored front-end libraries — Bootstrap, Bootstrap Icons, Chart.js, Leaflet, html5-qrcode). Only use this if the user explicitly asks for an urgent release despite outdated dependencies; the script prints a warning, and you must tell the user the same and follow up to update them right after.
- `--skip-dast-gate`: skips the dynamic security gate — the authorization matrix AND the passive scan. It is separate from `--skip-e2e-gate` because it is the only gate needing Docker, and a releaser without it must not have to drop the browser tests too. Only use this if the user explicitly asks; the script prints a warning, and you must tell the user to run `./scripts/dast.sh --profile=standard` and `--profile=passive` right after publishing.
- `--skip-sonar-gate`: skips the SonarQube Cloud check (every unresolved finding bar the pure convention nits exempted above, unreviewed Security Hotspots, the Quality Gate). Only use this if the user explicitly asks for an urgent release despite open findings or an unavailable/unconfirmed SonarQube Cloud result; the script prints a warning, and you must tell the user the same and follow up to resolve them right after.

### A gate that cannot be verified from the current environment

**The CodeQL/Dependabot permission gap specifically is already handled inside `check_security_gate` itself** and no longer needs any of the steps below: a `403 Resource not accessible by integration` on either query (GitHub's exact message when the calling token/App is authenticated and can reach the repo for everything else, but was never granted that one repository permission) prints a warning and lets the gate continue, rather than aborting the release — `composer audit`/`npm audit` still ran first and still block on a real finding, and are themselves entirely unaffected by this permission (they query FriendsOfPHP's/npm's public advisory databases directly, no GitHub API involved at all). Read the warning it prints; it names the exact Security-tab URL to check by hand as a real substitute for what it couldn't query. Any OTHER error from either query (auth failure, rate limit, an unreachable host, an unexpected response) still fails closed exactly as before.

The bypass flags above are for a **real** finding/failure the user knowingly accepts. A different, more general situation — this is what the rest of this section is about — is some OTHER gate whose own tooling cannot run in the current environment for a similar reason (the dependency-freshness gate's vendored-library check hitting the same kind of access wall against `twbs/bootstrap` et al. is the case this section was first written for), independent of whether any finding actually exists. Do not guess, and do not silently pass or silently skip:

1. Try to resolve it first, the same way as any other environment gap (install a missing CLI, start a needed daemon, look for another already-authorized path to the same data — as was done for `gh` and Docker before this note was added). Only move to step 2 once the check genuinely cannot run.
2. Ask the user to check manually, and be specific: give them the exact URL(s) to look at (e.g. `https://github.com/{owner}/{repo}/security/code-scanning`, `https://github.com/{owner}/{repo}/security/dependabot`), and ask them to confirm either "empty" or, if not, the actual list of what's open.
3. If the user confirms it's empty, proceed with the release, passing that gate's `--skip-*-gate` flag (its own tooling still can't run, so the script would otherwise abort on the environment gap, not on a finding) — but say plainly, in your own reply and in the release notes' "Vérifications effectuées" section, that this gate was verified manually by the user rather than by the script, and how.
4. If the user reports something open, treat it exactly like any other real finding under § 1 above — fix or dismiss it — before running the script at all.

Do not reach for this on your own initiative merely because a check is inconvenient or slow; it's specifically for a gate that is structurally unreachable from the environment you're running in (confirmed by trying and by fully explaining why to the user), not a substitute for actually running a gate that could run.

Never pass any of these flags on your own initiative to work around a genuine failure — fix the underlying issue instead (update the outdated package/vendored library, fix the test, resolve the finding, wait for/investigate the production deployment). These flags are for the user's explicit, informed decision only.
