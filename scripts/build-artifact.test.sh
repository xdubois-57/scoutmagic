#!/bin/bash
set -euo pipefail

# Usage: ./scripts/build-artifact.test.sh
#
# Exercises scripts/build-artifact.sh without a real Composer run and
# without touching this checkout's own vendor/: a fake `composer` (in a
# temp directory prepended to PATH) records every invocation and does
# nothing else, and the script under test is copied into a throwaway
# fixture tree whose contents are chosen to prove each line of the
# exclusion list and each of the two assertions.
#
# Same shape and same reasoning as scripts/check-sonar-release.test.sh:
# what is being tested is the script's decisions, not whether Composer or
# GitHub work. Run manually; nothing in CI runs it, because CI's own
# dev-build workflow (and every release) exercises the real thing.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_SCRIPT="${SCRIPT_DIR}/build-artifact.sh"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

FAKE_BIN_DIR="${WORK_DIR}/bin"
mkdir -p "${FAKE_BIN_DIR}"
cat > "${FAKE_BIN_DIR}/composer" <<'EOF'
#!/bin/bash
# Records the invocation and succeeds. vendor/ is provided by the fixture
# instead, so the script's own assertions still have something to check.
echo "$*" >> "${COMPOSER_CALL_LOG}"
EOF
chmod +x "${FAKE_BIN_DIR}/composer"
export PATH="${FAKE_BIN_DIR}:${PATH}"

PASS_COUNT=0
FAIL_COUNT=0

pass() { echo "  ✅ $1"; PASS_COUNT=$((PASS_COUNT + 1)); }
fail() { echo "  ❌ $1"; FAIL_COUNT=$((FAIL_COUNT + 1)); }

assert_contains() {
    if grep -q -- "$2" "$1"; then pass "$3"; else fail "$3"; fi
}

assert_not_contains() {
    if grep -q -- "$2" "$1"; then fail "$3"; else pass "$3"; fi
}

# A throwaway repository root holding a copy of the script (which locates
# the tree it zips from its OWN path, so the copy is what makes the
# fixture the "repository"). Every file below exists to prove one entry of
# the exclusion list, or one of the two assertions.
make_fixture() {
    local root="${WORK_DIR}/$1"
    rm -rf "${root}"
    mkdir -p "${root}/scripts"
    cp "${BUILD_SCRIPT}" "${root}/scripts/build-artifact.sh"

    mkdir -p "${root}/core" "${root}/modules/gallery" "${root}/public" \
             "${root}/docs/help" "${root}/schema" \
             "${root}/vendor/twig" "${root}/tests/Core" "${root}/bootstrap" \
             "${root}/.github/workflows" "${root}/storage/uploads" \
             "${root}/node_modules/left-pad" "${root}/config" \
             "${root}/.claude/worktrees/agent-x"
    echo '<?php' > "${root}/core/App.php"
    echo '{}' > "${root}/modules/gallery/module.json"
    echo '<?php' > "${root}/public/index.php"
    echo '# aide' > "${root}/docs/help/mise-a-jour.md"
    echo 'CREATE TABLE members (id INT);' > "${root}/schema/core.sql"
    echo '<?php' > "${root}/vendor/autoload.php"
    echo '<?php' > "${root}/vendor/twig/Environment.php"
    echo '<?php' > "${root}/tests/Core/AppTest.php"
    echo '<?php' > "${root}/bootstrap/bootstrap.php"
    echo 'name: CI' > "${root}/.github/workflows/ci.yml"
    echo 'live upload' > "${root}/storage/uploads/doc.pdf"
    echo 'x' > "${root}/node_modules/left-pad/index.js"
    echo '<?php' > "${root}/config/app.php"
    echo '<?php' > "${root}/config/routes.php"
    echo '{}' > "${root}/package.json"
    echo '{}' > "${root}/package-lock.json"
    echo 'SECRET=1' > "${root}/.env"
    echo '<?php' > "${root}/.claude/worktrees/agent-x/nested.php"
    echo '1.0.0' > "${root}/VERSION"

    echo "${root}"
}

echo "── A normal build ──────────────────────────────────────────────────"
ROOT="$(make_fixture normal)"
export COMPOSER_CALL_LOG="${WORK_DIR}/composer-normal.log"
: > "${COMPOSER_CALL_LOG}"
ARTIFACT="${WORK_DIR}/artifact.zip"
if "${ROOT}/scripts/build-artifact.sh" "${ARTIFACT}" > "${WORK_DIR}/normal.out" 2>&1; then
    pass "the script succeeds on a well-formed tree"
else
    fail "the script succeeds on a well-formed tree"
    cat "${WORK_DIR}/normal.out"
fi

LISTING="${WORK_DIR}/listing.txt"
unzip -l "${ARTIFACT}" > "${LISTING}"

