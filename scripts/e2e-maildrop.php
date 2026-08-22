<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * The end-to-end harness's mail transport: a stand-in for `sendmail` that
 * writes every message to a file instead of delivering it.
 *
 * scripts/e2e.sh points the application server's `sendmail_path` at this
 * script and exports E2E_MAILDROP; the throwaway instance runs in local
 * mail mode (scripts/e2e-support.php), so Core\Mail\MailService hands its
 * message to PHP's mail(), PHP runs this, and the whole RFC 5322 message
 * — headers, MIME parts, DKIM signature and all — lands in that
 * directory. tests/e2e/support/maildrop.js reads it back.
 *
 * Why this exists: several of the application's most important flows are
 * finished by an email and cannot be driven through a browser without one
 * — a magic link, a password reset, a form's confirmation. Before this,
 * the E2E instance was configured for SMTP against a localhost:25 that
 * nothing listened on, so every one of those sends raised a MailException
 * and no scenario could reach the other side of it.
 *
 * What it deliberately is NOT: a fake mailer. Nothing about MailService,
 * PHPMailer, the DKIM signing or the mail templates is stubbed or
 * bypassed — this replaces only the transport's last hop, exactly as a
 * developer's local `sendmail` writing to a Maildir would. A scenario
 * therefore asserts on the message the application really produced.
 *
 * Invoked by PHP as `sendmail_path` with sendmail's own arguments
 * (`-t -i ...`), which are all ignored: the recipient is in the message's
 * own To: header, which is what a scenario reads.
 *
 * Exit code 0 always, unless the drop directory is unusable. PHPMailer
 * reads a non-zero status as a delivery failure, and a harness that turns
 * "the test rig could not write a file" into "the application failed to
 * send" would be lying about which of the two broke — so the one case
 * that does fail says exactly what happened, on stderr, where the server
 * log will carry it.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "e2e-maildrop.php is a CLI script (it stands in for sendmail).\n");
    exit(1);
}

$directory = (string) getenv('E2E_MAILDROP');
if ($directory === '') {
    fwrite(STDERR, "e2e-maildrop.php: E2E_MAILDROP is not set — run the suite through scripts/e2e.sh.\n");
    exit(1);
}

if (!is_dir($directory)) {
    fwrite(STDERR, "e2e-maildrop.php: E2E_MAILDROP ('{$directory}') is not a directory.\n");
    exit(1);
}

$message = (string) stream_get_contents(STDIN);

// One file per message, named so that a plain sort is chronological: a
// scenario asking for "the last magic link" must not depend on the
// filesystem's own ordering. hrtime() rather than a counter or a
// second-resolution timestamp — several requests can be in flight, each
// running this in a process of its own, and two mails in the same second
// are ordinary; the random suffix settles the remaining tie.
$path = sprintf('%s/%s-%s.eml', $directory, (string) hrtime(true), bin2hex(random_bytes(4)));

if (file_put_contents($path, $message) === false) {
    fwrite(STDERR, "e2e-maildrop.php: could not write {$path}.\n");
    exit(1);
}

exit(0);
