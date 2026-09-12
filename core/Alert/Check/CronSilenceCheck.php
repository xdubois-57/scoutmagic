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
use Core\Scheduler\CronHealth;

/**
 * Has the real cron stopped running?
 *
 * **This is the one check that cannot live in the scheduled task**, and
 * the reason is the check itself: if the cron has stopped, the task never
 * runs, and an alert that lives inside it never fires. It is evaluated on
 * an ordinary web request instead — see `public/index.php`, where it runs
 * behind its own throttle.
 *
 * The signal is `cron_last_run`, which only `public/cron.php` stamps.
 * Never `scheduler_last_run`, which the poor man's cron writes on every
 * page view and which therefore says nothing at all about whether a
 * crontab exists (ARCHITECTURE.md §8.24). {@see CronHealth} already reads
 * the right one, together with the heartbeat file, and already knows the
 * difference between "never ran" and "ran and stopped".
 *
 * A site that has never seen a cron pass is left inconclusive rather than
 * triggered, and that is the one deliberate softness here: the setup
 * wizard refuses to finish without a crontab and says so far more usefully
 * than a notification would, and an installation mid-setup has no
 * super-admin to notify yet.
 *
 * **This is also the check that could not reach anybody off-site, and the
 * limit was structural.** `Core\Notification\NotificationService::
 * dispatch()` used to queue push and e-mail without exception —
 * `core/send_notifications` and `core/send_notification_emails` — and that
 * queue is drained by `public/cron.php`, the very cron being reported
 * dead. The two channels that exist for an administrator who is NOT
 * visiting waited behind the failure they described, and arrived, if at
 * all, as old news about a cron somebody had already repaired by hand.
 *
 * Closed by issue #296, and closed in the notification layer rather than
 * here: `core.operational_alert` declares
 * `NotificationType::$deliversImmediately`, so this alert's push and mail
 * leave during the dispatch itself. Nothing in `Core\Alert` sends
 * anything — a second way to send a notification, outside the registry
 * that decides channels and recipients, is exactly the kind of shortcut
 * that outlives its excuse.
 *
 * What that buys is bounded, and worth stating. The e-mail leaves from
 * the request that ran this check, so it needs a working mail transport
 * and nothing else; a push held back by the recipient's quiet hours is
 * still scheduled, and still waits for the cron. The in-app notification
 * and the attention point work throughout — and somebody IS on the site,
 * since that is what made the check run.
 */
final class CronSilenceCheck implements OperationalCheck
{
    public const KEY = 'cron_silent';

    public function __construct(
        private readonly CronHealth $cronHealth,
        private readonly ?\DateTimeImmutable $now = null
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Tâche planifiée';
    }

    public function read(): AlertReading
    {
        $status = $this->cronHealth->status($this->now?->getTimestamp());
        $seconds = $status->secondsSinceLastSeen();

        if ($seconds === null) {
            return AlertReading::inconclusive('jamais');
        }

        $hours = (int) floor($seconds / 3600);

        return new AlertReading(
            overTrigger: $hours >= AlertThresholds::CRON_SILENT_TRIGGER_HOURS,
            underRearm: $hours < AlertThresholds::CRON_SILENT_REARM_HOURS,
            value: $hours . ' h',
            title: sprintf('La tâche planifiée ne s\'est plus exécutée depuis %d heures.', $hours),
            why: 'C\'est le moteur du site : sans elle, aucune sauvegarde automatique, aucun e-mail '
                . 'différé, aucun rappel ne part. Vérifiez la tâche cron chez votre hébergeur — la page '
                . 'Maintenance affiche la ligne exacte à configurer.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir l\'état du cron'
        );
    }
}
