#!/usr/bin/env bash
set -euo pipefail

# Offline test for scripts/project-agent.sh: `gh` is a stateful fixture, so a
# write that did not happen shows up on the read-back, as it would for real.
# Run by tests/Security/BacklogProjectScriptsTest.php.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
mkdir -p "${tmp}/s"

# state per issue: file status, agent, state, reason
seed() { # seed <n> <status> <agent> <state> <reason> <branch yes|no>
  printf '%s' "$2" >"${tmp}/s/$1.status"; printf '%s' "$3" >"${tmp}/s/$1.agent"
  printf '%s' "$4" >"${tmp}/s/$1.state"; printf '%s' "$5" >"${tmp}/s/$1.reason"
  [ "$6" = yes ] && : >"${tmp}/s/$1.branch" || rm -f "${tmp}/s/$1.branch"
}
seed 10 Ready ''           OPEN   ''        yes
seed 11 'In Progress' 'Claude A1' OPEN '' yes
seed 12 Ready ''           OPEN   ''        no
seed 13 Canceled ''        OPEN   ''        yes
seed 14 'In Progress' 'Claude A1' CLOSED COMPLETED yes
seed 15 'In Progress' 'Claude A1' CLOSED NOT_PLANNED yes
seed 16 Todo ''            OPEN   ''        yes
seed 17 'In Progress' 'Claude A1' OPEN '' yes
seed 18 Canceled 'Claude A1' OPEN '' yes

cat >"${tmp}/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
d="$(dirname "$0")/s"
echo "$*" >>"$(dirname "$0")/calls.log"
arg() { sed -nE "s/.*(^| )-[fF] $1=([^ ]*).*/\2/p" <<<"$ALLARGS" | head -1; }
ALLARGS="$*"
case "$*" in
  *'items(first'*)
    for f in "$d"/*.status; do n="$(basename "$f" .status)"
      jq -nc --argjson n "$n" --arg st "$(cat "$f")" --arg ag "$(cat "$d/$n.agent")" --arg state "$(cat "$d/$n.state")" --arg reason "$(cat "$d/$n.reason")" '
        {id: "ITEM\($n)", content: {__typename: "Issue", number: $n, title: "t", url: "u", state: $state,
          stateReason: (if $reason == "" then null else $reason end), repository: {nameWithOwner: "xdubois-57/scoutmagic"}},
         fieldValues: {pageInfo: {hasNextPage: false}, nodes:
           ([{name: $st, field: {name: "Status"}}] + (if $ag != "" then [{text: $ag, field: {name: "Agent"}}] else [] end))}}'
    done | jq -sc '{data: {user: {projectV2: {id: "P", items: {pageInfo: {hasNextPage: false, endCursor: ""}, nodes: .}}}}}' ;;
  *'fields(first'*)
    jq -nc '{data: {user: {projectV2: {id: "P", fields: {pageInfo: {hasNextPage: false}, nodes: [
      {id: "FSTATUS", name: "Status", options: [{id: "O_Todo", name: "Todo"}, {id: "O_Ready", name: "Ready"}, {id: "O_Prog", name: "In Progress"}, {id: "O_Done", name: "Done"}, {id: "O_Canc", name: "Canceled"}]},
      {id: "FAGENT", name: "Agent", dataType: "TEXT"},
      {id: "FPRIO", name: "Priority", options: [{id: "P0", name: "P0"}]}]}}}}}' ;;
  *updateProjectV2ItemFieldValue*)
    item="$(arg item)"; n="${item#ITEM}"; field="$(arg field)"
    [ -f "$d/../fail-write" ] && exit 1
    if [ "$field" = FSTATUS ]; then
      case "$(arg option)" in O_Ready) v=Ready;; O_Prog) v='In Progress';; O_Done) v=Done;; *) exit 1;; esac
      printf '%s' "$v" >"$d/$n.status"
    elif [ "$field" = FAGENT ]; then
      printf '%s' "$(sed -nE 's/.*-f text=(.*) --jq.*/\1/p' <<<"$ALLARGS" | head -1)" >"$d/$n.agent"
    else echo "WRITE TO UNEXPECTED FIELD $field" >&2; exit 7; fi
    echo "$item" ;;
  *clearProjectV2ItemFieldValue*)
    item="$(arg item)"; n="${item#ITEM}"
    [ "$(arg field)" = FAGENT ] || { echo "CLEAR OF UNEXPECTED FIELD" >&2; exit 7; }
    : >"$d/$n.agent"; echo "$item" ;;
  *git/ref/heads/claude/issue-*)
    n="$(sed -E 's#.*issue-([0-9]+).*#\1#' <<<"$*")"
    if [ -f "$d/$n.branch" ]; then exit 0; else echo 'gh: Not Found (HTTP 404)' >&2; exit 1; fi ;;
  *) echo "Unexpected gh call: $*" >&2; exit 2 ;;
