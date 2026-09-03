#!/bin/bash
set -uo pipefail

# ScoutMagic — run the PHPUnit suite against BOTH database engines.
#
# Usage: ./scripts/test-engines.sh [<extra phpunit arguments>...]
#        npm run test:engines    (canonical command)
#
# Why this exists: the two engines disagree, the disagreement is silent,
# and until this script there was nowhere a developer could see both.
#
# Local development runs MariaDB (.claude/hooks/session-start.sh starts
# one); GitHub CI's `test` job runs MySQL 8. So the suite a developer runs
# before pushing and the suite that gates the merge exercised DIFFERENT
# engines, and neither of them exercised both. That gap is not theoretical
# — it cost a red CI on the very change that fixed the engine divergence:
# a rule that is correct on MariaDB (an unquoted `NULL` in COLUMN_DEFAULT
# means "no default") is exactly wrong on MySQL, where it means a genuine
# default of the string "NULL". See Core\Database\SchemaIntrospector::
# decodeDefault().
#
# The dangerous direction is the one this script mainly guards: code that
# is correct on MySQL and wrong on MariaDB passes CI and reaches the
# reference installation, which is MariaDB 10.11 on shared hosting.
#
# What it does:
#   1. Runs the suite against whatever MariaDB is already reachable on
#      TEST_DB_* (nothing is started or stopped — that server is the
#      developer's).
#   2. Starts a THROWAWAY MySQL 8 on a free port, with its own data
#      directory under a temp dir, runs the suite against it, and tears it
#      down — on success, on failure, and on Ctrl-C.
#   3. Reports both, and exits non-zero if either failed.
#
# A MySQL that could not be started is reported as such, never as a pass:
# "the suite is green on both engines" and "the suite is green on the one
# engine I could find" are different sentences, and this script exists
# precisely because the second was being read as the first.

cd "$(dirname "$0")/.."

MARIADB_HOST="${TEST_DB_HOST:-127.0.0.1}"
MARIADB_PORT="${TEST_DB_PORT:-3306}"
MARIADB_NAME="${TEST_DB_NAME:-test_db}"
MARIADB_USER="${TEST_DB_USER:-root}"
MARIADB_PASSWORD="${TEST_DB_PASSWORD:-test_password}"

MYSQL_DIR=""
MYSQL_PID=""

cleanup() {
    if [[ -n "${MYSQL_PID}" ]] && kill -0 "${MYSQL_PID}" 2>/dev/null; then
        echo "engines: stopping the throwaway MySQL (pid ${MYSQL_PID})."
        kill "${MYSQL_PID}" 2>/dev/null || true
        # Poll rather than sleep: a data directory removed from under a
        # still-running mysqld leaves it writing into deleted files.
        for _ in $(seq 1 50); do
            kill -0 "${MYSQL_PID}" 2>/dev/null || break
            sleep 0.2
        done
        kill -9 "${MYSQL_PID}" 2>/dev/null || true
    fi
    [[ -n "${MYSQL_DIR}" && -d "${MYSQL_DIR}" ]] && rm -rf "${MYSQL_DIR}"
    return 0
}
trap cleanup EXIT INT TERM

free_port() {
    php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $e, $m); $n = stream_socket_get_name($s, false); fclose($s); echo substr($n, strrpos($n, ":") + 1);'
    return $?
}

# The one binary that can be either engine, so ask it which it is.
mysqld_binary() {
    local candidate
    for candidate in /usr/sbin/mysqld /usr/libexec/mysqld "$(command -v mysqld 2>/dev/null || true)"; do
        [[ -x "${candidate}" ]] || continue
        if "${candidate}" --version 2>/dev/null | grep -qiv mariadb; then
            echo "${candidate}"
            return 0
        fi
    done

    return 1
}

run_suite() {
    local label="$1" host="$2" port="$3" name="$4" user="$5" password="$6"
    shift 6

    echo
    echo "=============================================================="
    echo "engines: running the suite against ${label}"
    echo "=============================================================="

    TEST_DB_HOST="${host}" TEST_DB_PORT="${port}" TEST_DB_NAME="${name}" \
    TEST_DB_USER="${user}" TEST_DB_PASSWORD="${password}" \
        vendor/bin/phpunit "$@"
    # The suite's own status, explicitly: every caller branches on it.
    return $?
}

