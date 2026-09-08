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
| **End-to-end** | CI on every push; the full tier again on every release tag | The app not booting, a broken composition root | Everything a browser does not exercise |
| **Dynamic scan** | CI on every push; again on every release tag | Over-permissive routes, what the running app actually answers | Logic the scan does not reach |
| **CodeQL** | CI, GitHub-managed | Taint flows into DOM sinks | Non-JavaScript defects |
| **SonarQube Cloud** | CI; release gate | Quality, duplication, security hotspots | Intent |
| **AI triage** | Every issue opened or reopened, plus a nightly pass over the untriaged backlog | Whether a report is a real defect, the one fact a blocked report is missing, and the workaround when the behaviour is correct — and, when the reporter cited a support ticket, what that site's anonymised logs show | Anything a running installation shows that its diagnostic archive does not — it reads the code and an extract, but reproduces nothing, changes nothing, and gates nothing |
| **AI review** | Pull requests it is eligible for — not drafts, and `Claude review` not on forks | Cross-file reasoning, stale documentation, intent mismatches | Nothing reliably — it is a reader, not a gate |
| **Release gates** | `scripts/release.sh` | Deployment state, the CI verdict on the released commit, security advisories, dependency freshness, Sonar | What the AI reviewers read — intent, cross-file reasoning, stale docs. It runs no test and no scan of its own: it reads the runner's verdict on all of them |
| **Release workflow** | `.github/workflows/release.yml`, on the tag | The same gates a second time, on a runner nobody configured by hand, with each tool's native output kept, signed and attached to the Release | Nothing the gates themselves are blind to — it is a record of them, not a new judge |

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
  The release workflow's evidence run does this (`checks.yml`,
  `evidence: true`); it is the release standard, not the push standard.

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
against it. **The gates themselves live in `.github/workflows/checks.yml`**,
a reusable workflow that `ci.yml` and `release.yml` both call — one
definition of what "green" means, so the fast loop and the release pass
cannot drift apart. Because they are called, a pull request shows them as
`Checks / <job>`:

| Job | What it runs | Engine |
|---|---|---|
| `Checks / test` | `phpstan analyse --memory-limit=512M`, then `phpunit --coverage-clover --log-junit` | MySQL 8 |
| `Checks / database-mariadb` | the whole PHPUnit suite, `--log-junit` | MariaDB 10.11 |
| `Checks / javascript-tests` | `npm ci`, `npm run typecheck`, `npm run test:coverage` | — |
| `Checks / End-to-end (browser)` | `npm run e2e` with `E2E_COVERAGE=1` | MySQL 8 |
| `Checks / Authorization matrix` | `./scripts/dast.sh --profile=standard` | MySQL 8 |
| `Checks / Dynamic scan (passive)` | `./scripts/dast.sh --profile=passive` | MySQL 8 |
| `Checks / security` | `composer install`, then `composer audit` | — |
| `Checks / SonarQube Cloud` | scanner + Quality Gate, consuming the coverage artifacts | — |
| `All checks` | nothing of its own: needs every job above, runs whatever they concluded, red unless all succeeded | — |
| `Analyze (…)` | CodeQL, GitHub-managed default setup | — |

**`All checks` is one status check standing for every gate.** The ruleset
on `main` blocks a merge only on the checks it is told to require, by
name, and a check it is not told about blocks nothing — red or still
running (issue #170: three jobs gated nothing, and #168 merged with a scan
in flight). Requiring every job by name is the fragile fix: each name is a
merge that stalls forever the day that job is renamed or removed, since
the ruleset keeps waiting for a check that will never report again. So
`ci.yml` carries one job whose name never changes and whose `needs:` is
reviewed like any other line; it runs with `if: always()` so an upstream
failure leaves it red rather than *skipped* (a skipped required check
reads as "expected" — the misreading #152 lost an hour to). It is a
required context on `main` **since 2026-09-06**, which is what closed
issue #170 — see § Branch ruleset on `main` below.

`checks.yml` takes one input, `evidence`. Off, it is what the table shows.
On — only `release.yml` sets it — each job also keeps what its tool emits
natively and uploads it as an `evidence-*` artifact, the end-to-end job
runs the full tier (`npm run e2e:full`, the release standard) with a
screenshot per test, and `SonarQube Cloud` is skipped, because a tag is not
a branch SonarCloud should analyse. § Releases below says what becomes of
those artifacts.

`.github/workflows/dev-build.yml` is separate: every push to `main` builds
the installable artifact and attaches it to a rolling **prerelease** tagged
`dev-latest`. The prerelease flag is an invariant — the stable channel reads
`releases/latest`, which excludes prereleases, and that single fact is what
keeps the two update channels apart.

`.github/workflows/issue-triage.yml` is not CI and gates nothing: it runs
on `issues: [opened, reopened]` and answers the reporter — and on
`issue_comment: [created]`, which is how the reporter answers back. It is the one
workflow here that is triggered by a member of the public, which is why it
holds `issues: write` and `id-token: write` and **nothing else** — no
`contents`, no checkout, no path to `main` at all. Claude reads the code
through the GitHub MCP tools; a checkout is only ever needed in order to
write. Its judgement lives in `.claude/skills/triage/SKILL.md`, reviewed
like code, and the workflow fetches that file from `main` rather than from
a working copy it does not have.

**The model decides; the workflow writes.** `issues: write` is
repository-wide — GitHub has no issue-scoped token — so while the agent
held the GitHub write tools, nothing but the prompt stopped a hostile
issue body from talking it into commenting on, relabelling or closing a
*different* issue, and a prompt is exactly what an injected body competes
with. Both workflows now give the agent the **read** tools one by one
(never the whole `mcp__github` server) and take its verdict back as JSON
through `--json-schema`. Shell steps apply it: the per-issue job writes at
`github.event.issue.number`, the scan only to the issues it selected
before the agent started, refusing any other number and going red for it.
Two consequences worth knowing: the agent never names a **label** — it
returns one of four verdicts and the shell maps them, so an invented label
cannot be applied by anyone — and naming individual tools fails *closed*
if the action's pinned image renames one, which is the price of the
guarantee and the reason to re-check those names whenever the action SHA
is bumped.

That price was paid on the first live run. The list named
`mcp__github__issue_read`; the pinned image spells those tools
`get_issue` and `get_issue_comments`, so the triage of #181 was denied 48
times, triaged a report it had never read, exhausted its turn budget
retrying and went red with no verdict — while every audit in
`tests/Security/IssueTriageWorkflowPermissionsTest.php` passed, because
they all asked whether the list was *safe* and none asked whether it was
*sufficient*. Both spellings are listed now, two tests cover the open half
of the trade (the agent can read an issue and its comments; both workflows
read with the same list), and the symptom to recognise is in the
transcript verbatim: `Claude requested permissions to use
mcp__github__<name>, but you haven't granted it yet`.

Both issue workflows also **deny the tools that assume a "later"** —
`Agent`, `Task`, `ScheduleWakeup` — because an agent that hands an issue to
a subagent and ends its turn waiting for the answer has, in a one-shot run,
simply thrown the work away. That was the root cause under every symptom
below, and it took a preserved transcript to see; the same flag is what
finally makes the "no shell" claim in those files true, since
`--allowedTools` never did.

**The support ticket extract, and the trade it made.** A reporter who sent
a support ticket from their site can cite its reference (`SUP-` plus six
characters) in the bug form's optional field or in a reply, and a step
before the agent (`scripts/support-triage-extract.sh`) fetches from the
support site a **reduced, anonymised copy** of that ticket's diagnostic
archive — IP addresses, e-mail addresses and accounts replaced by per-run
tokens, `phpinfo` and the site's parameters left out (ARCHITECTURE.md
§8.49sexies, SECURITY.md §18ter) — and unpacks it under
`support-extract/<issue>/` for the agent to read. That is the one thing a
triage that reads code could never see. It cost the "no file tools"
sentence: `Read`, `Glob` and `Grep` are **allowed** now, and what their
denial used to protect is protected instead by four boundaries, each
asserted in `tests/Security/IssueTriageWorkflowPermissionsTest.php`: the
paths a token can be read from (`/proc`, `~/.claude/`, the action's
`_temp` directory) are denied by name; `WebFetch` and `WebSearch` are
denied, so an agent that reads a stranger's log line has nowhere to send
anything — its only exit is the JSON verdict; that exit passes
`scripts/triage-comment-gate.sh`, which withholds a comment carrying an
address or anything shaped like a credential and turns the run red; and
the retry's transcript is printed only on a run that fetched no extract,
with the extract removed from the runner in a step that always runs. The
token the fetch presents lives in `SUPPORT_TRIAGE_TOKEN` and is read by
that one step; the support site's address lives in the `SUPPORT_SITE_URL`
repository variable. Both scripts come from `main` through the API at
run time — there is still no checkout — and each has a `.test.sh` beside
it. A reporter's linked page is no longer fetched; the skill file says to
ask for its content instead.