esac
MOCK
chmod +x "${tmp}/gh"

fail() { echo "FAIL: $*" >&2; exit 1; }
agent_run() { # prints the exit code; output in ${tmp}/out
  set +e
  PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic bash "${root}/scripts/project-agent.sh" "$@" >"${tmp}/out" 2>&1
  local rc=$?; set -e; echo "${rc}"
}
st() { cat "${tmp}/s/$1.status"; }
ag() { cat "${tmp}/s/$1.agent"; }
expect_rc() { [ "$1" = "$2" ] || { cat "${tmp}/out" >&2; fail "$3 (exit $1, wanted $2)"; }; }

# claim: Ready → In Progress, agent recorded, and repeating it is harmless.
expect_rc "$(agent_run claim 10 'Claude A2')" 0 'claim'
[ "$(st 10)" = 'In Progress' ] && [ "$(ag 10)" = 'Claude A2' ] || fail 'claim did not move Status/Agent'
: >"${tmp}/calls.log"
expect_rc "$(agent_run claim 10 'Claude A2')" 0 'claim again'
grep -q 'updateProjectV2ItemFieldValue' "${tmp}/calls.log" && fail 'an idempotent claim wrote again'

# A reservation is never taken over: another agent cannot claim, release or finish it.
expect_rc "$(agent_run claim 11 'Claude B1')" 3 'stealing a claim'
expect_rc "$(agent_run release 11 'Claude B1')" 3 'releasing someone else claim'
[ "$(st 11)" = 'In Progress' ] && [ "$(ag 11)" = 'Claude A1' ] || fail "another agent's claim was altered"
# No branch, no claim. Todo and Canceled are not claimable.
expect_rc "$(agent_run claim 12 'Claude A2')" 3 'claim without a reservation branch'
expect_rc "$(agent_run claim 13 'Claude A2')" 3 'claim of a canceled issue'
expect_rc "$(agent_run claim 16 'Claude A2')" 3 'claim of a Todo issue'
[ "$(st 12)" = Ready ] && [ "$(st 13)" = Canceled ] && [ "$(st 16)" = Todo ] || fail 'a refused claim changed the Project'

# done: only for an issue closed as completed; clears the agent.
expect_rc "$(agent_run done 17 'Claude A1')" 3 'done on an open issue'
expect_rc "$(agent_run done 15 'Claude A1')" 3 'done on a not-planned closure'
expect_rc "$(agent_run done 14 'Claude A1')" 0 'done'
[ "$(st 14)" = Done ] && [ -z "$(ag 14)" ] || fail 'done did not set Done and clear the agent'
expect_rc "$(agent_run done 18 'Claude A1')" 3 'done on a canceled issue'

# release: back to Ready, agent cleared; a Canceled issue stays Canceled.
expect_rc "$(agent_run release 17 'Claude A1')" 0 'release'
[ "$(st 17)" = Ready ] && [ -z "$(ag 17)" ] || fail 'release did not return the issue to Ready'
expect_rc "$(agent_run release 18 'Claude A1')" 0 'release of a canceled issue'
[ "$(st 18)" = Canceled ] && [ -z "$(ag 18)" ] || fail 'release moved a canceled issue'

# status is read-only.
: >"${tmp}/calls.log"
expect_rc "$(agent_run status 13)" 0 'status'
jq -e '.status == "Canceled"' "${tmp}/out" >/dev/null || fail 'status does not report Canceled'
grep -q 'mutation\|ProjectV2ItemFieldValue' "${tmp}/calls.log" && fail 'status wrote'

# A write that does not stick is a failure, not a success.
seed 10 Ready '' OPEN '' yes
touch "${tmp}/fail-write"
expect_rc "$(agent_run claim 10 'Claude A2')" 1 'a failed write'
rm -f "${tmp}/fail-write"

# Priority is never addressed (the mock aborts on any other field), nor Assignees.
grep -qi 'assignee\|Priority' "${tmp}/calls.log" && fail 'something other than Status/Agent was addressed'

# Bad input.
: >"${tmp}/calls.log"
expect_rc "$(agent_run claim abc 'Claude A1')" 2 'bad issue'
expect_rc "$(agent_run claim 10 'bad;agent')" 2 'bad agent identity'
expect_rc "$(agent_run claim 10 '')" 2 'empty agent'
expect_rc "$(agent_run frob 10 'Claude A1')" 2 'bad action'
[ ! -s "${tmp}/calls.log" ] || fail 'bad input reached gh'

echo 'Project agent tests passed.'
