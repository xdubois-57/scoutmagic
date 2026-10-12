<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The default transport: hand the configured message to PHPMailer and let
 * it go out over the wire.
 *
 * This is deliberately a real default rather than a null check inside
 * MailService — a seam whose "off" state is a `if ($transport !== null)`
 * has two delivery paths to keep correct, and only one of them is the one
 * production uses.
 */
final class PhpMailerTransport implements MailTransportInterface
{
    /**
     * What the relay said about the copies it refused, for the messages
     * that left anyway — read once by MailService through
     * {@see self::takeRefusedCopies()}.
     *
     * @var \WeakMap<PHPMailer, string>|null
     */
    private static ?\WeakMap $refusedCopies = null;

    /**
     * The purpose is ignored here, and deliberately so: putting a message
     * on the wire is the same operation whatever it says. Only a transport
     * that does something OTHER than send has a reason to look at it.
     *
     * **A refused copy is not a refused message** (chantier #843, IT-06).
     * Over SMTP, PHPMailer sends the DATA to every recipient the relay
     * accepted and only THEN throws « recipients failed » for the others —
     * so a `Cc:` address the relay rejects made a message the recipient
     * already had read as a failure. That failure is not harmless: the
     * provider chain tries the next relay, which delivers it again; the
     * caller is told it did not leave, so a decision e-mail keeps a
     * tracking link nobody stored and the chief presses « Renvoyer ». Here,
     * when every `To:` address was accepted and only copies were refused,
     * the message has left: the exception stops here, and what the relay
     * said is kept for MailService to journal. Anything else — the
     * recipient refused, no DATA sent, no copy at all — throws as before.
     */
    public function deliver(PHPMailer $mail, MailPurpose $purpose): void
    {
        /** @var array<string, true> $accepted */
        $accepted = [];
        $previousCallback = $mail->action_function;
        // PHPMailer reports each recipient's fate here once the DATA has
        // been accepted, before it throws for the ones that failed.
        $mail->action_function = static function (bool $isSent, array $to) use (&$accepted): void {
            foreach ($isSent ? $to : [] as $address) {
                $accepted[strtolower((string) $address[0])] = true;
            }
        };

        try {
            $mail->send();
        } catch (PHPMailerException $e) {
            if (!self::onlyCopiesWereRefused($mail, $accepted)) {
                throw $e;
            }

            self::$refusedCopies ??= new \WeakMap();
            self::$refusedCopies[$mail] = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        } finally {
            $mail->action_function = $previousCallback;
        }
    }

    /**
     * What the relay said about the copies it refused on a message that
     * nonetheless left — once, and null when nothing was refused.
     */
    public static function takeRefusedCopies(PHPMailer $mail): ?string
    {
        if (self::$refusedCopies === null || !isset(self::$refusedCopies[$mail])) {
            return null;
        }

        $reason = self::$refusedCopies[$mail];
        unset(self::$refusedCopies[$mail]);

        return $reason;
    }

    /**
     * @param array<string, true> $accepted
     */
    private static function onlyCopiesWereRefused(PHPMailer $mail, array $accepted): bool
    {
        $recipients = $mail->getToAddresses();
        if ($mail->getCcAddresses() === [] || $recipients === []) {
            return false;
        }

        foreach ($recipients as $recipient) {
            if (!isset($accepted[strtolower((string) $recipient[0])])) {
                return false;
            }
        }

        return true;
    }
}
