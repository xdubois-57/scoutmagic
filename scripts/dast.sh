#!/bin/bash
set -euo pipefail

# ScoutMagic — dynamic application security testing (DAST) harness.
#
# Usage: ./scripts/dast.sh [--profile=passive] [<extra playwright arguments>...]
#
# One command, one complete run, same spirit and structure as
# scripts/e2e.sh: it provisions a throwaway ScoutMagic install, serves it
# over self-signed HTTPS through the application's REAL entry point
# (public/index.php), drives it with the existing Playwright suite through
# an OWASP ZAP proxy, produces a report, and tears everything back down —
# on success, on failure, and on Ctrl-C.
#
# WHY THE BROWSER SUITE IS THE ATTACK SURFACE
# ---------------------------------------------------------------------
# Not ZAP's spider, and not its AJAX spider: the Playwright suite already
# traverses flows no crawler can reach — a magic link and an email
# confirmation both leave the browser entirely and come back through the
# run's maildrop — and it does so as several different signed-in
# identities. Replaying it through a proxy is therefore the most faithful
# picture of this application's real surface that exists.
#
# WHAT IT DOES, IN ORDER
#   1. Check prerequisites (php, npm, node_modules, Docker, the ZAP
#      image, the pcntl/openssl PHP extensions). Fail closed with the
#      exact command to run — never install anything.
#   2. Resolve the MySQL server to use, starting a throwaway Docker
#      container if none is reachable.
#   3. Generate a self-signed certificate for this run only.
#   4. Provision a throwaway instance + a dedicated, empty database
#      (scripts/e2e-support.php provision — the SAME provisioning the
#      browser suite uses, never a second copy of it).
#   5. Start `php -S` on a private loopback port, and
#      scripts/dast-tls-proxy.php in front of it to terminate TLS.
#   6. Start ZAP in daemon mode and run the profile's Automation
#      Framework plan, which configures the passive scanner and its alert
#      filters and then blocks on a `delay` job.
#   7. Run Playwright through ZAP.
#   8. Release the plan's delay job; ZAP finishes passive scanning and
#      writes the HTML and SARIF reports.
#   9. Assert the site map is non-empty and holds authenticated pages,
#      then gate on the findings.
#
# Cleanup (Playwright, ZAP, the TLS terminator, the server, the database,
# the instance, the containers) runs from an EXIT trap, so it happens on
# every path out, including SIGINT/SIGTERM.
#
# WHY IT CANNOT COLLIDE WITH `npm run e2e`
# ---------------------------------------------------------------------
# scripts/release.sh runs its gates in parallel subshells, and
# scripts/e2e.sh DROPS its database on teardown. A scan sharing that
# database would have it deleted out from under it mid-run, so this
# script uses a database name of its own (scoutmagic_dast) and picks its
# own ports. Nothing here touches anything scripts/e2e.sh owns.
#
# PROFILES
#   passive   Passive rules only, observing the Playwright traffic.
#             Runs in CI on every push.
#   deep      passive + an enumerated subset of ACTIVE rules (injection,
#             cross-site scripting, path traversal). Attacks the site.
#   audit     passive + every active rule ZAP ships, no time budget.
#             Manual, on demand, never a gate.
#   standard  The authorization matrix: every route replayed as every
#             role, checked against the role_min it declares. No ZAP and
#             no browser — see scripts/authz-support.php for why the
#             question is arithmetic rather than a scan, and for how a
#             POST is replayed without writing anything.
#
# An active profile REPLAYS every recorded request hundreds of times with
# attack payloads, carrying the session cookies the browser was using —
# so it acts as a signed-in super-admin. What stops it from truncating
# the database halfway through its own scan is the exclusion list in
# tests/dast/zap-active.yaml, which is the most important part of that
# file. Read it before adding a route that resets, restores, reconfigures
# or sends.
#
# THE FLAKE THAT WAS BLOCKING THIS, AND WHAT IS LEFT OF IT
# ---------------------------------------------------------------------
# tests/e2e/specs/gallery-media.spec.js used to fail roughly one run in
# three under this harness. This block used to blame "the synthetic
# HTML5 drag not always registering". That was wrong, and the real
# cause is worth writing down because it is a shape that recurs.
#
# Nothing was flaky about the drag. Three facts, each harmless alone:
# Gallery\Service\MediaService makes the FIRST upload the album cover;
# album_form.html.twig renders « Définir comme couverture » only on the
# tiles that are NOT the cover; and sortable.js decides before-or-after
# with a STRICT `(clientX - rect.left) > rect.width / 2`, whose boundary
# is exactly the centre — which is where Playwright's dragTo() aims by
# default. The drop landed on that boundary, so sub-pixel rounding chose
# which side the tile went, and the NEXT step assumed the leading tile
# was not the cover. Nothing asserted that. When it was, a click waited
# out its 40 s on a button the page was right not to render, twelve
# lines below the step that actually decided the outcome.
#
# The spec now drops at 90% of the target's width, asserts the exact
# resulting order rather than "something changed", and picks the tile to
# act on by the presence of its button. 18 consecutive runs green.
#
# Configuration (all optional; every value has a working default):
#   DAST_DB_HOST / DAST_DB_PORT / DAST_DB_USER / DAST_DB_PASSWORD
#       MySQL server to use. Default to TEST_DB_* like scripts/e2e.sh,
#       then to 127.0.0.1 / 3306 / root / empty.
#   DAST_DB_NAME
#       Default 'scoutmagic_dast' — deliberately NOT scripts/e2e.sh's
#       database (see above), and never a real install's: this script
#       empties it at the start of every run and drops it at the end.
#   DAST_PORT / DAST_BACKEND_PORT / DAST_ZAP_PORT
#       Fixed ports for, respectively, the HTTPS front door, the `php -S`
#       backend behind the TLS terminator, and ZAP's proxy/API listener.
#       Default: free ports chosen at run time.
#   DAST_ZAP_IMAGE
#       Default 'ghcr.io/zaproxy/zaproxy:stable'.
#   DAST_REPORT_DIR
#       Where the HTML and SARIF reports are written. Default
#       'dast-report/' at the repository root (gitignored).
#   DAST_THRESHOLD
#       Lowest risk level that fails the run. Default 'Medium' — see
#       SECURITY.md. There is deliberately no baseline file.
#   DAST_SERVER_TIMEOUT / DAST_ZAP_TIMEOUT / DAST_PLAN_TIMEOUT
#       Seconds to wait for the application server (60), for ZAP to
#       answer its API (180), and for the automation plan to finish
#       (3600).
#   DAST_TIMEOUT_FACTOR
#       Multiplies every Playwright timeout and the maildrop's own.
#       Default 4: the same scenarios do the same work, but each request
#       crosses ZAP and a TLS handshake, so the wall clock is several
#       times a plain `npm run e2e`. Scaling the ceilings is what stops
#       the harness's latency from being reported as application
#       failures. `npm run e2e` never sets it, and is unaffected.
#   DAST_WORKERS
#       PHP_CLI_SERVER_WORKERS for the backend. Default 1, and that
#       default was measured rather than chosen: at 4 workers,
#       tests/e2e/specs/gallery-media.spec.js fails reproducibly (the
#       drag-reorder POST never answers, or the reloaded grid comes back
#       without its controls) while passing at 1 — with and without ZAP
#       in the path, so it is the forking server and not the proxy. PHP's
#       built-in server workers are experimental, and this is what that
#       means in practice. There was little to lose: session files are
#       locked per session, so concurrent requests on one authenticated
#       session serialise regardless of worker count, and the browser
#       suite drives one session at a time. Raise it if a future PHP
#       makes it dependable, and re-run the gallery scenario when you do.
#   E2E_CHROMIUM_EXECUTABLE
#       Honoured exactly as scripts/e2e.sh honours it (see
#       tests/e2e/playwright.config.js).

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

