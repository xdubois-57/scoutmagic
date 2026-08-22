<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

class MailServiceFactory
{
    /**
     * Build a MailService from the secrets/config loaded at boot.
     *
     * @param array<string, string> $secrets
     * @param MailTransportInterface|null $transport Delivery step override; null keeps
     *                                               the default PhpMailerTransport.
     */
    public static function create(
        array $secrets,
        DkimManager $dkimManager,
        ?MailTransportInterface $transport = null
    ): MailService {
        return new MailService(
            mode: $secrets['mail_mode'] ?? 'local',
            fromAddress: $secrets['mail_from_address'] ?? '',
            fromName: $secrets['mail_from_name'] ?? '',
            shortName: $secrets['short_name'] ?? '',
            dkimManager: $dkimManager,
            dkimSelector: $secrets['dkim_selector'] ?? 'mail',
            smtpHost: $secrets['smtp_host'] ?? null,
            smtpPort: isset($secrets['smtp_port']) ? (int) $secrets['smtp_port'] : null,
            smtpUser: $secrets['smtp_user'] ?? null,
            smtpPassword: $secrets['smtp_password'] ?? null,
            transport: $transport ?? new PhpMailerTransport()
        );
    }
}
