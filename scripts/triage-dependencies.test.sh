#!/usr/bin/env bash
set -euo pipefail

# Offline test for scripts/triage-dependencies.sh: `gh` is a stateful fixture
# (POSTing a link adds it, so idempotence is observable). Run by
# tests/Security/BacklogProjectScriptsTest.php.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
mkdir -p "${tmp}/issues" "${tmp}/links"

# issue <number> <state> [pr]. The numeric id is deliberately NOT the number.
issue() {
  jq -nc --argjson n "$1" --arg s "$2" --arg pr "${3:-}" \
    '{number: $n, id: (900000 + $n), state: $s, repository_url: "https://api.github.com/repos/xdubois-57/scoutmagic"}
     + (if $pr != "" then {pull_request: {}} else {} end)' >"${tmp}/issues/$1.json"
}
issue 724 open
issue 740 open
issue 741 open
issue 742 open
issue 743 closed
issue 744 open pr
issue 745 open
issue 750 open
issue 760 open
link() { printf '%s\n' "$2" >>"${tmp}/links/$1"; }
link 740 724          # 740 is already blocked by 724
link 745 750          # 745 waits on 750, 750 on 741: 741 -> 745 would be a cycle
link 750 741

cat >"${tmp}/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
dir="$(dirname "$0")"
echo "$*" >>"${dir}/calls.log"
if [ -f "${dir}/fail-read-$(sed -nE 's#.*issues/([0-9]+)/dependencies.*#\1#p' <<<"$*")" ]; then echo 'HTTP 502' >&2; exit 1; fi
if [[ "$*" == *'-X DELETE'* ]]; then echo 'DELETE must never be called' >&2; exit 9; fi
if [[ "$*" == *'-X POST'* ]]; then
  n="$(sed -E 's#.*issues/([0-9]+)/dependencies.*#\1#' <<<"$*")"
  body="$(cat)"
  echo "${body}" >>"${dir}/posts.log"
  [ -f "${dir}/forbid-post" ] && { echo 'HTTP 403: Resource not accessible' >&2; exit 1; }
  id="$(jq -r '.issue_id' <<<"${body}")"
  echo "$((id - 900000))" >>"${dir}/links/${n}"
  exit 0
fi
case "$*" in
  *--slurp*issues/*/dependencies/blocked_by*)
    n="$(sed -E 's#.*issues/([0-9]+)/dependencies.*#\1#' <<<"$*")"
    if [ -f "${dir}/links/${n}" ]; then
      jq -Rsc '[ split("\n") | map(select(length > 0) | {number: tonumber}) ]' "${dir}/links/${n}"
    else echo '[[]]'; fi ;;
  *issues/*)
    n="$(sed -E 's#.*issues/([0-9]+).*#\1#' <<<"$*")"
    if [ -f "${dir}/issues/${n}.json" ]; then cat "${dir}/issues/${n}.json"; else echo 'gh: Not Found (HTTP 404)' >&2; exit 1; fi ;;
  *) echo "Unexpected gh call: $*" >&2; exit 2 ;;
esac
MOCK
chmod +x "${tmp}/gh"

fail() { echo "FAIL: $*" >&2; exit 1; }
run() { # run <issue> <candidates json>
  : >"${tmp}/posts.log"
  PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic \
    bash "${root}/scripts/triage-dependencies.sh" "$1" "$2" >"${tmp}/out" 2>&1
}
posts() { wc -l <"${tmp}/posts.log" | tr -d ' '; }

# A certain prerequisite is recorded with the blocker's numeric id.
run 741 '[{"issue":742,"evidence":"« À faire après #742 »"}]'
[ "$(posts)" = 1 ] || fail 'the prerequisite was not recorded'
jq -e '.issue_id == 900742' "${tmp}/posts.log" >/dev/null || fail 'the public number was sent instead of the numeric id'

# Idempotent: the same proposal writes nothing.
run 741 '[{"issue":742,"evidence":"« À faire après #742 »"}]'
[ "$(posts)" = 0 ] || fail 'a second run wrote again'

# An already present link (manual or not) is preserved and not duplicated.
run 740 '[{"issue":724,"evidence":"Partie 1"}]'
[ "$(posts)" = 0 ] || fail 'an existing link was rewritten'

# Everything dubious is skipped and the run still succeeds.
run 741 '[{"issue":741,"evidence":"x"},{"issue":744,"evidence":"a PR"},{"issue":743,"evidence":"closed"},{"issue":999,"evidence":"nowhere"},{"issue":745,"evidence":"cycle"},{"issue":742,"evidence":""},{"issue":"abc","evidence":"x"}]'
[ "$(posts)" = 0 ] || { cat "${tmp}/out"; fail 'an invalid candidate was recorded'; }
grep -q 'cannot block itself' "${tmp}/out" || fail 'self dependency not refused'
grep -q 'pull request' "${tmp}/out" || fail 'a pull request was not refused'
grep -q 'already closed' "${tmp}/out" || fail 'a closed prerequisite was not skipped'
grep -q 'does not exist' "${tmp}/out" || fail 'a missing issue was not refused'
grep -q 'a cycle' "${tmp}/out" || fail 'a transitive cycle was not refused'
grep -q 'no passage justifies' "${tmp}/out" || fail 'a candidate without evidence was accepted'

# A direct cycle: 724 would wait on 740 which already waits on 724.
run 724 '[{"issue":740,"evidence":"x"}]'
[ "$(posts)" = 0 ] || fail 'a direct cycle was recorded'

# The target must be an issue.
if PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic bash "${root}/scripts/triage-dependencies.sh" 744 '[{"issue":742,"evidence":"x"}]' >/dev/null 2>&1; then
  fail 'dependencies were added to a pull request'
fi

# A permission failure is reported (non-zero) but does not stop the others.
touch "${tmp}/forbid-post"
if run 741 '[{"issue":724,"evidence":"x"},{"issue":760,"evidence":"y"}]'; then fail 'a refused write passed as success'; fi
[ "$(posts)" = 2 ] || fail 'a failed write stopped the remaining candidates'
grep -q 'Dependency not recorded' "${tmp}/out" || fail 'the failure is not reported'
rm -f "${tmp}/forbid-post"

# A read that fails DURING the cycle walk is not "no blockers": nothing is
# recorded and the step fails. 760 is a clean candidate; its own links cannot be read.
issue 761 open
touch "${tmp}/fail-read-761"
if run 741 '[{"issue":761,"evidence":"x"}]'; then fail 'a failed read during the cycle walk passed as success'; fi
[ "$(posts)" = 0 ] || fail 'a link was recorded although the cycle check could not read the graph'
grep -q 'Could not read the links' "${tmp}/out" || fail 'the failed read is not reported'
rm -f "${tmp}/fail-read-761"

# Malformed input never reaches gh.
: >"${tmp}/calls.log"
for bad in "abc []" "741 notjson" "0 []"; do
  # shellcheck disable=SC2086
  if PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic bash "${root}/scripts/triage-dependencies.sh" ${bad} >/dev/null 2>&1; then fail "bad input accepted: ${bad}"; fi
done
[ ! -s "${tmp}/calls.log" ] || fail 'invalid input reached gh'
grep -q DELETE "${tmp}/calls.log" && fail 'a link was removed'

echo 'Triage dependencies tests passed.'
