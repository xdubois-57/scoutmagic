#!/usr/bin/env bash
set -euo pipefail

# Offline test for scripts/triage-title.sh — `gh` is a fixture. Run by
# tests/Security/BacklogProjectScriptsTest.php.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

cat >"${tmp}/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
echo "$*" >>"${GH_TEST_CALLS}"
if [[ "$*" == *PATCH* ]]; then cat >"${GH_TEST_PATCH}"; exit 0; fi
cat "${GH_TEST_ISSUE}"
MOCK
chmod +x "${tmp}/gh"

fail() { echo "FAIL: $*" >&2; exit 1; }

# run <title> <verdict> <category> → prints the new title, or "UNCHANGED"
run() {
  local title="$1" verdict="$2" category="$3"
  jq -nc --arg t "${title}" '{number: 909, title: $t}' >"${tmp}/issue.json"
  : >"${tmp}/calls"; rm -f "${tmp}/patch.json"
  PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic \
    GH_TEST_CALLS="${tmp}/calls" GH_TEST_ISSUE="${tmp}/issue.json" GH_TEST_PATCH="${tmp}/patch.json" \
    bash "${root}/scripts/triage-title.sh" 909 "${verdict}" "${category}" >/dev/null
  if [[ -f "${tmp}/patch.json" ]]; then jq -r '.title' "${tmp}/patch.json"; else echo UNCHANGED; fi
}
expect() { # expect <wanted> <title> <verdict> <category>
  local wanted="$1" title="$2" verdict="$3" category="$4"
  local got; got="$(run "${title}" "${verdict}" "${category}")"
  [[ "${got}" = "${wanted}" ]] || fail "'${title}' (${verdict}/${category}): expected '${wanted}', got '${got}'"
}

expect '[Bug] Le bouton ne répond pas'   'Le bouton ne répond pas'      bug:confirmed    confirmed_bug
expect '[Test] Couvrir les erreurs'      'Couvrir les erreurs'          feature-request  test
expect '[Idée] Un export CSV'            'Un export CSV'                feature-request  idea
expect '[Idée] Un export CSV'            'Un export CSV'                bug:needs-info   idea
expect '[Idée] Pas un bug'               'Pas un bug'                   bug:not-a-bug    idea
# Already conforming: nothing is written.
expect UNCHANGED                         '[Bug] Déjà bon'               bug:confirmed    confirmed_bug
expect UNCHANGED                         '[Idée] Déjà bon'              feature-request  idea
# A wrong prefix is replaced, duplicates collapse, the rest is kept verbatim.
expect '[Bug] Titre [avec] crochets'     '[Idée] Titre [avec] crochets' bug:confirmed    confirmed_bug
expect '[Test] Suite'                    '[Bug] [Test] Suite'           feature-request  test
expect '[Idée] Suite'                    '[Idée] [Bug] Suite'           bug:not-a-bug    idea
expect '[Bug] Suite'                     '[BUG][idee] Suite'            bug:confirmed    confirmed_bug
# The category cannot make a bug: confirmed_bug on another verdict is an idea.
expect '[Idée] Faux bug'                 'Faux bug'                     bug:needs-info   confirmed_bug
# A confirmed defect stays [Bug] even when the agent calls the work tests.
expect '[Bug] Bug à tester'              'Bug à tester'                 bug:confirmed    test
# Only OUR prefixes are stripped.
expect '[Idée] [RGPD] Partie 2'          '[RGPD] Partie 2'              feature-request  idea

# A pull request is refused, and a bare prefix is not turned into a title.
jq -nc '{number: 909, title: "x", pull_request: {}}' >"${tmp}/issue.json"
if PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic GH_TEST_CALLS="${tmp}/calls" \
   GH_TEST_ISSUE="${tmp}/issue.json" GH_TEST_PATCH="${tmp}/p" bash "${root}/scripts/triage-title.sh" 909 bug:confirmed confirmed_bug >/dev/null 2>&1; then
  fail 'a pull request was renamed'
fi
jq -nc '{number: 909, title: "[Bug] [Test]"}' >"${tmp}/issue.json"
if PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic GH_TEST_CALLS="${tmp}/calls" \
   GH_TEST_ISSUE="${tmp}/issue.json" GH_TEST_PATCH="${tmp}/p" bash "${root}/scripts/triage-title.sh" 909 bug:confirmed confirmed_bug >/dev/null 2>&1; then
  fail 'an empty title was written'
fi

# Only the targeted issue is ever addressed; bad input never reaches gh.
run 'Autre titre' feature-request idea >/dev/null
grep -q 'issues/909' "${tmp}/calls" || fail 'the targeted issue was not addressed'
! grep -v 'issues/909' "${tmp}/calls" | grep -q . || fail 'another resource was addressed'
: >"${tmp}/calls"
for bad in "909x feature-request idea" "0 feature-request idea" "909 nope idea" "909 feature-request nope"; do
  if PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic GH_TEST_CALLS="${tmp}/calls" \
     bash "${root}/scripts/triage-title.sh" ${bad} >/dev/null 2>&1; then fail "bad input accepted: ${bad}"; fi
done
if PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=other/repo GH_TEST_CALLS="${tmp}/calls" \
   bash "${root}/scripts/triage-title.sh" 909 feature-request idea >/dev/null 2>&1; then fail 'another repository accepted'; fi
[[ ! -s "${tmp}/calls" ]] || fail 'invalid input reached gh'

echo 'Triage title tests passed.'
