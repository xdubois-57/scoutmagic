#!/bin/bash
set -euo pipefail

# Usage: ./scripts/release.sh [--minor|--major] [--notes-file <path>]
#                             [--skip-deployment-check] [--skip-ci-gate]
#                             [--skip-security-gate] [--skip-dependency-check]
#                             [--skip-sonar-gate]
# Default: increments patch level, computes release notes from the commit
# list (fetched via the same GitHub API `--generate-notes` itself calls),
# and requires five gates to pass, in order, before anything is committed
# or tagged: deployment, continuous integration, security, dependency
# freshness, SonarQube Cloud. Each finishes in seconds, each is a
# PRECONDITION rather than a statement about the code, and the release
# stops at the first one that refuses.
#
# WHY THE TESTS ARE NOT IN THAT LIST
# ---------------------------------------------------------------
# They used to be: this script ran PHPStan, the whole PHPUnit suite,
# `npm run e2e:full` and both DAST profiles itself, taking twenty-five
# minutes and needing MySQL, a Chromium binary, Docker and a 1.2 GB ZAP
# image on the releaser's machine. Every one of those now runs on a
# GitHub runner — twice: on the pull request and the push to `main`
# through ci.yml, and again on the tag through release.yml, which keeps
# what each tool emits and signs it into the evidence pack.
#
# Running them here as well was the least trustworthy of those runs, not
# an extra guarantee. It exercised one database engine where CI runs two;
# the steward skill records that four of these reproductions run on the
# wrong engine locally and so cannot go red for the divergence they exist
# to catch; and its verdict appeared nowhere a reader of the Release could
# check. What it did buy was failing BEFORE the tag existed — and the
# `ci` gate below buys the same thing by reading the verdict GitHub
# already reached on the commit being released, in about a second.
#
# So: a red test still stops a release. It stops it on the pull request,
# where it belongs; then again at the `ci` gate here, before any commit
# or tag; and once more on the tag, where a red gate creates no draft
# Release at all.
# Whichever notes are used (this auto-generated list, or --notes-file's
# content), a "Vérifications effectuées" section reporting every gate's
# outcome (verified, with details, or bypassed) is always appended at the
# end — see ${GATE_REPORT} and the "Gate execution" block below — followed
# by the description of the evidence pack the Release workflow wrote, and
# by the dependency inventory scripts/dependency-inventory.php generates:
# every package that shipped, its version read from the lock files and the
# vendored banners, its licence, and why that licence may be combined with
# this project's AGPL-3.0. Never write that list by hand into a notes file.
#
# WHAT HAPPENS AFTER THE TAG IS PUSHED
# ---------------------------------------------------------------
# Pushing the tag starts .github/workflows/release.yml, which re-runs every
# gate in .github/workflows/checks.yml on GitHub's runners with each tool's
# native output kept, fetches SonarCloud's complete analysis of the
# commit and the repository's open security alerts, signs the whole pack
# (Sigstore, through GitHub's own identity) and creates the Release as a
# DRAFT carrying it. That draft is the only Release this script ever
# touches: it does NOT create one of its own — two would target the same
# tag, the workflow lands last, and it would quietly turn a published
# Release back into a draft. Instead it builds the deployable zip while
# the workflow runs, waits for the run, refuses on anything but success,
# attaches the zip and bootstrap.php to the draft, writes the notes, and
# publishes. Nothing reaches the Releases page unless every gate went
# green twice: here, on this machine, and there, on a runner nobody
# configured by hand.
#
# THE ONE THING NEVER TO ATTACH: a second `.zip`. Every installed site
# takes the FIRST asset whose name ends in .zip as the application
# (Core\Maintenance\GitHubReleaseClient::selectZipAssetUrl(), and
# bootstrap.php), GitHub sorts assets alphabetically, and those sites run
# the code they already have. The evidence pack is a .tar.gz for exactly
# that reason, and this script counts the zips before publishing.
#
# IF THE WORKFLOW IS RED, the tag exists and points at nothing published.
# Fix the cause on main, delete the tag (`git push --delete origin vX.Y.Z
# && git tag -d vX.Y.Z`) and run this script again: it recomputes the same
# version, and leaves VERSION alone when it already reads it.
#
#   --notes-file <path>        Use the release notes from this file
#                               instead of the auto-generated commit list.
#                               See AGENTS.md "Releases" for what a
#                               Claude-authored notes file must contain.
#   --skip-security-gate       Bypass composer audit, npm audit, AND the
#                               CodeQL/Dependabot check. Emergency use
#                               only — prints a warning. Note that a
#                               permission gap on the CodeQL/Dependabot
#                               query alone (this session's GitHub access
#                               lacking that specific repo permission) is
#                               NOT what this flag is for — that case is
#                               already a non-blocking warning inside the
#                               gate itself, composer audit/npm audit
#                               still run and still block for real. See
#                               check_security_gate.
#   --skip-ci-gate             Bypass the check that CI is green on the
#                               commit being released — PHPStan, both
#                               PHPUnit engines, the JavaScript analysis
#                               and tests, the browser suite, the
#                               authorization matrix and the passive scan,
#                               as `All checks` reports them. Emergency use
#                               only — prints a warning. Note what it
#                               costs: nothing else in this script looks
#                               at the code at all, so a release run with
#                               this flag has been tested by nobody until
#                               the tag's own workflow says otherwise, and
#                               that runs AFTER the tag exists. See
#                               check_ci_gate.
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