MARIADB_STATUS="not run"
MYSQL_STATUS="not run"
EXIT_CODE=0

# --- 1. MariaDB: whatever is already there ---------------------------
if H="${MARIADB_HOST}" P="${MARIADB_PORT}" U="${MARIADB_USER}" W="${MARIADB_PASSWORD}" \
    php -r 'exit(@(new PDO("mysql:host=" . getenv("H") . ";port=" . getenv("P"), getenv("U"), getenv("W"))) ? 0 : 1);' 2>/dev/null; then
    if run_suite "MariaDB (${MARIADB_HOST}:${MARIADB_PORT})" \
        "${MARIADB_HOST}" "${MARIADB_PORT}" "${MARIADB_NAME}" "${MARIADB_USER}" "${MARIADB_PASSWORD}" "$@"; then
        MARIADB_STATUS="PASSED"
    else
        MARIADB_STATUS="FAILED"
        EXIT_CODE=1
    fi
else
    MARIADB_STATUS="SKIPPED — no server reachable on ${MARIADB_HOST}:${MARIADB_PORT}"
    EXIT_CODE=1
fi

# --- 2. MySQL 8: a throwaway instance ---------------------------------
#
# Docker first, and it is not a preference: `mysql-server` and
# `mariadb-server` CONFLICT as Debian/Ubuntu packages — apt removes one to
# install the other — so on a machine provisioned the usual way the two
# engines cannot both be present natively. A container is what makes
# "both, locally" possible at all, and scripts/e2e.sh already leans on the
# same mechanism for the same reason.
#
# The native path below stays for the machine that does have a real
# mysqld: CI runners, and a container where the packages were forced.
if [[ "${ENGINES_DOCKER:-1}" != "0" ]] && command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    MYSQL_PORT="$(free_port)"
    DOCKER_CONTAINER="scoutmagic-engines-$$"

    if docker run --detach --rm --name "${DOCKER_CONTAINER}" \
        --env "MYSQL_ROOT_PASSWORD=${MARIADB_PASSWORD}" \
        --env "MYSQL_DATABASE=${MARIADB_NAME}" \
        --publish "127.0.0.1:${MYSQL_PORT}:3306" \
        mysql:8.0 >/dev/null 2>&1; then

        echo "engines: waiting for the MySQL container on port ${MYSQL_PORT}..."
        MYSQL_READY=""
        for _ in $(seq 1 120); do
            if H=127.0.0.1 P="${MYSQL_PORT}" U=root W="${MARIADB_PASSWORD}" \
                php -r 'exit(@(new PDO("mysql:host=" . getenv("H") . ";port=" . getenv("P"), getenv("U"), getenv("W"))) ? 0 : 1);' 2>/dev/null; then
                MYSQL_READY="yes"
                break
            fi
            sleep 0.5
        done

        if [[ -n "${MYSQL_READY}" ]]; then
            if run_suite "MySQL 8 (Docker, port ${MYSQL_PORT})" \
                127.0.0.1 "${MYSQL_PORT}" "${MARIADB_NAME}" root "${MARIADB_PASSWORD}" "$@"; then
                MYSQL_STATUS="PASSED"
            else
                MYSQL_STATUS="FAILED"
                EXIT_CODE=1
            fi
        else
            MYSQL_STATUS="FAILED — the container never accepted connections"
            EXIT_CODE=1
        fi

        docker rm -f "${DOCKER_CONTAINER}" >/dev/null 2>&1 || true
    else
        MYSQL_STATUS="FAILED — could not start the mysql:8.0 container"
        EXIT_CODE=1
    fi
