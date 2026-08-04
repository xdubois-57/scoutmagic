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
    zip -r "${ARTIFACT}" . \
        -x ".git/*" ".github/*" "tests/*" "storage/keys/*" "storage/config/*" \
           "storage/temp/*" "config/app.php" ".gitignore" ".env" "*.zip" \
           "bootstrap/*"

    # bootstrap.php protects a Layout B (single-tree) install with exactly
    # one root .htaccess it writes itself at install time — the artifact
    # must never ship one, or bootstrap's own S7 acceptance check would
    # correctly refuse the very release this script is about to publish.
    if unzip -l "${ARTIFACT}" | awk '{print $4}' | grep -qx '\.htaccess'; then
        echo "ERROR: release artifact contains a root-level .htaccess — aborting release." >&2
        rm -f "${ARTIFACT}"
        exit 1
    fi

    if ! unzip -l "${ARTIFACT}" | grep -q 'vendor/autoload.php'; then
        echo "ERROR: release artifact is missing vendor/autoload.php — aborting release." >&2
        rm -f "${ARTIFACT}"
        exit 1
    fi

    # bootstrap.php is published as a second asset — the zip artifact is
    # listed first so it lands as assets[0]. Core\Maintenance\
    # GitHubReleaseClient and bootstrap.php's own resolveArchiveUrl() both
    # prefer assets[0] as the main installable artifact.
    gh release create "${TAG}" "${ARTIFACT}" "bootstrap/bootstrap.php" \
        --title "Release ${TAG}" \
        --generate-notes

    rm -f "${ARTIFACT}"
    echo "GitHub release ${TAG} created with artifact and bootstrap.php."
else
    echo "Tag ${TAG} pushed. Install GitHub CLI (gh) to auto-create releases."
fi
