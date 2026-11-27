<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * One security advisory, with its score exactly as its source gave it.
 *
 * **A missing score stays missing.** `score` is null when the source
 * publishes none or one this code cannot read, and null is never zero:
 * an advisory nobody has scored yet is « gravité inconnue », not
 * « sans gravité », and {@see isHigh()} answers false for it while the
 * evaluator counts it as an uncertainty.
 */
final class Advisory
{
    /** The agreed threshold, inclusive: 7.0 counts, 6.9 does not (issue #1358). */
    public const HIGH_THRESHOLD_TENTHS = 70;

    public function __construct(
        /** CVE-2026-12345 — the deduplication key across sources and contexts. */
        public readonly string $id,
        public readonly ?float $score,
        /** « 3.1 », « 4.0 » — a score means nothing without its version. */
        public readonly ?string $cvssVersion,
        /** Who scored it: « nvd@nist.gov », a CNA's address. */
        public readonly ?string $scoreSource,
        public readonly string $url,
    ) {
    }

    /**
     * CVSS ≥ 7.0, compared in tenths so that 6.9 can never round its way
     * across the line through a float.
     */
    public function isHigh(): bool
    {
        return $this->score !== null && (int) round($this->score * 10) >= self::HIGH_THRESHOLD_TENTHS;
    }

    /** « 9,8 (CVSS 3.1, nvd@nist.gov) » or « gravité inconnue ». */
    public function severityLabel(): string
    {
        if ($this->score === null) {
            return 'gravité inconnue';
        }

        $details = array_filter([
            $this->cvssVersion !== null ? 'CVSS ' . $this->cvssVersion : 'CVSS',
            $this->scoreSource,
        ]);

        return number_format($this->score, 1, ',', '') . ' (' . implode(', ', $details) . ')';
    }

    /** @return array{id: string, score: ?float, cvss_version: ?string, score_source: ?string, url: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'cvss_version' => $this->cvssVersion,
            'score_source' => $this->scoreSource,
            'url' => $this->url,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['id'] ?? null) || $data['id'] === '') {
            return null;
        }
        $string = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $score = $data['score'] ?? null;

        return new self(
            $data['id'],
            is_int($score) || is_float($score) ? (float) $score : null,
            $string($data['cvss_version'] ?? null),
            $string($data['score_source'] ?? null),
            $string($data['url'] ?? null) ?? 'https://nvd.nist.gov/vuln/detail/' . rawurlencode($data['id'])
        );
    }

    /**
     * Highest score first, unknown scores last, then by identifier — one
     * order for every list this check prints or truncates.
     *
     * @param list<self> $advisories
     * @return list<self>
     */
    public static function sorted(array $advisories): array
    {
        usort($advisories, static function (self $a, self $b): int {
            if ($a->score === null || $b->score === null) {
                return [$a->score === null, $a->id] <=> [$b->score === null, $b->id];
            }

            return [$b->score, $a->id] <=> [$a->score, $b->id];
        });

        return $advisories;
    }

    /**
     * One entry per identifier: the same CVE reached through two sources
     * or two CPE names is one advisory. The first occurrence wins.
     *
     * @param list<self> $advisories
     * @return list<self>
     */
    public static function deduplicated(array $advisories): array
    {
        $unique = [];
        foreach ($advisories as $advisory) {
            $unique[$advisory->id] ??= $advisory;
        }

        return array_values($unique);
    }
}
