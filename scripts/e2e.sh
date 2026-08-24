#!/bin/bash
set -euo pipefail

# ScoutMagic — end-to-end (real browser) test harness.
#
# Usage: ./scripts/e2e.sh [<extra playwright arguments>...]
#        npm run e2e        (canonical command — see README.md § Développement)
#
# One command, one complete run: this script provisions a throwaway
# ScoutMagic install, serves it through the application's REAL entry point
# (public/index.php), drives it with a headless Chromium via Playwright,
# and tears everything back down — on success, on failure, and on Ctrl-C.
# Nothing here needs a graphical session, a browser window, a developer's
# existing local install, or any manually started service.
#
# What it does, in order:
#   1. Check prerequisites (php, npm, node_modules, @playwright/test).
#   2. Resolve the MySQL server to use, starting a throwaway Docker
#      container if none is reachable and Docker is available.
#   3. Provision a throwaway instance directory + a dedicated, empty E2E
#      database, with EVERY module the repository ships activated
#      (scripts/e2e-support.php provision — see its header for why the
#      instance is a directory of its own, and e2e_activate_all_modules()
#      for why all the modules and not just the default three).
#   4. Start `php -S` on a free local port, document root = the instance's
#      public/, with sendmail_path pointed at scripts/e2e-maildrop.php so
#      every email the run sends is captured in a directory the scenarios
#      can read (tests/e2e/support/maildrop.js).
#   5. Poll (never sleep) until the server answers.
#   6. Run the Playwright project in tests/e2e/.
#   7. Exit with Playwright's own exit code.
#
# Cleanup (kill the server, drop the database, remove the instance, remove
# the Docker container if this script started it) runs from an EXIT trap,
# so it happens on every path out — including SIGINT/SIGTERM.
#
# Configuration (all optional; every value has a working default):
#   E2E_DB_HOST / E2E_DB_PORT / E2E_DB_USER / E2E_DB_PASSWORD
#       MySQL server to use. Default to TEST_DB_* (the same variables the
#       PHPUnit `database` group already uses, so a developer or CI job
#       that has one configured needs no extra setup), then to
#       127.0.0.1 / 3306 / root / empty.
#   E2E_DB_NAME
#       Default 'scoutmagic_e2e' — deliberately NOT TEST_DB_NAME and never
#       a real install's database: this script empties it at the start of
#       every run and drops it at the end.
#   E2E_PORT
#       Fixed HTTP port for the application server. Default: a free port
#       chosen at run time.
#   E2E_DOCKER_MYSQL
#       1 (default) to start a throwaway MySQL container when no MySQL is
#       reachable and `docker` works; 0 to fail instead.
#   E2E_SERVER_TIMEOUT
#       Seconds to wait for the application server to answer. Default 60.
#   E2E_COVERAGE / E2E_COVERAGE_FILE
#       Set E2E_COVERAGE=1 to record PHP line coverage of everything the
#       browser makes the application execute, and write it as Clover XML
#       to E2E_COVERAGE_FILE (default coverage-e2e.xml at the repository
#       root) for SonarQube Cloud. Off by default: it needs pcov or Xdebug
#       loaded and it slows every request down, neither of which a
#       developer running the suite for its result should pay for. CI's
#       e2e-tests job turns it on — see .github/workflows/ci.yml.
#   E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD
#   E2E_MEMBER_EMAIL / E2E_MEMBER_PASSWORD
#       Credentials of the throwaway instance's two accounts — a
#       super-admin and an ordinary member — which scenarios needing an
#       authenticated session log in with through the real login form. Two
#       of them because several of this application's behaviours only
#       exist between two people (a comment being new to somebody,
#       reporting, moderating a report) and are unreachable with one. Both
#       addresses default to @example.invalid (.invalid is reserved by RFC
#       6761 — never a real mailbox); both passwords are generated fresh
#       for every run, so nothing password-shaped is ever committed and no
#       two runs share one.
#   E2E_INTENDANT_EMAIL / E2E_INTENDANT_PASSWORD
#   E2E_CHIEF_EMAIL / E2E_CHIEF_PASSWORD
#   E2E_UNIT_ADMIN_EMAIL / E2E_UNIT_ADMIN_PASSWORD
#       The three remaining rungs of Core\Security\Role, provisioned by
#       the same rules as the two above (an .invalid address, a password
#       generated per run). No Playwright scenario reads them — they exist
#       so the dynamic security scan (scripts/dast.sh) can replay the site
#       map as every role rather than as two of them. The `admin` one is
#       E2E_UNIT_ADMIN_* and not E2E_ADMIN_*, which has named the
#       SUPER-admin here since long before roles were provisioned; `admin`
#       is the role displayed as "Chef d'Unité".
#
# Exported for the scenarios (not configuration — set by this script):
#   E2E_MAILDROP
#       Directory every email the run sends lands in, one RFC 5322 file
#       per message. Read through tests/e2e/support/maildrop.js. Inside
#       the run's own temporary directory, removed with it.
#   E2E_INSTANCE_DIR
#       The throwaway instance's own directory. Used through
#       tests/e2e/support/scheduler.js to run its public/cron.php once,
#       for scenarios whose feature finishes in a background task.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

