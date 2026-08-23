#!/bin/bash
set -euo pipefail

# Usage: ./scripts/release.sh [--minor|--major] [--notes-file <path>]
#                             [--skip-security-gate] [--skip-tests-gate]
#                             [--skip-e2e-gate] [--skip-dependency-check]
#                             [--skip-deployment-check] [--skip-sonar-gate]
# Default: increments patch level, computes release notes from the commit
# list (fetched via the same GitHub API `--generate-notes` itself calls),
# and requires the deployment gate, the security gate, the tests gate,
# the end-to-end gate, the dependency freshness gate, and the SonarQube
# Cloud gate to all pass. Every non-skipped gate runs concurrently (see
# "Gate execution" below) — a failure in one is never masked by another
# still running, and if several fail, all of them are reported together
# rather than stopping at the first.
# Whichever notes are used (this auto-generated list, or --notes-file's
# content), a "Vérifications effectuées" section reporting every gate's
# outcome (verified, with details, or bypassed) is always appended at the
# end — see ${GATE_REPORT} and the "Gate execution" block below.
#
#   --notes-file <path>        Use the release notes from this file
#                               instead of the auto-generated commit list.
#                               See AGENTS.md "Releases" for what a
#                               Claude-authored notes file must contain.
#   --skip-security-gate       Bypass the CodeQL/Dependabot check.
#                               Emergency use only — prints a warning. See
#                               check_security_gate.
#   --skip-tests-gate          Bypass phpstan/phpunit AND the JavaScript
#                               portion (npm run typecheck static analysis
#                               + npm run test:coverage unit tests).
#                               Emergency use only — prints a warning. See
#                               check_tests_gate.
#   --skip-e2e-gate            Bypass the end-to-end browser test
#                               (npm run e2e). Emergency use only — prints
#                               a warning. Separate from --skip-tests-gate
#                               on purpose: this is the only gate needing
#                               a MySQL server and a Chromium binary, and
#                               a releaser missing either must not have to
#                               drop PHPStan/PHPUnit/Vitest to get past it.
#                               See check_e2e_gate.
#   --skip-dependency-check    Bypass the outdated-dependency check
#                               (direct Composer packages + every
#                               vendored front-end library — Bootstrap,
#                               Bootstrap Icons, Chart.js). Emergency use
#                               only — prints a warning. See
#                               check_dependency_freshness_gate.
#   --skip-deployment-check    Bypass the production deployment check
#                               (www.scoutmagic.be up to date and healthy).
#                               Emergency use only — prints a warning. See
#                               check_deployment_gate.
#   --skip-sonar-gate          Bypass the SonarQube Cloud check (active
#                               security findings, HIGH-or-above severity
#                               findings, unreviewed Security Hotspots, the
#                               Quality Gate). Emergency use only — prints
#                               a warning. See check_sonar_gate.

BUMP="patch"
NOTES_FILE=""
SKIP_SECURITY_GATE=0
SKIP_TESTS_GATE=0
SKIP_E2E_GATE=0
SKIP_DEPENDENCY_CHECK=0
SKIP_DEPLOYMENT_CHECK=0
SKIP_SONAR_GATE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --minor) BUMP="minor"; shift ;;
        --major) BUMP="major"; shift ;;
        --notes-file)
            NOTES_FILE="${2:-}"
            [[ -n "${NOTES_FILE}" ]] || { echo "ERROR: --notes-file requires a path argument." >&2; exit 1; }
            [[ -f "${NOTES_FILE}" ]] || { echo "ERROR: --notes-file path does not exist: ${NOTES_FILE}" >&2; exit 1; }
            shift 2
            ;;
        --skip-security-gate) SKIP_SECURITY_GATE=1; shift ;;
        --skip-tests-gate) SKIP_TESTS_GATE=1; shift ;;
        --skip-e2e-gate) SKIP_E2E_GATE=1; shift ;;
        --skip-dependency-check) SKIP_DEPENDENCY_CHECK=1; shift ;;
        --skip-deployment-check) SKIP_DEPLOYMENT_CHECK=1; shift ;;
        --skip-sonar-gate) SKIP_SONAR_GATE=1; shift ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            echo "Usage: $0 [--minor|--major] [--notes-file <path>] [--skip-security-gate] [--skip-tests-gate] [--skip-e2e-gate] [--skip-dependency-check] [--skip-deployment-check] [--skip-sonar-gate]" >&2
            exit 1
            ;;
    esac
done

# Get current version from latest git tag (default 0.0.0 if no tags)
CURRENT=$(git describe --tags --abbrev=0 2>/dev/null || echo "v0.0.0")
CURRENT="${CURRENT#v}"  # strip leading v

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

case $BUMP in
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    patch) PATCH=$((PATCH + 1)) ;;
    *) echo "ERROR: unexpected BUMP value: ${BUMP}" >&2; exit 1 ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
TAG="v${NEW_VERSION}"

echo "Bumping version: ${CURRENT} → ${NEW_VERSION}"

PRODUCTION_URL="https://www.scoutmagic.be"

# ${GATE_REPORT} — one Markdown bullet per gate (verified or bypassed), in
# French since it's appended to the public release notes (see the
# "Vérifications effectuées" block near the end of this script) — unlike
# this script's own English console output, the release notes are
# user-facing text read by site administrators. Built once, after every
# gate below has run (they run in parallel, each in its own subshell —
# see the "Gate execution" block), from each *_GATE_REPORT_LINE variable:
# already set directly for a skipped gate, or read back from that gate's
# own report file otherwise (a subshell's variable assignments never
# reach this parent shell).

