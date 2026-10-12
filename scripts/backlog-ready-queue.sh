#!/usr/bin/env bash
set -euo pipefail

# Read-only issue queue for ScoutMagic's user-owned GitHub Project #1.
# Ready is the sole development authorization; status:accepted is ignored.
# Auth is the caller's gh login with Projects READ rights, never the
# PROJECTS_TOKEN Actions secret or a model-authored token.
command -v gh >/dev/null || { echo 'ERROR: GitHub CLI (gh) is required.' >&2; exit 1; }
command -v jq >/dev/null || { echo 'ERROR: jq is required.' >&2; exit 1; }

REPO='xdubois-57/scoutmagic'
items="$(gh project item-list 1 --owner xdubois-57 --format json --limit 1000 --field Status --field Priority)" || {
  echo 'ERROR: Cannot read Project #1. Give the gh login permission to read GitHub Projects.' >&2
  exit 1
}
# Never silently take work from a truncated view of the Project.
printf '%s' "$items" | jq -e '(.items | type == "array") and ((.totalCount // (.items | length)) <= (.items | length))' >/dev/null || {
  echo 'ERROR: Project response is incomplete or malformed.' >&2
  exit 1
}
candidates="$(printf '%s' "$items" | jq -c '[.items[]
  | select(.status == "Ready" and .content.type == "Issue")
  | . as $item
  | (try ($item.content.url | capture("^https://github\\.com/xdubois-57/scoutmagic/issues/(?<number>[0-9]+)$") | .number | tonumber) catch null) as $number
  | select($number != null)
  | {number:$number, title:(.content.title // .title // ""), url:.content.url,
     priority:(.priority // ""),
     rank: (if (.priority // "" | test("^P0(\\b| |$)")) then 0
            elif (.priority // "" | test("^P1(\\b| |$)")) then 1
            elif (.priority // "" | test("^P2(\\b| |$)")) then 2
            elif (.priority // "" | test("^P3(\\b| |$)")) then 3
            else 4 end)}]')"

# For each candidate, check GitHub's live issue state and native blocked-by
# relations. Unreadable dependencies are an ERROR, not "no dependencies".
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
while IFS= read -r entry; do
  [ -n "$entry" ] || continue
  number="$(jq -r '.number' <<< "$entry")"
  state="$(gh api "repos/$REPO/issues/$number" --jq '.state')" || {
    echo "ERROR: Cannot check issue #$number." >&2
    exit 1
  }
  [ "$state" = open ] || continue
  deps="$(gh api --paginate --slurp "repos/$REPO/issues/$number/dependencies/blocked_by?per_page=100")" || {
    echo "ERROR: Cannot inspect blocked-by for #$number." >&2
    exit 1
  }
  open_blockers="$(printf '%s' "$deps" | jq -c '[.[][] | select(.state == "open") | {number, url:(.html_url // "")}]')" || {
    echo "ERROR: Invalid blocked-by response for #$number." >&2
    exit 1
  }
  jq -nc --argjson ticket "$entry" --argjson blocked "$open_blockers" \
    '$ticket + {blocked_by:$blocked, blocked:($blocked|length>0)}' >> "$tmp"
done < <(printf '%s' "$candidates" | jq -c '.[]')

# Propagate a Ready descendant's urgency through an entire blocked-by chain.
# The number of passes is bounded by the number of Ready tickets; ties favor
# work that unblocks multiple Ready issues, then the original priority and age.
jq -sc '
  map(. + {effective_rank:.rank})
  | . as $start
  | reduce range(0; ($start | length)) as $pass
      ($start;
        . as $all
        | map(. as $ticket
            | .effective_rank = (
                [ .rank ] +
                [ $all[] | select(.blocked_by | any(.number == $ticket.number)) | .effective_rank ]
                | min
              )
          )
      )
  | . as $all
  | map(. + {unlocks: (.number as $n | [ $all[] | select(.blocked_by | any(.number == $n)) ] | length)})
  | sort_by(.effective_rank, -.unlocks, .rank, .number)
  | {ready:(map(select(.blocked == false))), blocked:(map(select(.blocked == true)))}
' "$tmp"