elif MYSQLD="$(mysqld_binary)"; then
    MYSQL_DIR="$(mktemp -d -t scoutmagic-mysql-XXXXXX)"
    MYSQL_PORT="$(free_port)"
    mkdir -p "${MYSQL_DIR}/data" "${MYSQL_DIR}/files" "${MYSQL_DIR}/run"

    # --no-defaults is load-bearing on a machine that also has MariaDB:
    # /etc/mysql belongs to that one, and mysqld aborts on the first
    # MariaDB-only variable it finds there.
    if "${MYSQLD}" --no-defaults --initialize-insecure \
        --datadir="${MYSQL_DIR}/data" --user=root >"${MYSQL_DIR}/init.log" 2>&1; then

        "${MYSQLD}" --no-defaults --datadir="${MYSQL_DIR}/data" \
            --port="${MYSQL_PORT}" --socket="${MYSQL_DIR}/run/my.sock" \
            --pid-file="${MYSQL_DIR}/run/my.pid" --mysqlx=OFF --user=root \
            --bind-address=127.0.0.1 --secure-file-priv="${MYSQL_DIR}/files" \
            >"${MYSQL_DIR}/server.log" 2>&1 &
        MYSQL_PID=$!

        echo "engines: waiting for the throwaway MySQL on port ${MYSQL_PORT}..."
        MYSQL_READY=""
        for _ in $(seq 1 100); do
            if P="${MYSQL_PORT}" \
                php -r 'exit(@(new PDO("mysql:host=127.0.0.1;port=" . getenv("P"), "root", "")) ? 0 : 1);' 2>/dev/null; then
                MYSQL_READY="yes"
                break
            fi
            kill -0 "${MYSQL_PID}" 2>/dev/null || break
            sleep 0.3
        done

        if [[ -n "${MYSQL_READY}" ]]; then
            # The password matters: SetupControllerTest drives the real
            # setup wizard, which refuses a connection it cannot make, and
            # an empty root password there fails the wizard rather than
            # the assertion under test.
            P="${MYSQL_PORT}" W="${MARIADB_PASSWORD}" N="${MARIADB_NAME}" php -r '
                $pdo = new PDO("mysql:host=127.0.0.1;port=" . getenv("P"), "root", "");
                $pdo->exec("ALTER USER \"root\"@\"localhost\" IDENTIFIED BY \"" . getenv("W") . "\"");
                $pdo->exec("CREATE USER IF NOT EXISTS \"root\"@\"%\" IDENTIFIED BY \"" . getenv("W") . "\"");
                $pdo->exec("GRANT ALL ON *.* TO \"root\"@\"%\" WITH GRANT OPTION");
                $pdo->exec("FLUSH PRIVILEGES");
                $pdo->exec("CREATE DATABASE IF NOT EXISTS " . getenv("N")
                    . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            '

            VERSION="$("${MYSQLD}" --version | grep -oE 'Ver [0-9.]+' | head -1)"
            if run_suite "MySQL ${VERSION#Ver } (throwaway, port ${MYSQL_PORT})" \
                127.0.0.1 "${MYSQL_PORT}" "${MARIADB_NAME}" root "${MARIADB_PASSWORD}" "$@"; then
                MYSQL_STATUS="PASSED"
            else
                MYSQL_STATUS="FAILED"
                EXIT_CODE=1
            fi
        else
            MYSQL_STATUS="FAILED to start — see ${MYSQL_DIR}/server.log"
            cat "${MYSQL_DIR}/server.log" | tail -20
            EXIT_CODE=1
        fi
    else
        MYSQL_STATUS="FAILED to initialise — see the log above"
        tail -20 "${MYSQL_DIR}/init.log"
        EXIT_CODE=1
    fi
else
    MYSQL_STATUS="SKIPPED — no Docker daemon and no MySQL 8 binary. Note that apt cannot hold both engines: mysql-server and mariadb-server conflict, so Docker is normally how a developer gets the second one."
    EXIT_CODE=1
fi

echo
echo "=============================================================="
echo "engines: MariaDB  ${MARIADB_STATUS}"
echo "engines: MySQL    ${MYSQL_STATUS}"
echo "=============================================================="

if [[ "${EXIT_CODE}" -ne 0 ]]; then
    echo "engines: NOT green on both engines."
fi

exit "${EXIT_CODE}"
