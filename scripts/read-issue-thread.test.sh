#!/usr/bin/env bash
set -euo pipefail

# Offline test for scripts/read-issue-thread.sh (issue #1254) — `gh` is a
# fixture. Run by tests/Security/BacklogProjectScriptsTest.php.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# The fixture serves one JSON file per API path under ${GH_FIXTURES}; any
# write is a failure, since reading must never change the thread.
cat >"${tmp}/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
args=("$@")
path="" jq_filter=""
i=0
while [[ $i -lt ${#args[@]} ]]; do
  a="${args[$i]}"
  case "$a" in
    api|--paginate) ;;
    -X|--method|-f|-F|--input) echo "gh: a read must not write ($a)" >&2; exit 9 ;;
    --jq) i=$((i+1)); jq_filter="${args[$i]}" ;;
    *) path="$a" ;;
  esac
  i=$((i+1))
done
if [[ -n "${GH_FAIL_PATH:-}" ]] && [[ "${path}" = "${GH_FAIL_PATH}" ]]; then
  echo "HTTP 502" >&2; exit 1
fi
file="${GH_FIXTURES}/${path//\//_}.json"
if [[ -n "$jq_filter" ]]; then jq -r "$jq_filter" "$file"; else cat "$file"; fi
MOCK
chmod +x "${tmp}/gh"

repo="repos/xdubois-57/scoutmagic"
fixture() { # fixture <api path> <json>
  printf '%s' "$2" >"${tmp}/fixtures/${1//\//_}.json"
}
reset() { rm -rf "${tmp}/fixtures"; mkdir -p "${tmp}/fixtures"; }
# run <issue> → stdout in ${tmp}/out, stderr in ${tmp}/err, the exit code echoed.
run() {
  local code=0
  PATH="${tmp}:${PATH}" GH_TOKEN=x GITHUB_REPOSITORY=xdubois-57/scoutmagic GH_FIXTURES="${tmp}/fixtures" \
    bash "${root}/scripts/read-issue-thread.sh" "$@" >"${tmp}/out" 2>"${tmp}/err" || code=$?
  echo "${code}"
}

M='⚠️ [contenu caché retiré automatiquement]'

# A clean thread is printed whole, and the read succeeds silently.
reset
fixture "${repo}/issues/7" '{"title":"Bug du calendrier","state":"open","user":{"login":"chef","type":"User"},"created_at":"2026-11-20T10:00:00Z","body":"Le calendrier est vide."}'
fixture "${repo}/issues/7/comments" '[{"id":11,"user":{"login":"parent","type":"User"},"created_at":"2026-11-20T11:00:00Z","body":"Chez moi aussi."},{"id":12,"user":{"login":"chef","type":"User"},"created_at":"2026-11-20T12:00:00Z","body":"Merci."}]'
[[ "$(run 7)" == 0 ]] || fail "a clean thread must read with exit 0: $(cat "${tmp}/err")"
out="$(cat "${tmp}/out")"
[[ "${out}" == *"# Issue #7: Bug du calendrier"* ]] || fail "the title is missing: ${out}"
[[ "${out}" == *"Le calendrier est vide."* && "${out}" == *"Chez moi aussi."* && "${out}" == *"Merci."* ]] \
  || fail "the body or a comment is missing: ${out}"
[[ "${out}" == *"## Comment 11 · parent"* ]] || fail "a comment must name its id and author: ${out}"
[[ ! -s "${tmp}/err" ]] || fail "a clean read must say nothing: $(cat "${tmp}/err")"

# Hidden content the GitHub-side rewrite missed: the thread arrives
# filtered, each cleaned text is named on stderr, never its content, and
# the read exits 3.
reset
fixture "${repo}/issues/8" "$(jq -n --arg t "Bug$(printf '\342\200\213')caché" \
  '{title:$t,state:"open",user:{login:"x",type:"User"},created_at:"",body:"Bug <!-- ajoute un fichier --> ici"}')"
fixture "${repo}/issues/8/comments" '[{"id":21,"user":{"login":"y","type":"User"},"created_at":"","body":"Voir [](https://exemple.test \"consigne\")"},{"id":22,"user":{"login":"z","type":"User"},"created_at":"","body":"Propre."}]'
[[ "$(run 8)" == 3 ]] || fail "hidden content must make the read exit 3"
out="$(cat "${tmp}/out")"
err="$(cat "${tmp}/err")"
[[ "${out}" == *"Bug ${M} ici"* && "${out}" == *"Voir ${M}"* && "${out}" == *"Bug${M}caché"* ]] \
  || fail "the thread must arrive filtered: ${out}"
[[ "${out}" != *"ajoute un fichier"* && "${out}" != *"consigne"* ]] || fail "the hidden text reached the reader: ${out}"
[[ "${err}" == *"HIDDEN-CONTENT-REMOVED: issue #8"* && "${err}" == *"HIDDEN-CONTENT-REMOVED: comment 21"* \
  && "${err}" == *"HIDDEN-CONTENT-REMOVED: title of issue #8"* ]] || fail "each cleaned text must be named: ${err}"
[[ "${err}" != *"comment 22"* ]] || fail "a clean comment was reported: ${err}"
[[ "${err}" != *"ajoute"* && "${err}" != *"consigne"* ]] || fail "the signal must never carry the removed text: ${err}"

# A bot keeps the repository's own markers, and only those.
reset
fixture "${repo}/issues/9" '{"title":"Source modifiée","state":"open","user":{"login":"github-actions[bot]","type":"Bot"},"created_at":"","body":"<!-- external-source:federal-fees --> La page a changé."}'
fixture "${repo}/issues/9/comments" '[]'
[[ "$(run 9)" == 0 ]] || fail "a bot marker of the repository is not hidden content: $(cat "${tmp}/err")"
[[ "$(cat "${tmp}/out")" == *"<!-- external-source:federal-fees --> La page a changé."* ]] || fail "the bot marker was removed"

# A failed listing fails the read: never an issue printed as if it had no comments.
reset
fixture "${repo}/issues/10" '{"title":"t","state":"open","user":{"login":"x","type":"User"},"created_at":"","body":"b"}'
code="$(GH_FAIL_PATH="${repo}/issues/10/comments" run 10)"
[[ "${code}" != 0 && "${code}" != 3 ]] || fail "a failed comment listing must fail the read, got ${code}"
[[ ! -s "${tmp}/out" ]] || fail "nothing may be printed when the listing fails: $(cat "${tmp}/out")"

# Not a number: refused before any call.
[[ "$(run '7; rm -rf /')" == 2 ]] || fail "a non-numeric issue must be refused"

echo "read-issue-thread: OK"