# storage/temp/ is gitignored runtime state (PHPStan cache, Twig cache,
# session files, MigrationRunner's own backups — see .gitignore) and does
# not exist on a fresh checkout, CI included. Core\Database\MigrationRunner
# creates it lazily on first use, but this script's `find` calls below (the
# backup-snapshot bookkeeping) need it to exist unconditionally: `find` on a
# missing directory exits non-zero, and piped into `sort` under `set -o
# pipefail` that silently kills the whole script right here, before a
# single diagnostic line is printed. Created once, up front, so a fresh
# checkout and a developer's already-used one behave identically.
mkdir -p "${REPO_ROOT}/storage/temp"

E2E_DB_HOST="${E2E_DB_HOST:-${TEST_DB_HOST:-127.0.0.1}}"
E2E_DB_PORT="${E2E_DB_PORT:-${TEST_DB_PORT:-3306}}"
E2E_DB_NAME="${E2E_DB_NAME:-scoutmagic_e2e}"
E2E_DB_USER="${E2E_DB_USER:-${TEST_DB_USER:-root}}"
E2E_DB_PASSWORD="${E2E_DB_PASSWORD:-${TEST_DB_PASSWORD:-}}"
E2E_DOCKER_MYSQL="${E2E_DOCKER_MYSQL:-1}"
E2E_SERVER_TIMEOUT="${E2E_SERVER_TIMEOUT:-60}"
E2E_COVERAGE="${E2E_COVERAGE:-0}"
E2E_COVERAGE_FILE="${E2E_COVERAGE_FILE:-${REPO_ROOT}/coverage-e2e.xml}"
E2E_ADMIN_EMAIL="${E2E_ADMIN_EMAIL:-admin@example.invalid}"
# Generated per run rather than hardcoded — see this script's header. PHP
# rather than openssl/urandom so the one interpreter this harness already
# requires is the only thing it depends on, on macOS and Linux alike.
E2E_ADMIN_PASSWORD="${E2E_ADMIN_PASSWORD:-$(php -r 'echo "E2e-" . bin2hex(random_bytes(16));')}"
# The second, ordinary member — same rules as the admin above: an
# .invalid address that can never be a real mailbox, and a password
# generated fresh for every run.
E2E_MEMBER_EMAIL="${E2E_MEMBER_EMAIL:-kaa@example.invalid}"
E2E_MEMBER_PASSWORD="${E2E_MEMBER_PASSWORD:-$(php -r 'echo "E2e-" . bin2hex(random_bytes(16));')}"
# The three role-bearing accounts — same rules again. They are always
# provisioned, not only for a scan: a fixture that exists on one code path
# and not another is a fixture nobody can reason about, and the cost is
# three rows in a database that is dropped at teardown.
E2E_INTENDANT_EMAIL="${E2E_INTENDANT_EMAIL:-chil@example.invalid}"
E2E_INTENDANT_PASSWORD="${E2E_INTENDANT_PASSWORD:-$(php -r 'echo "E2e-" . bin2hex(random_bytes(16));')}"
E2E_CHIEF_EMAIL="${E2E_CHIEF_EMAIL:-bagheera@example.invalid}"
E2E_CHIEF_PASSWORD="${E2E_CHIEF_PASSWORD:-$(php -r 'echo "E2e-" . bin2hex(random_bytes(16));')}"
E2E_UNIT_ADMIN_EMAIL="${E2E_UNIT_ADMIN_EMAIL:-akela@example.invalid}"
E2E_UNIT_ADMIN_PASSWORD="${E2E_UNIT_ADMIN_PASSWORD:-$(php -r 'echo "E2e-" . bin2hex(random_bytes(16));')}"
export E2E_DB_HOST E2E_DB_PORT E2E_DB_NAME E2E_DB_USER E2E_DB_PASSWORD
export E2E_ADMIN_EMAIL E2E_ADMIN_PASSWORD
export E2E_MEMBER_EMAIL E2E_MEMBER_PASSWORD
export E2E_INTENDANT_EMAIL E2E_INTENDANT_PASSWORD
export E2E_CHIEF_EMAIL E2E_CHIEF_PASSWORD
export E2E_UNIT_ADMIN_EMAIL E2E_UNIT_ADMIN_PASSWORD

