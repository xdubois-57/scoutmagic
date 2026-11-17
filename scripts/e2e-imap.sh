#!/bin/bash
# ScoutMagic — the end-to-end run's IMAP server, started the same way by
# both harnesses that replay the browser suite: scripts/e2e.sh and
# scripts/dast.sh. Sourced, not executed.
#
# « Vérifier et enregistrer », in Configuration > Courrier entrant, opens a
# real IMAP connection before a mailbox is saved, and a mailbox that does
# not connect is not saved (#1333, D10) — so adding an address in the
# browser needs a server that answers. scripts/e2e-imap.php is that
# server, over TLS with a throwaway certificate for `localhost` made here.
# The application server trusts it through `-d openssl.cafile=` on top of
# the system's own authorities (the bundle keeps them, so nothing else the
# run does loses its trust): the certificate check runs as in production,
# nothing is switched off.
#
# start_e2e_imap <directory> <scripts/e2e-support.php>
#   Sets and exports E2E_IMAP_PORT (what tests/e2e/specs/inbound-mail-config.spec.js
#   reads), sets E2E_IMAP_CA_BUNDLE (for the caller's php -S) and
#   E2E_IMAP_PID (for the caller's cleanup).

start_e2e_imap() {
    local directory="$1" support="$2"
    local repo_root
    repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

    mkdir -p "${directory}"
    openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=localhost" \
        -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
        -keyout "${directory}/key.pem" -out "${directory}/cert.pem" > /dev/null 2>&1 \
        || { echo "ERROR: openssl could not make the E2E IMAP server's certificate." >&2; return 1; }

    local system_ca
    system_ca="$(php -r '$file = (string) ini_get("openssl.cafile"); echo is_file($file) ? $file : openssl_get_cert_locations()["default_cert_file"];')"
    E2E_IMAP_CA_BUNDLE="${directory}/ca-bundle.pem"
    {
        if [[ -f "${system_ca}" ]]; then cat "${system_ca}"; fi
        cat "${directory}/cert.pem"
    } > "${E2E_IMAP_CA_BUNDLE}"

    E2E_IMAP_PORT="$(php "${support}" free-port)"
    export E2E_IMAP_PORT
    php "${repo_root}/scripts/e2e-imap.php" "${E2E_IMAP_PORT}" "${directory}/cert.pem" "${directory}/key.pem" \
        > "${directory}/server.log" 2>&1 &
    E2E_IMAP_PID=$!

    # Listening before anything asks: a server still starting would turn the
    # first verification into « la connexion a échoué », which a scenario
    # expecting a refusal could not tell from the refusal it asked for.
    if ! php -r '
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $errstr, 1);
            if ($socket !== false) { fclose($socket); exit(0); }
            usleep(100000);
        }
        exit(1);
    ' "${E2E_IMAP_PORT}"; then
        echo "ERROR: the E2E IMAP server did not listen on port ${E2E_IMAP_PORT}." >&2
        cat "${directory}/server.log" >&2 || true
        return 1
    fi
}
