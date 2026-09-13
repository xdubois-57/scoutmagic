<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Point an already-assembled message at one provider
 * (ARCHITECTURE.md §8.106).
 *
 * This is the only place a relay's password is read, and the only place
 * the chain touches the PHPMailer instance at all. Everything else about
 * the message — the From, the envelope Sender, the DKIM block, the
 * prefixed subject, both body halves — was built by `MailService` and is
 * left exactly as it found it: a message the second provider receives is
 * byte for byte the message the first one refused.
 *
 * It is applied AFTER `MailService` has configured its own legacy mode,
 * and overrides it. That ordering is what let this whole mechanism land
 * without touching `MailService::send()` at all: the chain is a
 * transport, transports run last, and the last word on which server to
 * talk to is the one that counts.
 */
final class TransportConfigurator
{
    public function __construct(private ProviderConnections $connections)
    {
    }

    public function apply(PHPMailer $mail, MailProvider $provider): void
    {
        // A previous attempt in this same chain may have left a socket
        // open on another relay. PHPMailer reuses the connection it holds
        // whatever Host now says, so an unclosed one would send the
        // fallback's message through the provider that just failed.
        $mail->smtpClose();

        if ($provider->isLocal()) {
            $mail->isMail();
            $mail->Host = '';
            $mail->Username = '';
            $mail->Password = '';
            $mail->SMTPAuth = false;

            return;
        }

        $mail->isSMTP();
        $mail->Host = $provider->host;
        $mail->Port = $provider->port;
        $mail->Username = $provider->username;
        $mail->Password = $this->connections->password($provider->secretPrefix);
        // A relay with no user is a relay that takes no credentials —
        // an internal smarthost, a submission service authenticating on
        // the IP. Forcing SMTPAuth on would fail it before the first
        // RCPT, which reads as « le relais refuse » rather than as a
        // configuration nobody filled in.
        $mail->SMTPAuth = $provider->username !== '';
        $mail->SMTPSecure = $provider->port === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
    }
}