# Keep the machine awake for the whole run. The gates take about a minute
# now, but the wait for the tag's Release workflow is the better part of
# an hour, and a laptop that sleeps partway through leaves the release
# dead at an unpredictable point — most likely after the tag has been
# pushed but before the draft is published, which is the one state this
# script cannot recover from on its own (a state it has already been
# caught in once, for another reason). `caffeinate` holds sleep off for exactly as long as the command
# it wraps, and lets go afterwards, so there is nothing to remember to
# undo.
#
# Re-executes this script under caffeinate once; the exported marker makes
# the second pass fall through instead of recursing. macOS only —
# caffeinate does not exist on Linux (CI, a remote dev host), where
# `command -v` simply fails and the release proceeds exactly as it did
# before this block existed. `-i` blocks idle sleep, `-s` blocks system
# sleep while on mains power (this build takes -disu; there is no -m).
if [[ -z "${SCOUTMAGIC_RELEASE_AWAKE:-}" ]] && command -v caffeinate &> /dev/null; then
    export SCOUTMAGIC_RELEASE_AWAKE=1
    exec caffeinate -i -s "$0" "$@"
fi

BUMP="patch"
NOTES_FILE=""
SKIP_SECURITY_GATE=0
SKIP_CI_GATE=0
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
        --skip-ci-gate) SKIP_CI_GATE=1; shift ;;
        --skip-dependency-check) SKIP_DEPENDENCY_CHECK=1; shift ;;
        --skip-deployment-check) SKIP_DEPLOYMENT_CHECK=1; shift ;;
        --skip-sonar-gate) SKIP_SONAR_GATE=1; shift ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            echo "Usage: $0 [--minor|--major] [--notes-file <path>] [--skip-deployment-check] [--skip-ci-gate] [--skip-security-gate] [--skip-dependency-check] [--skip-sonar-gate]" >&2
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------
# Preflight — the one executable this script cannot do without,
# checked here rather than where it is first used.
# ---------------------------------------------------------------

# GitHub CLI: two gates already need it (security, dependency freshness),
# but a releaser skipping both would only discover its absence AFTER the
# tag is pushed — at the point where the draft Release the workflow
# creates has to be finished from here, which nothing else does. Checked
# once, up front, so a missing gh costs a message rather than a tag
# pointing at a draft nobody publishes.
command -v gh &> /dev/null || { echo "ERROR: GitHub CLI (gh) is required — this script finishes the release by attaching the artifact to the draft the Release workflow creates and publishing it. Install it and run gh auth login." >&2; exit 1; }