Whose reference counts is the whole of the security argument, so it is
stated here: the issue's **body**, and comments by the issue's own reporter
(matched on user id) or by the repository owner. A passer-by cannot make
somebody else's triage read an archive of their choosing, and nobody can
read an archive whose reference they do not hold — the reference is shown
to the person who sent the ticket and to nobody else. The support site
binds a reference to the first issue that cites it and refuses a second.

**It also checks its own outcome, retries once, and goes red when the
issue is still untriaged** — and that is not belt-and-braces, it repairs a
hole that made the workflow look two-thirds reliable. `claude-code-action`
exits 0 whenever the agent's turn ends normally, and *ended normally
having written nothing* is a normal end: on 2026-09-05, four issues opened
within nine minutes (#172–#175) produced four green runs and four issues
still carrying `triage:pending`. One had its verdict comment and never got
its labels; three had neither, inside their turn budget, with no timeout
and no permission denial. Nothing anywhere was red.

So the job checks what the attempt actually RETURNED — `have_verdict`,
since the agent writes nothing — and if no usable verdict came back it
waits a minute (whatever ends an attempt early is transient and short,
and retrying into the same second meets it again) and runs the agent a
second time with the *same* prompt and arguments. Retrying is safe by
construction rather than by instruction: exactly one verdict is applied
per run, so two successful attempts still produce one comment. Then, once
the job has written, it reads the issue's labels back: `triage:done`
present and `triage:pending` gone is the only thing that counts as
triaged, because the labels are the state (§ Labels below) and a comment
without them reads to the rest of this pipeline exactly like an issue
nobody looked at. If both attempts leave the issue untriaged the
job fails, keeps the second attempt's full transcript (the only place it
is kept — `show_full_output` is off by default, and the first
investigation into this ran aground on a log that had discarded the
evidence), and the issue keeps `triage:pending` so the nightly scan takes
it regardless.

**A comment never cancels a triage in flight.** Both triggers share one
concurrency group, per issue, and it does not cancel in progress — because
it did until issue #181, where the reporter's own clarification, posted
three minutes after opening, started a second run that killed the first
and was then skipped by the guard (the issue did not carry
`bug:needs-info` yet). No verdict, no comment, and no red run: a cancelled
run reports neither failure nor success. Queuing costs minutes and orders
the two correctly — the comment run waits, and re-triages on that comment
if the verdict was `bug:needs-info`.

**A `bug:needs-info` answer restarts it.** Asking the reporter a question
was, until issue #176, a one-way door: no workflow here listened to
comments, and the nightly scan skips `triage:done` — the label the
question is filed under — so the reply reached nothing, however complete
it was. A comment on an **open** issue carrying `bug:needs-info`, on
something that is not a pull request, written by somebody who is not a bot
and who is either the issue's own reporter or the repository owner, now
sends that issue back to `triage:pending` (dropping `triage:done` and
`bug:needs-info`) and re-triages it against everything it says now.

**A comment on an issue carrying `bug:not-a-bug` does the same.** That
verdict is reached by reading code rather than by running the site, and a
reporter who comes back to say it still happens is the best evidence
available that it was wrong — so their reply is a re-triage, not a new
ticket. Since that verdict stopped closing anything, the push-back now
lands on a live thread instead of on a strikethrough. **A CLOSED issue is
outside this whatever it carries**: closed as `completed` by a merged fix
it carries `bug:confirmed` and a comment there is a conversation about
work that is done; closed by the maintainer after they read a
`bug:not-a-bug`, it is a human decision, and re-triaging it would be the
pipeline second-guessing the person it answers to. The
order matters: reset first, because `triage:done` left over from the first
pass would make the verification below pass over a run that did nothing —
and the reset **reads itself back** and fails the job when the labels did
not move, because `gh api -X DELETE` cannot tell an absent label from an
expired token. The bot exclusion is what stops the loop: the verdict this
job posts is itself a comment. The reporter clause is what stops a
passer-by — opening an issue costs a triage by design, taking one over does
not — and it says `OWNER` rather than `MEMBER`/`COLLABORATOR` on purpose,
since `author_association` is a relationship and not a permission: on an
organisation-owned repository those two include the Read and Triage roles,
which hold no write access at all. The prompt's duplicate guard is stated the same way for
both cases: a verdict is a duplicate only when nothing has been said since
it.

