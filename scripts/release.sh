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

# Create annotated tag
git tag -a "${TAG}" -m "Release ${TAG}"
git push origin "${TAG}"

# Create GitHub release (requires gh CLI)
if command -v gh &> /dev/null; then
    # Build release artifact
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
    ARTIFACT="release-${TAG}.zip"
    zip -r "${ARTIFACT}" . \
        -x ".git/*" ".github/*" "tests/*" "storage/keys/*" "storage/config/*" \
           "storage/temp/*" "config/app.php" ".gitignore" ".env" "*.zip"

    gh release create "${TAG}" "${ARTIFACT}" \
        --title "Release ${TAG}" \
        --generate-notes

    rm -f "${ARTIFACT}"
    echo "GitHub release ${TAG} created with artifact."
else
    echo "Tag ${TAG} pushed. Install GitHub CLI (gh) to auto-create releases."
fi