# Get current version from the latest RELEASE tag (default 0.0.0 if none).
#
# `--match` is not decoration. This repository also carries moving tags
# the dev channel republishes — `dev-latest`, `dev-build` — and a bare
# `git describe --tags --abbrev=0` returns whichever tag is newest,
# which is one of those on any day a dev build was published. That is not
# a hypothetical: it happened, and it failed in the worst possible order.
# `IFS='.' read` split "dev-latest" into MAJOR="dev-latest", MINOR="",
# PATCH="", a patch bump made "" + 1 = 1, and the script wrote
# "dev-latest..1" into VERSION, committed it and PUSHED it to main before
# dying on `fatal: 'vdev-latest..1' is not a valid tag name`. Every gate
# had passed; the arithmetic had read the wrong tag.
CURRENT=$(git describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*' 2>/dev/null || echo "v0.0.0")
CURRENT="${CURRENT#v}"  # strip leading v

if [[ ! "${CURRENT}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "ERROR: the latest release tag reads '${CURRENT}', which is not MAJOR.MINOR.PATCH." >&2
    echo "Refusing to compute a version from it. Check 'git tag --list \"v[0-9]*\"'." >&2
    exit 1
fi

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

case $BUMP in
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    patch) PATCH=$((PATCH + 1)) ;;
    *) echo "ERROR: unexpected BUMP value: ${BUMP}" >&2; exit 1 ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
TAG="v${NEW_VERSION}"

# Belt to the braces above, and the half that actually protects main: the
# previous failure was not that a bad version was computed, but that it
# was committed and pushed before anything checked it. Whatever goes wrong
# upstream, nothing below this line runs on a version that is not
# MAJOR.MINOR.PATCH.
if [[ ! "${NEW_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "ERROR: computed version '${NEW_VERSION}' is not MAJOR.MINOR.PATCH — refusing to commit or tag it." >&2
    exit 1
fi

echo "Bumping version: ${CURRENT} → ${NEW_VERSION}"

PRODUCTION_URL="https://www.scoutmagic.be"

# ${GATE_REPORT} — one Markdown bullet per gate (verified or bypassed), in
# French since it's appended to the public release notes (see the
# "Vérifications effectuées" block near the end of this script) — unlike
# this script's own English console output, the release notes are
# user-facing text read by site administrators. Built once, after every
# gate below has run (each in its own subshell — see the "Gate execution"
# block), from each *_GATE_REPORT_LINE variable: already set directly for
# a skipped gate, or read back from that gate's own report file otherwise
# (a subshell's variable assignments never reach this parent shell).

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
    command -v composer &> /dev/null || { echo "ERROR: composer is required for the security gate (composer audit)." >&2; exit 1; }
    command -v npm &> /dev/null || { echo "ERROR: npm is required for the security gate (npm audit) — see package.json/README.md § Développement." >&2; exit 1; }
    [[ -d node_modules ]] || { echo "ERROR: node_modules/ not found — run 'npm ci' first (see README.md § Développement) before the security gate can run npm audit." >&2; exit 1; }

    local err codeql_lines dependabot_lines codeql_count dependabot_count
    local codeql_status dependabot_status permission_gap=""
    local audit_output audit_exit

    # composer audit / npm audit: known CVEs in installed dependencies
    # (Composer's require+require-dev, and the full npm lockfile), queried
    # directly against the FriendsOfPHP / npm advisory databases — no
    # GitHub API, no App permission of any kind. Mandatory and always
    # blocking: a real advisory here is a real finding regardless of what
    # GitHub's own alerts say, and unlike the CodeQL/Dependabot queries
    # below this pair cannot be permission-limited, so there is no softer
    # path for a nonzero exit here.
    echo "Running composer audit..."
    audit_output="$(composer audit 2>&1)"; audit_exit=$?
    if [[ "${audit_exit}" -ne 0 ]]; then
        echo "ERROR: composer audit found an advisory (or failed to run) — release blocked by the security gate." >&2
        echo "${audit_output}" >&2
        exit 1
    fi

    echo "Running npm audit..."
    audit_output="$(npm audit 2>&1)"; audit_exit=$?
    if [[ "${audit_exit}" -ne 0 ]]; then
        echo "ERROR: npm audit found a vulnerability (or failed to run) — release blocked by the security gate." >&2
        echo "${audit_output}" >&2
        exit 1
    fi

    # CodeQL / Dependabot: fail-closed on any error EXCEPT the specific
    # case of a permission gap — GitHub's own "Resource not accessible by
    # integration" message, returned when the calling token/App is
    # authenticated and can reach this repo for everything else, but was
    # never granted the "Code scanning alerts" / "Dependabot alerts"
    # repository permission. That is a statement about what THIS caller
    # can reach, not a security finding, so it downgrades to a warning
    # instead of blocking — every other error (auth failure, rate limit,
    # an unreachable host, an unexpected response) still aborts exactly as
    # before. See AGENTS.md § Releases for the manual-verification
    # fallback this warning points at (check the Security tab by hand).
    err="$(mktemp)"
    codeql_lines="$(gh api "repos/{owner}/{repo}/code-scanning/alerts" --paginate \
        --jq '.[] | select(.state == "open") | "\(.number)\t\(.rule.description)"' 2>"${err}")"
    codeql_status=$?
    if [[ "${codeql_status}" -ne 0 ]]; then
        if grep -qi "Resource not accessible by integration" "${err}"; then
            echo "WARNING: could not check CodeQL findings — this session's GitHub access lacks the 'Code scanning alerts' repository permission (a permission gap, not a finding). Verify manually: https://github.com/{owner}/{repo}/security/code-scanning" >&2
            codeql_lines=""
            permission_gap="${permission_gap}CodeQL, "
        else
            echo "ERROR: cannot query CodeQL findings:" >&2; cat "${err}" >&2; rm -f "${err}"; exit 1
        fi
    fi
    rm -f "${err}"

    err="$(mktemp)"
    dependabot_lines="$(gh api "repos/{owner}/{repo}/dependabot/alerts" --paginate \
        --jq '.[] | select(.state == "open") | "\(.number)\t\(.security_advisory.summary)"' 2>"${err}")"
    dependabot_status=$?
    if [[ "${dependabot_status}" -ne 0 ]]; then
        if grep -qi "Resource not accessible by integration" "${err}"; then
            echo "WARNING: could not check Dependabot alerts — this session's GitHub access lacks the 'Dependabot alerts' repository permission (a permission gap, not a finding). Verify manually: https://github.com/{owner}/{repo}/security/dependabot" >&2
            dependabot_lines=""
            permission_gap="${permission_gap}Dependabot, "
        else
            echo "ERROR: cannot query Dependabot alerts:" >&2; cat "${err}" >&2; rm -f "${err}"; exit 1
        fi
    fi
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

    if [[ -n "${permission_gap}" ]]; then
        permission_gap="${permission_gap%, }"
        echo "vérifié — composer audit et npm audit : aucune vulnérabilité connue. ${permission_gap} non vérifié(s) depuis cette session (permission GitHub manquante) — vérifié manuellement à la place (voir la remarque associée)." > "${GATE_REPORT_FILE}"
        echo "Security gate OK (partial): composer audit and npm audit clean; ${permission_gap} could not be checked from this session (permission gap, not a finding — see warning above)." >&2
    else
        echo "vérifié — composer audit et npm audit : aucune vulnérabilité connue ; aucun signalement CodeQL ni alerte Dependabot ouvert." > "${GATE_REPORT_FILE}"
        echo "Security gate OK: composer audit and npm audit clean; no open CodeQL findings, no open Dependabot alerts."
    fi
}

# ---------------------------------------------------------------
# Continuous integration gate — the verdict GitHub already reached on
# the commit being released.
#
# `All checks` (.github/workflows/ci.yml) is one job that needs every
# gate in checks.yml and goes red when any of them is red, cancelled or
# never ran. Reading it is how this script knows PHPStan, both PHPUnit
# engines, the JavaScript analysis and tests, the browser suite, the
# authorization matrix and the passive scan all passed on THIS commit —
# on two database engines, with the flags CI sets, on a machine nobody
# configured by hand. See the header for why that is a better answer
# than running them here.
#
# Three refusals, and each says something different:
#
#   - a dirty working tree. The artifact is zipped from this tree
#     (scripts/build-artifact.sh) while the verdict below is about HEAD,
#     so uncommitted changes would ship files nothing ever tested. This
#     is the one thing the old local gates would have caught by accident
#     and this one has to catch on purpose.
#   - GitHub has no `All checks` run for this commit. It was never
#     pushed, or the workflow never started. Either way there is no
#     verdict to read, and "no verdict" is not a pass.
#   - the run is red, cancelled, or still going after the wait below.
#
# The wait is bounded and usually instant: production must already be on
# the previous release (the deployment gate above), so CI has had time.
# It exists for the case of releasing minutes after a merge — the same
# reasoning, and the same shape, as the SonarQube gate's wait.
# ---------------------------------------------------------------
check_ci_gate() {
    command -v gh &> /dev/null || { echo "ERROR: GitHub CLI (gh) is required for the CI gate." >&2; exit 1; }
    command -v git &> /dev/null || { echo "ERROR: git is required for the CI gate." >&2; exit 1; }
    command -v php &> /dev/null || { echo "ERROR: php is required for the CI gate." >&2; exit 1; }

    local attempts="${CI_WAIT_ATTEMPTS:-90}" seconds="${CI_WAIT_SECONDS:-30}"
    local check_name="${CI_VERDICT_CHECK:-All checks}"
    local sha short_sha check_query answer status conclusion url attempt err budget

    # Rendered rather than divided inline: integer minutes print "0
    # minutes" for any wait under sixty seconds, which is what a
    # shortened timeout in a test looks like, and it reads as a bug in
    # the gate rather than a small number.
    if [[ $(( attempts * seconds )) -lt 60 ]]; then
        budget="$(( attempts * seconds )) second(s)"
    else
        budget="$(( attempts * seconds / 60 )) minute(s)"
    fi

    if [[ -n "$(git status --porcelain)" ]]; then
        echo "ERROR: the working tree has uncommitted changes — release blocked by the CI gate." >&2
        echo "The release artifact is built from this tree, and the verdict this gate reads is about HEAD," >&2
        echo "so anything uncommitted would ship having been tested by nothing. Commit or stash it first:" >&2
        git status --short >&2
        exit 1
    fi

    sha="$(git rev-parse HEAD)"
    short_sha="${sha:0:7}"
    check_query="$(php -r 'echo rawurlencode($argv[1]);' "${check_name}")"

    for (( attempt = 1; attempt <= attempts; attempt++ )); do
        err="$(mktemp)"
        # filter=latest so a re-run of the workflow answers with the run
        # that matters rather than the first one GitHub happens to list.
        # An empty check_runs array yields empty fields rather than an
        # error, which is the "not started yet" case handled below.
        answer="$(gh api "repos/{owner}/{repo}/commits/${sha}/check-runs?check_name=${check_query}&filter=latest" \
            --jq '.check_runs[0] | [.status // "", .conclusion // "", .html_url // ""] | @tsv' 2>"${err}")" || {
            echo "ERROR: cannot read the ${check_name} status for ${short_sha}:" >&2
            cat "${err}" >&2
            rm -f "${err}"
            echo "If this commit was never pushed, push it and let CI run: the release must ship something CI has judged." >&2
            exit 1
        }
        rm -f "${err}"

        # `cut`, not `read -r` with IFS=$'\t': a tab is IFS *whitespace*,
        # so bash collapses a run of them into one delimiter and an empty
        # middle field shifts everything left — which is exactly the shape
        # of an unfinished run, whose conclusion is empty. cut counts
        # delimiters instead of splitting on them.
        status="$(cut -f1 <<< "${answer}")"
        conclusion="$(cut -f2 <<< "${answer}")"
        url="$(cut -f3 <<< "${answer}")"

        if [[ "${status}" == "completed" ]]; then
            break
        fi

        if [[ "${attempt}" -eq 1 ]]; then
            if [[ -z "${status}" ]]; then
                echo "  no ${check_name} run for ${short_sha} yet — waiting (up to ${budget})..."
            else
                echo "  ${check_name} is ${status} on ${short_sha} — waiting (up to ${budget})..."
            fi
        fi

        if [[ "${attempt}" -eq "${attempts}" ]]; then
            echo "ERROR: ${check_name} is still '${status:-absent}' on ${short_sha} after ${budget} — release blocked by the CI gate." >&2
            echo "  ${url:-$(gh repo view --json url -q .url 2>/dev/null)/actions}" >&2
            echo "Wait for it, or investigate why it never ran. A release must not be cut on a commit nothing has judged." >&2
            exit 1
        fi

        sleep "${seconds}"
    done

    if [[ "${conclusion}" != "success" ]]; then
        echo "ERROR: ${check_name} concluded '${conclusion}' on ${short_sha} — release blocked by the CI gate." >&2
        echo "  ${url}" >&2
        echo "At least one gate in .github/workflows/checks.yml is not green on the commit being released." >&2
        echo "Fix it on main and release the commit that fixes it — never around it." >&2
        exit 1
    fi

    echo "vérifié — CI verte sur le commit livré (\`${check_name}\`, \`${short_sha}\`) : PHPStan, PHPUnit sur MySQL 8 et MariaDB 10.11, analyse statique et tests JavaScript, suite navigateur, matrice d'autorisation, analyse dynamique passive et \`composer audit\`." > "${GATE_REPORT_FILE}"
    echo "CI gate OK: ${check_name} is green on ${short_sha}."
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
# Bootstrap Icons, Chart.js, Leaflet, html5-qrcode as of this writing;
# add a new
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
    # Leaflet was vendored for the camps map without being added here, which
    # AGENTS.md § CSS / frontend requires in the same change. Its banner reads
    # "Leaflet 1.9.4" with no `v`, unlike the three above.
    check_vendored_asset_freshness "Leaflet" "public/assets/vendor/leaflet/leaflet.js" 'Leaflet [0-9]+\.[0-9]+\.[0-9]+' "Leaflet/Leaflet" \
        || found_outdated=1
    # The QR reader of the news module's door screen. Its upstream bundle
    # carries NO version banner of its own, so the vendored copy has one
    # prepended — that and the file's own header comment are the only
    # bytes that differ from the npm tarball. Whoever updates the library
    # updates that banner in the same move, or this gate goes on reporting
    # the old version as current.
    check_vendored_asset_freshness "html5-qrcode" "public/assets/vendor/html5-qrcode/html5-qrcode.min.js" 'html5-qrcode v[0-9]+\.[0-9]+\.[0-9]+' "mebjas/html5-qrcode" \
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
    # run_gate runs every gate function under `set +e` (so a gate can
    # decide for itself when to abort instead of the whole subshell dying
    # on the first nonzero exit) — which also means errexit is OFF for
    # this function, so a failing command here does NOT stop execution on
    # its own. Every command that can fail must be explicitly checked;
    # `check-sonar-release.sh` already exits 1 and prints its own reason
    # on failure, so `|| exit 1` is enough to propagate that here instead
    # of silently falling through to the "vérifié" line below.
    "${script_dir}/check-sonar-release.sh" || exit 1
    echo "vérifié — aucun signalement SonarCloud non résolu hors les nits de convention exemptés, aucun Security Hotspot à trier, Quality Gate OK, sur l'analyse du commit livré." > "${GATE_REPORT_FILE}"
}

# ---------------------------------------------------------------
# Gate execution — five gates, in this order, one after another, and
# the release stops at the first one that refuses.
#
# They are ordered by what they are about rather than by cost, because
# each one costs seconds: is production ready for a new version, has
# this commit been judged, is anything shipped known-vulnerable, is
# anything shipped out of date, is the analysis clean. Nothing here
# runs a test — see the header for where the tests run and why that is
# the more trustworthy answer.
#
# This used to be a parallel scheduler: seven gates in background
# subshells, a sentinel-file dependency chain between the three that
# fought over the same local MySQL server, and a collection loop that
# reported every failure together. All of it existed to overlap runs
# measured in tens of minutes. With none of those left, the machinery
# would be a page of orchestration for five checks that finish before
# it could have forked them.
#
# Each gate still runs in a subshell under `set +e`, because several are
# WRITTEN for errexit being off and say so in their own comments —
# check_sonar_gate's `|| exit 1` only makes sense that way. Output is
# tee'd rather than captured: with one gate running at a time there is
# nothing to interleave, and the CI gate can wait minutes, which must
# not look like a hang. A passing gate writes its report line to
# ${GATE_REPORT_FILE} rather than setting a variable, since a subshell's
# assignments never reach this shell. stdin is /dev/null so a gate that
# somehow tried to prompt (check-sonar-release.sh asking for a missing
# SONAR_TOKEN) fails closed instead of hanging.
#
# A skipped gate (--skip-*) is never run at all; its warning and its
# report line say exactly what was not checked.
# ---------------------------------------------------------------
GATE_TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${GATE_TMP_DIR}"' EXIT

run_gate() {
    local key="$1" label="$2" func="$3"
    local status=0

    echo ""
    echo "── ${label} ──"

    export GATE_REPORT_FILE="${GATE_TMP_DIR}/${key}.report"
    # pipefail (line 2) makes the pipeline's status the subshell's own;
    # tee always succeeds. `|| status=$?` because errexit would otherwise
    # end the script here without the message below.
    (
        set +e
        "${func}"
        exit $?
    ) < /dev/null 2>&1 | tee "${GATE_TMP_DIR}/${key}.log" || status=$?
    unset GATE_REPORT_FILE

    if [[ "${status}" -ne 0 ]]; then
        echo "" >&2
        echo "❌ ERROR: release blocked by the ${label} gate (above). Nothing was committed, tagged or published." >&2
        exit 1
    fi

    echo "✅ ${label} gate passed"
}

if [[ "${SKIP_DEPLOYMENT_CHECK}" -eq 1 ]]; then
    echo "WARNING: --skip-deployment-check used — ${PRODUCTION_URL} was NOT checked for this release. Emergency use only: verify it manually right after publishing." >&2
    DEPLOYMENT_GATE_REPORT_LINE="ignoré (\`--skip-deployment-check\`) — à vérifier manuellement."
else
    run_gate deployment "Deployment" check_deployment_gate
fi

if [[ "${SKIP_CI_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-ci-gate used — nothing checked that CI is green on the commit being released, so NOTHING in this script has looked at the code. Emergency use only: read the Actions tab immediately, and expect the tag's own Release workflow to refuse if a gate is red." >&2
    CI_GATE_REPORT_LINE="ignoré (\`--skip-ci-gate\`) — l'état de l'intégration continue sur le commit livré n'a pas été vérifié avant la publication."
else
    run_gate ci "Continuous integration" check_ci_gate
fi

if [[ "${SKIP_SECURITY_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-security-gate used — composer audit, npm audit, open CodeQL findings and open Dependabot alerts were NONE of them checked for this release. Emergency use only: verify and resolve them immediately after publishing." >&2
    SECURITY_GATE_REPORT_LINE="ignoré (\`--skip-security-gate\`) — à vérifier manuellement."
else
    run_gate security "Security" check_security_gate
fi

if [[ "${SKIP_DEPENDENCY_CHECK}" -eq 1 ]]; then
    echo "WARNING: --skip-dependency-check used — outdated Composer/vendored front-end dependencies were NOT checked for this release. Emergency use only: update them immediately after publishing." >&2
    DEPENDENCY_GATE_REPORT_LINE="ignoré (\`--skip-dependency-check\`) — à vérifier manuellement."
else
    run_gate dependency "Dependency freshness" check_dependency_freshness_gate
fi

if [[ "${SKIP_SONAR_GATE}" -eq 1 ]]; then
    echo "WARNING: --skip-sonar-gate used — active SonarQube Cloud security findings, unreviewed Security Hotspots, and the Quality Gate were NOT checked for this release. Emergency use only: verify and resolve them immediately after publishing." >&2
    SONAR_GATE_REPORT_LINE="ignoré (\`--skip-sonar-gate\`) — à vérifier manuellement."
else
    run_gate sonar "SonarQube Cloud" check_sonar_gate
fi

echo ""
echo "Every gate passed. Committing the version, tagging, and handing over to the Release workflow."

DEPLOYMENT_GATE_REPORT_LINE="${DEPLOYMENT_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/deployment.report" 2>/dev/null)}"
CI_GATE_REPORT_LINE="${CI_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/ci.report" 2>/dev/null)}"
SECURITY_GATE_REPORT_LINE="${SECURITY_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/security.report" 2>/dev/null)}"
DEPENDENCY_GATE_REPORT_LINE="${DEPENDENCY_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/dependency.report" 2>/dev/null)}"
SONAR_GATE_REPORT_LINE="${SONAR_GATE_REPORT_LINE:-$(cat "${GATE_TMP_DIR}/sonar.report" 2>/dev/null)}"

