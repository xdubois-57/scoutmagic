<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

use Core\Member\NameDobKey;
use Core\Security\EncryptionService;

/**
 * Finds members an import has just created who already existed under
 * another Desk identifier.
 *
 * **The mistake, and why it is worth code.** Somebody leaves, comes back
 * a year later, and is created as a NEW person in Desk instead of having
 * their old record reopened. New `desk_id`, new `members` row — and
 * photos, badges, private documents, section periods, totem and every
 * file scoped by `files.owner_member_id` stay attached to the abandoned
 * identity. The returning member's page is empty and nothing explains
 * why. It is not supposed to happen; it is an ordinary human error, and
 * the damage is silent and delayed.
 *
 * **Runs after the commit, never inside it.** The comparison decrypts
 * names and birth dates in bulk — the same technique
 * `Modules\Registration\Service\ReconciliationService` uses, recomputing
 * the blind index in memory with no persisted column — and that is one
 * more reason not to hold the import's transaction open across it. It is
 * also why the result is stored rather than recomputed on every page
 * view: the attention point that surfaces it has to stay bounded.
 *
 * **Only the members this import created, only against earlier years.**
 * Desk guarantees `desk_id` uniqueness within an export, so there is
 * nothing to find inside one season; the problem is strictly inter-year.
 */
class DuplicateMemberDetector
{
    public function __construct(
        private DuplicateMemberRepository $repository,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Detect and record duplicate candidates for the members created by
     * one import.
     *
     * Returns how many new candidates were recorded. A pair somebody has
     * already decided about is left alone — re-proposing "these are two
     * different people" after every import is exactly how a list stops
     * being read.
     *
     * @param int[] $createdMemberIds `members.id` values this import inserted
     */
    public function detect(array $createdMemberIds, int $scoutYearId): int
    {
        $createdMemberIds = array_values(array_unique(array_filter($createdMemberIds)));
        if ($createdMemberIds === []) {
            return 0;
        }

        $newcomers = $this->keysFor($this->repository->findIdentitiesForMembers($createdMemberIds, $scoutYearId));
        if ($newcomers === []) {
            return 0;
        }

        $earlier = $this->keysFor($this->repository->findIdentitiesBeforeYear($scoutYearId));

        $recorded = 0;
        foreach ($newcomers as $key => $newMemberIds) {
            foreach ($newMemberIds as $duplicateMemberId) {
                foreach ($earlier[$key] ?? [] as $keptMemberId) {
                    if ($keptMemberId === $duplicateMemberId) {
                        continue;
                    }
                    if ($this->repository->hasPair($keptMemberId, $duplicateMemberId)) {
                        continue;
                    }

                    $this->repository->recordCandidate(
                        $keptMemberId,
                        $duplicateMemberId,
                        // A shared address makes a doubtful pair much more
                        // likely to be one person — and never proves it,
                        // since siblings share one too. Recorded as a hint
                        // for whoever decides, never as a reason to
                        // propose the pair in the first place.
                        $this->repository->shareAnAddress($keptMemberId, $duplicateMemberId)
                    );
                    $recorded++;
                }
            }
        }

        return $recorded;
    }

    /**
     * Group member ids by their name+dob blind index.
     *
     * A member with no birth date is skipped rather than matched on name
     * alone: "Dupont Jean" is not enough to propose merging two people's
     * whole history.
     *
     * @param array<int, array{member_id: int, first_name: string, last_name: string, birth_date: ?string}> $identities
     * @return array<string, int[]>
     */
    private function keysFor(array $identities): array
    {
        $byKey = [];
        foreach ($identities as $identity) {
            if ($identity['birth_date'] === null || $identity['birth_date'] === '') {
                continue;
            }

            $normalized = NameDobKey::normalize(
                $identity['last_name'],
                $identity['first_name'],
                $identity['birth_date']
            );
            $blindIndex = $this->encryption->blindIndex($normalized, NameDobKey::BLIND_INDEX_CONTEXT);

            $byKey[$blindIndex][$identity['member_id']] = $identity['member_id'];
        }

        return array_map('array_values', $byKey);
    }
}
