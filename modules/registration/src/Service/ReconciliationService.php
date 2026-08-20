<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Registration\Api\ReconciliationTrigger;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Confronts every 'accepted' request for a scout year against the members
 * that year's Desk import just produced (module spec). Comparison is
 * entirely blind-index based: this class decrypts only the small,
 * year-scoped set of freshly imported member_years rows (unavoidable —
 * their blind index doesn't exist yet) to compute a comparable index,
 * then does a plain in-memory lookup against the accepted requests' own
 * (already-stored) blind indexes — never a decrypt loop over
 * registration_requests, and never every historical year at once.
 *
 * Exactly one request matching exactly one member migrates automatically
 * (Service\MigrationService — the same path a manual link uses). Zero
 * matches leaves the request 'accepted' (it surfaces in the "non
 * rapprochées" encart). More than one match on EITHER side is refused
 * outright — module spec: real homonym/twin cases exist, and guessing
 * wrong is worse than asking a human. The journal entry for that case
 * carries counts and ids only, never a name (SECURITY.md §11).
 */
class ReconciliationService implements ReconciliationTrigger
{
    public function __construct(
        private \PDO $pdo,
        private RegistrationRequestRepository $requestRepository,
        private EncryptionService $encryption,
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
        $stmt = $this->pdo->prepare(
            'SELECT member_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted
             FROM member_years WHERE scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([$scoutYearId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['birth_date_encrypted'] === null) {
                continue;
            }
            $firstName = $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name');
            $lastName = $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name');
            $birthDate = $this->encryption->decrypt($row['birth_date_encrypted'], 'member_years.birth_date');
            $normalized = RegistrationRequestRepository::normalizeForNameDobBlindIndex($lastName, $firstName, $birthDate);
            $blindIndex = $this->encryption->blindIndex($normalized, 'registration_name_dob');
            $memberIdsByBlind[$blindIndex][] = (int) $row['member_id'];
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
                    'Rapprochement ambigu : plusieurs correspondances possibles, aucun rattachement automatique',
                    ['request_ids' => $requestIds, 'member_ids' => $memberIds]
                );
                continue;
            }

            $this->migrationService->migrate($requestIds[0], $memberIds[0]);
        }
    }
}
