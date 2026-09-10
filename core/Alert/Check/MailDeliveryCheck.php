<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\AlertThresholds;
use Core\Alert\OperationalCheck;
use Core\Journal\JournalRepository;

/**
 * Has sending e-mail stopped working?
 *
 * Counts `mail_send_failed` entries — the one journal line
 * `Core\Mail\MailService::journalFailure()` writes at the single point
 * every send path passes through. That entry exists precisely because
 * every *caller* treats a failed send as non-fatal (`catch (MailException)
 * {}` is the common shape), so a relay that has stopped answering is
 * otherwise invisible from both ends at once.
 *
 * **This is the alert that must not travel by e-mail**, for the obvious
 * reason. {@see \Core\Alert\OperationalAlertService} routes it to
 * `core.operational_alert_mail`, whose e-mail channel is declared `off` —
 * a locked value no preference can turn on — so it reaches its audience
 * in-app and as an attention point instead.
 *
 * One failure is a full mailbox at the other end and is not news. The
 * threshold is a count over a window, so what trips this is a
 * configuration that has stopped working rather than one bad address.
 */
final class MailDeliveryCheck implements OperationalCheck
{
    public const KEY = 'mail_delivery';

    public function __construct(
        private readonly JournalRepository $journal,
        private readonly ?\DateTimeImmutable $now = null
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Envoi d\'e-mails';
    }

    public function read(): AlertReading
    {
        $now = $this->now ?? new \DateTimeImmutable();
        $since = $now
            ->modify('-' . AlertThresholds::MAIL_FAILURE_WINDOW_HOURS . ' hours')
            ->format('Y-m-d H:i:s');

        $failures = $this->journal->countEventsSince('core', 'mail_send_failed', $since);

        return new AlertReading(
            overTrigger: $failures >= AlertThresholds::MAIL_FAILURE_TRIGGER_COUNT,
            underRearm: $failures <= AlertThresholds::MAIL_FAILURE_REARM_COUNT,
            value: $failures . ' échecs',
            title: sprintf('%d envois d\'e-mail ont échoué en 24 heures.', $failures),
            why: 'Les familles ne reçoivent plus les messages du site, et rien ne le signale ailleurs : '
                . 'chaque envoi échoue en silence. Vérifiez les réglages SMTP et envoyez un e-mail de test.',
            actionUrl: '/admin/journal?category=core',
            actionLabel: 'Voir le journal'
        );
    }
}
