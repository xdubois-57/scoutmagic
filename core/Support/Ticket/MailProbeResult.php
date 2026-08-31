<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

/**
 * What one press of « Tester l'acheminement » came to (roadmap IT-27).
 *
 * The counts are deliberately separate: « 3 boîtes, 2 messages partis »
 * is a diagnosis on its own — the local mail configuration reached two
 * relays and failed on the third — and « 3 sur 3 » with nothing ever
 * arriving is a different one entirely.
 */
final class MailProbeResult
{
    /**
     * @param string|null $correlationKey the key the receiver issued, so
     *        the page can name what to look for; null on any failure
     * @param string|null $failureReason one of MailProbeSender's reasons
     */
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $correlationKey,
        public readonly int $addressCount,
        public readonly int $deliveredCount,
        public readonly ?string $failureReason
    ) {
    }

    public static function sent(string $correlationKey, int $addressCount, int $deliveredCount): self
    {
        return new self(true, $correlationKey, $addressCount, $deliveredCount, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, 0, 0, $reason);
    }
}
