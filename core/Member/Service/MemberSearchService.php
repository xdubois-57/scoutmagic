<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Service;

use Core\Config\ScoutYearService;
use Core\Member\Repository\MemberSearchRepository;
use Core\Service\TextNormalizerService;

/**
 * Searches decrypted members for a scout year, in memory (data is encrypted at
 * rest). Matching is case- and accent-insensitive across all member fields.
 *
 * **The cost of that is what shapes this class's API.** Every search loads
 * and decrypts a whole scout year in PHP — there is no blind index on
 * names, so there is nothing to filter on in SQL. At a few hundred members
 * that is fine. Across five scout years it is five times the AES work, on
 * every keystroke, which is why searching the past years is a separate,
 * explicitly-called method rather than a flag on the ordinary one: the
 * page asks for it only when a chef d'unité presses a button.
 */
class MemberSearchService
{
    /** Membership scopes the search page offers. */
    public const SCOPE_ACTIVE = 'active';
    public const SCOPE_INACTIVE = 'inactive';
    public const SCOPE_ALL = 'all';

    /** @var string[] */
    public const SCOPES = [self::SCOPE_ACTIVE, self::SCOPE_INACTIVE, self::SCOPE_ALL];

    /** @var array<int, MemberSearchResult[]> Per-request cache, keyed by scout year id. */
    private array $cache = [];

    /** @var array<int, true> the years whose cached rows already carry their addresses */
    private array $cacheWithAddresses = [];

