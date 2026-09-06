#!/bin/bash
set -euo pipefail

# Usage: ./scripts/support-triage-extract.test.sh
#
# Exercises scripts/support-triage-extract.sh against a fake `gh` and a
# fake `curl` — two scripts in a temp directory prepended to PATH, one
# answering issues and comments from JSON fixtures, the other recording
# the one request the script may make and answering it with a canned
# zip or a 403. No network, no token, no real issue touched.
#
# Same shape and same reasoning as scripts/sync-issue-labels.test.sh:
# what is under test is the script's DECISIONS — whose reference counts,
# which one wins, what a refusal does, where the token may appear — not
# whether GitHub or the support site work. Run by hand; nothing in CI
# runs it.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTRACT_SCRIPT="${SCRIPT_DIR}/support-triage-extract.sh"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

FAKE_BIN_DIR="${WORK_DIR}/bin"
FIXTURES="${WORK_DIR}/fixtures"
mkdir -p "${FAKE_BIN_DIR}" "${FIXTURES}"

export CURL_LOG="${WORK_DIR}/curl.log"
export FIXTURES

PASS_COUNT=0
FAIL_COUNT=0

# A zip the fake support site answers with.
python3 - "${FIXTURES}/extract.zip" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1], 'w') as z:
    z.writestr('LISEZ-MOI.txt', "# Extrait de triage\n")
    z.writestr('logs/error.log', "PHP Fatal error at ip-1\n")
PY

cat > "${FAKE_BIN_DIR}/gh" <<'EOF'
#!/bin/bash
# Answers `gh api repos/O/R/issues/N` and `…/issues/N/comments…` from
# $FIXTURES/issue-N.json and $FIXTURES/comments-N.json. Anything else is
# a test bug, so it is loud.
set -uo pipefail
[[ "${1:-}" = "api" ]] || { echo "fake gh: unexpected: $*" >&2; exit 99; }
path="$2"
slurp=false
for arg in "$@"; do [[ "${arg}" = "--slurp" ]] && slurp=true; done
if [[ "${path}" =~ /issues/([0-9]+)/comments ]]; then
  file="${FIXTURES}/comments-${BASH_REMATCH[1]}.json"
  [[ -f "${file}" ]] || file=/dev/null
  if [[ "${slurp}" = true ]]; then
    printf '['; cat "${file}" 2>/dev/null || printf '[]'; printf ']'
  else
    cat "${file}" 2>/dev/null || printf '[]'
  fi
  exit 0
fi
if [[ "${path}" =~ /issues/([0-9]+)$ ]]; then
  file="${FIXTURES}/issue-${BASH_REMATCH[1]}.json"
  [[ -f "${file}" ]] || { echo "fake gh: no fixture for ${path}" >&2; exit 1; }
  cat "${file}"
  exit 0
fi
echo "fake gh: unexpected path ${path}" >&2
exit 99
EOF

cat > "${FAKE_BIN_DIR}/curl" <<'EOF'
#!/bin/bash
# Records the request — URL, headers, body — to $CURL_LOG and answers:
# a reference containing REFUSE gets a 403 and no body, anything else
# gets the fixture zip written to the -o file and a 200.
set -uo pipefail
out=""; url=""; data=""; headers=()
args=("$@")
for ((i=0; i<${#args[@]}; i++)); do
  case "${args[i]}" in
    -o) out="${args[i+1]}"; i=$((i+1)) ;;
    -H) headers+=("${args[i+1]}"); i=$((i+1)) ;;
    --data) data="${args[i+1]}"; i=$((i+1)) ;;
    -w|-X|--max-time) i=$((i+1)) ;;
    -*) ;;
    *) url="${args[i]}" ;;
  esac
done
{
  echo "URL ${url}"
  for h in "${headers[@]}"; do echo "HEADER ${h}"; done
  echo "DATA ${data}"
} >> "${CURL_LOG}"
if [[ "${url}" == *REFUSE* ]]; then
  printf '403'
  exit 22
fi
cp "${FIXTURES}/extract.zip" "${out}"
printf '200'
exit 0
EOF
chmod +x "${FAKE_BIN_DIR}/gh" "${FAKE_BIN_DIR}/curl"
export PATH="${FAKE_BIN_DIR}:${PATH}"

issue_fixture() {
  local number="$1" reporter="$2" body="$3"
  jq -n --argjson number "${number}" --argjson reporter "${reporter}" --arg body "${body}" \
    '{number: $number, user: {id: $reporter, login: "reporter"}, body: $body}' \
    > "${FIXTURES}/issue-${number}.json"
}

comments_fixture() {
  # The JSON array of comments arrives on stdin.
  local number="$1"
  cat > "${FIXTURES}/comments-${number}.json"
}

run_extract() {
  # Environment overrides come from the caller's environment.
  local issues="$1"
  : > "${CURL_LOG}"
  rm -rf "${WORK_DIR}/extract"
  GH_TOKEN=gh-test-token \
  GITHUB_REPOSITORY=xdubois-57/scoutmagic \
  SUPPORT_SITE_URL="${SUPPORT_SITE_URL-https://support.example.be}" \
  SUPPORT_TRIAGE_TOKEN="${SUPPORT_TRIAGE_TOKEN-secret-triage-token-0123456789}" \
  ISSUE_NUMBERS="${issues}" \
  EXTRACT_ROOT="${WORK_DIR}/extract" \
  GITHUB_OUTPUT="${WORK_DIR}/output" \
  bash "${EXTRACT_SCRIPT}"
}

check() {
  local name="$1" ok="$2"
  if [[ "${ok}" = true ]]; then
    echo "PASS  ${name}"
    PASS_COUNT=$((PASS_COUNT + 1))
  else
    echo "FAIL  ${name}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
}