PROFILE="passive"
PLAYWRIGHT_ARGS=()
for argument in "$@"; do
    case "${argument}" in
        --profile=*) PROFILE="${argument#--profile=}" ;;
        *) PLAYWRIGHT_ARGS+=("${argument}") ;;
    esac
done

# `deep` and `audit` share one plan and one context — they differ only in
# which scan policy the activeScan job picks. Keeping the destructive
# exclusions in a single file is the point: two copies of that list is
# two chances for one of them to miss the route that truncates the
# database.
DAST_ACTIVE_POLICY=""
case "${PROFILE}" in
    passive)
        PLAN_FILE="${REPO_ROOT}/tests/dast/zap-passive.yaml"
        ;;
    deep)
        PLAN_FILE="${REPO_ROOT}/tests/dast/zap-active.yaml"
        DAST_ACTIVE_POLICY="scoutmagic-deep"
        ;;
    audit)
        PLAN_FILE="${REPO_ROOT}/tests/dast/zap-active.yaml"
        DAST_ACTIVE_POLICY="scoutmagic-audit"
        ;;
    standard)
        # The authorization matrix. No ZAP, no browser: the question it
        # asks — does every route answer exactly the roles its role_min
        # admits — has one right answer per pair, so it is replayed and
        # compared rather than scanned for. See scripts/authz-support.php.
        PLAN_FILE=""
        ;;
    *)
        echo "ERROR: unknown profile '${PROFILE}' (expected: passive, standard, deep or audit)." >&2
        exit 1
        ;;
esac
SITEMAP_EXPECTATIONS="${REPO_ROOT}/tests/dast/expected-authenticated-paths.txt"

# See scripts/e2e.sh's own comment: `find` on a missing directory exits
# non-zero, which under `set -o pipefail` kills the script silently.
mkdir -p "${REPO_ROOT}/storage/temp"

