<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Member\AddressNormalizer;
use Core\Member\NameDobKey;
use Core\Security\CapabilityToken;
use Core\Security\EncryptionService;
use Core\Service\DateInput;

/**
 * All identity data is encrypted at rest (SECURITY.md §5) — this is the
 * only class that ever calls EncryptionService::encrypt()/decrypt() for a
 * registration request. Blind indexes exist for the exact-match lookups
 * the module spec calls for: email (tracking-page linkage), the normalized
 * name+birth-date triple (Desk reconciliation, Service\
 * ReconciliationService), and the normalized address (household fee count,
 * Api\HouseholdRegistrationCountProvider) — never used to refuse a
 * submission.
 */
class RegistrationRequestRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param array{
     *   parent_name: string, child_last_name: string, child_first_name: string,
     *   gender: string, birth_date: string, street: string, number: string,
     *   postal_code: string, city: string, email: string, phone1: string,
     *   phone2: ?string, remarks: ?string
     * } $fields
     * @param array<int> $siblingMemberIds
     * @return array{id: int, tracking_token: string} the raw tracking token, never
     *         stored or logged anywhere else — the caller must hand it to the
     *         parent (tracking link) and then discard it.
     */
    public function create(
        int $scoutYearId,
        array $fields,
        ?int $desiredSectionId,
        array $siblingMemberIds
    ): array {
        $trackingToken = CapabilityToken::generate();
        $trackingTokenHash = password_hash($trackingToken, PASSWORD_DEFAULT);

        $nameDobBlind = $this->encryption->blindIndex(self::normalizeForNameDobBlindIndex(
            $fields['child_last_name'],
            $fields['child_first_name'],
            $fields['birth_date']
        ), 'registration_name_dob');

        $addressNormalized = AddressNormalizer::normalize($fields['street'], $fields['number'], null, $fields['postal_code']);
        $addressBlind = $addressNormalized !== '' ? $this->encryption->blindIndex($addressNormalized, 'address') : null;

        // The request row and its sibling links are one single unit of work:
        // a failing link (a member id that vanished between the form being
        // rendered and submitted, a full disk...) used to leave a request
        // created but link-less, with the exception escaping before Service\
        // RegistrationService::submit() could send the receipt email — so the
        // family got nothing at all for a request that did exist. Same
        // begin/commit/rollBack shape as Service\MigrationService::migrate().
        $this->pdo->beginTransaction();
        try {
            $id = $this->insertRequestAndSiblings([
                $scoutYearId,
                $this->encryption->encrypt($fields['parent_name'], 'registration_requests.parent_name'),
                $this->encryption->encrypt($fields['child_last_name'], 'registration_requests.child_last_name'),
                $this->encryption->encrypt($fields['child_first_name'], 'registration_requests.child_first_name'),
                $this->encryption->encrypt($fields['gender'], 'registration_requests.gender'),
                $this->encryption->encrypt($fields['birth_date'], 'registration_requests.birth_date'),
                $this->encryption->encrypt($fields['street'], 'registration_requests.street'),
                $this->encryption->encrypt($fields['number'], 'registration_requests.number'),
                $this->encryption->encrypt($fields['postal_code'], 'registration_requests.postal_code'),
                $this->encryption->encrypt($fields['city'], 'registration_requests.city'),
                $this->encryption->encrypt($fields['email'], 'registration_requests.email'),
                $this->encryption->blindIndex(self::normalizeEmail($fields['email']), 'registration_email'),
                $this->encryption->encrypt($fields['phone1'], 'registration_requests.phone1'),
                $fields['phone2'] !== null ? $this->encryption->encrypt($fields['phone2'], 'registration_requests.phone2') : null,
                $fields['remarks'] !== null && $fields['remarks'] !== '' ? $this->encryption->encrypt($fields['remarks'], 'registration_requests.remarks') : null,
                $nameDobBlind,
                $desiredSectionId,
                RegistrationRequest::STATUS_PENDING,
                $trackingTokenHash,
                $addressBlind,
            ], $siblingMemberIds);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['id' => $id, 'tracking_token' => $trackingToken];
    }

    /**
     * @param array<int, mixed> $requestParams
     * @param array<int> $siblingMemberIds
     */
    private function insertRequestAndSiblings(array $requestParams, array $siblingMemberIds): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_requests (
                scout_year_id, parent_name_encrypted, child_last_name_encrypted, child_first_name_encrypted,
                gender_encrypted, birth_date_encrypted, street_encrypted, number_encrypted,
                postal_code_encrypted, city_encrypted, email_encrypted, email_blind_index,
                phone1_encrypted, phone2_encrypted, remarks_encrypted, name_dob_blind_index,
                desired_section_id, status, tracking_token_hash, address_normalized_blind_index
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute($requestParams);

        $id = (int) $this->pdo->lastInsertId();

        if ($siblingMemberIds !== []) {
            $siblingStmt = $this->pdo->prepare(
                'INSERT INTO registration_request_siblings (registration_request_id, member_id) VALUES (?, ?)'
            );
            foreach (array_unique($siblingMemberIds) as $memberId) {
                $siblingStmt->execute([$id, $memberId]);
            }
        }

        return $id;
    }

    public function findById(int $id): ?RegistrationRequest
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Verifies a presented tracking token against request $id's stored
     * hash — password_verify(), same technique as Core\Member\
     * MemberEmailService's confirmation tokens (SECURITY.md §5). Never
     * expires while the request exists, per the module spec.
     */
    public function verifyTrackingToken(int $id, string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT tracking_token_hash FROM registration_requests WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();

        return $hash !== false && password_verify($token, (string) $hash);
    }

    /**
     * Every request whose primary email blind-indexes to $email — the
     * tracking-page linkage lookup (Service\TrackingService). Blind index
     * on purpose: the alternative (decrypt every row to compare) doesn't
     * scale and isn't needed for an exact match.
     *
     * @return array<RegistrationRequest>
     */
    public function findAllByEmailBlindIndex(string $blindIndex): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every request for $scoutYearId, oldest first ("premier arrivé,
     * premier servi" — module spec) — Controller\RegistrationConfigController's
     * management list.
     *
     * @return array<RegistrationRequest>
     */
    public function findAllForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE scout_year_id = ? ORDER BY received_at ASC, id ASC');
        $stmt->execute([$scoutYearId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * "Accepted" requests still missing a linked member ("demandes non
     * rapprochées" encart) — never automatically decided further, only a
     * chief acting (manual link) or a future import clears this.
     *
     * @return array<RegistrationRequest>
     */
    public function findUnreconciledAcceptedForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registration_requests
             WHERE scout_year_id = ? AND status = 'accepted' AND linked_member_id IS NULL
             ORDER BY received_at ASC, id ASC"
        );
        $stmt->execute([$scoutYearId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Non-final requests ("demandes non clôturées" encart, and the bulk
     * "tout refuser"/"tout retirer" actions' own target set).
     *
     * @return array<RegistrationRequest>
     */
    public function findNonFinalForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registration_requests
             WHERE scout_year_id = ? AND status IN ('pending', 'accepted')
             ORDER BY received_at ASC, id ASC"
        );
        $stmt->execute([$scoutYearId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Count of non-final requests (pending/accepted) across every scout
     * year — Api\ScoutYearTransitionVetoProvider's own count, deliberately
     * NOT scoped to one year ("toutes années cibles confondues", module
     * spec): a request that targeted a past year and was simply never
     * processed still blocks, so a forgotten backlog can never hide behind
     * a year switch. Ids only would be pointless here — the veto only ever
     * needs the count.
     */
    public function countNonFinalAllYears(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM registration_requests WHERE status IN ('pending', 'accepted')");

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    /**
     * Every 'accepted' request for $scoutYearId, fully decrypted —
     * Service\SlotService buckets these into slots by birth date, same as
     * the public waitlist display, for both the admin capacity table's
     * "demandes acceptées" column and the tier shown to the public (module
     * spec: accepted requests are real commitments, unlike still-undecided
     * pending ones, so they're what the public-facing availability level
     * is computed from).
     *
     * @return array<RegistrationRequest>
     */
    public function findAcceptedForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registration_requests WHERE scout_year_id = ? AND status = 'accepted'"
        );
        $stmt->execute([$scoutYearId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The real member id behind every 'encoded' request for $scoutYearId
     * — Service\ExternalMailingListService's own candidate pool (module
     * spec: the mailing list contributed to mass_mail only ever includes
     * requests that made it all the way to a real Desk member; an
     * 'accepted'-but-not-yet-encoded request has no `members` row to send
     * to at all, which is exactly why it's excluded here).
     *
     * @return array<int>
     */
    public function findEncodedMemberIdsForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT linked_member_id FROM registration_requests WHERE scout_year_id = ? AND status = 'encoded' AND linked_member_id IS NOT NULL"
        );
        $stmt->execute([$scoutYearId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every 'accepted' request for $scoutYearId — Service\
     * ReconciliationService's own candidate pool at import time. Only the
     * id and name_dob_blind_index are needed for matching; the full
     * decrypted row is only ever read again for the (small) subset that
     * actually gets migrated or flagged ambiguous.
     *
     * @return array<int, array{id: int, name_dob_blind_index: string}>
     */
    public function findAcceptedIdsAndBlindIndexForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name_dob_blind_index FROM registration_requests WHERE scout_year_id = ? AND status = 'accepted'"
        );
        $stmt->execute([$scoutYearId]);

        return array_map(
            static fn(array $row) => ['id' => (int) $row['id'], 'name_dob_blind_index' => (string) $row['name_dob_blind_index']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Count of 'accepted'/'encoded' requests whose submitted address
     * matches this blind index, for $scoutYearId, excluding
     * $excludeRequestId (a request must never count itself while its own
     * fiche is being estimated) — Api\HouseholdRegistrationCountProvider's
     * implementation, injected nullable into Core\Member\
     * FeeEstimationService (ARCHITECTURE.md §7.5).
     */
    public function countHouseholdAtAddress(string $addressBlindIndex, int $scoutYearId, ?int $excludeRequestId): int
    {
        $sql = "SELECT COUNT(*) FROM registration_requests
                WHERE address_normalized_blind_index = ? AND scout_year_id = ? AND status IN ('accepted', 'encoded')";
        $params = [$addressBlindIndex, $scoutYearId];
        if ($excludeRequestId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeRequestId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The same count for a batch of addresses, in one query — Api\
     * HouseholdRegistrationCountProvider::countsAtAddresses(), which
     * Core\Member\Household\HouseholdService calls once for a whole
     * scout year rather than once per household.
     *
     * @param string[] $addressBlindIndexes
     * @return array<string, int> blind index => count (addresses with no
     *         matching request are absent)
     */
    public function countHouseholdsAtAddresses(array $addressBlindIndexes, int $scoutYearId): array
    {
        $addressBlindIndexes = array_values(array_unique(array_filter(
            $addressBlindIndexes,
            static fn(string $index): bool => $index !== ''
        )));
        if ($addressBlindIndexes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($addressBlindIndexes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT address_normalized_blind_index AS blind_index, COUNT(*) AS total
             FROM registration_requests
             WHERE scout_year_id = ?
               AND status IN ('accepted', 'encoded')
               AND address_normalized_blind_index IN ($placeholders)
             GROUP BY address_normalized_blind_index"
        );
        $stmt->execute(array_merge([$scoutYearId], $addressBlindIndexes));

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['blind_index']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Declared siblings for a whole batch of requests, one query — the
     * per-request lookup below issued one query per row of the Passage page
     * and of the management list.
     *
     * @param array<int> $requestIds
     * @return array<int, array<int>> request id => member ids (requests with
     *         no declared sibling are simply absent)
     */
    public function findSiblingMemberIdsForRequests(array $requestIds): array
    {
        $requestIds = array_values(array_unique($requestIds));
        if ($requestIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT registration_request_id, member_id FROM registration_request_siblings
             WHERE registration_request_id IN ({$placeholders})"
        );
        $stmt->execute($requestIds);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['registration_request_id']][] = (int) $row['member_id'];
        }

        return $result;
    }

    /**
     * How many siblings each request declares — the management list only
     * ever shows the count, so it has no business fetching the ids.
     *
     * @param array<int> $requestIds
     * @return array<int, int> request id => count
     */
    public function countSiblingsForRequests(array $requestIds): array
    {
        return array_map('count', $this->findSiblingMemberIdsForRequests($requestIds));
    }

    /**
     * @return array<int> member ids declared as siblings on this request
     */
    public function findSiblingMemberIds(int $requestId): array
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM registration_request_siblings WHERE registration_request_id = ?');
        $stmt->execute([$requestId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every manual status transition (accept/refuse/withdraw/revert) goes
     * through here. $finalAt is set the moment $status becomes one of
     * RegistrationRequest::FINAL_STATUSES (module spec: retention counters
     * start there, never at received_at), and cleared back to NULL by
     * "revenir en attente" — the one transition that leaves a final state.
     */
    public function updateStatus(int $id, string $status, ?\DateTimeImmutable $finalAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET status = ?, final_at = ? WHERE id = ?');
        $stmt->execute([$status, $finalAt?->format('Y-m-d H:i:s'), $id]);
    }

    public function updateIntendedSection(int $id, ?int $sectionId): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET intended_section_id = ? WHERE id = ?');
        $stmt->execute([$sectionId, $id]);
    }

    /**
     * The other half of IT-18's « Réinitialiser »: the intended section of
     * every accepted request of one target year, cleared in one statement.
     *
     * Accepted requests only. A pending or refused request has no place on
     * the Passage page, so the button that empties that page has no
     * business touching one.
     */
    public function clearIntendedSectionsForYear(int $scoutYearId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE registration_requests SET intended_section_id = NULL
              WHERE scout_year_id = ? AND status = 'accepted'"
        );
        $stmt->execute([$scoutYearId]);
    }

    public function updateFeeCategory(int $id, ?int $feeCategoryId): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET fee_category_id = ? WHERE id = ?');
        $stmt->execute([$feeCategoryId, $id]);
    }

    /**
     * Chiffré au repos, jamais journalisé (module spec's own stricter
     * privacy rule for this one field — Controller\
     * RegistrationRequestController::saveNotes() never logs its content,
     * only that a save happened).
     */
    public function updateInternalNotes(int $id, ?string $notes): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET internal_notes_encrypted = ? WHERE id = ?');
        $stmt->execute([$notes !== null && $notes !== '' ? $this->encryption->encrypt($notes, 'registration_requests.internal_notes') : null, $id]);
    }

    /**
     * Stores the hash of a freshly minted tracking token, replacing the
     * previous one — same "previous value stops matching immediately"
     * precedent as RegistrationYearCodeRepository::regenerate(). Used
     * whenever a later email (acceptance/refusal) embeds a tracking link
     * again.
     *
     * The caller generates the raw token and calls this only ONCE THE EMAIL
     * HAS ACTUALLY BEEN SENT (Service\RequestEmailService::send()). This
     * used to be a single rotateTrackingToken() that minted and persisted in
     * one go, before the send: a delivery failure then left the family's
     * existing link dead while the replacement had never reached them —
     * which is precisely the retry the service's own docblock promises is
     * always safe.
     */
    public function storeTrackingTokenHash(int $id, string $rawToken): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET tracking_token_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($rawToken, PASSWORD_DEFAULT), $id]);
    }

    public function markAcceptedEmailSent(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET accepted_email_sent_at = ? WHERE id = ?');
        $stmt->execute([$now, $id]);
    }

    public function markRefusedEmailSent(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE registration_requests SET refused_email_sent_at = ? WHERE id = ?');
        $stmt->execute([$now, $id]);
    }

    /**
     * The request already linked to $memberId, if any — Service\
     * ReconciliationService's manual-link pre-check ("refuser un numéro
     * déjà lié à une autre demande"), backed either way by
     * idx_rr_linked_member's UNIQUE constraint at the database level.
     */
    public function findByLinkedMemberId(int $memberId): ?RegistrationRequest
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE linked_member_id = ?');
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * The one write both the automatic reconciliation match and the
     * manual Desk-tiers-number link go through (module spec: "un seul
     * chemin de code, pas deux") — sets the migration target and moves
     * status to 'encoded' in one statement.
     */
    public function linkToMemberAndEncode(int $id, int $memberId, \DateTimeImmutable $finalAt): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE registration_requests SET linked_member_id = ?, status = 'encoded', final_at = ? WHERE id = ?"
        );
        $stmt->execute([$memberId, $finalAt->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Ids of every request in a FINAL state whose final_at is at or before
     * $cutoff — Task\PurgeRegistrationRequestsHandler's own candidate set.
     * Ids only: a row about to be deleted outright is never worth
     * decrypting.
     *
     * @return array<int>
     */
    public function findFinalIdsOlderThan(\DateTimeImmutable $cutoff): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM registration_requests
             WHERE status IN ('encoded', 'refused', 'withdrawn') AND final_at IS NOT NULL AND final_at <= ?"
        );
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Permanent deletion — cascades to registration_request_siblings and
     * registration_secondary_emails via their own ON DELETE CASCADE.
     */
    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM registration_requests WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function normalizeEmail(string $email): string
    {
        return EncryptionService::normalizeEmailForIndex($email);
    }

    /**
     * Comparison-only key, never displayed.
     *
     * The rule itself moved into the core (`Core\Member\NameDobKey`) when
     * a second consumer appeared — the duplicate detector for members
     * re-created in Desk. "Is this the same person?" is a question about
     * members, not about registration requests, and two normalisations of
     * it would be one edit away from disagreeing; a blind index that
     * misses answers "not found" rather than failing, which is the worst
     * way for that to go wrong. This forwarder stays because it is what
     * this module's own call sites and tests grip.
     */
    public static function normalizeForNameDobBlindIndex(string $lastName, string $firstName, string $birthDate): string
    {
        return NameDobKey::normalize($lastName, $firstName, $birthDate);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RegistrationRequest
    {
        return new RegistrationRequest(
            id: (int) $row['id'],
            scoutYearId: (int) $row['scout_year_id'],
            parentName: $this->encryption->decrypt($row['parent_name_encrypted'], 'registration_requests.parent_name'),
            childLastName: $this->encryption->decrypt($row['child_last_name_encrypted'], 'registration_requests.child_last_name'),
            childFirstName: $this->encryption->decrypt($row['child_first_name_encrypted'], 'registration_requests.child_first_name'),
            gender: $this->encryption->decrypt($row['gender_encrypted'], 'registration_requests.gender'),
            birthDate: $this->encryption->decrypt($row['birth_date_encrypted'], 'registration_requests.birth_date'),
            street: $this->encryption->decrypt($row['street_encrypted'], 'registration_requests.street'),
            number: $this->encryption->decrypt($row['number_encrypted'], 'registration_requests.number'),
            postalCode: $this->encryption->decrypt($row['postal_code_encrypted'], 'registration_requests.postal_code'),
            city: $this->encryption->decrypt($row['city_encrypted'], 'registration_requests.city'),
            email: $this->encryption->decrypt($row['email_encrypted'], 'registration_requests.email'),
            phone1: $this->encryption->decrypt($row['phone1_encrypted'], 'registration_requests.phone1'),
            phone2: $row['phone2_encrypted'] !== null ? $this->encryption->decrypt($row['phone2_encrypted'], 'registration_requests.phone2') : null,
            remarks: $row['remarks_encrypted'] !== null ? $this->encryption->decrypt($row['remarks_encrypted'], 'registration_requests.remarks') : null,
            desiredSectionId: $row['desired_section_id'] !== null ? (int) $row['desired_section_id'] : null,
            status: (string) $row['status'],
            receivedAt: DateInput::requireFromStorage((string) $row['received_at'], 'received_at'),
            intendedSectionId: $row['intended_section_id'] !== null ? (int) $row['intended_section_id'] : null,
            feeCategoryId: $row['fee_category_id'] !== null ? (int) $row['fee_category_id'] : null,
            internalNotes: $row['internal_notes_encrypted'] !== null ? $this->encryption->decrypt($row['internal_notes_encrypted'], 'registration_requests.internal_notes') : null,
            linkedMemberId: $row['linked_member_id'] !== null ? (int) $row['linked_member_id'] : null,
            acceptedEmailSentAt: DateInput::fromStorage($row['accepted_email_sent_at'] === null ? null : (string) $row['accepted_email_sent_at']),
            refusedEmailSentAt: DateInput::fromStorage($row['refused_email_sent_at'] === null ? null : (string) $row['refused_email_sent_at']),
            finalAt: DateInput::fromStorage($row['final_at'] === null ? null : (string) $row['final_at'])
        );
    }
}
