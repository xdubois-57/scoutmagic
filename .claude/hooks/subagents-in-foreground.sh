#!/bin/bash
#
# PreToolUse hook — in a one-shot automated run, every subagent starts in the
# FOREGROUND, whether or not whoever launched it said so.
#
# WHY THIS IS A HOOK AND NOT A SENTENCE IN A PROMPT.
# `.github/workflows/claude-review.yml` has told the reviewer to "pass
# run_in_background false" since 2026-09-08, and the review still stopped
# part-way through 35 times in the 300 runs to 2026-09-20 — every sampled one
# of them on the same signal, agents launched and never collected. The
# transcripts say why, and they say it in two different ways:
#
#   run 35514703160  "requested": {"background":0,"foreground":0,"unset":2}
#                    "started_in_background": 2, "completed": 0
#                    result: "Two background agents are now running … I'll
#                    wait for both to complete before proceeding."
#
#   run 35515224252  "requested": {"background":5,"foreground":3}
#                    "started_in_background": 5, "completed": 2
#                    result: "All 5 review agents … are running in the
#                    background. I'll wait for them to finish."
#
# The first is the one that matters: the flag was not set to `true`, it was
# NOT SET AT ALL, and a subagent whose caller says nothing starts in the
# background. The instruction is obeyed only when a model remembers to type
# one extra field, and the unsafe value is the default — so every launch that
# forgets it is a part of the diff nobody reads, in silence. An allowlist
# cannot refuse a default, which is why naming tools (`ScheduleWakeup`,
# `Monitor`) closed two doors and not the room.
#
# A workflow run has no later turn. Nothing wakes it up, so an agent still
# running when the orchestrator stops is simply lost: the SDK closes the run
# `subtype: success` and the findings that agent would have posted do not
# exist. Run 35515224252 shows the reviewer trying to wait and having nothing
# to wait WITH — it called `ListAgents` (which only reports), then ran
# `Bash true` described as "no-op, waiting for background agents to complete",
# which returns instantly, and then ended its turn.
#
# IT IS ALSO THE CHEAPEST FIX ON THE TABLE. Across twelve complete runs, cost
# tracks TURNS (r = 0.86) and not agent count (r = 0.06) — and the high-turn
# runs are exactly the backgrounded ones, because a main thread with no
# blocking primitive polls its agents turn after turn, re-sending the
# conversation each time. 608 turns and 24.23 USD with 9 agents in the
# background; 15 to 38 turns and 3 to 11 USD with the same number in the
# foreground.
#
# WHAT IT COSTS, written down because it is a real grant. `updatedInput` is
# honoured by Claude Code only on an `allow` decision, so this hook also
# ALLOWS the Agent/Task call it rewrites — it therefore bypasses the
# permission allowlist for those two tools, and only for them. That is not a
# widening in the review job, which grants `Task` and `Agent` outright in
# `claude_args`, and subagents inherit that same allowlist for everything
# they go on to do. But it does mean that REMOVING `Agent` from that list
# would no longer stop an agent from being launched here. If the day comes to
# deny the reviewer its subagents, delete this hook in the same change.
#
# SCOPE: THE WORKFLOW HAS TO ASK FOR THIS, and that is not caution, it is a
# hole found before this hook shipped. Because the rewrite needs an `allow`,
# a hook that fired on every workflow run would GRANT `Agent`/`Task`
# everywhere — including in `.github/workflows/issue-triage.yml`, which
# denies both on purpose:
#
#     --disallowedTools "Agent,Task,ScheduleWakeup,Bash,Write,…"
#
# That denial is itself the fix for a real incident (2026-09-05: a backlog
# scan spawned three `Agent` subagents, scheduled a wake-up, ended its turn,
# and three reporters got nothing). A hook meant to stop lost subagents would
# have re-opened the door to exactly that, in another workflow, silently.
#
# So the grant is opt-in: this hook does nothing unless the job that runs
# Claude sets `CLAUDE_SUBAGENTS_FOREGROUND=true` in its own environment.
# `GITHUB_ACTIONS` is still required alongside it, so that an exported
# variable on a laptop cannot change how an interactive session behaves —
# and an interactive session has a later turn and a person to wake it, so
# the defect this hook exists for cannot happen there anyway.
#
# Adding the variable to a workflow is therefore a decision with two parts:
# every subagent runs in the foreground, AND `Agent`/`Task` are granted
# there regardless of the allowlist. Do not add it to a job that means to
# deny them.
#
# It must never break a session it cannot help. Every failure below exits 0
# with no output, which Claude Code reads as "this hook has nothing to say" —
# the launched-against-finished guard in claude-review.yml still catches the
# truncation if this hook is absent, unreadable or silently wrong, which is
# why that guard stays rather than being retired in favour of this.
#
# Tests\Architecture\SubagentsStartInForegroundTest runs this file against
# real hook payloads, including the two shapes quoted above.
#
# Deliberately not `set -e`: a hook that dies mid-script must still exit 0.
set -uo pipefail

# An interactive session keeps its background agents; this is only about a
# run that cannot be resumed, and only where that run has asked for it.
if [ "${GITHUB_ACTIONS:-}" != "true" ]; then
  exit 0
fi

if [ "${CLAUDE_SUBAGENTS_FOREGROUND:-}" != "true" ]; then
  exit 0
fi

# No jq, no rewrite. Saying nothing leaves the call exactly as it was, which
# is the behaviour this repository had before this hook existed.
command -v jq >/dev/null 2>&1 || exit 0

payload="$(cat)" || exit 0
[ -n "${payload}" ] || exit 0

tool_name="$(jq -r 'if type == "object" then (.tool_name // "") else "" end' <<<"${payload}" 2>/dev/null)" || exit 0

# `Task` is the older spelling of the same tool and both still appear in this
# repository's transcripts, so neither name may be the only one here.
case "${tool_name}" in
  Agent | Task) ;;
  *) exit 0 ;;
esac

# `.tool_input + {run_in_background: false}` rather than a targeted edit: the
# whole input is returned with one field overridden, so a launch that already
# asked for the foreground is unchanged and one that said nothing is answered.
jq -ce '
  if (.tool_input | type) == "object" then
    {
      hookSpecificOutput: {
        hookEventName: "PreToolUse",
        permissionDecision: "allow",
        permissionDecisionReason:
          "Subagents run in the foreground in a workflow run: nothing can wake this run up to collect a background one.",
        updatedInput: (.tool_input + {run_in_background: false})
      }
    }
  else
    empty
  end
' <<<"${payload}" 2>/dev/null || exit 0

exit 0
