#!/bin/bash
set -euo pipefail

# Usage: ./scripts/release.sh [--minor|--major]
# Default: increments patch level

BUMP="patch"
if [[ "${1:-}" == "--minor" ]]; then BUMP="minor"; fi
if [[ "${1:-}" == "--major" ]]; then BUMP="major"; fi

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

    echo "Security gate OK: no open CodeQL findings, no open Dependabot alerts."
}

check_security_gate

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
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
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
    LISTING_FILE="$(mktemp)"
    trap 'rm -f "${LISTING_FILE}"' EXIT
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

    # bootstrap.php is published as a second asset. GitHub does not
    # preserve this command's argument order in the assets array (observed:
    # it sorts alphabetically, putting bootstrap.php before the zip) — both
    # Core\Maintenance\GitHubReleaseClient and bootstrap.php's own
    # resolveArchiveUrl() select the artifact by its .zip filename, never
    # by array position, so upload order here doesn't matter.
    gh release create "${TAG}" "${ARTIFACT}" "bootstrap/bootstrap.php" \
        --title "Release ${TAG}" \
        --generate-notes

    rm -f "${ARTIFACT}"
    echo "GitHub release ${TAG} created with artifact and bootstrap.php."
else
    echo "Tag ${TAG} pushed. Install GitHub CLI (gh) to auto-create releases."
fi
