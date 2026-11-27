<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * The verdict on one component in one context, and everything needed to
 * say it without consulting anything again (issue #1358).
 *
 * It is the **one** evaluation the box, the counter and the notification
 * all read: {@see HostSecurityEvaluator} writes it once per weekly run, it
 * is stored in the snapshot, and nothing downstream compares a version or
 * an advisory a second time.
 *
 * The update and the advisories are kept as two halves, so the page can
 * say « une mise à jour est disponible » and « un avis de sécurité
 * s'applique » separately even on the same line.
 */
final class ComponentAssessment
{
    public const STATE_UP_TO_DATE = 'up_to_date';
    public const STATE_OUTDATED = 'outdated';
    public const STATE_VULNERABLE = 'vulnerable';
    public const STATE_UNVERIFIABLE = 'unverifiable';

    public const UPDATE_CURRENT = 'current';
    public const UPDATE_BEHIND = 'behind';
    public const UPDATE_UNSUPPORTED = 'unsupported';
    public const UPDATE_UNKNOWN = 'unknown';

    /** How many advisories a line lists; the rest are counted. */
    public const DISPLAYED_ADVISORIES = 10;

    /**
     * @param list<Advisory> $advisories the applicable ones, highest first, at most {@see DISPLAYED_ADVISORIES}
     * @param list<string> $highAdvisoryIds every applicable advisory at CVSS ≥ 7 — what a notification is about
     * @param list<string> $notes French sentences qualifying the verdict (a backport, a stale source)
     * @param list<array{label: string, url: string}> $references
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $label,
        public readonly string $familyLabel,
        public readonly ?string $version,
        public readonly ?int $measuredAt,
        public readonly string $state,
        public readonly string $updateStatus,
        public readonly ?string $branch,
        public readonly ?string $latest,
        public readonly ?string $supportEnds,
        /** False when a backport could make the upstream comparison wrong: « à confirmer ». */
        public readonly bool $certain,
        public readonly array $advisories,
        public readonly int $advisoryCount,
        public readonly array $highAdvisoryIds,
        public readonly string $advisoryStatus,
        public readonly bool $needsUpdate,
        /** The verdict on the alert condition can be trusted either way — what lets a fixed alert re-arm. */
        public readonly bool $conclusive,
        public readonly array $notes,
        public readonly string $action,
        public readonly array $references,
        public readonly ?int $versionsCheckedAt,
        public readonly ?int $advisoriesCheckedAt,
    ) {
    }

    /** {@see HostSecuritySnapshot::trimmed()} for one line. */
    public function trimmed(int $advisories, ?int $highIds): self
    {
        $data = $this->toArray();
        $data['advisories'] = array_slice($data['advisories'], 0, $advisories);
        if ($highIds !== null) {
            $data['high_advisory_ids'] = array_slice($data['high_advisory_ids'], 0, $highIds);
        }

        return self::fromArray($data) ?? $this;
    }

    /** Counted in « N points à régler chez l'hébergeur » (issue #1358). */
    public function isProblem(): bool
    {
        return $this->state === self::STATE_OUTDATED || $this->state === self::STATE_VULNERABLE;
    }

    /** The notification condition: an update is needed AND an advisory at CVSS ≥ 7 applies. */
    public function alertWorthy(): bool
    {
        return $this->needsUpdate && $this->highAdvisoryIds !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'label' => $this->label,
            'family_label' => $this->familyLabel,
            'version' => $this->version,
            'measured_at' => $this->measuredAt,
            'state' => $this->state,
            'update_status' => $this->updateStatus,
            'branch' => $this->branch,
            'latest' => $this->latest,
            'support_ends' => $this->supportEnds,
            'certain' => $this->certain,
            'advisories' => array_map(static fn(Advisory $a): array => $a->toArray(), $this->advisories),
            'advisory_count' => $this->advisoryCount,
            'high_advisory_ids' => $this->highAdvisoryIds,
            'advisory_status' => $this->advisoryStatus,
            'needs_update' => $this->needsUpdate,
            'conclusive' => $this->conclusive,
            'notes' => $this->notes,
            'action' => $this->action,
            'references' => $this->references,
            'versions_checked_at' => $this->versionsCheckedAt,
            'advisories_checked_at' => $this->advisoriesCheckedAt,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['subject'] ?? null) || !is_string($data['state'] ?? null)) {
            return null;
        }
        $states = [self::STATE_UP_TO_DATE, self::STATE_OUTDATED, self::STATE_VULNERABLE, self::STATE_UNVERIFIABLE];
        if (!in_array($data['state'], $states, true)) {
            return null;
        }
        $string = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $int = static fn(mixed $value): ?int => is_int($value) ? $value : null;
        $strings = static fn(mixed $value): array => is_array($value)
            ? array_values(array_filter($value, 'is_string'))
            : [];

        $references = [];
        foreach (is_array($data['references'] ?? null) ? $data['references'] : [] as $reference) {
            if (is_array($reference) && is_string($reference['label'] ?? null) && is_string($reference['url'] ?? null)) {
                $references[] = ['label' => $reference['label'], 'url' => $reference['url']];
            }
        }

        return new self(
            $data['subject'],
            $string($data['label'] ?? null) ?? $data['subject'],
            $string($data['family_label'] ?? null) ?? '',
            $string($data['version'] ?? null),
            $int($data['measured_at'] ?? null),
            $data['state'],
            $string($data['update_status'] ?? null) ?? self::UPDATE_UNKNOWN,
            $string($data['branch'] ?? null),
            $string($data['latest'] ?? null),
            $string($data['support_ends'] ?? null),
            ($data['certain'] ?? false) === true,
            array_values(array_filter(array_map(
                [Advisory::class, 'fromArray'],
                is_array($data['advisories'] ?? null) ? $data['advisories'] : []
            ))),
            is_int($data['advisory_count'] ?? null) ? $data['advisory_count'] : 0,
            $strings($data['high_advisory_ids'] ?? null),
            $string($data['advisory_status'] ?? null) ?? AdvisoryLookup::UNAVAILABLE,
            ($data['needs_update'] ?? false) === true,
            ($data['conclusive'] ?? false) === true,
            $strings($data['notes'] ?? null),
            (string) ($data['action'] ?? ''),
            $references,
            $int($data['versions_checked_at'] ?? null),
            $int($data['advisories_checked_at'] ?? null)
        );
    }
}
