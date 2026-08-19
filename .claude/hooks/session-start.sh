#!/bin/bash
#
# SessionStart hook for Claude Code on the web.
#
# Brings a fresh remote container to the point where every gate in AGENTS.md
# § Releases can actually be run:
#
#   vendor/bin/phpstan analyse                     (static analysis)
#   vendor/bin/phpunit                             (1561 tests)
#   vendor/bin/phpunit --group=database            (2858 tests, needs MySQL)
#   npm run test:coverage                          (Vitest, tests/js/)
#
# Local machines are untouched: this exits immediately unless it is running in
# a remote session. Nothing here is required to run, build, or deploy
# ScoutMagic itself — it is development/test tooling only, the same status
# AGENTS.md § CSS / frontend already gives npm.
#
set -euo pipefail

[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

log()  { printf '\n=== %s\n' "$*"; }
warn() { printf '\n!!! %s (continuing)\n' "$*" >&2; }

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_PROCESS_TIMEOUT=900

# ---------------------------------------------------------------------------
# 1. System packages
#
# ghostscript + imagick: Core\File\PdfRasterizer and Core\Pdf\PdfCompressor.
#   Their tests self-skip when these are absent, so without this the suite
#   reports OK while silently skipping 6 tests.
# pcov: coverage driver for `phpunit --coverage-clover` (what CI uses).
# mariadb-server: the --group=database suite. MySQL-compatible; the schema
#   and every Repository test run against it unchanged.
# ---------------------------------------------------------------------------
PKGS=()
command -v gs >/dev/null                || PKGS+=(ghostscript)
php -m | grep -qix imagick              || PKGS+=(php8.4-imagick)
php -m | grep -qix pcov                 || PKGS+=(php8.4-pcov)
command -v mariadbd >/dev/null          || PKGS+=(mariadb-server)

if [ ${#PKGS[@]} -gt 0 ]; then
    log "Installing system packages: ${PKGS[*]}"
    apt-get update -qq && apt-get install -y -qq "${PKGS[@]}" || warn "apt-get failed"
fi

# ---------------------------------------------------------------------------
# 2. Composer dependencies
#
# --prefer-source is REQUIRED here, not a preference. Packagist points every
# package's `dist` at https://api.github.com/repos/<vendor>/<pkg>/zipball/...,
# and a remote session's GitHub access is scoped to this repository only, so
# every one of those zipballs returns 403 ("GitHub access to this repository
# is not enabled for this session"). Plain `git clone` of a public repo IS
# served by the session's git proxy, so resolving from `source` works for all
# 72 packages that declare one.
#
# phpstan/phpstan is the one exception: it is published dist-only, with no
# `source` in composer.lock, so --prefer-source cannot reach it either. The
# loop below rebuilds its dist archive from its git repository and drops it
# into Composer's own download cache under the exact key Composer would look
# for, so `composer install` finds it already cached and never makes the
# blocked request. composer.lock stays authoritative -- the commit fetched is
# the one the lock pins, so this changes no version.
# ---------------------------------------------------------------------------
seed_dist_only_packages() {
    php -r '
        $lock = json_decode(file_get_contents("composer.lock"), true);
        foreach (array_merge($lock["packages"], $lock["packages-dev"] ?? []) as $p) {
            if (!empty($p["source"]["url"]) || empty($p["dist"]["url"])) {
                continue;
            }
            printf("%s\t%s\t%s\t%s\n", $p["name"], $p["dist"]["url"], $p["dist"]["type"], $p["dist"]["reference"]);
        }
    ' | while IFS=$'\t' read -r name url type ref; do
        cache_dir="${COMPOSER_CACHE_DIR:-/root/.cache/composer}/files/${name}"
        cache_file="${cache_dir}/$(printf '%s' "$url" | sha1sum | cut -d' ' -f1).${type}"
        [ -f "$cache_file" ] && continue

        log "Seeding Composer cache for dist-only package ${name} (${ref})"
        repo=$(printf '%s' "$url" | sed -E 's#https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/.*#https://github.com/\1.git#')
        tmp=$(mktemp -d)
        (
            git -C "$tmp" init --quiet
            git -C "$tmp" remote add origin "$repo"
            git -C "$tmp" fetch --quiet --depth 1 origin "$ref"
            git -C "$tmp" checkout --quiet FETCH_HEAD
            mkdir -p "$cache_dir"
            # GitHub zipballs wrap everything in one top-level directory, which
            # Composer strips on extract; reproduce that or every path is wrong.
            git -C "$tmp" archive --format="$type" --prefix="${name//\//-}-${ref:0:7}/" -o "$cache_file" HEAD
        ) || warn "could not seed ${name}; composer install may fail on it"
        rm -rf "$tmp"
    done
}

if [ ! -f vendor/autoload.php ]; then
    seed_dist_only_packages
    log "composer install (--prefer-source; see comment above)"
    composer install --prefer-source --no-progress --no-interaction || warn "composer install failed"
else
    log "Composer dependencies already present"
fi

# ---------------------------------------------------------------------------
# 3. Node dependencies for the Vitest suite (tests/js/)
# `install` rather than `ci`: the container image is cached after this hook
# completes, and install reuses an already-populated node_modules.
# ---------------------------------------------------------------------------
if [ ! -d node_modules ]; then
    log "npm install"
    npm install --no-audit --no-fund || warn "npm install failed"
else
    log "node_modules already present"
fi

# ---------------------------------------------------------------------------
# 4. MySQL for `phpunit --group=database`
#
# No systemd in the container, so mariadbd is started directly. Credentials
# match .github/workflows/ci.yml's database-tests job exactly, so the same
# TEST_DB_* values work here and in CI.
# ---------------------------------------------------------------------------
if command -v mariadbd >/dev/null; then
    if ! mariadb-admin ping --protocol=tcp -h 127.0.0.1 --silent 2>/dev/null; then
        log "Starting MariaDB"
        mkdir -p /var/run/mysqld /var/log/mysql
        chown -R mysql:mysql /var/run/mysqld /var/log/mysql /var/lib/mysql 2>/dev/null || true
        nohup /usr/sbin/mariadbd --user=mysql --datadir=/var/lib/mysql \
            --socket=/var/run/mysqld/mysqld.sock --bind-address=127.0.0.1 --port=3306 \
            >/var/log/mysql/session-start.log 2>&1 &

        for _ in $(seq 1 60); do
            mariadb-admin ping --protocol=tcp -h 127.0.0.1 --silent 2>/dev/null && break
            sleep 1
        done
    fi

    if mariadb-admin ping --protocol=tcp -h 127.0.0.1 --silent 2>/dev/null; then
        # root@localhost is what a 127.0.0.1 TCP connection actually matches,
        # so both rows need the password the tests pass.
        #
        # Two connection attempts, not one: on a first run root@localhost has
        # no password and the socket login succeeds bare, but on every later
        # run this script has already set one, so the bare login is refused.
        # Trying both is what makes this step genuinely idempotent.
        sql_file=$(mktemp)
        cat > "$sql_file" <<'SQL'
CREATE DATABASE IF NOT EXISTS test_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY 'test_password';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
ALTER USER 'root'@'localhost' IDENTIFIED BY 'test_password';
FLUSH PRIVILEGES;
SQL
        if mariadb --socket=/var/run/mysqld/mysqld.sock -u root < "$sql_file" 2>/dev/null \
            || mariadb --socket=/var/run/mysqld/mysqld.sock -u root -ptest_password < "$sql_file" 2>/dev/null; then
            log "MariaDB ready on 127.0.0.1:3306 (test_db)"
        else
            warn "could not provision test_db; --group=database will not run"
        fi
        rm -f "$sql_file"
    else
        warn "MariaDB did not come up; --group=database will not run"
    fi
fi

# ---------------------------------------------------------------------------
# 5. Environment for the session
# ---------------------------------------------------------------------------
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    cat >> "$CLAUDE_ENV_FILE" <<'ENV'
export TEST_DB_HOST=127.0.0.1
export TEST_DB_PORT=3306
export TEST_DB_NAME=test_db
export TEST_DB_USER=root
export TEST_DB_PASSWORD=test_password
export COMPOSER_ALLOW_SUPERUSER=1
ENV
fi

log "Ready: vendor/bin/phpstan analyse | vendor/bin/phpunit | vendor/bin/phpunit --group=database | npm run test:coverage"
