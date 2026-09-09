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

**A bot finding is a bug report, not an opinion.** An AI reviewer runs on
every non-draft pull request here. *Which* one is a setting the maintainer can change
in an afternoon, so read it off the PR — an author whose login ends in
`[bot]`, posting inline comments — rather than off a vendor name written
here, which is the kind of detail that goes stale without anyone noticing.
Verify what it reports against the code, then fix it. "It's stylistic" is a
conclusion you reach after reading the code, never a reason to skip reading
it.

**Its severity label orders your work; it does not settle anything.**
Whatever scale the current reviewer emits — P1/P2, high/low, a coloured
badge — it is that model's guess at importance. Whether a finding is real,
and what it would actually break, is yours to establish against the code.

The failure mode this repo has already lived through is the opposite of a
false alarm: a finding that reads like a nit and is a real defect. The
first bot review on this repository caught `strlen`/`substr` used where
every neighbour used `mb_strlen`/`mb_substr` — a one-word diff, and invalid
UTF-8 in a user-facing French label. Judge the consequence, not the size of
the patch.

**Reply in the language of the thread.** Reviews arrive in English; the
maintainer writes French. Match whoever you are answering. This is the one
thing `AGENTS.md` § Language exempts from its "everything written about a
change is French" rule, and the two files now say so to each other — a
thread reply is a conversation, not the record the change leaves behind.

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
other job does. That is a starting point, not a transcript.
`.github/workflows/checks.yml` is the only exact account of a job — that is
where the steps live; `ci.yml` and `release.yml` merely call it — and when
the red step looks like setup rather than a test, go read it there before
trusting anything below.

Three things every row assumes, because CI does them and a warm container
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
- **A browser Playwright will actually launch.** `npm run e2e:install`
  fetches the build the pinned `@playwright/test` asks for; this container
  ships its own under `/opt/pw-browsers` and they are usually a different
  build (1194 against the 1234 that 1.62.1 wants). The mismatch does not
  read as one: every spec fails in 2 ms, so a run looks like 75 broken
  tests rather than a browser that never started, and the reason is only in
  `tests/e2e/test-results/*/error-context.md` — never in the console
  output. Export `E2E_CHROMIUM_EXECUTABLE=/opt/pw-browsers/chromium`, which
  `tests/e2e/playwright.config.js` already reads, rather than downloading a
  second copy. Read the exit code from `npm run e2e:full` itself, too: pipe
  it into `tail` and the shell reports `tail`'s status, not Playwright's.

The gates live in `.github/workflows/checks.yml`, a reusable workflow
that `ci.yml` calls — which is why a pull request shows them as
`Checks / <job>`. The names below are the ones the pull request shows.

| CI job | Its distinguishing command |
|---|---|
| `Checks / test` | `vendor/bin/phpstan analyse --memory-limit=512M`, then `vendor/bin/phpunit --coverage-clover coverage.xml --log-junit phpunit-report.xml` |
| `Checks / database-mariadb` | `vendor/bin/phpunit --log-junit phpunit-mariadb.xml` against a MariaDB 10.11 reachable through `TEST_DB_*` |
| `Checks / javascript-tests` | `npm run typecheck`, then `npm run test:coverage` |
| `Checks / End-to-end (browser)` | `E2E_COVERAGE=1 npm run e2e` |
| `Checks / Authorization matrix` | `./scripts/dast.sh --profile=standard` |
| `Checks / Dynamic scan (passive)` | `./scripts/dast.sh --profile=passive` |
| `Checks / security` | `composer audit` |
| `All checks` | nothing of its own — it reads the reusable workflow's roll-up, so it is red exactly when a `Checks / …` job failed or was cancelled, and green when every one either passed or was deliberately skipped (`Checks / SonarQube Cloud` on a fork). Start from the red job, never from here |
| `Claude review` | no local equivalent — read the findings on the PR; see below. Two steps: the action, which reviews and keeps its whole transcript in the run log (`show_full_output`), then `Read what the run actually did`, which reads that transcript back into the counts the status job judges on. That second step never fails the job — a transcript it cannot parse is published as `evidence=missing`, because this is the one required check on `main` |
| `Claude review status` | no local equivalent — it posts the comment that says what the green above means, deciding from the review job's `result`, its `conclusion` output, and the transcript the run left behind; it is the one check here that can be red on its own |
| `Checks / SonarQube Cloud` | no local equivalent — read the bot's PR comment |
| `Analyze (…)` (CodeQL) | no local equivalent — see `AGENTS.md` § CodeQL |

The issue workflows — `issue-triage.yml`, `issue-backlog-scan.yml` and
`issue-fixed-comment.yml` — are deliberately absent from that table, and
adding a row for any of them would make it wrong. None can turn a pull
request check red or block a merge: the first fires on `issues:`, the
second on a cron, and the third on `pull_request: [closed]` filtered to a
merge, so it starts only once the merge it reacts to has happened. There is
nothing to reproduce when one misbehaves, and nothing to reproduce it with.
`docs/quality-pipeline.md` covers them instead.

`issue-fixed-comment.yml` is still worth watching after you merge: it says
on each issue the body named that the fix landed, then closes it, and it
exits non-zero when it could not say so — a comment the API refused, a
state it could not read back. That red run is on the merge commit rather
than on the pull request, and it means an issue is fixed and does not say
so. Finish that one by hand: comment, then close as `completed`, per
AGENTS.md § Fix the backlog, its closing step — named rather than
numbered, because the numbering has already drifted once under it.

