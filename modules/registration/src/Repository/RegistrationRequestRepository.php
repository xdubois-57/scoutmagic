<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Member\Household\HouseholdKey;
use Core\Member\Household\HouseholdRepository;
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
    private HouseholdRepository $households;

    private DecisionEmailRepository $decisionEmails;

    /**
     * $households is the core's, and the only thing here that knows how an
     * address becomes a household (issue #630). Built from the same
     * connection when the caller passes none, so the repository cannot be
     * constructed in a state where it would have to derive a key itself.
     *
     * $decisionEmails likewise (issue #843, IT-05): a status change
     * supersedes the decision e-mails of the old decision in the same
     * transaction (updateStatus()), and every request read here carries
     * the date its current decision's e-mail first went out — so both
     * must be on this repository's own connection.
     */
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        ?HouseholdRepository $households = null,
        ?DecisionEmailRepository $decisionEmails = null
    ) {
        $this->households = $households ?? new HouseholdRepository($pdo, $encryption);
        $this->decisionEmails = $decisionEmails ?? new DecisionEmailRepository($pdo);
    }

    /**
     * `previous_unit_answer`/`previous_unit_name` are OPTIONAL keys, not
     * missing ones: a caller that predates issue #331 — the reference
     * dataset's own seeder among them — files a request on which the
     * question was never put, which is exactly the NULL schema.sql
     * describes. The public form always supplies the answer.
     *
     * @param array{
     *   parent_name: string, child_last_name: string, child_first_name: string,
     *   gender: string, birth_date: string, street: string, number: string,
     *   postal_code: string, city: string, email: string, phone1: string,
     *   phone2: ?string, remarks: ?string,
     *   previous_unit_answer?: ?string, previous_unit_name?: ?string
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

        $nameDobBlind = $this->encryption->blindIndex(
            self::normalizeForNameDobBlindIndex(
                $fields['child_last_name'],
                $fields['child_first_name'],
                $fields['birth_date']
            ),
            'registration_name_dob'
        );

        // The household is the core's to derive (issue #630): this module
        // stores the identity it is handed and never learns how an address
        // becomes one. It used to normalize and blind-index the address
        // itself, a second derivation of the core's key that would have
        // stopped matching, silently, the day either side changed.
        $addressBlind = $this->households->keyForAddress(
            $fields['street'],
            $fields['number'],
            null,
            $fields['postal_code']
        )?->storable();

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
                $fields['phone2'] !== null
                    ? $this->encryption->encrypt($fields['phone2'], 'registration_requests.phone2')
                    : null,
                $fields['remarks'] !== null && $fields['remarks'] !== ''
                    ? $this->encryption->encrypt($fields['remarks'], 'registration_requests.remarks')
                    : null,
                $nameDobBlind,
                $desiredSectionId,
                RegistrationRequest::STATUS_PENDING,
                $trackingTokenHash,
                $addressBlind,
                self::normalizePreviousUnitAnswer($fields['previous_unit_answer'] ?? null),
                // Only ever stored with a « oui »: a name left over from a
                // family who changed their answer back to « non » would say
                // the opposite of what they told the unit.
                ($fields['previous_unit_answer'] ?? null) === RegistrationRequest::PREVIOUS_UNIT_YES
                    && ($fields['previous_unit_name'] ?? null) !== null
                    && $fields['previous_unit_name'] !== ''
                        ? $this->encryption->encrypt(
                            (string) $fields['previous_unit_name'],
                            'registration_requests.previous_unit_name'
                        )
                        : null,
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
                desired_section_id, status, tracking_token_hash, address_normalized_blind_index,
                previous_unit_answer, previous_unit_name_encrypted
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC))[0] ?? null;
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

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
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
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE scout_year_id = ? ORDER BY received_at '
            . 'ASC, id ASC');
        $stmt->execute([$scoutYearId]);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * « Demandes en attente » on the management dashboard: requests still
     * waiting for a decision, in the given scout years.
     *
     * @param array<int> $scoutYearIds
     */
    public function countPendingForYears(array $scoutYearIds): int
    {
        $scoutYearIds = self::distinctIds($scoutYearIds);
        if ($scoutYearIds === []) {
            return 0;
        }
        $placeholders = self::placeholdersFor($scoutYearIds);

        return $this->countRows(
            "SELECT COUNT(*) FROM registration_requests
             WHERE scout_year_id IN ({$placeholders}) AND status = 'pending'",
            $scoutYearIds
        );
    }

    /**
     * « E-mails de décision à envoyer » on the management dashboard: every
     * decision whose e-mail has not left yet, in the given scout years.
     *
     * The definition is the one the family's own page applies
     * (Service\RequestStatusService::visibleStatus()): an acceptance —
     * still `accepted`, or already `encoded` in Desk — or a refusal stays
     * invisible to the family until an e-mail of THAT decision, not
     * superseded by a later status change, has been sent
     * (`registration_decision_emails`, issue #843 IT-05). So this counts
     * exactly the decisions a family cannot see yet, whatever their e-mail
     * is doing — scheduled, failed, or never planned. Withdrawn requests
     * send nothing and are never counted.
     *
     * @param array<int> $scoutYearIds
     */
    public function countDecisionEmailsToSendForYears(array $scoutYearIds): int
    {
        $scoutYearIds = self::distinctIds($scoutYearIds);
        if ($scoutYearIds === []) {
            return 0;
        }
        $placeholders = self::placeholdersFor($scoutYearIds);

        return $this->countRows(
            "SELECT COUNT(*) FROM registration_requests r
             WHERE r.scout_year_id IN ({$placeholders})
               AND r.status IN ('accepted', 'encoded', 'refused')
               AND NOT EXISTS (
                   SELECT 1 FROM registration_decision_emails e
                   WHERE e.registration_request_id = r.id
                     AND e.state = 'sent' AND e.superseded_at IS NULL
                     AND e.decision = CASE WHEN r.status = 'refused' THEN 'refused' ELSE 'accepted' END
               )",
            $scoutYearIds
        );
    }

    /**
     * « Acceptées, pas encore dans Desk » on the management dashboard:
     * accepted requests that no Desk import (nor a manual link) has turned
     * into a member yet — `encoded` is the state that means "in Desk".
     *
     * @param array<int> $scoutYearIds
     */
    public function countAcceptedNotEncodedForYears(array $scoutYearIds): int
    {
        $scoutYearIds = self::distinctIds($scoutYearIds);
        if ($scoutYearIds === []) {
            return 0;
        }
        $placeholders = self::placeholdersFor($scoutYearIds);

        return $this->countRows(
            "SELECT COUNT(*) FROM registration_requests
             WHERE scout_year_id IN ({$placeholders}) AND status = 'accepted'",
            $scoutYearIds
        );
    }

    /**
     * The year ids once each, as integers — a repeated year must not need
     * a second placeholder.
     *
     * @param array<int> $ids
     * @return array<int, int>
     */
    private static function distinctIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * One `?` per value, for an `IN (…)` whose values all travel as bound
     * parameters.
     *
     * @param array<int, int|string> $ids
     */
    private static function placeholdersFor(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * @param array<int, int> $params
     */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
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

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
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
     * Every 'accepted' request for $scoutYearId, fully decrypted — the
     * fourth source of Service\ForecastService's projection, and through
     * it the « Demandes acceptées » column of the capacity check and the
     * level shown to the public (module spec: accepted requests are real
     * commitments, unlike still-undecided pending ones).
     *
     * @return array<RegistrationRequest>
     */
    public function findAcceptedForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registration_requests WHERE scout_year_id = ? AND status = 'accepted'"
        );
        $stmt->execute([$scoutYearId]);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every 'encoded' request for $scoutYearId, fully decrypted — what
     * Service\SlotService needs to keep an accepted request in exactly one
     * place once Desk knows the child: through the member it was linked
     * to when the projection holds that member, through its own birth
     * date when it does not yet (R1, docs/chantiers/
     * CHANTIER-formulaire-inscription.md).
     *
     * @return array<RegistrationRequest>
     */
    public function findEncodedForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registration_requests WHERE scout_year_id = ? AND status = 'encoded'"
        );
        $stmt->execute([$scoutYearId]);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
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
            "SELECT linked_member_id FROM registration_requests WHERE scout_year_id = ? AND status = 'encoded' AND "
                . "linked_member_id IS NOT NULL"
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
            static fn(array $row) => [
                'id' => (int) $row['id'],
                'name_dob_blind_index' => (string) $row['name_dob_blind_index']
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Count of 'accepted'/'encoded' requests submitted from $household,
     * for $scoutYearId, excluding
     * $excludeRequestId (a request must never count itself while its own
     * fiche is being estimated) — Api\HouseholdRegistrationCountProvider's
     * implementation, injected nullable into Core\Member\
     * FeeEstimationService (ARCHITECTURE.md §7.5).
     */
    public function countRequestsInHousehold(HouseholdKey $household, int $scoutYearId, ?int $excludeRequestId): int
    {
        $sql = "SELECT COUNT(*) FROM registration_requests
                WHERE address_normalized_blind_index = ? AND scout_year_id = ? AND status IN ('accepted', 'encoded')";
        $params = [$household->storable(), $scoutYearId];
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
     * HouseholdRegistrationCountProvider::countsInHouseholds(), which
     * Core\Member\Household\HouseholdService calls once for a whole
     * scout year rather than once per household.
     *
     * @param HouseholdKey[] $households
     * @return array<string, int> {@see HouseholdKey::storable()} => count
     *         (households with no matching request are absent)
     */
    public function countRequestsInHouseholds(array $households, int $scoutYearId): array
    {
        $addressBlindIndexes = array_values(array_unique(array_map(
            static fn(HouseholdKey $household): string => $household->storable(),
            $households
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
        $stmt = $this->pdo->prepare('SELECT member_id FROM registration_request_siblings WHERE '
            . 'registration_request_id = ?');
        $stmt->execute([$requestId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every manual status transition (accept/refuse/withdraw/revert) goes
     * through here. $finalAt is set the moment $status becomes one of
     * RegistrationRequest::FINAL_STATUSES (module spec: retention counters
     * start there, never at received_at), and cleared back to NULL by
     * "revenir en attente" — the one transition that leaves a final state.
     *
     * The refusal reason (issue #843, D6) is written with the status, in
     * the same statement, so it can never describe a status the request no
     * longer has: kept only when $status is 'refused' and $refusalReason is
     * one of RegistrationRequest::REFUSAL_*, cleared by every other
     * transition — a request put back in the pending list was not refused
     * for lack of room any more.
     */
    public function updateStatus(
        int $id,
        string $status,
        ?\DateTimeImmutable $finalAt,
        ?string $refusalReason = null
    ): int {
        $storedReason = $status === RegistrationRequest::STATUS_REFUSED
            ? self::normalizeRefusalReason($refusalReason)
            : null;

        return $this->transactional(function () use ($id, $status, $finalAt, $storedReason): int {
            // The request row first, then its e-mails — the order the send
            // path reads them in (DecisionEmailRepository::insert() selects
            // FROM this row), so a send racing this change either sees the
            // new status and creates nothing, or ran entirely before it and
            // is superseded below.
            $stmt = $this->pdo->prepare(
                'UPDATE registration_requests SET status = ?, final_at = ?, refusal_reason = ? WHERE id = ?'
            );
            $stmt->execute([$status, $finalAt?->format('Y-m-d H:i:s'), $storedReason, $id]);

            // A changed decision makes every e-mail written for the old one
            // obsolete (issue #843, D8): none of them may leave any more,
            // and none of them may ever make the new decision visible to
            // the family (R3). Here rather than in a service so that no
            // path writing a status — the fiche, the bulk actions, the
            // decision dialog — can change a decision and leave its old
            // e-mail scheduled.
            return $this->decisionEmails->supersedeForRequest($id, new \DateTimeImmutable());
        });
    }

    /**
     * Every live request of one scout year — pending, accepted or encoded —
     * as Service\RecommendationService's queue reads it (issue #843, D5):
     * the pending ones are queued, and all three decide which request of a
     * child filed twice is « la plus ancienne ». Refused and withdrawn
     * requests are closed, so a child filing again after one of those is
     * not a duplicate to settle.
     *
     * Only the birth date of a pending request is decrypted — the queue
     * needs its slot and nothing else of the fiche, and an accepted or
     * encoded one only for its child key and its filing. Ordered by filing
     * (received_at, then id), the queue's own tie-break.
     *
     * @return array<QueueCandidate>
     */
    public function findQueueCandidatesForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, status, received_at, name_dob_blind_index, birth_date_encrypted
             FROM registration_requests
             WHERE scout_year_id = ? AND status IN ('pending', 'accepted', 'encoded')
             ORDER BY received_at ASC, id ASC"
        );
        $stmt->execute([$scoutYearId]);

        return array_map(
            fn(array $row) => new QueueCandidate(
                id: (int) $row['id'],
                status: (string) $row['status'],
                receivedAt: DateInput::requireFromStorage((string) $row['received_at'], 'received_at'),
                nameDobBlindIndex: (string) $row['name_dob_blind_index'],
                birthDate: $row['status'] === RegistrationRequest::STATUS_PENDING
                    ? $this->encryption->decrypt($row['birth_date_encrypted'], 'registration_requests.birth_date')
                    : null
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * « Ancienne demande restée sans place » (issue #843, D5): for each
     * child key among $nameDobBlindIndexes, the label of the most recent
     * scout year STARTING BEFORE $beforeStartDate in which that child had a
     * request refused with the reason 'no_room'.
     *
     * A refusal without a recorded reason does not count — nobody said it
     * was for lack of room — and neither does one of the same year or a
     * later one: the criterion is about a family who already waited.
     *
     * @param array<string> $nameDobBlindIndexes
     * @param string $beforeStartDate a scout year's start date, "YYYY-MM-DD"
     * @return array<string, string> blind index => scout year label (keys
     *         without such a refusal are absent)
     */
    public function findNoRoomRefusalYearLabels(array $nameDobBlindIndexes, string $beforeStartDate): array
    {
        $keys = array_values(array_unique($nameDobBlindIndexes));
        if ($keys === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.name_dob_blind_index, y.label, y.start_date
             FROM registration_requests r
             JOIN scout_years y ON y.id = r.scout_year_id
             WHERE r.status = 'refused' AND r.refusal_reason = 'no_room' AND y.start_date < ?
               AND r.name_dob_blind_index IN (" . self::placeholdersFor($keys) . ')
             ORDER BY y.start_date ASC, r.id ASC'
        );
        $stmt->execute(array_merge([$beforeStartDate], $keys));

        $labels = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // Ascending, so the last row written for a key is its most
            // recent year.
            $labels[(string) $row['name_dob_blind_index']] = (string) $row['label'];
        }

        return $labels;
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
     * Runs $work in one transaction on this repository's connection — or
     * inside the one already open, which then decides. What the decision
     * dialog needs (Service\RequestDecisionService::decide()): its status
     * and its note land together or not at all. updateStatus(),
     * linkToMemberAndEncode() and deleteById() go through it too, so the
     * decision e-mails they supersede or delete (issue #843, IT-05) commit
     * or roll back with whatever transaction they run in.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
        $stmt->execute([$notes !== null && $notes !== '' ? $this->encryption->encrypt(
            $notes,
            'registration_requests.internal_notes'
        ) : null,
            $id]);
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

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC))[0] ?? null;
    }

    /**
     * The one write both the automatic reconciliation match and the
     * manual Desk-tiers-number link go through (module spec: "un seul
     * chemin de code, pas deux") — sets the migration target and moves
     * status to 'encoded' in one statement.
     */
    public function linkToMemberAndEncode(int $id, int $memberId, \DateTimeImmutable $finalAt): void
    {
        $this->transactional(function () use ($id, $memberId, $finalAt): void {
            $stmt = $this->pdo->prepare(
                "UPDATE registration_requests SET linked_member_id = ?, status = 'encoded', final_at = ? WHERE id = ?"
            );
            $stmt->execute([$memberId, $finalAt->format('Y-m-d H:i:s'), $id]);

            // Encoding keeps an acceptance what it was: its e-mails still
            // describe the decision. A request linked from any other state
            // (the manual link does not ask) has just become an acceptance,
            // and whatever was written for its earlier decision is obsolete,
            // exactly as in updateStatus().
            $this->decisionEmails->supersedeForRequest(
                $id,
                new \DateTimeImmutable(),
                DecisionEmail::DECISION_ACCEPTED
            );
        });
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
     * registration_secondary_emails via their own ON DELETE CASCADE. The
     * request's decision e-mails (issue #843, R7) are deleted explicitly
     * first, in the same transaction, so the retention purge removes them
     * whether or not the engine enforces their own cascade.
     */
    public function deleteById(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->decisionEmails->deleteForRequest($id);

            $stmt = $this->pdo->prepare('DELETE FROM registration_requests WHERE id = ?');
            $stmt->execute([$id]);
        });
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
     * Every row, hydrated, with the date its current decision's e-mail
     * first went out — read in one query for the whole set rather than one
     * per request (Repository\DecisionEmailRepository::
     * firstSentAtByRequest()).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, RegistrationRequest>
     */
    private function hydrateAll(array $rows): array
    {
        $sentAt = $this->decisionEmails->firstSentAtByRequest(
            array_map(static fn(array $row): int => (int) $row['id'], $rows)
        );

        return array_map(function (array $row) use ($sentAt): RegistrationRequest {
            // Only the e-mail of the decision the request holds NOW: a
            // refused request whose acceptance e-mail went out earlier owes
            // its refusal e-mail, and is not "sent".
            $decision = DecisionEmail::decisionForStatus((string) $row['status']);

            return $this->hydrate(
                $row,
                $decision !== null ? ($sentAt[(int) $row['id']][$decision] ?? null) : null
            );
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, ?\DateTimeImmutable $decisionEmailSentAt): RegistrationRequest
    {
        return new RegistrationRequest(
            id: (int) $row['id'],
            scoutYearId: (int) $row['scout_year_id'],
            parentName: $this->encryption->decrypt($row['parent_name_encrypted'], 'registration_requests.parent_name'),
            childLastName: $this->encryption->decrypt(
                $row['child_last_name_encrypted'],
                'registration_requests.child_last_name'
            ),
            childFirstName: $this->encryption->decrypt(
                $row['child_first_name_encrypted'],
                'registration_requests.child_first_name'
            ),
            gender: $this->encryption->decrypt($row['gender_encrypted'], 'registration_requests.gender'),
            birthDate: $this->encryption->decrypt($row['birth_date_encrypted'], 'registration_requests.birth_date'),
            street: $this->encryption->decrypt($row['street_encrypted'], 'registration_requests.street'),
            number: $this->encryption->decrypt($row['number_encrypted'], 'registration_requests.number'),
            postalCode: $this->encryption->decrypt($row['postal_code_encrypted'], 'registration_requests.postal_code'),
            city: $this->encryption->decrypt($row['city_encrypted'], 'registration_requests.city'),
            email: $this->encryption->decrypt($row['email_encrypted'], 'registration_requests.email'),
            phone1: $this->encryption->decrypt($row['phone1_encrypted'], 'registration_requests.phone1'),
            phone2: $row['phone2_encrypted'] !== null
                ? $this->encryption->decrypt($row['phone2_encrypted'], 'registration_requests.phone2')
                : null,
            remarks: $row['remarks_encrypted'] !== null
                ? $this->encryption->decrypt($row['remarks_encrypted'], 'registration_requests.remarks')
                : null,
            desiredSectionId: $row['desired_section_id'] !== null ? (int) $row['desired_section_id'] : null,
            status: (string) $row['status'],
            receivedAt: DateInput::requireFromStorage((string) $row['received_at'], 'received_at'),
            intendedSectionId: $row['intended_section_id'] !== null ? (int) $row['intended_section_id'] : null,
            feeCategoryId: $row['fee_category_id'] !== null ? (int) $row['fee_category_id'] : null,
            internalNotes: $row['internal_notes_encrypted'] !== null
                ? $this->encryption->decrypt($row['internal_notes_encrypted'], 'registration_requests.internal_notes')
                : null,
            linkedMemberId: $row['linked_member_id'] !== null ? (int) $row['linked_member_id'] : null,
            decisionEmailSentAt: $decisionEmailSentAt,
            finalAt: DateInput::fromStorage($row['final_at'] === null ? null : (string) $row['final_at']),
            previousUnitAnswer: $row['previous_unit_answer'] !== null
                ? (string) $row['previous_unit_answer']
                : null,
            previousUnitName: $row['previous_unit_name_encrypted'] !== null
                ? $this->encryption->decrypt(
                    $row['previous_unit_name_encrypted'],
                    'registration_requests.previous_unit_name'
                )
                : null,
            refusalReason: self::normalizeRefusalReason(
                isset($row['refusal_reason']) ? (string) $row['refusal_reason'] : null
            )
        );
    }

    /**
     * The column is an ENUM of two reasons plus NULL (schema.sql). Anything
     * else is no known reason — never a value MySQL would coerce to the
     * empty string, and never one a reading would mistake for « Pas de
     * place ».
     */
    private static function normalizeRefusalReason(?string $reason): ?string
    {
        return isset(RegistrationRequest::REFUSAL_REASON_LABELS[(string) $reason]) ? $reason : null;
    }

    /**
     * The column is an ENUM of two values plus NULL, and NULL carries its
     * own meaning — « the question was not put » (schema.sql). Anything
     * that is neither answer lands there rather than in a value MySQL
     * would silently coerce to the empty string.
     */
    private static function normalizePreviousUnitAnswer(?string $answer): ?string
    {
        return in_array(
            $answer,
            [RegistrationRequest::PREVIOUS_UNIT_NO, RegistrationRequest::PREVIOUS_UNIT_YES],
            true
        ) ? $answer : null;
    }
}
