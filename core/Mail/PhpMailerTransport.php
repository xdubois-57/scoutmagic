<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

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
     * The purpose is ignored here, and deliberately so: putting a message
     * on the wire is the same operation whatever it says. Only a transport
     * that does something OTHER than send has a reason to look at it.
     */
    public function deliver(PHPMailer $mail, MailPurpose $purpose): void
    {
        $mail->send();
    }
}
