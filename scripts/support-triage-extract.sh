#!/bin/bash
set -euo pipefail

# Usage (from a GitHub Actions step, never by hand on a real issue):
#
#   GH_TOKEN=…                        the job's `github.token`, to read issues
#   SUPPORT_SITE_URL=https://…        the support site, from a repository variable
#   SUPPORT_TRIAGE_TOKEN=…            the receiver's triage token, from a secret
#   ISSUE_NUMBERS=181,185             the issues this run may read for
#   EXTRACT_ROOT=support-extract      where each extract is unpacked
#   ./scripts/support-triage-extract.sh
#
# For every issue named in ISSUE_NUMBERS, finds the support-ticket
# reference the REPORTER cited, fetches the anonymised extract of that
# ticket's archive from the support site, and unpacks it under
# EXTRACT_ROOT/<issue number>/ for the triage agent to read
# (ARCHITECTURE.md §8.49sexies, docs/quality-pipeline.md § AI triage).
#
# WHERE A REFERENCE MAY COME FROM, and why nowhere else. The issue BODY —
# the reporter's own form — and comments written by the issue's own
# reporter (matched on user id, never on login) or by the repository
# OWNER. A passer-by's comment is ignored: the reference is a claim on a
# ticket, and only the person who sent that ticket, or the maintainer,
# gets to make it. Bot comments are ignored too, since the triage's own
# verdicts quote what the reporter wrote. The LAST reference in that
# order wins, so a reporter who sends a fresh ticket and cites it in a
# reply gets the fresh archive.
#
# WHAT IS SENT, AND WHERE. One POST per issue, to
# `${SUPPORT_SITE_URL}/api/support/tickets/<reference>/triage-extract`,
# carrying the bearer token and `{"github_issue_number": N}` — the
# number this job was given, never one read out of an issue. The token
# goes into a header and nowhere else: not on the command line where a
# process listing would show it, not in any output.
#
# EVERY FAILURE IS THE ORDINARY CASE, not an error. No reference cited,
# no token configured, a refusal from the support site (unknown
# reference, purged archive, a reference another issue already claimed
# — the site answers the same 403 to all of them on purpose), an
# unreachable host: each is printed in one line and the triage runs on
# the issue alone, which is what it was written to do. This script exits
# non-zero only when it cannot do its own job at all — a missing input,
# an issue it cannot read.
#
# Outputs, appended to $GITHUB_OUTPUT when that is set:
#   present=true|false          whether at least one extract was unpacked
#   issues_with_extract=181,185 the issues that got one, comma-separated

: "${GH_TOKEN:?GH_TOKEN is required}"
: "${ISSUE_NUMBERS:?ISSUE_NUMBERS is required}"
: "${EXTRACT_ROOT:?EXTRACT_ROOT is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
SUPPORT_SITE_URL="${SUPPORT_SITE_URL:-}"
SUPPORT_TRIAGE_TOKEN="${SUPPORT_TRIAGE_TOKEN:-}"

# Exactly what the receiver issues: `SUP-` and six characters of an
# alphabet with no O/0 or I/1 (Modules\SupportDashboard\Repository\
# SupportTicketRepository). Anything else is prose.
REFERENCE_PATTERN='SUP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}'

mkdir -p "${EXTRACT_ROOT}"

present=false
with_extract=()

# The reference the reporter cited on one issue, or nothing.
reference_for() {
  local issue="$1"
  local issue_json comments_json reporter_id

  issue_json="$(gh api "repos/${GITHUB_REPOSITORY}/issues/${issue}")"
  reporter_id="$(printf '%s' "${issue_json}" | jq -r '.user.id')"

  comments_json="$(gh api "repos/${GITHUB_REPOSITORY}/issues/${issue}/comments?per_page=100" --paginate --slurp 2>/dev/null \
    || gh api "repos/${GITHUB_REPOSITORY}/issues/${issue}/comments?per_page=100" --paginate | jq -s '.')"

  # Body first, then the eligible comments in order; the last match
  # wins. Everything the reporter wrote is data for grep, never
  # interpolated into anything.
  {
    printf '%s' "${issue_json}" | jq -r '.body // ""'
    printf '\n'
    printf '%s' "${comments_json}" | jq -r --argjson reporter "${reporter_id}" '
      (if type == "array" and (.[0] | type) == "array" then flatten else . end)
      | .[]?
      | select(.user.type != "Bot")
      | select((.user.id == $reporter) or (.author_association == "OWNER"))
      | .body // ""'
  } | grep -oE "${REFERENCE_PATTERN}" | tail -n 1 || true
}

for issue in ${ISSUE_NUMBERS//,/ }; do
  case "${issue}" in
    ''|*[!0-9]*)
      echo "::error title=Not an issue number::'${issue}' is not an issue number; nothing was fetched for it." >&2
      exit 1
      ;;
    *) ;;
  esac

  reference="$(reference_for "${issue}")"
  if [[ -z "${reference}" ]]; then
    echo "Issue #${issue}: no support-ticket reference cited; the triage runs on the issue alone."
    continue
  fi

  if [[ -z "${SUPPORT_SITE_URL}" || -z "${SUPPORT_TRIAGE_TOKEN}" ]]; then
    echo "Issue #${issue}: cites ${reference}, but this repository has no SUPPORT_SITE_URL variable or no SUPPORT_TRIAGE_TOKEN secret; no extract was fetched."
    continue
  fi

  target="${EXTRACT_ROOT}/${issue}"
  archive="$(mktemp)"

  # `--fail` turns the site's 403 into a non-zero exit with no body
  # written; `--max-time` bounds a site that hangs; the token is a
  # header. `-sS` keeps curl quiet except for a real transport error.
  status="$(curl -sS --fail-with-body --max-time 120 \
    -o "${archive}" -w '%{http_code}' \
    -X POST \
    -H "Authorization: Bearer ${SUPPORT_TRIAGE_TOKEN}" \
    -H 'Content-Type: application/json' \
    --data "{\"github_issue_number\": ${issue}}" \
    "${SUPPORT_SITE_URL}/api/support/tickets/${reference}/triage-extract" 2>/dev/null || true)"

  if [[ "${status}" != '200' ]]; then
    echo "Issue #${issue}: cites ${reference}; the support site answered ${status:-nothing} — no extract (unknown reference, archive purged, reference claimed by another issue, or site unreachable). The triage runs on the issue alone."
    rm -f "${archive}"
    continue
  fi

  mkdir -p "${target}"
  # `unzip` refuses entries whose path leaves the target directory, and
  # the receiver builds the zip anyway; `-o` because a re-run of this
  # step must not stop on a leftover.
  if ! unzip -q -o "${archive}" -d "${target}"; then
    echo "Issue #${issue}: cites ${reference}; the extract could not be unpacked. The triage runs on the issue alone."
    rm -rf "${target}" "${archive}"
    continue
  fi
  rm -f "${archive}"

  echo "Issue #${issue}: extract of ${reference} unpacked under ${target}/ ($(find "${target}" -type f | wc -l) file(s))."
  present=true
  with_extract+=("${issue}")
done

joined="$(IFS=,; printf '%s' "${with_extract[*]:-}")"

echo "present=${present}"
echo "issues_with_extract=${joined}"

if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
  {
    echo "present=${present}"
    echo "issues_with_extract=${joined}"
  } >> "${GITHUB_OUTPUT}"
fi
