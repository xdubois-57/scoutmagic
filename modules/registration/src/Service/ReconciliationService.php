<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Database\ConstraintViolation;
use Core\Journal\JournalService;
use Modules\Registration\Api\ReconciliationTrigger;
use Modules\Registration\Repository\ImportedMemberRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Confronts every 'accepted' request for a scout year against the members
 * that year's Desk import just produced (module spec). Comparison is
 * entirely blind-index based: only the small, year-scoped set of freshly
 * imported member_years rows is decrypted (unavoidable — their blind index
 * doesn't exist yet), by Repository\ImportedMemberRepository, to compute a
 * comparable index; this class then does a plain in-memory lookup against
 * the accepted requests' own
 * (already-stored) blind indexes — never a decrypt loop over
 * registration_requests, and never every historical year at once.
 *
 * Exactly one request matching exactly one member migrates automatically
 * (Service\MigrationService — the same path a manual link uses). Zero
 * matches leaves the request 'accepted' (counted on the management
 * dashboard as « Acceptées, pas encore dans Desk », and linked by hand from
 * its fiche, « Lier à un membre Desk »). More than one match on EITHER side is refused
 * outright — module spec: real homonym/twin cases exist, and guessing
 * wrong is worse than asking a human. The journal entry for that case
 * carries counts and ids only, never a name (SECURITY.md §11).
 *
 * A single match whose member is already linked to another request is
 * refused the same way (issue #1434): journaled with ids only, and the
 * next request is reconciled.
 */
class ReconciliationService implements ReconciliationTrigger
{
    public function __construct(
        private ImportedMemberRepository $importedMembers,
        private RegistrationRequestRepository $requestRepository,
        private MigrationService $migrationService,
        private JournalService $journalService
    ) {
    }

    public function reconcileForYear(int $scoutYearId): void
    {
        $accepted = $this->requestRepository->findAcceptedIdsAndBlindIndexForYear($scoutYearId);
        if ($accepted === []) {
            return;
        }

        /** @var array<string, array<int>> $requestIdsByBlind */
        $requestIdsByBlind = [];
        foreach ($accepted as $row) {
            $requestIdsByBlind[$row['name_dob_blind_index']][] = $row['id'];
        }

        /** @var array<string, array<int>> $memberIdsByBlind */
        $memberIdsByBlind = [];
        foreach ($this->importedMembers->findNameDobBlindIndexesForYear($scoutYearId) as $member) {
            $memberIdsByBlind[$member['name_dob_blind_index']][] = $member['member_id'];
        }

        foreach ($requestIdsByBlind as $blindIndex => $requestIds) {
            $memberIds = $memberIdsByBlind[$blindIndex] ?? [];
            if ($memberIds === []) {
                continue;
            }

            if (count($memberIds) > 1 || count($requestIds) > 1) {
                $this->journalService->log(
                    'registration',
                    'registration_reconciliation_ambiguous',
                    'info',
                    'Correspondance ambiguë avec Desk : plusieurs correspondances possibles, aucun lien automatique',
                    ['request_ids' => $requestIds, 'member_ids' => $memberIds]
                );
                continue;
            }

            // A member is the target of one request only (UNIQUE
            // idx_rr_linked_member), and an older, encoded request stays
            // linked for two years: a family that fills the public form
            // again instead of re-enrolling produces a second, accepted
            // request for the same child (issue #1434). Like the ambiguous
            // case, it is journaled and left to a person — and the loop
            // goes on, so one such request never stops the others.
            $existingLink = $this->requestRepository->findByLinkedMemberId($memberIds[0]);
            if ($existingLink !== null && $existingLink->id !== $requestIds[0]) {
                $this->journalAlreadyLinked($requestIds[0], $existingLink->id, $memberIds[0]);
                continue;
            }

            try {
                $this->migrationService->migrate($requestIds[0], $memberIds[0]);
            } catch (RegistrationException | \PDOException $e) {
                // Linked by a concurrent request after the check above: the
                // same case, the same treatment. Before migrate()'s own
                // check it is its RegistrationException; between that check
                // and its UPDATE, the UNIQUE idx_rr_linked_member refuses
                // the write with a duplicate key (review of #1552). Any
                // other database error is not this case and goes up.
                if ($e instanceof \PDOException && !ConstraintViolation::isDuplicateKey($e)) {
                    throw $e;
                }
                $raced = $this->requestRepository->findByLinkedMemberId($memberIds[0]);
                if ($raced === null || $raced->id === $requestIds[0]) {
                    throw $e;
                }
                $this->journalAlreadyLinked($requestIds[0], $raced->id, $memberIds[0]);
            }
        }
    }

    private function journalAlreadyLinked(int $requestId, int $linkedRequestId, int $memberId): void
    {
        $this->journalService->log(
            'registration',
            'registration_reconciliation_already_linked',
            'warning',
            'Correspondance avec Desk non liée : ce membre est déjà lié à une autre demande d\'inscription',
            ['request_id' => $requestId, 'linked_request_id' => $linkedRequestId, 'member_id' => $memberId]
        );
    }
}
