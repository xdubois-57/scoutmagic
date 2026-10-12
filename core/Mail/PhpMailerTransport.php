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

    /** @var \Closure(list<string>): RecipientFirstSmtp */
    private \Closure $copySession;

    /**
     * @param (\Closure(list<string>): RecipientFirstSmtp)|null $copySession
     *        The SMTP session a message carrying a copy is sent through —
     *        a seam for tests, which hand a relay that opens no socket.
     */
    public function __construct(?\Closure $copySession = null)
    {
        $this->copySession = $copySession
            ?? static fn (array $recipients): RecipientFirstSmtp => new RecipientFirstSmtp($recipients);
    }

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
     *
     * **And the reverse never reaches the copy.** A refused `To:` with an
     * accepted `Cc:` would still have DATA sent to the copy alone — the
     * unit told of a message the family never got, again from every relay
     * the chain tried and on every retry of the queue. An SMTP message
     * carrying a copy goes through a {@see RecipientFirstSmtp} session,
     * which asks for no copy once the recipient is refused: no DATA, and
     * the failure is the recipient's, as without a copy.
     */
    public function deliver(PHPMailer $mail, MailPurpose $purpose): void
    {
        if ($mail->Mailer === 'smtp' && $mail->getCcAddresses() !== []) {
            $mail->setSMTPInstance(($this->copySession)(
                array_map(static fn (array $address): string => (string) $address[0], $mail->getToAddresses())
            ));
        }

        /** @var array<string, true> $accepted */
        $accepted = [];
        $previousCallback = $mail->action_function;
        // PHPMailer reports each recipient's fate here once the DATA has
        // been accepted, before it throws for the ones that failed. A
        // callback somebody had set is still called.
        $mail->action_function = static function (bool $isSent, array $to, ...$rest) use (
            &$accepted,
            $previousCallback
        ): void {
            foreach ($isSent ? $to : [] as $address) {
                $accepted[strtolower((string) $address[0])] = true;
            }
            self::callIfSet($previousCallback, [$isSent, $to, ...$rest]);
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
     * PHPMailer's `action_function` is declared callable and defaults to
     * `''`, which is not — hence `mixed`, read as PHPMailer reads it.
     *
     * @param list<mixed> $arguments
     */
    private static function callIfSet(mixed $callback, array $arguments): void
    {
        if ($callback !== '' && is_callable($callback)) {
            $callback(...$arguments);
        }
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
