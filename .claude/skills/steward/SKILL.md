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
claim that it was addressed: resolve the ones you fixed, and leave open,
with a reply, the ones you are deliberately not fixing. Never resolve a
thread to tidy the page.

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

Fix and push: nits, renames, a missing test, a one-function correction,
anything a bot found. Reply with a proposal instead of pushing, and let the
author decide: multi-file refactors, a schema or `Api\` contract change,
open-ended design feedback from a human. When you cannot tell which a human
reviewer's ask is, treat it as the larger one.

## A red check — which local command reproduces it

Run the one that matches; do not run the whole battery for a lint failure.

| CI job | Reproduce locally with |
|---|---|
| `test` | `vendor/bin/phpstan analyse` then `vendor/bin/phpunit` |
| `database-mariadb` | `vendor/bin/phpunit` (the session hook already exports `TEST_DB_*`) |
| `javascript-tests` | `npm run typecheck` then `npm test` |
| `End-to-end (browser)` | `npm run e2e` |
| `Authorization matrix` | `./scripts/dast.sh --profile=standard` |
| `Dynamic scan (passive)` | `./scripts/dast.sh --profile=passive` |
| `security` | `composer audit` (this job runs that alone — `npm audit` is a release gate, not a CI check) |
| `SonarQube Cloud` | no local equivalent — read the bot's PR comment |
| `Analyze (…)` (CodeQL) | no local equivalent — see `AGENTS.md` § CodeQL |

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

## What "done" means for a PR

Green CI on the current head, no merge conflict, every review thread either
resolved or answered, and the PR template's checklist honestly filled. A
green PR with an open unanswered thread cannot merge, so it is not done.

Do not merge. Merging is the maintainer's, always.
