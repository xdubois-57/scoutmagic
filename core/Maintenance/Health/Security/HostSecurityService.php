<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * One run of the host security check: consult the reference sources
 * through their cache, evaluate, store the dated snapshot (issue #1358,
 * lot 2).
 *
 * **The cache is what keeps a failing source from erasing a finding.**
 * Every answer is stored with its fetch date. A source that fails is
 * replaced by its last answer for up to {@see MAX_STALE_SECONDS}, and the
 * line says how old it is; past that the half it feeds is « non
 * vérifiable ». In no case does a failure turn into « À jour ».
 *
 * **The NVD is asked at most {@see NVD_REQUESTS_PER_RUN} times per run.**
 * Without a key it accepts five requests per rolling thirty seconds; the
 * inventory dedupes CPE names (the web and the cron usually share every
 * version), and whatever is left is marked `pending` and the snapshot
 * incomplete, which makes the handler run again one minute later rather
 * than in a week. No `sleep()`: the scheduler is the clock.
 */
final class HostSecurityService
{
    /** Re-fetched by each weekly run, served from the cache by a continuation one minute later. */
    public const FRESH_SECONDS = 6 * 86400;

    /** How long a source that stopped answering is still believed, its date shown. */
    public const MAX_STALE_SECONDS = 30 * 86400;

    /** Four, under the NVD's five per thirty seconds even if a run overlaps the previous one's tail. */
    public const NVD_REQUESTS_PER_RUN = 4;

    /** Per CPE name, highest scores first; the rest are only counted. */
    public const KEPT_ADVISORIES = 20;

    public function __construct(
        private readonly HostSecurityStore $store,
        private readonly ReferenceSource $source,
        private readonly HostSecurityEvaluator $evaluator = new HostSecurityEvaluator(),
    ) {
    }

    /**
     * @param list<ObservedComponent> $components
     */
    public function refresh(array $components, int $now): HostSecuritySnapshot
    {
        $cache = $this->store->cache();
        $cachedBranches = is_array($cache['branches'] ?? null) ? $cache['branches'] : [];
        $cachedAdvisories = is_array($cache['advisories'] ?? null) ? $cache['advisories'] : [];

        [$families, $cpes] = $this->needs($components);

        $branches = [];
        foreach ($families as $key => $family) {
            [$branches[$key], $entry] = $this->branchesFor($family, $cachedBranches[$key] ?? null, $now);
            if ($entry !== null) {
                $cachedBranches[$key] = $entry;
            }
        }

        $advisories = [];
        $budget = self::NVD_REQUESTS_PER_RUN;
        $complete = true;
        foreach ($cpes as $cpe) {
            $cached = self::cachedAdvisories($cachedAdvisories[$cpe] ?? null);
            if ($cached !== null && $now - (int) $cached->fetchedAt < self::FRESH_SECONDS) {
                $advisories[$cpe] = $cached;
                continue;
            }
            if ($budget === 0) {
                $complete = false;
                $advisories[$cpe] = new AdvisoryLookup(AdvisoryLookup::PENDING);
                continue;
            }

            $budget--;
            try {
                $found = Advisory::sorted(Advisory::deduplicated($this->source->advisories($cpe)));
                $kept = array_slice($found, 0, self::KEPT_ADVISORIES);
                $advisories[$cpe] = new AdvisoryLookup(AdvisoryLookup::OK, $kept, count($found), $now);
                $cachedAdvisories[$cpe] = [
                    'fetched_at' => $now,
                    'total' => count($found),
                    'items' => array_map(static fn(Advisory $a): array => $a->toArray(), $kept),
                ];
            } catch (ReferenceSourceUnavailable) {
                $advisories[$cpe] = $cached !== null && $now - (int) $cached->fetchedAt <= self::MAX_STALE_SECONDS
                    ? new AdvisoryLookup(AdvisoryLookup::STALE, $cached->advisories, $cached->total, $cached->fetchedAt)
                    : new AdvisoryLookup(AdvisoryLookup::UNAVAILABLE);
            }
        }

        $this->store->saveCache([
            'branches' => self::withoutExpired($cachedBranches, $now),
            'advisories' => self::withoutExpired($cachedAdvisories, $now),
        ]);

        $snapshot = new HostSecuritySnapshot(
            $now,
            $this->evaluator->evaluate($components, $branches, $advisories),
            $complete
        );
        $this->store->save($snapshot);

        return $snapshot;
    }

