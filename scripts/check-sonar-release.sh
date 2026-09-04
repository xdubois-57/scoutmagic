#!/bin/bash
set -euo pipefail

# Usage: ./scripts/check-sonar-release.sh
#
# The SonarQube Cloud release gate for scripts/release.sh. Called by that
# script's check_sonar_gate as the last pre-release check, after the
# deployment, security (CodeQL/Dependabot), tests (PHPStan/PHPUnit), and
# dependency freshness gates.
#
# A release is refused when, for the branch currently being released
# (SONAR_BRANCH, default "main"):
#   1. any unresolved SECURITY-impact issue is active, OR
#   2. any unresolved issue at software-quality severity HIGH or BLOCKER
#      (the only severity above HIGH in SonarQube's current model:
#      INFO < LOW < MEDIUM < HIGH < BLOCKER) is active, OR
#   3. any Security Hotspot is still awaiting triage (status TO_REVIEW —
#      an unreviewed hotspot is an unresolved security question, treated
#      the same as an active security finding), OR
#   4. the Quality Gate computed for that analysis is not OK, for any
#      reason — including a coverage condition (e.g. new_coverage): PHP
#      (PHPUnit + Playwright E2E) and JavaScript (Vitest) coverage are both
#      wired into SonarQube Cloud (see sonar-project.properties), so a
#      coverage-on-new-code failure is a real, actionable finding, not an
#      unachievable condition.
#
# This is deliberately FAIL CLOSED (see README.md / AGENTS.md § Releases):
# anything that prevents a definitive PASS answer — no token, an
# unreachable host, a non-200/401/403 response, invalid JSON, or no
# SonarQube analysis confirmed to correspond to the exact commit being
# released — blocks the release. This script itself has no self-bypass:
# every invocation runs the full check. scripts/release.sh's
# --skip-sonar-gate flag (mirroring its sibling gates' --skip-*-gate
# flags) controls only whether release.sh calls this script at all —
# emergency use only, prints a warning, and is never meant to be reached
# for on your own initiative to work around a genuine finding. See
# AGENTS.md § Releases for the full rationale.
#
# Requires: curl, php, git. Requires SONAR_TOKEN in the environment, or in
# the local, gitignored .sonar-token file (see below) — never in a file
# committed to this repository.
#
# Env vars (all optional except SONAR_TOKEN, which may instead come from
# .sonar-token):
#   SONAR_TOKEN         SonarQube Cloud token. No default.
#   SONAR_HOST_URL      Default: https://sonarcloud.io
#   SONAR_PROJECT_KEY   Default: xdubois-57_scoutmagic
#   SONAR_BRANCH        Default: main
#
# Local token storage: if SONAR_TOKEN isn't set in the environment, this
# script falls back to <repo root>/.sonar-token (one line, no quoting) so a
# developer running releases locally isn't asked to paste the token every
# time. If that file doesn't exist either and stdin is a terminal, the
# script prompts for the token interactively and offers to save it — but
# only ever writes it after confirming via `git check-ignore` that the
# file is actually gitignored; if git does not report it as ignored, the
# script refuses to write the token anywhere and fails closed instead. In
# any non-interactive context (CI, an agent run with no TTY) with no token
# available, the gate fails closed exactly as before this feature existed.

SONAR_HOST_URL="${SONAR_HOST_URL:-https://sonarcloud.io}"
SONAR_PROJECT_KEY="${SONAR_PROJECT_KEY:-xdubois-57_scoutmagic}"
SONAR_BRANCH="${SONAR_BRANCH:-main}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Overridable so check-sonar-release.test.sh can point this at a throwaway
# path instead of ever touching a real developer's ${REPO_ROOT}/.sonar-token.
SONAR_TOKEN_FILE="${SONAR_TOKEN_FILE:-${REPO_ROOT}/.sonar-token}"

echo "Checking SonarQube Cloud..."

fail() {
    echo "SonarQube release gate: FAIL" >&2
    echo "Release aborted." >&2
    exit 1
}

