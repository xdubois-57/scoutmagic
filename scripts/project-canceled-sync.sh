#!/usr/bin/env bash
set -euo pipefail

# Close the issues the maintainer CANCELED on ScoutMagic Project #1 (issue
# #923 § 7): Status = `Canceled` → the GitHub issue itself becomes
# `closed` with state_reason `not_planned` (« Not planned »).
#
# Canceled is not Done. Done means work was delivered; Canceled is a decision
# not to do it. So this script never says « corrigé », never touches a label,
# and never runs the fixed-comment workflow (that one listens to merged pull
# requests, which this is not).
#
# Why a poll and not an event: GitHub offers a Projects v2 webhook only to
# organisation projects and sends no workflow event for a user-owned one, so
# the workflow runs this on a schedule (docs/quality-pipeline.md names the
# delay).
#
# Safe by construction:
#   - the Project is #1 of xdubois-57 and the issue belongs to
#     xdubois-57/scoutmagic — draft issues and pull requests are not Issues;
#   - only OPEN issues are looked at: one already closed, for ANY reason, is
#     left exactly as it is (no rewriting of a delivered ticket's reason);
#   - the issue is re-read just before it is closed;
#   - a failure on one issue is reported and the rest still run;
#   - a Project that cannot be read fails loudly and closes nothing;
#   - the claim branch and the Project's Agent field of an issue being worked
#     on are not touched: the agent working it sees the cancellation and
#     releases its own claim (AGENTS.md).
#
# Two credentials, so two modes, and the workflow gives each to a step of
# its own: a classic PAT scoped `project` can read the user-owned Project but
# cannot write an issue, and the repository token can write issues but cannot
# see the Project.
#
#   list           Projects token: prints the number of each open issue whose
#                  Status is Canceled, one per line. Writes nothing.
#   close <issue>  repository token (`issues: write`): comments and closes ONE
#                  issue. Reads no Project; re-reads the issue itself.
#   all            both, with one token that can do both (a maintainer's own
#                  `gh` login); the default.
#
# Usage: GH_TOKEN=… bash scripts/project-canceled-sync.sh [list|close <issue>|all]

mode="${1:-all}"
case "${mode}" in
  list|all) [ "$#" -le 1 ] || { echo "Usage: project-canceled-sync.sh ${mode}" >&2; exit 2; } ;;
  close)
    [ "$#" -eq 2 ] || { echo 'Usage: project-canceled-sync.sh close <issue>' >&2; exit 2; }
    case "$2" in ''|*[!0-9]*|0) echo 'Invalid issue number.' >&2; exit 2 ;; esac ;;
  *) echo 'Usage: project-canceled-sync.sh [list|close <issue>|all]' >&2; exit 2 ;;
esac

command -v gh >/dev/null || { echo 'ERROR: GitHub CLI (gh) is required.' >&2; exit 1; }
command -v jq >/dev/null || { echo 'ERROR: jq is required.' >&2; exit 1; }

# shellcheck source=scripts/project-lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/project-lib.sh"

canceled_issues() {
  local items
  items="$(project_items)" || {
    echo '::error title=Canceled sync cannot read the Project::Nothing was closed. Check PROJECTS_TOKEN (scope project) and that Project #1 exists.' >&2
    return 1
  }
  jq -r --arg repo "${PROJECT_REPO}" \
    '.[] | select(.status == "Canceled" and .repo == $repo and .state == "OPEN") | .issue' <<<"${items}"
}

# Returns 0 when the issue is closed or was not an open issue any more.
close_issue() {
  local number="$1" current body
  current="$(gh api "repos/${PROJECT_REPO}/issues/${number}")" || {
    echo "::error title=Cancel not applied::Cannot read issue #${number}."
    return 1
  }
  if [ "$(jq -r 'has("pull_request")' <<<"${current}")" = 'true' ] || [ "$(jq -r '.state' <<<"${current}")" != 'open' ]; then
    echo "Issue #${number} is not an open issue any more; left alone."
    return 0
  fi

  # The explanation goes first and the closure only follows it: an issue
  # closed with nothing written on it is the backlog being rude.
  body='Ticket annulé : le mainteneur l’a passé en « Canceled » dans le projet ScoutMagic. Il est fermé comme « non planifié » (Not planned) : aucun travail n’a été livré. Pour le reprendre, rouvrez-le et changez son statut dans le projet.'
  if ! jq -nc --arg body "${body}" '{body: $body}' \
      | gh api "repos/${PROJECT_REPO}/issues/${number}/comments" --input - --silent; then
    echo "::error title=Cancel not applied::Could not explain the cancellation on #${number}; it stays open."
    return 1
  fi
  if gh api "repos/${PROJECT_REPO}/issues/${number}" -X PATCH -f state=closed -f state_reason=not_planned --silent; then
    echo "Issue #${number} closed as not planned (Status = Canceled)."
    return 0
  fi
  echo "::error title=Cancel not applied::Could not close #${number}."
  return 1
}

case "${mode}" in
  list)
    canceled_issues
    ;;
  close)
    close_issue "$2"
    ;;
  all)
    listing="$(canceled_issues)" || exit 1
    closed=0
    failed=0
    while IFS= read -r number; do
      [ -n "${number}" ] || continue
      if close_issue "${number}"; then closed=$((closed + 1)); else failed=$((failed + 1)); fi
    done <<<"${listing}"
    echo "Canceled sync: ${closed} handled, ${failed} failed."
    [ "${failed}" -eq 0 ]
    ;;
esac