SUPPORT="${REPO_ROOT}/scripts/e2e-support.php"

# State the cleanup trap acts on. Each stays empty until the corresponding
# resource actually exists, so cleanup is safe at any point.
SERVER_PID=""
PLAYWRIGHT_PID=""
INSTANCE_DIR=""
DOCKER_CONTAINER=""
DATABASE_PROVISIONED=0
BACKUP_SNAPSHOT=""
COVERAGE_DIR=""

# Stop the application server and wait for it to actually be gone.
# Idempotent, and safe to call before cleanup() does: the coverage merge
# needs the server quiesced so that every request's shutdown handler has
# certainly finished writing its fragment.
stop_server() {
    [[ -n "${SERVER_PID}" ]] && kill -0 "${SERVER_PID}" 2>/dev/null || return 0

    echo "E2E: stopping the application server (pid ${SERVER_PID})."
    kill "${SERVER_PID}" 2>/dev/null || true
    # Give it a moment to exit on SIGTERM, then insist. Polling rather
    # than a blanket sleep: `wait` cannot be used here because cleanup()
    # may run from a different shell context than the one that forked.
    local waited=0
    while kill -0 "${SERVER_PID}" 2>/dev/null && [[ "${waited}" -lt 50 ]]; do
        waited=$((waited + 1))
        sleep 0.1
    done
    # `|| true` on both kills, not decoration: the server has usually
    # exited on the SIGTERM above by now, and `kill` on a dead pid returns
    # non-zero — which under `set -e` would abort the whole script at the
    # coverage call site (cleanup() gets away without it only because it
    # runs `set +e` first).
    kill -9 "${SERVER_PID}" 2>/dev/null || true
    SERVER_PID=""

    return 0
}

