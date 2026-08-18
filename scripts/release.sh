#!/bin/bash
set -euo pipefail

# Usage: ./scripts/release.sh [--minor|--major] [--notes-file <path>]
#                             [--skip-security-gate] [--skip-tests-gate]
#                             [--skip-dependency-check] [--skip-deployment-check]
# Default: increments patch level, computes release notes from the commit
# list (fetched via the same GitHub API `--generate-notes` itself calls),
# and requires the deployment gate, the security gate, the tests gate,
# the dependency freshness gate, and the SonarQube Cloud gate to all pass.
# Whichever notes are used (this auto-generated list, or --notes-file's
# content), a "Vérifications effectuées" section reporting every gate's
# outcome (verified, with details, or bypassed) is always appended at the
# end — see ${GATE_REPORT} and the gate-invocation blocks below.
#
#   --notes-file <path>        Use the release notes from this file
#                               instead of the auto-generated commit list.
#                               See AGENTS.md "Releases" for what a
#                               Claude-authored notes file must contain.
#   --skip-security-gate       Bypass the CodeQL/Dependabot check.
#                               Emergency use only — prints a warning. See
#                               check_security_gate.
#   --skip-tests-gate          Bypass phpstan/phpunit. Emergency use
#                               only — prints a warning. See
#                               check_tests_gate.
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
#
# There is intentionally NO --skip-sonar-gate flag. The SonarQube Cloud
# gate (scripts/check-sonar-release.sh — active security findings, HIGH or
# above severity findings, unreviewed Security Hotspots, and the Quality
# Gate) is fail-closed with no bypass: unlike the four gates above, it
# cannot be skipped from this script under any circumstance, including a
# user's explicit request for an urgent release. See check_sonar_gate and
# AGENTS.md § Releases.

BUMP="patch"
NOTES_FILE=""
SKIP_SECURITY_GATE=0
SKIP_TESTS_GATE=0
SKIP_DEPENDENCY_CHECK=0
SKIP_DEPLOYMENT_CHECK=0

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
        --skip-dependency-check) SKIP_DEPENDENCY_CHECK=1; shift ;;
        --skip-deployment-check) SKIP_DEPLOYMENT_CHECK=1; shift ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            echo "Usage: $0 [--minor|--major] [--notes-file <path>] [--skip-security-gate] [--skip-tests-gate] [--skip-dependency-check] [--skip-deployment-check]" >&2
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
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
TAG="v${NEW_VERSION}"

echo "Bumping version: ${CURRENT} → ${NEW_VERSION}"

PRODUCTION_URL="https://www.scoutmagic.be"

# Accumulates one Markdown bullet per gate below (verified or bypassed),
# in French since it's appended to the public release notes (see the
# "Vérifications effectuées" block near the end of this script) — unlike
# this script's own English console output, the release notes are
# user-facing text read by site administrators.
GATE_REPORT=""

# ---------------------------------------------------------------
# Deployment gate — verifies the PREVIOUS release actually reached
# production before a new one is created, and that production isn't
# currently broken. Exposed via GET /api/version (Core\Http\Controller\
# VersionController, role_min: public — see its own docblock), which
# reports the same version/commit already shown to a logged-in admin on
# Configuration > Maintenance. Two cases, matching VersionFile's own
# format:
#   - dev build ("dev-{sha}"): the reported commit must match local HEAD.
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

    local version_json remote_version remote_commit local_head_short home_body home_status

    version_json="$(curl -fsS --max-time 15 "${PRODUCTION_URL}/api/version")" \
        || { echo "ERROR: cannot reach ${PRODUCTION_URL}/api/version." >&2; exit 1; }
    remote_version="$(php -r '$d=json_decode(file_get_contents("php://stdin"), true); echo $d["version"] ?? "";' <<< "${version_json}")"
    remote_commit="$(php -r '$d=json_decode(file_get_contents("php://stdin"), true); echo $d["commit"] ?? "";' <<< "${version_json}")"

    if [[ -n "${remote_commit}" ]]; then
        local_head_short="$(git rev-parse --short=7 HEAD)"
        if [[ "${remote_commit}" != "${local_head_short}" ]]; then
            echo "ERROR: release blocked by the deployment gate — ${PRODUCTION_URL} is on dev commit ${remote_commit}, but local HEAD is ${local_head_short}." >&2
            echo "Wait for the site to pick up the latest commit (or re-run with --skip-deployment-check to bypass, emergency use only)." >&2
            exit 1
        fi
    elif [[ "${remote_version}" != "${CURRENT}" ]]; then
        echo "ERROR: release blocked by the deployment gate — ${PRODUCTION_URL} reports version '${remote_version}', expected the latest released tag '${CURRENT}'." >&2
        echo "The previous release may not have deployed yet. Wait for it, or re-run with --skip-deployment-check to bypass (emergency use only)." >&2
        exit 1
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

    DEPLOYMENT_GATE_REPORT_LINE="vérifié — ${PRODUCTION_URL} à jour (${remote_version}${remote_commit:+/${remote_commit}}), HTTP ${home_status}."
    echo "Deployment gate OK: ${PRODUCTION_URL} is up to date (${remote_version}${remote_commit:+/${remote_commit}}) and responds normally."
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

    SECURITY_GATE_REPORT_LINE="vérifié — aucun signalement CodeQL ni alerte Dependabot ouvert."
    echo "Security gate OK: no open CodeQL findings, no open Dependabot alerts."
}

