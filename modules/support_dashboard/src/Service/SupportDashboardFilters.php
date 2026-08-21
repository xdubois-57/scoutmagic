<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * The dashboard's view state, built from the query string and from nothing
 * else (ARCHITECTURE.md §8.50).
 *
 * **No filter, search, sort, page or period is ever persisted.** No cookie,
 * no local storage, no session, no user preference row — arriving at
 * `/support-dashboard` with no query string always produces the same
 * default view. That is a deliberate product decision (a support dashboard
 * that "remembers" a filter from three weeks ago shows a misleading picture
 * to whoever opens it next) and it has the pleasant side effect of adding
 * nothing at all to the cookie registry.
 */
final class SupportDashboardFilters
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_STALE = 'stale';
    public const STATUS_ALL = 'all';

    public const SORT_LAST_RECEIVED = 'last_received';
    public const SORT_ACTIVE_MEMBERS = 'active_members';
    public const SORT_VERSION = 'version';
    public const SORT_INSTALLED_AT = 'installed_at';
    public const SORT_INSTANCE_URL = 'instance_url';

    public const PER_PAGE = 25;

    /** @var array<int, string> */
    public const SORTABLE = [
        self::SORT_LAST_RECEIVED,
        self::SORT_ACTIVE_MEMBERS,
        self::SORT_VERSION,
        self::SORT_INSTALLED_AT,
        self::SORT_INSTANCE_URL,
    ];

    private function __construct(
        public readonly string $search,
        public readonly string $status,
        public readonly ?string $version,
        public readonly ?string $installationMethod,
        public readonly ?string $autoUpdate,
        public readonly ?string $moduleId,
        public readonly ?bool $moduleEnabled,
        public readonly string $sort,
        public readonly bool $descending,
        public readonly int $page
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $status = self::stringOrNull($query['status'] ?? null) ?? self::STATUS_ACTIVE;
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_STALE, self::STATUS_ALL], true)) {
            $status = self::STATUS_ACTIVE;
        }

        $sort = self::stringOrNull($query['sort'] ?? null) ?? self::SORT_LAST_RECEIVED;
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = self::SORT_LAST_RECEIVED;
        }

        $moduleState = self::stringOrNull($query['module_state'] ?? null);
        $moduleEnabled = match ($moduleState) {
            'enabled' => true,
            'disabled' => false,
            default => null,
        };

        $page = (int) ($query['page'] ?? 1);

        return new self(
            mb_substr(trim((string) ($query['q'] ?? '')), 0, 120),
            $status,
            self::stringOrNull($query['version'] ?? null),
            self::stringOrNull($query['installation_method'] ?? null),
            self::stringOrNull($query['auto_update'] ?? null),
            self::stringOrNull($query['module'] ?? null),
            $moduleEnabled,
            $sort,
            self::stringOrNull($query['dir'] ?? null) !== 'asc',
            max(1, $page)
        );
    }

    public static function defaults(): self
    {
        return self::fromQuery([]);
    }

    /**
     * The query string for the same view with one parameter changed — used
     * by the column headers and the pager, so no link ever silently drops
     * the filters the user is looking at.
     *
     * @param array<string, string|int|null> $overrides
     */
    public function queryString(array $overrides = []): string
    {
        $parameters = array_filter([
            'q' => $this->search !== '' ? $this->search : null,
            'status' => $this->status !== self::STATUS_ACTIVE ? $this->status : null,
            'version' => $this->version,
            'installation_method' => $this->installationMethod,
            'auto_update' => $this->autoUpdate,
            'module' => $this->moduleId,
            'module_state' => match ($this->moduleEnabled) {
                true => 'enabled',
                false => 'disabled',
                null => null,
            },
            'sort' => $this->sort !== self::SORT_LAST_RECEIVED ? $this->sort : null,
            'dir' => $this->descending ? null : 'asc',
            'page' => $this->page > 1 ? $this->page : null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($parameters[$key]);
            } else {
                $parameters[$key] = $value;
            }
        }

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
