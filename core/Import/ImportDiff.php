<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * What one Desk import changed, compared with the one before it in the
 * same scout year.
 *
 * **A dated, frozen fact.** "12 members added, 7 gone on 3 September" will
 * never become anything else, so it is computed once, at the end of the
 * import, and stored. That is what lets the report page read it back
 * months later and still be honest — a page that recomputed would be
 * describing today, under a heading that says September.
 *
 * Do not confuse it with an attention point, which is the opposite kind
 * of thing: a current state of the unit, recalculated at every display,
 * and disappearing on its own when it stops being true.
 *
 * **No personal data.** Foreign keys and codes throughout — `members.id`,
 * `sections.id`, `functions.id`, `fee_categories.id`, role strings. A
 * screen that needs a readable person joins back to `member_years` on
 * (member_id, the import's scout year), the same way the roster snapshot
 * this is computed from does.
 */
final class ImportDiff
{
    /** Why there is nothing to compare against. */
    public const UNAVAILABLE_FIRST_OF_SEASON = 'first_of_season';
    public const UNAVAILABLE_PREDECESSOR_PURGED = 'predecessor_purged';

    /**
     * @param int[] $arrivedMemberIds
     * @param int[] $departedMemberIds
     * @param array<int, array{from: ?int, to: ?int}> $sectionChanges member id => before/after
     * @param array<int, array{from: ?int, to: ?int}> $functionChanges member id => before/after
     * @param array<int, array{from: ?string, to: ?string}> $roleChanges member id => before/after
     * @param array<int, array{from: ?int, to: ?int}> $feeCategoryChanges member id => before/after
     * @param int[] $adminGainedMemberIds
     * @param int[] $adminLostMemberIds
     * @param int[] $newFunctionIds functions this installation had never seen
     * @param int[] $newSectionIds
     * @param int[] $newBranchIds
     * @param int[] $newFeeCategoryIds
     * @param int[] $sectionsGoneInactiveIds sections that lost their last member
     * @param int[] $sectionsGoneActiveIds sections that gained their first
     */
    public function __construct(
        public readonly bool $available,
        public readonly ?string $unavailableReason,
        public readonly ?int $previousImportId,
        public readonly array $arrivedMemberIds = [],
        public readonly array $departedMemberIds = [],
        public readonly array $sectionChanges = [],
        public readonly array $functionChanges = [],
        public readonly array $roleChanges = [],
        public readonly array $feeCategoryChanges = [],
        public readonly array $adminGainedMemberIds = [],
        public readonly array $adminLostMemberIds = [],
        public readonly array $newFunctionIds = [],
        public readonly array $newSectionIds = [],
        public readonly array $newBranchIds = [],
        public readonly array $newFeeCategoryIds = [],
        public readonly array $sectionsGoneInactiveIds = [],
        public readonly array $sectionsGoneActiveIds = [],
        /**
         * The state this import left behind, counted at the time — not a
         * comparison with anything, but stored here so the report has one
         * frozen artefact to read rather than two. See {@see
         * ImportQuality}.
         */
        public readonly ImportQuality $quality = new ImportQuality()
    ) {
    }

    /**
     * The diff of an import with no predecessor.
     *
     * Two different absences, kept apart on purpose: the season's first
     * import genuinely has nothing before it, while a purged predecessor
     * is a comparison that once existed and no longer does. Both mean
     * "unavailable", never "nothing changed", and the screen says which.
     */
    public static function unavailable(string $reason, ?ImportQuality $quality = null): self
    {
        return new self(
            false,
            $reason,
            null,
            quality: $quality ?? new ImportQuality()
        );
    }

    /**
     * Whether anything at all moved. False on a re-import of the same
     * file, which is a real and unremarkable thing to do.
     */
    public function isEmpty(): bool
    {
        return $this->available
            && $this->arrivedMemberIds === []
            && $this->departedMemberIds === []
            && $this->sectionChanges === []
            && $this->functionChanges === []
            && $this->roleChanges === []
            && $this->feeCategoryChanges === []
            && $this->newFunctionIds === []
            && $this->newSectionIds === []
            && $this->newBranchIds === []
            && $this->newFeeCategoryIds === [];
    }

    /**
     * The one figure the report leads on: how many people are affected by
     * something that changes what they can see or do.
     */
    public function accessImpactCount(): int
    {
        return count($this->adminGainedMemberIds)
            + count($this->adminLostMemberIds)
            + count($this->newFunctionIds);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'unavailable_reason' => $this->unavailableReason,
            'previous_import_id' => $this->previousImportId,
            'arrived_member_ids' => $this->arrivedMemberIds,
            'departed_member_ids' => $this->departedMemberIds,
            'section_changes' => $this->sectionChanges,
            'function_changes' => $this->functionChanges,
            'role_changes' => $this->roleChanges,
            'fee_category_changes' => $this->feeCategoryChanges,
            'admin_gained_member_ids' => $this->adminGainedMemberIds,
            'admin_lost_member_ids' => $this->adminLostMemberIds,
            'new_function_ids' => $this->newFunctionIds,
            'new_section_ids' => $this->newSectionIds,
            'new_branch_ids' => $this->newBranchIds,
            'new_fee_category_ids' => $this->newFeeCategoryIds,
            'sections_gone_inactive_ids' => $this->sectionsGoneInactiveIds,
            'sections_gone_active_ids' => $this->sectionsGoneActiveIds,
            'quality' => $this->quality->toArray(),
        ];
    }

    /**
     * Rebuild a stored diff.
     *
     * Every field is defaulted rather than required: a row written by an
     * older version of this class must keep opening, since the whole
     * point of storing it was that it never has to be recomputed.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $ints = static fn(mixed $value): array => is_array($value) ? array_map('intval', array_values($value)) : [];

        $idChanges = static function (mixed $value): array {
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($value as $memberId => $change) {
                if (!is_array($change)) {
                    continue;
                }
                $out[(int) $memberId] = [
                    'from' => isset($change['from']) ? (int) $change['from'] : null,
                    'to' => isset($change['to']) ? (int) $change['to'] : null,
                ];
            }

            return $out;
        };

        $stringChanges = static function (mixed $value): array {
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($value as $memberId => $change) {
                if (!is_array($change)) {
                    continue;
                }
                $out[(int) $memberId] = [
                    'from' => isset($change['from']) ? (string) $change['from'] : null,
                    'to' => isset($change['to']) ? (string) $change['to'] : null,
                ];
            }

            return $out;
        };

        return new self(
            (bool) ($data['available'] ?? false),
            isset($data['unavailable_reason']) ? (string) $data['unavailable_reason'] : null,
            isset($data['previous_import_id']) ? (int) $data['previous_import_id'] : null,
            $ints($data['arrived_member_ids'] ?? []),
            $ints($data['departed_member_ids'] ?? []),
            $idChanges($data['section_changes'] ?? []),
            $idChanges($data['function_changes'] ?? []),
            $stringChanges($data['role_changes'] ?? []),
            $idChanges($data['fee_category_changes'] ?? []),
            $ints($data['admin_gained_member_ids'] ?? []),
            $ints($data['admin_lost_member_ids'] ?? []),
            $ints($data['new_function_ids'] ?? []),
            $ints($data['new_section_ids'] ?? []),
            $ints($data['new_branch_ids'] ?? []),
            $ints($data['new_fee_category_ids'] ?? []),
            $ints($data['sections_gone_inactive_ids'] ?? []),
            $ints($data['sections_gone_active_ids'] ?? []),
            ImportQuality::fromArray(is_array($data['quality'] ?? null) ? $data['quality'] : [])
        );
    }
}
