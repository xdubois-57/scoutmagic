---
name: steward
description: How to handle a pull request event on this repository — a review comment (human or bot), a red CI job, a SonarCloud gate. Covers which local command reproduces which CI job, what to validate before pushing, and what to escalate to the author rather than fix. Read this before acting on any PR event; AGENTS.md and CONTRIBUTING.md remain the source of truth for the conventions themselves.
---

# Stewarding a pull request

`AGENTS.md` says what the code must look like. `CONTRIBUTING.md` says how to
submit it. This file says what to do when a PR **comes back at you** — a
review comment, a red check, a quality gate — and it assumes both of those
have been read.

## The rule that outranks everything here

`main` requires **conversation resolution before merging**. Every review
thread must be resolved before the merge button unlocks, so an unanswered
thread is not a loose end — it is a hard block. Resolving a thread is a
claim that it was addressed. Never resolve one to tidy the page.

Which means resolution is also how an agent hands itself a merge. So:

**Reply in the thread, then resolve it. Every time, including the trivial
ones.** The reply says what changed and where — "`mb_substr` in
`excerpt()`, plus an accented case in the test provider" — never a bare
"fixed" or "done", which tells the maintainer nothing the resolution
marker didn't. One factual line is the whole requirement; do not restate
the finding back, and do not thank the reviewer.

This is deliberate overhead, and here is what it buys: the only human on
this repository is its maintainer, and the reviewer on the other side is a
bot. Without that line, checking that a fix actually matches its finding
means reading the diff by hand, on every thread. The line also outlives
the code — it stays readable in the thread after the diff it describes has
been rewritten.

A thread you are **not** fixing gets the reply and stays **open**: say why
in a sentence, and let the maintainer decide. Never resolve a finding you
disagree with; disagreeing is not addressing.

## Review comments

**A bot finding is a bug report, not an opinion.** Codex
(`chatgpt-codex-connector[bot]`) reviews every PR here and posts inline
comments with a P-badge. Verify the finding against the code, then fix it.
"It's stylistic" is a conclusion you reach after reading the code, never a
reason to skip reading it.

The failure mode this repo has already lived through is the opposite one:
a finding that reads like a nit and is a real defect. Codex's first review
on this repo caught `strlen`/`substr` used where every neighbour used
`mb_strlen`/`mb_substr` — a one-word diff, and invalid UTF-8 in a
user-facing French label. Judge the consequence, not the size of the patch.

**Reply in the language of the thread.** Reviews arrive in English; the
maintainer writes French. Match whoever you are answering.

**Two questions, and size answers before identity.**

Fix and push, whoever raised it: nits, renames, a missing test, a
one-function correction.

Propose and let the maintainer decide — however the ask arrived, and
however confident the reviewer sounds: a multi-file refactor, a schema
change, anything touching a module's `Api\` contract (`ARCHITECTURE.md`
§7.5), open-ended design feedback. **That a bot raised it changes nothing
here.** A bot finding is a bug report worth acting on, and it is still not
a licence to make a change this size on your own: verify it, then bring a
proposal.

Identity only breaks a tie. When you cannot size a human reviewer's ask,
treat it as the larger one.

## A red check — reproducing it locally

Each row gives the job's **distinguishing command**: what it runs that no
other job does. That is a starting point, not a transcript. `ci.yml` is the
only exact account of a job, and when the red step looks like setup rather
than a test, go read it there before trusting anything below.

Two things every row assumes, because CI does them and a warm container
does not:

- **Dependencies installed the way CI installs them** — every PHP job runs
  `composer install --prefer-dist --no-progress` first, every Node job runs
  `npm ci`, and the browser jobs also run `npm run e2e:install`. An
  already-populated `vendor/` or `node_modules/` never exercises any of
  that, so a manifest/lockfile drift or an unmet platform requirement fails
  in CI and nowhere else. Run them when the failing step *is* the
  installation, or when you touched a manifest or a lockfile.
- **The engine CI hands the job**, which is not the one you have. See
  below — it applies to four of these rows.

| CI job | Its distinguishing command |
|---|---|
| `test` | `vendor/bin/phpstan analyse --memory-limit=512M`, then `vendor/bin/phpunit --coverage-clover coverage.xml --log-junit phpunit-report.xml` |
| `database-mariadb` | `vendor/bin/phpunit` (the session hook already exports `TEST_DB_*`) |
| `javascript-tests` | `npm run typecheck`, then `npm run test:coverage` |
| `End-to-end (browser)` | `E2E_COVERAGE=1 npm run e2e` |
| `Authorization matrix` | `./scripts/dast.sh --profile=standard` |
| `Dynamic scan (passive)` | `./scripts/dast.sh --profile=passive` |
| `security` | `composer audit` |
| `SonarQube Cloud` | no local equivalent — read the bot's PR comment |
| `Analyze (…)` (CodeQL) | no local equivalent — see `AGENTS.md` § CodeQL |

The flags are not decoration — each is a failure the shorter command
cannot show you:

- **`--coverage-clover` / `--log-junit`** on `test`, and
  **`npm run test:coverage`** rather than `npm test`, because producing the
  reports is part of the job: they are what `sonarqube` consumes, and
  report generation can fail with every test passing.