# ---------------------------------------------------------------
# Tests gate — mirrors CI's `test` job (PHPStan + non-database PHPUnit
# suites). Runs BEFORE any git commit/tag, same reasoning as the security
# gate above. Database-group tests are excluded here too, exactly as
# phpunit.xml's default <groups><exclude> does — they need a live MySQL
# test instance that CI provisions as a service container but a local/
# release environment does not.
# ---------------------------------------------------------------
check_tests_gate() {
    local phpunit_output phpunit_summary

    echo "Running PHPStan..."
    vendor/bin/phpstan analyse --memory-limit=512M

    echo "Running PHPUnit..."
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

    TESTS_GATE_REPORT_LINE="vérifié — PHPStan sans erreur ; PHPUnit : ${phpunit_summary}"
    echo "Tests gate OK: PHPStan and PHPUnit passed."
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

    DEPENDENCY_GATE_REPORT_LINE="vérifié — dépendances Composer directes, Bootstrap, Bootstrap Icons et Chart.js vendorisés à jour."
    echo "Dependency freshness gate OK: direct Composer dependencies and vendored front-end libraries (Bootstrap, Bootstrap Icons, Chart.js) are up to date."
}

if [[ "${SKIP_DEPLOYMENT_CHECK}" -eq 1 ]]; then
    echo "WARNING: --skip-deployment-check used — ${PRODUCTION_URL} was NOT checked for this release. Emergency use only: verify it manually right after publishing." >&2
    DEPLOYMENT_GATE_REPORT_LINE="ignoré (\`--skip-deployment-check\`) — à vérifier manuellement."
else
    check_deployment_gate
fi
GATE_REPORT="${GATE_REPORT}- **Déploiement** : ${DEPLOYMENT_GATE_REPORT_LINE}
"

if [[ "${SKIP_SECURITY_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-security-gate used — open CodeQL findings and/or Dependabot alerts were NOT checked for this release. Emergency use only: verify and resolve them immediately after publishing." >&2
    SECURITY_GATE_REPORT_LINE="ignoré (\`--skip-security-gate\`) — à vérifier manuellement."
else
    check_security_gate
fi
GATE_REPORT="${GATE_REPORT}- **Sécurité** : ${SECURITY_GATE_REPORT_LINE}
"

if [[ "${SKIP_TESTS_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-tests-gate used — PHPStan and PHPUnit were NOT run for this release. Emergency use only: run them immediately after publishing and fix any failure." >&2
    TESTS_GATE_REPORT_LINE="ignoré (\`--skip-tests-gate\`) — à vérifier manuellement."
else
    check_tests_gate
fi
GATE_REPORT="${GATE_REPORT}- **Tests** : ${TESTS_GATE_REPORT_LINE}
"

if [[ "${SKIP_DEPENDENCY_CHECK}" -eq 1 ]]; then
    echo "WARNING: --skip-dependency-check used — outdated Composer/vendored front-end dependencies were NOT checked for this release. Emergency use only: update them immediately after publishing." >&2
    DEPENDENCY_GATE_REPORT_LINE="ignoré (\`--skip-dependency-check\`) — à vérifier manuellement."
else
    check_dependency_freshness_gate
fi
GATE_REPORT="${GATE_REPORT}- **Dépendances** : ${DEPENDENCY_GATE_REPORT_LINE}
"

# ---------------------------------------------------------------
# SonarQube Cloud gate — delegates to scripts/check-sonar-release.sh (kept
# as a separate script rather than inlined here: its logic — multiple Web
# API calls, JSON parsing, fail-closed error handling — is non-trivial
# enough to warrant being testable on its own, see
# scripts/check-sonar-release.test.sh). Runs BEFORE any git commit/tag,
# same reasoning as the other gates. No bypass flag exists for this gate:
# it always runs, and always blocks the release on an active security
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
}

check_sonar_gate
GATE_REPORT="${GATE_REPORT}- **SonarQube Cloud** : vérifié — aucun signalement de sécurité actif, aucun problème de sévérité HIGH ou supérieure, Quality Gate OK.
"

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
    zip -r "${ARTIFACT}" . \
        -x ".git/*" ".github/*" "tests/*" "storage/*" \
           "config/app.php" ".gitignore" ".env" "*.zip" \
           "bootstrap/*" ".claude/*" ".idea/*" ".vscode/*" "*.DS_Store"

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