assert_contains "${LISTING}" 'vendor/autoload.php' "vendor/ ships — without it the site is dead on a host with no Composer"
assert_contains "${LISTING}" 'vendor/twig/Environment.php' "the whole vendor tree ships, not just the autoloader"
assert_contains "${LISTING}" 'core/App.php' "core/ ships"
assert_contains "${LISTING}" 'modules/gallery/module.json' "modules/ ship"
assert_contains "${LISTING}" 'docs/help/mise-a-jour.md' "docs/help/ ships — it is the contextual help, read at runtime"
assert_contains "${LISTING}" 'schema/core.sql' "schema/ ships — the migration step runs against it"
assert_contains "${LISTING}" 'config/routes.php' "config/ ships apart from the unit-specific app.php"

assert_not_contains "${LISTING}" 'tests/' "tests/ never reach a production webroot"
assert_not_contains "${LISTING}" 'bootstrap/' "bootstrap/ is the installer, never re-planted into a live site"
assert_not_contains "${LISTING}" '.github/' ".github/ never reaches a production webroot"
assert_not_contains "${LISTING}" 'storage/' "storage/ is live data and would be a personal-data leak"
assert_not_contains "${LISTING}" 'node_modules/' "node_modules/ is development-only"
assert_not_contains "${LISTING}" 'package.json' "package.json is development-only"
assert_not_contains "${LISTING}" 'config/app.php' "config/app.php is unit-specific"
assert_not_contains "${LISTING}" '.env' ".env is unit-specific and holds secrets"
assert_not_contains "${LISTING}" '.claude/' ".claude/ can hold whole nested checkouts"

# Flat, not wrapped: this is what lets both channels install the artifact
# as source_type 'release'. GitHub's zipball wraps everything in one
# "{owner}-{repo}-{sha}/" directory and needs stripping; this must not.
if unzip -l "${ARTIFACT}" | grep -qE '[[:space:]]core/App\.php$'; then
    pass "the archive is flat — entries start at the repository root"
else
    fail "the archive is flat — entries start at the repository root"
fi

assert_contains "${COMPOSER_CALL_LOG}" 'install --no-dev --optimize-autoloader' "a production vendor/ is resolved before zipping"
assert_contains "${COMPOSER_CALL_LOG}" '^install --no-interaction' "dev dependencies are restored on exit, so the caller's tree still works"

echo "── A tree that would ship a root .htaccess ─────────────────────────"
ROOT="$(make_fixture htaccess)"
echo 'Deny from all' > "${ROOT}/.htaccess"
export COMPOSER_CALL_LOG="${WORK_DIR}/composer-htaccess.log"
: > "${COMPOSER_CALL_LOG}"
ARTIFACT="${WORK_DIR}/htaccess.zip"
if "${ROOT}/scripts/build-artifact.sh" "${ARTIFACT}" > "${WORK_DIR}/htaccess.out" 2>&1; then
    fail "a root-level .htaccess aborts the build"
else
    pass "a root-level .htaccess aborts the build"
fi
if [[ -f "${ARTIFACT}" ]]; then
    fail "the rejected artifact is deleted rather than left lying around"
else
    pass "the rejected artifact is deleted rather than left lying around"
fi
assert_contains "${COMPOSER_CALL_LOG}" '^install --no-interaction' "dev dependencies are restored even when the build aborts"

echo "── A tree with no production vendor/ ───────────────────────────────"
ROOT="$(make_fixture novendor)"
rm -rf "${ROOT}/vendor"
export COMPOSER_CALL_LOG="${WORK_DIR}/composer-novendor.log"
: > "${COMPOSER_CALL_LOG}"
ARTIFACT="${WORK_DIR}/novendor.zip"
if "${ROOT}/scripts/build-artifact.sh" "${ARTIFACT}" > "${WORK_DIR}/novendor.out" 2>&1; then
    fail "a missing vendor/autoload.php aborts the build"
else
    pass "a missing vendor/autoload.php aborts the build"
fi
assert_contains "${WORK_DIR}/novendor.out" 'vendor/autoload.php' "the failure says what is missing"

echo "── No output path ──────────────────────────────────────────────────"
ROOT="$(make_fixture noarg)"
export COMPOSER_CALL_LOG="${WORK_DIR}/composer-noarg.log"
: > "${COMPOSER_CALL_LOG}"
if "${ROOT}/scripts/build-artifact.sh" > "${WORK_DIR}/noarg.out" 2>&1; then
    fail "the output path is required"
else
    pass "the output path is required"
fi
if [[ -s "${COMPOSER_CALL_LOG}" ]]; then
    fail "a usage error touches nothing — no Composer run at all"
else
    pass "a usage error touches nothing — no Composer run at all"
fi

echo ""
echo "===================================================================="
echo "Passed: ${PASS_COUNT}    Failed: ${FAIL_COUNT}"
echo "===================================================================="
[[ "${FAIL_COUNT}" -eq 0 ]]
