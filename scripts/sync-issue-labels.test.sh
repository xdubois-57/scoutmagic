#!/bin/bash
set -euo pipefail

# Usage: ./scripts/sync-issue-labels.test.sh
#
# Exercises scripts/sync-issue-labels.sh against a fake `gh` — a script in
# a temp directory prepended to PATH that answers from a JSON file standing
# in for the repository's label set, and records every write. No network,
# no token, no real repository touched.
#
# Same shape and same reasoning as scripts/build-artifact.test.sh and
# scripts/check-sonar-release.test.sh: what is under test is the script's
# DECISIONS — create, patch, leave alone, refuse — not whether GitHub
# works. Run manually; nothing in CI runs it, and the label sync itself is
# a maintainer command rather than a build step.
#
# The case that matters most is the third: a second run against the state
# the first run produced must write nothing at all. "Re-running the label
# script changes nothing" is the whole claim the script makes, and it is
# the kind of claim that is true right up until somebody adds a field to
# the comparison and forgets to send it on create.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYNC_SCRIPT="${SCRIPT_DIR}/sync-issue-labels.sh"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

FAKE_BIN_DIR="${WORK_DIR}/bin"
mkdir -p "${FAKE_BIN_DIR}"

# The fake repository: a JSON object of name → {color, description}.
export LABEL_STATE="${WORK_DIR}/labels.json"
# Every write the script attempts, one per line.
export WRITE_LOG="${WORK_DIR}/writes.log"

cat > "${FAKE_BIN_DIR}/gh" <<'EOF'
#!/bin/bash
# Answers the three calls sync-issue-labels.sh makes, from LABEL_STATE.
# Anything else is a test bug rather than a script bug, so it is loud.
set -uo pipefail

method="GET"
args=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        api) shift ;;
        -X) method="$2"; shift 2 ;;
        -f) args+=("$2"); shift 2 ;;
        --include) shift ;;
        *) args+=("$1"); shift ;;
    esac
done

path="${args[0]}"
fields=("${args[@]:1}")

field_value() {
    local key="$1" f
    for f in "${fields[@]}"; do
        [[ "${f}" == "${key}="* ]] && { printf '%s' "${f#*=}"; return 0; }
    done
    printf ''
}

if [[ "${GH_FAIL_HARD:-0}" == "1" ]]; then
    echo "gh: Bad credentials (HTTP 401)" >&2
    exit 1
fi

case "${method}" in
    GET)
        name="${path##*/labels/}"
        if jq -e --arg n "${name}" 'has($n)' "${LABEL_STATE}" >/dev/null; then
            jq -c --arg n "${name}" '.[$n] + {name: $n}' "${LABEL_STATE}"
            exit 0
        fi
        echo "gh: Not Found (HTTP 404)" >&2
        exit 1
        ;;
    POST)
        name="$(field_value name)"
        echo "POST ${name} $(field_value color) $(field_value description)" >> "${WRITE_LOG}"
        jq --arg n "${name}" --arg c "$(field_value color)" --arg d "$(field_value description)" \
            '.[$n] = {color: $c, description: $d}' "${LABEL_STATE}" > "${LABEL_STATE}.tmp"
        mv "${LABEL_STATE}.tmp" "${LABEL_STATE}"
        echo '{}'
        exit 0
        ;;
    PATCH)
        name="${path##*/labels/}"
        echo "PATCH ${name} $(field_value color) $(field_value description)" >> "${WRITE_LOG}"
        jq --arg n "${name}" --arg c "$(field_value color)" --arg d "$(field_value description)" \
            '.[$n] = {color: $c, description: $d}' "${LABEL_STATE}" > "${LABEL_STATE}.tmp"
        mv "${LABEL_STATE}.tmp" "${LABEL_STATE}"
        echo '{}'
        exit 0
        ;;
esac

echo "fake gh: unexpected call: ${method} ${path}" >&2
exit 99
EOF
chmod +x "${FAKE_BIN_DIR}/gh"
export PATH="${FAKE_BIN_DIR}:${PATH}"

PASS_COUNT=0
FAIL_COUNT=0

pass() {
    echo "  ✅ $1"
    PASS_COUNT=$((PASS_COUNT + 1))
    return 0
}

