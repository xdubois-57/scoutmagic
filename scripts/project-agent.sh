#!/usr/bin/env bash
set -euo pipefail

# Keep ScoutMagic Project #1 truthful about who is working on what (issue
# #923 § 3): the `Status` and the `Agent` field of ONE issue, moved through
# the life of a « fix the backlog » claim.
#
#   claim   <issue> <agent>   Ready → In Progress, Agent = <agent>
#   done    <issue> <agent>   after the issue CLOSED as completed → Done, Agent cleared
#   release <issue> <agent>   giving up → an In Progress issue goes back to
#                             Ready; any other status (Todo, Canceled…) is
#                             left alone; Agent cleared
#   status  <issue>           read-only: prints the Project's view of the issue
#
# NONE OF THIS IS A LOCK. The lock is the branch `claude/issue-<n>`, created
# atomically through the API by the agent that won it; the Project and its
# labels only report. So this script never decides who may work — it refuses
# to CONTRADICT the lock:
#   - `claim` needs the branch to exist, and the Project's Agent not to name
#     someone else: an active agent's reservation is never taken over, however
#     stale it looks;
#   - `release` and `done` only act for the agent recorded, and never touch
#     an issue that is not theirs;
#   - nothing here assigns the issue to a GitHub user — an assignee would start
#     a native agent session, and Assignees keeps its ordinary meaning;
#   - `release` only turns In Progress back into Ready: a Todo issue is never
#     promoted, and a Canceled one is never moved out of Canceled;
#   - `Done` is written only for an issue GitHub shows closed as `completed`.
# Every write is idempotent, addresses the one issue it was given, and is read
# back; a write that does not show up on the read is a failure.
#
# Needs a token that can WRITE the user-owned Project; in Actions that is
# PROJECTS_TOKEN as GH_TOKEN, held by the one step that runs this.
#
# Exit status: 0 done · 1 failure · 2 bad usage · 3 refused on purpose.
#
# Usage: GH_TOKEN=… bash scripts/project-agent.sh <claim|done|release> <issue> <agent>
#        GH_TOKEN=… bash scripts/project-agent.sh status <issue>

command -v gh >/dev/null || { echo 'ERROR: GitHub CLI (gh) is required.' >&2; exit 1; }
command -v jq >/dev/null || { echo 'ERROR: jq is required.' >&2; exit 1; }

# shellcheck source=scripts/project-lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/project-lib.sh"

readonly STATUS_IN_PROGRESS='In Progress'

action="${1:-}"
issue="${2:-}"
agent="${3:-}"

case "${action}" in
  claim|done|release) [[ "$#" -eq 3 ]] || { echo "Usage: project-agent.sh ${action} <issue> <agent>" >&2; exit 2; } ;;
  status) [[ "$#" -eq 2 ]] || { echo 'Usage: project-agent.sh status <issue>' >&2; exit 2; } ;;
  *) echo 'Usage: project-agent.sh <claim|done|release|status> <issue> [agent]' >&2; exit 2 ;;
esac
case "${issue}" in ''|*[!0-9]*|0) echo 'Invalid issue number.' >&2; exit 2 ;; *) ;; esac
if [[ "${action}" != 'status' ]]; then
  # A label for a session — « Claude A1 », « Codex B1 » — never free text:
  # it ends up in a Project field and in logs.
  [[ "${agent}" =~ ^[A-Za-z][A-Za-z0-9\ ._-]{0,39}$ ]] || { echo 'Invalid agent identity (letters, digits, space . _ -; at most 40).' >&2; exit 2; }
fi
if [[ "${GITHUB_REPOSITORY:-${PROJECT_REPO}}" != "${PROJECT_REPO}" ]]; then
  echo 'Refusing to write to a different repository.' >&2
  exit 2
fi

item_of() { # item_of <issue> → the Project item JSON, or empty
  project_items | jq -c --argjson n "${issue}" --arg repo "${PROJECT_REPO}" \
    '[.[] | select(.issue == $n and .repo == $repo)] | first // empty'
}

item="$(item_of)" || { echo '::error title=Project unreadable::Cannot read Project #1.' >&2; exit 1; }
if [[ -z "${item}" ]]; then
  echo "Issue #${issue} is not on Project #1; nothing to synchronise." >&2
  exit 1
fi

status_now="$(jq -r '.status // ""' <<<"${item}")"
agent_now="$(jq -r '.agent // ""' <<<"${item}")"
state_now="$(jq -r '.state' <<<"${item}")"
item_id="$(jq -r '.item_id' <<<"${item}")"

if [[ "${action}" = 'status' ]]; then
  jq -c '{issue, state, state_reason, status, agent, priority, effort}' <<<"${item}"
  exit 0
fi

meta="$(project_meta)" || exit 1
project_id="$(jq -r '.project_id' <<<"${meta}")"
status_field="$(jq -r '.fields.Status.id // empty' <<<"${meta}")"
agent_field="$(jq -r '.fields.Agent.id // empty' <<<"${meta}")"
[[ -n "${status_field}" ]] || { echo '::error title=Status field missing::Project #1 has no Status field.' >&2; exit 1; }
[[ -n "${agent_field}" ]] || { echo '::error title=Agent field missing::Create a text field named Agent on Project #1.' >&2; exit 1; }