cleanup() {
    # Never let a cleanup step's failure replace the test's own exit code,
    # and never let one failing step skip the others.
    local exit_code=$?
    set +e

    # Playwright first: it is the only child that can still be driving the
    # server, and killing the server out from under it would turn a
    # cancelled run into a wall of connection errors.
    if [[ -n "${PLAYWRIGHT_PID}" ]] && kill -0 "${PLAYWRIGHT_PID}" 2>/dev/null; then
        kill "${PLAYWRIGHT_PID}" 2>/dev/null
        local browser_waited=0
        while kill -0 "${PLAYWRIGHT_PID}" 2>/dev/null && [[ "${browser_waited}" -lt 100 ]]; do
            browser_waited=$((browser_waited + 1))
            sleep 0.1
        done
        kill -9 "${PLAYWRIGHT_PID}" 2>/dev/null
    fi

    stop_server

    if [[ "${DATABASE_PROVISIONED}" -eq 1 ]]; then
        php "${SUPPORT}" teardown-db
    fi

    if [[ -n "${DOCKER_CONTAINER}" ]]; then
        echo "E2E: removing the throwaway MySQL container."
        docker rm -f "${DOCKER_CONTAINER}" > /dev/null 2>&1
    fi

    # Core\Database\MigrationRunner dumps the database it is about to
    # migrate into the REPOSITORY's storage/temp — it anchors that
    # directory to its own file location, and the throwaway instance loads
    # core/ from the repository, so no amount of instance isolation can
    # redirect it. A run produces several of these (the provisioning
    # migration, then one per module applying its own schema.sql on the
    # first request), all of them dumps of a database that no longer
    # exists by the time this runs. Only the files that appeared during
    # this run are removed, matched against a snapshot taken before it
    # started — a real backup a developer already had sitting there is
    # never touched.
    if [[ -n "${BACKUP_SNAPSHOT}" && -f "${BACKUP_SNAPSHOT}" ]]; then
        find "${REPO_ROOT}/storage/temp" -maxdepth 1 -type f -name 'backup_*.sql' 2>/dev/null | sort \
            | comm -13 "${BACKUP_SNAPSHOT}" - \
            | while IFS= read -r stray_backup; do
                  [[ -n "${stray_backup}" ]] && rm -f "${stray_backup}"
              done
    fi

    if [[ -n "${INSTANCE_DIR}" && -d "${INSTANCE_DIR}" ]]; then
        rm -rf "${INSTANCE_DIR}"
    fi

    exit "${exit_code}"
}
trap cleanup EXIT
# Without these, Ctrl-C kills this script without running the EXIT trap in
# some shells; re-raising the signal after the trap keeps the exit status
# honest for whatever invoked this script.
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------
# 1. Prerequisites. Fail closed with the exact command to run —
# never install anything on the caller's behalf, same philosophy as
# scripts/release.sh's tests gate.
# ---------------------------------------------------------------
command -v php > /dev/null 2>&1 || { echo "ERROR: php is required to run the E2E tests." >&2; exit 1; }
command -v npm > /dev/null 2>&1 || { echo "ERROR: npm is required to run the E2E tests (Node.js >= 22 — see README.md § Prérequis)." >&2; exit 1; }
[[ -f "${REPO_ROOT}/vendor/autoload.php" ]] || { echo "ERROR: vendor/autoload.php not found — run 'composer install' first." >&2; exit 1; }
[[ -d "${REPO_ROOT}/node_modules/@playwright/test" ]] || {
    echo "ERROR: @playwright/test is not installed — run 'npm ci' then 'npm run e2e:install' (see README.md § Développement)." >&2
    exit 1
}