    /**
     * Which branch sources and which CPE names this inventory needs —
     * each once, however many contexts share it.
     *
     * @param list<ObservedComponent> $components
     * @return array{0: array<string, ComponentFamily>, 1: list<string>}
     */
    private function needs(array $components): array
    {
        $families = [];
        $cpes = [];
        foreach ($components as $component) {
            $family = ComponentFamily::get($component->family);
            if ($family === null || $component->presence !== ObservedComponent::PRESENT || $component->version === null) {
                continue;
            }
            $version = VersionNumber::parse($component->version, $family);
            if ($version === null || $version->isPrerelease()) {
                continue;
            }
            $key = $family->branchSourceKey();
            if ($key !== null) {
                $families[$key] = $family;
            }
            $cpes[$family->cpeName($version)] = true;
        }

        return [$families, array_keys($cpes)];
    }

    /**
     * @return array{0: BranchLookup, 1: array<string, mixed>|null} the lookup, and the cache entry to keep
     */
    private function branchesFor(ComponentFamily $family, mixed $cachedEntry, int $now): array
    {
        $cached = self::cachedBranches($cachedEntry);
        if ($cached !== null && $now - (int) $cached->fetchedAt < self::FRESH_SECONDS) {
            return [$cached, null];
        }

        try {
            $branches = $family->versionSource === ComponentFamily::SOURCE_PHP_NET
                ? $this->source->phpBranches()
                : $this->source->endOfLifeBranches((string) $family->endOfLifeProduct);

            return [
                new BranchLookup(BranchLookup::OK, $branches, $now),
                [
                    'fetched_at' => $now,
                    'items' => array_map(static fn(ReleaseBranch $b): array => $b->toArray(), $branches),
                ],
            ];
        } catch (ReferenceSourceUnavailable) {
            if ($cached !== null && $now - (int) $cached->fetchedAt <= self::MAX_STALE_SECONDS) {
                return [new BranchLookup(BranchLookup::STALE, $cached->branches, $cached->fetchedAt), null];
            }

            return [new BranchLookup(BranchLookup::UNAVAILABLE), null];
        }
    }

    private static function cachedBranches(mixed $entry): ?BranchLookup
    {
        if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null) || !is_array($entry['items'] ?? null)) {
            return null;
        }

        return new BranchLookup(
            BranchLookup::OK,
            array_values(array_filter(array_map([ReleaseBranch::class, 'fromArray'], $entry['items']))),
            $entry['fetched_at']
        );
    }

    private static function cachedAdvisories(mixed $entry): ?AdvisoryLookup
    {
        if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null) || !is_array($entry['items'] ?? null)) {
            return null;
        }
        $items = array_values(array_filter(array_map([Advisory::class, 'fromArray'], $entry['items'])));

        return new AdvisoryLookup(
            AdvisoryLookup::OK,
            $items,
            is_int($entry['total'] ?? null) ? $entry['total'] : count($items),
            $entry['fetched_at']
        );
    }

    /**
     * Drops what is too old to be believed any more, so a version a host
     * left long ago does not stay in the cache for ever.
     *
     * @param array<string, mixed> $entries
     * @return array<string, mixed>
     */
    private static function withoutExpired(array $entries, int $now): array
    {
        return array_filter(
            $entries,
            static fn(mixed $entry): bool => is_array($entry)
                && is_int($entry['fetched_at'] ?? null)
                && $now - $entry['fetched_at'] <= self::MAX_STALE_SECONDS
        );
    }
}
