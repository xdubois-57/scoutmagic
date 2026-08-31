<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Service\DateInput;

/**
 * « Rafraîchir maintenant » — a synchronisation inside the request.
 *
 * It exists because the scheduled one runs every quarter of an hour on a
 * page view, and a superadmin who has just typed a password wants to know
 * within seconds whether it works, not whether it will have worked by
 * lunchtime.
 *
 * **Behind a lock, and the lock is the whole design.** Two clicks a second
 * apart would open two IMAP sessions on the same box, fetch the same
 * messages twice and race each other on the cursor — and the loser's write
 * would move the cursor backwards, so the next scheduled run would re-read
 * what had already been read. The lock is a setting rather than a table:
 * one row, no schema, and readable from the scheduled path as well.
 *
 * **The lock expires.** A request killed by `max_execution_time` mid-sync
 * never clears it, and a permanently locked button is a feature that
 * silently stopped existing. Ten minutes is comfortably longer than a
 * bounded batch takes and short enough that nobody waits on it.
 */
class ManualRefreshService
{
    public const SETTING_LOCK = 'inbound_mail_refresh_started_at';

    /** After this, a lock is assumed to belong to a request that died. */
    public const LOCK_TTL_SECONDS = 600;

    public function __construct(
        /**
         * A closure rather than the service, and that is load-bearing:
         * building a synchronisation graph is the one thing an ordinary
         * page view must never do, and this class is constructed on every
         * one of them so that the button can exist. The graph is assembled
         * when — and only when — somebody presses it.
         *
         * @var \Closure(): MailboxSyncService
         */
        private \Closure $syncServiceFactory,
        private SettingService $settings,
        private SettingRepository $settingRepository
    ) {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function refresh(\DateTimeImmutable $now): array
    {
        if ($this->isLocked($now)) {
            return [
                'ok' => false,
                'message' => 'Un rafraîchissement est déjà en cours. Patientez quelques instants.',
            ];
        }

        $this->lock($now);

        try {
            $report = ($this->syncServiceFactory)()->syncAll($now);
        } finally {
            // Released whatever happened: a failed synchronisation has
            // already recorded its reason on the mailbox row, and leaving
            // the button locked on top of that would hide the one action
            // that lets somebody retry.
            $this->unlock();
        }

        return [
            'ok' => true,
            'message' => sprintf(
                '%d message(s) lu(s), %d conservé(s).',
                $report->totalSeen(),
                $report->totalStored()
            ),
        ];
    }

    public function isLocked(\DateTimeImmutable $now): bool
    {
        $raw = trim((string) ($this->settings->get(self::SETTING_LOCK, 'inbound_mail', '') ?? ''));
        if ($raw === '') {
            return false;
        }

        $startedAt = DateInput::parse('Y-m-d H:i:s', $raw);
        if ($startedAt === null) {
            return false;
        }

        return $now->getTimestamp() - $startedAt->getTimestamp() < self::LOCK_TTL_SECONDS;
    }

    private function lock(\DateTimeImmutable $now): void
    {
        $this->settingRepository->updateValue('inbound_mail', self::SETTING_LOCK, $now->format('Y-m-d H:i:s'));
        $this->settings->clearCache();
    }

    private function unlock(): void
    {
        $this->settingRepository->updateValue('inbound_mail', self::SETTING_LOCK, '');
        $this->settings->clearCache();
    }
}