# ---------------------------------------------------------------
# 2. MySQL. Reuse whatever is already reachable; otherwise start a
# throwaway container and remember to remove it in cleanup().
# ---------------------------------------------------------------
mysql_is_reachable() {
    php -r '
        $host = getenv("E2E_DB_HOST");
        $port = (int) getenv("E2E_DB_PORT");
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($socket === false) { exit(1); }
        fclose($socket);
        try {
            new PDO("mysql:host={$host};port={$port}", getenv("E2E_DB_USER"), getenv("E2E_DB_PASSWORD"));
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        exit(0);
    '
}

start_docker_mysql() {
    DOCKER_CONTAINER="scoutmagic-e2e-mysql-$$"
    E2E_DB_HOST="127.0.0.1"
    E2E_DB_PORT="$(php "${SUPPORT}" free-port)"
    E2E_DB_USER="root"
    E2E_DB_PASSWORD="e2e_password"
    export E2E_DB_HOST E2E_DB_PORT E2E_DB_USER E2E_DB_PASSWORD

    echo "E2E: no MySQL reachable — starting a throwaway container on 127.0.0.1:${E2E_DB_PORT} (mysql:8.0)."
    docker run --detach --rm \
        --name "${DOCKER_CONTAINER}" \
        --env "MYSQL_ROOT_PASSWORD=${E2E_DB_PASSWORD}" \
        --publish "127.0.0.1:${E2E_DB_PORT}:3306" \
        mysql:8.0 > /dev/null || {
            echo "ERROR: could not start the throwaway MySQL container." >&2
            exit 1
        }

    echo "E2E: waiting for the MySQL container to accept connections..."
    local waited=0
    until mysql_is_reachable > /dev/null 2>&1; do
        waited=$((waited + 1))
        if [[ "${waited}" -gt 600 ]]; then
            echo "ERROR: the throwaway MySQL container did not become reachable within 60 s." >&2
            docker logs "${DOCKER_CONTAINER}" 2>&1 | tail -20 >&2
            exit 1
        fi
        sleep 0.1
    done
}

if mysql_is_reachable > /dev/null 2>&1; then
    echo "E2E: using the MySQL server at ${E2E_DB_HOST}:${E2E_DB_PORT} (database '${E2E_DB_NAME}')."
elif [[ "${E2E_DOCKER_MYSQL}" != "0" ]] && command -v docker > /dev/null 2>&1 && docker info > /dev/null 2>&1; then
    start_docker_mysql
else
    echo "ERROR: no MySQL server reachable at ${E2E_DB_HOST}:${E2E_DB_PORT} as user '${E2E_DB_USER}'." >&2
    echo "       Start one and re-run, point E2E_DB_HOST/E2E_DB_PORT/E2E_DB_USER/E2E_DB_PASSWORD at it," >&2
    echo "       or make Docker available so this script can start a throwaway one itself." >&2
    exit 1
fi

# ---------------------------------------------------------------
# 3-5. Provision, serve, wait. Retried as a unit on a lost port race:
# the port is free when it is picked and can be taken by anything else
# before `php -S` binds it, which no portable shell can prevent.
# ---------------------------------------------------------------
INSTANCE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/scoutmagic-e2e.XXXXXX")"
SERVER_LOG="${INSTANCE_DIR}/php-server.log"

# Taken before anything migrates — see cleanup()'s own comment.
BACKUP_SNAPSHOT="${INSTANCE_DIR}/backups-before-run.txt"

# ---------------------------------------------------------------
# The run's mailbox. The instance is provisioned in local mail mode
# (scripts/e2e-support.php), so PHP's own mail() is what delivers, and
# sendmail_path below points it at scripts/e2e-maildrop.php, which writes
# each message here whole. That is what lets a scenario follow a magic
# link, a password reset or a form's confirmation email — flows that
# simply have no browser-only half. Inside the run's temporary directory,
# so cleanup() takes it away with everything else and no message outlives
# the run that produced it.
# ---------------------------------------------------------------
E2E_MAILDROP="${INSTANCE_DIR}/maildrop"
mkdir -p "${E2E_MAILDROP}"
export E2E_MAILDROP

# The instance itself, for the one thing a scenario cannot do through the
# browser: turn the task queue. Background work (a gallery upload's
# renditions, a notification's push) finishes in public/cron.php, and the
# application's own poor-man's cron runs at most once a minute — a minute
# a test cannot spend. tests/e2e/support/scheduler.js runs the instance's
# cron entry point through scripts/e2e-support.php run-scheduler.
# The application instance itself lives one level down (see the
# `provision` call below) — this names THAT directory, the one
# holding public/cron.php.
export E2E_INSTANCE_DIR="${INSTANCE_DIR}/instance"

# ---------------------------------------------------------------
# PHP coverage of the application, when asked for. The fragments live
# inside the run's own temporary directory, so cleanup() removes them with
# everything else and only the merged Clover report survives the run.
# Fails closed rather than producing a silently empty report: a coverage
# number nobody can trust is worse than no number.
# ---------------------------------------------------------------
if [[ "${E2E_COVERAGE}" != "0" ]]; then
    php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);' || {
        echo "ERROR: E2E_COVERAGE is on but neither pcov nor Xdebug is loaded — PHP coverage cannot be recorded." >&2
        exit 1
    }
    COVERAGE_DIR="${INSTANCE_DIR}/coverage"
    mkdir -p "${COVERAGE_DIR}"
    export E2E_COVERAGE_DIR="${COVERAGE_DIR}"
    echo "E2E: recording PHP coverage into ${E2E_COVERAGE_FILE}."