DAST_DB_HOST="${DAST_DB_HOST:-${TEST_DB_HOST:-127.0.0.1}}"
DAST_DB_PORT="${DAST_DB_PORT:-${TEST_DB_PORT:-3306}}"
DAST_DB_NAME="${DAST_DB_NAME:-scoutmagic_dast}"
DAST_DB_USER="${DAST_DB_USER:-${TEST_DB_USER:-root}}"
DAST_DB_PASSWORD="${DAST_DB_PASSWORD:-${TEST_DB_PASSWORD:-}}"
DAST_ZAP_IMAGE="${DAST_ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:stable}"
DAST_REPORT_DIR="${DAST_REPORT_DIR:-${REPO_ROOT}/dast-report}"
DAST_THRESHOLD="${DAST_THRESHOLD:-Medium}"
DAST_SERVER_TIMEOUT="${DAST_SERVER_TIMEOUT:-60}"
DAST_ZAP_TIMEOUT="${DAST_ZAP_TIMEOUT:-180}"
DAST_PLAN_TIMEOUT="${DAST_PLAN_TIMEOUT:-28800}"
# One worker for the passive profile, which only has to serve the
# browser suite; more for the active ones, which are throughput-bound.
# Measured: the first full `audit` run managed about 5 requests a second
# against a single worker — roughly 12 hours for the whole rule set,
# because `php -S` serves exactly one request per worker at a time and
# each costs ~200 ms here. The cost of raising it is the known
# gallery-media drag flake (see above); an active profile is not a gate,
# and scripts/dast.sh scans the traffic it did get either way.
if [[ "${PROFILE}" == "passive" ]]; then
    DAST_WORKERS="${DAST_WORKERS:-1}"
else
    DAST_WORKERS="${DAST_WORKERS:-4}"
fi
DAST_TIMEOUT_FACTOR="${DAST_TIMEOUT_FACTOR:-4}"
# The active scanner's own ceiling, in minutes, and separately the whole
# plan's. 0 means unlimited for the scanner; the roadmap gives `audit` no
# time budget on purpose. Both are raised for the active profiles because
# the browser phase alone takes about twelve minutes before the scan even
# starts.
DAST_MAX_SCAN_MINS="${DAST_MAX_SCAN_MINS:-0}"

SUPPORT="${REPO_ROOT}/scripts/e2e-support.php"
DAST_SUPPORT="${REPO_ROOT}/scripts/dast-support.php"

# scripts/e2e-support.php reads its database configuration from the E2E_*
# variables. It is the single provisioning implementation on purpose
# (there is no second copy of it here), so this script speaks its
# language rather than inventing a parallel one.
export E2E_DB_HOST="${DAST_DB_HOST}"
export E2E_DB_PORT="${DAST_DB_PORT}"
export E2E_DB_NAME="${DAST_DB_NAME}"
export E2E_DB_USER="${DAST_DB_USER}"
export E2E_DB_PASSWORD="${DAST_DB_PASSWORD}"

# The instance answers on https://, and believes X-Forwarded-Proto —
# which the TLS terminator sets and which Core\Http\RequestScheme honours
# only behind this opt-in (SECURITY.md § 9). Without both, the scan would
# see a site emitting neither Secure cookies nor HSTS.
export E2E_BASE_SCHEME="https"
export E2E_TRUST_FORWARDED_PROTO="1"

# Same rules as scripts/e2e.sh: .invalid addresses that can never be a
# real mailbox, and passwords generated fresh for every run.
generate_password() { php -r 'echo "Dast-" . bin2hex(random_bytes(16));'; }
export E2E_ADMIN_EMAIL="${E2E_ADMIN_EMAIL:-admin@example.invalid}"
export E2E_ADMIN_PASSWORD="${E2E_ADMIN_PASSWORD:-$(generate_password)}"
export E2E_MEMBER_EMAIL="${E2E_MEMBER_EMAIL:-kaa@example.invalid}"
export E2E_MEMBER_PASSWORD="${E2E_MEMBER_PASSWORD:-$(generate_password)}"
export E2E_INTENDANT_EMAIL="${E2E_INTENDANT_EMAIL:-chil@example.invalid}"
export E2E_INTENDANT_PASSWORD="${E2E_INTENDANT_PASSWORD:-$(generate_password)}"
export E2E_CHIEF_EMAIL="${E2E_CHIEF_EMAIL:-bagheera@example.invalid}"
export E2E_CHIEF_PASSWORD="${E2E_CHIEF_PASSWORD:-$(generate_password)}"
export E2E_UNIT_ADMIN_EMAIL="${E2E_UNIT_ADMIN_EMAIL:-akela@example.invalid}"
export E2E_UNIT_ADMIN_PASSWORD="${E2E_UNIT_ADMIN_PASSWORD:-$(generate_password)}"

# State the cleanup trap acts on. Each stays empty until the matching
# resource actually exists, so cleanup is safe at any point.
SERVER_PID=""
TLS_PID=""
PLAYWRIGHT_PID=""
INSTANCE_DIR=""
MYSQL_CONTAINER=""
ZAP_CONTAINER=""
DATABASE_PROVISIONED=0
BACKUP_SNAPSHOT=""

