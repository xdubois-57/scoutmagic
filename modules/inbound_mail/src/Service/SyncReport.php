<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

/**
 * One synchronisation run across every enabled mailbox.
 *
 * A failing box does not stop the others: each is polled and recorded on
 * its own, so one server being down for a week does not cost the unit the
 * mail arriving in the other two.
 */
class SyncReport
{
    /** @var array<int, SyncOutcome> */
    private array $outcomes = [];

    public function add(int $mailboxId, SyncOutcome $outcome): void
    {
        $this->outcomes[$mailboxId] = $outcome;
    }

    /**
     * @return array<int, SyncOutcome>
     */
    public function all(): array
    {
        return $this->outcomes;
    }

    public function forMailbox(int $mailboxId): ?SyncOutcome
    {
        return $this->outcomes[$mailboxId] ?? null;
    }

    public function totalStored(): int
    {
        return array_sum(array_map(static fn(SyncOutcome $o) => $o->messagesStored, $this->outcomes));
    }

    /**
     * How many messages were read, stored or not. What « Rafraîchir
     * maintenant » tells the operator alongside the stored count: a run
     * that read forty and kept none is a working connection, and a run
     * that read none is not.
     */
    public function totalSeen(): int
    {
        return array_sum(array_map(static fn(SyncOutcome $o) => $o->messagesSeen, $this->outcomes));
    }

    public function failureCount(): int
    {
        return count(array_filter($this->outcomes, static fn(SyncOutcome $o) => $o->isFailure()));
    }
}
