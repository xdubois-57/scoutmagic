#!/usr/bin/env bash
set -euo pipefail

# Record the prerequisites a triage found as GitHub's NATIVE `blocked by`
# links on ONE issue (issue #923 § 9). The triage agent is read-only: it
# returns candidate issue NUMBERS with the passage that establishes each, and
# this script — the only thing that writes — decides whether to believe them.
#
# It adds a link only when the candidate:
#   - is an issue of this repository, not a pull request and not the issue
#     itself (a PR or a "related to #X" is a reference, not a prerequisite);
#   - is still OPEN (a closed prerequisite blocks nothing);
#   - is not already linked (idempotent: a second run writes nothing);
#   - would not close a cycle (the candidate is not itself, directly or
#     through any chain, waiting on this issue).
# It NEVER removes a link — manual ones included. An invalid suggestion is
# logged and skipped (the model being wrong is not an outage); an API failure
# is reported at the end and fails the step, after every other candidate has
# still been tried.
#
# The API takes the blocker's numeric `id` (the database id), NOT its public
# number: POST /repos/{owner}/{repo}/issues/{n}/dependencies/blocked_by with
# {"issue_id": <id>}. `Issues: write` on this repository is the whole
# authorization; the Projects token is neither needed nor given.
#
# Usage: GH_TOKEN=… GITHUB_REPOSITORY=xdubois-57/scoutmagic \
#          bash scripts/triage-dependencies.sh <issue> '<[{"issue":724,"evidence":"…"}]>'

if [ "$#" -ne 2 ]; then
  echo 'Usage: triage-dependencies.sh <issue number> <candidates JSON>' >&2
  exit 2
fi
issue="$1"
candidates="$2"
repo="${GITHUB_REPOSITORY:-}"
max_candidates=10
max_walk=200

case "${issue}" in ''|*[!0-9]*|0) echo 'Invalid issue number.' >&2; exit 2 ;; esac
if [ "${repo}" != 'xdubois-57/scoutmagic' ]; then
  echo 'Refusing to write to a different repository.' >&2
  exit 2
fi
if ! jq -e 'type == "array"' <<<"${candidates}" >/dev/null 2>&1; then
  echo 'The candidates must be a JSON array.' >&2
  exit 2
fi
if [ "$(jq 'length' <<<"${candidates}")" -eq 0 ]; then
  echo "No prerequisite proposed for #${issue}."
  exit 0
fi
if [ "$(jq 'length' <<<"${candidates}")" -gt "${max_candidates}" ]; then
  echo "More than ${max_candidates} candidates for #${issue}; that is a guess, not a finding. Nothing linked." >&2
  exit 1
fi

log_evidence() { printf '    evidence: %s\n' "$(printf '%s' "$1" | tr '\r\n' '  ' | cut -c1-200)"; }

# Numbers of the issues `$1` is currently blocked by (open or not).
blockers_of() {
  gh api --paginate --slurp "repos/${repo}/issues/${1}/dependencies/blocked_by?per_page=100" \
    | jq -r '.[][] | .number'
}

target="$(gh api "repos/${repo}/issues/${issue}")" || {
  echo "::error title=Dependencies not synced::Cannot read issue #${issue}."
  exit 1
}
if [ "$(jq -r 'has("pull_request")' <<<"${target}")" = 'true' ]; then
  echo "Refusing to add dependencies to #${issue}: it is a pull request." >&2
  exit 1
fi

existing="$(blockers_of "${issue}")" || { echo "::error title=Dependencies not synced::Cannot read the links of #${issue}."; exit 1; }

