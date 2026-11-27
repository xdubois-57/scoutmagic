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

    /**
     * How long a CPE name whose advisory request failed is left alone. A
     * failure is remembered, so a source that keeps failing (an outage, a
     * 429) costs each name one attempt per this period instead of keeping
     * the run « incomplete » and the task re-arming every minute forever.
     */
    public const RETRY_AFTER_FAILURE_SECONDS = 6 * 3600;

    /** Per CPE name, highest scores first; the rest are only counted. */
    public const KEPT_ADVISORIES = 20;

    /**
     * Per CPE name, the identifiers at CVSS ≥ 7 kept beyond the detailed
     * advisories — the notification's subjects, so a new high advisory past
     * the twentieth still makes the set grow. Bounded so the cache stays a
     * settings row; a CPE with more than this many is far past any doubt.
     */
    public const KEPT_HIGH_IDS = 200;

    /**
     * The successive trims a snapshot too large for its row goes through:
     * [advisories listed per line, CVSS ≥ 7 identifiers kept per line],
     * null for « as evaluated ».
     */
    private const SNAPSHOT_TRIMS = [[null, null], [3, null], [0, 50]];

    /** When the full cache does not fit, how many detailed advisories each CPE keeps. */
    private const TRIMMED_ADVISORIES = 5;

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
        $failedAt = array_filter(
            is_array($cache['advisory_failures'] ?? null) ? $cache['advisory_failures'] : [],
            static fn(mixed $at): bool => is_int($at) && $now - $at < self::RETRY_AFTER_FAILURE_SECONDS
        );

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
            if (isset($failedAt[$cpe])) {
                // Failed recently: answered from what is known, without
                // spending the budget nor holding the run open.
                $advisories[$cpe] = self::afterFailure($cached, $now);
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
                $highIds = array_slice(self::highIds($found), 0, self::KEPT_HIGH_IDS);
                $unscored = self::hasUnscored($found);
                $advisories[$cpe] = new AdvisoryLookup(AdvisoryLookup::OK, $kept, count($found), $now, $highIds, $unscored);
                $cachedAdvisories[$cpe] = [
                    'fetched_at' => $now,
                    'total' => count($found),
                    'items' => array_map(static fn(Advisory $a): array => $a->toArray(), $kept),
                    'high_ids' => $highIds,
                    'has_unscored' => $unscored,
                ];
                unset($failedAt[$cpe]);
            } catch (ReferenceSourceUnavailable) {
                $advisories[$cpe] = self::afterFailure($cached, $now);
                $failedAt[$cpe] = $now;
            }
        }

        // Only what this inventory needs is kept: a version the host left
        // has nothing more to say, and keeping it is how the document would
        // creep towards the size of a settings row.
        $cache = [
            'branches' => self::withoutExpired(array_intersect_key($cachedBranches, $families), $now),
            'advisories' => self::withoutExpired(array_intersect_key($cachedAdvisories, array_flip($cpes)), $now),
            'advisory_failures' => array_intersect_key($failedAt, array_flip($cpes)),
        ];
        if (!$this->store->saveCache($cache)) {
            // Still too large: keep fewer details per CPE rather than lose
            // what this run fetched. The identifiers at CVSS ≥ 7, the
            // total and the date survive whole, so the next run neither
            // asks the NVD again nor misses a new high advisory.
            foreach ($cache['advisories'] as $cpe => $entry) {
                $cache['advisories'][$cpe]['items'] = array_slice($entry['items'], 0, self::TRIMMED_ADVISORIES);
            }
            if (!$this->store->saveCache($cache)) {
                foreach ($cache['advisories'] as $cpe => $entry) {
                    $cache['advisories'][$cpe]['items'] = [];
                }
                $this->store->saveCache($cache);
            }
        }

        $snapshot = new HostSecuritySnapshot(
            $now,
            $this->evaluator->evaluate($components, $branches, $advisories, $now),
            $complete
        );
        // The snapshot the page reads must be the one the alerts are run
        // on (review of #1415): when it does not fit its settings row, the
        // detail is trimmed — the advisories listed, then the identifiers
        // kept per line — and the trimmed snapshot is what is returned.
        // One that still does not fit is a failure, not a silent skip: the
        // handler then runs no alert from a snapshot nobody can read.
        foreach (self::SNAPSHOT_TRIMS as [$advisoryDetail, $highIds]) {
            $stored = $advisoryDetail === null ? $snapshot : $snapshot->trimmed($advisoryDetail, $highIds);
            if ($this->store->save($stored)) {
                return $stored;
            }
        }

        throw new \RuntimeException('The host security snapshot does not fit its settings row.');
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

    /** What a CPE name whose source failed reads: the last answer while it is believable, else nothing. */
    private static function afterFailure(?AdvisoryLookup $cached, int $now): AdvisoryLookup
    {
        return $cached !== null && $now - (int) $cached->fetchedAt <= self::MAX_STALE_SECONDS
            ? new AdvisoryLookup(AdvisoryLookup::STALE, $cached->advisories, $cached->total, $cached->fetchedAt, $cached->highIds, $cached->hasUnscored)
            : new AdvisoryLookup(AdvisoryLookup::UNAVAILABLE);
    }

    private static function cachedAdvisories(mixed $entry): ?AdvisoryLookup
    {
        if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null) || !is_array($entry['items'] ?? null)) {
            return null;
        }
        $items = array_values(array_filter(array_map([Advisory::class, 'fromArray'], $entry['items'])));

        $highIds = is_array($entry['high_ids'] ?? null)
            ? array_values(array_filter($entry['high_ids'], 'is_string'))
            : self::highIds($items);

        return new AdvisoryLookup(
            AdvisoryLookup::OK,
            $items,
            is_int($entry['total'] ?? null) ? $entry['total'] : count($items),
            $entry['fetched_at'],
            $highIds,
            is_bool($entry['has_unscored'] ?? null) ? $entry['has_unscored'] : self::hasUnscored($items)
        );
    }

    /** @param list<Advisory> $advisories */
    private static function hasUnscored(array $advisories): bool
    {
        return array_filter($advisories, static fn(Advisory $a): bool => $a->score === null) !== [];
    }

    /**
     * @param list<Advisory> $advisories
     * @return list<string>
     */
    private static function highIds(array $advisories): array
    {
        return array_values(array_map(
            static fn(Advisory $a): string => $a->id,
            array_filter($advisories, static fn(Advisory $a): bool => $a->isHigh())
        ));
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
