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
     * @param array<string, string> $secrets the boot secrets, with the
     *   mail identity keys the settings table owns already merged in by
     *   the composition root — `mail_reply_address` among them, so a
     *   reply address configured on the Authentification sub-page reaches
     *   every send rather than only the ones that name their own.
     * @param MailTransportInterface|null $transport Delivery step override; null keeps
     *                                               the default PhpMailerTransport.
     * @param \Core\Journal\JournalService|null $journal Where a send that fails is written
     *                                               down. Null only for the setup wizard,
     *                                               which may have no database yet.
     * @param Transport\DeferredMailQueue|null $deferred Where a message goes when its
     *                                               whole lane has run out (D9). Null
     *                                               means « fail as before », which is
     *                                               what the setup wizard wants: there
     *                                               is no cron yet to drain anything.
     */
    public static function create(
        array $secrets,
        DkimManager $dkimManager,
        ?MailTransportInterface $transport = null,
        ?\Core\Journal\JournalService $journal = null,
        ?Transport\DeferredMailQueue $deferred = null
    ): MailService {
        return new MailService(
            mode: $secrets['mail_mode'] ?? 'local',
            fromAddress: $secrets['mail_from_address'] ?? '',
            fromName: $secrets['mail_from_name'] ?? '',
            shortName: $secrets['short_name'] ?? '',
            dkimManager: $dkimManager,
            dkimSelector: $secrets['dkim_selector'] ?? 'mail',
            replyAddress: $secrets[MailIdentity::SETTING_REPLY_ADDRESS] ?? '',
            smtpHost: $secrets['smtp_host'] ?? null,
            smtpPort: isset($secrets['smtp_port']) ? (int) $secrets['smtp_port'] : null,
            smtpUser: $secrets['smtp_user'] ?? null,
            smtpPassword: $secrets['smtp_password'] ?? null,
            transport: $transport ?? new PhpMailerTransport(),
            journal: $journal,
            deferred: $deferred
        );
    }
}
