#!/usr/bin/env bash
set -euo pipefail

# The queue « fix the backlog » works from (issue #923): the open issues whose
# Status is `Ready` on ScoutMagic Project #1, ordered, with the ones that
# cannot start set aside. READ-ONLY — nothing is written anywhere.
#
# Authorization: Status = Ready is the maintainer's decision and the only
# thing that selects work. `status:accepted` and `bug:confirmed` select
# nothing, and a Project that cannot be read is an ERROR, never an empty
# queue: the script exits non-zero and says why, and nobody falls back to
# labels.
#
# Order: priority P0 → P3 (no Priority last), but a prerequisite INHERITS the
# urgency of the best Ready issue that needs it, along the whole chain of
# GitHub's native `blocked by` links — including through a link whose middle
# issue is not Ready, because the prerequisite is needed all the same. Ties go
# to the issue that unblocks the most Ready work, then to its own priority,
# then to the oldest. A blocker that is closed no longer blocks. The
# inheritance is a calculation of this run; Priority is never written.
#
# Credentials: whatever `gh` is logged in as, which must be allowed to READ
# the user-owned Project. In an Actions step that is PROJECTS_TOKEN as GH_TOKEN,
# set by the step itself; it is never copied into an agent's shell.
#
# Output (stdout, JSON):
#   ready        executable now, best first
#   reserved     executable but `claude/issue-<n>` already exists (a peer holds it)
#   blocked      Ready, but waiting on an open issue; `blockers[].ready` says
#                whether the prerequisite is itself Ready
#   needs_ready  prerequisites that block Ready work but are not Ready: the
#                maintainer's decision, never made on their behalf
#   in_progress  what is being worked on, and by which agent

command -v gh >/dev/null || { echo 'ERROR: GitHub CLI (gh) is required.' >&2; exit 1; }
command -v jq >/dev/null || { echo 'ERROR: jq is required.' >&2; exit 1; }

# shellcheck source=scripts/project-lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/project-lib.sh"

MAX_GRAPH_NODES="${BACKLOG_MAX_GRAPH_NODES:-300}"

items="$(project_items)" || {
  echo 'ERROR: The Project could not be read, so the queue is UNKNOWN, not empty.' >&2
  echo '       Give this gh login permission to read GitHub Projects (scope read:project).' >&2
  exit 1
}

ready_numbers="$(jq -c --arg repo "${PROJECT_REPO}" \
  '[.[] | select(.status == "Ready" and .repo == $repo and .state == "OPEN") | .issue] | unique' <<<"${items}")"

# Breadth-first walk of `blocked by`, so a chain is followed through every
# link. An unreadable dependency list is an error: guessing "no blockers"
# would start work on an issue that cannot be finished.
edges_file="$(mktemp)"
trap 'rm -f "${edges_file}"' EXIT
declare -A seen=()
queue=()
while IFS= read -r number; do
  [ -n "${number}" ] || continue
  seen["${number}"]=1
  queue+=("${number}")
done < <(jq -r '.[]' <<<"${ready_numbers}")

