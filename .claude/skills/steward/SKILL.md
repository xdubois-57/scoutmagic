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

## A red check — which local command reproduces it

Run the one that matches; do not run the whole battery for a lint failure.

| CI job | Reproduce locally with |
|---|---|
| `test` | `vendor/bin/phpstan analyse` then `vendor/bin/phpunit` |
| `database-mariadb` | `vendor/bin/phpunit` (the session hook already exports `TEST_DB_*`) |
| `javascript-tests` | `npm ci`, `npm run typecheck`, `npm run test:coverage` |
| `End-to-end (browser)` | `E2E_COVERAGE=1 npm run e2e` |
| `Authorization matrix` | `./scripts/dast.sh --profile=standard` |
| `Dynamic scan (passive)` | `./scripts/dast.sh --profile=passive` |
| `security` | `composer install --prefer-dist --no-progress` then `composer audit` |
| `SonarQube Cloud` | no local equivalent — read the bot's PR comment |
| `Analyze (…)` (CodeQL) | no local equivalent — see `AGENTS.md` § CodeQL |

Every row is the job's own commands. The install steps and the flags are
not ceremony — each one is a failure the shorter command cannot show you:

- **`npm ci`** is the only step that fails on a `package.json` and
  `package-lock.json` that have drifted apart; an already-installed
  `node_modules/` never exercises it. **`npm run test:coverage`**, not
  `npm test`, because collection is part of the job — it writes
  `coverage/js/lcov.info` for `sonarqube`, and it can fail on its own with
  every spec passing. `npm test` is for iterating, not for concluding.
- **`composer install --prefer-dist --no-progress`** before `composer
  audit`, because the job installs first and `composer audit` reports on
  *installed* packages: a stale local `vendor/` audits a different package
  set than CI does, and a manifest/lock drift or a platform requirement
  fails in the install step you skipped. (This job runs no `npm audit` —
  that one is a release gate, not a CI check.)
- **`E2E_COVERAGE=1`** because CI sets it and `scripts/e2e.sh` defaults it
  off, and the difference is behavioural rather than cosmetic: coverage
  makes every request slow enough to change timing. The comment at
  `tests/e2e/specs/rental-management.spec.js` (the Calendrier click)
  documents a failure that surfaced *only* under coverage. It needs pcov
  or Xdebug loaded — this container already has pcov.

### These three reproducers run on the wrong engine

`e2e-tests`, `authorization-matrix` and `dast-passive` each provision
**MySQL 8** in CI and pass the job its own `E2E_DB_*` / `DAST_DB_*`
variables. Locally both scripts fall back to `TEST_DB_*` when those are
unset — which the session hook points at **MariaDB**. So the three commands
above reproduce the *scenario* but not the *engine*, and a MySQL-only
failure in any of them will sit there staying green.

To actually reproduce one, point the job's own variables at a MySQL 8
server before running — `E2E_DB_HOST`/`E2E_DB_PORT`/`E2E_DB_USER`/
`E2E_DB_PASSWORD` for `npm run e2e`, the `DAST_DB_*` equivalents for
`scripts/dast.sh`. Both scripts prefer those over `TEST_DB_*`, and both
start a throwaway `mysql:8.0` container when no server answers at all.

### The engine trap, which runs the opposite way locally

`test` runs on **MySQL 8**. `database-mariadb` runs on **MariaDB 10.11**,
which is what production runs — and it is also what this container's session
hook starts. So a green local suite is a **MariaDB**-green suite, and the
divergence you can miss here is a MySQL-only failure in the `test` job. That
is the mirror image of the danger `AGENTS.md` § Database describes for
production. `npm run test:engines` runs both; use it whenever a change
touches schema introspection, column defaults, or type normalisation.

`database-mariadb` red while `test` is green (or the reverse) is an engine
divergence until proven otherwise. Do not paper over it with a test that
accepts both outputs — `SchemaIntrospector` reads the server version for
exactly this reason, and that is where the branch belongs.

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

Do not merge. Merging is the maintainer's, always.
