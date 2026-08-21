<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

/**
 * What one mailbox's synchronisation did.
 *
 * `$stored` and `$seen` are counts and nothing else: no subject, no
 * address, no Message-ID. This object reaches a task log and a page, and
 * neither is a place for the content of somebody's mail (§7.9).
 */
class SyncOutcome
{
    private function __construct(
        public readonly bool $connected,
        public readonly int $messagesSeen,
        public readonly int $messagesStored,
        public readonly ?string $reason = null
    ) {
    }

    public static function succeeded(int $seen, int $stored): self
    {
        return new self(true, $seen, $stored);
    }

    public static function failed(string $reason): self
    {
        return new self(false, 0, 0, $reason);
    }

    /**
     * Nothing was attempted, and that is not a failure — no consumer to
     * claim anything, or no credentials on the box yet.
     */
    public static function skipped(string $reason): self
    {
        return new self(false, 0, 0, $reason);
    }

    public function isFailure(): bool
    {
        return !$this->connected && $this->reason !== null;
    }

    /**
     * The messages read and discarded because nobody claimed them (§7.6) —
     * a number worth having, since a box where it is always equal to
     * `messagesSeen` is one whose consumer is not recognising anything.
     */
    public function messagesDiscarded(): int
    {
        return max(0, $this->messagesSeen - $this->messagesStored);
    }
}