# ---------------------------------------------------------------
# Deployment gate — verifies the PREVIOUS release actually reached
# production before a new one is created, and that production isn't
# currently broken. Exposed via GET /api/version (Core\Http\Controller\
# VersionController, role_min: public — see its own docblock), which
# reports the same version already shown to a logged-in admin on
# Configuration > Maintenance. Two cases, matching VersionFile's own
# format:
#   - dev build: the bare "version":"dev" response never discloses the
#     real commit (audit hardening — it would fingerprint exactly which
#     patches an install is missing), so this asks the one question that
#     needs answering instead: GET .../api/version?commit=<local HEAD
#     short sha> back, and check its own "matches" boolean rather than
#     comparing a value it was never given.
#   - stable build (a semver tag): the reported version must equal
#     ${CURRENT}, the latest tag before this run's bump — i.e. the last
#     release already installed itself.
# A plain GET / is also checked for a 200 status and no obvious error
# text, since a stuck update or a fatal error wouldn't otherwise surface
# here. Runs BEFORE any git commit/tag, same reasoning as the other
# gates. Fails closed: any request error also aborts the release.
# ---------------------------------------------------------------
check_deployment_gate() {
    command -v curl &> /dev/null || { echo "ERROR: curl is required for the deployment gate." >&2; exit 1; }
    command -v php &> /dev/null || { echo "ERROR: php is required for the deployment gate." >&2; exit 1; }

    local version_json remote_version local_head_short match_json matches report_suffix home_body home_status

    version_json="$(curl -fsS --max-time 15 "${PRODUCTION_URL}/api/version")" \
        || { echo "ERROR: cannot reach ${PRODUCTION_URL}/api/version." >&2; exit 1; }
    remote_version="$(php -r '$d=json_decode(file_get_contents("php://stdin"), true); echo $d["version"] ?? "";' <<< "${version_json}")"

    if [[ "${remote_version}" == "dev" ]]; then
        local_head_short="$(git rev-parse --short=7 HEAD)"
        match_json="$(curl -fsS --max-time 15 "${PRODUCTION_URL}/api/version?commit=${local_head_short}")" \
            || { echo "ERROR: cannot reach ${PRODUCTION_URL}/api/version?commit=${local_head_short}." >&2; exit 1; }
        matches="$(php -r '$d=json_decode(file_get_contents("php://stdin"), true); echo ($d["matches"] ?? false) ? "1" : "";' <<< "${match_json}")"
        if [[ -z "${matches}" ]]; then
            echo "ERROR: release blocked by the deployment gate — ${PRODUCTION_URL} is a dev build not yet on local HEAD (${local_head_short})." >&2
            echo "Wait for the site to pick up the latest commit (or re-run with --skip-deployment-check to bypass, emergency use only)." >&2
            exit 1
        fi
        report_suffix="dev/${local_head_short}"
    elif [[ "${remote_version}" != "${CURRENT}" ]]; then
        echo "ERROR: release blocked by the deployment gate — ${PRODUCTION_URL} reports version '${remote_version}', expected the latest released tag '${CURRENT}'." >&2
        echo "The previous release may not have deployed yet. Wait for it, or re-run with --skip-deployment-check to bypass (emergency use only)." >&2
        exit 1
    else
        report_suffix="${remote_version}"
    fi

    home_body="$(curl -fsS --max-time 15 -w '\n%{http_code}' "${PRODUCTION_URL}/")" \
        || { echo "ERROR: cannot reach ${PRODUCTION_URL}/." >&2; exit 1; }
    home_status="${home_body##*$'\n'}"
    home_body="${home_body%$'\n'"${home_status}"}"

    if [[ "${home_status}" != "200" ]]; then
        echo "ERROR: release blocked by the deployment gate — ${PRODUCTION_URL}/ returned HTTP ${home_status}." >&2
        exit 1
    fi

    if grep -qiE 'fatal error|uncaught exception|stack trace' <<< "${home_body}"; then
        echo "ERROR: release blocked by the deployment gate — ${PRODUCTION_URL}/ response looks like an error page." >&2
        exit 1
    fi

    echo "vérifié — ${PRODUCTION_URL} à jour (${report_suffix}), HTTP ${home_status}." > "${GATE_REPORT_FILE}"
    echo "Deployment gate OK: ${PRODUCTION_URL} is up to date (${report_suffix}) and responds normally."
}

