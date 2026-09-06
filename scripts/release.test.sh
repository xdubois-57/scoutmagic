#!/usr/bin/env bash
# Tests for scripts/release.sh's own logic, the way
# scripts/check-sonar-release.test.sh covers the Sonar gate's — mocked,
# no network, no release, nothing published.
#
# Three things are pinned here, and the first two are things that went
# wrong for real rather than things that looked fragile.
#
# 1. THE VERSION ARITHMETIC. `git describe --tags --abbrev=0` returns the
#    newest tag, and this repository carries moving ones the dev channel
#    republishes (`dev-latest`, `dev-build`). Reading one of those as "the
#    current version" produced "dev-latest..1", which was written to
#    VERSION, committed and PUSHED to main before anything checked it —
#    the run only died afterwards, on an invalid tag name.
#
# 2. THE REFUSAL. run_gate() stops the release at the first gate that
#    refuses, and must run its gate function under `set +e`, because
#    several gate functions are written for errexit being off and say so
#    in their own comments — check_sonar_gate's `|| exit 1` only makes
#    sense that way. A gate whose refusal did not stop the script would
#    let the next one commit and tag.
#
# 3. WHAT THIS SCRIPT NO LONGER RUNS. PHPUnit, the browser suite and the
#    dynamic scan moved to the runner (see the script's own header), and
#    the value of that move is entirely in them not being here: a copy
#    left behind would be the untrustworthy verdict again, on one engine,
#    proving nothing the pack can show. So their absence is asserted
#    rather than assumed.
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
# 2. Gate execution
# ---------------------------------------------------------------
echo "Gate execution:"

GATE_TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${GATE_TMP_DIR}"' EXIT
GATE_KEYS=(); GATE_LABELS=()

# The real function, lifted out of the script under test rather than
# copied into it — a reimplementation here would pin nothing.
eval "$(sed -n '/^run_gate() {/,/^}/p' "${RELEASE_SH}")"

passing_gate() {
    echo "verified" > "${GATE_REPORT_FILE}"
    return 0
}

# `return 1`, not `exit 1`: run_gate runs a gate as `( set +e; "$func"; exit $? )`,
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

if ( run_gate p "Pass" passing_gate ) > /dev/null 2>&1; then
    ok "a passing gate lets the release continue"
else
    fail "a passing gate stopped the release"
fi
if [[ "$(cat "${GATE_TMP_DIR}/p.report" 2>/dev/null)" == "verified" ]]; then
    ok "and its report reaches the release notes"
else
    fail "the passing gate's report was not written"
fi

output="$( ( run_gate f "Refuse" refusing_gate; echo "CONTINUED" ) 2>&1 )"
if grep -q "CONTINUED" <<< "${output}"; then
    fail "a refusing gate did NOT stop the release — the next gate would commit and tag"
else
    ok "a refusing gate stops the release"
fi
if grep -q "blocked by the Refuse gate" <<< "${output}" && grep -q "nobody should have to guess" <<< "${output}"; then
    ok "and it prints the gate's own reason, not just a status"
else
    fail "the refusal did not surface the gate's own output"
fi

if ( run_gate e "Errexit" errexit_gate ) > /dev/null 2>&1; then
    ok "the gate function runs under set +e, as the script runs it"
else
    fail "errexit is on inside run_gate — gate functions written for set +e will abort early"
fi

# The order is the documented one, and it is the order of the file.
expected_order="deployment ci security dependency sonar"
# [[:space:]], not \s: BSD grep — the one a macOS releaser runs, and this
# script is written for the same machine scripts/release.sh caffeinates —
# does not know \s in an ERE. It would match nothing, actual_order would be
# empty, and this would report a gate-order failure that is not one.
actual_order="$(grep -oE '^[[:space:]]*run_gate [a-z]+' "${RELEASE_SH}" | awk '{print $2}' | tr '\n' ' ' | sed 's/ $//')"
if [[ "${actual_order}" == "${expected_order}" ]]; then
    ok "the five gates run in the documented order (${actual_order})"
else
    fail "gate order is '${actual_order}', expected '${expected_order}'"
fi

# ---------------------------------------------------------------
# 3. The long gates are gone, and stay gone
# ---------------------------------------------------------------
echo "What the script no longer runs:"

# Comments stripped first: the script's header explains at length what it
# used to run and why that moved, and a grep that could not tell the
# explanation from a call would forbid writing the explanation down.
CODE_ONLY="$(mktemp)"
grep -v '^[[:space:]]*#' "${RELEASE_SH}" > "${CODE_ONLY}"

for forbidden in "vendor/bin/phpunit" "npm run e2e" "npm run test:coverage" "scripts/dast.sh" "vendor/bin/phpstan"; do
    if grep -qF -- "${forbidden}" "${CODE_ONLY}"; then
        fail "release.sh runs '${forbidden}' again — that verdict belongs on the runner, where both engines and the evidence pack are"
    else
        ok "does not run '${forbidden}' itself"
    fi
done

rm -f "${CODE_ONLY}"

# What replaces them: the verdict GitHub reached on the released commit.
if grep -q 'check-runs?check_name=' "${RELEASE_SH}" && grep -q 'CI_VERDICT_CHECK' "${RELEASE_SH}"; then
    ok "reads the CI verdict for the commit being released instead"
else
    fail "nothing reads the CI verdict — a release could be cut on a commit nothing has judged"
fi

if grep -q 'git status --porcelain' "${RELEASE_SH}"; then
    ok "and refuses a dirty tree, which that verdict would not describe"
else
    fail "a dirty working tree is no longer refused — the artifact would ship untested files"
fi

echo ""
if [[ "${FAILURES}" -eq 0 ]]; then
    echo "release.sh self-tests: all passed."
    exit 0
fi
echo "release.sh self-tests: ${FAILURES} failure(s)." >&2
exit 1
