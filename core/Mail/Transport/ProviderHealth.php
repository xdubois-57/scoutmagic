<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * The circuit breaker for one provider (D15, ARCHITECTURE.md §8.106).
 *
 * After {@see FAILURES_BEFORE_OPEN} consecutive failures that are the
 * provider's own ({@see MailFailure}), it is stepped over for a while —
 * doubling each time it reopens, capped at {@see MAX_MINUTES}. The first
 * success closes it and forgets the count, because a relay that answers
 * is a relay that works, whatever it was doing ten minutes ago.
 *
 * **The rule that overrides everything else: the breaker never empties a
 * lane.** {@see MailTransportChain} tries the last candidate even when
 * its circuit is open. Without that, a breaker still armed after the
 * outage was fixed would lock everybody out of the site — which is the
 * precise failure the whole chain exists to prevent, reintroduced by the
 * thing meant to protect it.
 */
final class ProviderHealth
{
    /**
     * Failures in a row before a provider is stepped over. Three rather
     * than one: a single refusal is ordinary — a relay hiccups, a
     * connection times out — and shutting a provider out on it would make
     * the chain flap.
     */
    public const FAILURES_BEFORE_OPEN = 3;

    /** The first lockout, doubling on each reopen. */
    public const FIRST_MINUTES = 5;

    /**
     * The ceiling. Hours rather than days: past this the useful thing is
     * to try again and find out, and a super-admin who fixed the relay
     * should not have to wait out a penalty measured in days.
     */
    public const MAX_MINUTES = 240;

    public function __construct(
        public readonly int $providerId,
        public readonly int $consecutiveFailures,
        public readonly ?string $openedAt,
        public readonly ?string $openedUntil,
        public readonly int $openCount,
        public readonly string $lastReason
    ) {
    }

    public static function closed(int $providerId): self
    {
        return new self($providerId, 0, null, null, 0, '');
    }

    /**
     * Whether this provider is being stepped over at $now.
     *
     * An `opened_until` in the past is not open: the lockout simply ran
     * out, and nothing needs to have written to the row for that to be
     * true. The alternative — a scheduled task that closes expired
     * circuits — would mean a provider staying shut because a cron did
     * not run, which is the wrong way for this to fail.
     */
    public function isOpen(?string $now = null): bool
    {
        if ($this->openedUntil === null) {
            return false;
        }

        return strtotime($this->openedUntil) > strtotime($now ?? date('Y-m-d H:i:s'));
    }

    /**
     * How long the NEXT lockout lasts, in minutes.
     *
     * Doubling on `open_count` rather than on the failure count: what
     * grows is the penalty for a provider that keeps coming back broken,
     * not for one that failed four times instead of three in a single bad
     * minute.
     */
    public function nextLockoutMinutes(): int
    {
        $minutes = self::FIRST_MINUTES * (2 ** $this->openCount);

        return (int) min($minutes, self::MAX_MINUTES);
    }
}