# ---------------------------------------------------------------
# Security gate — runs BEFORE any git commit/tag so a blocked release
# leaves no partial state behind.
#
# A release is refused while any GitHub CodeQL scanning finding or any
# Dependabot alert is still open (state != fixed/dismissed). The version
# bump commit and tag below must only ever be created once this gate is
# green. gh api expands {owner}/{repo} from the current repo's default
# remote. Fail-closed: any query error (auth, rate limit, endpoint
# disabled) also aborts the release.
# ---------------------------------------------------------------
check_security_gate() {
    command -v gh &> /dev/null || { echo "ERROR: GitHub CLI (gh) is required for the security gate — install it and run gh auth login." >&2; exit 1; }

    local err codeql_lines dependabot_lines codeql_count dependabot_count

    # One line per OPEN CodeQL finding: "<number> <rule description>".
    err="$(mktemp)"
    codeql_lines="$(gh api "repos/{owner}/{repo}/code-scanning/alerts" --paginate \
        --jq '.[] | select(.state == "open") | "\(.number)\t\(.rule.description)"' 2>"${err}")" \
        || { echo "ERROR: cannot query CodeQL findings:" >&2; cat "${err}" >&2; rm -f "${err}"; exit 1; }
    rm -f "${err}"

    err="$(mktemp)"
    dependabot_lines="$(gh api "repos/{owner}/{repo}/dependabot/alerts" --paginate \
        --jq '.[] | select(.state == "open") | "\(.number)\t\(.security_advisory.summary)"' 2>"${err}")" \
        || { echo "ERROR: cannot query Dependabot alerts:" >&2; cat "${err}" >&2; rm -f "${err}"; exit 1; }
    rm -f "${err}"

        codeql_count="$(grep -c . <<< "${codeql_lines}" || true)"
    dependabot_count="$(grep -c . <<< "${dependabot_lines}" || true)"
    # grep -c . counts non-empty lines; here-string adds a newline so an
    # empty capture yields 0, and command substitution stripping the final
    # newline can't undercount (unlike wc -l).

    if [[ "${codeql_count}" -gt 0 || "${dependabot_count}" -gt 0 ]]; then
        echo "ERROR: release blocked by the security gate." >&2
        echo "  Open CodeQL findings: ${codeql_count}" >&2
        if [[ "${codeql_count}" -gt 0 ]]; then printf '%s\n' "${codeql_lines}" >&2; fi
        echo "  Open Dependabot alerts: ${dependabot_count}" >&2
        if [[ "${dependabot_count}" -gt 0 ]]; then printf '%s\n' "${dependabot_lines}" >&2; fi
        echo "Fix or dismiss them first (opencode should do this before asking for a release), then re-run." >&2
        exit 1
    fi

    echo "vérifié — aucun signalement CodeQL ni alerte Dependabot ouvert." > "${GATE_REPORT_FILE}"
    echo "Security gate OK: no open CodeQL findings, no open Dependabot alerts."
}