# --- A reference in the body is fetched and unpacked -------------------
issue_fixture 181 1001 $'### Version du site\n\n1.0.41\n\n### Référence du ticket de support\n\nSUP-ABC234\n'
: > "${WORK_DIR}/output"
stdout="$(run_extract 181)"
ok=true
[[ -f "${WORK_DIR}/extract/181/logs/error.log" ]] || ok=false
grep -q '^URL https://support.example.be/api/support/tickets/SUP-ABC234/triage-extract$' "${CURL_LOG}" || ok=false
grep -q '^HEADER Authorization: Bearer secret-triage-token-0123456789$' "${CURL_LOG}" || ok=false
grep -q '^DATA {"github_issue_number": 181}$' "${CURL_LOG}" || ok=false
grep -q '^present=true$' "${WORK_DIR}/output" || ok=false
grep -q '^issues_with_extract=181$' "${WORK_DIR}/output" || ok=false
check 'a reference in the body is fetched, with the token in a header and the issue number in the body' "${ok}"

ok=true
[[ "${stdout}" != *secret-triage-token* ]] || ok=false
check 'the token never appears on stdout' "${ok}"

# --- No reference: nothing is fetched ----------------------------------
issue_fixture 182 1002 $'### Version du site\n\n1.0.41\n\n### Référence du ticket de support\n\n_No response_\n'
run_extract 182 >/dev/null
ok=true
[[ ! -s "${CURL_LOG}" ]] || ok=false
[[ ! -d "${WORK_DIR}/extract/182" ]] || ok=false
grep -q '^present=false$' "${WORK_DIR}/output" || ok=false
check 'an issue citing no reference fetches nothing' "${ok}"

# --- Whose comment counts ---------------------------------------------
issue_fixture 183 1003 'Rien dans le formulaire.'
comments_fixture 183 <<'JSON'
[
  {"user": {"id": 9999, "type": "User"}, "author_association": "NONE", "body": "Essayez SUP-PASSER"},
  {"user": {"id": 1003, "type": "User"}, "author_association": "NONE", "body": "Voici ma référence : SUP-REP234"},
  {"user": {"id": 4242, "type": "Bot"}, "author_association": "NONE", "body": "Verdict citing SUP-BOTBOT"}
]
JSON
run_extract 183 >/dev/null
ok=true
grep -q 'SUP-REP234' "${CURL_LOG}" || ok=false
! grep -q 'SUP-PASSER' "${CURL_LOG}" || ok=false
! grep -q 'SUP-BOTBOT' "${CURL_LOG}" || ok=false
check "a passer-by's and a bot's references are ignored; the reporter's is used" "${ok}"

issue_fixture 184 1004 'Rien.'
comments_fixture 184 <<'JSON'
[
  {"user": {"id": 1, "type": "User"}, "author_association": "OWNER", "body": "Le ticket est SUP-XYZ789."}
]
JSON
run_extract 184 >/dev/null
ok=true
grep -q 'SUP-XYZ789' "${CURL_LOG}" || ok=false
check "the repository owner's reference counts" "${ok}"

issue_fixture 185 1005 'Première : SUP-AAA222.'
comments_fixture 185 <<'JSON'
[
  {"user": {"id": 1005, "type": "User"}, "author_association": "NONE", "body": "Nouveau ticket, nouvelle référence SUP-BBB333."}
]
JSON
run_extract 185 >/dev/null
ok=true
grep -q 'SUP-BBB333' "${CURL_LOG}" || ok=false
! grep -q 'SUP-AAA222' "${CURL_LOG}" || ok=false
check 'the last reference the reporter gave wins' "${ok}"

# --- Prose that looks like a reference is not one ----------------------
issue_fixture 186 1006 'Voir SUP-ABC12 et sup-abc234 et SUP-ABC0O1 ; rien de valable.'
run_extract 186 >/dev/null
ok=true
[[ ! -s "${CURL_LOG}" ]] || ok=false
check 'a wrong length, a lower-case or an out-of-alphabet reference is not a reference' "${ok}"

# --- A refusal from the site is the ordinary case ---------------------
issue_fixture 187 1007 'Référence SUP-REFUSE.'
stdout="$(run_extract 187)"
ok=true
[[ ! -d "${WORK_DIR}/extract/187" ]] || ok=false
grep -q '^present=false$' "${WORK_DIR}/output" || ok=false
[[ "${stdout}" == *"answered 403"* ]] || ok=false
check 'a 403 from the support site leaves no extract and does not fail the step' "${ok}"

# --- Several issues in one run ----------------------------------------
stdout="$(run_extract 181,182,187)"
ok=true
[[ -f "${WORK_DIR}/extract/181/logs/error.log" ]] || ok=false
[[ ! -d "${WORK_DIR}/extract/182" ]] || ok=false
grep -q '^present=true$' "${WORK_DIR}/output" || ok=false
grep -q '^issues_with_extract=181$' "${WORK_DIR}/output" || ok=false
check 'a run over several issues unpacks one directory per issue that got an extract' "${ok}"

# --- No token configured: nothing is fetched, nothing fails -----------
stdout="$(SUPPORT_TRIAGE_TOKEN='' run_extract 181)"
ok=true
[[ ! -s "${CURL_LOG}" ]] || ok=false
[[ "${stdout}" == *"no SUPPORT_TRIAGE_TOKEN"* ]] || ok=false
check 'without a token the reference is reported and nothing is fetched' "${ok}"

# --- An issue number that is not one ----------------------------------
ok=false
run_extract '181;rm' >/dev/null 2>&1 || ok=true
check 'an issue number that is not a number fails the step' "${ok}"

echo
echo "${PASS_COUNT} passed, ${FAIL_COUNT} failed."
[[ "${FAIL_COUNT}" -eq 0 ]]
