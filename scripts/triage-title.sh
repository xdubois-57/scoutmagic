#!/usr/bin/env bash
set -euo pipefail

# Give ONE issue the title prefix its triage decided (issue #923 § 8):
#
#   [Bug]    a confirmed defect  (verdict bug:confirmed)
#   [Test]   work whose main object is tests, with no confirmed defect
#   [Idée]   everything else: enhancements, ideas, unconfirmed reports
#
# The prefix is DERIVED here from the verdict and the category the agent
# returned; the agent never writes a title. A confirmed defect stays [Bug]
# even when the fix needs tests, and a category of `confirmed_bug` on a
# verdict that is not bug:confirmed is ignored — the prefix is not what makes
# a bug confirmed.
#
# Everything after the prefixes is preserved verbatim. Any run of our own
# prefixes at the front is replaced by the one right prefix, which removes
# duplicates (`[Bug] [Test] …`) and a wrong prefix in one move; a title that
# already conforms is not written at all. Only the targeted issue is touched,
# a pull request is refused, and the title is never truncated.
#
# Usage: GH_TOKEN=… GITHUB_REPOSITORY=xdubois-57/scoutmagic \
#          bash scripts/triage-title.sh <issue> <verdict> <test|idea|confirmed_bug>

if [[ "$#" -ne 3 ]]; then
  echo 'Usage: triage-title.sh <issue number> <verdict> <test|idea|confirmed_bug>' >&2
  exit 2
fi
issue="$1"
verdict="$2"
category="$3"

case "${issue}" in ''|*[!0-9]*|0) echo 'Invalid issue number.' >&2; exit 2 ;; *) ;; esac
case "${verdict}" in
  bug:confirmed|bug:not-a-bug|bug:needs-info|feature-request) ;;
  *) echo 'Invalid verdict.' >&2; exit 2 ;;
esac
case "${category}" in test|idea|confirmed_bug) ;; *) echo 'Invalid category.' >&2; exit 2 ;; esac
if [[ "${GITHUB_REPOSITORY:-}" != 'xdubois-57/scoutmagic' ]]; then
  echo 'Refusing to write to a different repository.' >&2
  exit 2
fi

if [[ "${verdict}" = 'bug:confirmed' ]]; then
  prefix='[Bug]'
elif [[ "${category}" = 'test' ]]; then
  prefix='[Test]'
else
  prefix='[Idée]'
fi

# The title is read NOW, not taken from the event payload: it may have been
# edited since, and the comparison below decides whether to write at all.
current="$(gh api "repos/${GITHUB_REPOSITORY}/issues/${issue}")" || {
  echo "::error title=Title not normalised::Cannot read issue #${issue}." >&2
  exit 1
}
if [[ "$(jq -r 'has("pull_request")' <<<"${current}")" = 'true' ]]; then
  echo "Refusing to rename #${issue}: it is a pull request." >&2
  exit 1
fi

wanted="$(jq -r --arg prefix "${prefix}" '
  def strip: if test("^\\s*\\[(bug|test|id[ée]e)\\]\\s*"; "i")
             then sub("^\\s*\\[(bug|test|id[ée]e)\\]\\s*"; ""; "i") | strip
             else . end;
  (.title | strip) as $rest
  | if ($rest | length) == 0 then empty else $prefix + " " + $rest end' <<<"${current}")"

if [[ -z "${wanted}" ]]; then
  echo "Issue #${issue} has no title beyond its prefixes; leaving it alone." >&2
  exit 1
fi
if [[ "${wanted}" = "$(jq -r '.title' <<<"${current}")" ]]; then
  echo "Issue #${issue} already carries ${prefix}; title unchanged."
  exit 0
fi
if [[ "$(printf '%s' "${wanted}" | jq -Rrs 'length')" -gt 256 ]]; then
  echo "::error title=Title not normalised::Issue #${issue} would exceed GitHub's 256-character title limit with ${prefix}." >&2
  exit 1
fi

jq -nc --arg title "${wanted}" '{title: $title}' \
  | gh api "repos/${GITHUB_REPOSITORY}/issues/${issue}" -X PATCH --input - --silent
echo "Issue #${issue} is now titled with ${prefix}."
