<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

use Core\Config\AppClock;

/**
 * The dated result of one host security check — what the box, the counter
 * and the notifications read, all three, so none of them re-runs a
 * comparison (issue #1358).
 */
final class HostSecuritySnapshot
{
    /** Past this, the box says how old the check is and never concludes « À jour ». */
    public const STALE_AFTER_SECONDS = 14 * 86400;

    /**
     * @param list<ComponentAssessment> $assessments in display order, confirmed-absent components excluded
     * @param bool $complete false while part of the advisories waits for the next pass
     */
    public function __construct(
        public readonly int $checkedAt,
        public readonly array $assessments,
        public readonly bool $complete,
    ) {
    }

    /**
     * The new points the summary adds to « N points à régler chez
     * l'hébergeur »: one per component and context that is out of date or
     * potentially vulnerable — several advisories on one component are one
     * point. An unverifiable component adds none: not knowing is not, on
     * its own, a delay.
     */
    public function pointsToFix(): int
    {
        return count(array_filter($this->assessments, static fn(ComponentAssessment $a): bool => $a->isProblem()));
    }

    public function hasVulnerable(): bool
    {
        return $this->countIn(ComponentAssessment::STATE_VULNERABLE) > 0;
    }

    public function countIn(string $state): int
    {
        return count(array_filter($this->assessments, static fn(ComponentAssessment $a): bool => $a->state === $state));
    }

    public function isStale(int $now): bool
    {
        return $now - $this->checkedAt > self::STALE_AFTER_SECONDS;
    }

    /**
     * The most important situation, without hiding the uncertain ones: a
     * problem anywhere wins; otherwise « À jour » only when every line is,
     * the run was complete and recent. A partial inventory or a source that
     * failed therefore reads « non vérifiable », never « À jour ».
     */
    public function overallState(int $now): string
    {
        return match (true) {
            $this->hasVulnerable() => ComponentAssessment::STATE_VULNERABLE,
            $this->countIn(ComponentAssessment::STATE_OUTDATED) > 0 => ComponentAssessment::STATE_OUTDATED,
            $this->assessments === [],
            !$this->complete,
            $this->isStale($now),
            $this->countIn(ComponentAssessment::STATE_UNVERIFIABLE) > 0 => ComponentAssessment::STATE_UNVERIFIABLE,
            default => ComponentAssessment::STATE_UP_TO_DATE,
        };
    }

    /**
     * A stored timestamp as a moment on the application clock, for the
     * date filters — the snapshot keeps integers, the page prints dates.
     */
    public function moment(?int $timestamp): ?\DateTimeImmutable
    {
        return $timestamp === null || $timestamp <= 0
            ? null
            : (new \DateTimeImmutable('@' . $timestamp))->setTimezone(AppClock::zone());
    }

    /**
     * The same verdicts with less detail, for a snapshot that would not fit
     * its settings row: at most $advisories listed per line and, when given,
     * $highIds identifiers at CVSS ≥ 7. States, counts and actions are kept.
     */
    public function trimmed(int $advisories, ?int $highIds): self
    {
        return new self(
            $this->checkedAt,
            array_map(
                static fn(ComponentAssessment $a): ComponentAssessment => $a->trimmed($advisories, $highIds),
                $this->assessments
            ),
            $this->complete
        );
    }

    public function find(string $subject): ?ComponentAssessment
    {
        foreach ($this->assessments as $assessment) {
            if ($assessment->subject === $subject) {
                return $assessment;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'checked_at' => $this->checkedAt,
            'complete' => $this->complete,
            'assessments' => array_map(static fn(ComponentAssessment $a): array => $a->toArray(), $this->assessments),
        ];
    }

    /** A malformed value reads as « no check yet », never as an empty, healthy one. */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_int($data['checked_at'] ?? null) || !is_array($data['assessments'] ?? null)) {
            return null;
        }

        return new self(
            $data['checked_at'],
            array_values(array_filter(array_map([ComponentAssessment::class, 'fromArray'], $data['assessments']))),
            ($data['complete'] ?? false) === true
        );
    }
}
