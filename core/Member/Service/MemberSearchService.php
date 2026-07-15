<?php

declare(strict_types=1);

namespace Core\Member\Service;

use Core\Member\Repository\MemberSearchRepository;

/**
 * Searches decrypted members for a scout year, in memory (data is encrypted at
 * rest). Matching is case- and accent-insensitive across all member fields.
 */
class MemberSearchService
{
    /** @var array<int, MemberSearchResult[]> Per-request cache, keyed by scout year id. */
    private array $cache = [];

    public function __construct(private MemberSearchRepository $repository)
    {
    }

    /**
     * Members of the year whose fields contain the query. Empty query → no results.
     * Sorted by last name then first name.
     *
     * @return MemberSearchResult[]
     */
    public function search(int $scoutYearId, string $query): array
    {
        $needle = self::fold($query);
        if ($needle === '') {
            return [];
        }

        $matched = array_filter(
            $this->allForYear($scoutYearId),
            static fn(MemberSearchResult $m): bool => str_contains(self::fold($m->haystack()), $needle)
        );

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
                return $member;
            }
        }

        return null;
    }

    /**
     * @return MemberSearchResult[]
     */
    private function allForYear(int $scoutYearId): array
    {
        return $this->cache[$scoutYearId] ??= $this->repository->findAllForYear($scoutYearId);
    }

    /**
     * Lower-case and strip diacritics for accent-insensitive comparison.
     */
    public static function fold(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');

        return strtr($lower, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
        ]);
    }
}
