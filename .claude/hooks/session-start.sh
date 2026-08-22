#!/bin/bash
#
# SessionStart hook — installs PHP and JS dependencies so `vendor/bin/phpunit`,
# `vendor/bin/phpstan` and `npm test` work in a Claude Code on the web session.
#
# Idempotent: re-running it is a fast no-op once vendor/ matches
# composer.lock and node_modules/ holds every package package.json declares.
# Both of those are MEASURED, not assumed from a directory being there — see
# composer_missing_packages() and npm_missing_packages() below for why that
# distinction is the whole point.
#
set -euo pipefail

# Local checkouts manage their own dependencies.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

export COMPOSER_NO_INTERACTION=1
export COMPOSER_ALLOW_SUPERUSER=1
export DEBIAN_FRONTEND=noninteractive

log() { echo "[session-start] $*"; }
warn() { echo "[session-start] WARNING: $*" >&2; }

# --- Freshness probes ------------------------------------------------------
#
# Both of these answer "which declared packages are NOT installed", one name
# per line, and print nothing when everything is there. They exist because
# the checks they replace asked a much weaker question — "does vendor/ exist"
# and "does node_modules/ exist" — and a container image whose dependency
# tree predates a commit satisfies both while being materially incomplete.
#
# That is not hypothetical. A container image has shipped with a vendor/
# 20 packages behind composer.lock (webklex/php-imap and its whole
# transitive tree, which arrived with the inbound_mail module) and a
# node_modules/ holding no typescript. The hook said "already present" to
# both. What that produced was a PHPStan run with 36 class.notFound errors,
# a failing Tests\Core\System\TypeHintResolutionTest and an unrunnable
# `npm run typecheck` — three findings that read as defects in this
# repository and were nothing of the sort. A missing dependency has to
# announce itself as a missing dependency.

# Every composer.lock package (dev included — phpunit and phpstan live
# there) that vendor/composer/installed.json does not record at the locked
# version, or whose directory is absent or empty.
composer_missing_packages() {
  php -r '
    $lock = json_decode((string) @file_get_contents("composer.lock"), true);
    if (!is_array($lock)) { echo "composer.lock\n"; exit; }

    $installed = [];
    if (is_file("vendor/composer/installed.json")) {
        $json = json_decode((string) file_get_contents("vendor/composer/installed.json"), true);
        foreach ((array) ($json["packages"] ?? []) as $p) { $installed[$p["name"]] = $p["version"]; }
    }

    foreach (array_merge((array) ($lock["packages"] ?? []), (array) ($lock["packages-dev"] ?? [])) as $p) {
        $dir = "vendor/" . $p["name"];
        $ok = ($installed[$p["name"]] ?? null) === $p["version"]
            && is_dir($dir) && count((array) scandir($dir)) > 2;
        if (!$ok) { echo $p["name"], "\n"; }
    }
  ' 2>/dev/null || true
}

# Every name package.json declares (dependencies and devDependencies alike)
# that does not resolve under node_modules/. Names rather than versions: the
# manifest is the contract this repository states, npm install is what the
# container caches, and a missing NAME is the failure that actually happens.
npm_missing_packages() {
  command -v node >/dev/null 2>&1 || return 0
  node -e '
    const fs = require("node:fs");
    const manifest = JSON.parse(fs.readFileSync("package.json", "utf8"));
    const declared = { ...(manifest.dependencies || {}), ...(manifest.devDependencies || {}) };
    for (const name of Object.keys(declared)) {
      if (!fs.existsSync(`node_modules/${name}/package.json`)) { console.log(name); }
    }
  ' 2>/dev/null || true
}

# "a, b, c and 17 more" — enough to recognise the problem in the session log
# without pasting a hundred package names into it.
summarise_names() {
  echo "$1" | php -r '
    $names = array_values(array_filter(array_map("trim", explode("\n", (string) stream_get_contents(STDIN)))));
    $shown = array_slice($names, 0, 3);
    $rest = count($names) - count($shown);
    echo implode(", ", $shown), $rest > 0 ? " and {$rest} more" : "";
  '
}

