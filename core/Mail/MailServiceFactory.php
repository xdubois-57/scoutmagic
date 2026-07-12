<?php

declare(strict_types=1);

namespace Core\Mail;

class MailServiceFactory
{
    /**
     * Build a MailService from the secrets/config loaded at boot.
     *
     * @param array<string, string> $secrets
     */
    public static function create(array $secrets, DkimManager $dkimManager): MailService
    {
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
            smtpPassword: $secrets['smtp_password'] ?? null
        );
    }
}