stop_pid() {
    local pid="$1" name="$2"
    [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null || return 0

    echo "DAST: stopping ${name} (pid ${pid})."
    kill "${pid}" 2>/dev/null || true
    local waited=0
    while kill -0 "${pid}" 2>/dev/null && [[ "${waited}" -lt 50 ]]; do
        waited=$((waited + 1))
        sleep 0.1
    done
    kill -9 "${pid}" 2>/dev/null || true

    return 0
}

cleanup() {
    # Never let a cleanup step's failure replace the run's own exit code,
    # and never let one failing step skip the others.
    local exit_code=$?
    set +e

    # Playwright first: it is the only child still driving the stack, and
    # killing the server under it turns a cancelled run into a wall of
    # connection errors.
    stop_pid "${PLAYWRIGHT_PID}" "Playwright"
    stop_pid "${TLS_PID}" "the TLS terminator"
    stop_pid "${SERVER_PID}" "the application server"

    # Both of those FORK: `php -S` spawns PHP_CLI_SERVER_WORKERS children,
    # and the TLS terminator one child per connection. Killing the parent
    # leaves the children running, holding their ports and their memory —
    # an interrupted run left five orphaned servers behind before this
    # existed. Matched on the run's own temporary directory, which appears
    # in every one of those command lines (`-t <dir>/instance/public`,
    # `--cert=<dir>/server.pem`) and is a mktemp name no other process on
    # the machine can carry. This script's own command line does not
    # contain it, so cleanup cannot kill itself.
    if [[ -n "${INSTANCE_DIR}" ]]; then
        pkill -f "${INSTANCE_DIR}" > /dev/null 2>&1
    fi

    if [[ -n "${ZAP_CONTAINER}" ]]; then
        echo "DAST: removing the ZAP container."
        docker rm -f "${ZAP_CONTAINER}" > /dev/null 2>&1
    fi

    if [[ "${DATABASE_PROVISIONED}" -eq 1 ]]; then
        php "${SUPPORT}" teardown-db
    fi

    if [[ -n "${MYSQL_CONTAINER}" ]]; then
        echo "DAST: removing the throwaway MySQL container."
        docker rm -f "${MYSQL_CONTAINER}" > /dev/null 2>&1
    fi

    # Core\Database\MigrationRunner dumps into the REPOSITORY's
    # storage/temp regardless of how isolated the instance is — same
    # situation, and the same snapshot-and-diff answer, as scripts/e2e.sh.
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
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------
# 1. Prerequisites. Fail closed with the exact command to run — never
# install anything on the caller's behalf, same philosophy as
# scripts/e2e.sh and scripts/release.sh's gates.
# ---------------------------------------------------------------
command -v php > /dev/null 2>&1 || { echo "ERROR: php is required to run the security scan." >&2; exit 1; }
command -v npm > /dev/null 2>&1 || { echo "ERROR: npm is required to run the security scan (Node.js >= 22)." >&2; exit 1; }
[[ -f "${REPO_ROOT}/vendor/autoload.php" ]] || { echo "ERROR: vendor/autoload.php not found — run 'composer install' first." >&2; exit 1; }
# The matrix profile drives no browser and starts no scanner, so it is
# not made to depend on either being installed — a prerequisite that is
# never used is a prerequisite that turns somebody away for nothing.
if [[ "${PROFILE}" != "standard" ]]; then
    [[ -d "${REPO_ROOT}/node_modules/@playwright/test" ]] || {
        echo "ERROR: @playwright/test is not installed — run 'npm ci' then 'npm run e2e:install'." >&2
        exit 1
    }
    [[ -f "${PLAN_FILE}" ]] || { echo "ERROR: no ZAP plan for profile '${PROFILE}' at ${PLAN_FILE}." >&2; exit 1; }
fi

php -r 'exit(extension_loaded("openssl") && extension_loaded("pcntl") ? 0 : 1);' || {
    echo "ERROR: the security scan needs PHP's 'openssl' and 'pcntl' extensions." >&2
    echo "       openssl generates the run's certificate; pcntl runs the TLS terminator." >&2
    exit 1
}

# Docker is ZAP's requirement, not the harness's. The matrix profile
# runs no container, and a MySQL server is looked for on the host first
# either way (below) — so this is asked only of the profiles that
# actually need it, rather than turning somebody away from the one
# profile that would have worked.
if [[ "${PROFILE}" != "standard" ]]; then
    command -v docker > /dev/null 2>&1 || {
        echo "ERROR: Docker is required — OWASP ZAP runs as a container." >&2
        echo "       Install Docker Desktop (macOS) or the docker engine (Linux), then re-run." >&2
        exit 1
    }
    docker info > /dev/null 2>&1 || {
        echo "ERROR: the Docker daemon is not reachable. Start Docker, then re-run." >&2
        exit 1
    }
    docker image inspect "${DAST_ZAP_IMAGE}" > /dev/null 2>&1 || {
        echo "ERROR: the ZAP image is not present locally. Pull it once with:" >&2
        echo "           docker pull ${DAST_ZAP_IMAGE}" >&2
        echo "       (about 1.2 GB; nothing here downloads it for you.)" >&2
        exit 1
    }
fi

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

if mysql_is_reachable > /dev/null 2>&1; then
    echo "DAST: using the MySQL server at ${E2E_DB_HOST}:${E2E_DB_PORT} (database '${E2E_DB_NAME}')."
else
    MYSQL_CONTAINER="scoutmagic-dast-mysql-$$"
    E2E_DB_HOST="127.0.0.1"
    E2E_DB_PORT="$(php "${SUPPORT}" free-port)"
    E2E_DB_USER="root"
    E2E_DB_PASSWORD="dast_password"
    export E2E_DB_HOST E2E_DB_PORT E2E_DB_USER E2E_DB_PASSWORD

    echo "DAST: no MySQL reachable — starting a throwaway container on 127.0.0.1:${E2E_DB_PORT} (mysql:8.0)."
    docker run --detach --rm \
        --name "${MYSQL_CONTAINER}" \
        --env "MYSQL_ROOT_PASSWORD=${E2E_DB_PASSWORD}" \
        --publish "127.0.0.1:${E2E_DB_PORT}:3306" \
        mysql:8.0 > /dev/null || {
            echo "ERROR: could not start the throwaway MySQL container." >&2
            exit 1
        }

    waited=0
    until mysql_is_reachable > /dev/null 2>&1; do
        waited=$((waited + 1))
        if [[ "${waited}" -gt 600 ]]; then
            echo "ERROR: the throwaway MySQL container did not become reachable within 60 s." >&2
            exit 1
        fi
        sleep 0.1
    done
fi

# ---------------------------------------------------------------
# 3-5. Certificate, provisioning, server, TLS terminator.
# ---------------------------------------------------------------
INSTANCE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/scoutmagic-dast.XXXXXX")"
SERVER_LOG="${INSTANCE_DIR}/php-server.log"
TLS_LOG="${INSTANCE_DIR}/tls-proxy.log"
ZAP_LOG="${INSTANCE_DIR}/zap.log"
BACKUP_SNAPSHOT="${INSTANCE_DIR}/backups-before-run.txt"
CERT_FILE="${INSTANCE_DIR}/server.pem"

# The run's mailbox, and the instance directory the scenarios reach for
# when they need the task queue turned. Both are provisioned exactly as
# scripts/e2e.sh provisions them, because the SAME scenarios read them:
# a magic link, a password reset, a registration confirmation and both
# mass-mail flows have no browser-only half, and without these two
# variables six specs fail on "E2E_MAILDROP is not set" long before
# anything security-related is exercised.
export E2E_MAILDROP="${INSTANCE_DIR}/maildrop"
mkdir -p "${E2E_MAILDROP}"
export E2E_INSTANCE_DIR="${INSTANCE_DIR}/instance"

# Shared with the ZAP container: the plan reads the release file from
# here and writes its reports into it. One mount, so nothing else of the
# host filesystem is visible to the scanner. The matrix profile has no
# plan and no container, so it skips the copy.
ZAP_WORK_DIR="${INSTANCE_DIR}/zap"
mkdir -p "${ZAP_WORK_DIR}/reports"
if [[ -n "${PLAN_FILE}" ]]; then
    cp "${PLAN_FILE}" "${ZAP_WORK_DIR}/plan.yaml"

    # One placeholder the Automation Framework cannot expand for us: it
    # resolves an activeScan job's `policy` against the policies it knows
    # while VERIFYING the plan, which happens before environment-variable
    # expansion, so a ${VAR} there arrives verbatim and the whole plan
    # aborts. Substituted into the copy, never into the file under
    # version control. Harmless for the passive plan, which has no such
    # placeholder.
    if [[ -n "${DAST_ACTIVE_POLICY}" ]]; then
        sed -i.bak "s/__DAST_ACTIVE_POLICY__/${DAST_ACTIVE_POLICY}/g" "${ZAP_WORK_DIR}/plan.yaml"
        rm -f "${ZAP_WORK_DIR}/plan.yaml.bak"
    fi
fi
# The container runs as the `zap` user, not as whoever started this
# script. Only the reports directory needs to be writable by that other
# uid; the plan is read, and the directory itself only traversed. The
# run's temporary directory above it stays 0700 — the bind mount is
# resolved by the kernel at mount time, so the container never needs to
# walk through it.
chmod 0755 "${ZAP_WORK_DIR}"
chmod 0777 "${ZAP_WORK_DIR}/reports"
if [[ -n "${PLAN_FILE}" ]]; then
    chmod 0644 "${ZAP_WORK_DIR}/plan.yaml"
fi

find "${REPO_ROOT}/storage/temp" -maxdepth 1 -type f -name 'backup_*.sql' 2>/dev/null | sort > "${BACKUP_SNAPSHOT}"

PORT="${DAST_PORT:-$(php "${SUPPORT}" free-port)}"
BACKEND_PORT="${DAST_BACKEND_PORT:-$(php "${SUPPORT}" free-port)}"
ZAP_PORT="${DAST_ZAP_PORT:-$(php "${SUPPORT}" free-port)}"

# `localhost`, not 127.0.0.1 — e2e_base_url() in scripts/e2e-support.php
# documents why (WebAuthn refuses an IP-literal Relying Party ID, so an
# instance calling itself 127.0.0.1 cannot register a passkey at all, and
# the browser suite's passkey scenario is part of the traffic this scan
# replays).
BASE_URL="https://localhost:${PORT}"

echo "DAST: generating a self-signed certificate for this run."
php "${DAST_SUPPORT}" generate-cert "${CERT_FILE}" localhost

php "${SUPPORT}" provision "${INSTANCE_DIR}/instance" "${PORT}"
DATABASE_PROVISIONED=1

echo "DAST: starting the application server on 127.0.0.1:${BACKEND_PORT} (${DAST_WORKERS} worker(s))."
# Coverage is deliberately NOT collected here, and pcov is switched off
# rather than merely unused: PHP_CLI_SERVER_WORKERS forks, and the
# coverage merge in scripts/e2e-support.php assumes a single process.
# Coverage of a security scan would not mean anything anyway.
PHP_CLI_SERVER_WORKERS="${DAST_WORKERS}" php \
    -d display_errors=0 \
    -d log_errors=1 \
    -d error_log="${INSTANCE_DIR}/php-error.log" \
    -d upload_max_filesize=100M \
    -d post_max_size=110M \
    -d pcov.enabled=0 \
    -d sendmail_path="php '${REPO_ROOT}/scripts/e2e-maildrop.php'" \
    -S "127.0.0.1:${BACKEND_PORT}" \
    -t "${INSTANCE_DIR}/instance/public" \
    > "${SERVER_LOG}" 2>&1 &
SERVER_PID=$!

echo "DAST: terminating TLS on 127.0.0.1:${PORT} in front of it."
php "${REPO_ROOT}/scripts/dast-tls-proxy.php" \
    --listen="127.0.0.1:${PORT}" \
    --backend="127.0.0.1:${BACKEND_PORT}" \
    --cert="${CERT_FILE}" \
    > "${TLS_LOG}" 2>&1 &
TLS_PID=$!

if ! php "${DAST_SUPPORT}" wait-url "${BASE_URL}/api/version" "${DAST_SERVER_TIMEOUT}"; then
    echo "ERROR: the application did not answer on ${BASE_URL} within ${DAST_SERVER_TIMEOUT} s." >&2
    echo "--- php -S output ---" >&2
    tail -40 "${SERVER_LOG}" >&2 || true
    echo "--- TLS terminator output ---" >&2
    tail -40 "${TLS_LOG}" >&2 || true
    echo "--- PHP error log ---" >&2
    tail -40 "${INSTANCE_DIR}/php-error.log" >&2 || true
    exit 1
fi

# The whole reason IT-01 exists: prove, before spending an hour scanning,
# that the instance really believes it is on HTTPS. If this header is
# missing the scan would spend its time rediscovering a broken harness
# and reporting it as an application defect.
if ! php -r '
    $context = stream_context_create(["ssl" => ["verify_peer" => false, "verify_peer_name" => false]]);
    $body = @file_get_contents($argv[1] . "/api/version", false, $context);
    if ($body === false) { exit(1); }
    foreach ($http_response_header as $header) {
        if (stripos($header, "Strict-Transport-Security:") === 0) { exit(0); }
    }
    exit(1);
' "${BASE_URL}"; then
    echo "ERROR: the instance is not emitting Strict-Transport-Security over HTTPS." >&2
    echo "       The X-Forwarded-Proto opt-in is not reaching Core\\Http\\RequestScheme —" >&2
    echo "       scanning now would report the harness's own defect as the application's." >&2
    exit 1
fi
echo "DAST: HSTS confirmed on the throwaway instance — the HTTPS wiring is live."

# ---------------------------------------------------------------
# 6-bis. The authorization matrix, and nothing else.
#
# `standard` stops here. It shares everything above — the throwaway
# database, the provisioning, the server, the real TLS — because the
# matrix must sign in the way a visitor does, and it needs none of what
# follows: there is no traffic to scan and nothing to attack, only 528
# routes to ask the same question of six times each. That makes it the
# one profile fast enough to run on a whim.
# ---------------------------------------------------------------
if [[ "${PROFILE}" == "standard" ]]; then
    mkdir -p "${DAST_REPORT_DIR}"
    php "${REPO_ROOT}/scripts/authz-support.php" matrix "${BASE_URL}" "${DAST_REPORT_DIR}/authz-matrix.json"
    MATRIX_EXIT=$?

    if [[ "${MATRIX_EXIT}" -ne 0 ]]; then
        echo "DAST FAILED (profile standard). Report: ${DAST_REPORT_DIR}/authz-matrix.json" >&2
        exit "${MATRIX_EXIT}"
    fi

    echo "DAST OK (profile standard). Report: ${DAST_REPORT_DIR}/authz-matrix.json"
    exit 0
fi

# ---------------------------------------------------------------
# 6. ZAP. Daemon mode, so the plan can configure the passive scanner and
# then block while the browser drives the traffic through it.
# ---------------------------------------------------------------
ZAP_API_KEY="$(php -r 'echo bin2hex(random_bytes(16));')"
ZAP_CONTAINER="scoutmagic-dast-zap-$$"
RELEASE_FILE_HOST="${ZAP_WORK_DIR}/browser-finished"

# Reaching a server on the host from inside a container is
# platform-dependent, and getting it wrong produces an empty site map
# rather than an error. On Linux the container shares the host's network
# namespace, so `localhost` is the same loopback on both sides. Elsewhere
# (Docker Desktop) the ports are published and the host is reachable by
# name.
if [[ "$(uname -s)" == "Linux" ]]; then
    ZAP_NETWORK_ARGS=(--network=host)
    ZAP_TARGET="${BASE_URL}"
    ZAP_PROXY="http://127.0.0.1:${ZAP_PORT}"
    ZAP_LISTEN_HOST="127.0.0.1"
else
    ZAP_NETWORK_ARGS=(--publish "127.0.0.1:${ZAP_PORT}:${ZAP_PORT}" --add-host "host.docker.internal:host-gateway")
    ZAP_TARGET="https://host.docker.internal:${PORT}"
    ZAP_PROXY="http://127.0.0.1:${ZAP_PORT}"
    ZAP_LISTEN_HOST="0.0.0.0"
    echo "DAST: not Linux — ZAP will reach the instance as ${ZAP_TARGET}."
fi

# The URL the BROWSER is given, which is not always the one this script
# serves. Every request the browser makes is resolved by ZAP, not by the
# browser, so the hostname in it has to be one that means "the instance"
# from INSIDE the container. On Linux that is `localhost` for both, since
# --network=host makes the two loopbacks the same interface, and
# BROWSER_URL is BASE_URL unchanged.
#
# On Docker Desktop they are different machines and `localhost` inside the
# container is the container itself, where nothing listens. Handing the
# browser BASE_URL there produced the one failure this whole block exists
# to prevent, and produced it silently: every request 502'd or timed out,
# every spec failed for its own apparent reason, and ZAP's site map for
# ZAP_TARGET stayed empty because the browser had never asked for that
# host — so the scan "completed" having seen no traffic at all. The two
# halves of the harness were aimed at two different hostnames: ZAP_TARGET
# went to ZAP for its context, site map and alerts (below), while the
# browser was still being sent to localhost.
#
# The self-signed certificate stays a localhost certificate and does not
# need a host.docker.internal SAN: ZAP terminates and re-signs every
# HTTPS connection with its own CA, so the browser never sees the
# instance's certificate, and ZAP does not verify it.
#
# NECESSARY BUT NOT YET SUFFICIENT ON DOCKER DESKTOP. This restores the
# traffic — ZAP records the site map it used to leave empty — and most of
# the suite passes. What still fails is every scenario that follows a link
# the SERVER built: the instance is provisioned through
# scripts/e2e-support.php e2e_base_url(), which hardcodes `localhost`, so
# a magic-link email and a passkey's Relying Party ID still name a host
# ZAP resolves to its own container. Measured: the password login and the
# public pages pass, the magic link and the passkey do not.
#
# Making the instance agree is NOT the one-line change it looks like, and
# e2e_base_url()'s own docblock is where to start before trying: three
# documented behaviours are keyed to the literal name `localhost` — most
# sharply Core\Statistics\StatisticsSender::isPublicHost(), which refuses
# to report BECAUSE it recognises that name. An instance calling itself
# host.docker.internal may consider itself a public host and start
# reporting outward from a security scanning bench. Any fix has to answer
# that first.
#
# Until then the dynamic gate is a Linux/CI gate: it runs green there (and
# on af70f87c it did), and needs --skip-dast-gate to release from macOS.
BROWSER_URL="${ZAP_TARGET}"

echo "DAST: starting OWASP ZAP (${DAST_ZAP_IMAGE}) on ${ZAP_PROXY}."
docker run --detach --rm \
    --name "${ZAP_CONTAINER}" \
    "${ZAP_NETWORK_ARGS[@]}" \
    --volume "${ZAP_WORK_DIR}:/dast" \
    --env "DAST_TARGET=${ZAP_TARGET}" \
    --env "DAST_REPORT_DIR=/dast/reports" \
    --env "DAST_RELEASE_GATE_FILE=/dast/browser-finished" \
    --env "DAST_PROFILE=${PROFILE}" \
    --env "DAST_ACTIVE_POLICY=${DAST_ACTIVE_POLICY}" \
    --env "DAST_MAX_SCAN_MINS=${DAST_MAX_SCAN_MINS}" \
    "${DAST_ZAP_IMAGE}" \
    zap.sh -daemon -silent \
        -host "${ZAP_LISTEN_HOST}" -port "${ZAP_PORT}" \
        -config api.key="${ZAP_API_KEY}" \
        -config api.addrs.addr.name=.* \
        -config api.addrs.addr.regex=true \
        -config anticsrf.tokens.token\(99\).name=_csrf_token \
        -config anticsrf.tokens.token\(99\).enabled=true \
    > /dev/null || {
        echo "ERROR: could not start the ZAP container." >&2
        exit 1
    }

if ! php "${DAST_SUPPORT}" wait-url "${ZAP_PROXY}/JSON/core/view/version/?apikey=${ZAP_API_KEY}" "${DAST_ZAP_TIMEOUT}"; then
    echo "ERROR: ZAP did not answer its API within ${DAST_ZAP_TIMEOUT} s." >&2
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi
echo "DAST: ZAP is up."

# The plan starts now and blocks on its own `delay` job until the browser
# has finished. Starting it BEFORE the traffic is the whole point: alert
# filters and passive-scan configuration have to be in place before the
# first response is scanned, or the alerts they concern have already been
# raised by the time the filter exists.
PLAN_ID="$(php "${DAST_SUPPORT}" zap-plan-start "${ZAP_PROXY}" "${ZAP_API_KEY}" /dast/plan.yaml)"
echo "DAST: ZAP automation plan ${PLAN_ID} started (waiting on the browser)."

# Do not send traffic until the configuration jobs have actually run.
# Polled, never slept: the `delay` job is the third job in the plan, and
# its appearance in the progress log is the proof that the two before it
# are done.
if ! php "${DAST_SUPPORT}" zap-plan-await-delay "${ZAP_PROXY}" "${ZAP_API_KEY}" "${PLAN_ID}" 120; then
    echo "ERROR: ZAP never reached the plan's delay job — the passive scanner is not configured." >&2
    cat "${ZAP_LOG}" >&2 2>/dev/null || true
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi

# ---------------------------------------------------------------
# 7. The browser, proxied through ZAP.
# ---------------------------------------------------------------
echo "DAST: running the Playwright suite through ZAP..."
set +e
# ${PLAYWRIGHT_ARGS[@]+"${PLAYWRIGHT_ARGS[@]}"}, not the plain
# "${PLAYWRIGHT_ARGS[@]}": under `set -u` (line 2), bash 3.2 — which is
# what macOS still ships as /bin/bash, and this script's shebang — treats
# an EMPTY array's expansion as an unbound variable and aborts. The array
# is empty on exactly the path that matters: scripts/release.sh's dynamic
# gate calls this script with only --profile=passive and no extra
# Playwright arguments, so the browser suite died here before sending ZAP
# a single request, and the gate then failed for "a browser suite that did
# not complete". It went unseen because CI runs bash 5 on Linux, where
# expanding an empty array under `set -u` has been legal since 4.4.
# The ${var[@]+…} form expands to nothing when the array is empty and to
# the properly quoted elements otherwise, in both bash versions.
# --grep-invert @full: the scan replays the CONFIDENCE tier, the same
# scenarios `npm run e2e` runs (AGENTS.md § Tests). The @full-tagged
# per-module boot matrix toggles every module on and off in turn — through
# a proxy that records and replays every request, that is minutes of extra
# traffic examining module toggling twenty times over, for no header or
# cookie the confidence scenarios don't already show ZAP.
E2E_BASE_URL="${BROWSER_URL}" \
E2E_PROXY_SERVER="${ZAP_PROXY}" \
E2E_IGNORE_HTTPS_ERRORS="1" \
E2E_TIMEOUT_FACTOR="${DAST_TIMEOUT_FACTOR}" \
    npm exec --no -- playwright test --config="${REPO_ROOT}/tests/e2e/playwright.config.js" --grep-invert @full ${PLAYWRIGHT_ARGS[@]+"${PLAYWRIGHT_ARGS[@]}"} &
PLAYWRIGHT_PID=$!
wait "${PLAYWRIGHT_PID}"
PLAYWRIGHT_EXIT=$?
PLAYWRIGHT_PID=""
set -e

# ---------------------------------------------------------------
# 8. Release the plan, whatever the browser's verdict: a failed scenario
# still produced traffic worth scanning, and the browser's exit code is
# reported separately below rather than swallowing the findings.
# ---------------------------------------------------------------
if [[ "${PLAYWRIGHT_EXIT}" -ne 0 ]]; then
    echo "DAST: the browser suite exited ${PLAYWRIGHT_EXIT} — scanning the traffic it did produce." >&2
fi

touch "${RELEASE_FILE_HOST}"
echo "DAST: waiting for ZAP to finish passive scanning and write its reports."
if ! php "${DAST_SUPPORT}" zap-plan-wait "${ZAP_PROXY}" "${ZAP_API_KEY}" "${PLAN_ID}" "${DAST_PLAN_TIMEOUT}"; then
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi

# ---------------------------------------------------------------
# 9. Verdict.
# ---------------------------------------------------------------
mkdir -p "${DAST_REPORT_DIR}"

cp "${ZAP_WORK_DIR}"/reports/* "${DAST_REPORT_DIR}/" 2>/dev/null || {
    echo "ERROR: ZAP produced no report in ${ZAP_WORK_DIR}/reports." >&2
    exit 1
}
echo "DAST: reports written to ${DAST_REPORT_DIR}/"

php "${DAST_SUPPORT}" assert-sitemap "${ZAP_PROXY}" "${ZAP_API_KEY}" "${ZAP_TARGET}" "${SITEMAP_EXPECTATIONS}"

# What the active scan actually got through, before the verdict rather
# than after it: a bounded scan reporting "no findings" reads exactly
# like a complete one, and only this tells them apart.
php "${DAST_SUPPORT}" scan-coverage "${ZAP_PROXY}" "${ZAP_API_KEY}" || true

set +e
php "${DAST_SUPPORT}" gate-alerts "${ZAP_PROXY}" "${ZAP_API_KEY}" "${ZAP_TARGET}" "${DAST_THRESHOLD}"
GATE_EXIT=$?
set -e

if [[ "${GATE_EXIT}" -ne 0 ]]; then
    echo "DAST FAILED (profile ${PROFILE}). Report: ${DAST_REPORT_DIR}/dast-${PROFILE}.html" >&2
    exit "${GATE_EXIT}"
fi

if [[ "${PLAYWRIGHT_EXIT}" -ne 0 ]]; then
    echo "DAST: no security finding, but the browser suite failed (exit ${PLAYWRIGHT_EXIT})." >&2
    echo "      A scan is only as complete as the traffic it was given — treat this as a failed run." >&2
    exit "${PLAYWRIGHT_EXIT}"
fi

echo "DAST OK (profile ${PROFILE})."
