#!/usr/bin/env bash
set -euo pipefail

# One-off catch-up of the open issues onto the new Project conventions (issue
# #923 § 9, « Migration initiale »), driven by scripts/project-backfill.json:
#
#   effort        fill `Effort` on Project #1 — ONLY where it is empty, re-read
#                 just before the write by scripts/project-effort.sh, so a
#                 manual estimate or a concurrent change is never overwritten.
#                 `unknown` is left blank. Needs the Projects token.
#   dependencies  record the native `blocked by` links the issue text proves
#                 (#740 on #724), through scripts/triage-dependencies.sh —
#                 which refuses pull requests, closed issues, self links and
#                 cycles, and never removes a link. Needs only `issues: write`.
#
# Two modes because the two writes use two different credentials, and the
# workflow gives each to a step of its own. `Priority` is not in the data and
# not in either helper: it is the maintainer's decision.
#
# Every issue gets a line in the result, a failure on one does not stop the
# others, and the exit status is non-zero if any could not be synchronised.
#
# Usage: GH_TOKEN=… GITHUB_REPOSITORY=xdubois-57/scoutmagic \
#          bash scripts/project-backfill.sh <effort|dependencies>

mode="${1:-}"
case "${mode}" in effort|dependencies) ;; *) echo 'Usage: project-backfill.sh <effort|dependencies>' >&2; exit 2 ;; esac

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
data="${BACKFILL_DATA:-${here}/project-backfill.json}"
effort_script="${BACKFILL_EFFORT_SCRIPT:-${here}/project-effort.sh}"
deps_script="${BACKFILL_DEPENDENCIES_SCRIPT:-${here}/triage-dependencies.sh}"

failed=0
done_count=0
skipped=0

if [ "${mode}" = 'effort' ]; then
  while IFS=$'\t' read -r issue effort; do
    [ -n "${issue}" ] || continue
    if [ "${effort}" = 'unknown' ]; then
      echo "#${issue}: no defensible estimate, Effort stays empty."
      skipped=$((skipped + 1))
      continue
    fi
    if bash "${effort_script}" "${issue}" "${effort}"; then
      done_count=$((done_count + 1))
    else
      echo "::error title=Effort not synchronised::#${issue} could not be synchronised."
      failed=$((failed + 1))
    fi
  done < <(jq -r '.efforts | to_entries[] | [.key, .value] | @tsv' "${data}")
else
  while IFS= read -r entry; do
    [ -n "${entry}" ] || continue
    issue="$(jq -r '.issue' <<<"${entry}")"
    candidates="$(jq -c '[{issue: .blocked_by, evidence: .evidence}]' <<<"${entry}")"
    if bash "${deps_script}" "${issue}" "${candidates}"; then
      done_count=$((done_count + 1))
    else
      echo "::error title=Dependency not synchronised::#${issue} could not be synchronised."
      failed=$((failed + 1))
    fi
  done < <(jq -c '.dependencies[]' "${data}")
fi

echo "Backfill (${mode}): ${done_count} processed, ${skipped} left empty on purpose, ${failed} failed."
[ "${failed}" -eq 0 ]