Watch it after **each** block's merge rather than only after the last. The
backlog is fixed one block of issues per pull request now (same section,
§ Fix the backlog, step 4), so there is no single merge at which every
issue is meant to close.

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

**A green `Claude review` does not always mean a review happened.** The
action refuses to run when `.github/workflows/claude-review.yml` differs
from the copy on `main` — sound, since a pull request could otherwise
rewrite the reviewer and use its token — but it then exits *success*. So on
a pull request that edits that file, the check goes green in about fifteen
seconds having reviewed nothing.

You no longer have to open the run to find that out: the `Claude review
status` job posts one comment on the pull request, rewritten on every run,
saying whether a review happened and going **red** when it cannot show that
one did. Read that comment; it is the answer the check alone cannot give.

**And a green `Claude review` did not always mean a review happened even
when Claude ran.** For its first 105 runs the reviewer was refused one tool
call per run, launched no review agent, posted nothing, and reported
success. The first transcript that named the refusal named `Skill`: the
`prompt:` is a slash command, a slash command is invoked through that tool,
and the code-review procedure had therefore never been loaded on any of
them (#208, 2026-09-07; the story is in that file's header and in
docs/quality-pipeline.md § Code review). Which is why the comment's verdict
now rests on three rows — **`Review agents launched`**, **`Review agents
finished`** and **`Tool calls refused`** — read from the run's own
transcript. Agents launched at zero, fewer finished than launched, or a
tool refused **outside `Bash`**, and no review happened, whatever the check
says.

**`Bash` is the one entry granted command by command**, so a refused shell
line there is the allowlist working and does not fail the check — the row
still counts them. Every other tool is granted whole, so a refusal of one
is a gap in `claude_args`. That distinction was bought on #217: a review
that launched sixteen agents, finished all sixteen, spent 6.46 USD and
posted five findings was reported as "not a review" because it had also
tried nineteen exploratory one-liners and then done without them.
`python3 -c`, `php -r` and shell loops stay denied on purpose — that job
carries the maintainer's subscription token past an untrusted diff — and
the reason is written next to the list.

**`Review agents finished` is the one that catches a run that stopped
half-way.** Subagents start in the background, and a reviewer that ends its
turn waiting for one ends the run — nothing wakes a workflow up, so the SDK
closes it a success with part of the diff unread. That is what #208 did:
three spawned, two collected, no comment.

A refusal names the tool: grant it in `claude_args`, or write down in that
file why it must stay denied.

**Its `Took` row is information, not evidence.** That comment used to
decide on duration — under a minute meant a skip — and it was wrong
routinely, because a review with nothing to report finishes in about the
same time as a refusal. It announced "green without a review" over reviews
that had read the whole diff (issue #159).

A comment carrying **no `Claude ran` row at all** predates that fix, so its
verdict came from the clock and settles nothing either way: open the run
and read whether Claude ran. One survives only on a pull request that has
had no run since — every run rewrites the comment in place.

**Before believing a green PHPUnit run, check the database was there.** The
database-backed tests `markTestSkipped` when the server does not answer, so
`vendor/bin/phpunit` reports green on a machine with no MariaDB — having
skipped precisely the tests the `database-mariadb` job exists to run. In a
Claude Code *remote* session the SessionStart hook has already started
MariaDB and exported `TEST_DB_*`; in a local checkout that hook exits at its
first line (`CLAUDE_CODE_REMOTE` is not `true`), and even remotely it can
warn and carry on after a failed setup. Confirm the connection instead of
assuming it — the run's skipped count is the cheapest tell.

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
authorization and you carry it out. Nothing else substitutes for it: not
the PR's state, not this file, not your own reading of what they would
want.

Carry it out by **arming auto-merge**, not by watching the checks:

```shell
gh pr merge <number> --squash --auto
```

or, with no GitHub CLI on the box (a session running on the web), the
GitHub MCP server's `enable_pr_auto_merge` with `mergeMethod: SQUASH`. If
your build of that server has neither, say so — **never** substitute
`merge_pull_request`, which merges on the spot instead of arming.

Confirm first what you would confirm before merging by hand, because arming
it *is* merging: **every check green on the current head**, no open thread,
the checklist honestly filled, nothing of your own left unfiled. Then GitHub
lands it the moment the ruleset is satisfied, whether or not you are still
here. If everything is already green the same command merges immediately.

The CI confirmation is not a formality, though since 2026-09-06 the ruleset
catches most of it: `Claude review` and `All checks` are both required
contexts (`docs/quality-pipeline.md` § Branch ruleset on `main`), and `All
checks` needs every `Checks / …` job and goes red when any of them fails or
is cancelled. So a red `database-mariadb`, `Authorization matrix` or
`Dynamic scan (passive)` does block the merge now, where before it blocked
nothing and arming on one merged it (issue #170). Read `All checks` as the quickest way to confirm the
whole set. What it cannot tell you is that the pipeline has *finished*: it
reports only once every job it needs has, so arming before that is arming on
a verdict nobody has reached.

Do not poll the PR instead. That is what cost #152 four CI cycles and three
interruptions on 2026-09-05, and it is why auto-merge is enabled at all
(AGENTS.md § Merging a pull request, `docs/quality-pipeline.md`
§ Auto-merge). Two failure modes to know: auto-merge never updates a branch
that has fallen behind `main`, and it is disarmed in silence by a change of
base branch or a push from an account without write access.