GATE_REPORT="- **Déploiement** : ${DEPLOYMENT_GATE_REPORT_LINE}
- **Intégration continue** : ${CI_GATE_REPORT_LINE}
- **Sécurité** : ${SECURITY_GATE_REPORT_LINE}
- **Dépendances** : ${DEPENDENCY_GATE_REPORT_LINE}
- **SonarQube Cloud** : ${SONAR_GATE_REPORT_LINE}
"

rm -rf "${GATE_TMP_DIR}"
trap - EXIT

# The VERSION file is the running site's source of truth for its installed
# version (Core\Maintenance\VersionFile, read by the Configuration >
# Maintenance "Mise à jour" section) — it must be committed as part of the
# release commit so the tag, the file, and the artifact all agree.
#
# Skipped, not failed, when VERSION already reads this version: that is
# what a re-run after a red Release workflow looks like (the header says
# how to get there), and `git commit` with nothing staged would otherwise
# stop the release right here, after every gate had passed again.
echo "${NEW_VERSION}" > VERSION
if git diff --quiet -- VERSION; then
    echo "VERSION already reads ${NEW_VERSION} (a re-run after a deleted tag) — nothing to commit."
else
    git add VERSION
    git commit -m "chore: bump VERSION to ${NEW_VERSION}"
    git push origin HEAD
