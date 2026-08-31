#!/bin/bash
set -euo pipefail

# Usage: ./scripts/build-artifact.sh <output-zip-path>
#
# Builds THE installable ScoutMagic artifact: the repository tree with a
# production `vendor/` in it and nothing a live site must never receive.
# It is the single implementation shared by the two channels that publish
# one, so they cannot drift:
#
#   - scripts/release.sh          — the stable channel's release-<tag>.zip
#   - .github/workflows/dev-build.yml — the development channel's
#                                   scoutmagic-dev-<sha7>.zip, published
#                                   as an asset of the rolling `dev-build`
#                                   prerelease and installed by
#                                   Core\Maintenance\Task\InstallUpdateHandler
#                                   on every push to the tracked branch.
#
# Sharing it is the whole point. The development channel used to install
# GitHub's raw zipball of the commit instead, which is the git tree: no
# `vendor/` (it is gitignored), but `tests/`, `.github/`, `bootstrap/` and
# `scripts/` all copied onto a production webroot. Since
# `InstallUpdateHandler::installFiles()` copies additively, the live
# `vendor/` was never replaced while `composer.lock` on disk was — measured
# on scoutmagic.be, where vendor/'s mtime stayed at the original install
# date across ~40 dev updates. A second, hand-maintained copy of the
# exclusion list below would reintroduce exactly that class of drift, one
# forgotten line at a time.
#
# The archive is FLAT: `zip -r <artifact> .` from the repository root, so
# its entries are `core/…`, `vendor/…` — no wrapping `{owner}-{repo}-{sha}/`
# directory. That is what lets both channels install it as
# `source_type: 'release'` (InstallUpdateHandler::resolveBranchArchiveRoot()
# is for GitHub's zipball shape and must never be applied here).
#
# Parameters:
#   <output-zip-path>  Required. Where to write the artifact. Relative
#                      paths are resolved against the caller's working
#                      directory, not the repository root.

if [[ "$#" -ne 1 || -z "${1:-}" ]]; then
    echo "Usage: $0 <output-zip-path>" >&2
    exit 1
fi

# Resolved BEFORE the cd below, so a relative path means what the caller
# meant by it. `realpath -m` does not require the file to exist yet.
ARTIFACT="$(realpath -m "$1")"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

# vendor/ must be present in the artifact — there is no Composer on a
# shared host, so a vendor-less artifact installs cleanly (per
# bootstrap.php's own artifact verification) but yields a dead site.
#
# This runs `composer install` directly against THIS checkout's own
# vendor/ (there is no separate build directory) — --no-dev strips
# phpstan/phpunit/etc. from it, which would silently break every
# subsequent `vendor/bin/phpstan`/`vendor/bin/phpunit` call in this same
# working tree until someone thought to run `composer install` again. The
# trap restores dev dependencies unconditionally on any exit from here on
# (success, a failed verification below, Ctrl-C — doesn't matter which),
# and is registered BEFORE the --no-dev install so an interrupt during it
# still restores. On CI this restore is wasted work on a throwaway
# runner; in a releaser's own checkout it is the difference between a
# working tree and a broken one.
trap 'rm -f "${LISTING_FILE:-}"; echo "Restoring dev dependencies (composer install)..."; composer install --no-interaction --quiet || echo "WARNING: failed to restore dev dependencies — run \`composer install\` manually." >&2' EXIT

LISTING_FILE=""

# --prefer-dist matters beyond speed: a package installed from SOURCE
# carries its whole upstream .git (612 MB for aws-sdk-php, measured), and
# the "*/.git" exclusions below are the belt to this suspender for a
# builder whose vendor/ predates this script.
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet

# An artifact left over from a previous run would otherwise be updated in
# place by `zip -r` rather than rebuilt, keeping entries that no longer
# exist in the tree.
rm -f "${ARTIFACT}"