    public function __construct(
        private MemberSearchRepository $repository,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * Normalises whatever arrived in `?scope=` — an unknown value falls
     * back to the default rather than being treated as "all", so a typo
     * or a stale bookmark never quietly widens the list.
     */
    public static function normalizeScope(?string $scope): string
    {
        return in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_ACTIVE;
    }

    /**
     * Members of the year whose fields contain the query. Empty query → no results.
     * Sorted by last name then first name.
     *
     * @return MemberSearchResult[]
     */
    public function search(int $scoutYearId, string $query, string $scope = self::SCOPE_ACTIVE): array
    {
        return $this->sortByName($this->matchForYear($scoutYearId, $query, $scope));
    }

    /**
     * Match against the year's rows WITHOUT their addresses first — a
     * search is nearly always a name, and an address is seven decryptions
     * per row for the whole year — then fetch the addresses of the rows
     * that matched, so the result still shows them. Only when nothing at
     * all matched is the address pass run over everybody, once, and kept
     * for the rest of the request.
     *
     * @return MemberSearchResult[]
     */
    private function matchForYear(int $scoutYearId, string $query, string $scope): array
    {
        $matched = $this->matchIn($this->allForYear($scoutYearId), $query, $scope);
        if ($matched !== []) {
            return $this->withAddresses($scoutYearId, $matched);
        }

        return $this->matchIn($this->allForYearWithAddresses($scoutYearId), $query, $scope);
    }

    /**
     * @param MemberSearchResult[] $rows
     * @return MemberSearchResult[]
     */
    private function withAddresses(int $scoutYearId, array $rows): array
    {
        if (isset($this->cacheWithAddresses[$scoutYearId])) {
            return $rows;
        }
        $addresses = $this->repository->findAddressTexts(
            array_map(static fn(MemberSearchResult $r): int => $r->memberYearId, $rows)
        );

        return array_map(
            static fn(MemberSearchResult $r): MemberSearchResult => $r->withAddress($addresses[$r->memberYearId] ?? null),
            $rows
        );
    }

    /**
     * The same search widened to every scout year in the database,
     * grouped so each person is one row.
     *
     * Deliberately its own method, and deliberately not reachable from a
     * keystroke: see the class docblock. `$effectiveScoutYearId` is not a
     * filter — it is only what decides whether a person counts as a
     * former member, i.e. whether none of their matched years is the one
     * the site is currently showing.
     *
     * @return GroupedMemberSearchResult[]
     */
    public function searchAllYears(string $query, string $scope, int $effectiveScoutYearId): array
    {
        $matched = [];
        foreach ($this->scoutYearService->getAll() as $year) {
            // (matchForYear() rather than matchIn(allForYear()), for the
            // same address economy year after year)
            $matched = [...$matched, ...$this->matchForYear((int) $year['id'], $query, $scope)];
        }

        return $this->groupByMember($matched, $effectiveScoutYearId);
    }

    /**
     * One scout year's matches, grouped the same way — so the page renders
     * one kind of row whether or not the search was widened, and a member
     * whose year carries two rows for them still reads as one person.
     *
     * @return GroupedMemberSearchResult[]
     */
    public function searchGrouped(int $scoutYearId, string $query, string $scope, int $effectiveScoutYearId): array
    {
        return $this->groupByMember($this->matchIn($this->allForYear($scoutYearId), $query, $scope),
            $effectiveScoutYearId);
    }

    /**
     * @param MemberSearchResult[] $pool
     * @return MemberSearchResult[]
     */
    private function matchIn(array $pool, string $query, string $scope): array
    {
        $needle = self::fold($query);
        if ($needle === '') {
            return [];
        }
        $scope = self::normalizeScope($scope);

        return array_values(array_filter($pool, static function (MemberSearchResult $m) use ($needle, $scope): bool {
            if ($scope === self::SCOPE_ACTIVE && !$m->isActive) {
                return false;
            }
            if ($scope === self::SCOPE_INACTIVE && $m->isActive) {
                return false;
            }

            return str_contains(self::fold($m->haystack()), $needle);
        }));
    }

    /**
     * @param MemberSearchResult[] $matched
     * @return GroupedMemberSearchResult[]
     */
    private function groupByMember(array $matched, int $effectiveScoutYearId): array
    {
        /** @var array<int, MemberSearchResult[]> $byMember */
        $byMember = [];
        foreach ($matched as $result) {
            $byMember[$result->memberId][] = $result;
        }

        $grouped = [];
        foreach ($byMember as $memberId => $rows) {
            // Most recent year first — the row displays the latest one,
            // since somebody looking up a former member wants their last
            // known section and status, not the year the query matched.
            //
            // Ordered on the year's start_date, never on its id: an
            // ensureYear() call can create a past year after a later one,
            // so the ids are not chronological and sorting on them shows
            // the wrong year's data under the right person's name.
            usort(
                $rows,
                static fn(MemberSearchResult $a, MemberSearchResult $b): int
                    => $b->scoutYearStartDate <=> $a->scoutYearStartDate
            );

            $labels = [];
            $isFormer = true;
            foreach ($rows as $row) {
                if ($row->scoutYearLabel !== '' && !in_array($row->scoutYearLabel, $labels, true)) {
                    $labels[] = $row->scoutYearLabel;
                }
                if ($row->scoutYearId === $effectiveScoutYearId) {
                    $isFormer = false;
                }
            }

            $grouped[] = new GroupedMemberSearchResult($memberId, $rows[0], $labels, $isFormer);
        }

        usort(
            $grouped,
            static fn(GroupedMemberSearchResult $a, GroupedMemberSearchResult $b): int =>
                [self::fold($a->latest->lastName), self::fold($a->latest->firstName)]
                <=> [self::fold($b->latest->lastName), self::fold($b->latest->firstName)]
        );

        return $grouped;
    }

    /**
     * @param MemberSearchResult[] $matched
     * @return MemberSearchResult[]
     */
    private function sortByName(array $matched): array
    {
        usort(
            $matched,
            static fn(MemberSearchResult $a, MemberSearchResult $b): int =>
                [self::fold($a->lastName), self::fold($a->firstName)]
                <=> [self::fold($b->lastName), self::fold($b->firstName)]
        );

        return array_values($matched);
    }

    /**
     * Find a member of the year by its member_year id, or null if it does not
     * belong to that year.
     */
    public function findById(int $scoutYearId, int $memberYearId): ?MemberSearchResult
    {
        foreach ($this->allForYear($scoutYearId) as $member) {
            if ($member->memberYearId === $memberYearId) {
                return $this->withAddresses($scoutYearId, [$member])[0];
            }
        }

        return null;
    }

    /**
     * @return MemberSearchResult[]
     */
    private function allForYear(int $scoutYearId): array
    {
        if (!isset($this->cache[$scoutYearId])) {
            $this->cache[$scoutYearId] = $this->loadYear($scoutYearId, withAddresses: false);
        }

        return $this->cache[$scoutYearId];
    }

    /**
     * The address pass: every row of the year with its address, loaded
     * once and then used for everything (matching and display alike).
     *
     * @return MemberSearchResult[]
     */
    private function allForYearWithAddresses(int $scoutYearId): array
    {
        if (!isset($this->cacheWithAddresses[$scoutYearId])) {
            $this->cacheWithAddresses[$scoutYearId] = true;
            $this->cache[$scoutYearId] = $this->loadYear($scoutYearId, withAddresses: true);
        }

        return $this->cache[$scoutYearId];
    }

    /**
     * @return MemberSearchResult[]
     */
    private function loadYear(int $scoutYearId, bool $withAddresses): array
    {
        $year = $this->scoutYearService->findById($scoutYearId);

        return $this->repository->findAllForYear(
            $scoutYearId,
            (string) ($year['label'] ?? ''),
            (string) ($year['start_date'] ?? ''),
            $withAddresses
        );
    }

    /**
     * Lower-case, accent-fold and collapse punctuation for
     * accent-insensitive comparison — `Core\Service\TextNormalizerService`
     * holds the one implementation the whole site shares.
     */
    public static function fold(string $value): string
    {
        return TextNormalizerService::fold($value);
    }
}
