#!/bin/bash
set -euo pipefail

# Usage: ./scripts/check-sonar-release.test.sh
#
# Exercises scripts/check-sonar-release.sh against every scenario from
# AGENTS.md / the SonarQube release gate spec, without ever touching real
# SonarQube Cloud data: a fake `curl` (this script's own directory,
# prepended to PATH) intercepts every request and returns a canned JSON
# fixture chosen from the requested URL, so the gate's actual decision
# logic — not network access — is what gets tested. Run manually
# (`./scripts/check-sonar-release.test.sh`); not wired into CI, since CI's
# own `sonarqube` job already exercises the real API against the real
# project on every push/PR.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GATE_SCRIPT="${SCRIPT_DIR}/check-sonar-release.sh"
FAKE_BIN_DIR="$(mktemp -d)"
trap 'rm -rf "${FAKE_BIN_DIR}"' EXIT

PASS_COUNT=0
FAIL_COUNT=0

# Builds a fake `curl` that recognizes each SonarQube Web API endpoint by a
# substring of its path and returns the matching fixture body with HTTP 200,
# regardless of query parameters — good enough to drive the gate script's
# logic deterministically. Scenario-specific overrides (auth failure,
# network error, invalid JSON, HTTP 500) replace this default before each
# such test.
write_default_fake_curl() {
    local analysis_revision="$1"
    local quality_gate_status="$2"
    local security_issues_total="$3"
    local hotspots_total="$4"
    local high_total="$5"
    local higher_total="$6"

    cat > "${FAKE_BIN_DIR}/curl" <<EOF
#!/bin/bash
# Parse just enough of the real curl invocation used by check-sonar-release.sh:
# ... -o <file> -w '%{http_code}' -H 'Authorization: ...' '<url>'
out_file=""
url=""
args=("\$@")
for ((i=0; i<\${#args[@]}; i++)); do
    if [[ "\${args[i]}" == "-o" ]]; then
        out_file="\${args[i+1]}"
    fi
done
url="\${args[-1]}"

case "\$url" in
    */project_analyses/search*)
        printf '{"analyses":[{"revision":"${analysis_revision}"}]}' > "\${out_file}"
        ;;
    */qualitygates/project_status*)
        printf '{"projectStatus":{"status":"${quality_gate_status}"}}' > "\${out_file}"
        ;;
    */hotspots/search*)
        printf '{"paging":{"total":${hotspots_total}},"hotspots":[]}' > "\${out_file}"
        ;;
    */issues/search*impactSoftwareQualities=SECURITY*)
        printf '{"paging":{"total":${security_issues_total}},"issues":[]}' > "\${out_file}"
        ;;
    */issues/search*impactSeverities=HIGH*)
        printf '{"paging":{"total":${high_total}},"issues":[]}' > "\${out_file}"
        ;;
    */issues/search*impactSeverities=BLOCKER*)
        printf '{"paging":{"total":${higher_total}},"issues":[]}' > "\${out_file}"
        ;;
    *)
        printf '{}' > "\${out_file}"
        ;;
esac
echo -n "200"
EOF
    chmod +x "${FAKE_BIN_DIR}/curl"
}

write_fake_curl_body() {
    # $1: bash body assigned verbatim as the fake curl script
    cat > "${FAKE_BIN_DIR}/curl" <<EOF
#!/bin/bash
$1
EOF
    chmod +x "${FAKE_BIN_DIR}/curl"
}

run_case() {
    local name="$1" expected_exit="$2"
    local actual_exit=0
    local output

    output="$(PATH="${FAKE_BIN_DIR}:${PATH}" SONAR_TOKEN="fake-token-for-tests" "${GATE_SCRIPT}" 2>&1)" || actual_exit=$?

    if [[ "${actual_exit}" -eq "${expected_exit}" ]]; then
        echo "PASS: ${name} (exit ${actual_exit})"
        PASS_COUNT=$((PASS_COUNT + 1))
    else
        echo "FAIL: ${name} (expected exit ${expected_exit}, got ${actual_exit})"
        echo "--- output ---"
        echo "${output}"
        echo "--------------"
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
}

HEAD_SHA="$(git -C "${SCRIPT_DIR}/.." rev-parse HEAD)"

echo "1. No blocking finding -> PASS"
write_default_fake_curl "${HEAD_SHA}" "OK" 0 0 0 0
run_case "no findings" 0

echo "2. Active security finding -> BLOCK"
write_default_fake_curl "${HEAD_SHA}" "OK" 1 0 0 0
run_case "active security issue" 1

echo "2b. Unreviewed security hotspot -> BLOCK"
write_default_fake_curl "${HEAD_SHA}" "OK" 0 1 0 0
run_case "unreviewed hotspot" 1

echo "3. HIGH finding active -> BLOCK"
write_default_fake_curl "${HEAD_SHA}" "OK" 0 0 1 0
run_case "active HIGH finding" 1

echo "4. Finding above HIGH (BLOCKER) active -> BLOCK"
write_default_fake_curl "${HEAD_SHA}" "OK" 0 0 0 1
run_case "active BLOCKER finding" 1

echo "5. Only non-blocking findings (Quality Gate OK, no security/HIGH/BLOCKER) -> PASS"
write_default_fake_curl "${HEAD_SHA}" "OK" 0 0 0 0
run_case "non-blocking findings only" 0

echo "5b. Quality Gate ERROR alone -> BLOCK"
write_default_fake_curl "${HEAD_SHA}" "ERROR" 0 0 0 0
run_case "quality gate error" 1

echo "6. SonarQube Cloud unreachable -> BLOCK"
write_fake_curl_body 'exit 7'
run_case "network unreachable" 1

echo "7. SONAR_TOKEN absent -> BLOCK"
write_default_fake_curl "${HEAD_SHA}" "OK" 0 0 0 0
actual_exit=0
output="$(PATH="${FAKE_BIN_DIR}:${PATH}" env -u SONAR_TOKEN "${GATE_SCRIPT}" 2>&1)" || actual_exit=$?
if [[ "${actual_exit}" -eq 1 ]]; then
    echo "PASS: missing SONAR_TOKEN (exit 1)"
    PASS_COUNT=$((PASS_COUNT + 1))
else
    echo "FAIL: missing SONAR_TOKEN (expected exit 1, got ${actual_exit})"
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

echo "8. Invalid authentication -> BLOCK"
write_fake_curl_body '
out_file=""
args=("$@")
for ((i=0; i<${#args[@]}; i++)); do
    if [[ "${args[i]}" == "-o" ]]; then out_file="${args[i+1]}"; fi
done
printf "{}" > "${out_file}"
echo -n "401"
'
run_case "invalid authentication" 1

echo "9. Invalid JSON response -> BLOCK"
write_fake_curl_body '
out_file=""
args=("$@")
for ((i=0; i<${#args[@]}; i++)); do
    if [[ "${args[i]}" == "-o" ]]; then out_file="${args[i+1]}"; fi
done
printf "not json" > "${out_file}"
echo -n "200"
'
run_case "invalid JSON" 1

echo "10. No analysis found for this exact commit -> BLOCK"
write_default_fake_curl "0000000000000000000000000000000000dead" "OK" 0 0 0 0
run_case "analysis commit mismatch" 1

echo
echo "Results: ${PASS_COUNT} passed, ${FAIL_COUNT} failed."
[[ "${FAIL_COUNT}" -eq 0 ]]
