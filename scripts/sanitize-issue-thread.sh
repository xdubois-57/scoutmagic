#!/usr/bin/env bash
set -euo pipefail

# Rewrites an issue body, a comment, or a whole thread through the API,
# with scripts/strip-hidden-issue-content.sh applied (issue #1117).
#
#   sanitize-issue-thread.sh issue   <issue number>
#   sanitize-issue-thread.sh comment <comment id>
#   sanitize-issue-thread.sh thread  <issue number>   # body + every comment
#   sanitize-issue-thread.sh open    0                # every open issue's thread
#
# NEVER rewrites a text written by a bot. This repository's own workflows
# write HTML comments on purpose — `<!-- external-source:<id> -->`,
# `<!-- issue-fixed-comment: pull request #… -->`,
# `<!-- claude-review-status -->` — and find them again by searching for
# them. A human has no reason to write one, so a human text loses all of
# them, imitated markers included; the workflows that search for markers
# also only trust a bot's (external-sources-check.yml, issue-fixed-comment.yml).
#
# Writes only when the cleaned text differs: an untouched text is never
# PATCHed, and the rewrite's own `edited` event finds nothing left to do.
#
# Needs GH_TOKEN and GITHUB_REPOSITORY. The text is read from the API and
# piped, never interpolated into a command line. Prints one line per text
# rewritten, identifiers only — never the removed content.
# Tested by scripts/sanitize-issue-thread.test.sh.

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
strip="${here}/strip-hidden-issue-content.sh"

kind="${1:-}"
id="${2:-}"
case "${kind}" in
  issue|comment|thread|open) ;;
  *) echo "usage: $0 issue|comment|thread|open <id>" >&2; exit 2 ;;
esac
[[ "${id}" =~ ^[0-9]+$ ]] || { echo "not a number: ${id}" >&2; exit 2; }
: "${GITHUB_REPOSITORY:?}"

# GitHub's limits on an issue or comment body and on an issue title.
BODY_LIMIT=65536
TITLE_LIMIT=256
MARKER='⚠️ [contenu caché retiré automatiquement]'
MARKER_TOO_LONG="${MARKER} Texte trop long une fois nettoyé : l'original reste dans l'historique des modifications."

# A text can be far larger than one command-line argument may be (Linux
# caps each at 128 KiB), so every text reaches jq on stdin or from a file,
# never as `--arg`: past that size the exec fails, and a failed check reads
# as false.
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

# chars <file> → the length of its text in characters, whatever the locale.
chars() {
  local file="$1"
  jq -Rrs 'length' <"${file}"
}

# sanitize_one <api path of the object> <label for the log>
sanitize_one() {
  local path="$1" label="$2" json body clean title clean_title
  json="$(gh api "${path}")"
  if [[ "$(jq -r '.user.type // ""' <<<"${json}")" == "Bot" ]]; then
    return 0
  fi
  body="$(jq -r '.body // ""' <<<"${json}")"
  clean="$(printf '%s' "${body}" | bash "${strip}")"
  # An issue's title reaches the agents too (the triage prompt, the Ready
  # queue), and can carry the same invisible characters. A comment has none.
  title="$(jq -r '.title // ""' <<<"${json}")"
  clean_title="$(printf '%s' "${title}" | bash "${strip}" --title)"
  if [[ "${clean}" == "${body}" && "${clean_title}" == "${title}" ]]; then
    return 0
  fi
  # Every hidden run becomes a 41-character marker, so a text alternating
  # hidden and visible characters grows about twentyfold — past GitHub's
  # limits, and the PATCH would then fail the step and the agent job behind
  # it, on every run, for one crafted issue. Such a text is replaced whole;
  # its original stays in GitHub's edit history, which no agent reads.
  local length has_title
  printf '%s' "${clean}" >"${work}/body"
  printf '%s' "${clean_title}" >"${work}/title"
  length="$(chars "${work}/body")"
  if (( length > BODY_LIMIT )); then
    printf '%s' "${MARKER_TOO_LONG}" >"${work}/body"
  fi
  length="$(chars "${work}/title")"
  if (( length > TITLE_LIMIT )); then
    printf '%s' "${MARKER}" >"${work}/title"
  fi
  has_title="$(jq 'has("title")' <<<"${json}")"
  jq -n --rawfile body "${work}/body" --rawfile title "${work}/title" --argjson has_title "${has_title}" \
    '{body: $body} + (if $has_title then {title: $title} else {} end)' \
    | gh api -X PATCH "${path}" --input - >/dev/null
  echo "${label}: hidden content removed"
}

repo="repos/${GITHUB_REPOSITORY}"
case "${kind}" in
  issue)   sanitize_one "${repo}/issues/${id}" "issue #${id}" ;;
  comment) sanitize_one "${repo}/issues/comments/${id}" "comment ${id}" ;;
  thread)
    sanitize_one "${repo}/issues/${id}" "issue #${id}"
    # Listed first, then walked: inside `for … in $(…)` a failed listing
    # is not caught by `set -e`, the loop runs over nothing, and the agent
    # that runs next reads the comments as they were.
    ids="$(gh api --paginate "${repo}/issues/${id}/comments" --jq '.[].id')"
    while IFS= read -r comment; do
      [[ -n "${comment}" ]] || continue
      sanitize_one "${repo}/issues/comments/${comment}" "comment ${comment}"
    done <<<"${ids}"
    ;;
  open)
    # Every open issue's thread — the one-off pass over what was written
    # before this filter existed (issue-sanitize.yml, workflow_dispatch).
    # `id` is ignored but still required numeric, so the usage stays one.
    numbers="$(gh api --paginate "${repo}/issues?state=open&per_page=100" \
      --jq '.[] | select(.pull_request == null) | .number')"
    while IFS= read -r number; do
      [[ -n "${number}" ]] || continue
      bash "${BASH_SOURCE[0]}" thread "${number}"
    done <<<"${numbers}"
    ;;
  *)
    # Unreachable — the kind was checked above — kept so a kind added to
    # that check without a branch here fails loudly instead of doing nothing.
    echo "unhandled kind: ${kind}" >&2
    exit 2
    ;;
esac