# ---------------------------------------------------------------
# Tests gate — mirrors CI's `test` (PHPStan + the COMPLETE PHPUnit suite)
# AND `javascript-tests` (JavaScript static analysis + Vitest) jobs. Runs
# BEFORE any git commit/tag, same reasoning as the security gate above.
#
# No test group is excluded, `database` included: a release must not be
# cut on a narrower suite than CI ran. Most of that group needs no MySQL
# (Tests\DatabaseTestHelper builds an in-memory SQLite database), but the
# six files reading TEST_DB_* do, so the preflight below fails closed on
# an unreachable server rather than letting a releaser discover it as ten
# confusing "Connection refused" failures — same philosophy as the
# npm/node_modules check further down. Point TEST_DB_* at any throwaway
# MySQL instance (the defaults match CI's own service container:
# 127.0.0.1:3306, database test_db, user root).
#
# The JavaScript portion — static analysis (npm run typecheck, the
# PHPStan-equivalent gate for public/assets/js/ — see AGENTS.md § Static
# analysis and README.md § Analyse statique JavaScript) then unit tests
# (npm run test:coverage), same order as CI — fails closed on a missing
# `npm`/`node_modules` rather than running `npm ci` on the releaser's
# behalf: silently installing dependencies here would mask an improperly
# prepared release environment (e.g. the wrong Node version, or a
# lockfile that was never actually validated) instead of surfacing it —
# same fail-closed philosophy as every other gate in this script. Run
# `npm ci` yourself first (see README.md § Développement) if this fails.
# ---------------------------------------------------------------
check_tests_gate() {
    local phpunit_output phpunit_summary

    echo "Running PHPStan..."
    vendor/bin/phpstan analyse --memory-limit=512M

    echo "Checking the MySQL test instance is reachable..."
    # Without a server the suite is GREEN, not broken — the server-dependent
    # tests skip cleanly. That is exactly why this check has to exist and has
    # to fail closed: a release would otherwise be cut on a run where ~28
    # tests silently skipped, which is the same "narrower suite than CI
    # actually ran" problem that removing phpunit.xml's group exclusion was
    # meant to end. A skip is easier to miss than a failure, not safer.
    #
    # An EMPTY TEST_DB_PASSWORD is rejected too, not just an unreachable
    # server: Tests\Core\Http\Controller\SetupControllerTest drives the real
    # first-time-setup form, whose own validation makes db_password
    # mandatory (Core\Http\Controller\SetupController::validateFormData()) —
    # so an empty password fails those two tests with a bare "200 is not
    # 302" that says nothing about the actual cause.
    php -r '
        $host = getenv("TEST_DB_HOST") ?: "127.0.0.1";
        $port = (int) (getenv("TEST_DB_PORT") ?: 3306);
        $name = getenv("TEST_DB_NAME") ?: "test_db";
        $user = getenv("TEST_DB_USER") ?: "root";
        $pass = getenv("TEST_DB_PASSWORD") ?: "";
        if ($pass === "") {
            fwrite(STDERR, "  TEST_DB_PASSWORD is empty — the setup-form tests require a non-empty database password." . PHP_EOL);
            exit(1);
        }
        try {
            new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
        } catch (Throwable $e) {
            fwrite(STDERR, "  {$host}:{$port}/{$name} as {$user} — " . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    ' || { echo "ERROR: the database-group tests need a reachable MySQL test instance with a non-empty password. Start one and set TEST_DB_HOST/TEST_DB_PORT/TEST_DB_NAME/TEST_DB_USER/TEST_DB_PASSWORD (see README.md § Développement), or re-run with --skip-tests-gate (emergency use only)." >&2; exit 1; }

    echo "Running PHPUnit (complete suite, database group included)..."
    # Piped through tee so the run still streams live (to stderr, since
    # stdout is captured here) rather than going silent for its whole
    # duration — pipefail (line 2) still propagates PHPUnit's own exit
    # code through the pipeline, so a failure still aborts the script via
    # set -e exactly as a direct `vendor/bin/phpunit` call would.
    phpunit_output="$(vendor/bin/phpunit 2>&1 | tee /dev/stderr)"
    # PHPUnit 13 colorizes its summary line (e.g. "\e[30;43mTests: …\e[0m")
    # even when piped through tee here, so the ANSI codes are stripped
    # before matching — otherwise the line no longer starts with "OK (" or
    # "Tests: " and grep's no-match exit 1 would abort the whole release
    # via pipefail below. `|| true` on the grep itself is a second guard:
    # if PHPUnit's summary format ever changes again, this degrades to the
    # "résumé non trouvé" fallback instead of aborting a release that
    # otherwise passed.
    phpunit_summary="$(sed -E $'s/\x1b\\[[0-9;]*m//g' <<< "${phpunit_output}" | { grep -E '^(OK \(|Tests: )' || true; } | tail -1)"
    [[ -n "${phpunit_summary}" ]] || phpunit_summary="résumé PHPUnit non trouvé dans la sortie"

    command -v npm &> /dev/null || { echo "ERROR: npm is required for the JavaScript portion of the tests gate (see package.json/README.md § Développement) — install Node.js LTS, or re-run with --skip-tests-gate (emergency use only)." >&2; exit 1; }
    [[ -d node_modules ]] || { echo "ERROR: node_modules/ not found — run 'npm ci' first (see README.md § Développement), or re-run with --skip-tests-gate (emergency use only)." >&2; exit 1; }

    echo "Running JavaScript static analysis (npm run typecheck)..."
    npm run typecheck

    echo "Running JavaScript unit tests (npm run test:coverage)..."
    npm run test:coverage

    echo "vérifié — PHPStan sans erreur ; PHPUnit : ${phpunit_summary} ; analyse statique JavaScript (npm run typecheck) : OK ; tests JavaScript (Vitest) : OK." > "${GATE_REPORT_FILE}"
    echo "Tests gate OK: PHPStan, PHPUnit, JavaScript static analysis, and JavaScript unit tests passed."
}

# ---------------------------------------------------------------
# End-to-end gate — mirrors CI's `e2e-tests` job: the real application,
# served through public/index.php by `php -S`, driven by a headless
# Chromium (Playwright) against a throwaway database. Runs BEFORE any git
# commit/tag, same reasoning as every other gate.
#
# This is the only gate that proves the composition root in
# public/index.php actually boots — PHPStan checks its argument types
# statically and PHPUnit never executes it at all, which is exactly how a
# TypeError on every request once shipped (AGENTS.md § Static analysis).
#
# Delegates wholesale to `npm run e2e` (scripts/e2e.sh): the release must
# exercise the identical orchestration a developer and CI do, never a
# second copy of it that can drift. That script provisions and tears down
# its own instance, database, and port — nothing is left running here.
#
# Fail-closed on a missing npm/node_modules, same reasoning as the tests
# gate above: silently running `npm ci` here would mask an improperly
# prepared release environment instead of surfacing it.
# ---------------------------------------------------------------
check_e2e_gate() {
    command -v npm &> /dev/null || { echo "ERROR: npm is required for the end-to-end gate (see package.json/README.md § Tests end-to-end) — install Node.js LTS, or re-run with --skip-e2e-gate (emergency use only)." >&2; exit 1; }
    [[ -d node_modules ]] || { echo "ERROR: node_modules/ not found — run 'npm ci' first (see README.md § Développement), or re-run with --skip-e2e-gate (emergency use only)." >&2; exit 1; }

    echo "Running end-to-end browser tests (npm run e2e)..."
    npm run e2e

    echo "vérifié — la page d'accueil publique démarre et s'affiche dans un vrai navigateur (Playwright/Chromium, via \`public/index.php\`)." > "${GATE_REPORT_FILE}"
    echo "E2E gate OK: the application boots and renders in a real browser."
}

# Checks one vendored front-end library's committed file against its
# latest upstream GitHub release. There's no npm/package manager for any
# of these (AGENTS.md's frontend rules — CSS/JS build tools are banned),
# so every one of them is a plain minified file committed under
# public/assets/vendor/<name>/, with its own version baked into a leading
# comment banner — that's what ${version_regex} extracts. Returns 1 (does
# NOT exit) when outdated, so check_dependency_freshness_gate below can
# check every library and report all of them together before failing;
# still exits immediately on a hard error (missing file, undetectable
# version, GitHub API failure) since those aren't a "these are the
# outdated ones" finding to aggregate — they mean the check itself
# couldn't run.
check_vendored_asset_freshness() {
    local label="$1" file="$2" version_regex="$3" repo="$4"
    local current latest err

    [[ -f "${file}" ]] || { echo "ERROR: vendored ${label} not found at ${file}." >&2; exit 1; }
    current="$(grep -oE "${version_regex}" "${file}" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
    [[ -n "${current}" ]] || { echo "ERROR: cannot determine vendored ${label} version from ${file}." >&2; exit 1; }

    err="$(mktemp)"
    latest="$(gh api "repos/${repo}/releases/latest" --jq '.tag_name' 2>"${err}" | sed 's/^v//')" \
        || { echo "ERROR: cannot query latest ${repo} release:" >&2; cat "${err}" >&2; rm -f "${err}"; exit 1; }
    rm -f "${err}"

    if [[ "${current}" != "${latest}" ]]; then
        echo "Outdated vendored dependency: ${label} ${current} → ${latest} available (https://github.com/${repo}/releases/tag/v${latest})." >&2
        return 1
    fi
    return 0
}

# ---------------------------------------------------------------
# Dependency freshness gate — checks direct Composer dependencies
# (require + require-dev) against their latest available version, and
# every vendored front-end library (public/assets/vendor/ — Bootstrap,
# Bootstrap Icons, Chart.js as of this writing; add a new
# check_vendored_asset_freshness call here whenever another one is
# vendored) against its latest upstream GitHub release. This is about
# staying current with upstream COTS releases, distinct from
# check_security_gate (which is about CVEs/Dependabot alerts already
# reported against the exact versions currently installed). Runs BEFORE
# any git commit/tag, same reasoning as the other gates. Fails closed:
# any query error also aborts the release.
# ---------------------------------------------------------------
check_dependency_freshness_gate() {
    command -v composer &> /dev/null || { echo "ERROR: composer is required for the dependency freshness gate." >&2; exit 1; }
    command -v gh &> /dev/null || { echo "ERROR: GitHub CLI (gh) is required for the dependency freshness gate — install it and run gh auth login." >&2; exit 1; }
    command -v php &> /dev/null || { echo "ERROR: php is required for the dependency freshness gate." >&2; exit 1; }

    local composer_outdated_json composer_outdated_count found_outdated
    found_outdated=0

    composer_outdated_json="$(composer outdated --direct --format=json 2>/dev/null)" \
        || { echo "ERROR: cannot query composer outdated." >&2; exit 1; }
    composer_outdated_count="$(php -r '$d=json_decode(file_get_contents("php://stdin"), true); echo count($d["installed"] ?? []);' <<< "${composer_outdated_json}")"

    if [[ "${composer_outdated_count}" -gt 0 ]]; then
        found_outdated=1
        echo "Outdated direct Composer dependencies:" >&2
        composer outdated --direct >&2
    fi

    check_vendored_asset_freshness "Bootstrap" "public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js" 'Bootstrap v[0-9]+\.[0-9]+\.[0-9]+' "twbs/bootstrap" \
        || found_outdated=1
    check_vendored_asset_freshness "Bootstrap Icons" "public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css" 'Bootstrap Icons v[0-9]+\.[0-9]+\.[0-9]+' "twbs/icons" \
        || found_outdated=1
    check_vendored_asset_freshness "Chart.js" "public/assets/vendor/chartjs/chart.umd.min.js" 'Chart\.js v[0-9]+\.[0-9]+\.[0-9]+' "chartjs/Chart.js" \
        || found_outdated=1

    if [[ "${found_outdated}" -eq 1 ]]; then
        echo "ERROR: release blocked by the dependency freshness gate — outdated dependencies found (see above)." >&2
        echo "Update them first, then re-run — or re-run with --skip-dependency-check to bypass (emergency use only)." >&2
        exit 1
    fi

    echo "vérifié — dépendances Composer directes, Bootstrap, Bootstrap Icons et Chart.js vendorisés à jour." > "${GATE_REPORT_FILE}"
    echo "Dependency freshness gate OK: direct Composer dependencies and vendored front-end libraries (Bootstrap, Bootstrap Icons, Chart.js) are up to date."
}

# ---------------------------------------------------------------
# SonarQube Cloud gate — delegates to scripts/check-sonar-release.sh (kept
# as a separate script rather than inlined here: its logic — multiple Web
# API calls, JSON parsing, fail-closed error handling — is non-trivial
# enough to warrant being testable on its own, see
# scripts/check-sonar-release.test.sh). Runs BEFORE any git commit/tag,
# same reasoning as the other gates. When not bypassed via
# --skip-sonar-gate, it always blocks the release on an active security
# finding, a HIGH-or-above severity finding, an unreviewed Security
# Hotspot, a Quality Gate that isn't OK, or any failure to reach a
# definitive answer from SonarQube Cloud (missing SONAR_TOKEN,
# unreachable host, auth failure, invalid response, or no analysis
# confirmed for the exact commit being released).
# ---------------------------------------------------------------
check_sonar_gate() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    "${script_dir}/check-sonar-release.sh"
    echo "vérifié — aucun signalement de sécurité actif, aucun problème de sévérité HIGH ou supérieure, Quality Gate OK." > "${GATE_REPORT_FILE}"
}

# ---------------------------------------------------------------
# Gate execution — every gate above is independent of every other (each
# checks a different system: production over HTTPS, GitHub's API, this
# checkout's own PHP/JS toolchain, a real browser against a throwaway
# instance, Composer/GitHub for dependency freshness, SonarQube Cloud's
# API), reads but never writes vendor/ or node_modules/, and none of them
# commits/tags/pushes anything — that only happens once every gate that
# ran has passed. Running them concurrently instead of one after another
# is purely a speed optimization, worth it because the two genuinely slow
# ones (the tests gate's full PHPUnit suite, the end-to-end browser gate)
# otherwise get paid for one after the other instead of overlapped with
# the four fast, network-bound ones.
#
# One narrow, accepted exception: the end-to-end gate wipes and
# regenerates storage/temp/twig_cache at startup (scripts/e2e-support.php
# — Twig's compiled-template cache is keyed on VERSION and anchored to
# this repository, shared with anything else that renders a view). A
# PHPUnit test rendering a real Twig view at the exact moment that happens
# could see a transient cache miss. Twig recompiles from source on a
# miss — self-healing, not a wrong result — so this can cost an occasional
# rerun, never a false pass.
#
# Each non-skipped gate runs in its own background subshell, output
# redirected to its own log file under ${GATE_TMP_DIR} — interleaved
# parallel output would be unreadable, so nothing prints live; every
# gate's log is replayed in order once all of them are known to have
# passed, and a failed gate's log (last 60 lines, plus the full path) is
# shown in the combined summary below instead. A subshell's own variable
# assignments never reach this parent shell, which is why a passing gate
# writes its report line to ${GATE_REPORT_FILE} (set per-gate by
# launch_gate) instead of setting one directly, the way the sequential
# version of this script used to. stdin is /dev/null for every gate so
# one that somehow tried to prompt interactively (check-sonar-release.sh
# asking for a missing SONAR_TOKEN, say) fails closed instead of hanging
# invisibly in the background.
#
# A skipped gate (--skip-*) is never launched at all — its warning and
# report line are exactly what the sequential version printed.
# ---------------------------------------------------------------
GATE_TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${GATE_TMP_DIR}"' EXIT

# Four parallel indexed arrays (never associative — macOS's own /bin/bash
# is still 3.2, which has no `declare -A` at all) keyed by a shared
# position: GATE_KEYS[i]/GATE_LABELS[i]/GATE_PIDS[i]/GATE_EXIT[i] all
# describe the same i-th launched gate.
GATE_KEYS=()
GATE_LABELS=()
GATE_PIDS=()
GATE_EXIT=()

launch_gate() {
    local key="$1" label="$2" func="$3"
    GATE_KEYS+=("${key}")
    GATE_LABELS+=("${label}")
    ( export GATE_REPORT_FILE="${GATE_TMP_DIR}/${key}.report"; "${func}" ) \
        > "${GATE_TMP_DIR}/${key}.log" 2>&1 < /dev/null &
    GATE_PIDS+=("$!")
}

if [[ "${SKIP_DEPLOYMENT_CHECK}" -eq 1 ]]; then
    echo "WARNING: --skip-deployment-check used — ${PRODUCTION_URL} was NOT checked for this release. Emergency use only: verify it manually right after publishing." >&2
    DEPLOYMENT_GATE_REPORT_LINE="ignoré (\`--skip-deployment-check\`) — à vérifier manuellement."
else
    launch_gate deployment "Deployment" check_deployment_gate
fi

if [[ "${SKIP_SECURITY_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-security-gate used — open CodeQL findings and/or Dependabot alerts were NOT checked for this release. Emergency use only: verify and resolve them immediately after publishing." >&2
    SECURITY_GATE_REPORT_LINE="ignoré (\`--skip-security-gate\`) — à vérifier manuellement."
else
    launch_gate security "Security" check_security_gate
fi

if [[ "${SKIP_TESTS_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-tests-gate used — PHPStan, PHPUnit, JavaScript static analysis (npm run typecheck), AND the JavaScript unit tests (npm run test:coverage) were NOT run for this release. Emergency use only: run them immediately after publishing and fix any failure." >&2
    TESTS_GATE_REPORT_LINE="ignoré (\`--skip-tests-gate\`) — PHPStan, PHPUnit, l'analyse statique JavaScript et les tests JavaScript non exécutés, à vérifier manuellement."
else
    launch_gate tests "Tests" check_tests_gate
fi

if [[ "${SKIP_E2E_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-e2e-gate used — the end-to-end browser test (npm run e2e) was NOT run for this release, so nothing verified that the application actually boots and renders. Emergency use only: run it immediately after publishing and fix any failure." >&2
    E2E_GATE_REPORT_LINE="ignoré (\`--skip-e2e-gate\`) — test navigateur de bout en bout non exécuté, à vérifier manuellement."
else
    launch_gate e2e "End-to-end" check_e2e_gate
fi

if [[ "${SKIP_DEPENDENCY_CHECK}" -eq 1 ]]; then
    echo "WARNING: --skip-dependency-check used — outdated Composer/vendored front-end dependencies were NOT checked for this release. Emergency use only: update them immediately after publishing." >&2
    DEPENDENCY_GATE_REPORT_LINE="ignoré (\`--skip-dependency-check\`) — à vérifier manuellement."
else
    launch_gate dependency "Dependency freshness" check_dependency_freshness_gate
fi

if [[ "${SKIP_SONAR_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-sonar-gate used — active SonarQube Cloud security findings, HIGH-or-above severity findings, unreviewed Security Hotspots, and the Quality Gate were NOT checked for this release. Emergency use only: verify and resolve them immediately after publishing." >&2
    SONAR_GATE_REPORT_LINE="ignoré (\`--skip-sonar-gate\`) — à vérifier manuellement."
else
    launch_gate sonar "SonarQube Cloud" check_sonar_gate
fi

GATE_COUNT="${#GATE_KEYS[@]}"

if [[ "${GATE_COUNT}" -gt 0 ]]; then
    gate_label_list=""
    gi=0
    while [[ "${gi}" -lt "${GATE_COUNT}" ]]; do
        gate_label_list="${gate_label_list}${gate_label_list:+, }${GATE_LABELS[${gi}]}"
        gi=$((gi + 1))
    done
    echo ""
    echo "Running ${GATE_COUNT} gate(s) in parallel: ${gate_label_list}..."
fi

GATES_FAILED=0
gi=0
while [[ "${gi}" -lt "${GATE_COUNT}" ]]; do
    if wait "${GATE_PIDS[${gi}]}"; then
        GATE_EXIT+=(0)
    else
        GATE_EXIT+=("$?")
    fi
    gi=$((gi + 1))
done

echo ""
echo "==================== Gate results ===================="
gi=0
while [[ "${gi}" -lt "${GATE_COUNT}" ]]; do
    if [[ "${GATE_EXIT[${gi}]}" -eq 0 ]]; then
        echo "✅ ${GATE_LABELS[${gi}]} gate passed"
    else
        GATES_FAILED=1
        echo "❌ ${GATE_LABELS[${gi}]} gate FAILED — last 60 lines (full log: ${GATE_TMP_DIR}/${GATE_KEYS[${gi}]}.log):"
        tail -n 60 "${GATE_TMP_DIR}/${GATE_KEYS[${gi}]}.log" | sed 's/^/    /'
        echo ""
    fi
    gi=$((gi + 1))
done
echo "========================================================"

if [[ "${GATES_FAILED}" -eq 1 ]]; then
    echo "" >&2
    echo "ERROR: release blocked — one or more gates failed (see above). Fix the underlying issue and re-run." >&2
    exit 1
fi

# Every gate that ran had its own live output going only to its log file
# (interleaved parallel output would be unreadable) — replay each one now,
# in the same order the sequential version of this script used to print
# them, so a passing run's output still shows every gate's own detail.
for key in ${GATE_KEYS[@]+"${GATE_KEYS[@]}"}; do
    cat "${GATE_TMP_DIR}/${key}.log"
done

DEPLOYMENT_GATE_REPORT_LINE="${DEPLOYMENT_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/deployment.report" 2>/dev/null)}"
SECURITY_GATE_REPORT_LINE="${SECURITY_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/security.report" 2>/dev/null)}"
TESTS_GATE_REPORT_LINE="${TESTS_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/tests.report" 2>/dev/null)}"
E2E_GATE_REPORT_LINE="${E2E_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/e2e.report" 2>/dev/null)}"
DEPENDENCY_GATE_REPORT_LINE="${DEPENDENCY_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/dependency.report" 2>/dev/null)}"
SONAR_GATE_REPORT_LINE="${SONAR_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/sonar.report" 2>/dev/null)}"

