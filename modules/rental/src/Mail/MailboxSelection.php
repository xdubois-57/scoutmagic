<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Core\Config\SettingService;
use Modules\InboundMail\Api\InboundMailInterface;

/**
 * Which of the unit's mailboxes this module listens to (§7.4).
 *
 * **What a manager sees here is a name and a state, and nothing else.** The
 * host, the port, the account and the password belong to the superadmin
 * page and never leave it — `InboundMailInterface::listMailboxSummaries()`
 * is the whole of what crosses the boundary, which is why the selection
 * cannot accidentally become a way to read the mailbox configuration.
 *
 * **Empty means every mailbox**, deliberately. Most units have one box, and
 * asking them to tick it before anything works would be a configuration
 * step whose only sensible answer is "yes" — and one whose omission would
 * look exactly like a broken sync.
 */
class MailboxSelection
{
    public const SETTING_KEY = 'inbound_mailbox_ids';
    private const MODULE = 'rental';

    public function __construct(
        private SettingService $settingService,
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->inboundMail !== null;
    }

    /**
     * The selected mailbox ids, or an empty array meaning "all of them".
     *
     * @return int[]
     */
    public function selectedIds(): array
    {
        $raw = (string) ($this->settingService->get(self::SETTING_KEY, self::MODULE) ?: '');
        if (trim($raw) === '') {
            return [];
        }

        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $raw)), static fn(string $v) => $v !== ''));

        return array_values(array_unique(array_filter($ids, static fn(int $id) => $id > 0)));
    }

    /**
     * @param int[] $mailboxIds
     */
    public function save(array $mailboxIds): void
    {
        $available = array_keys($this->availableMailboxes());

        // Filtered against what actually exists rather than stored as
        // submitted: a stale id from a deleted box would silently narrow
        // the selection to nothing a manager could see or undo.
        $valid = array_values(array_unique(array_filter(
            array_map('intval', $mailboxIds),
            static fn(int $id) => in_array($id, $available, true)
        )));

        $this->settingService->set(self::SETTING_KEY, implode(',', $valid), self::MODULE);
    }

    /**
     * The boxes a manager may choose from, as name and state only.
     *
     * @return array<int, array{name: string, state: string, is_enabled: bool}>
     */
    public function availableMailboxes(): array
    {
        return $this->inboundMail?->listMailboxSummaries() ?? [];
    }
}
