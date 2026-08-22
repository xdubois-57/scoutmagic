<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The delivery step of MailService::send(), and only that step
 * (ARCHITECTURE.md §8.7).
 *
 * The instance handed over is already fully configured: transport mode,
 * From and envelope Sender, recipient, Reply-To, attachments, custom
 * headers, DKIM parameters, prefixed subject and multipart body. An
 * implementation decides what "deliver" means — the default one
 * (PhpMailerTransport) calls send(); modules/test_tools' CaptureTransport
 * assembles the message and stores it instead of putting it on the wire.
 *
 * Nothing else belongs here. Building the message stays in MailService, so
 * a captured mail is byte-for-byte the mail that would have gone out.
 */
interface MailTransportInterface
{
    /**
     * Deliver an already-configured message.
     *
     * @throws \Exception MailService wraps anything thrown into a MailException,
     *                    exactly as it does for the default transport.
     */
    public function deliver(PHPMailer $mail): void;
}
