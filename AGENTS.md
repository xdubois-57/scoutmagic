# Agent rules

This file is automatically loaded by Devin, Cursor, Copilot, and other AI coding agents. These rules are non-negotiable. Before making any change, also read `SECURITY.md`.

## Language

- All code, comments, variable names, function names, class names, table names, column names: **English**.
- All user-facing text (Twig templates, labels, messages, descriptions, settings labels): **French**.
- **Everything written *about* a change: French.** Commit messages (title and
  body), pull request titles and descriptions, release notes. This line used
  to say English, and the history shows it: the maintainer reads this
  repository's log and its Releases in French, and site administrators read
  the release notes.
- **A reply on a pull request review thread is the one exception**, and it is
  deliberate: `.claude/skills/steward/SKILL.md` § Reply in the language of
  the thread stands unchanged. Reviews arrive in English and the maintainer
  writes French, so a reply matches whoever it answers. The rule above is
  about the *record* a change leaves behind; a thread reply is a
  conversation with the person reading it, not part of that record.
- No exceptions beyond that one. A French variable name or an English UI
  label is always a bug, and so is an English commit message or PR title.
- **A Twig comment is a comment**, so it is English, and the repository does
  not yet match: 144 French ones survive across 45 template files, against
  roughly a thousand English (issue #327). That is the rule being owed a
  correction, not the rule being softer than it reads — so write yours in
  English, and translate a file's comments when a change takes you into it
  anyway. `Tests\Architecture\TwigCommentsAreEnglishTest` holds the count as
  a ratchet that can only shrink; it is never the place to park a comment
  written today.

The split is easy to state and easy to get wrong in the same file: **the code
and its comments are English, everything written *about a change* is
French.** A docblock explaining why a method exists is a comment, so it
stays English; the commit message explaining why that method was added is
prose for a human, so it is French.

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
and `.claude/skills/triage` is what decides them: `issue-triage.yml` fires on
every issue opened, including the one you just filed, and applies the verdict
plus `triage:done` — the workflow does the applying, from a verdict the agent
returns, which is why no label the model spells can ever reach an issue.

**That pass never closes an issue, `bug:not-a-bug` included.** It posts an
answer and labels; the maintainer reads it and decides. A verdict reached by
a reader of the code who never ran the site is a label to disagree with, not
a report to end. So:

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
- **When adding or changing frontend (`public/assets/js/`) behavior that is deterministic and reasonably decoupled from the DOM it ships with** (form validation, complexity/strength checks, client-side computed state, anything not primarily "wire two DOM elements together") **write or update a Vitest unit test in `tests/js/`** exercising the real production file (`import` it — never copy/reimplement its logic in the test). Not every script needs this: a thin script whose entire job is gluing a handful of DOM elements together with no independent logic of its own is often not worth the isolation cost — use judgment, the same way "every Service method" above doesn't mean every one-line getter. `npm run test:coverage` runs in CI either way (`Checks / javascript-tests`) regardless of whether a given change added new JS tests.
- Frontend JavaScript unit tests exist to catch regressions in that isolated logic fast and without a browser — they are a complement to, never a replacement for, this project's PHP integration tests or the manual mobile/desktop visual verification ARCHITECTURE.md § 15 already requires. Production JavaScript itself must never acquire a Node/runtime/build dependency because it is now unit-tested — see § CSS / frontend above.
- When modifying existing code, update the corresponding tests to match the new behavior.
- When fixing a bug, write a test that reproduces the bug first, then fix it.
- **A test may not re-implement a Twig filter this project ships.** Every filter a template can use lives in a `Core\View` extension — `DateFilterExtension`, `MemberNameFilterExtension`, `FormatFilterExtension`, `RichTextFilterExtension`, `TextNormalizerExtension`, `CompactHtmlExtension` — precisely so that a test which builds its own `Twig\Environment` (139 files do, against 79 that call `TwigFactory::create()`) can register the real ones with `addExtension(new …)` instead of writing a double for the one its template complained about. A double is free to drift from the original and nothing is watching it: `Tests\Core\View\DisplayNameFilterTest` asserted six behaviours of a copy of `display_name` pasted into its own `setUp()`, and returning `'MUTANT'` from the real filter left it — and the 2 210 other tests that build the real environment — green. `Tests\Core\View\TestEnvironmentsUseTheRealFiltersTest` holds the rule. Twig FUNCTIONS are not covered yet (issue #465 § B) — four of them read services out of the environment's globals.
- **End-to-end (`tests/e2e/`, Playwright + headless Chromium)**: one canonical command, `npm run e2e` (`scripts/e2e.sh`), which provisions a throwaway install + database, serves it through the real `public/index.php`, drives it with a real browser, and tears everything down. It exists to catch what PHPUnit structurally cannot: the application failing to boot at all (a broken composition root, a failed dependency wiring, a bootstrap that throws before any route runs). Keep it to a small number of high-value scenarios — this is a release gate, not a coverage tool; a flaky or slow E2E suite is worse than none. Prove a new scenario is deterministic (run it repeatedly, from a clean state) before adding it. Canonical documentation lives in README.md § Tests de bout en bout; do not duplicate it elsewhere.
- **The E2E suite has two tiers, `confidence` and `full`, and `full` is a strict superset.** `npm run e2e` runs the confidence tier — every scenario NOT tagged `@full` — and is what CI's `e2e-tests` job runs on every push. `npm run e2e:full` runs everything, `@full` scenarios included, and is what the release workflow's evidence run does (`.github/workflows/checks.yml` with `evidence: true`, on every `v*` tag — the release standard, as against the push standard above; the dynamic security scan replays the confidence tier, for the reason written at its call site in `scripts/dast.sh`). **A new scenario lands in `confidence` by default** — an untagged spec is a confidence spec, so the default is self-enforcing — and is relegated to `full` (tagged `{ tag: '@full' }` on the test) only when it is costly *by nature*: a matrix, a combinatorial sweep, a long unavoidable wait. Never demote a scenario to `full` because it is slow through inefficiency — fix it — and never tag one `@full` to get a flaky test out of CI's way — fix it or delete it. The only `@full` content today is `specs/zz-module-boot-matrix.spec.js`, the per-module boot matrix (one boot per shipped module with that module disabled). **Budget**: the confidence tier measured **481 s wall clock (~8 min, provisioning included, 41 scenarios)** on the reference environment (a Claude Code container, `php -S`, no coverage) when the tiers were introduced; treat **12 minutes** (the measured figure plus a ~50% margin) as the ceiling — when a confidence run first exceeds it, re-examine the tier's contents (a scenario to move to `full`, a scenario that got slow, a scenario whose value no longer covers its cost) instead of raising the number.
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
- **Production frontend assets still require no build step.** No Sass, no webpack, no application bundler, no transpiler — `public/assets/js/*.js` is always plain, unbundled browser JavaScript, loaded via a classic `<script src="...">` tag, exactly as before. Any new vendored front-end library goes under `public/assets/vendor/<name>/` and must be added, in the same change, to `scripts/release.sh`'s dependency freshness gate (a new `check_vendored_asset_freshness` call — see that function's docblock) and to `scripts/dependency-inventory.php`'s `INVENTORY_VENDORED_LIBRARIES` map with its licence — `tests/Core/System/DependencyInventoryTest` fails on a directory the map does not know, and on a banner pattern that differs from the gate's.
- **A vendored file never points at a source map that is not served.** A minified distribution ends with a `sourceMappingURL` comment naming a `.map` file that this repository does not ship, and the browser requests it on every page: issue #167 was three 404s in every visitor's console, on a site nobody was debugging. When vendoring or re-vendoring a library, strip that trailing comment (or copy the `.map` next to the file — either is correct, this repository strips). `tests/Core/View/VendoredAssetSourceMapsTest.php` fails on the next dangling reference, which is the only thing that will notice.
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
- **A test class that builds a database carries `#[\PHPUnit\Framework\Attributes\Group('database')]`** — the ATTRIBUTE, never a `@group database` doc-comment, which PHPUnit 13 does not read and which selects nothing (issue #481 §A). "Builds one" is the three idioms this suite uses — `DatabaseTestHelper::createTestDatabase()`, a bare `new \PDO('sqlite::memory:')`, a module helper's `createTables()` — and it counts through inheritance AND through composition, so a class whose base builds the fixture carries it too — and so does one that merely BUILDS a support class whose constructor opens a connection, or calls a helper method that does. `extends` was the only path the guard followed at first, and four classes reached an in-memory database through composition while it called them green. Where the build sits **inside individual test methods** rather than in `setUp()`, the attribute goes on those methods and the class needs none: what the rule is really about is that every test needing a database is selected, and `Core\Import\DeskCsvParserTest` says it that way — four of its eighteen tests build one, each marked, and `--group=database` selects exactly those four. Anywhere else — `setUp()`, or a private helper whose callers this cannot see — only the class-level attribute covers it. `Tests\Architecture\DatabaseBackedTestsCarryTheGroupTest` holds this, and holds it **one way only**: carrying the group without building one is nobody's bug, because the cost of an over-selected test is milliseconds and the cost of a missing one is a run that looked like it checked the database part and did not. CI ignores the group by the rule above; what depends on it is manual selection, which the session hook recommends on every open — and when this guard first ran it was missing from **a hundred and seventy-four classes**, two whole modules (`covoiturage` and `documents`) among them — issue #395's own figure of a hundred and thirty was counted by a reader that has since been corrected four times, so the number to quote is the guard's. See `docs/quality-pipeline.md` § Who carries `database`.
- **Indexes are auto-migrated, but matched by NAME only.** `Core\Database\SchemaComparator` creates any declared index absent from the database (`ADD INDEX`/`ADD UNIQUE INDEX`) — but an index that already exists under the same name is never compared column-by-column, so **changing an existing index's columns in `schema.sql` is silently a no-op on every installed site**. To redefine an index, declare it under a NEW name; the old one lingers on installed sites (nothing is ever auto-dropped, and `drops.sql` only handles columns and foreign keys — a stale index stays until someone drops it by hand, which is usually fine). Primary-key changes are skipped entirely. See ARCHITECTURE.md §10.
- **A module's `schema.sql` no longer needs a `module.json` version bump to take effect.** It used to, and that was a rule nothing enforced: `ModuleManager::loadEnabledModules()` re-applied a module's schema only when the declared `version` exceeded the one in the registry, so editing `schema.sql` alone was silently a no-op on every already-enabled install, and produced real `Unknown column`/`PDOException` errors in production. The whole declared schema — `schema/core.sql` plus every `modules/*/schema.sql`, enabled or not — is now migrated as one set by whatever deploys the code (`Core\Database\SchemaFiles`, ARCHITECTURE.md §10). Editing a module's `schema.sql` is enough. Bump the module `version` when the module itself changes in a way its users should see, or when the new manifest stops declaring a setting the old one did — that pruning is still what the version comparison drives.
- **A module's table must never carry a foreign key into another module's table.** The whole schema is migrated in one pass, core first and then modules in alphabetical order, so such a constraint would work or fail depending on how the two module names happen to sort — and fail on a fresh install, where neither table exists yet. Put the shared table in `schema/core.sql`, or drop the constraint. `Tests\Architecture\ModuleSchemaBoundariesTest` enforces this.

### Setting types

`settings.setting_type` drives validation (`Core\Config\SettingService`) and rendering. Beyond the usual `text`/`textarea`/`boolean`/`number`/`select`/`email`/`url`/`tel`/`date`/`color`, one type carries a security meaning:

- **`secret`** — a setting whose *value* must never be displayed or exported. It is filtered out of Configuration > Réglages entirely (`SettingsController::index()`), and the support package's `configuration-parameters.xlsx` writes `[REDACTED]` in its place while keeping the key and label visible (ARCHITECTURE.md §8.48). Use it for any setting that is a credential, a token, or anything a screenshot of the settings page must not reveal. **One setting carries it today, and it holds a hash rather than a credential**: `support_triage_token_hash` (`modules/support_dashboard`, ARCHITECTURE.md §8.49sexies) keeps the SHA-256 of the token the GitHub triage presents, and nothing else. Every real credential lives outside `settings` (`secrets.enc`, or an encrypted BLOB column) and should keep doing so; `secret` is the safety net for the case where that isn't practical, not an invitation to start storing credentials in `settings`.

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

**The composition of the population is data, not decoration.** Roughly
half the unit shares a home with somebody, the three household sizes all
carry volume, and three homes a year hold a tariff nobody updated
(README §9.1 « Les foyers », issue #201). Those are what give « Justesse
des tarifs », `FeeEstimationService` and the registration module's
household count anything to work on; a generator change that flattens them
back to one person per address turns three screens into blank pages
without failing to parse. `ReferenceDatasetImportTest` holds floors under
all of it. Two things in particular are easy to break by accident: every
member of a home carries the **same** second address (the site groups
households on *every* address, so one sibling with an extra address and one
without land in differently-sized households and the screen reports an écart
the generator invented), and any change to the `Rng` flow rewrites the whole
dataset — expected, but the diff is enormous.

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

Everywhere a member name is shown: `totem ?? first_name`. Use `{{ member|display_name }}` Twig filter (`Core\View\MemberNameFilterExtension`, with `full_name` and `display_name_full` beside it). Never hardcode the logic, and never re-implement it in a test — see § Tests.

## Email

All email sent via `MailService::send()`. Never send email directly. The service handles subject prefix, DKIM signing, multipart, and delivery mode.

## Scheduler

Use `SchedulerService` for any delayed or timed action. Never use `sleep()`, cron-specific code, or ad-hoc timing logic. Declare task handlers in `module.json`.

## "Fix the backlog" — what that instruction asks for, exactly

The maintainer asks for this in French — « fixe le backlog », « répare le
backlog » — and it is a standing instruction, not a one-off. It means
**one accepted ticket at a time, carried from end to end**, and then the
next.

It can be given to several agents at once, and they will not pick the same
ticket without being told about each other: **step 2 is the whole of the
coordination**, and it works because creating a git ref is atomic
server-side where applying a label or an assignee is not. Step 6 says why
there is nothing equivalent for merging, and why this repository already
decided it does not need one.

1. **List the OPEN issues carrying `status:accepted`, lowest number
   first.** That label, and only that label, selects the work. Nothing
   automatic ever applies it (`.claude/skills/triage/SKILL.md` § 6 forbids
   it), which is what makes it a decision rather than an opinion.
   `bug:confirmed` alone selects nothing: a confirmed defect nobody has
   accepted is a backlog item, not an instruction.

   **`status:accepted` also means the analysis is done.** The maintainer
   said so on 2026-09-25: « si le label accepted est dessus c'est que j'ai
   déjà fait l'analyse et que l'agent peut l'implémenter directement ». So
   an accepted ticket is implemented as it stands — a feature as readily as
   a defect — and does not go back for a design, a roadmap or a
   confirmation first.

   **An accepted ticket that is not clear enough to implement is skipped.**
   Same instruction: « si pas clair alors ignore le ticket et continue ».
   Leave it untouched, take the next one, and name it in what you report at
   the end. A ticket left for the maintainer costs them a sentence; a
   ticket taken and guessed wrong costs a pull request, a review round and
   a revert.

2. **Claim the first one by creating the branch `claude/issue-<n>` off
   `main` through the GitHub API**, not with a local `git push`. The call
   fails with **« Reference already exists »** — verified against the tool
   rather than assumed, and an HTTP 422 underneath — when another agent
   already holds that issue, and that refusal is the whole mechanism. It has to be
   a ref: two agents can apply the same label or assignee in the same
   second and both believe they won, and a push can succeed against a
   branch another agent created a moment ago and has not committed to yet.

   On 422, move to the next issue and say nothing **as you pass** — a claim
   you lost is not an event, and a running commentary on normal operation is
   not a report. Step 8 is where they are named, once, at the end. The claim
   itself costs nothing, because the branch is the first thing the work
   needed anyway.

3. **Put `status:in-progress` on the issue if that label exists**, so the
   issue list says what is being worked on. It is a signal for whoever is
   reading, never a lock — step 2 is the lock, and the work proceeds
   identically without the label. **If it does not exist, do not create it,
   and do not let the API create it for you** — adding an unknown label to an
   issue mints it with an arbitrary colour and no description, which is the
   hand-made GitHub configuration `scripts/sync-issue-labels.sh` exists to
   replace (docs/quality-pipeline.md § Labels). Read the
   label first; if it is missing, say so in your report and carry on without
   it. The script owns it, and it needs `gh`, which a remote session has not
   got.
   Take it off when the pull request merges, or when you give the ticket up.

4. **Fix it**, under the rules in this file: a test alongside the fix,
   `vendor/bin/phpstan analyse` before committing PHP, `npm run typecheck`
   before committing `public/assets/js/`, French interface and English code.
   Reproduce the CI job rather than its neighbour — `npm run test:coverage`
   is what `javascript-tests` runs, where `npm test` passes over failures it
   would catch.

   **A choice the ticket genuinely leaves open is asked in the
   conversation**, and you take the NEXT ticket while you wait rather than
   idling on this one. Keep the claim and the label: the ticket is still
   yours, it is waiting for an answer. This is the one question the
   instruction allows, and it is a question about the work, never a request
   for permission to do it.

5. **Open one pull request and name the issue in its body with
   `Corrige #158`** — that word, when the pull request is opened rather than
   afterwards. `Corrige` is deliberately **not** one of GitHub's closing
   keywords (`Closes`, `Fixes`, `Resolves` and their inflections): a keyword
   makes GitHub close the issue itself, server-side, at the instant of the
   merge, which is seconds *before* `issue-fixed-comment.yml` can say
   anything — so the reporter's first notification is a bare closure.
   Leaving the closing to that workflow is what buys the
   sentence-then-closure order. The cost is the issue's *Development*
   sidebar link, which only a closing keyword creates; `Corrige #158` still
   cross-references the pull request on the issue's timeline, and the
   workflow's comment names the pull request and the merge commit outright.
   **Do not "fix" a body by putting a closing keyword back** — that is the
   bug, not the convention.

6. **Bring `main` in if it moved into your files, then arm auto-merge.** If
   `main` has moved into files your branch touches, merge `main` in, re-run
   the checks locally — `vendor/bin/phpstan analyse` above all, which catches
   a semantic conflict that compiles on each side and not together — push,
   and wait for green. Where the files are disjoint, nothing is needed.

   Then **arm auto-merge**: § Merging a pull request has the command and the
   reason, and says why `merge_pull_request` is not a fallback there. The
   instruction to fix the backlog IS the authorization that section requires,
   for every ticket in the set and not for the first one, and everything it
   requires *before* arming still holds without exception.

   **There is no merge lock, and « fusionne les PR une par une » cannot be
   obeyed as worded.** The maintainer asked for it twice — « Travaille en
   parallèle, mais fusionne les PR une par une, jamais en même temps » — and
   it was written when one agent cut the work into blocks and merged each of
   them itself. An agent here does not merge: it **arms**, and GitHub merges
   once the ruleset on `main` is satisfied, at a moment no agent chooses. Two
   agents that armed seconds apart cannot serialise what neither of them
   performs. A git ref cannot bridge that: an earlier version of this section
   tried, and every attempt produced a new hole instead of a mutex — a
   creation date refs do not have, a threshold the work itself exceeded, an
   unconditional delete that destroyed a peer's fresh lock.

   **What that rule protects against is answered one step later, and this
   repository decided so deliberately.** `docs/quality-pipeline.md` § Branch
   ruleset keeps « require branches to be up to date » **off** for a measured
   reason — with it on, every push to `main` invalidates every open pull
   request, and on 2026-09-05 that cost #152 four consecutive CI cycles, each
   green and stale again before the merge call. It then names exactly what
   pays for it: two pull requests each green alone whose combination is not,
   caught by `ci.yml` on `main` **after** they land, with **the maintainer**
   answering the red-`main` notification and the fix going forward rather
   than by revert. The window is accepted, owned, and small by construction.

   Your share of it is the first paragraph, and it is the half a branch can
   actually see: never arm against a `main` that has moved into **your**
   files.

7. **Verify the comment and the closure, then take `status:in-progress`
   off** — this step and the next apply to the pull request that carries
   `Corrige #<n>`, which for a ticket delivered in several is the **last**
   one. Between the others, go back to step 4 and **keep the claim and the
   label**: the ticket is still yours and still open. Following this step
   after a sub-pull-request would strip the label from a ticket still in
   flight and send you back to step 1, where your own surviving branch
   answers « Reference already exists » and you would walk away from your own
   half-delivered work, reporting it as one somebody else held. `issue-fixed-comment.yml` does both on merge: one comment naming
   the pull request, the commit and the branch, and *then* the closure as
   `completed`. An issue it could not comment on is left open on purpose and
   the run goes red, so finish by hand any it left open — comment first,
   then `state_reason: completed`. An accepted issue whose fix is merged and
   which is still open is the backlog lying about itself; one closed with
   nothing written on it is the backlog being rude.

   **And delete `claude/issue-<n>`.** GitHub deletes the head branch on
   merge on its own since 2026-09-26, so for a pull request that merged this
   is usually a no-op — deleting a branch that is already gone costs
   nothing and needs no special case.

   It stays because that setting only ever covers a MERGE, and a claim can
   end three other ways: a pull request closed without merging, a ticket
   given up at step 4, and a claim taken before any pull request existed at
   all. Nothing deletes the branch in those, and they are exactly the cases
   the rules below already tell you to handle by hand. What a surviving ref
   costs is unchanged either way: the claim outlives the work it stood for,
   and a ticket reopened later answers « Reference already exists » to every
   agent for ever, reported at step 8 as held by somebody who finished
   months ago.

8. **Back to step 1.** When no accepted issue is left that you can take,
   **stop and report**: what you delivered, which tickets you skipped as
   unclear, **which one you are still waiting on an answer for** — it keeps
   its claim and its label, so no other agent can take it and only this
   report makes it visible — and which ones another agent held — that last list is where a
   branch nobody is working on any more becomes visible, and it is the only
   place any of this is mentioned. Do not idle waiting for the label to
   appear on something new.

**A ticket bigger than one reviewable pull request is delivered in
several.** No pull request carries more than about **50 changed files**; a
ticket needing more is cut by sub-theme into successive pull requests off
`main`, each merged before the next begins. `Corrige #<n>` goes on the
**last** one only, so the issue stays open — correctly — until all of it
has shipped.

The number is not a style preference. On #257 — 40 issues, 188 files,
7 000 lines — `Claude review` was cancelled at 20m20s and again at 20m21s
with the reviewer still working, and because that check is REQUIRED on
`main` the pull request was simply unmergeable. The same size produced that
day's other two defects: a silent semantic conflict with `main` (both sides
had fixed issue #226, differently, and `git merge` reported nothing — 16 000
tests green on the branch, twelve failing after the merge, and only
`phpstan` on the merge result saw it), and a debounce that became a
`ReferenceError` only once a sibling fix made it read `document.cookie`. The
reviewer's ceiling is 60 minutes now, which buys room and does not buy a
reader. The measured point of comparison is #217: 25 files, reviewed end to
end in 10 min 47 s.

**Never take a ticket somebody else has claimed, even when the claim looks
abandoned.** A branch `claude/issue-<n>` with no commit and no pull request
is what a claim looks like for as long as step 4 lasts — reading the issue,
writing the fix — and longer still when the ticket is parked on a question.
From outside there is nothing that distinguishes it from a claim whose agent
died, which is the same point step 2 makes to explain why a push is not a
lock. So do not delete another agent's branch: skip that ticket, and name it
in **step 8's report** rather than as you pass it — the same rule step 2
gives, because it is the same observation. A human reading that report clears
a genuinely dead branch in seconds. A ticket that waits costs a sentence; a
ticket taken from an agent still working on it costs two pull requests that
fix the same thing differently.


**Do not wait for the maintainer at any other point.** Not to start, not to
merge, not to close, not between tickets. Report what you did afterwards; do
not ask for permission during. A red pipeline is work, never a question to
bring back. This overrides nothing in § Merging a pull request about what
must be TRUE before you merge — it settles only who decides, and that was
settled when the instruction was given.

**A ticket you take and then abandon is not silently dropped**: say on that
issue what stopped you, take `status:in-progress` off, delete the branch,
and go to the next one. Scaling the work down is the maintainer's call and
they can only make it if they know.

**The release gates are not part of this.** Dependencies, SonarQube Cloud,
CodeQL and Dependabot have their own section below and their own
instruction; they are never smuggled into a pull request whose diff a
reviewer is holding for a ticket.

## Leaving the release gates green

Asked for on 2026-09-19: « souviens-toi en plus de faire tout ce qui est
prévu de t'assurer que toutes les dépendances soient à jour, que toutes les
issues SonarCloud soient fixées, et en général que toutes les gates
nécessaires pour faire une release soient vertes. Sans pour cela lancer une
release. »

This is its own instruction, given in the maintainer's own words when they
want it. **It is not covered by « fixe le backlog »** — it was, and it made
that instruction two jobs at once, with dependency bumps landing in pull
requests opened for a ticket.

The goal is a repository where `scripts/release.sh` would pass every one of
its gates on the first try, because the alternative is what this exists to
stop: a release that aborts on a finding nobody had looked at since the last
one, at the moment somebody wanted to ship.

**Dependency work and SonarQube Cloud work are each their own pull
request.**

- **Dependencies up to date.** Every outdated direct Composer package
  (`composer outdated --direct`) and every vendored front-end library
  against its latest upstream release. That pair is exactly what
  § Releases' dependency freshness gate checks, so run that gate's own
  commands rather than something that resembles them. `composer audit` and
  `npm audit` come back clean too.
- **SonarQube Cloud at zero.** Every unresolved finding on `main` that
  survives the one exemption in § SonarQube Cloud release gate, every
  Security Hotspot still `TO_REVIEW`, and a Quality Gate that is `OK`. Fix
  them. Resolving one in SonarQube Cloud with a written justification is the
  second-best answer and carries the same standard as dismissing a
  Dependabot alert.
- **The rest of the gates, read rather than assumed**: open CodeQL alerts,
  open Dependabot alerts, `All checks` green on `main`.

**Never run `scripts/release.sh` for this.** The instruction is to leave the
gates green, not to ship — releasing is its own instruction, with its own
notes file and its own hour of runner time. An agent that releases because
the gates happened to go green has done something nobody asked for, to a
production site.

A gate you cannot make green is what this sends back: a major version bump
that takes the suite red, a finding whose fix is a design decision. Finish
the others, then say which one and why.

## The working tree, and three ways it reports green while lying

All three were learnt here, all three are silent, and none of them
announces itself as a setup problem.

**Do not run the local checks in a `git worktree`.** `vendor/` there is a
symlink, so Composer's autoloader resolves `$baseDir` to the main checkout
and loads `Core\` and `Tests\` from the OTHER working tree: the branch you
believe you are testing is never read. A mutation proof taken that way is
worth nothing and looks green — this was found by
`Tests\Core\View\TwigCacheVersioningTest` failing with the main checkout's
path in it, not by suspecting the setup. Use one checkout and switch
branches in it; keep worktrees for pure git plumbing, where no autoloader
runs.

**Never merge into a local branch that merely shares a name with the remote
one.** `git checkout claude/some-branch` picks a LOCAL ref of that name when
one exists, silently, however far behind it is. Merging `main` into one
produces a plausible merge commit whose first parent is the branch as it was
hours ago, missing every push since; pushing it would revert the pull
request, review fixes included. Only the non-fast-forward rejection stops
that, and a `--force` would not be stopped at all. So build from the remote
ref by name (`git checkout -B work origin/claude/some-branch`, then
`git merge origin/main`), and afterwards assert that the head you meant to
build on is an ancestor: `git merge-base --is-ancestor <pushed head> HEAD`.
Delete stale local branches that shadow a remote — including `main` itself,
which goes stale the same way and is the one nobody thinks to check.

**One full suite at a time, and do not touch the working tree while it
runs.** A second `vendor/bin/phpunit` shares the one `test_db` this
container has, so the two runs write over each other's fixtures and either
verdict can be wrong in either direction. And a run whose tree changes under
it — a branch switched, a file edited — is reading something that no longer
exists: three failures were reported that way in one session, and one green
that had no right to be. Both are silent. If a suite is running and
something else needs doing, the something else waits, or the suite is killed
and started again afterwards.

## Merging a pull request

**The maintainer's instruction is the only authorization to merge**, and
nothing substitutes for it — not a green pipeline, not this file, not your
reading of what they would probably want. Without it, green and
merge-ready is where your work stops and you say so.

« Fixe le backlog » **is** that instruction, standing, for **every** pull
request that fixes accepted issues — the work is one pull request per
accepted ticket, and the authorization covers every one of them, not the
first. See § "Fix the backlog" above, which also says not to come back
for a second confirmation of it. Everything below still applies to each of
those pull requests unchanged.

**A change to these instructions themselves carries the same standing
authorization**, granted on 2026-09-08: « une fois que tu as fait le
changement dans les instructions, tu peux le fusionner et le pousser sur
main immédiatement, pas besoin d'attendre ma permission ». It covers
`AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md` and `.claude/skills/**` — the
files that tell the next agent what to do — when the maintainer asked for
the change. The reason is the same one that makes those files exist: a
rule agreed in a conversation and left unmerged is a rule the next session
never sees, so the gap between "we decided this" and "it is on `main`" is
the whole risk. Asking again to close a gap the maintainer just asked you
to close is how a decided rule stays undecided.

It authorises the *merge*, nothing else. It is not permission to rewrite
these files on your own initiative, and a rule you thought of yourself is
a proposal to the maintainer, never a commit to `main`. Every requirement
below still holds on such a pull request without exception — green checks,
answered threads, an honest checklist — and so does the test discipline:
a rule worth writing into `AGENTS.md` is worth an assertion in
`tests/Architecture/` pinning it against the edit that would undo it,
in the manner of `AutoMergeRuleIsWrittenDownTest` and
`BacklogIsOneTicketAtATimeTest`.

With it, arm **auto-merge** rather than watching the pull request:

```shell
gh pr merge <number> --squash --auto
```

Where `gh` is not installed — a Claude Code session running on the web has
no GitHub CLI — use the GitHub MCP server's `enable_pr_auto_merge` with
`mergeMethod: SQUASH`, which is what armed #168. Not every build of that
server exposes it; when it is missing, say so and hand the maintainer the
`gh` line above.

**`merge_pull_request` is not the fallback.** It merges immediately, which
is a different act from arming: it lands the pull request whether or not
the checks have finished. Reaching for it because the auto-merge tool was
not there would turn "arm this and let the ruleset decide" into "merge it
now", unreviewed and untested, on an instruction that said neither.

GitHub then merges the moment the ruleset on `main` is satisfied, and the
instruction is carried out without the maintainer being called back. Polling
a pull request for an hour is not diligence — on 2026-09-05 that cost four
CI cycles and three interruptions on #152, and auto-merge is the answer
this repository chose (`docs/quality-pipeline.md` § Auto-merge).

Arming it is *merging*, so everything that must be true before a merge must
be true before you arm it:

- **Every check green on the current head.** Not "the required one" —
  every one. The ruleset requires `Claude review` and `All checks`, the
  `ci.yml` job that needs every `Checks / …` job and goes red when any of
  them fails or is cancelled. So since 2026-09-06 GitHub no longer lands a
  pull request whose `database-mariadb`, `Authorization matrix` or
  `Dynamic scan (passive)` is red — that hole was issue #170, and
  `docs/quality-pipeline.md` § Branch ruleset on `main` records the
  required list. Reading `All checks` is therefore the fastest way to
  confirm the whole set, but not a substitute for confirming it: it
  reports only once every job it needs has finished, and a pipeline still
  running has reached no verdict. Arming on one is arming on nothing.
- **Every review thread replied to and resolved on purpose**, rather than
  resolved to clear the way.
- **The PR template's checklist honestly filled**, and no finding of your
  own left unfiled.

What you must never do is arm it and walk away from a pull request you have
not finished — auto-merge does not wait for you to come back. If a check is
still running, either wait for it or say you are arming on an incomplete
pipeline and why.

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
2. Only after all of them are resolved, run the release script. Its six gates — **deployment** (www.scoutmagic.be is on the previous release and responds normally — via the public `GET /api/version`, `Core\Http\Controller\VersionController`), **continuous integration** (see below), **security** (`composer audit` + `npm audit` — always mandatory and blocking, queried directly against public advisory databases, no GitHub permission of any kind involved — plus the CodeQL/Dependabot query described above; see § A gate that cannot be verified from the current environment below for what happens when that query alone hits a permission gap), **dependency freshness** (`composer outdated --direct` + every vendored front-end library — Bootstrap, Bootstrap Icons, Chart.js, Leaflet, html5-qrcode — each vs. its latest upstream GitHub release), **deprecated browser API** (`scripts/check-deprecated-api.php` — has any engine removed `document.execCommand`, which the rich-text editors are built on; see below), and **SonarQube Cloud** (`scripts/check-sonar-release.sh` — see below) — run in that order, in seconds, and are the final checks rather than the fix: the first one that finds a problem aborts the script before any commit or tag exists. Do not bypass or disable any gate to make a release "pass" — `--skip-deployment-check`, `--skip-ci-gate`, `--skip-security-gate`, `--skip-dependency-check`, `--skip-deprecated-api-gate` and `--skip-sonar-gate` (see below) exist only for genuine emergencies, not to route around a real finding, a real test failure, a real outdated dependency, or a real production problem.

### The tests are not among those gates, and that is deliberate

PHPStan, the complete PHPUnit suite on **both** database engines, `npm run typecheck`, Vitest, the full-tier browser suite, the authorization matrix and the passive dynamic scan all run on a GitHub runner: on the pull request, on the push to `main` (`.github/workflows/ci.yml` → `checks.yml`), and once more on the tag (`release.yml`), where each tool's own output is signed into the evidence pack attached to the Release. The script used to run them locally too — twenty-five of a thirty-minute release — and that copy was the least trustworthy of the three: one engine where CI uses two, four of the reproductions on the wrong engine altogether (`.claude/skills/steward/SKILL.md` § Four of these rows run on the wrong engine), and a verdict nobody reading the Release could check.

The **continuous integration gate** keeps the one property that was worth keeping — refusing *before* the tag exists — by reading the `All checks` status GitHub already reported for the commit being released, in about a second. It refuses on a red or cancelled run, on no run at all ("no verdict" is not a pass), on a run still going after a bounded wait, and on a dirty working tree, since the artifact is built from that tree while the verdict describes `HEAD`.

So a red test still blocks a release three times over: on the pull request, at this gate, and on the tag — where a red gate creates no draft Release at all and nothing is ever published. What you must never do is reach for `--skip-ci-gate` to get past one.

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

### Deprecated browser API release gate

`scripts/check-deprecated-api.php` (invoked automatically by `release.sh` unless `--skip-deprecated-api-gate` is passed) answers one question: has any browser engine **removed** `document.execCommand`? Six files under `public/assets/js/` are built on it — the shared rich-text toolbar, the mass-mail token and chip insertions, the news form builder's own toolbar, and two clipboard fallbacks — and it is deprecated with no standard replacement (issue #379). It reads MDN's published `browser-compat-data`, which is the machine-readable form of what https://caniuse.com/document-execcommand renders.

**It is the one gate here that does not fail closed, and that is deliberate.** A network error or a moved upstream schema is reported as « non vérifié automatiquement — à vérifier à la main », in the release notes, with the page to read; it does not refuse the release. Every other gate answers a question about *this* release; this one answers a question about a removal that has happened nowhere yet, and blocking a release because `raw.githubusercontent.com` returned a 502 would buy nothing. A removal actually **found** in the data does block.

What makes that safe is the second layer, which is blocking: `tests/e2e/specs/rich-text-commands.spec.js` exercises all twelve commands in a real Chromium and fails if any stops producing markup, so a removal that has actually shipped is caught by the `ci` gate above. The two are complementary and neither replaces the other — the spec is exact but knows one engine and only after the fact; the gate is an early warning and the only thing here that can speak about Firefox or Safari at all. `tests/Architecture/DeprecatedBrowserApiIsWatchedTest.php` is what keeps the spec's list of commands and the count of call sites above honest: it reads them out of the product and fails until the alarm covers each one.

Test the gate's own logic with `vendor/bin/phpunit tests/Core/System/DeprecatedApiCheckTest.php` — no network: the decision takes its fetcher as an argument, and the fetching itself is exercised against a local file — rather than against the live document. The shape of that document is the whole difficulty — a per-engine entry is an object, *or* a newest-first list of them, *or* the string `"mirror"`, and a `version_removed` anywhere but the first entry is history rather than a removal. Reading it indiscriminately reports Firefox as having dropped the API in version 69, on data that says the opposite; there is a regression test for exactly that.

### Release notes — mandatory when releasing from Claude

`scripts/release.sh` accepts `--notes-file <path>`. Every time a release is started from Claude, you **must** write a release-notes file and pass it via `--notes-file` — never rely on the auto-generated commit-list notes (the default when the flag is omitted, intended for manual/human-triggered releases only). Write the file to a temp path (e.g. `mktemp`) since the notes are multi-line Markdown; do not attempt to pass this inline.

The notes file must be a human-readable Markdown document, in French, covering (omit a section if genuinely empty, but check first):

- A short summary of the release in plain language.
- The complete list of new features.
- The complete list of bug fixes.
- The complete list of security fixes.
- Any updated open-source dependency (name, old → new version), including transitive bumps pulled in by `composer update`. The *delta* only: the complete inventory of what shipped — every package at the version the lock files record, every vendored front-end library, each with its licence and why it may be combined with AGPL-3.0 — is appended by the script itself from `scripts/dependency-inventory.php`, and must never be written by hand into the notes file. Adding a dependency under a licence that script has no verdict for fails `tests/Core/System/DependencyInventoryTest`; write the verdict there, it is a decision that gets a name against it.
- Any backward-compatibility issue or warning (schema changes requiring manual action, config changes, deprecated behavior, etc.). State explicitly if there are none.

Derive this from the actual diff/commit list since the last tag — do not guess or copy the commit subjects verbatim; summarize what changed and why it matters to someone deciding whether to update.

The script appends two more things after the note and the "Vérifications effectuées" block, and neither is yours to write: the Release workflow's description of the **evidence pack** (`evidence-vX.Y.Z.tar.gz` — every gate re-run on a GitHub runner with each tool's native output kept, SonarCloud's full analysis of the released commit, the open CodeQL and Dependabot alerts, a manifest, checksums and a Sigstore signature; `docs/quality-pipeline.md` § The Release workflow and the evidence pack) and the dependency inventory above. Nothing is published until that workflow is green: `.github/workflows/release.yml` is what creates that draft; `scripts/release.sh` waits for the workflow, refuses on any other conclusion, attaches the installable zip and `bootstrap.php` to the draft **the workflow** created, and only then publishes — so a release takes about an hour, and a red runner-side gate after the tag is pushed is handled the way the script's error message says (fix on `main`, delete the tag, run it again), never by publishing the draft by hand around it.

### Bypass flags — emergency use only

- `--skip-deployment-check`: skips checking that www.scoutmagic.be already has the previous release installed and responds normally. Only use this if the user explicitly asks for an urgent release despite production not being confirmed healthy/up to date; the script prints a warning, and you must tell the user the same and follow up to verify production right after.
- `--skip-security-gate`: skips composer audit, npm audit, AND the CodeQL/Dependabot check — all of it. Only use this if the user explicitly asks for an urgent release despite a real, known finding in one of these; the script prints a warning, and you must tell the user the same and follow up to resolve them right after. This is **not** what a CodeQL/Dependabot permission gap needs — see the next section, `check_security_gate` already turns that specific case into a non-blocking warning on its own, while composer audit/npm audit still run and still block for real.
- `--skip-ci-gate`: skips the check that `All checks` is green on the commit being released — which is to say, every test and scan this project has. **This is the widest bypass in the list**: nothing else in the script looks at the code at all, so a release cut with it has been tested by nobody at the moment it is tagged. Only use this if the user explicitly asks, knowing that; the script prints a warning, and you must tell the user the same. Note what happens next either way: the tag's own Release workflow runs every gate again and creates no draft if one is red, so the release will simply refuse to publish rather than ship untested — which is why reaching for this flag to get past a red test wastes an hour and fixes nothing.
- `--skip-dependency-check`: skips the dependency freshness gate (outdated direct Composer packages, outdated vendored front-end libraries — Bootstrap, Bootstrap Icons, Chart.js, Leaflet, html5-qrcode). Only use this if the user explicitly asks for an urgent release despite outdated dependencies; the script prints a warning, and you must tell the user the same and follow up to update them right after.
- `--skip-deprecated-api-gate`: skips the deprecated browser API check. Rarely the right flag: that gate already reports « non vérifié » instead of blocking when it cannot read the compatibility data, so the only thing this flag suppresses is a removal it actually found — which is a broken editor, not an inconvenience. Use it only if the user asks for an urgent release knowing that, and follow up.
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