command -v curl &> /dev/null || { echo "ERROR: curl is required for the SonarQube release gate." >&2; fail; }
command -v php &> /dev/null || { echo "ERROR: php is required for the SonarQube release gate." >&2; fail; }
command -v git &> /dev/null || { echo "ERROR: git is required for the SonarQube release gate." >&2; fail; }

if [[ -z "${SONAR_TOKEN:-}" && -f "${SONAR_TOKEN_FILE}" ]]; then
    SONAR_TOKEN="$(cat "${SONAR_TOKEN_FILE}")"
fi

if [[ -z "${SONAR_TOKEN:-}" && -t 0 && -t 1 ]]; then
    if ! git -C "${REPO_ROOT}" check-ignore -q -- "${SONAR_TOKEN_FILE}"; then
        echo "ERROR: refusing to store a SonarQube token in ${SONAR_TOKEN_FILE} — git does not report this path as ignored (check .gitignore)." >&2
        fail
    fi

    echo "SONAR_TOKEN is not set." >&2
    read -r -s -p "Enter your SonarQube Cloud token (saved locally to ${SONAR_TOKEN_FILE}, gitignored, never printed): " SONAR_TOKEN
    echo >&2

    if [[ -z "${SONAR_TOKEN}" ]]; then
        echo "ERROR: no token entered." >&2
        fail
    fi

    ( umask 077 && printf '%s' "${SONAR_TOKEN}" > "${SONAR_TOKEN_FILE}" )
    echo "Token saved to ${SONAR_TOKEN_FILE} (mode 600, gitignored)." >&2
fi

if [[ -z "${SONAR_TOKEN:-}" ]]; then
    echo "ERROR: SONAR_TOKEN is not set (checked the environment, ${SONAR_TOKEN_FILE}, and — no terminal attached — could not prompt for it)." >&2
    fail
fi

# Performs a GET against the SonarQube Cloud Web API and prints the response
# body on success. Fails closed on any transport error, non-200 status, or
# non-JSON body — never guesses, never treats an unreadable response as "no
# findings". The token itself is never echoed anywhere.
sonar_get() {
    local path="$1"
    local tmp_body http_code

    tmp_body="$(mktemp)"
    if ! http_code="$(curl -sS --max-time 30 -o "${tmp_body}" -w '%{http_code}' \
            -H "Authorization: Bearer ${SONAR_TOKEN}" \
            "${SONAR_HOST_URL}${path}")"; then
        echo "ERROR: request to SonarQube Cloud failed (network error) for: ${path}" >&2
        rm -f "${tmp_body}"
        return 1
    fi

    if [[ "${http_code}" == "401" || "${http_code}" == "403" ]]; then
        echo "ERROR: SonarQube Cloud authentication failed (HTTP ${http_code}) — check SONAR_TOKEN." >&2
        rm -f "${tmp_body}"
        return 1
    fi

    if [[ "${http_code}" != "200" ]]; then
        echo "ERROR: SonarQube Cloud returned HTTP ${http_code} for: ${path}" >&2
        rm -f "${tmp_body}"
        return 1
    fi

    if ! php -r '
        $d = json_decode(file_get_contents($argv[1]), true);
        exit(json_last_error() === JSON_ERROR_NONE && is_array($d) ? 0 : 1);
    ' "${tmp_body}"; then
        echo "ERROR: SonarQube Cloud returned an invalid JSON response for: ${path}" >&2
        rm -f "${tmp_body}"
        return 1
    fi

    cat "${tmp_body}"
    rm -f "${tmp_body}"
}

# php_get <json-file> <expression-using-\$d> — evaluates a small PHP
# expression against the decoded response, used below to keep each
# extraction a single readable line instead of hand-parsing JSON in bash.
php_get() {
    local json_file="$1" expression="$2"
    php -r '
        $d = json_decode(file_get_contents($argv[1]), true);
        echo eval("return " . $argv[2] . ";");
    ' "${json_file}" "${expression}"
    return $?
}