while [ "${#queue[@]}" -gt 0 ]; do
  current="${queue[0]}"
  queue=("${queue[@]:1}")

  deps="$(gh api --paginate --slurp "repos/${PROJECT_REPO}/issues/${current}/dependencies/blocked_by?per_page=100")" || {
    echo "ERROR: Cannot read the blocked-by links of #${current}; refusing to guess." >&2
    exit 1
  }
  # Only OPEN blockers matter. One outside this repository cannot be walked
  # (its numbers mean something else here), so it blocks and ends the chain.
  blockers="$(jq -c --arg repo "${PROJECT_REPO}" --argjson from "${current}" '
    [ .[][] | select(.state == "open")
      | { from: $from,
          to: (if ((.repository_url // "") | endswith("/repos/" + $repo)) then .number else null end),
          external: (if ((.repository_url // "") | endswith("/repos/" + $repo)) then null else (.html_url // .url // "external") end) } ]' <<<"${deps}")" || {
    echo "ERROR: Unexpected blocked-by response for #${current}." >&2
    exit 1
  }
  jq -c '.[]' <<<"${blockers}" >>"${edges_file}"

  while IFS= read -r target; do
    [ -n "${target}" ] && [ "${target}" != 'null' ] || continue
    if [ -z "${seen[${target}]:-}" ]; then
      seen["${target}"]=1
      if [ "${#seen[@]}" -gt "${MAX_GRAPH_NODES}" ]; then
        echo "ERROR: The dependency graph is larger than ${MAX_GRAPH_NODES} issues; refusing to rank a partial view." >&2
        exit 1
      fi
      queue+=("${target}")
    fi
  done < <(jq -r '.[] | .to' <<<"${blockers}")
done

edges="$(jq -sc '.' "${edges_file}")"

ranked="$(jq -nc --arg repo "${PROJECT_REPO}" --argjson items "${items}" --argjson edges "${edges}" '
  def rank($p):
    if $p == null then 4
    elif ($p | test("^P0")) then 0
    elif ($p | test("^P1")) then 1
    elif ($p | test("^P2")) then 2
    elif ($p | test("^P3")) then 3
    else 4 end;

  ($items | map({key: (.issue | tostring), value: .}) | from_entries) as $by
  | ($edges | map(select(.to != null))) as $graph
  | ($edges | map(select(.to == null))) as $outside
  | def item($n): $by[($n | tostring)];
    def is_ready($n): (item($n)) as $i
      | ($i != null and $i.status == "Ready" and $i.repo == $repo and $i.state == "OPEN");
    ([ $items[] | select(.status == "Ready" and .repo == $repo and .state == "OPEN") | .issue ]
      + [ $graph[] | .from, .to ] | unique) as $nodes
  | (reduce $nodes[] as $n ({}; .[($n | tostring)] = (if is_ready($n) then rank(item($n).priority) else 99 end))) as $own
  # Urgency flows from a dependent to what it waits on; N+1 passes cover any chain.
  | (reduce range(0; ($nodes | length) + 1) as $_ ($own;
      . as $cur
      | reduce $nodes[] as $n (.;
          .[($n | tostring)] = ([$own[($n | tostring)]] + [ $graph[] | select(.to == $n) | $cur[(.from | tostring)] ] | min)))) as $eff
  | def dependents($n):
      (reduce range(0; ($nodes | length) + 1) as $_ ([$n];
        . as $set | ($set + [ $graph[] | select(.to as $t | $set | index($t)) | .from ]) | unique));
    def entry($n): (item($n)) as $i | {
      number: $n,
      title: ($i.title // null),
      url: ($i.url // null),
      priority: ($i.priority // null),
      effort: ($i.effort // null),
      effective_priority: (if $eff[($n | tostring)] >= 4 then null else "P\($eff[($n | tostring)])" end),
      unlocks: ([ dependents($n)[] | select(. != $n and is_ready(.)) ] | length),
      blockers: (
        ([ $graph[] | select(.from == $n) | .to ] | unique
          | map(. as $b | {number: $b, status: (item($b).status // "not in the Project"), ready: is_ready($b), url: (item($b).url // null)}))
        + ([ $outside[] | select(.from == $n) | {number: null, status: "other repository", ready: false, url: .external} ])
      )
    };
    ([ $nodes[] | select(is_ready(.)) | entry(.) ]) as $readies
  | { executable: ($readies | map(select(.blockers | length == 0))
        | sort_by([ (if .effective_priority == null then 4 else (.effective_priority[1:] | tonumber) end), -.unlocks,
                    (if .priority == null then 4 else (try (.priority | capture("^P(?<n>[0-3])").n | tonumber) catch 4) end), .number ])),
      blocked: ($readies | map(select(.blockers | length > 0)) | sort_by(.number)),
      needs_ready: ([ $readies[] | . as $r | .blockers[] | select(.ready == false and .number != null)
          | {number, status, blocks: $r.number} ]
        | group_by(.number) | map({number: .[0].number, status: .[0].status, blocks: (map(.blocks) | unique)})),
      in_progress: ([ $items[] | select(.status == "In Progress" and .repo == $repo and .state == "OPEN")
          | {number: .issue, title, url, agent} ] | sort_by(.number)) }')"

# A claim is a git ref, so `claude/issue-<n>` existing means a peer holds the
# issue. 404 is the only answer that means "free"; anything else is unknown.
ready_out='[]'
reserved_out='[]'
while IFS= read -r entry; do
  [ -n "${entry}" ] || continue
  number="$(jq -r '.number' <<<"${entry}")"
  if err="$(gh api "repos/${PROJECT_REPO}/git/ref/heads/claude/issue-${number}" --silent 2>&1)"; then
    reserved_out="$(jq -c --argjson e "${entry}" '. + [$e]' <<<"${reserved_out}")"
  elif [[ "${err}" == *'HTTP 404'* || "${err}" == *'Not Found'* ]]; then
    ready_out="$(jq -c --argjson e "${entry}" '. + [$e]' <<<"${ready_out}")"
  else
    echo "ERROR: Cannot tell whether claude/issue-${number} exists: ${err}" >&2
    exit 1
  fi
done < <(jq -c '.executable[]' <<<"${ranked}")

jq -n --argjson ready "${ready_out}" --argjson reserved "${reserved_out}" --argjson r "${ranked}" \
  '{ready: $ready, reserved: $reserved, blocked: $r.blocked, needs_ready: $r.needs_ready, in_progress: $r.in_progress}'