fi
find "${REPO_ROOT}/storage/temp" -maxdepth 1 -type f -name 'backup_*.sql' 2>/dev/null | sort > "${BACKUP_SNAPSHOT}"

attempt=0
while true; do
    attempt=$((attempt + 1))

    PORT="${E2E_PORT:-$(php "${SUPPORT}" free-port)}"
    # `localhost`, not the 127.0.0.1 the server binds just below: WebAuthn
    # Relying Party IDs are domain names, and Chrome rejects an IP-literal
    # one outright, so an instance calling itself http://127.0.0.1:<port>
    # cannot register or use a passkey at all. Same loopback address, same
    # secure context, same not-a-public-host — see e2e_base_url() in
    # scripts/e2e-support.php for the full reasoning and for what it
    # deliberately does not change.
    BASE_URL="http://localhost:${PORT}"

    php "${SUPPORT}" provision "${INSTANCE_DIR}/instance" "${PORT}"
    DATABASE_PROVISIONED=1

    echo "E2E: starting the application server on ${BASE_URL} (document root: the instance's public/)."
    # display_errors off / log_errors on: the instance must behave like a
    # production install (an uncaught error is a clean HTTP 500, not a
    # stack trace rendered into the page the browser then "successfully"
    # asserts against), while still leaving the real error somewhere the
    # failure path below can print.
    #
    # When coverage is on, auto_prepend_file runs the collector before the
    # application boots on every request — the only seam that exists, since
    # the code under test runs in this server process and not in the test's.
    # One array built up front, never spliced from a possibly-empty one:
    # "${array[@]}" on a declared-but-empty array is treated as an unset
    # variable under `set -u` on bash < 4.4 (macOS's own /bin/bash is 3.2)
    # even though it expands to nothing correctly everywhere else — always
    # having the base -d options in here keeps this array non-empty
    # regardless of whether coverage is on, sidestepping that entirely.
    #
    # sendmail_path is the run's mailbox (see E2E_MAILDROP above): the
    # instance is in local mail mode, so Core\Mail\MailService goes
    # through PHP's mail(), which runs this and gets the complete message
    # on stdin. Only the transport's last hop is replaced — MailService,
    # PHPMailer, DKIM and the templates all run for real.
    php_options=(
        -d display_errors=0
        -d log_errors=1
        -d error_log="${INSTANCE_DIR}/php-error.log"
        -d upload_max_filesize=100M
        -d post_max_size=110M
        # The inner quotes are not decoration: sendmail_path is run through
        # a shell, so a repository path containing a space would otherwise
        # arrive as two arguments.
        -d sendmail_path="php '${REPO_ROOT}/scripts/e2e-maildrop.php'"
    )
    if [[ -n "${COVERAGE_DIR}" ]]; then
        # pcov.directory has to span both trees the run executes PHP from:
        # the repository (core/, modules/, reached through the instance's
        # symlinks, so recorded at their repository paths) and the
        # instance's own copied public/. Their only common ancestor is /,
        # so vendor/ and node_modules/ are excluded by pattern instead —
        # otherwise every request would pay to instrument the SDKs too.
        php_options+=(
            -d "auto_prepend_file=${REPO_ROOT}/scripts/e2e-coverage-prepend.php"
            -d 'pcov.enabled=1'
            -d 'pcov.directory=/'
            -d 'pcov.exclude=~/(vendor|node_modules)/~'
        )
    fi

    php \
        "${php_options[@]}" \
        -S "127.0.0.1:${PORT}" \
        -t "${INSTANCE_DIR}/instance/public" \
        > "${SERVER_LOG}" 2>&1 &
    SERVER_PID=$!

    if php "${SUPPORT}" wait-http "${BASE_URL}/api/version" "${E2E_SERVER_TIMEOUT}"; then
        break
    fi

    kill "${SERVER_PID}" 2>/dev/null || true
    SERVER_PID=""

    if [[ -n "${E2E_PORT:-}" ]] || [[ "${attempt}" -ge 3 ]]; then
        echo "ERROR: the application server did not answer on ${BASE_URL} within ${E2E_SERVER_TIMEOUT} s." >&2
        echo "--- php -S output ---" >&2
        tail -40 "${SERVER_LOG}" >&2 || true
        echo "--- PHP error log ---" >&2
        tail -40 "${INSTANCE_DIR}/php-error.log" >&2 || true
        exit 1
    fi

    echo "E2E: port ${PORT} did not come up — retrying with another port." >&2