fail() {
    echo "  ❌ $1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    return 0
}

assert_eq() {
    local actual="$1" expected="$2" label="$3"
    if [[ "${actual}" == "${expected}" ]]; then
        pass "${label}"
    else
        fail "${label} (expected '${expected}', got '${actual}')"
    fi
    return 0
}

reset_state() {
    echo "${1:-{\}}" > "${LABEL_STATE}"
    : > "${WRITE_LOG}"
    return 0
}

# A fixture issue-form directory, so the form/label cross-check runs
# against something this test controls rather than against the real
# .github/ISSUE_TEMPLATE (which would make these cases pass or fail for
# reasons that have nothing to do with the script).
FORMS="${WORK_DIR}/forms"
mkdir -p "${FORMS}"

run_sync() {
    ISSUE_TEMPLATE_DIR="${FORMS}" "${SYNC_SCRIPT}" --repo "acme/widgets" "$@" 2>&1
    return $?
}

echo "sync-issue-labels.sh"

# ---------------------------------------------------------------------------
echo
echo "A first run on a repository with no labels creates them all"
# ---------------------------------------------------------------------------
printf 'name: Bug\nlabels: ["triage:pending"]\n' > "${FORMS}/bug.yml"
reset_state
output="$(run_sync)" || fail "the first run exited non-zero"

expected_count="$(grep -cE '^\s+"[a-z:-]+\|' "${SYNC_SCRIPT}")"
assert_eq "$(wc -l < "${WRITE_LOG}" | tr -d ' ')" "${expected_count}" \
    "one POST per label in the table (${expected_count})"
assert_eq "$(grep -c '^POST ' "${WRITE_LOG}")" "${expected_count}" "every write was a create, none a patch"
if grep -q "Done: ${expected_count} created, 0 updated, 0 unchanged." <<< "${output}"; then
    pass "the summary counts them as created"
else
    fail "the summary counts them as created"
fi
if grep -q 'POST triage:pending fbca04' "${WRITE_LOG}"; then
    pass "triage:pending is created with its colour"
else
    fail "triage:pending is created with its colour"
fi

# ---------------------------------------------------------------------------
echo
echo "A second run against that state writes nothing — the idempotence claim"
# ---------------------------------------------------------------------------
: > "${WRITE_LOG}"
output="$(run_sync)" || fail "the second run exited non-zero"
assert_eq "$(wc -c < "${WRITE_LOG}" | tr -d ' ')" "0" "no write of any kind"
if grep -q 'Nothing to do' <<< "${output}"; then
    pass "it says so rather than reporting silence"
else
    fail "it says so rather than reporting silence"
fi

# ---------------------------------------------------------------------------
echo
echo "A colour or description edited in the UI is repaired, and only that one"
# ---------------------------------------------------------------------------
jq '.["bug:confirmed"].color = "ffffff"' "${LABEL_STATE}" > "${LABEL_STATE}.tmp" && mv "${LABEL_STATE}.tmp" "${LABEL_STATE}"
jq '.["triage:done"].description = "something somebody typed"' "${LABEL_STATE}" > "${LABEL_STATE}.tmp" && mv "${LABEL_STATE}.tmp" "${LABEL_STATE}"
: > "${WRITE_LOG}"
output="$(run_sync)" || fail "the repair run exited non-zero"
assert_eq "$(wc -l < "${WRITE_LOG}" | tr -d ' ')" "2" "exactly two labels rewritten"
assert_eq "$(grep -c '^PATCH ' "${WRITE_LOG}")" "2" "both writes were patches, not creates"
if grep -q '^PATCH bug:confirmed b60205' "${WRITE_LOG}"; then
    pass "the colour is put back"
else
    fail "the colour is put back"
fi
if grep -q '^PATCH triage:done ' "${WRITE_LOG}"; then
    pass "the description is put back"
else
    fail "the description is put back"
fi

# ---------------------------------------------------------------------------
echo
echo "A label the script does not own is left alone"
# ---------------------------------------------------------------------------
: > "${WRITE_LOG}"
jq '.["claude-review"] = {color: "111111", description: "asks for a fresh Claude pass"}' \
    "${LABEL_STATE}" > "${LABEL_STATE}.tmp" && mv "${LABEL_STATE}.tmp" "${LABEL_STATE}"
