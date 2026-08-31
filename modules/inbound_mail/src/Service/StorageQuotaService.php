<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Service\DateInput;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * How much disk this module may take, and what happens at the ceiling.
 *
 * **The order is fixed and it matters** (D5):
 *
 * 1. **Refuse the write immediately.** The message is stored whole; only
 *    the attachment's bytes are refused, and its row records
 *    `quota_exceeded` so a reader is told rather than left counting
 *    attachments. Refusing first is what makes the rest safe: whatever the
 *    purge below manages to free, the unit does not lose the mail arriving
 *    while it runs.
 * 2. **Purge the oldest messages nothing points at.** Not the retention
 *    purge — that one waits for a message to be old. The disk is full now,
 *    and a message from last week that belongs to nobody is a better thing
 *    to lose than the ability to receive mail at all.
 * 3. **Tell the superadmin**, at most once a day. Only they can raise the
 *    quota or buy space, and a mail per refused attachment on a busy box
 *    would be unsubscribed from within a week — which would leave nobody
 *    told at all.
 *
 * **The check runs before every attachment write, not only at purge time.**
 * The ceiling is reached during a synchronisation, and `poor_mans_cron`
 * only advances on page views: a quota enforced by a nightly task would let
 * a single busy afternoon fill the host's disk.
 */
class StorageQuotaService
{
    public const SETTING_QUOTA_MB = 'inbound_mail_storage_quota_mb';

    /** A7: protects the whole hosting account, not just this module. */
    public const DEFAULT_QUOTA_MB = 500;

    /** How many messages one over-quota event frees at most. */
    public const EMERGENCY_PURGE_BATCH = 50;

    /**
     * The setting that remembers when the superadmin was last told, so the
     * alert stays at most daily.
     */
    public const SETTING_LAST_ALERT = 'inbound_mail_quota_alerted_at';

    public const ALERT_INTERVAL_SECONDS = 86400;

    public function __construct(
        private InboundMessageRepository $messageRepository,
        private SettingService $settings,
        /**
         * Written through the repository rather than `SettingService::set()`
         * because the alert stamp is bookkeeping, not configuration: it is
         * declared non-editable so it never appears on the Réglages page,
         * and `set()` refuses a non-editable setting by design.
         */
        private ?SettingRepository $settingRepository = null,
        private ?JournalService $journal = null,
        /**
         * Called with no argument when the superadmin should be told.
         * A closure rather than `NotificationService` itself so this class
         * stays testable without a mail stack, and so the composition root
         * decides what "tell the superadmin" means.
         *
         * @var (\Closure(int, int): void)|null
         */
        private ?\Closure $alert = null
    ) {
    }

    /**
     * Whether these bytes can still be written.
     *
     * Asked before the write, with the size the write would add — a check
     * made afterwards is a check that has already let the disk fill.
     */
    public function accepts(int $additionalBytes): bool
    {
        $quota = $this->quotaBytes();
        if ($quota <= 0) {
            // 0 or negative means no ceiling. Deliberately expressible: a
            // unit on its own server has no reason to carry one.
            return true;
        }

        return $this->messageRepository->totalStoredBytes() + $additionalBytes <= $quota;
    }

    /**
     * Everything that has to happen once a write has been refused, in the
     * order D5 fixes.
     *
     * Returns the number of messages the emergency purge removed, which is
     * what the caller journals — a count, never an id list and never
     * anything a sender wrote.
     *
     * @param \Closure(int): void $deleteMessage removes one message and its files
     */
    public function handleOverQuota(\Closure $deleteMessage, \DateTimeImmutable $now): int
    {
        $purged = 0;
        foreach ($this->messageRepository->findOldestUnclaimedMessageIds(self::EMERGENCY_PURGE_BATCH) as $messageId) {
            $deleteMessage($messageId);
            $purged++;
        }

        $this->journal?->log(
            'inbound_mail',
            'inbound_quota_exceeded',
            'warning',
            'Quota de stockage du courrier entrant atteint',
            ['purged' => $purged, 'quota_mb' => $this->quotaMb()],
            null
        );

        $this->alertSuperadminOncePerDay($purged, $now);

        return $purged;
    }

    public function quotaMb(): int
    {
        $raw = $this->settings->get(self::SETTING_QUOTA_MB, 'inbound_mail', (string) self::DEFAULT_QUOTA_MB);

        return (int) ($raw ?? self::DEFAULT_QUOTA_MB);
    }

    public function quotaBytes(): int
    {
        return $this->quotaMb() * 1024 * 1024;
    }

    private function alertSuperadminOncePerDay(int $purged, \DateTimeImmutable $now): void
    {
        if ($this->alert === null) {
            return;
        }

        $last = (string) ($this->settings->get(self::SETTING_LAST_ALERT, 'inbound_mail', '') ?? '');
        if ($last !== '') {
            // Through DateInput, the one place this codebase parses a
            // formatted date: PHP's own parser raises a ValueError on a NUL
            // byte instead of returning false, so a `!== false` guard would
            // let that one input through as an uncaught exception.
            $lastAt = DateInput::parse('Y-m-d H:i:s', $last);
            if ($lastAt !== null && $now->getTimestamp() - $lastAt->getTimestamp() < self::ALERT_INTERVAL_SECONDS) {
                return;
            }
        }

        try {
            ($this->alert)($this->quotaMb(), $purged);
        } catch (\Throwable) {
            // A failed notification must not fail a synchronisation. The
            // journal entry above already recorded that the ceiling was
            // reached, which is the durable half of telling somebody.
            return;
        }

        $this->settingRepository?->updateValue('inbound_mail', self::SETTING_LAST_ALERT, $now->format('Y-m-d H:i:s'));
        $this->settings->clearCache();
    }
}