`tests/Security/IssueTriageWorkflowPermissionsTest.php` asserts that
shape: the labels read back, both halves of the predicate, the retry, its
gate, the single shared prompt and the single shared argument list, the
comment trigger with each clause of its guard, the label reset that must
precede the agent — and the write boundary above: that no allowed tool is
the bare server or carries a writing verb in its name, that a schema comes
back with the verdict as an enum, that the shell is what maps a verdict to
a label — and, since #181, that the list is also *sufficient*: the tools
that read an issue and its comments are there, under every spelling, in
both workflows.

**A verdict replaces the one before it.** The label reset strips the old
`bug:*` labels when a *comment* brings an issue back, but that step is
skipped on the `issues:` path — and a reopened issue is re-triaged from
there. #181 came out of it carrying `bug:confirmed` *and* `bug:not-a-bug`:
one was the answer, the other was the answer before the reporter corrected
it, and the pair told a maintainer nothing. The removal now sits beside the
write, in both workflows, and is asserted there — together with the read-back
that makes it safe: each deletion is allowed to fail, because the ordinary
reason one fails is that the label was not there, so what the job trusts is
the label set it reads afterwards. Exactly one `bug:*` label, or none for a
feature request, or the run goes red naming what it found.

`.github/workflows/issue-backlog-scan.yml` is the same triage, applied to
the issues that workflow never saw: everything filed before it reached
`main`, and everything whose run was lost to a cancelled job or a failed
one. It runs at 03:17 UTC, takes the **oldest five** open issues carrying
`triage:pending` or no triage label at all, and runs the same skill file on
each. Same permissions, same absence of a checkout, same reasoning — **and
the same verification, retry and red run**, because it met the same failure
the first time it ran.

That is worth stating plainly, since this file is where somebody will look
for it. Dispatched by hand the moment it merged, with four issues waiting,
the scan triaged **one** of them and ended `success` after 29 turns of a
180-turn budget. Nothing stopped it; it treated one issue as the task. A
safety net that reports success over three reporters it never answered is
not a safety net, it is a second place for issues to disappear — so it now
counts the candidates **before** the agent runs, counts them again after,
and compares what the run cleared against what it selected.

Against what it *selected*, never against an empty backlog: the cap is five
a night by design, so a backlog of two hundred leaves a hundred and
ninety-five behind and that is a complete run. Two details in that counting
fail silently when wrong, and both are asserted in
`tests/Security/IssueTriageWorkflowPermissionsTest.php`. GitHub's `/issues`
endpoint **returns pull requests too**, and a pull request carries no
triage label — so without excluding them every open pull request counts as
an untriaged issue and the job goes red every night demanding the agent
triage things that are not issues. And the one-hour cutoff is captured
**once**, before the agent, then reused: recomputing it afterwards would
let an issue that was fifty-nine minutes old at the start join the
candidate set at the end and read as work the agent failed to do.

The minute is 17 and not 0 on purpose: GitHub defers and drops scheduled
jobs under load, and load peaks at the top of the hour. A dropped run is a
night of backlog nobody triages, and nothing reports it.

It also leaves alone any issue **opened in the last hour**. The two
workflows cannot share a lock — one groups its concurrency per issue
number, the other per workflow, and GitHub offers nothing spanning the two
— so a brand-new issue would look untriaged to both agents at once and the
reporter would get two verdicts on the same report. Waiting costs nothing:
the per-issue job either labelled it, or failed and this scan takes it
tomorrow night.

The cap is the design, not a limitation: without it the first run against a
real backlog posts a comment on every untriaged issue at once, which is
both a large bill and a bad morning for whoever filed them. A backlog is
drained over nights.

Four GitHub behaviours shape that file, and all four fail *silently* —
they are in the list at the end of this document for that reason, and
repeated in the file's own header because that is where somebody editing it
will be looking.

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