done

echo "E2E: server ready. Running the browser tests..."

# ---------------------------------------------------------------
# 6-7. Run Playwright and propagate its exit code verbatim. `set -e` is
# lifted around this one call so the diagnostics below still print on a
# failure before the script exits with Playwright's status.
# ---------------------------------------------------------------
# Backgrounded and `wait`ed rather than run in the foreground: bash defers
# a trap until a foreground child finishes, so a SIGINT that reaches only
# this shell (and not the whole process group, as a terminal's Ctrl-C
# would) would otherwise leave the run going for minutes before cleanup
# happened. Interrupting a `wait` runs the trap immediately.
set +e
E2E_BASE_URL="${BASE_URL}" npm exec --no -- playwright test --config="${REPO_ROOT}/tests/e2e/playwright.config.js" "$@" &
PLAYWRIGHT_PID=$!
wait "${PLAYWRIGHT_PID}"
PLAYWRIGHT_EXIT=$?
PLAYWRIGHT_PID=""
set -e

# ---------------------------------------------------------------
# Coverage, before the diagnostics and before cleanup wipes the run's
# temporary directory. The server is stopped first so that every request's
# shutdown handler has certainly flushed its fragment.
#
# A merge failure is reported but never changes the run's verdict: the
# browser tests have already decided it, and a reporting problem must not
# turn a green suite red (nor, just as importantly, hide a red one).
# ---------------------------------------------------------------
if [[ -n "${COVERAGE_DIR}" ]]; then
    stop_server
    php "${SUPPORT}" merge-coverage "${COVERAGE_DIR}" "${E2E_COVERAGE_FILE}" \
        || echo "WARNING: the E2E coverage report could not be produced (the test result above is unaffected)." >&2
fi

if [[ "${PLAYWRIGHT_EXIT}" -ne 0 ]]; then
    echo "--- php -S output (last 40 lines) ---" >&2
    tail -40 "${SERVER_LOG}" >&2 || true
    if [[ -s "${INSTANCE_DIR}/php-error.log" ]]; then
        echo "--- PHP error log (last 40 lines) ---" >&2
        tail -40 "${INSTANCE_DIR}/php-error.log" >&2 || true
    fi
    echo "E2E FAILED (Playwright exit code ${PLAYWRIGHT_EXIT}). Report: tests/e2e/playwright-report/" >&2
else
    echo "E2E OK."
fi

exit "${PLAYWRIGHT_EXIT}"
