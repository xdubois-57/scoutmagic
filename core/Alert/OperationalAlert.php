<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

/**
 * One row of `operational_alerts`: whether a check is currently armed or
 * triggered, and what it last saw.
 *
 * This is the one piece of state the whole mechanism has, and it exists
 * for one reason (`docs/exigences-non-fonctionnelles.md` §4, D1): an alert
 * has a state, not just a threshold. Without it, "disk at 92 %" is
 * announced on every scheduler pass and switched off within days.
 *
 * The deliberate contrast is {@see \Core\Attention\AttentionPoint}, which
 * stores nothing at all and is recomputed on every display. Both are
 * right, for opposite reasons: an attention point disappears because it
 * stopped being true, and needs no memory; an alert must remember that it
 * has already spoken, or it never stops.
 */
final class OperationalAlert
{
    public const STATE_ARMED = 'armed';
    public const STATE_TRIGGERED = 'triggered';

    public function __construct(
        public readonly string $alertKey,
        public readonly string $state,
        public readonly ?string $triggeredAt,
        public readonly ?string $lastNotifiedAt,
        public readonly ?string $lastValue
    ) {
    }

    /**
     * The state a key that has never been recorded is in. A check running
     * for the first time on a healthy site writes nothing at all, so the
     * table stays empty until something is actually wrong — and an absent
     * row and an armed row mean exactly the same thing.
     */
    public static function armed(string $alertKey): self
    {
        return new self($alertKey, self::STATE_ARMED, null, null, null);
    }

    public function isTriggered(): bool
    {
        return $this->state === self::STATE_TRIGGERED;
    }
}
