#!/usr/bin/env bash
# Tests for scripts/release.sh's own logic, the way
# scripts/check-sonar-release.test.sh covers the Sonar gate's — mocked,
# no network, no release, nothing published.
#
# Two things are pinned here, and both are things that went wrong for
# real rather than things that looked fragile.
#
# 1. THE VERSION ARITHMETIC. `git describe --tags --abbrev=0` returns the
#    newest tag, and this repository carries moving ones the dev channel
#    republishes (`dev-latest`, `dev-build`). Reading one of those as "the
#    current version" produced "dev-latest..1", which was written to
#    VERSION, committed and PUSHED to main before anything checked it —
#    the run only died afterwards, on an invalid tag name.
#
# 2. THE FAST-GATE ORDER. run_fast_gate() runs the gates that finish in
#    seconds before any long one starts, and stops the release at the
#    first refusal. It must also run its gate function under `set +e`,
#    because several gate functions are written for errexit being off and
#    say so in their own comments — check_sonar_gate's `|| exit 1` only
#    makes sense that way.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_SH="${REPO_ROOT}/scripts/release.sh"
FAILURES=0

ok() {
    local message="$1"
    echo "  ok   — ${message}"
    return 0
}

fail() {
    local message="$1"
    echo "  FAIL — ${message}" >&2
    FAILURES=$((FAILURES + 1))
    return 0
}

# ---------------------------------------------------------------
# 1. Version arithmetic
# ---------------------------------------------------------------
echo "Version arithmetic:"

# The same --match the script uses, applied to a scratch repository whose
# tags deliberately include the moving ones.
scratch="$(mktemp -d)"
(
    cd "${scratch}" || exit 1
    git init -q .
    git -c user.email=t@test.invalid -c user.name=T commit -q --allow-empty -m one
    git tag v1.0.38
    git -c user.email=t@test.invalid -c user.name=T commit -q --allow-empty -m two
    git tag dev-build
    git tag dev-latest
) > /dev/null 2>&1

described="$(cd "${scratch}" && git describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' 2>/dev/null)"
if [[ "${described}" == "v1.0.38" ]]; then
    ok "the moving dev tags are ignored (got ${described})"
else
    fail "expected v1.0.38 from --match, got '${described}'"
fi

unmatched="$(cd "${scratch}" && git describe --tags --abbrev=0 2>/dev/null)"
if [[ "${unmatched}" != "v1.0.38" ]]; then
    ok "and without --match it really would have read '${unmatched}' — the bug this pins"
else
    fail "the scratch repository does not reproduce the original hazard"
fi
rm -rf "${scratch}"

# The guard that stands between the arithmetic and the first side effect.
guard_rejects() {
    local candidate="$1"
    [[ "${candidate}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] && return 1
    return 0
}
for bad in "dev-latest..1" "v1.0.39" "1.0" "" "1.0.39-rc1"; do
    if guard_rejects "${bad}"; then
        ok "guard refuses '${bad}'"
    else
        fail "guard accepted '${bad}'"
    fi
done
if guard_rejects "1.0.39"; then
    fail "guard refused a valid version"
else
    ok "guard accepts 1.0.39"
fi

if grep -q "match 'v\[0-9\]\*\.\[0-9\]\*\.\[0-9\]\*'" "${RELEASE_SH}"; then
    ok "release.sh restricts git describe to release tags"
else
    fail "release.sh no longer restricts git describe to release tags"
fi

# ---------------------------------------------------------------
# 2. Fast-gate ordering
# ---------------------------------------------------------------
echo "Fast gates:"

GATE_TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${GATE_TMP_DIR}"' EXIT
GATE_KEYS=(); GATE_LABELS=(); GATE_PIDS=(); GATE_EXIT=()

# The real function, lifted out of the script under test rather than
# copied into it — a reimplementation here would pin nothing.
eval "$(sed -n '/^run_fast_gate() {/,/^}/p' "${RELEASE_SH}")"

passing_gate() {
    echo "verified" > "${GATE_REPORT_FILE}"
    return 0
}

# `return 1`, not `exit 1`: run_fast_gate runs a gate as `( set +e; "$func"; exit $? )`,
# so the two are the same refusal — and a return is what a real gate does.
refusing_gate() {
    echo "the reason nobody should have to guess" >&2
    return 1
}

# The point of this one: under `set +e` a failing command does NOT end the
# function, so the line after it still runs and the gate still succeeds.
errexit_gate() {
    false
    echo "reached" > "${GATE_REPORT_FILE}"
    return 0
}

if ( run_fast_gate p "Pass" passing_gate ) > /dev/null 2>&1; then
    ok "a passing gate lets the release continue"
else
    fail "a passing gate stopped the release"
fi
if [[ "$(cat "${GATE_TMP_DIR}/p.report" 2>/dev/null)" == "verified" ]]; then
    ok "and its report reaches the release notes"
else
    fail "the passing gate's report was not written"
fi

output="$( ( run_fast_gate f "Refuse" refusing_gate; echo "CONTINUED" ) 2>&1 )"
if grep -q "CONTINUED" <<< "${output}"; then
    fail "a refusing gate did NOT stop the release — long gates would still start"
else
    ok "a refusing gate stops the release before any long gate starts"
fi
if grep -q "gate FAILED" <<< "${output}" && grep -q "nobody should have to guess" <<< "${output}"; then
    ok "and it prints the gate's own reason, not just a status"
else
    fail "the refusal did not surface the gate's own output"
fi

if ( run_fast_gate e "Errexit" errexit_gate ) > /dev/null 2>&1; then
    ok "the gate function runs under set +e, as launch_gate runs it"
else
    fail "errexit is on inside run_fast_gate — gate functions written for set +e will abort early"
fi

# The whole point of the change: nothing long may be launched before the
# fast ones have run.
fast_line="$(grep -n 'run_fast_gate sonar' "${RELEASE_SH}" | cut -d: -f1)"
slow_line="$(grep -n 'launch_gate tests' "${RELEASE_SH}" | cut -d: -f1)"
if [[ -n "${fast_line}" && -n "${slow_line}" && "${fast_line}" -lt "${slow_line}" ]]; then
    ok "every fast gate is declared before the first long one"
else
    fail "a long gate is launched before the fast gates have run (sonar@${fast_line}, tests@${slow_line})"
fi

echo ""
if [[ "${FAILURES}" -eq 0 ]]; then
    echo "release.sh self-tests: all passed."
    exit 0
fi
echo "release.sh self-tests: ${FAILURES} failure(s)." >&2
exit 1
