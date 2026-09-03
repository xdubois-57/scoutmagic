<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

use Core\Security\EncryptionService;

/**
 * Reads and writes `member_duplicate_candidates`, plus the two decrypting
 * reads the detector needs.
 *
 * The decrypting reads are the expensive part of this feature, which is
 * why they run once per import rather than once per page view (see
 * {@see DuplicateMemberDetector}).
 */
class DuplicateMemberRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Decrypted identities for specific members, in one scout year.
     *
     * @param int[] $memberIds
     * @return array<int, array{member_id: int, first_name: string, last_name: string, birth_date: ?string}>
     */
    public function findIdentitiesForMembers(array $memberIds, int $scoutYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted
             FROM member_years
             WHERE scout_year_id = ? AND member_id IN ({$placeholders})"
        );
        $stmt->execute([$scoutYearId, ...$memberIds]);

        return $this->hydrateIdentities($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Decrypted identities from every scout year strictly earlier than
     * the one given.
     *
     * Merged-away identities are excluded: a row that has already been
     * folded into another is not a candidate to fold something else into.
     *
     * @return array<int, array{member_id: int, first_name: string, last_name: string, birth_date: ?string}>
     */
    public function findIdentitiesBeforeYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.member_id, my.first_name_encrypted, my.last_name_encrypted, my.birth_date_encrypted
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             JOIN members m ON m.id = my.member_id
             WHERE m.merged_into_member_id IS NULL
               AND sy.start_date < (SELECT start_date FROM scout_years WHERE id = ?)'
        );
        $stmt->execute([$scoutYearId]);

        return $this->hydrateIdentities($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Whether two identities have ever shared a normalised address.
     *
     * Compared through the blind index already on `member_addresses`
     * (§8) — no decryption, and the address itself is never read.
     */
    public function shareAnAddress(int $memberIdA, int $memberIdB): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM member_addresses a
             JOIN member_years mya ON mya.id = a.member_year_id
             JOIN member_addresses b ON b.address_normalized_blind_index = a.address_normalized_blind_index
             JOIN member_years myb ON myb.id = b.member_year_id
             WHERE mya.member_id = ? AND myb.member_id = ?
               AND a.address_normalized_blind_index IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([$memberIdA, $memberIdB]);

        return $stmt->fetchColumn() !== false;
    }

    /** Whether this pair has already been proposed, in either direction. */
    public function hasPair(int $keptMemberId, int $duplicateMemberId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM member_duplicate_candidates
             WHERE (kept_member_id = ? AND duplicate_member_id = ?)
                OR (kept_member_id = ? AND duplicate_member_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$keptMemberId, $duplicateMemberId, $duplicateMemberId, $keptMemberId]);

        return $stmt->fetchColumn() !== false;
    }

    public function recordCandidate(int $keptMemberId, int $duplicateMemberId, bool $sameAddress): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO member_duplicate_candidates (kept_member_id, duplicate_member_id, same_address, status, detected_at)
             VALUES (?, ?, ?, 'pending', ?)"
        );
        $stmt->execute([$keptMemberId, $duplicateMemberId, $sameAddress ? 1 : 0, date('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The pairs nobody has decided about yet, oldest first.
     *
     * @return array<int, array{id: int, kept_member_id: int, duplicate_member_id: int, same_address: bool}>
     */
    public function findPending(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, kept_member_id, duplicate_member_id, same_address
             FROM member_duplicate_candidates
             WHERE status = \'pending\'
             ORDER BY detected_at, id'
        );
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'kept_member_id' => (int) $row['kept_member_id'],
                'duplicate_member_id' => (int) $row['duplicate_member_id'],
                'same_address' => (bool) $row['same_address'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM member_duplicate_candidates WHERE status = 'pending'");

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * @return array{id: int, kept_member_id: int, duplicate_member_id: int, same_address: bool, status: string}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_duplicate_candidates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'kept_member_id' => (int) $row['kept_member_id'],
            'duplicate_member_id' => (int) $row['duplicate_member_id'],
            'same_address' => (bool) $row['same_address'],
            'status' => (string) $row['status'],
        ];
    }

    /** 'merged' or 'distinct' — both are decisions, and both are remembered. */
    public function decide(int $id, string $status, ?int $userAccountId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_duplicate_candidates SET status = ?, decided_at = ?, decided_by = ? WHERE id = ?'
        );
        $stmt->execute([$status, date('Y-m-d H:i:s'), $userAccountId, $id]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{member_id: int, first_name: string, last_name: string, birth_date: ?string}>
     */
    private function hydrateIdentities(array $rows): array
    {
        $identities = [];
        foreach ($rows as $row) {
            $firstName = $this->decrypt($row['first_name_encrypted'], 'member_years.first_name');
            $lastName = $this->decrypt($row['last_name_encrypted'], 'member_years.last_name');
            if ($firstName === null || $lastName === null) {
                continue;
            }

            $identities[] = [
                'member_id' => (int) $row['member_id'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $this->decrypt($row['birth_date_encrypted'], 'member_years.birth_date'),
            ];
        }

        return $identities;
    }

    private function decrypt(mixed $value, string $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $this->encryption->decrypt(
                is_resource($value) ? (string) stream_get_contents($value) : (string) $value,
                $context
            );
        } catch (\Throwable) {
            // One unreadable row must not stop the whole detection: the
            // worst case is a duplicate this pass does not propose, which
            // the next import will.
            return null;
        }
    }
}
