<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * What a mail transport said, with the e-mail addresses taken out.
 *
 * PHPMailer's `ErrorInfo` quotes the conversation, and the useful half of
 * it — « 550 5.1.1 User unknown », « Could not authenticate », « SMTP
 * connect() failed » — arrives glued to the address that failed. That
 * address is personal data, both destinations are readable by every
 * admin and the journal travels to the maintainer inside the diagnostic
 * archive, and SECURITY.md's rule has no exception for « it was in the
 * error message ». The SMTP code and the server's words are what
 * diagnoses the problem anyway; the address only says which member
 * happened to be next in the queue.
 *
 * A class of its own rather than a private method, because there are now
 * **two** places that persist that text and they are in different
 * layers: `MailService::journalFailure()` writes it to `event_log`, and
 * `Modules\TestTools\Mail\CaptureTransport::recordFailure()` writes it to
 * `captured_emails.error_message`. A security control with two call sites
 * is a control that must have one implementation — the second copy is the
 * one that silently stops matching the first.
 */
final class MailErrorRedaction
{
    /**
     * Bounded as well as redacted: `event_log.context` is JSON on a
     * shared-hosting database, and a transport that answers with a wall
     * of text is exactly the transport that is misbehaving.
     */
    private const MAX_LENGTH = 400;

    /**
     * **Deliberately not `/u`.** A transport error is routinely *not*
     * valid UTF-8 — a relay banner, a driver message, a non-ASCII byte
     * from a misconfigured server — and `preg_replace()` with the `/u`
     * flag returns `null` on such a subject, which a `(string)` cast then
     * turns into an empty string. That is how the journal entry this
     * exists to fill ends up carrying nothing at all;
     * `Core\Support\SupportCollectorContext::collapseWhitespace()` had
     * already reached the same conclusion for the same reason. Without
     * the flag the pattern is byte-oriented and cannot fail — and an
     * address quoted in an SMTP error is ASCII, so nothing is lost.
     */
    public static function withoutAddresses(string $text): string
    {
        $clean = (string) preg_replace(
            '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/',
            '[adresse]',
            trim($text)
        );

        return mb_substr($clean, 0, self::MAX_LENGTH);
    }
}