- **`E2E_COVERAGE=1`** because CI sets it and `scripts/e2e.sh` defaults it
  off, and the difference is behavioural: coverage slows every request
  enough to change timing. The Calendrier comment in
  `tests/e2e/specs/rental-management.spec.js` documents a failure that
  surfaced *only* under it. Needs pcov or Xdebug — this container has pcov.
- **`composer audit`** reports on *installed* packages, so a stale local
  `vendor/` audits a different set than CI's freshly installed one. (This
  job runs no `npm audit`; that one is a release gate, not a CI check.)
- **`./scripts/dast.sh`** refuses to download a missing ZAP image and exits
  before scanning anything. CI pulls `ghcr.io/zaproxy/zaproxy:stable` in a
  step of its own; do the same first, or you cannot tell a missing
  prerequisite from the failure you came to reproduce.

### Four of these rows run on the wrong engine

`test`, `e2e-tests`, `authorization-matrix` and `dast-passive` are each
handed **MySQL 8** by CI, which passes the last three their own
`E2E_DB_*` / `DAST_DB_*` variables. Locally all four fall back to
`TEST_DB_*` — the **MariaDB 10.11** this container's session hook starts,
and what production runs. So the commands above reproduce the *scenario*
and not the *engine*, and a MySQL-only failure in any of those four jobs
will sit there staying green.

That is the mirror image of the danger `AGENTS.md` § Database describes for
production, and it bites hardest exactly when you do not yet know what a
red job means. So:

- **`test` red while `database-mariadb` is green** is an engine divergence
  until proven otherwise, and `npm run test:engines` — which runs the suite
  against both — is its reproducer, not plain `vendor/bin/phpunit`.
- **For the browser and scanner jobs**, point the job's own variables at a
  MySQL 8 server before running: `E2E_DB_HOST`/`E2E_DB_PORT`/`E2E_DB_USER`/
  `E2E_DB_PASSWORD` for `npm run e2e`, the `DAST_DB_*` equivalents for
  `scripts/dast.sh`. Both scripts prefer those over `TEST_DB_*`, and both
  start a throwaway `mysql:8.0` container when nothing answers at all.

Do not paper over a divergence with a test that accepts both outputs —
`SchemaIntrospector` reads the server version for exactly this reason, and
that is where the branch belongs.

### Before you push a fix

1. Reproduce the failure locally first. A fix you never saw fail is a guess.
2. Run the checks matching the paths you touched — the same gating
   `CONTRIBUTING.md` steps 4-8 describe.
3. Re-read your own diff for what CI would reject.
4. Keep it minimal. A CI fix does not widen the PR.

A push that turns CI red costs a full cycle here: the confidence E2E tier
alone is ~8 minutes, and `Dynamic scan (passive)` is slower still.

## Things that are never the fix

- Skipping, disabling, or `@full`-tagging a test to get green. A slow
  scenario gets fixed; a flaky one gets fixed or deleted.
- Adding your own new finding to `phpstan-baseline.neon` or
  `js-typecheck-baseline.json`. Those accept *pre-existing* debt only.
- An empty commit, or closing and reopening the PR, to re-trigger CI.
- Bypassing a `scripts/release.sh` gate.
- Force-pushing or rebasing a branch you did not create. Merge `main` into
  the PR head to resolve a conflict; the merge commit keeps the author's
  checkout valid.

## SonarCloud on a PR

The `sonarqubecloud[bot]` comment carries the PR's Quality Gate, and it must
pass before merge. New issues it reports are this PR's to fix, whatever
their severity — the `MAINTAINABILITY`/`LOW`/`convention` exemption in
`AGENTS.md` § SonarQube Cloud release gate governs *releases*, not the PR
gate, and even there an exempt finding is still a finding.

**On a pull request from a fork it will never arrive.** The `sonarqube` job
is skipped there by design — `SONAR_TOKEN` is not exposed to fork runs, so
the job would fail rather than analyse — which means no bot comment and no
PR gate for an external contribution. Do not wait for one, and do not read
its absence as a PR that can never qualify: judge that PR on the checks
that did run plus your own reading of the diff, and expect SonarCloud to
have its say on `main` after the merge instead.

## What "done" means for a PR

Green CI on the current head, no merge conflict, **no thread left without a
reply**, and the PR template's checklist honestly filled. Threads you fixed
are replied to and resolved; the ones still open are open on purpose, each
carrying the sentence that says why, waiting on the maintainer.

A green PR with a silent thread is not done — and a PR whose threads were
all resolved without a word is worse, because it looks done.

**Never merge on your own initiative** — not to finish a PR, not because
everything finally went green, not because the maintainer seems likely to
agree. Green and merge-ready is where your work stops and you say so.

When the maintainer explicitly asks you to merge, that instruction is the
authorization and you carry it out: confirm every check is green on the
current head, that no thread is open, and that the PR is actually
mergeable, then merge — matching how this repository merges rather than
inventing a style. Nothing else substitutes for that instruction: not the
PR's state, not this file, not your own reading of what they would want.
