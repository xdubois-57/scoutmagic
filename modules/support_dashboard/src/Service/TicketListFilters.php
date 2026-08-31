<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Modules\SupportDashboard\Repository\SupportTicketRepository;

/**
 * The ticket list's view state, built from the query string and from
 * nothing else — the same posture as `SupportDashboardFilters` beside it
 * (ARCHITECTURE.md §8.50): no cookie, no session, no stored preference,
 * so the same URL always shows the same list and an unfiltered visit is
 * always an unfiltered list.
 */
final class TicketListFilters
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ALL = 'all';

    public const SORT_CREATED = 'created';
    public const SORT_STATUS = 'status';
    public const SORT_CATEGORY = 'category';
    public const SORT_INSTALLATION = 'installation';

    /** @var array<int, string> */
    public const SORTABLE = [
        self::SORT_CREATED,
        self::SORT_STATUS,
        self::SORT_CATEGORY,
        self::SORT_INSTALLATION,
    ];

    private function __construct(
        public readonly string $search,
        public readonly string $status,
        public readonly ?string $category,
        public readonly ?string $installation,
        public readonly string $sort,
        public readonly bool $descending
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $status = self::stringOrNull($query['status'] ?? null) ?? self::STATUS_OPEN;
        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_ALL], true)) {
            $status = self::STATUS_OPEN;
        }

        $sort = self::stringOrNull($query['sort'] ?? null) ?? self::SORT_CREATED;
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = self::SORT_CREATED;
        }

        return new self(
            trim((string) (self::stringOrNull($query['q'] ?? null) ?? '')),
            $status,
            self::stringOrNull($query['category'] ?? null),
            self::stringOrNull($query['installation'] ?? null),
            $sort,
            // Newest first is the only default that makes sense for a
            // support queue; the other sorts follow the same direction
            // flag so one control governs all of them.
            ($query['dir'] ?? 'desc') !== 'asc'
        );
    }

    /** Whether this ticket's status passes the status filter. */
    public function acceptsStatus(string $ticketStatus): bool
    {
        return match ($this->status) {
            self::STATUS_OPEN => $ticketStatus === SupportTicketRepository::STATUS_OPEN,
            self::STATUS_CLOSED => $ticketStatus === SupportTicketRepository::STATUS_CLOSED,
            default => true,
        };
    }

    /**
     * The query string that reproduces this view, so a link can change one
     * facet without dropping the others.
     *
     * @param array<string, string|null> $overrides
     */
    public function queryString(array $overrides = []): string
    {
        $parameters = array_filter([
            'q' => $this->search !== '' ? $this->search : null,
            'status' => $this->status !== self::STATUS_OPEN ? $this->status : null,
            'category' => $this->category,
            'installation' => $this->installation,
            'sort' => $this->sort !== self::SORT_CREATED ? $this->sort : null,
            'dir' => $this->descending ? null : 'asc',
            ...$overrides,
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        return $parameters === [] ? '' : '?' . http_build_query($parameters);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