**It made 105 runs before anybody could show that one of them had
reviewed anything, and the check was green for all of them.** The reviewer
really ran; on every run sampled it never got past the first step of the
command it was given. `claude_args` granted a single
tool — the one that posts a finding — because that is what makes the action
install the inline-comment MCP server. True, and incomplete: the same list
is the permission allowlist, and the code-review command is built entirely
on subagents. The four runs sampled had each spent five turns, been refused
exactly one tool, launched no review agent and posted nothing — on a
one-line pull request (#193) and a twenty-five-file one (#200) alike, and
with no Opus model in any of them, which the command's two bug-hunting
agents are by definition. Meanwhile CodeRabbit was finding real defects in
the same diffs. What the reader above said about all of it was "Reviewed,
nothing to report".

**The refused tool was `Skill`, and it was not `Task`.** The first run that
kept a transcript naming its denials was #208, and it named four. The first
is the whole story: `Skill{skill: "code-review:code-review"}`. The `prompt:`
this workflow passes is a slash command, and a slash command is invoked
through the `Skill` tool — so on all 105 runs the code-review procedure was
never loaded, and what ran was an agent improvising a review from the diff
with whatever tools it happened to hold. It also settles the question
`issue-triage.yml` left open: built-in tools **are** gated by
`--allowedTools`. The other three denials were one `git fetch` of the pull
request head, refused and retried twice; both are granted now.

**And that same run found a third way to review nothing.** It ended with
`result` reading *"Waiting for the background diff-summary agent to
complete before proceeding to the parallel review step"* — three review
agents spawned, two collected, no comment posted, `subtype: success`. A
subagent starts in the background unless the caller says otherwise, and
ending a turn to wait for one works in a session a person can resume;
nothing resumes a workflow run. Launched was 3 and, with the refused tools
granted, refusals would have been 0 — so **both signals added on 2026-09-07
would have passed it**, and the comment would have read "Reviewed, nothing
to report" over a review that stopped in the middle. The status job now
reads a third number, `subagent_stats.completed` against `spawned`, and
goes red when they differ; the reviewer is also told in
`--append-system-prompt` to wait for the agents it launches, which is a
mitigation rather than the guard, because it asks a model to remember
something.

Three things changed on 2026-09-07, and the third is the one that
generalises:

- The reviewer is granted the tools its procedure needs — `Task`/`Agent`
  first — and told, through `--append-system-prompt`, that the status
  comment below is written by the workflow rather than by Claude. Step 1 of
  the command stops the whole review when it believes Claude has already
  commented, and that comment is headed "Claude review".
- `show_full_output: true`, so a run keeps its transcript. Three rounds of
  fixes to the issue triage could not find its cause because no run kept
  one; the first that did explained it in a line.
- **The status job reads that transcript instead of the exit status**, and
  **goes red when it cannot show a review happened**. Its signals are how
  many review agents were launched, how many of them finished, and how many
  tool calls were refused: facts about the run, not estimates of it, and
  none needs a threshold. A refusal is disqualifying when it falls outside
  the refusals this workflow has **decided** — declared once, in
  `DELIBERATE_DENIALS` at the top of the file, so that a refused call the
  allowlist meant to refuse is the allowlist working and a refusal of
  anything else is a gap. See below for why that is a declared list rather
  than one tool name.
  `tests/Architecture/ClaudeReviewIsVerifiableTest` pins each of these
  against the single edit that would undo it.

**The first review that ever ran end to end was #217**, the first pull
request after that fix landed: 10 min 47 s against the 13 seconds a
declined run takes, `Skill` in its tool list, sixteen agents launched and
sixteen finished, `claude-opus-5` among its models for the first time, five
inline findings posted — a stale SSRF claim, a route count off by 130, two
paragraphs describing a cron that no longer exists. It also **failed the
status check**, over nineteen refused `Bash` one-liners (`grep`, `ls`,
`find`, `wc`, `jq`, and five `python3 -c` / `php -r`) that it went on to do
with `Grep` and `Read` instead. The read-only utilities are granted now;
the interpreters are not and will not be, because that job carries
`CLAUDE_CODE_OAUTH_TOKEN` and `id-token: write` past a diff this repository
does not control, and `pull-requests: read` bounds neither. That is the
"say in this file why it must stay denied" branch the steward skill offers
next to "grant it", and it is why the verdict counts refusals outside
`Bash` rather than all of them: a check that cries wolf over its first real
review is a check people learn to skip.

**"A refusal is red unless it was decided" needed somewhere to write
"decided".** The rule above shipped as a single literal — the count
excluded `Bash` and nothing else — and every tool decided after it was
decided in a comment the check could not read. So the check called three
complete reviews "not a review", in three pull requests, over three
different tools: `Bash` on #224 (fixed by #254), `Write` on #260,
`WebFetch` on #259. That is a series rather than a coincidence, and
naming a fourth tool in the `jq` would only have added a term to it
(issue #261). The decided refusals are now one list at the top of the
workflow, read by the `jq` that counts and named in the comment that
reports, with the reason for each written beside it. `Write` and
`WebFetch` are on it as refusals rather than grants: the run that settled
it (34260813534) was refused `Write` twice, both times for a throwaway
script to check string literals against a file, inside a run of
twenty-three refusals that also included `python3`, a heredoc, a loop and
a pipe — granting `Write` would have cleared two of twenty-three and left
the review red at the next loop. The reviewer did not need to write; it
needed to run code, which is the one thing this job will not grant. And
`WebFetch` does not inherit `Write`'s cheapness at all: a file in a
throwaway workspace is inert, while an outbound request to a URL the
agent chose, from a job holding `CLAUDE_CODE_OAUTH_TOKEN` and
`id-token: write` past an untrusted diff, is an exfiltration channel.

**The truncated reviews had a tell, and it was in the tool list.** On
2026-09-08 the same commit was reviewed twice and stopped part-way both
times — 7 agents launched, 3 collected, three minutes and two dollars
against the 11 min 37 s and 8-of-8 of a complete pass on the same pull
request. `ScheduleWakeup` appears in the tool list of every one of those
runs and in neither of the two complete reviews of that day (issue #262).
A reviewer that schedules a wake-up has ended its turn, and nothing wakes
a workflow run up: the SDK closes it a success with four agents still
reading, which is the #208 failure in a new spelling. It is refused now,
declared on the same list, and the prompt says why — there is no later
turn to schedule. The `spawned` against `completed` comparison remains
the guard; this only stops the reviewer walking into it.

**Then a working reviewer found the ceiling.** #217 took 10 min 47 s
against a `timeout-minutes: 20` written when a review took a few minutes
and the number was a formality. Pull request #257 — 185 files, the whole
accepted backlog in one change — was cancelled at 20m20s and again at
20m21s on an independent run. Nothing was wrong with either run: the
reviewer was working when the clock stopped it. But `Claude review` is
one of the two required contexts on `main`, GitHub reports a cancelled
required check as unmergeable, and no re-run can help, because the second
attempt is as long as the first. **A ceiling written to bound a runaway
had become an undeclared size limit on pull requests**, and it announced
itself as a merge refusal rather than as anything about the reviewer. The
ceiling is 60 minutes now, chosen against what a review of that size
costs rather than against what a normal one does; issue #262 collects the
reviewer's defects, and this is the first that blocked a merge outright.

The mirror of § Reading a green result applies here: a **red** result can
prove nothing too. Cancelled is not failed, and neither is a verdict on
the diff — the run has to be read before either is treated as one.

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

Five gates, all fail-closed, all run **before** any commit or tag, one
after another, and the release stops at the first one that refuses:

| Gate | What it checks |
|---|---|
| **Deployment** | production is on the previous release and answers `GET /api/version` |
| **Continuous integration** | `All checks` is green on the commit being released, and the working tree is clean |
| **Security** | `composer audit`, `npm audit`, open CodeQL findings, open Dependabot alerts |
| **Dependency freshness** | `composer outdated --direct`, and every vendored front-end library against its upstream release |
| **SonarQube Cloud** | `scripts/check-sonar-release.sh` — see below |

**None of them runs a test**, and that is the design rather than a gap.
PHPStan, both PHPUnit engines, the JavaScript analysis and tests, the
browser suite, the authorization matrix and the passive scan run on a
runner — on the pull request, on the push to `main`, and again on the tag,
where what each tool emits is signed into the evidence pack. Running them
on the releaser's machine as well used to take twenty-five of the release's
thirty minutes and was the *least* trustworthy of those runs: one database
engine where CI uses two, four of the reproductions on the wrong engine
entirely (see the steward skill), and a verdict appearing nowhere a reader
of the Release could check. The **Continuous integration** gate buys back
the one thing that was worth keeping — failing *before* the tag exists — by
reading the verdict GitHub has already reached on the same commit.

That gate refuses three ways, and each says something different: a dirty
working tree (the artifact is zipped from it while the verdict is about
`HEAD`, so uncommitted changes would ship untested), no `All checks` run
for the commit at all (never pushed, or the workflow never started — and
"no verdict" is not a pass), or a run that is red, cancelled, or still
going after a bounded wait.

Gates fail closed on a missing tool or service rather than silently doing
less.
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

**Bypass flags** (`--skip-deployment-check`, `--skip-ci-gate`,
`--skip-security-gate`, `--skip-dependency-check`, `--skip-sonar-gate`)
exist for genuine emergencies. Each prints a warning naming exactly what
was not checked. Using one to route around a real finding is how a release
ships a known defect — and `--skip-ci-gate` is the widest of them by far,
since nothing else in the script looks at the code at all: a run with it
has been tested by nobody until the tag's own workflow says otherwise, and
that runs *after* the tag exists.

After the gates pass, the script bumps `VERSION`, commits, tags `vX.Y.Z`
and pushes the tag — and that push starts the second half.

### The Release workflow and the evidence pack

`.github/workflows/release.yml` runs on every `v*` tag (and on
`workflow_dispatch`, which is how the chain is rehearsed without cutting a
version — a dispatch run keeps the pack as a workflow artifact and creates
no Release). It calls `checks.yml` with `evidence: true`, so **every gate
runs a second time, on a runner nobody configured by hand**, with each
tool's native output kept. Beside them it records the repository's open
CodeQL and Dependabot alerts as GitHub's API returns them (or a file
saying the call was refused and where to read by hand), and fetches
SonarCloud's complete analysis **of the released commit** through
`scripts/sonar-evidence.php` — waiting for `ci.yml`'s analysis of that
commit if it is still running, and refusing if it never arrives, because
"not analysed yet" and "analysed and clean" are different answers.

Only if all of that is green does the last job build the pack: every
`evidence-*` artifact, a `manifest.json` naming the repository, the commit
and the run URL, a `SHA256SUMS` over every file, all in
`evidence-vX.Y.Z.tar.gz`. It **signs the archive** through
`actions/attest-build-provenance` — GitHub's own identity via Sigstore —
and creates the Release as a **draft** carrying it. A red gate creates no
draft at all.

| Evidence | Where it comes from |
|---|---|
| PHP tests on MySQL 8, with coverage | PHPUnit `--log-junit`, `--coverage-clover` (`Checks / test`) |
| PHP tests on MariaDB 10.11 | PHPUnit `--log-junit` (`Checks / database-mariadb`) |
| PHP static analysis | PHPStan's verdict, its version, level, paths, baseline size, **and the list of every file it analysed** (from `--debug`; the JSON report lists only files with errors, so on a clean run it is empty) |
| JavaScript static analysis | `tsc`'s verdict through `npm run typecheck`, its version, baseline size, and the files it checked |
| JavaScript tests, with coverage | Vitest `--reporter=junit`, plus the lcov |
| End-to-end | Playwright's own HTML report, full tier, one screenshot per test, plus the browser-side Clover |
| Authorization matrix | `authz-matrix.json`, every (route, role) pair and its verdict |
| Dynamic scan | ZAP's full HTML report and SARIF, plus counts per level — published deliberately, see below |
| Dependency audit | `composer audit`'s output |
| GitHub security alerts | open CodeQL and Dependabot alerts, and the commit's `Analyze (…)` check runs |
| SonarCloud | quality gate, every measure, the same per file, every open issue sorted by the release rule, every hotspot, and a French front page |
| Provenance | `manifest.json`, `SHA256SUMS`, and the Sigstore attestation |

**How an auditor checks it.** The reports are produced by the same
pipeline they attest to, and anybody who can change that pipeline can
change what it emits — so the pack is built to be cross-checked rather
than trusted. `manifest.json` names the run; that run's log is
timestamped, retained by GitHub and editable by nobody with write access
here. `SHA256SUMS` detects a pack edited after the fact. And the
signature is the part nobody in this repository can forge:

```
gh attestation verify evidence-vX.Y.Z.tar.gz --repo xdubois-57/scoutmagic
```

fails if the archive was altered by a byte, or built anywhere other than
this workflow in this repository.

**The full DAST report is in the pack, on purpose.** This repository is
public, so Release assets are public — and so are the workflow artifacts
`Checks / Dynamic scan (passive)` already uploads on every run. The scan
describes a throwaway instance on `127.0.0.1` running code anybody can
read; what survives that is that a header or cookie finding on it is a
finding about the shipped configuration, so publishing one publishes a
to-do list before it is done. The trade is a pack anybody can audit
without a GitHub account, at the cost of saying out loud what the scan
reports — worth making only while the report stays clean, which the gate
(red at Medium and above, before any pack is built) is what ensures. One
`if:` in `checks.yml` reverses it.

**The evidence pack must never be a `.zip`.** Every installed site takes
the first Release asset whose name ends in `.zip` as the application
(`Core\Maintenance\GitHubReleaseClient::selectZipAssetUrl()`, and
`bootstrap.php`), GitHub sorts assets alphabetically, and those sites run
the code they already have — so `evidence-v1.0.42.zip` would be installed
as ScoutMagic on every site that updates, and no fix here would reach
them first. `tests/Architecture/ReleasePipelineIsWiredTest` pins the
extension, and `release.sh` counts the zips before publishing.

### Finishing the draft

`release.sh` does not create a Release of its own — two would target the
same tag, the workflow lands last, and it would quietly turn a published
Release back into a draft. While the runners work it builds the
installable artifact through `scripts/build-artifact.sh`; then it waits
for the run, **refuses on anything but `success`** (the tag then exists
and points at nothing: fix the cause on `main`, delete the tag, re-run —
the script recomputes the same version and leaves an already-bumped
`VERSION` alone), attaches `release-vX.Y.Z.zip` and `bootstrap/bootstrap.php`
to the draft, checks that exactly one `.zip` and the evidence pack are on
it, writes the notes, and publishes with `--latest`. A release is one
command, and it takes about an hour: the local gates, then the runner's.

The notes are four parts, and only the first is written by a person: the
note (or GitHub's generated commit list), the **Vérifications effectuées**
block (one line per local gate, as it ran or was bypassed), the
workflow's own description of the pack and how to verify it, and the
**dependency inventory** — `scripts/dependency-inventory.php`, every PHP
and JavaScript package at the version the lock files record, every
vendored front-end library at the version its banner declares, each with
its licence, and a table saying licence by licence why it may be
combined with this project's AGPL-3.0. A licence that table has never
seen is printed as *à examiner* rather than reassured about, and
`tests/Core/System/DependencyInventoryTest` fails the build the day one
appears without a written verdict. Nobody writes that list by hand.

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
| `SONAR_TOKEN` | the `sonarqube` job in `checks.yml`, `check-sonar-release.sh`, `release.yml`'s SonarCloud evidence job | no Quality Gate on pull requests; the release gate fails closed; a tag's Release workflow refuses for want of the analysis |
| `CLAUDE_CODE_OAUTH_TOKEN` | `claude-review.yml`, `issue-triage.yml`, `issue-backlog-scan.yml` | the review job fails at authentication, and no issue is ever triaged — neither on arrival nor overnight |
| `SUPPORT_TRIAGE_TOKEN` | the `extract` step of `issue-triage.yml` and `issue-backlog-scan.yml` | no support ticket extract is ever fetched: a cited reference is reported in the step's log and the triage runs on the issue alone, green |

`CLAUDE_CODE_OAUTH_TOKEN` is generated with `claude setup-token` and spends
a Claude subscription rather than a metered API key. It is tied to the
person who generated it.

`SUPPORT_TRIAGE_TOKEN` is generated on the support site — Supervision ›
Tickets › « Générer un nouveau jeton » — shown once, and pasted here; the
site keeps only its hash (ARCHITECTURE.md §8.49sexies). Generating a new
one there invalidates this one at once, so the two are rotated together.

One thing about `CLAUDE_CODE_OAUTH_TOKEN` that the extract makes worth
writing down: the extract is anonymised before it leaves the support site
(SECURITY.md §18ter), so what the triage agent reads is not personal data
— but which of the provider's terms govern what it reads depends on the
kind of token that authenticates the run (a subscription token and a
metered API key are not under the same terms), and that is the
maintainer's to check against the provider's current terms, not something
this repository can assert. Until it is checked, the anonymisation is the
guarantee, and it is the one the tests hold.

### Repository variables

*Settings → Secrets and variables → Actions → Variables*

| Variable | Used by | Without it |
|---|---|---|
| `SUPPORT_SITE_URL` | the `extract` step of both issue workflows | same as a missing token: no extract is fetched, and the step says so |

The support site's address, `https://…` with no trailing slash. A variable
rather than a literal in the workflow so that the file names no host, and
rather than a secret because it is not one.

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
  before. Two contexts are required **as of 2026-09-06**, confirmed
  against `GET /repos/xdubois-57/scoutmagic/rules/branches/main`:
  `Claude review`, and `All checks` (§ Continuous integration) — one name
  standing for every `Checks / …` job, stable across renames, red whenever
  any gate is red or was cancelled. The second is what closed issue #170
  at the source: a red `database-mariadb`, `Authorization matrix` or
  `Dynamic scan (passive)` now blocks the merge and holds an armed
  auto-merge, where until that day the three gated nothing at all. Do not
  add the individual `Checks / …` names as well: that is the fragile form
  — each name is a merge that stalls forever the day that job is renamed
  — and `All checks` already waits for all of them.
  **`Claude review status` is deliberately not on the list**, though since
  2026-09-07 it can go red (§ Code review). It warns rather than blocks
  while its signals — review agents launched, review agents finished, tool
  calls refused —
  build a run history: a check that has never been wrong on this repository
  is not yet a check worth deadlocking every merge on. Promoting it is a
  one-line change here and in the ruleset, and it is the maintainer's call.
- **Require branches to be up to date before merging** — *deliberately off.*
  It is the sub-option of the rule above, and turning it on again brings back
  the failure it was turned off for: with it on, a pull request must be even
  with `main` at the moment of merging, so every push to `main` invalidates
  every open pull request and each one has to re-run a fifteen-minute CI
  before it can land. A single agent never notices; a burst does. On
  2026-09-05 five pull requests landed on `main` within the hour, and #152
  lost four consecutive CI cycles to it — each time green, each time stale
  again before the merge call, and each recovery needed a human to be told.
  GitHub reports that state as `Required status check "Claude review" is
  expected`, which reads like a check that never ran rather than a branch
  that fell behind.
  What it protected against is real and is now caught one step later:
  two pull requests, each green alone, whose combination is not. CI runs on
  every push to `main` (`ci.yml`), so that combination is tested — after it
  lands rather than before, which means somebody has to answer for the
  window in between. **The maintainer does**, on the failure notification
  GitHub sends for a red run on `main`, and the answer is forward: the
  second pull request's author fixes it in a new pull request, the way any
  other red `main` is handled here. Not a revert by default — the two
  changes are both wanted, and reverting the one that merged second
  punishes an ordering nobody chose. Revert only when the fix is not
  quick and `main` has to be green for a release. The window is small by
  construction: it opens only when two pull requests are in flight at once,
  which on a repository with one maintainer means a burst, and it closes at
  the next push.
  The alternative that keeps the guarantee without the stall is a merge
  queue, and it is not free here: it needs every
  gating check to report on `merge_group`, and `Claude review` is bound to a
  pull request (its prompt names `github.event.pull_request.number`) while
  CodeQL runs as GitHub-managed default setup that this repository cannot
  give a trigger to. Revisit it if the combination failure ever actually
  bites.
- **Require review from Code Owners** — *not enabled, and not currently
  enableable.* See below.

### Auto-merge

*Settings → General → Pull Requests*

**Allow auto-merge — enabled.** It is what lets an agent carry out
"merge this" without sitting on the pull request: GitHub merges the moment
the ruleset above is satisfied, and nothing has to be watched or asked
again. It grants nothing — a pull request with auto-merge armed still
merges only when every rule passes — so the checklist in AGENTS.md
§ Merging a pull request is about *when an agent may arm it*, never about
what it lets through.

Note what turns it off again: **a push by someone without write access, or
a change of base branch, disarms auto-merge silently.** Nothing announces
it. A pull request that was going to land and then simply did not is the
first thing to check.

And note what it now waits for, and what it still does not. The
required-check list is two contexts, `Claude review` and `All checks`, and
the `code_scanning` rule above waits on CodeQL and SonarCloud — so an armed
pull request whose `database-mariadb`, `Authorization matrix` or `Dynamic
scan (passive)` is red no longer merges: `All checks` is red with it. Until
2026-09-06 those three gated nothing at all and an armed pull request
merged over them, which is what issue #170 was about.

What no ruleset can tell you is that a pipeline has *finished*. `All checks`
reports only once every job it needs has, so arming before that is arming
on a verdict nobody has reached yet — and a required check that has not
reported looks, in GitHub's own display, like one that is merely waiting.
That is why AGENTS.md § Merging a pull request still puts "every check green
on the current head" first among the things to confirm, and why arming and
walking away from an unfinished pull request is the one thing it forbids.

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
`issue-backlog-scan.yml` writes exactly the same labels through exactly the
same skill file — there is one taxonomy and one method, not two.

The labels are also what the nightly scan **reads** to decide what is left
to do: an issue carrying `triage:done` is never picked up by *the scan*
again, and one carrying neither `triage:pending` nor `triage:done` is
treated as never triaged. The one thing that puts a `triage:done` issue
back in the queue is the reporter answering a `bug:needs-info` question,
which `issue-triage.yml` handles by resetting the labels itself (above);
that exception exists because the scan's rule made the question
unanswerable. That makes an unlabelled issue self-healing — but it also means a
label removed by hand puts an issue back in the queue, and it will be
answered a second time.

Which is why **a verdict comment is not a triage and the labels are.** An
issue can carry a careful, correct, published answer and still be
invisible to every other part of this pipeline, because nothing else reads
the comment: the maintainer's filters, the nightly scan and the per-issue
job's own verification all read the labels. That is the state issue #172
was left in, and it is what `issue-triage.yml` now checks for before
calling a run successful.

**No verdict closes an issue.** `bug:not-a-bug` used to, with reason
`not planned`; it was the one thing in this pipeline that ended a
conversation with somebody who had taken the trouble to write, decided by
a reader of the code who never ran the site, on a report the maintainer
had not seen. It is now an answer: the comment is posted, the label is
applied, the issue stays open, and the maintainer closes it when they have
read it. A label is cheap to disagree with; a closure is not — and the
recourse against a wrong reading is now a reply on a live thread rather
than a reopening.

The skill still makes that verdict expensive on purpose: it may only be
reached when the workaround can be written for the reporter without
jargon, and when it cannot, the interface misled a competent user and the
verdict becomes `bug:confirmed` about the interface instead. What changed
is the cost of being wrong, not the standard for being right.

**The one thing that does close an issue automatically is a merged fix**,
and on a body written to the convention below — `Corrige #158` — it is
`issue-fixed-comment.yml` that closes it, not GitHub. On every merge the
workflow reads the pull request body for the issues it names, posts one
comment per issue saying the fix is on `main` and in which pull request and
commit, and closes each as `completed` afterwards. An issue it could not
comment on is deliberately left open, and every failure but one turns the
run red (which one, and why, is two paragraphs down): an open issue is five
minutes of somebody's time, a silent closure is the thing the file exists
to prevent. A body that uses one of GitHub's own closing keywords instead
is the exception, and it is the old behaviour: GitHub closes that issue at
the merge and the workflow's comment lands beside the closure rather than
before it.

That order is the reason a pull request body here says `Corrige #158`
rather than `Closes #158`. GitHub's own closing keywords close the issue
server-side at the instant of the merge, seconds before any workflow can
run, so the earlier version of this file commented *beside* a closure that
had already happened — the reporter's first notification was still a
strikethrough. `Corrige` is not a keyword GitHub acts on, so nothing closes
the issue but the workflow, after it has spoken. What that costs is the
issue's *Development* sidebar link, which only a closing keyword creates;
what stands in its place is the timeline cross-reference `Corrige #158`
still produces and a comment that names the pull request and the commit
outright. English keywords are still read, so a body written the old way
gets its comment too — GitHub will have closed that one first, and the
workflow finds it already closed. The convention is asserted in
`tests/Security/IssueTriageWorkflowPermissionsTest.php`, because AGENTS.md
drifting back to `Closes` would be a green pull request that silently
restores the old ordering.

That guarantee is only as good as the run's exit code, and at first it was
not: every call in that job ended `|| echo "::warning …"`, so a run in
which nobody was told anything finished **green**, with two annotations on
a merge everybody had moved on from — the shape this document's last
section is entirely about. Now exactly one failure is tolerated, and it is
the one the code was describing: a **404**, meaning the number is not an
issue of this repository (deleted, mistyped, pointing elsewhere), which
warns and skips. Everything else fails the run, told apart by reading
`gh`'s own message the way `scripts/sync-issue-labels.sh` does. Two
consequences worth knowing: **the closing waits for the telling** — an
issue whose comment could not be posted is left exactly as it is, since
closing it anyway is the silent closure this job exists to prevent — and a
merged pull request **from a fork** now goes red instead of quiet, because
`pull_request` hands such a run a read-only token whatever the job's
permissions say, and no permission here can change that (the alternative,
`pull_request_target`, is forbidden for this file and asserted to be).

It also says it **once**: the comment carries an invisible marker naming
the pull request, and an issue already carrying it is skipped whole — no
second notice on a re-run, and no re-closing of an issue a human has since
reopened. And it reads the closing **reason**, not just the state, so a
report closed as `not planned` before its fix landed (GitHub's keyword
does not reopen a closed issue) stops being filed under "dropped" on a
thread that now says it is corrected.

One gap, recorded rather than papered over: **a feature request has no
verdict.** `feature.yml` opens issues with `triage:pending` like `bug.yml`,
but the three verdicts are all about defects, and `bug:not-a-bug` means
*we looked, and there is nothing to fix here* — the wrong thing to attach
to a request the maintainer may well want to build. Such an issue therefore gets
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

`Claude review` **is** a required status check on `main` — the only one —
so a pull request from a fork is permanently unmergeable here: the check it
needs can never report. That is live, not hypothetical, and it is a
governance decision about accepting outside contributions rather than a
configuration detail. Reopening the repository to outside contributions
means revisiting it; `.github/workflows/claude-review.yml` carries the same
note next to the token it depends on.

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
  comment names which of the two a green check was.
  **The reader itself has now been the next instance of this failure mode
  twice.** It first judged on the run's duration, on the premise that a
  real review takes minutes. A review that finds nothing takes about as
  long as a refusal, so the verdict was near random on exactly the pull
  requests it was meant to reassure — green checks reported as unread, and
  no way to tell a true report from a false one (issue #159). It then
  judged on the action's own `conclusion` output, which is deterministic
  and answers a different question: *did the action start Claude*, not
  *did anybody review the diff*. An agent that starts, is refused a tool
  and ends its turn normally sets it, which is what every run sampled on
  2026-09-07 was doing — see § Code review. A heuristic standing in for a fact,
  then a fact standing in for a different fact: the same defect one level
  up, twice. It now reads the run's own transcript — agents launched,
  agents finished, tool calls refused — and goes red when none of them can
  show a review happened.
- **An agent's own report of success means only that its turn ended.** It
  is the same reading error as the bullet above and it deserves its own
  line, because it applies to every job in this repository that runs one.
  A reviewer refused a tool, a triage that read an issue and gave up, a
  scan that answered one candidate of four: all three end normally, and
  the pipeline sees `is_error: false`. What separates them from real work
  is never the exit status but what the run *did* — a comment posted, a
  label applied, an agent launched — read back from the transcript or from
  GitHub's own state. Anything a job asserts about its agent must be a
  count of one of those.
- A `CODEOWNERS` entry naming a non-collaborator is **ignored silently**, so
  a protection rule can be enabled, appear active, and match nothing.
- A local reproduction that runs on the wrong database engine, or without
  the flag CI sets, **cannot go red** for the failure it is meant to
  reproduce.
- The release **Security gate passes on a permission gap**: denied access to
  the CodeQL or Dependabot alert API is a warning, not a refusal, so a
  release can be published with those two sources never consulted. The
  evidence pack records the same two sources with the same honesty — a
  file saying `UNAVAILABLE` and why, rather than no file — so read the
  pack's `codeql-open-alerts.json` before reading its absence as clean.
- **A draft Release is invisible and stays so.** `release.yml` creates the
  Release as a draft, and `release.sh` is what publishes it; a script that
  died between the two — a laptop asleep, a network gone — leaves a tag,
  a draft with the evidence pack and no zip, and no installed site any the
  wiser. Nothing reports it. `gh release list` shows drafts; finish one by
  hand with `gh release upload` and `gh release edit --draft=false --latest`.
- **A required status check that is *skipped* reads as "expected".** GitHub
  shows it exactly like a check that has not reported yet, and the ruleset
  waits rather than refuses. This is why `All checks` runs under
  `if: always()`: without it, a failed gate would leave the verdict job
  skipped, and the pull request would sit looking like one whose CI is
  still running rather than one whose CI failed.
- **Auto-merge is disarmed in silence** by a push from someone without write
  access or by a change of base branch. The pull request simply stops being
  on its way to `main`, looking exactly like one nobody has merged yet.
- **A feature switched off in the end-to-end fixture is a feature the
  browser suite stops proving anything about.** `scripts/e2e-support.php`
  sets `help_discovery_enabled` to 0 when it provisions the instance, and
  it had to: « Le saviez-vous ? » opens on every page load of a signed-in
  account until it is closed once, a modal's backdrop intercepts pointer
  events, and forty-four scenarios that have nothing to do with the help
  could no longer click anything. Nothing about that setting reports
  itself — the suite simply goes green over a dialog nobody exercised.
  What holds it is `specs/help-discovery.spec.js`, which turns the
  setting back on for itself and drives the whole chain; delete that spec
  and the fixture's switch silently becomes a hole. Any other fixture
  that turns a shipped behaviour off owes the same pairing.
- **An issue form's label is dropped in silence when the label does not
  exist.** GitHub creates the issue anyway — no error on it, nothing in any
  log — so a report arrives with no triage state and looks exactly like one
  nobody has got to yet. `scripts/sync-issue-labels.sh` refuses to run when
  a form under `.github/ISSUE_TEMPLATE/` names a label outside its own
  table, which is the only place that pairing is ever checked.
- **An agent can end its turn believing it will be resumed.** The single
  most expensive silent failure this repository has met, and the cause of
  every symptom above. A backlog scan with three issues waiting spawned
  three `Agent` subagents, one per issue, called `ScheduleWakeup`, and
  ended with *"No need to schedule a wakeup — I'll be notified
  automatically when each research agent completes."* There is no later:
  these are one-shot runs, the process exits with the turn, and everything
  the subagents found is discarded. Sixteen turns of a three-hundred-turn
  budget, `subtype: success`, zero permission denials, not one write call.
  Both workflows now deny `Agent`, `Task` and `ScheduleWakeup` and say so
  in the prompt. It stayed unexplainable for three rounds of fixes because
  no run kept its transcript.
- **`--allowedTools` is a permission allowlist, not the tool surface.**
  Naming only the GitHub MCP server does *not* take `Bash`, `Read`, `Glob`
  or `Grep` away — a transcript caught the agent running `date` and `ls`
  on the runner while both workflow files claimed it "holds no shell".
  Only `--disallowedTools` makes that claim true. An untrue security
  comment is worse than none: it is the one somebody relies on. And "there
  is no checkout, so there is nothing to read" is not a reason to leave the
  read tools out: an empty *repository* is not an empty *filesystem* —
  `/home/runner/.claude/`, the action's `_temp` directory and
  `/proc/self/environ` are all still there, the last being where these
  jobs' tokens live, and secret masking covers the log rather than an issue
  comment the agent writes. Since the support ticket extract, the read
  tools are allowed and those three paths are denied by name instead —
  which moves the silent failure one step over: a path rule with a typo
  denies nothing and says so nowhere, which is why the exact rules are
  asserted in the permissions test rather than read by a reviewer.
- **A missing repository variable or secret is the empty string, not an
  error, and the extract step is green without it.** `SUPPORT_SITE_URL`
  unset, `SUPPORT_TRIAGE_TOKEN` unset, a token revoked on the support
  site: each means the triage runs on the issue alone, which is also
  what it does when no reference was cited — so a misconfiguration looks
  exactly like the ordinary case. The step prints one line naming which
  it was; nothing else does. If reporters who cite references keep being
  asked for the archive's contents, read that step's log first.
- **`claude-code-action` exits 0 on an agent that did nothing.** The step
  reports success whenever the agent's turn ends normally, and an agent
  that read the issue, gave up and said so ended normally. No timeout, no
  `error_max_turns`, no permission denial, no annotation — a green tick on
  an issue nobody answered. It applies just as much to an agent that did
  *part* of the job: the backlog scan's first run triaged one candidate of
  four and reported success. Worse, the transcript that would say why is
  discarded unless `show_full_output` is on. This is why both issue
  workflows read GitHub's own state back instead of trusting their own
  step, and why both retries print in full.
- **A missing step output is the empty string, not an error.** Delete the
  step that publishes `steps.before.outputs.expected` and every expression
  reading it silently becomes `''` — comparisons still evaluate, shell
  arithmetic still runs, and the job decides its verdict against nothing.
  Nothing in GitHub Actions warns about a reference to an output no step
  produces.
- A **scheduled job is deferred or dropped under load**, most of all at
  the top of the hour where every `0 *` cron fires at once. Nothing
  reports the drop; the run simply never happens. This is why
  `issue-backlog-scan.yml` asks for minute 17.
- A **`schedule:` trigger only ever runs from the default branch**, so a
  change to `issue-backlog-scan.yml` on a pull request branch proves the
  YAML parses and nothing more. Its `workflow_dispatch:` is not a
  convenience — it is the only way to find out whether the workflow works.
- On a public repository, GitHub **silently disables a scheduled workflow
  after 60 days without commit activity**. No email, no failing run, no
  annotation: the nightly scan simply stops happening and the backlog
  quietly stops being triaged. A repository between releases reaches this
  easily. If issues stop getting verdicts, look at the Actions tab first.
- A scheduled run is **attributed to the last account that edited the cron
  line**, not to whoever merged it. The action refuses to act for an actor
  without write access, so a bot editing that one line would turn every
  subsequent nightly run into a successful no-op.
  `allowed_non_write_users: "*"` with an explicit `github_token` is what
  neutralises this — the same pair that lets members of the public have
  their issues triaged at all.

The habit that catches these is cheap: ask what a green result would look
like if the thing had not run at all. When the answer is "the same", the
signal is not a signal. Where that distinction is known, it is written down
next to the thing it concerns — in the steward skill, in
`claude-review.yml`'s header, and here.