# --- 1. Confirm a SonarQube analysis exists for the exact commit being released ---
# Fail closed if the branch has no analysis yet, or its most recent analysis
# does not correspond to the local HEAD commit — this is exactly the "l'état
# Sonar ne peut pas être confirmé pour ce commit" case that must block.
LOCAL_HEAD="$(git rev-parse HEAD)"

ANALYSES_FILE="$(mktemp)"
if ! sonar_get "/api/project_analyses/search?project=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_PROJECT_KEY}")&branch=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_BRANCH}")&ps=1" > "${ANALYSES_FILE}"; then
    rm -f "${ANALYSES_FILE}"
    fail
fi

ANALYSIS_REVISION="$(php_get "${ANALYSES_FILE}" '$d["analyses"][0]["revision"] ?? ""')"
rm -f "${ANALYSES_FILE}"

if [[ -z "${ANALYSIS_REVISION}" ]]; then
    echo "ERROR: no SonarQube Cloud analysis found for branch '${SONAR_BRANCH}' of project '${SONAR_PROJECT_KEY}'." >&2
    fail
fi

if [[ "${ANALYSIS_REVISION}" != "${LOCAL_HEAD}" ]]; then
    echo "ERROR: the latest SonarQube Cloud analysis on '${SONAR_BRANCH}' is for commit ${ANALYSIS_REVISION}, not the commit being released (${LOCAL_HEAD})." >&2
    echo "Wait for CI to finish analyzing this exact commit, then re-run." >&2
    fail
fi

# --- 2. Quality Gate ---
QG_FILE="$(mktemp)"
if ! sonar_get "/api/qualitygates/project_status?projectKey=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_PROJECT_KEY}")&branch=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_BRANCH}")" > "${QG_FILE}"; then
    rm -f "${QG_FILE}"
    fail
fi
QUALITY_GATE_STATUS="$(php_get "${QG_FILE}" '$d["projectStatus"]["status"] ?? ""')"

if [[ -z "${QUALITY_GATE_STATUS}" ]]; then
    rm -f "${QG_FILE}"
    echo "ERROR: could not determine the SonarQube Cloud Quality Gate status." >&2
    fail
fi

# Any non-OK status blocks, full stop — coverage conditions included (see
# the header comment above).
QUALITY_GATE_BLOCKS="$(php_get "${QG_FILE}" '($d["projectStatus"]["status"] ?? "") === "ERROR" ? "1" : "0"')"
rm -f "${QG_FILE}"

# --- 3. Unresolved SECURITY-impact issues (any severity) ---
SECURITY_ISSUES_FILE="$(mktemp)"
if ! sonar_get "/api/issues/search?componentKeys=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_PROJECT_KEY}")&branch=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_BRANCH}")&resolved=false&impactSoftwareQualities=SECURITY&ps=100" > "${SECURITY_ISSUES_FILE}"; then
    rm -f "${SECURITY_ISSUES_FILE}"
    fail
fi
SECURITY_ISSUES_COUNT="$(php_get "${SECURITY_ISSUES_FILE}" '$d["paging"]["total"] ?? $d["total"] ?? 0')"
SECURITY_ISSUES_KEYS="$(php_get "${SECURITY_ISSUES_FILE}" 'implode("\n", array_slice(array_column($d["issues"] ?? [], "key"), 0, 10))')"
rm -f "${SECURITY_ISSUES_FILE}"

# --- 4. Unreviewed Security Hotspots ---
HOTSPOTS_FILE="$(mktemp)"
if ! sonar_get "/api/hotspots/search?projectKey=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_PROJECT_KEY}")&branch=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_BRANCH}")&status=TO_REVIEW&ps=100" > "${HOTSPOTS_FILE}"; then
    rm -f "${HOTSPOTS_FILE}"
    fail
