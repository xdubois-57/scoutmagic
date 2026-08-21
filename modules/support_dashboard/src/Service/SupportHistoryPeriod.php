<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * The history section's period selector: 6, 12, 24 months or everything,
 * defaulting to 12 (ARCHITECTURE.md §8.51).
 *
 * Like every other piece of view state on this page it lives in the query
 * string and is **not persisted between visits** — no cookie, no local
 * storage, no session. It is deliberately its own type rather than another
 * field on SupportDashboardFilters: the history is independent of the
 * current-state filters, and sharing one object is how that independence
 * quietly stops being true.
 */
final class SupportHistoryPeriod
{
    public const DEFAULT_MONTHS = 12;

    /** @var array<int, int> */
    public const CHOICES = [6, 12, 24];

    /** Null means the whole history. */
    private function __construct(public readonly ?int $months)
    {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $raw = $query['history'] ?? null;

        if (is_string($raw) && strtolower(trim($raw)) === 'all') {
            return new self(null);
        }

        $months = is_string($raw) || is_int($raw) ? (int) $raw : 0;

        return new self(in_array($months, self::CHOICES, true) ? $months : self::DEFAULT_MONTHS);
    }

    public static function default(): self
    {
        return self::fromQuery([]);
    }

    /** The query-string value that reproduces this period. */
    public function value(): string
    {
        return $this->months === null ? 'all' : (string) $this->months;
    }

    public function label(): string
    {
        return $this->months === null ? "Tout l'historique" : $this->months . ' mois';
    }
}
