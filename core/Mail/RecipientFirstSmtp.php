<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use PHPMailer\PHPMailer\SMTP;

/**
 * An SMTP session that writes to nobody in copy once the recipient itself
 * has been refused (chantier #843, IT-06).
 *
 * PHPMailer sends the DATA as soon as ONE recipient is accepted. With a
 * `Cc:`, a refused `To:` — a mistyped address on the public form — still
 * put the message in the unit's mailbox, then failed: the provider chain
 * tried the next relay and copied the unit again, a deferred message
 * copied it again on every retry, and the unit believed the family had
 * been told. PHPMailer asks for the `To:` addresses first, so refusing to
 * ask for anything after a refused one leaves no accepted recipient, and
 * therefore no DATA at all: the message fails whole, exactly as it would
 * have without a copy, and the relay's answer about the recipient is the
 * error everybody reads.
 *
 * Installed by {@see PhpMailerTransport} for an SMTP message carrying a
 * copy, and only then.
 */
class RecipientFirstSmtp extends SMTP
{
    private bool $recipientRefused = false;

    /** @var list<string> */
    private array $recipients;

    /**
     * @param list<string> $recipients The `To:` addresses of the message.
     */
    public function __construct(array $recipients)
    {
        $this->recipients = array_map('strtolower', $recipients);
    }

    /**
     * A new transaction: nobody has been refused in it yet.
     *
     * @param string $from
     * @return bool
     */
    public function mail($from)
    {
        $this->recipientRefused = false;

        return $this->openTransaction((string) $from);
    }

    /**
     * Asks the relay — unless the recipient was already refused in this
     * transaction, in which case a copy is not even asked for. The last
     * error stays the relay's answer about the recipient.
     *
     * @param string $address
     * @param string $dsn
     * @return bool
     */
    public function recipient($address, $dsn = '')
    {
        if ($this->recipientRefused) {
            return false;
        }

        $accepted = $this->askRelay((string) $address, (string) $dsn);
        if (!$accepted && in_array(strtolower((string) $address), $this->recipients, true)) {
            $this->recipientRefused = true;
        }

        return $accepted;
    }

    /** `MAIL FROM`, as PHPMailer's own session sends it. */
    protected function openTransaction(string $from): bool
    {
        return parent::mail($from);
    }

    /** `RCPT TO`, as PHPMailer's own session sends it. */
    protected function askRelay(string $address, string $dsn): bool
    {
        return parent::recipient($address, $dsn);
    }
}