fi
HOTSPOTS_COUNT="$(php_get "${HOTSPOTS_FILE}" '$d["paging"]["total"] ?? 0')"
HOTSPOTS_KEYS="$(php_get "${HOTSPOTS_FILE}" 'implode("\n", array_slice(array_column($d["hotspots"] ?? [], "key"), 0, 10))')"
rm -f "${HOTSPOTS_FILE}"

SECURITY_TOTAL=$((SECURITY_ISSUES_COUNT + HOTSPOTS_COUNT))

# --- 5. Unresolved HIGH-severity issues (any software quality) ---
HIGH_FILE="$(mktemp)"
if ! sonar_get "/api/issues/search?componentKeys=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_PROJECT_KEY}")&branch=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_BRANCH}")&resolved=false&impactSeverities=HIGH&ps=100" > "${HIGH_FILE}"; then
    rm -f "${HIGH_FILE}"
    fail
fi
HIGH_COUNT="$(php_get "${HIGH_FILE}" '$d["paging"]["total"] ?? $d["total"] ?? 0')"
HIGH_KEYS="$(php_get "${HIGH_FILE}" 'implode("\n", array_slice(array_column($d["issues"] ?? [], "key"), 0, 10))')"
rm -f "${HIGH_FILE}"

# --- 6. Unresolved issues above HIGH (BLOCKER — the only higher level in the
#         current software-quality severity model: INFO < LOW < MEDIUM < HIGH
#         < BLOCKER). Kept as its own query so a future additional level
#         above BLOCKER would need this list updated deliberately, not
#         silently included or excluded. ---
HIGHER_FILE="$(mktemp)"
if ! sonar_get "/api/issues/search?componentKeys=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_PROJECT_KEY}")&branch=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_BRANCH}")&resolved=false&impactSeverities=BLOCKER&ps=100" > "${HIGHER_FILE}"; then
    rm -f "${HIGHER_FILE}"
    fail
fi
HIGHER_COUNT="$(php_get "${HIGHER_FILE}" '$d["paging"]["total"] ?? $d["total"] ?? 0')"
HIGHER_KEYS="$(php_get "${HIGHER_FILE}" 'implode("\n", array_slice(array_column($d["issues"] ?? [], "key"), 0, 10))')"
rm -f "${HIGHER_FILE}"

echo "Quality Gate: ${QUALITY_GATE_STATUS}"
echo "Security findings: ${SECURITY_TOTAL}"
echo "HIGH findings: ${HIGH_COUNT}"
echo "Higher-severity findings: ${HIGHER_COUNT}"

BLOCKED=0

if [[ "${QUALITY_GATE_BLOCKS}" == "1" ]]; then
    BLOCKED=1
fi
if [[ "${SECURITY_TOTAL}" -gt 0 ]]; then
    BLOCKED=1
fi
if [[ "${HIGH_COUNT}" -gt 0 ]]; then
    BLOCKED=1
fi
if [[ "${HIGHER_COUNT}" -gt 0 ]]; then
    BLOCKED=1
fi

if [[ "${BLOCKED}" -eq 1 ]]; then
    {
        [[ -n "${SECURITY_ISSUES_KEYS}" ]] && { echo "Blocking security issues:"; printf '  %s\n' ${SECURITY_ISSUES_KEYS}; }
        [[ -n "${HOTSPOTS_KEYS}" ]] && { echo "Unreviewed security hotspots:"; printf '  %s\n' ${HOTSPOTS_KEYS}; }
        [[ -n "${HIGH_KEYS}" ]] && { echo "Blocking HIGH-severity issues:"; printf '  %s\n' ${HIGH_KEYS}; }
        [[ -n "${HIGHER_KEYS}" ]] && { echo "Blocking BLOCKER-severity issues:"; printf '  %s\n' ${HIGHER_KEYS}; }
        echo "See: ${SONAR_HOST_URL}/project/issues?id=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_PROJECT_KEY}")&branch=$(php -r 'echo rawurlencode($argv[1]);' "${SONAR_BRANCH}")&resolved=false"
    } >&2
    fail
fi

echo "SonarQube release gate: PASS"
