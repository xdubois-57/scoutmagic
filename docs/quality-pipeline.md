# The quality pipeline

Everything that stands between a change and production: what runs, where,
what it actually proves, and the GitHub configuration none of it works
without.

This document **points**; it does not copy. `AGENTS.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `CONTRIBUTING.md` and `design.md` remain the source of truth
for the rules themselves — a rule restated here would drift from its
original, and the original wins. What follows is the map: which layer
catches what, and what each one cannot see.

## The layers, and what each is for

| Layer | Runs | Catches | Blind to |
|---|---|---|---|
| **Static analysis** | Locally, before each commit; CI | Signature drift, unresolved identifiers, wrong argument counts | Anything that only shows at runtime |
| **Unit & integration tests** | Locally; CI (two engines) | Behaviour, RBAC boundaries, architecture invariants | The application failing to boot at all |
| **End-to-end** | CI; release gate | The app not booting, a broken composition root | Everything a browser does not exercise |
| **Dynamic scan** | CI; release gate | Over-permissive routes, what the running app actually answers | Logic the scan does not reach |
| **CodeQL** | CI, GitHub-managed | Taint flows into DOM sinks | Non-JavaScript defects |
| **SonarQube Cloud** | CI; release gate | Quality, duplication, security hotspots | Intent |
| **AI triage** | Every issue opened or reopened | Whether a report is a real defect, the one fact a blocked report is missing, and the workaround when the behaviour is correct | Anything only a running installation shows — it reads the code but reproduces nothing, changes nothing, and gates nothing |
| **AI review** | Pull requests it is eligible for — not drafts, and `Claude review` not on forks | Cross-file reasoning, stale documentation, intent mismatches | Nothing reliably — it is a reader, not a gate |
| **Release gates** | `scripts/release.sh` | Deployment state, security advisories, dependency freshness, Sonar, PHPStan + the full PHPUnit suite, `e2e:full`, both DAST profiles | What the AI reviewers read — intent, cross-file reasoning, stale docs. It reads CodeQL's open alerts but runs no scan of its own |

No single layer is trusted alone, and the ones that overlap do so on
purpose: the `test` job and `database-mariadb` run the same suite against
different database engines, and that difference is the point.

## Tests

### PHP — PHPUnit

Six suites, registered in `phpunit.xml`: `Core`, `Modules`, `Bootstrap`,
`Security`, `Integration`, `Architecture`.

**A new test directory must be added to `phpunit.xml` in the same change.**
`vendor/bin/phpunit` runs the suites that file lists and nothing else, so an
unlisted directory is one nobody runs — which is exactly what happened to
`tests/Security/` and `tests/Integration/` for months.

`tests/Architecture` is not a formality. `ModuleBoundariesTest` fails the
build on the first reference from outside a module to anything but its
`Api\` namespace, and `ModuleSchemaBoundariesTest` on a foreign key crossing
module tables. Both are absolute (`ARCHITECTURE.md` §7.5).

### The two database engines

Production runs **MariaDB 10.11**. CI runs the suite twice:

- `test` — **MySQL 8**, with coverage, feeding SonarQube
- `database-mariadb` — **MariaDB 10.11**, the whole suite, no coverage

They disagree on how `INFORMATION_SCHEMA` reports column defaults, display
widths and `CURRENT_TIMESTAMP`, which is why `SchemaIntrospector` reads the
server version. `npm run test:engines` runs both locally.

The asymmetry that matters: **code correct on MySQL and wrong on MariaDB
reaches production**, because the `test` job is green. That is why
`database-mariadb` runs the *whole* suite rather than `--group=database` —
the day someone adds a database-backed test without that group, the narrow
version would miss it silently.

### The skip that looks like a pass

Database-backed tests call `markTestSkipped` when no server answers. They do
not fail. So `vendor/bin/phpunit` on a machine with no database returns
green **having skipped exactly the tests that needed one**, and nothing in
the output says the run proved less than it looks.

Inside a Claude Code remote session the `SessionStart` hook has started
MariaDB and exported `TEST_DB_*`; in a local checkout that hook exits at its
first line. Read the skipped count before believing a green run.

### JavaScript — Vitest

`tests/js/`, one `<name>.test.js` per script under `public/assets/js/`.
Required for deterministic browser logic reasonably decoupled from its DOM;
not for a thin script that only wires two elements together. Specs import
the real production file — never a copy of its logic.

### End-to-end — Playwright

`tests/e2e/`, one command: `npm run e2e`. It provisions a throwaway install
and database, serves it through the real `public/index.php`, and drives it
with headless Chromium. It exists to catch what PHPUnit structurally cannot:
**the application failing to boot at all.**

Two tiers, `full` a strict superset of `confidence`:

- `npm run e2e` — the confidence tier, every scenario not tagged `@full`. CI
  runs this on every push. Budget: measured at 481 s; treat 12 minutes as
  the ceiling and re-examine the tier's contents rather than raise it.
- `npm run e2e:full` — everything, including the per-module boot matrix.
  `scripts/release.sh` runs this.

A new scenario lands in `confidence` by default. Relegate one to `full` only
when it is costly *by nature*, never because it is slow through inefficiency
and never to get a flaky test out of CI's way.

**CI sets `E2E_COVERAGE=1`, and that changes behaviour, not just reporting**
— coverage slows every request enough to alter timing. The Calendrier click
in `tests/e2e/specs/rental-management.spec.js` documents a failure that
surfaced only under it. Reproduce a red `e2e-tests` with the flag on.

### Dynamic scan — OWASP ZAP

`scripts/dast.sh`, profiles `passive`, `standard`, `deep`, `audit`.
`tests/dast/` holds the ZAP plans — configuration, not tests: nothing there
runs under PHPUnit and it needs no `<testsuite>` entry.

Two profiles run in CI:

- `--profile=standard` → the **authorization matrix**: every route replayed
  as every role, each answer compared to the `role_min` it declares. An
  over-permissive route fails the commit that introduced it.
- `--profile=passive` → ZAP's passive rules over the browser suite.

Both need Docker and a pulled `ghcr.io/zaproxy/zaproxy:stable`;
`scripts/dast.sh` refuses to download a missing image and exits before
scanning, so a missing prerequisite looks nothing like the failure you came
to reproduce.

### Static analysis

`vendor/bin/phpstan analyse` before **every** commit touching PHP, and
`npm run typecheck` before every commit touching `public/assets/js/`.
Passing tests is a different guarantee: PHPStan's scope covers `core/`,
`modules/` and both `public/` entry points precisely because a composition
root is where a signature change goes unnoticed until every request 500s.

Both carry a baseline — `phpstan-baseline.neon`,
`js-typecheck-baseline.json` — holding accepted pre-existing debt. A clean
run means no *new* finding. **Never add a finding of your own to a
baseline**; regenerating one is for deliberately accepting existing debt,
never for hiding what you just introduced.

## Continuous integration

`.github/workflows/ci.yml`, on every push to `main` and every pull request
against it.

| Job | What it runs | Engine |
|---|---|---|
| `test` | `phpstan analyse --memory-limit=512M`, then `phpunit --coverage-clover --log-junit` | MySQL 8 |
| `database-mariadb` | the whole PHPUnit suite | MariaDB 10.11 |
| `javascript-tests` | `npm ci`, `npm run typecheck`, `npm run test:coverage` | — |
| `End-to-end (browser)` | `npm run e2e` with `E2E_COVERAGE=1` | MySQL 8 |
| `Authorization matrix` | `./scripts/dast.sh --profile=standard` | MySQL 8 |
| `Dynamic scan (passive)` | `./scripts/dast.sh --profile=passive` | MySQL 8 |
| `security` | `composer install`, then `composer audit` | — |
| `SonarQube Cloud` | scanner + Quality Gate, consuming the coverage artifacts | — |
| `Analyze (…)` | CodeQL, GitHub-managed default setup | — |

`.github/workflows/dev-build.yml` is separate: every push to `main` builds
the installable artifact and attaches it to a rolling **prerelease** tagged
`dev-latest`. The prerelease flag is an invariant — the stable channel reads
`releases/latest`, which excludes prereleases, and that single fact is what
keeps the two update channels apart.

`.github/workflows/issue-triage.yml` is not CI and gates nothing: it runs
on `issues: [opened, reopened]` and answers the reporter. It is the one
workflow here that is triggered by a member of the public, which is why it
holds `issues: write` and `id-token: write` and **nothing else** — no
`contents`, no checkout, no path to `main` at all. Claude reads the code
through the GitHub MCP tools; a checkout is only ever needed in order to
write. Its judgement lives in `.claude/skills/triage/SKILL.md`, reviewed
like code, and the workflow fetches that file from `main` rather than from
a working copy it does not have.

`.github/workflows/claude-review.yml` is the AI reviewer; see below. It
carries two jobs: `Claude review`, which reads the diff, and `Claude review
status`, which posts the comment saying what that check's green means. They
are separate jobs so that the write permission the second one needs never
reaches the step that runs Claude.

The steward skill (`.claude/skills/steward/SKILL.md`) maps each job to the
local command that reproduces it, and records which ones cannot be
reproduced locally at all. Changing a job's name, commands, engine or
environment makes that table wrong — update it in the same change.

## Code review

Three readers, none of which blocks a merge on its own judgement.

**CodeRabbit** — configured in `.coderabbit.yaml`, free on this public
repository. Reviews **once, when a pull request opens**;
`auto_incremental_review` is off, because a pull request that answers its
own review takes several corrective pushes and re-reviewing each one buys
little while spending an hourly quota (10 included reviews per hour, and
this repository has exhausted it in a busy morning). Ask for another pass
with `@coderabbitai review`. `request_changes_workflow` is off so it cannot
submit an approval that would satisfy a required review. Its
`path_instructions` point it at the rules a diff can break here without any
check noticing, and `knowledge_base.code_guidelines.filePatterns` is what
lets it actually open `ARCHITECTURE.md`, `SECURITY.md`, `design.md` and
`CONTRIBUTING.md` — the built-in defaults cover `AGENTS.md` and `CLAUDE.md`
and not those.

**Claude review** — `.github/workflows/claude-review.yml`, running
`anthropics/claude-code-action` against the maintainer's Claude subscription
via `CLAUDE_CODE_OAUTH_TOKEN`. Runs on open, ready-for-review, reopen and
**every push**; the `claude-review` label asks for a pass without one. Read
the header of that file before changing it: it documents a gap that matters
and is not obvious.

It reports findings as inline comments and says nothing when it finds
nothing, so a second job, **`Claude review status`**, posts one comment per
pull request — rewritten in place on every run — stating whether the review
ran, was skipped, or failed. Without it, a green check and no comment means
either "read it, found nothing" or "declined to read it", and only the run
log tells you which.

**SonarQube Cloud** — posts a Quality Gate on each pull request. It is
skipped entirely on pull requests from forks, because `SONAR_TOKEN` is not
exposed to those runs. Absence of the comment there is not a failure.

## Releases

`scripts/release.sh [--minor|--major] [--notes-file <path>]`.

Both are optional: without a bump flag the patch component moves, and
without `--notes-file` the notes are the auto-generated commit list, which is
acceptable only for a manual release — see the end of this section.

**Fix first, release later.** Before running it, resolve every open GitHub
security item: CodeQL alerts, Dependabot alerts, and active SonarQube Cloud
findings. The gates below are the final check, not the fix.

Seven gates, all fail-closed, all run **before** any commit or tag:

| Gate | What it checks |
|---|---|
| **Deployment** | production is on the previous release and answers `GET /api/version` |
| **Security** | `composer audit`, `npm audit`, open CodeQL findings, open Dependabot alerts |
| **Dependency freshness** | `composer outdated --direct`, and every vendored front-end library against its upstream release |
| **SonarQube Cloud** | `scripts/check-sonar-release.sh` — see below |
| **Tests** | PHPStan + the complete PHPUnit suite (no group excluded), `npm run typecheck`, `npm run test:coverage` |
| **End-to-end** | `npm run e2e:full`, including the `@full` per-module boot matrix |
| **Dynamic scan** | `dast.sh --profile=standard` then `--profile=passive` |

**The four fast gates run first, one after another, and the release stops at
the first one that refuses** — nothing long is started behind a failed
precondition. Each takes seconds (deployment 2 s, Sonar 4 s, dependency
freshness and security ~5 s), so parallelising them would buy nothing and
cost the thing that matters: a Sonar gate refusing in four seconds used to
burn the full twenty-five minutes before saying so.

The three slow ones are then launched as concurrent subshells, but chained
`tests → end-to-end → dynamic scan`: all three migrate the same local MySQL
server, and overlapping them caused spurious migration timeouts. Skipping a
link collapses the chain onto the one before it. These are collected
together, so a run reports **every** slow gate that failed, not just the
first — a `Gate results` block lists each one with the last 60 lines of its
log, then the release exits.

Gates fail closed on a missing tool or service — an unreachable database, no
`node_modules/`, no Docker, no ZAP image — rather than silently doing less.
**The Security gate has one deliberate exception**: a
`Resource not accessible by integration` answer to the CodeQL or Dependabot
alert query is a permission gap rather than a finding, so it warns and the
release continues — every other error from those calls still aborts, and
`composer audit`/`npm audit` block regardless, being unaffected by any GitHub
permission. A release that printed that warning has **not** been checked
against those two sources; the warning names the Security-tab URL to read by
hand. `AGENTS.md` § Releases governs what to do about it.

**The SonarQube release rule, in one sentence:** 100% of findings must be
fixed, except those that are *all three at once* — software quality
`MAINTAINABILITY`, severity `LOW`, and tagged `convention`. An issue carries
a *list* of impacts and is exempt only when every one of them qualifies; an
issue with no impacts at all is not exempt.

**Bypass flags** (`--skip-security-gate`, `--skip-tests-gate`,
`--skip-e2e-gate`, `--skip-dast-gate`, `--skip-dependency-check`,
`--skip-deployment-check`, `--skip-sonar-gate`) exist for genuine
emergencies. Each prints a warning naming exactly what was not checked.
Using one to route around a real finding is how a release ships a known
defect.

After the gates pass, the script bumps `VERSION`, commits, tags `vX.Y.Z`,
builds the installable artifact through `scripts/build-artifact.sh`, and
publishes a GitHub release with that artifact and `bootstrap/bootstrap.php`.

**Release notes are mandatory when releasing from Claude**: write a French
Markdown file and pass `--notes-file`. The auto-generated commit list is for
manual releases only.

## The GitHub configuration this all depends on

Two kinds of thing sit here, and the difference matters. **`.github/CODEOWNERS`
and the workflows are in the repository** — a pull request touching them is
reviewed like any other change. **The rest is not**: secrets, installed Apps,
the branch ruleset, the labels, the required-check list and CodeQL's default
setup live only in the repository's settings, where no diff shows them and no
check reports them.

What both kinds share is the failure mode: **nothing warns you when one is
missing or wrong.** A CODEOWNERS entry naming a non-collaborator sits in the
repository, reviewed and merged, and still matches nothing. Verify after any
change to either.

### Repository secrets

*Settings → Secrets and variables → Actions*

| Secret | Used by | Without it |
|---|---|---|
| `SONAR_TOKEN` | the `sonarqube` CI job, `check-sonar-release.sh` | no Quality Gate on pull requests; the release gate fails closed |
| `CLAUDE_CODE_OAUTH_TOKEN` | `claude-review.yml`, `issue-triage.yml` | the review job fails at authentication, and no issue is ever triaged |

`CLAUDE_CODE_OAUTH_TOKEN` is generated with `claude setup-token` and spends
a Claude subscription rather than a metered API key. It is tied to the
person who generated it.

### GitHub Apps

- **CodeRabbit** — installed on this repository, which is what makes the
  free open-source plan apply. `.coderabbit.yaml` configures it; the app
  installation is what runs it.
- **Claude** — installed for `claude-code-action`'s default authentication.

Nothing in the repository installs either. A missing app means silence, not
an error.

### Branch ruleset on `main`

*Settings → Rules → Rulesets*

- **Require a pull request before merging.**
- **Require conversation resolution before merging.** Every review thread —
  including a bot's — must be resolved before the merge button unlocks.
  This is what makes an AI reviewer's finding a hard blocker rather than a
  suggestion, and it is why an agent resolving a thread silently is handing
  itself a merge.
- **Require status checks to pass.** A check only appears in GitHub's list
  after it has run at least once, so add each one after its first run, not
  before.
- **Require review from Code Owners** — *not enabled, and not currently
  enableable.* See below.

### Private vulnerability reporting

*Settings → Code security → Private vulnerability reporting*

**Enabled** — confirmed against
`GET /repos/xdubois-57/scoutmagic/private-vulnerability-reporting`, which
answers `{"enabled": true}` and is readable without a token on a public
repository. That is the cheapest way to check it, and worth doing rather
than assuming, because the failure is silent in both directions.

`SECURITY.md` and `.github/ISSUE_TEMPLATE/config.yml` both send a reporter to
the *Report a vulnerability* button on the Security tab. That button exists
only while this setting is on, and when it is off there is no error and no
check — the reporter simply does not find it, and the alternative on offer
is "contact the maintainer directly" with no address. Blank issues are
disabled, so the path that a stuck reporter would otherwise fall back on
(open a public issue about a vulnerability) is the one thing that must not
happen.

### Labels

`claude-review` — adding it to a pull request asks `claude-review.yml` for a
fresh pass without pushing a commit. The workflow's job guard matches this
name exactly.

`.github/workflows/issue-triage.yml` **writes** part of the taxonomy below:
it applies `triage:done` plus exactly one `bug:*` verdict and removes
`triage:pending`. It never applies or removes `status:accepted`, which
stays a marker for a human eye that no workflow reads.

**`bug:not-a-bug` is the one label that closes an issue** — with reason
`not planned`, never `completed`, since nothing was completed and the
release notes read that field. Every other outcome leaves the issue open.
The skill makes that verdict expensive on purpose: it may only be reached
when the workaround can be written for the reporter without jargon, and
when it cannot, the interface misled a competent user and the verdict
becomes `bug:confirmed` about the interface instead.

One gap, recorded rather than papered over: **a feature request has no
verdict.** `feature.yml` opens issues with `triage:pending` like `bug.yml`,
but the three verdicts are all about defects, and `bug:not-a-bug` is the
label that will later mean *closed as not planned* — the wrong end for a
request the maintainer may want to keep. Such an issue therefore gets
`triage:done` and no `bug:*` label at all. Closing that gap means a new
label, which means a decision about what it would be for; inventing one at
runtime is exactly what the script below exists to prevent.

The issue triage taxonomy — `triage:*`, `bug:*`, `status:accepted` — is the
one part of this section that *is* reproducible from the repository:
`scripts/sync-issue-labels.sh` is its single source, and running it creates
what is missing and repairs what somebody edited in the UI. It never
deletes, so `claude-review` and the older `bug` label survive it. Run it
after any change to that table, and read the summary — a run that reports
nothing to do is the normal one.

`.github/ISSUE_TEMPLATE/` holds the two issue forms (`bug.yml`,
`feature.yml`, both French) and `config.yml`, which turns blank issues off.
A form's `labels:` is what gives a new issue its starting state, and it
depends on the label existing — see the last section of this document for
what happens when it does not.

### CODEOWNERS

`.github/CODEOWNERS` must name an account that actually has write access.
**GitHub ignores an entry naming anyone else, in silence** — no error, no
warning on a pull request, no failing check. This file named a
non-collaborator for its entire existence, so every rule in it matched
nothing.

`Require review from Code Owners` cannot be enabled while this repository
has a single collaborator: GitHub forbids approving your own pull request,
so the sole code owner who is also the sole author could never satisfy it,
and every pull request would be permanently unmergeable. Adding that account
to the ruleset's bypass list would restore merging and protect against
nobody. **Enable it the day a second person gets write access** — the same
day the protections it provides start having something to protect against.

### CodeQL

GitHub-managed default setup (*Settings → Code security*), not a workflow in
this repository. It produces the `Analyze (…)` checks. Its findings live in
the Security tab, and a green check means the scan **ran**, not that it
found nothing — `AGENTS.md` § CodeQL covers how to read them and what to do
after a push touching `public/assets/js/`.

### What forks cannot have

GitHub withholds secrets from workflow runs triggered by a pull request from
a fork. On such a pull request:

- **SonarQube Cloud is skipped** — the job's `if:` condition says so
  explicitly.
- **Claude review cannot run** — no `CLAUDE_CODE_OAUTH_TOKEN`.
- **CodeRabbit is unaffected by the secret rule**, being a GitHub App rather
  than a workflow — whether it reviews a given fork pull request is its own
  setting, not something this repository controls.

If `Claude review` is ever made a *required* status check, a pull request
from a fork becomes permanently unmergeable, since the check can never
report. That is a governance decision about accepting outside
contributions, not a configuration detail.

## The failure mode this repository keeps meeting

Red is not the danger. Every layer above is loud when it fails. What has
actually gone wrong here, repeatedly, is **something green that proved
nothing**:

- Database-backed tests **skip** rather than fail without a server, so
  `phpunit` is green having tested none of them.
- `Claude review` exits **success** when it refuses to run over a
  workflow-file mismatch, so the check is green having reviewed nothing —
  and that happens precisely on a pull request editing the reviewer. This
  is the one on the list with a reader attached: the `Claude review status`
  comment names which of the two a green check was, reading the action's
  own `conclusion` output, which is set only once Claude has run.
  **The reader itself was the next instance of this failure mode**: it
  first judged on the run's duration, on the premise that a real review
  takes minutes. A review that finds nothing takes about as long as a
  refusal, so the verdict was near random on exactly the pull requests it
  was meant to reassure — green checks reported as unread, and no way to
  tell a true report from a false one (issue #159). A heuristic standing in
  for a fact is the same defect one level up.
- A `CODEOWNERS` entry naming a non-collaborator is **ignored silently**, so
  a protection rule can be enabled, appear active, and match nothing.
- A local reproduction that runs on the wrong database engine, or without
  the flag CI sets, **cannot go red** for the failure it is meant to
  reproduce.
- The release **Security gate passes on a permission gap**: denied access to
  the CodeQL or Dependabot alert API is a warning, not a refusal, so a
  release can be published with those two sources never consulted.
- **An issue form's label is dropped in silence when the label does not
  exist.** GitHub creates the issue anyway — no error on it, nothing in any
  log — so a report arrives with no triage state and looks exactly like one
  nobody has got to yet. `scripts/sync-issue-labels.sh` refuses to run when
  a form under `.github/ISSUE_TEMPLATE/` names a label outside its own
  table, which is the only place that pairing is ever checked.

The habit that catches these is cheap: ask what a green result would look
like if the thing had not run at all. When the answer is "the same", the
signal is not a signal. Where that distinction is known, it is written down
next to the thing it concerns — in the steward skill, in
`claude-review.yml`'s header, and here.