fi

# Create annotated tag
git tag -a "${TAG}" -m "Release ${TAG}"
git push origin "${TAG}"

# Pushing the tag started the Release workflow (see the header). The
# artifact is built HERE, while the runners work, and attached to the
# workflow's draft once they are done.
#
# Build release artifact — delegated to scripts/build-artifact.sh,
# which is the ONE implementation of "what an installable ScoutMagic
# artifact is": the --no-dev/--optimize-autoloader Composer install,
# the exclusion list, the flat `zip -r <artifact> .` shape, the
# vendor/autoload.php and root-.htaccess assertions, and the trap that
# puts this checkout's dev dependencies back on any exit. The
# development channel's CI build (.github/workflows/dev-build.yml)
# calls the same script, so the two can never drift — a second,
# hand-maintained copy of that list is how the dev channel ended up
# shipping tests/ and no vendor/ at all in the first place.
#
# Everything Composer-related therefore lives inside that script,
# including the restore: by the time it returns, this working tree has
# its dev dependencies back. The trap registered here only cleans up
# this script's own two temp files; it is single-quoted so bash
# expands the variables at trap-fire time, and registered before they
# exist (as empty strings) so an early failure still triggers it.
LISTING_FILE=""
FINAL_NOTES_FILE=""
trap 'rm -f "${LISTING_FILE}" "${FINAL_NOTES_FILE}"' EXIT
ARTIFACT="release-${TAG}.zip"
ARTIFACT_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
"${ARTIFACT_SCRIPT_DIR}/build-artifact.sh" "${ARTIFACT}"

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
#
# The two assertions build-artifact.sh already makes (vendor/autoload.php
# present, no root-level .htaccess) are NOT repeated here: they belong
# to the artifact itself and therefore to both channels. The ones
# below are release-specific — they guard what a *published release*
# must never contain — and stay here.
LISTING_FILE="$(mktemp)"
FINAL_NOTES_FILE="$(mktemp)"
unzip -l "${ARTIFACT}" > "${LISTING_FILE}"