# storage/* is excluded WHOLESALE, not just keys/config/temp: it's where
# every module keeps real uploaded content (gallery photos and videos,
# finance receipts, section documents, local backups, etc.) on a live or
# local dev install — publishing any of it in a public artifact would be
# a real personal-data leak. Neither InstallUpdateHandler::installFiles()
# nor bootstrap.php's bootstrap_copy_tree() ever reads storage/ from the
# artifact anyway (both explicitly skip it as a top-level entry when
# installing/updating), so there is nothing lost by excluding it
# entirely — the 5 empty subdirs are created fresh by the installer/
# updater instead.
#
# bootstrap/ is excluded: it's the installer, not something an
# install/update should ever re-plant into a live site.
#
# .claude/* matters for the same "never publish local-only state" reason:
# it can hold worktrees (each a full nested checkout,
# .claude/worktrees/<name>/), which would otherwise get zipped into the
# artifact wholesale.
#
# node_modules/, coverage/, package.json, and package-lock.json are
# development/test-only (Vitest — see AGENTS.md § CSS / frontend and
# package.json's own description): production ScoutMagic runs plain,
# unbundled browser JavaScript and needs neither Node nor npm, so none of
# these belong in the artifact — same reasoning as excluding vendor/ dev
# dependencies would if Composer's --no-dev (already run above) didn't
# handle that side on its own.
#
# docs/ is deliberately NOT excluded: the contextual help ships as
# Markdown under docs/help/ and is read at runtime (ARCHITECTURE.md
# §8.64).
# The bare ".git" entry needs excluding separately from ".git/*": zip's
# pattern matches the directory's CONTENTS, not the directory entry
# itself, and an empty .git/ extracted onto a production webroot is how
# every install ended up with one. Same story for the local tool
# droppings (".phpunit.result.cache" from release.sh's own test gate,
# ".sonar-token", ".lane-env", dast-report/) — none of them is tracked,
# all of them were observed shipped to scoutmagic.be inside release
# artifacts built from a working checkout.
zip -r "${ARTIFACT}" . \
    -x ".git" ".git/*" ".github/*" "tests/*" "storage/*" \
       "config/app.php" ".gitignore" ".env" "*.zip" \
       "bootstrap/*" ".claude/*" ".idea/*" ".vscode/*" "*.DS_Store" \
       "node_modules/*" "coverage/*" "package.json" "package-lock.json" \
       "vitest.config.js" \
       ".phpunit.result.cache" ".sonar-token" ".lane-env" \
       "dast-report" "dast-report/*" "coverage*.xml" \
       "*/.git" "*/.git/*"

# Listed to a real file rather than piped live into grep -q: with
# `set -o pipefail` (line 2), a `grep -q` that matches early closes its
# read end, SIGPIPE-killing whatever wrote to that pipe — pipefail then
# reports the pipeline's exit status as the writer's 141, not grep's own
# (successful) 0, even though the match was genuinely found. On a large
# listing (thousands of entries, as this artifact has grown to) there's
# enough left to write that the race reliably loses — this bit both
# checks below in the wild despite the artifact being correct both times.
# Grepping a file has no live writer to kill, so no race.
LISTING_FILE="$(mktemp)"
unzip -l "${ARTIFACT}" > "${LISTING_FILE}"

# bootstrap.php protects a Layout B (single-tree) install with exactly one
# root .htaccess it writes itself at install time — the artifact must
# never ship one, or bootstrap's own S7 acceptance check would correctly
# refuse the very build this script just produced. Matched directly
# against the whitespace-then-filename tail of an `unzip -l` row rather
# than via awk '{print $4}' | grep -qx, which reintroduces the same
# live-pipe SIGPIPE race one level down.
if grep -qE '[[:space:]]\.htaccess$' "${LISTING_FILE}"; then
    echo "ERROR: artifact contains a root-level .htaccess — aborting." >&2
    rm -f "${ARTIFACT}"
    exit 1
fi

if ! grep -q 'vendor/autoload.php' "${LISTING_FILE}"; then
    echo "ERROR: artifact is missing vendor/autoload.php — aborting." >&2
    rm -f "${ARTIFACT}"
    exit 1
fi

echo "Artifact built: ${ARTIFACT}"