# --- System packages ---------------------------------------------------
#
# Ghostscript and the imagick extension back Core\File\PdfRasterizer and
# Core\Pdf\PdfCompressor. Their tests self-skip when either is missing —
# not fail — so without this the suite quietly reports OK while never
# running six PDF tests, which is worse than a visible failure. pcov is
# the coverage driver `phpunit --coverage-clover` needs (see ci.yml's
# `test` job); without it, coverage generation silently produces nothing
# useful rather than erroring.
#
# PHP's own major.minor picks the matching Sury/Ondrej-style package name
# (php8.4-imagick, php8.4-pcov, ...) instead of a version hardcoded here,
# so this keeps working if the container's PHP version moves.
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
SYSTEM_PKGS=()
command -v gs >/dev/null 2>&1 || SYSTEM_PKGS+=(ghostscript)
php -m | grep -qix imagick || SYSTEM_PKGS+=("php${PHP_VER}-imagick")
php -m | grep -qix pcov || SYSTEM_PKGS+=("php${PHP_VER}-pcov")

if [ "${#SYSTEM_PKGS[@]}" -gt 0 ]; then
  log "installing system packages: ${SYSTEM_PKGS[*]}"
  if apt-get update -qq && apt-get install -y -qq "${SYSTEM_PKGS[@]}"; then
    log "system packages installed"
  else
    warn "apt-get failed; Ghostscript/imagick/pcov-dependent tests will self-skip instead of running"
  fi
else
  log "system packages (ghostscript, imagick, pcov) already present"
fi

# --- PHP dependencies ------------------------------------------------------
#
# Three layers, cheapest first. In an unrestricted environment the first one
# is all that ever runs.
#
#   1. composer install --prefer-dist — the normal path.
#   2. composer install --prefer-source — Composer's own git transport. Some
#      sandboxes allow git over https://github.com but block the zipball hosts
#      (codeload.github.com and api.github.com answer 403), which is enough to
#      abort layer 1.
#   3. A per-package git clone at the reference composer.lock pins, for the
#      packages that publish no git source at all (phpstan/phpstan ships as a
#      phar from an api.github.com zipball, so layers 1 and 2 both fail on it —
#      and Composer aborts the whole run when one package cannot be fetched).
#
# If layers 2 or 3 are being exercised, the real fix is to allowlist
# codeload.github.com and api.github.com for this environment; then layer 1
# succeeds and the rest is dead weight.
#
# The gate below asks composer.lock, never the filesystem's shape: a
# vendor/ that is merely PRESENT tells you nothing about whether it is
# COMPLETE (see composer_missing_packages() for the incident that makes the
# difference concrete). vendor/bin/phpunit and vendor/bin/phpstan are
# checked too — this script advertises both at the end, and the layer-3
# fallback below is the one path that can leave them behind.
COMPOSER_MISSING="$(composer_missing_packages)"