# The contextual help ships as Markdown under docs/help/ (ARCHITECTURE.md
# §8.64) and is read at runtime — docs/ is deliberately NOT in the -x
# exclusion list above, and this assertion is what keeps a future
# "exclude docs/ from the artifact" cleanup from silently shipping a
# release whose /aide is empty. Same file-based grep as
# build-artifact.sh's own two checks, for the same SIGPIPE reason.
if ! grep -q 'docs/help/' "${LISTING_FILE}"; then
    echo "ERROR: release artifact is missing docs/help/ (contextual help) — aborting release." >&2
    rm -f "${ARTIFACT}"
    exit 1
fi

# node_modules/ and coverage/ are development/test-only (Vitest — see
# build-artifact.sh's -x list); a leftover local install of either must
# never reach a release artifact regardless of how it got there.
if grep -qE '[[:space:]](node_modules|coverage)/' "${LISTING_FILE}"; then
    echo "ERROR: release artifact contains node_modules/ or coverage/ — aborting release." >&2
    rm -f "${ARTIFACT}"
    exit 1
fi

# The reference dataset (tests/fixtures/reference-dataset/ — its own
# README.md) is a test harness: fake member exports, fake bank
# statements, a CLI builder that writes massively to the database, and
# documented demo passwords. It is already covered by the "tests/*"
# entry of build-artifact.sh's -x list, so this check has nothing of
# its own to exclude — it exists so that exclusion stops being tacit.
# Anything that moves this dataset out from under tests/ (or a -x list
# someone trims) fails the release here instead of shipping a builder
# into an installable artifact.
#
# Matched as a DIRECTORY (trailing slash), not as a bare substring: the
# dataset is always a directory of files, whereas
# docs/chantiers/reference-dataset.md — documentation ABOUT it, which
# does ship and should — carries the same word in its filename and
# blocked a release here once, after every gate had already passed.
if grep -q 'reference-dataset/' "${LISTING_FILE}"; then
    echo "ERROR: release artifact contains reference-dataset — the test dataset must never ship; aborting release." >&2
    rm -f "${ARTIFACT}"
    exit 1