# Is `$2` reachable from `$1` by following `blocked by`? Adding
# "issue blocked by candidate" is a cycle exactly when the candidate can
# already reach the issue.
reaches() {
  local from="$1" goal="$2" cur next deps count=0
  local -a pending=("${from}")
  local -A visited=()
  visited["${from}"]=1
  while [ "${#pending[@]}" -gt 0 ]; do
    cur="${pending[0]}"
    pending=("${pending[@]:1}")
    [ "${cur}" = "${goal}" ] && return 0
    count=$((count + 1))
    if [ "${count}" -gt "${max_walk}" ]; then
      return 2   # too large to prove safe: treated as unsafe by the caller
    fi
    # Read into a variable first: inside `done < <(…)` a failing read is
    # invisible, the loop gets no lines, and a rate limit or a 5xx would be
    # taken for "no blockers" — the walk would call a cycle safe and the link
    # would be recorded. 3 = could not read, and the caller fails the step.
    deps="$(blockers_of "${cur}")" || return 3
    while IFS= read -r next; do
      [ -n "${next}" ] || continue
      if [ -z "${visited[${next}]:-}" ]; then
        visited["${next}"]=1
        pending+=("${next}")
      fi
    done <<<"${deps}"
  done
  return 1
}

linked=0
skipped=0
failed=0
seen=' '

while IFS= read -r entry; do
  number="$(jq -r '.issue // empty' <<<"${entry}")"
  evidence="$(jq -r '.evidence // empty' <<<"${entry}")"

  if ! [[ "${number}" =~ ^[1-9][0-9]*$ ]]; then
    echo "Skipped a candidate without a valid issue number."; skipped=$((skipped + 1)); continue
  fi
  if [ -z "${evidence//[[:space:]]/}" ]; then
    echo "Skipped #${number}: no passage justifies it."; skipped=$((skipped + 1)); continue
  fi
  if [[ "${seen}" == *" ${number} "* ]]; then
    echo "Skipped #${number}: proposed twice."; skipped=$((skipped + 1)); continue
  fi
  seen="${seen}${number} "

  if [ "${number}" = "${issue}" ]; then
    echo "Skipped #${number}: an issue cannot block itself."; skipped=$((skipped + 1)); continue
  fi

  if ! blocker="$(gh api "repos/${repo}/issues/${number}" 2>/dev/null)"; then
    echo "Skipped #${number}: it does not exist in ${repo}."; skipped=$((skipped + 1)); continue
  fi
  if [ "$(jq -r 'has("pull_request")' <<<"${blocker}")" = 'true' ]; then
    echo "Skipped #${number}: it is a pull request, not an issue."; skipped=$((skipped + 1)); continue
  fi
  if [ "$(jq -r '.state' <<<"${blocker}")" != 'open' ]; then
    echo "Skipped #${number}: already closed, it blocks nothing."; skipped=$((skipped + 1)); continue
  fi
  if grep -qx "${number}" <<<"${existing}"; then
    echo "#${issue} is already blocked by #${number}; left as it is."; skipped=$((skipped + 1)); continue
  fi

  # `reaches` exits 0 (cycle), 1 (safe), 2 (too long to prove), 3 (a read failed).
  set +e; reaches "${number}" "${issue}"; walk=$?; set -e
  if [ "${walk}" -eq 0 ]; then
    echo "Skipped #${number}: #${number} already waits on #${issue}, so this link would be a cycle."; skipped=$((skipped + 1)); continue
  elif [ "${walk}" -eq 3 ]; then
    echo "::error title=Dependency not recorded::Could not read the links needed to prove #${number} free of a cycle with #${issue}; nothing was recorded."
    failed=$((failed + 1)); continue
  elif [ "${walk}" -ne 1 ]; then
    echo "Skipped #${number}: its chain is too long to prove free of a cycle."; skipped=$((skipped + 1)); continue
  fi

  id="$(jq -r '.id' <<<"${blocker}")"
  if jq -nc --argjson id "${id}" '{issue_id: $id}' \
      | gh api "repos/${repo}/issues/${issue}/dependencies/blocked_by" -X POST --input - --silent; then
    echo "#${issue} is now blocked by #${number}."
    log_evidence "${evidence}"
    existing="${existing}"$'\n'"${number}"
    linked=$((linked + 1))
  else
    echo "::error title=Dependency not recorded::GitHub refused to record #${number} as a prerequisite of #${issue}."
    failed=$((failed + 1))
  fi
done < <(jq -c '.[]' <<<"${candidates}")

echo "Dependencies of #${issue}: ${linked} linked, ${skipped} skipped, ${failed} failed."
[ "${failed}" -eq 0 ]
