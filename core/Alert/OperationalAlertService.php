<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

use Core\Journal\JournalService;
use Core\Notification\NotificationService;

/**
 * The armed/triggered state machine, run once for every operational check
 * (`docs/exigences-non-fonctionnelles.md` §4).
 *
 * **A notification goes out on the armed → triggered transition, and at
 * that moment only.** That single sentence is the whole design. A check
 * that announced « disque à 92 % » on every scheduler pass would be
 * switched off within three days, and would then not exist on the day it
 * mattered.
 *
 * **There is no "back to normal" notification.** Re-arming is silent. A
 * site that recovers on its own does not need to be told, and the message
 * would double the volume of a channel whose value depends entirely on
 * being rare.
 *
 * The notification and the attention point (§8.79,
 * {@see OperationalAttentionProvider}) read the same row, written by the
 * same run. They are not two features: the notification says « this has
 * just tipped over », the attention point says « this is still true ».
 */
final class OperationalAlertService
{
    /** The ordinary alert: in-app, push, and above all e-mail. */
    public const TYPE_DEFAULT = 'core.operational_alert';

    /**
     * The one alert that must not travel by e-mail, because it is about
     * e-mail not working. Declared with `email => 'off'`, a locked value
     * no preference can turn on.
     */
    public const TYPE_MAIL = 'core.operational_alert_mail';

    /** Keys whose notification uses {@see TYPE_MAIL}. */
    private const MAIL_KEYS = [Check\MailDeliveryCheck::KEY];

    public function __construct(
        private readonly OperationalAlertRepository $repository,
        private readonly ?NotificationService $notifications = null,
        private readonly ?JournalService $journal = null
    ) {
    }

    /**
     * Runs every check given, and returns how many alerts newly tripped.
     *
     * One check's failure never stops the others: a check that throws is
     * journaled and skipped, exactly as {@see \Core\Attention\
     * AttentionService} does for its providers. A site whose disk check is
     * broken must still be told its cron has stopped.
     *
     * @param OperationalCheck[] $checks
     */
    public function run(array $checks): int
    {
        $triggered = 0;
        foreach ($checks as $check) {
            try {
                if ($this->evaluate($check)) {
                    $triggered++;
                }
            } catch (\Throwable $e) {
                $this->journal?->log(
                    'core',
                    'operational_check_failed',
                    'warning',
                    'Un contrôle opérationnel n\'a pas pu s\'exécuter',
                    ['check' => $check->key(), 'error' => $e->getMessage()]
                );
            }
        }

        return $triggered;
    }

    /**
     * One check, one decision. Returns true only when this call is the one
     * that moved the alert from armed to triggered.
     */
    public function evaluate(OperationalCheck $check): bool
    {
        $reading = $check->read();
        $stored = $this->repository->findOrArmed($check->key());

        if (!$stored->isTriggered() && $reading->overTrigger) {
            $this->repository->markTriggered($check->key(), $reading->value);
            $this->journal?->log(
                'core',
                'operational_alert_triggered',
                'warning',
                $reading->title,
                ['check' => $check->key(), 'value' => $reading->value]
            );
            $this->notify($check, $reading);

            return true;
        }

        if ($stored->isTriggered() && $reading->underRearm) {
            // Silent on purpose — see this class's docblock.
            $this->repository->markArmed($check->key(), $reading->value);

            return false;
        }

        // Between the two thresholds, or simply healthy. The state does
        // not move; only what it last saw does.
        $this->repository->recordValue($check->key(), $reading->value);

        return false;
    }

    /**
     * Announces to every super-admin.
     *
     * `recipientsForType()` resolves the audience from the type's own
     * `role_min` and re-checks each recipient's CURRENT role, so the
     * restriction holds without any hand-filtering here — and without a
     * second way of asking "who are the super-admins", which would be one
     * more thing to keep in step with the role resolver.
     */
    private function notify(OperationalCheck $check, AlertReading $reading): void
    {
        if ($this->notifications === null) {
            return;
        }

        $typeId = in_array($check->key(), self::MAIL_KEYS, true) ? self::TYPE_MAIL : self::TYPE_DEFAULT;
        $recipients = $this->notifications->recipientsForType($typeId);
        if ($recipients === []) {
            return;
        }

        $this->notifications->dispatch($typeId, $recipients, [
            'title' => $reading->title,
            'body' => $reading->why,
            'url' => $reading->actionUrl ?? '/config/maintenance',
        ]);
    }
}