fi

# ---------------------------------------------------------------
# Wait for the Release workflow — every gate again, on GitHub's runners,
# then the signed evidence pack and the draft Release it is attached to.
# Waiting here is what makes the whole chain one command: the alternative
# is a human remembering to come back an hour later to finish a release by
# hand, which is how a version ships with a red gate nobody looked at.
#
# The run is found by tag rather than by commit: a tag push sets the run's
# head_branch to the tag name, and the release commit also has a CI run of
# its own against main.
# ---------------------------------------------------------------
echo ""
echo "Waiting for the Release workflow (every gate on a runner, then the evidence pack)..."

RELEASE_RUN_ID=""
for _ in $(seq 1 30); do
    RELEASE_RUN_ID="$(gh run list --workflow=release.yml --branch "${TAG}" \
        --limit 1 --json databaseId -q '.[0].databaseId' 2>/dev/null || true)"
    [[ -n "${RELEASE_RUN_ID}" && "${RELEASE_RUN_ID}" != "null" ]] && break
    sleep 10
done

if [[ -z "${RELEASE_RUN_ID}" || "${RELEASE_RUN_ID}" == "null" ]]; then
    echo "ERROR: no Release workflow run appeared for ${TAG} after 5 minutes." >&2
    echo "The tag is pushed and nothing is published. Check the Actions tab; if the workflow" >&2
    echo "never started, re-run it for the tag rather than cutting another version, then" >&2
    echo "finish by hand: gh release upload ${TAG} ${ARTIFACT} bootstrap/bootstrap.php && gh release edit ${TAG} --draft=false --latest" >&2
    exit 1
fi

# Watched for the live job list, but NOT trusted for the verdict: `gh run
# watch` refuses a run that has already completed, and a fast failure can
# finish before the poll above even finds it. The conclusion is read
# separately afterwards, so the decision is the same whether the run was
# watched or was already over.
if [[ "$(gh run view "${RELEASE_RUN_ID}" --json status -q .status)" != "completed" ]]; then
    gh run watch "${RELEASE_RUN_ID}" --interval 15 || true
fi

RELEASE_RUN_CONCLUSION="$(gh run view "${RELEASE_RUN_ID}" --json conclusion -q .conclusion)"
if [[ "${RELEASE_RUN_CONCLUSION}" != "success" ]]; then
    echo "" >&2
    echo "ERROR: the Release workflow concluded '${RELEASE_RUN_CONCLUSION}' — a gate is red on the runner." >&2
    echo "Nothing was published: the workflow creates no draft when a gate is red." >&2
    echo "Tag ${TAG} exists and points at nothing. Fix the cause on main, then:" >&2
    echo "  git push --delete origin ${TAG} && git tag -d ${TAG}" >&2
    echo "and run this script again (it recomputes ${NEW_VERSION} and leaves VERSION alone)." >&2
    exit 1