option_id() {
  local option_name="$1"
  jq -r --arg name "${option_name}" '.fields.Status.options[$name] // empty' <<<"${meta}"
}

set_status() { # set_status <option name>
  local option_name="$1"
  local id; id="$(option_id "${option_name}")"
  [[ -n "${id}" ]] || { echo "::error title=Status option missing::Status has no '${option_name}' option." >&2; exit 1; }
  project_set_option "${project_id}" "${item_id}" "${status_field}" "${id}" || { echo "::error title=Project write failed::Could not set Status to ${option_name} on #${issue}." >&2; exit 1; }
}
set_agent() {
  local agent_name="$1"
  if [[ "$(jq -r '.fields.Agent.options | length' <<<"${meta}")" -gt 0 ]]; then
    local id; id="$(jq -r --arg a "${agent_name}" '.fields.Agent.options[$a] // empty' <<<"${meta}")"
    [[ -n "${id}" ]] || { echo "::error title=Agent option missing::The Agent field has no option '${agent_name}'." >&2; exit 1; }
    project_set_option "${project_id}" "${item_id}" "${agent_field}" "${id}"
  else
    project_set_text "${project_id}" "${item_id}" "${agent_field}" "${agent_name}"
  fi || { echo "::error title=Project write failed::Could not set Agent on #${issue}." >&2; exit 1; }
}
clear_agent() { project_clear_field "${project_id}" "${item_id}" "${agent_field}" || { echo "::error title=Project write failed::Could not clear Agent on #${issue}." >&2; exit 1; }; }
refuse() { echo "Refused: $*" >&2; exit 3; }

verify() { # verify <status> <agent>   (agent '' = cleared)
  local want_status="$1" want_agent="$2" now
  now="$(item_of)" || exit 1
  if [[ "$(jq -r '.status // ""' <<<"${now}")" != "${want_status}" ]] || [[ "$(jq -r '.agent // ""' <<<"${now}")" != "${want_agent}" ]]; then
    echo "::error title=Project write not confirmed::Re-reading #${issue} does not show Status='${want_status}' Agent='${want_agent}'." >&2
    exit 1
  fi
}

case "${action}" in
  claim)
    [[ "${state_now}" = 'OPEN' ]] || refuse "#${issue} is not open."
    [[ "${status_now}" != 'Canceled' ]] || refuse "#${issue} was canceled."
    # The lock must exist: no branch, no claim.
    if ! gh api "repos/${PROJECT_REPO}/git/ref/heads/claude/issue-${issue}" --silent 2>/dev/null; then
      refuse "branch claude/issue-${issue} does not exist, so the issue was never reserved."
    fi
    if [[ -n "${agent_now}" ]] && [[ "${agent_now}" != "${agent}" ]]; then
      refuse "#${issue} is held by '${agent_now}'; a reservation is never taken over."
    fi
    case "${status_now}" in
      Ready|"${STATUS_IN_PROGRESS}") ;;
      *) refuse "#${issue} is '${status_now}', not Ready." ;;
    esac
    [[ "${status_now}" = "${STATUS_IN_PROGRESS}" ]] || set_status "${STATUS_IN_PROGRESS}"
    [[ "${agent_now}" = "${agent}" ]] || set_agent "${agent}"
    verify "${STATUS_IN_PROGRESS}" "${agent}"
    echo "#${issue} is In Progress, held by ${agent}."
    ;;
  done)
    [[ "${status_now}" != 'Canceled' ]] || refuse "#${issue} was canceled, not delivered."
    [[ "$(jq -r '.state' <<<"${item}")" = 'CLOSED' ]] && [[ "$(jq -r '.state_reason' <<<"${item}")" = 'COMPLETED' ]] \
      || refuse "#${issue} is not closed as completed yet; Done means delivered."
    if [[ -n "${agent_now}" ]] && [[ "${agent_now}" != "${agent}" ]]; then
      refuse "#${issue} is recorded against '${agent_now}', not '${agent}'."
    fi
    [[ "${status_now}" = 'Done' ]] || set_status 'Done'
    [[ -z "${agent_now}" ]] || clear_agent
    verify 'Done' ''
    echo "#${issue} is Done."
    ;;
  release)
    [[ "${status_now}" != 'Done' ]] || refuse "#${issue} is already Done."
    if [[ -n "${agent_now}" ]] && [[ "${agent_now}" != "${agent}" ]]; then
      refuse "#${issue} is held by '${agent_now}', not '${agent}'; only the holder releases."
    fi
    # Only undo OUR claim. Todo, Ready, Canceled, or anything else the
    # maintainer set stays exactly as it is: Ready is their decision and is
    # never written on their behalf (a Todo issue released by an agent must
    # not become eligible for every other agent).
    target="${status_now}"
    if [[ "${status_now}" = "${STATUS_IN_PROGRESS}" ]]; then
      target='Ready'
      set_status 'Ready'
    fi
    [[ -z "${agent_now}" ]] || clear_agent
    verify "${target}" ''
    echo "#${issue} released: ${target}, no agent."
    ;;
  *) ;;
esac