GATE_REPORT="- **Déploiement** : ${DEPLOYMENT_GATE_REPORT_LINE}
- **Sécurité** : ${SECURITY_GATE_REPORT_LINE}
- **Tests** : ${TESTS_GATE_REPORT_LINE}
- **Tests de bout en bout** : ${E2E_GATE_REPORT_LINE}
- **Dépendances** : ${DEPENDENCY_GATE_REPORT_LINE}
- **SonarQube Cloud** : ${SONAR_GATE_REPORT_LINE}
"

rm -rf "${GATE_TMP_DIR}"
trap - EXIT

# The VERSION file is the running site's source of truth for its installed
# version (Core\Maintenance\VersionFile, read by the Configuration >
# Maintenance "Mise à jour" section) — it must be committed as part of the
# release commit so the tag, the file, and the artifact all agree.
echo "${NEW_VERSION}" > VERSION
git add VERSION
git commit -m "chore: bump VERSION to ${NEW_VERSION}"
git push origin HEAD

# Create annotated tag
git tag -a "${TAG}" -m "Release ${TAG}"
git push origin "${TAG}"

# Create GitHub release (requires gh CLI)
if command -v gh &> /dev/null; then
    # Build release artifact. vendor/ must be present — there is no
    # Composer on a shared host, so a vendor-less artifact installs
    # cleanly (per bootstrap.php's own artifact verification) but yields a
    # dead site. bootstrap/ is excluded: it's the installer, not something
    # an install/update should ever re-plant into a live site.
    #
    # This runs `composer install` directly against THIS checkout's own
    # vendor/ (there's no separate build directory) — --no-dev strips
    # phpstan/phpunit/etc. from it, which would silently break every
    # subsequent `vendor/bin/phpstan`/`vendor/bin/phpunit` call in this
    # same working tree until someone thought to run `composer install`
    # again. The trap below restores dev dependencies unconditionally on
    # any exit from here on (success, a later gate failure, Ctrl-C —
    # doesn't matter which), registered before LISTING_FILE/
    # FINAL_NOTES_FILE even exist so an early failure (e.g. `zip -r`
    # itself) still triggers it; single-quoted so bash expands the
    # variables at trap-fire time, by which point both are set.
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
    LISTING_FILE=""
    FINAL_NOTES_FILE=""
    trap 'rm -f "${LISTING_FILE}" "${FINAL_NOTES_FILE}"; echo "Restoring dev dependencies (composer install)..."; composer install --no-interaction --quiet || echo "WARNING: failed to restore dev dependencies — run \`composer install\` manually." >&2' EXIT
    ARTIFACT="release-${TAG}.zip"
    # storage/* is excluded WHOLESALE, not just keys/config/temp: it's
    # where every module keeps real uploaded content (gallery photos and
    # videos, finance receipts, section documents, local backups, etc.)
    # on a live or local dev install — publishing any of it in a public
    # release artifact would be a real personal-data leak. Neither
    # InstallUpdateHandler::installFiles() nor bootstrap.php's
    # bootstrap_copy_tree() ever reads storage/ from the artifact anyway
    # (both explicitly skip it as a top-level entry when installing/
    # updating), so there is nothing lost by excluding it entirely — the
    # 5 empty subdirs are created fresh by the installer/updater instead.
    #
    # .claude/* matters for the same "never publish local-only state"
    # reason: it can hold worktrees (each a full nested checkout,
    # .claude/worktrees/<name>/), which would otherwise get zipped into
    # the artifact wholesale.
    #
    # node_modules/, coverage/, package.json, and package-lock.json are
    # development/test-only (Vitest — see AGENTS.md § CSS / frontend and
    # package.json's own description): production ScoutMagic runs plain,
    # unbundled browser JavaScript and needs neither Node nor npm, so none
    # of these belong in the release artifact — same reasoning as
    # excluding vendor/ dev dependencies would if Composer's --no-dev
    # (already run above) didn't handle that side on its own.
    zip -r "${ARTIFACT}" . \
        -x ".git/*" ".github/*" "tests/*" "storage/*" \
           "config/app.php" ".gitignore" ".env" "*.zip" \
           "bootstrap/*" ".claude/*" ".idea/*" ".vscode/*" "*.DS_Store" \
           "node_modules/*" "coverage/*" "package.json" "package-lock.json" \
           "vitest.config.js"

    # Listed to a real file rather than piped live into grep -q: with
    # `set -o pipefail` (line 2), a `grep -q` that matches early closes its
    # read end, SIGPIPE-killing whatever wrote to that pipe — pipefail then
    # reports the pipeline's exit status as the writer's 141, not grep's
    # own (successful) 0, even though the match was genuinely found. On a
    # large listing (thousands of entries, as this artifact has grown to)
    # there's enough left to write that the race reliably loses — this bit
    # both checks below in the wild despite the artifact being correct
    # both times. Grepping a file has no live writer to kill, so no race.
    # Trap already registered above (before these existed, as empty
    # strings) — assigning the real paths here is enough, no need to
    # re-register it.
    LISTING_FILE="$(mktemp)"
    FINAL_NOTES_FILE="$(mktemp)"
    unzip -l "${ARTIFACT}" > "${LISTING_FILE}"

    # bootstrap.php protects a Layout B (single-tree) install with exactly
    # one root .htaccess it writes itself at install time — the artifact
    # must never ship one, or bootstrap's own S7 acceptance check would
    # correctly refuse the very release this script is about to publish.
    # Matched directly against the whitespace-then-filename tail of an
    # `unzip -l` row rather than via awk '{print $4}' | grep -qx, which
    # reintroduces the same live-pipe SIGPIPE race one level down.
    if grep -qE '[[:space:]]\.htaccess$' "${LISTING_FILE}"; then
        echo "ERROR: release artifact contains a root-level .htaccess — aborting release." >&2
        rm -f "${ARTIFACT}"
        exit 1
    fi

    if ! grep -q 'vendor/autoload.php' "${LISTING_FILE}"; then
        echo "ERROR: release artifact is missing vendor/autoload.php — aborting release." >&2
        rm -f "${ARTIFACT}"
        exit 1
    fi

    # node_modules/ and coverage/ are development/test-only (Vitest — see
    # the -x list above); a leftover local install of either must never
    # reach a release artifact regardless of how it got there.
    if grep -qE '[[:space:]](node_modules|coverage)/' "${LISTING_FILE}"; then
        echo "ERROR: release artifact contains node_modules/ or coverage/ — aborting release." >&2
        rm -f "${ARTIFACT}"
        exit 1
    fi

    # Release notes always end with the "Vérifications effectuées" block
    # (${GATE_REPORT}, built above as each gate ran or was bypassed) — this
    # is added here in the script itself, never left to whoever wrote
    # NOTES_FILE, so it can't be forgotten or drift from what actually
    # ran. --generate-notes only supports *prepending* custom text via
    # --notes, not appending after it, so the auto-generated notes are
    # instead pre-fetched through the same GitHub API endpoint that flag
    # uses (`releases/generate-notes`) — this way both paths (custom
    # NOTES_FILE or auto-generated) end up going through the same
    # "write base notes, then append the gate report" logic below,
    # always passed to gh via --notes-file.
    if [[ -n "${NOTES_FILE}" ]]; then
        cat "${NOTES_FILE}" > "${FINAL_NOTES_FILE}"
    else
        gh api "repos/{owner}/{repo}/releases/generate-notes" \
            -f tag_name="${TAG}" --jq '.body' > "${FINAL_NOTES_FILE}" \
            || { echo "ERROR: cannot generate release notes." >&2; rm -f "${ARTIFACT}"; exit 1; }
    fi

    {
        echo ""
        echo "---"
        echo ""
        echo "## Vérifications effectuées pour cette release"
        echo ""
        printf '%s' "${GATE_REPORT}"
    } >> "${FINAL_NOTES_FILE}"

    # bootstrap.php is published as a second asset. GitHub does not
    # preserve this command's argument order in the assets array (observed:
    # it sorts alphabetically, putting bootstrap.php before the zip) — both
    # Core\Maintenance\GitHubReleaseClient and bootstrap.php's own
    # resolveArchiveUrl() select the artifact by its .zip filename, never
    # by array position, so upload order here doesn't matter.
    gh release create "${TAG}" "${ARTIFACT}" "bootstrap/bootstrap.php" \
        --title "Release ${TAG}" \
        --notes-file "${FINAL_NOTES_FILE}"

    rm -f "${ARTIFACT}"
    echo "GitHub release ${TAG} created with artifact and bootstrap.php."
else
    echo "Tag ${TAG} pushed. Install GitHub CLI (gh) to auto-create releases."
fi