fi

# ---------------------------------------------------------------
# Attach the deployable zip and bootstrap.php to the draft.
#
# The workflow's draft carries the evidence pack only. The artifact built
# above is the other half — the copy of the site somebody actually
# installs — and the two belong on the same Release. --clobber so a re-run
# replaces the asset instead of failing on a name that is already there.
#
# GitHub does not preserve this command's argument order in the assets
# array (observed: it sorts alphabetically, putting bootstrap.php before
# the zip) — both Core\Maintenance\GitHubReleaseClient and bootstrap.php's
# own resolveArchiveUrl() select the artifact by its .zip filename, never
# by array position, so upload order here doesn't matter. What DOES matter
# is that the zip is the only .zip — see the header, and the count below.
# ---------------------------------------------------------------
EVIDENCE_ASSET="evidence-${TAG}.tar.gz"

echo ""
echo "Attaching ${ARTIFACT} and bootstrap.php to the draft Release..."
gh release upload "${TAG}" "${ARTIFACT}" "bootstrap/bootstrap.php" --clobber

RELEASE_ASSETS="$(gh release view "${TAG}" --json assets -q '.assets[].name')"
ZIP_COUNT="$(grep -c '\.zip$' <<< "${RELEASE_ASSETS}" || true)"
if [[ "${ZIP_COUNT}" -ne 1 ]]; then
    echo "ERROR: the draft Release ${TAG} carries ${ZIP_COUNT} .zip asset(s); exactly one is allowed:" >&2
    printf '  %s\n' ${RELEASE_ASSETS} >&2
    echo "Every installed site installs the FIRST .zip it finds. Release NOT published — remove the extra asset(s) and finish by hand: gh release edit ${TAG} --draft=false --latest" >&2
    exit 1
fi
if ! grep -qx "${EVIDENCE_ASSET}" <<< "${RELEASE_ASSETS}"; then
    echo "ERROR: the draft Release ${TAG} does not carry ${EVIDENCE_ASSET} — the workflow's evidence pack is missing." >&2
    echo "Release NOT published. Read the workflow run (${RELEASE_RUN_ID}) before finishing by hand." >&2
    exit 1
fi

# ---------------------------------------------------------------
# Compose the notes. Four parts, in this order, and only the first is
# written by a person:
#
#   1. The note itself — NOTES_FILE, or the commit list GitHub generates.
#      --generate-notes only supports *prepending* custom text via
#      --notes, so the auto-generated list is pre-fetched through the same
#      endpoint that flag uses (`releases/generate-notes`) and both paths
#      go through the same appends below.
#   2. "Vérifications effectuées" — ${GATE_REPORT}, one line per local
#      gate as it ran or was bypassed. Added here in the script itself,
#      never left to whoever wrote NOTES_FILE, so it cannot be forgotten
#      or drift from what actually ran.
#   3. The evidence pack — the body the Release workflow wrote on its
#      draft: what is in the archive and how to verify its signature. Kept
#      rather than overwritten, since it is the part a reader auditing the
#      release needs.
#   4. The dependency inventory — scripts/dependency-inventory.php, read
#      from the lock files and the vendored banners so it says what
#      shipped rather than what a constraint allowed. Last because it is
#      the longest.
# ---------------------------------------------------------------
if [[ -n "${NOTES_FILE}" ]]; then
    cat "${NOTES_FILE}" > "${FINAL_NOTES_FILE}"
else
    gh api "repos/{owner}/{repo}/releases/generate-notes" \
        -f tag_name="${TAG}" --jq '.body' > "${FINAL_NOTES_FILE}" \
        || { echo "ERROR: cannot generate release notes. Release ${TAG} is still a draft; nothing was published." >&2; exit 1; }
fi

{
    echo ""
    echo "---"
    echo ""
    echo "## Vérifications effectuées pour cette release"
    echo ""
    printf '%s' "${GATE_REPORT}"
    echo ""
    gh release view "${TAG}" --json body -q .body
    echo ""
    php "${ARTIFACT_SCRIPT_DIR}/dependency-inventory.php"
} >> "${FINAL_NOTES_FILE}" \
    || { echo "ERROR: could not compose the release notes (evidence body or dependency inventory). Release ${TAG} is still a draft; nothing was published." >&2; exit 1; }

gh release edit "${TAG}" --title "Release ${TAG}" --notes-file "${FINAL_NOTES_FILE}"

# Publishing here rather than leaving the draft for a human is deliberate,
# and it is not a loosening: the draft exists so that nothing is published
# before the gates have spoken, and by this line they have — twice. What
# is given up is a pair of eyes on the evidence BEFORE the Release is
# public; the pack stays attached to the published Release, so it is still
# read, just not as a blocking step. --latest re-asserted rather than left
# to GitHub's default, since the stable update channel reads
# `releases/latest` and nothing else.
gh release edit "${TAG}" --draft=false --latest

rm -f "${ARTIFACT}"
echo ""
echo "GitHub release ${TAG} published: ${ARTIFACT}, bootstrap.php and ${EVIDENCE_ASSET} attached."
echo "  https://github.com/$(gh repo view --json nameWithOwner -q .nameWithOwner)/releases/tag/${TAG}"
