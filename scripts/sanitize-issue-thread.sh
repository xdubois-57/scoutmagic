#!/usr/bin/env bash
set -euo pipefail

# Rewrites an issue body, a comment, or a whole thread through the API,
# with scripts/strip-hidden-issue-content.sh applied (issue #1117).
#
#   sanitize-issue-thread.sh issue   <issue number>
#   sanitize-issue-thread.sh comment <comment id>
#   sanitize-issue-thread.sh thread  <issue number>   # body + every comment
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
  issue|comment|thread) ;;
  *) echo "usage: $0 issue|comment|thread <id>" >&2; exit 2 ;;
esac
[[ "${id}" =~ ^[0-9]+$ ]] || { echo "not a number: ${id}" >&2; exit 2; }
: "${GITHUB_REPOSITORY:?}"

# sanitize_one <api path of the object> <label for the log>
sanitize_one() {
  local path="$1" label="$2" json body clean
  json="$(gh api "${path}")"
  if [[ "$(jq -r '.user.type // ""' <<<"${json}")" == "Bot" ]]; then
    return 0
  fi
  body="$(jq -r '.body // ""' <<<"${json}")"
  clean="$(printf '%s' "${body}" | bash "${strip}")"
  if [[ "${clean}" == "${body}" ]]; then
    return 0
  fi
  jq -n --arg body "${clean}" '{body: $body}' | gh api -X PATCH "${path}" --input - >/dev/null
  echo "${label}: contenu caché retiré"
}

repo="repos/${GITHUB_REPOSITORY}"
case "${kind}" in
  issue)   sanitize_one "${repo}/issues/${id}" "issue #${id}" ;;
  comment) sanitize_one "${repo}/issues/comments/${id}" "commentaire ${id}" ;;
  thread)
    sanitize_one "${repo}/issues/${id}" "issue #${id}"
    for comment in $(gh api --paginate "${repo}/issues/${id}/comments" --jq '.[].id'); do
      sanitize_one "${repo}/issues/comments/${comment}" "commentaire ${comment}"
    done
    ;;
esac
