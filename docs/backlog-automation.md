# Backlog automation — GitHub Project #1, « fix the backlog » and its hourly watch

Everything the maintainer needs to run, observe and stop the backlog agents
(issue #923). The rules the agents follow are in `AGENTS.md` § "Fix the
backlog"; this file is the setup behind them. The map of CI and its workflows is
`docs/quality-pipeline.md`.

## What is the source of truth

[ScoutMagic Project #1](https://github.com/users/xdubois-57/projects/1) plans
the work. **Status** is a single select:

| Status        | Meaning                                                                     |
| ------------- | --------------------------------------------------------------------------- |
| `Todo`        | Known, not authorised. `fix the backlog` never develops it, whatever its labels. |
| `Ready`       | **The maintainer authorised it.** The only status that starts work.         |
| `In Progress` | An agent holds it (see `Agent`).                                            |
| `Done`        | Delivered: the issue closed as completed.                                   |
| `Canceled`    | Given up on purpose. The issue is closed as *not planned* within the hour.  |

`status:accepted` is a legacy label: it stays on old issues and nothing reads it.

Other fields:

| Field      | Type          | Written by                                                     |
| ---------- | ------------- | -------------------------------------------------------------- |
| `Priority` | single select (`P0`…`P3`) | **The maintainer, only.** Read by the queue to order work; never computed, proposed or written by the triage, a sync or an agent. |
| `Effort`   | single select (`S`, `M`, `L`, spelled exactly) | The triage fills it **only when empty**; a value the maintainer set is never overwritten. |
| `Agent`    | **text**      | The agent that holds the issue (`Claude A1`, `Claude A2`, `Codex B1`…), cleared on Done or release. |

Create `Agent` as a **Text** field named exactly `Agent`, and make sure the
`Status` options are spelled exactly as above — the scripts look them up by name
and fail loudly (`Status option missing`, `Agent field missing`) rather than guess.

## Credentials

| Who                                   | Credential                                   | Why |
| ------------------------------------- | -------------------------------------------- | --- |
| Workflows that touch the Project      | Actions secret **`PROJECTS_TOKEN`**: a classic personal access token with the **`project`** scope, owned by an account that may edit Project #1 | `github.token` has no permission on a user-owned Project. It is read only by the one step of each workflow that needs it, and never by a model. |
| A developer's or agent's own `gh`     | `gh auth login` with **`read:project`** (and `project` to write) | `bash scripts/backlog-ready-queue.sh` reads the Project with it. |
| An agent session **without `gh`**     | none — it dispatches `.github/workflows/backlog-project.yml` | The workflow holds the token; the agent reads the answer from the run's log. |

Never paste `PROJECTS_TOKEN` into a session, a prompt or an issue. If the
Project cannot be read, the queue is **unknown**, not empty: the script says so
and exits non-zero, and nothing falls back to labels.

## Without `gh`: the `backlog-project.yml` workflow

Dispatch it (GitHub MCP `actions_run_trigger`, or `gh workflow run backlog-project.yml -f action=… -f issue=… -f agent=…`):

| `action`  | Inputs         | Does                                                                           |
| --------- | -------------- | ------------------------------------------------------------------------------ |
| `queue`   | —              | Prints the Ready queue between `QUEUE-JSON-BEGIN` and `QUEUE-JSON-END` in the log (and the run summary). |
| `status`  | `issue`        | Prints the Project's view of one issue — how an agent sees a Canceled before arming an auto-merge. |
| `claim`   | `issue`, `agent` | Ready → In Progress and `Agent`, **only if** the branch `claude/issue-<n>` exists and nobody else is recorded. |
| `done`    | `issue`, `agent` | → Done, `Agent` cleared, **only if** the issue closed as completed.            |
| `release` | `issue`, `agent` | An `In Progress` issue goes back to Ready; any other status (Todo, Canceled…) is left as it is — Ready is never written on the maintainer's behalf. `Agent` cleared, only for the holder. |

None of these is the lock. The lock is the branch `claude/issue-<n>`, created
atomically through the API; the Project only reports it, and the scripts refuse
to contradict it. The run refuses any ref but `main`.

## Reading the queue

`scripts/backlog-ready-queue.sh` prints JSON:

- `ready` — executable now, best first;
- `reserved` — executable, but a peer's `claude/issue-<n>` branch exists;
- `blocked` — Ready, waiting on an open issue (`blockers[].ready` says whether
  the prerequisite is itself Ready);
- `needs_ready` — prerequisites that hold Ready work back and are **not**
  Ready. They are not promoted: moving them to Ready is the maintainer's call;
- `in_progress` — what is held, and by which agent.

Order: P0 → P3, no Priority last; a prerequisite inherits the urgency of the
best Ready issue that needs it, along the whole chain of native `blocked by`
links; ties go to what unblocks the most Ready work, then to the oldest.

## The hourly watch

`fix the backlog` does not end on an empty queue: it sleeps an hour, re-reads the
Project, and carries on. Two mechanisms, and they are not equivalent.

**A session loop** — free, and as durable as the session.
`/loop 1h fix the backlog` runs the first pass immediately and re-fires every
hour while the Claude Code session lives (its prompt is `.claude/loop.md`).
**It ends with the session and expires after seven days.** It is for a day's
work, not for « always ».

**A Claude Code Routine** — the durable one. Create a Routine (the scheduled-tasks feature of Claude Code on the web, or a
session's scheduled-tasks tool) with:

- schedule: hourly (the minimum interval is one hour);
- environment: this repository, with a GitHub connection that can push branches,
  read Actions logs and dispatch workflows;
- prompt: `Follow AGENTS.md § "Fix the backlog" and § "Staying on watch" for
  xdubois-57/scoutmagic. This is a scheduled run: take what is Ready, and report
  concisely when nothing can be taken.`;
- a name that says what it is — **`Fix the backlog — hourly`** — and exactly one
  of them. Look at the existing routines before creating one; two identical
  routines would run two agents on the same queue (the atomic claim keeps that
  safe but wastes the second).

Each run is a fresh session, so nothing accumulates, and the atomic
`claude/issue-<n>` claim makes overlapping runs safe: a run that finds every
Ready issue reserved reports it and ends. **A run that fails shows red in the
Routine's run list and the next hour simply starts over** — that is the whole
failure strategy; check the list now and then, and note that a run of an empty
queue costs one short session. Nothing in this repository can install the
Routine, which is why it is not claimed to be installed.

### Seeing it, stopping it

- **Session loop**: `/loop` lists the session's scheduled tasks; stop it by
  asking the agent, then check the listing — a task still listed is still armed.
- **Routine**: the Routine's page shows the schedule, the last runs and a
  pause/delete control. Pause or delete it there, then confirm the next run
  time has gone. Sessions it already started finish on their own; their
  tickets are released with `release`, or by finishing.
- Nothing stops the watch except the maintainer: an empty queue, a blocked
  ticket, an unclear ticket or a failed run does not.

## Behind the scenes: the triage and the syncs

| Workflow / script | Credential | What it writes |
| --- | --- | --- |
| `issue-triage.yml`, `issue-backlog-scan.yml` | model: read tools only | nothing — it returns JSON: verdict, comment, `effort`, `category`, `blocked_by` |
| same workflows, *Normalise the title…* step | `github.token` (`issues: write`) | the `[Bug]`/`[Test]`/`[Idée]` title prefix (`scripts/triage-title.sh`) and the native `blocked by` links (`scripts/triage-dependencies.sh`) |
| same workflows, *Set Effort…* step | `PROJECTS_TOKEN` | `Effort`, only when empty (`scripts/project-effort.sh`) |
| `project-canceled-sync.yml` (hourly) | `PROJECTS_TOKEN` to list, `github.token` to close | closes issues whose Status is `Canceled` as `not_planned`, with a French note |
| `backlog-project.yml` (dispatch) | `PROJECTS_TOKEN` | Status / Agent of the one issue given |
| `project-backfill.yml` (dispatch, once) | both, one per step | catch-up of `Effort` and of #740 ← #724 from `scripts/project-backfill.json` |

**Canceled takes up to an hour** to close the issue: GitHub sends no event for a
change in a user-owned Project, so the workflow looks hourly. Run it from the
Actions tab to make it immediate. An issue reopened while still `Canceled` is
closed again at the next run — change its Status.

Title prefixes: `[Bug]` only for a verdict `bug:confirmed`; `[Test]` when the
ticket's main object is tests; `[Idée]` for everything else. The rest of the
title is never touched. Dependencies are added only when the issue text proves a
prerequisite, never for a pull request, a closed issue, a parent ticket or a
cycle, and a link is never removed.

## Checking the scripts

`tests/Security/BacklogProjectScriptsTest.php` runs every `scripts/*.test.sh` of
this family offline (`gh` is replaced by a fixture) as part of the normal suite:
`vendor/bin/phpunit tests/Security`. They cannot prove the Project's real field
names and options are what the scripts expect — that is the first run's job, and
it fails with a message naming what is missing.
