<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * A scripted IMAP server for the end-to-end run — test infrastructure only,
 * started and stopped by scripts/e2e.sh, never shipped.
 *
 * Usage: php scripts/e2e-imap.php <port> <certificate.pem> <private-key.pem>
 *
 * **Why it exists.** « Vérifier et enregistrer » (#1333, D10) opens a real
 * IMAP connection before a box is saved, and a box that does not connect
 * is not saved — so the browser cannot add an address at all without a
 * server that answers. This one answers just enough of RFC 3501 for that
 * verification: the greeting, CAPABILITY, LOGIN, LIST, NOOP and LOGOUT,
 * plus empty answers to what a relève would ask (EXAMINE, SELECT, UID
 * SEARCH) should a scheduler pass reach it.
 *
 * **Over TLS, with a certificate the application really verifies.** The
 * module refuses an unencrypted session and an invalid certificate, and
 * that refusal is not something the run should switch off: e2e.sh makes a
 * throwaway certificate for `localhost`, and hands it to the application
 * server as a trusted authority (`openssl.cafile`) — the verification
 * runs exactly as in production.
 *
 * **One password is refused**, `refuse`, with the tagged NO a real server
 * sends, so a scenario can show the failure path; any other is accepted.
 *
 * The folders listed carry the special-use attributes the configuration
 * filters on (RFC 6154): a junk folder that must never be offered, a sent
 * folder found automatically, and one ordinary folder that may be ticked.
 */

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/e2e-imap.php <port> <certificate.pem> <private-key.pem>\n");
    exit(2);
}

$port = (int) $argv[1];
$context = stream_context_create(['ssl' => [
    'local_cert' => $argv[2],
    'local_pk' => $argv[3],
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);

$server = @stream_socket_server(
    'tls://127.0.0.1:' . $port,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if ($server === false) {
    fwrite(STDERR, "e2e-imap: cannot listen on port {$port}: {$errstr}\n");
    exit(1);
}

const E2E_IMAP_FOLDERS = [
    ['(\\HasNoChildren)', 'INBOX'],
    ['(\\HasNoChildren)', 'Factures'],
    ['(\\HasNoChildren \\Junk)', 'Indesirables'],
    ['(\\HasNoChildren \\Sent)', 'Envoyes'],
];

/**
 * @param resource $client
 */
function e2eImapWrite($client, string $line): void
{
    @fwrite($client, $line . "\r\n");
}

/**
 * One connection, from greeting to LOGOUT or silence.
 *
 * @param resource $client
 */
function e2eImapServe($client): void
{
    stream_set_timeout($client, 15);
    e2eImapWrite($client, '* OK [CAPABILITY IMAP4rev1] ScoutMagic E2E IMAP ready');

    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        if (!preg_match('/^(\S+)\s+(?:UID\s+)?(\S+)(.*)$/i', $line, $parts)) {
            continue;
        }
        [, $tag, $command, $rest] = $parts;

        switch (strtoupper($command)) {
            case 'CAPABILITY':
                e2eImapWrite($client, '* CAPABILITY IMAP4rev1');
                e2eImapWrite($client, $tag . ' OK CAPABILITY completed');
                break;
            case 'LOGIN':
                if (str_contains($rest, '"refuse"')) {
                    e2eImapWrite($client, $tag . ' NO [AUTHENTICATIONFAILED] Invalid credentials');
                } else {
                    e2eImapWrite($client, $tag . ' OK LOGIN completed');
                }
                break;
            case 'LIST':
                foreach (E2E_IMAP_FOLDERS as [$flags, $name]) {
                    e2eImapWrite($client, '* LIST ' . $flags . ' "/" "' . $name . '"');
                }
                e2eImapWrite($client, $tag . ' OK LIST completed');
                break;
            case 'EXAMINE':
            case 'SELECT':
                e2eImapWrite($client, '* 0 EXISTS');
                e2eImapWrite($client, '* OK [UIDVALIDITY 1] UIDs valid');
                e2eImapWrite($client, '* OK [UIDNEXT 1] Predicted next UID');
                e2eImapWrite($client, $tag . ' OK [READ-ONLY] ' . strtoupper($command) . ' completed');
                break;
            case 'SEARCH':
                e2eImapWrite($client, '* SEARCH');
                e2eImapWrite($client, $tag . ' OK SEARCH completed');
                break;
            case 'LOGOUT':
                e2eImapWrite($client, '* BYE Logging out');
                e2eImapWrite($client, $tag . ' OK LOGOUT completed');

                return;
            default:
                e2eImapWrite($client, $tag . ' OK ' . strtoupper($command) . ' completed');
        }
    }
}

// One connection at a time: the application server itself answers one
// request at a time, and each verification is one short session.
while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        // A handshake a client abandoned: wait for the next one.
        continue;
    }
    e2eImapServe($client);
    @fclose($client);
}