if [ ! -f vendor/autoload.php ] || [ -n "${COMPOSER_MISSING}" ] \
   || [ ! -x vendor/bin/phpunit ] || [ ! -x vendor/bin/phpstan ]; then
  if [ -n "${COMPOSER_MISSING}" ]; then
    log "installing PHP dependencies ($(echo "${COMPOSER_MISSING}" | wc -l | tr -d ' ') missing vs composer.lock: $(summarise_names "${COMPOSER_MISSING}"))"
  else
    log "installing PHP dependencies"
  fi

  if composer install --prefer-dist --no-progress 2>/dev/null; then
    log "composer install (dist) ok"
  elif composer install --prefer-source --no-progress 2>/dev/null; then
    log "composer install (source) ok"
  else
    log "composer could not fetch every package; falling back to git clones at the locked references"
    log "note: allowlisting codeload.github.com and api.github.com removes the need for this"

    php -r '
      $lock = json_decode(file_get_contents("composer.lock"), true);
      $installed = [];
      if (file_exists("vendor/composer/installed.json")) {
          $j = json_decode(file_get_contents("vendor/composer/installed.json"), true);
          foreach (($j["packages"] ?? []) as $p) { $installed[$p["name"]] = $p["version"]; }
      }
      foreach (array_merge($lock["packages"], $lock["packages-dev"]) as $p) {
          $dir = "vendor/" . $p["name"];
          $present = is_dir($dir) && count(scandir($dir)) > 2
              && ($installed[$p["name"]] ?? null) === $p["version"];
          if ($present) { continue; }
          // Packages with no git source in the lock (phpstan/phpstan) still
          // have a public repository; derive it from the dist url.
          $url = $p["source"]["url"] ?? null;
          if ($url === null && preg_match("#api\.github\.com/repos/([^/]+/[^/]+)/zipball#", $p["dist"]["url"] ?? "", $m) === 1) {
              $url = "https://github.com/" . $m[1] . ".git";
          }
          if ($url === null) { continue; }
          echo $p["name"] . "\t" . $url . "\t" . $p["version"] . "\t" . ($p["source"]["reference"] ?? $p["dist"]["reference"] ?? "") . "\n";
      }
    ' > /tmp/session-start-packages.tsv

    fetch_one() {
      IFS=$'\t' read -r name url version reference <<< "$1"
      dir="vendor/$name"
      rm -rf "$dir"; mkdir -p "$dir"
      if git clone -q --depth 1 --branch "$version" "$url" "$dir" 2>/dev/null; then
        rm -rf "$dir/.git"
      elif ( cd "$dir" && git init -q . && git remote add origin "$url" \
             && git fetch -q --depth 1 origin "$reference" && git checkout -q FETCH_HEAD ) 2>/dev/null; then
        rm -rf "$dir/.git"
      else
        echo "[session-start] FAILED to fetch $name@$version" >&2
      fi
    }
    export -f fetch_one
    if [ -s /tmp/session-start-packages.tsv ]; then
      xargs -d'\n' -P 8 -I{} bash -c 'fetch_one "$@"' _ {} < /tmp/session-start-packages.tsv
    fi

    # Composer records what is installed here; it never ran to completion, so
    # rebuild it from the lock (this is what dump-autoload reads).
    php -r '
      $lock = json_decode(file_get_contents("composer.lock"), true);
      $packages = []; $devNames = [];
      foreach ($lock["packages"] as $p) { $p["install-path"] = "../" . $p["name"]; $packages[] = $p; }
      foreach ($lock["packages-dev"] as $p) { $p["install-path"] = "../" . $p["name"]; $packages[] = $p; $devNames[] = $p["name"]; }
      @mkdir("vendor/composer", 0755, true);
      file_put_contents("vendor/composer/installed.json", json_encode(
          ["packages" => $packages, "dev" => true, "dev-package-names" => $devNames],
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
      ));
    '
    composer dump-autoload --no-plugins >/dev/null

    # composer install creates vendor/bin; this path never got there.
    mkdir -p vendor/bin
    [ -f vendor/phpunit/phpunit/phpunit ] && ln -sf ../phpunit/phpunit/phpunit vendor/bin/phpunit && chmod +x vendor/phpunit/phpunit/phpunit
    [ -f vendor/phpstan/phpstan/phpstan ] && ln -sf ../phpstan/phpstan/phpstan vendor/bin/phpstan && chmod +x vendor/phpstan/phpstan/phpstan
  fi

  # Measured again rather than inferred from an exit code: any of the three
  # layers above can succeed for most packages and quietly leave a few
  # behind, and a partial vendor/ that nobody names is exactly the state
  # this whole section exists to stop happening twice.
  COMPOSER_MISSING="$(composer_missing_packages)"
  if [ -n "${COMPOSER_MISSING}" ]; then
    warn "$(echo "${COMPOSER_MISSING}" | wc -l | tr -d ' ') package(s) from composer.lock are still not installed: $(summarise_names "${COMPOSER_MISSING}")"
    warn "expect PHPStan class.notFound errors and Tests\\Core\\System\\TypeHintResolutionTest failures for code that uses them"
  else
    log "PHP dependencies complete (composer.lock fully installed)"
  fi

  # PHPStan caches its verdicts per file in storage/temp/phpstan, and a
  # `class.notFound` recorded while a package was absent SURVIVES that
  # package being installed — the analysed file has not changed, so nothing
  # invalidates it. Without this, the very run that repairs vendor/ is
  # followed by an analysis still insisting the class is not there, which
  # sends whoever reads it looking for a bug in the code. Cleared only on
  # the path that actually moved a package, so a warm cache is kept the
  # rest of the time.
  [ -x vendor/bin/phpstan ] && vendor/bin/phpstan clear-result-cache >/dev/null 2>&1 || true