run_sync >/dev/null || fail "the run exited non-zero"
assert_eq "$(wc -c < "${WRITE_LOG}" | tr -d ' ')" "0" "no write at all"
if jq -e 'has("claude-review")' "${LABEL_STATE}" >/dev/null; then
    pass "claude-review still exists — nothing is ever deleted"
else
    fail "claude-review still exists — nothing is ever deleted"
fi

# ---------------------------------------------------------------------------
echo
echo "A form declaring a label the script does not create is refused"
# ---------------------------------------------------------------------------
# The failure this guards against is GitHub dropping an unknown form label
# in silence, which produces an issue with no triage state and no trace of
# why. The refusal must come BEFORE any write.
printf 'name: Bug\nlabels: ["triage:pending", "priority:high"]\n' > "${FORMS}/bug.yml"
: > "${WRITE_LOG}"
if output="$(run_sync)"; then
    fail "the run should have refused"
else
    pass "the run refuses"
fi
if grep -q 'priority:high' <<< "${output}"; then
    pass "it names the offending label"
else
    fail "it names the offending label"
fi
assert_eq "$(wc -c < "${WRITE_LOG}" | tr -d ' ')" "0" "it refused before writing anything"

# ---------------------------------------------------------------------------
echo
echo "A form declaring labels as a YAML block sequence is refused, not skipped"
# ---------------------------------------------------------------------------
# Silently skipping it would leave the cross-check above reporting success
# over a file it never read — the failure mode where a green result and no
# result look identical.
printf 'name: Bug\nlabels:\n  - triage:pending\n' > "${FORMS}/bug.yml"
if output="$(run_sync)"; then
    fail "the run should have refused"
else
    pass "the run refuses"
fi
if grep -q 'block sequence' <<< "${output}"; then
    pass "it says why, naming the file"
else
    fail "it says why, naming the file"
fi

# ---------------------------------------------------------------------------
echo
echo "An API error that is not a 404 aborts instead of being read as absent"
# ---------------------------------------------------------------------------
printf 'name: Bug\nlabels: ["triage:pending"]\n' > "${FORMS}/bug.yml"
reset_state
: > "${WRITE_LOG}"
if output="$(GH_FAIL_HARD=1 run_sync)"; then
    fail "the run should have aborted"
else
    pass "the run aborts"
fi
assert_eq "$(wc -c < "${WRITE_LOG}" | tr -d ' ')" "0" "it created nothing on the way out"
if grep -q 'could not read label' <<< "${output}"; then
    pass "it reports the API error rather than the label"
else
    fail "it reports the API error rather than the label"
fi

# ---------------------------------------------------------------------------
echo
echo "--dry-run reports what it would do and writes nothing"
# ---------------------------------------------------------------------------
reset_state
: > "${WRITE_LOG}"
output="$(run_sync --dry-run)" || fail "the dry run exited non-zero"
assert_eq "$(wc -c < "${WRITE_LOG}" | tr -d ' ')" "0" "no write"
if grep -q "Done: ${expected_count} created" <<< "${output}"; then
    pass "it still reports the ${expected_count} labels it would create"
else
    fail "it still reports the ${expected_count} labels it would create"
fi

# ---------------------------------------------------------------------------
echo
echo "The real issue forms declare only labels this script creates"
# ---------------------------------------------------------------------------
# The one case that reads the repository rather than a fixture: it is the
# assertion that .github/ISSUE_TEMPLATE and this script have not drifted
# apart, and it is worth having precisely because nothing else notices.
reset_state
if "${SYNC_SCRIPT}" --repo "acme/widgets" --dry-run >/dev/null 2>&1; then
    pass ".github/ISSUE_TEMPLATE and LABELS agree"
else
    fail ".github/ISSUE_TEMPLATE and LABELS agree"
fi

echo
echo "─────────────────────────────────────────"
echo "  ${PASS_COUNT} passed, ${FAIL_COUNT} failed"
echo "─────────────────────────────────────────"
[[ "${FAIL_COUNT}" -eq 0 ]] || exit 1