else
  log "PHP dependencies already present (composer.lock fully installed)"
fi

# --- JS dependencies (tests/js, tests/e2e, npm run typecheck) --------------
#
# Same rule as the PHP side: what decides is whether the packages
# package.json declares are actually there, not whether node_modules/ is.
# A node_modules/ missing only `typescript` looks entirely normal and makes
# `npm run typecheck` — a mandatory gate (AGENTS.md § Static analysis) —
# report a missing binary instead of analysing anything.
if [ -f package.json ]; then
  NPM_MISSING="$(npm_missing_packages)"

  if [ ! -d node_modules ] || [ -n "${NPM_MISSING}" ]; then
    if [ -n "${NPM_MISSING}" ]; then
      log "installing JS dependencies ($(echo "${NPM_MISSING}" | wc -l | tr -d ' ') missing vs package.json: $(summarise_names "${NPM_MISSING}"))"
    else
      log "installing JS dependencies"
    fi

    npm install --no-audit --no-fund >/dev/null 2>&1 || log "npm install failed (JS tests unavailable)"

    NPM_MISSING="$(npm_missing_packages)"
    if [ -n "${NPM_MISSING}" ]; then
      warn "still missing after npm install: $(summarise_names "${NPM_MISSING}") — 'npm run typecheck'/'npm test' may not run"
    fi
  else
    log "JS dependencies already present (package.json fully installed)"
  fi
fi

# --- MySQL for `phpunit --group=database` -----------------------------
#
# Everything outside this group runs on an in-memory SQLite database and
# needs nothing here; the tests in this group exercise real MySQL/MariaDB
# behaviour (migrations, dumps, connection handling) and skip cleanly on
# a connection failure rather than fail — so without a server, a web
# session silently never runs them, same story as the PDF tests above.
#
# No systemd in this container, so mariadbd is started directly rather
# than through a service manager. Credentials match
# .github/workflows/ci.yml's database-tests job exactly
# (TEST_DB_HOST/PORT/NAME/USER/PASSWORD), so the same values work here and
# in CI.
if ! command -v mariadbd >/dev/null 2>&1; then
  log "installing mariadb-server"
  apt-get update -qq && apt-get install -y -qq mariadb-server \
    || warn "apt-get failed; --group=database will self-skip instead of running"
fi

if command -v mariadbd >/dev/null 2>&1; then
  if ! mariadb-admin ping --protocol=tcp -h 127.0.0.1 --silent 2>/dev/null; then
    log "starting MariaDB"
    mkdir -p /var/run/mysqld /var/log/mysql
    chown -R mysql:mysql /var/run/mysqld /var/log/mysql /var/lib/mysql 2>/dev/null || true
    nohup /usr/sbin/mariadbd --user=mysql --datadir=/var/lib/mysql \
      --socket=/var/run/mysqld/mysqld.sock --bind-address=127.0.0.1 --port=3306 \
      >/var/log/mysql/session-start.log 2>&1 &

    for _ in $(seq 1 30); do
      mariadb-admin ping --protocol=tcp -h 127.0.0.1 --silent 2>/dev/null && break
      sleep 1
    done
  fi

  if mariadb-admin ping --protocol=tcp -h 127.0.0.1 --silent 2>/dev/null; then
    # Two connection attempts, not one, so this stays idempotent: on a
    # first run root@localhost has no password and the bare socket login
    # works, but on every later run this script has already set one, and
    # the bare login is refused.
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
    warn "MariaDB did not come up; --group=database will self-skip instead of running"
  fi
fi

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  cat >> "$CLAUDE_ENV_FILE" <<'ENV'
export TEST_DB_HOST=127.0.0.1
export TEST_DB_PORT=3306
export TEST_DB_NAME=test_db
export TEST_DB_USER=root
export TEST_DB_PASSWORD=test_password
ENV
fi

# --- Report ----------------------------------------------------------------
log "phpunit: $(vendor/bin/phpunit --version 2>/dev/null | head -1 || echo unavailable)"
log "phpstan: $(vendor/bin/phpstan --version 2>/dev/null | head -1 || echo unavailable)"
log "run 'vendor/bin/phpunit --group=database' for the MySQL-backed suite (TEST_DB_* already exported)"
