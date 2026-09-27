<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Security\EncryptionService;

/**
 * The roster reads behind « Passage », « Prévisions » and the reenrollment
 * campaign: who the animés of a year are, who shares their address, what
 * their names and sections read as.
 *
 * These statements used to live in `Service\PassageService` and
 * `Service\ReenrollmentRecipientService` (issue #593). ARCHITECTURE.md §13
 * and SECURITY.md §1 keep PDO in the Repository layer, and §5 keeps
 * decryption there too, so what the services now receive is already the
 * plain value they asked for — never a ciphertext to decrypt themselves —
 * except where a caller has always received the raw row
 * ({@see findAnimeMemberYears()}).
 *
 * « Animé » has one definition here, shared by every method that asks it:
 * an active row, a function in a section, and a role that is not staff —
 * `f.role NOT IN ('chief', 'admin', 'intendant')`, the same trap
 * `Core\Member\SectionService::getSectionStaff()` guards against.
 */
class PassageRosterRepository
{
    private const NOT_STAFF = "f.role NOT IN ('chief', 'admin', 'intendant') AND mf.section_id IS NOT NULL";

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * What decides where an animé goes next year: their birth date,
     * decrypted, and the chief's offset. Null when $memberId is not an
     * animé still coming back in $scoutYearId.
     *
     * @return array{birth_date: ?string, scout_year_offset: int}|null
     */
    public function findArrivalAge(int $memberId, int $scoutYearId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.birth_date_encrypted, my.scout_year_offset
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.member_id = ? AND my.scout_year_id = ? AND my.is_active = 1 AND my.leaving = 0
               AND ' . self::NOT_STAFF . '
             ORDER BY mf.is_main_function DESC, mf.id ASC
             LIMIT 1'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'birth_date' => $row['birth_date_encrypted'] !== null
                ? $this->encryption->decrypt($row['birth_date_encrypted'], 'member_years.birth_date')
                : null,
            'scout_year_offset' => (int) $row['scout_year_offset'],
        ];
    }

    /**
     * Every animé of $scoutYearId, one row per member_year: the main
     * function first, then the lowest `mf.id` — without that second
     * tie-break a member with several non-staff functions and no main one
     * could change section between two page loads.
     *
     * The row is returned as stored, encrypted columns included: that is
     * what `PassageService::getAnimeMemberYears()` has always handed its
     * callers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAnimeMemberYears(int $scoutYearId, bool $includeLeaving): array
    {
        $leavingFilter = $includeLeaving ? '' : ' AND my.leaving = 0';

        $stmt = $this->pdo->prepare(
            'SELECT my.id AS member_year_id, my.member_id, my.first_name_encrypted, my.last_name_encrypted,
                    my.birth_date_encrypted, my.gender_encrypted, my.scout_year_offset, mf.section_id
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1' . $leavingFilter . '
               AND ' . self::NOT_STAFF . '
             ORDER BY my.id, mf.is_main_function DESC, mf.id ASC'
        );
        $stmt->execute([$scoutYearId]);

        $byMemberYear = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberYearId = (int) $row['member_year_id'];
            if (!isset($byMemberYear[$memberYearId])) {
                $byMemberYear[$memberYearId] = $row;
            }
        }

        return array_values($byMemberYear);
    }

    /**
     * The subset of $memberIds who are animés in $scoutYearId. Nothing is
     * decrypted.
     *
     * @param array<int, int> $memberIds
     * @return array<int, int> member ids, in no particular order
     */
    public function findAnimeMemberIdsAmong(int $scoutYearId, array $memberIds, bool $includeLeaving): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $leavingFilter = $includeLeaving ? '' : ' AND my.leaving = 0';
        $placeholders = implode(', ', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT my.member_id
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ? AND my.is_active = 1' . $leavingFilter . '
               AND my.member_id IN (' . $placeholders . ')
               AND ' . self::NOT_STAFF
        );
        $stmt->execute([$scoutYearId, ...$memberIds]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The address blind indexes of each of $memberYearIds — a member with
     * no indexed address is absent.
     *
     * @param array<int> $memberYearIds
     * @return array<int, array<int, string>> member_year_id => blind indexes
     */
    public function findAddressBlindIndexes(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique($memberYearIds));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT member_year_id, address_normalized_blind_index FROM member_addresses
             WHERE member_year_id IN (' . $placeholders . ') AND address_normalized_blind_index IS NOT NULL'
        );
        $stmt->execute($memberYearIds);

        $blinds = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $blinds[(int) $row['member_year_id']][] = (string) $row['address_normalized_blind_index'];
        }

        return $blinds;
    }

    /**
     * Who lives at each of $blindIndexes in $scoutYearId: active,
     * non-leaving, any role (a household can include a staff sibling).
     *
     * @param array<int, string> $blindIndexes
     * @return array<string, array<int, int>> blind index => member_year ids
     */
    public function findActiveOccupants(array $blindIndexes, int $scoutYearId): array
    {
        $blindIndexes = array_values(array_unique($blindIndexes));
        if ($blindIndexes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($blindIndexes), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT ma2.address_normalized_blind_index AS blind, my2.id AS member_year_id
             FROM member_addresses ma2
             JOIN member_years my2 ON my2.id = ma2.member_year_id
             WHERE ma2.address_normalized_blind_index IN (' . $placeholders . ')
               AND my2.scout_year_id = ? AND my2.is_active = 1 AND my2.leaving = 0'
        );
        $stmt->execute([...$blindIndexes, $scoutYearId]);

        $occupants = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $occupants[(string) $row['blind']][] = (int) $row['member_year_id'];
        }

        return $occupants;
    }

    /**
     * Names and section labels for a batch of member_years, one query. The
     * first row wins — main function, then lowest `mf.id` — for the same
     * stability reason as {@see findAnimeMemberYears()}.
     *
     * @param array<int> $memberYearIds
     * @return array<int, array{name: string, section_label: ?string}>
     */
    public function findNamesAndSections(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique($memberYearIds));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT my.id, my.first_name_encrypted, my.last_name_encrypted, s.name, s.desk_code
             FROM member_years my
             LEFT JOIN member_functions mf ON mf.member_year_id = my.id
             LEFT JOIN sections s ON s.id = mf.section_id
             WHERE my.id IN (' . $placeholders . ')
             ORDER BY my.id, mf.is_main_function DESC, mf.id ASC'
        );
        $stmt->execute($memberYearIds);

        $labels = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (isset($labels[$id])) {
                continue;
            }
            $labels[$id] = [
                'name' => $this->decryptName($row['first_name_encrypted'], $row['last_name_encrypted']),
                'section_label' => $row['name'] ?? $row['desk_code'] ?? null,
            ];
        }

        return $labels;
    }

    /**
     * The active member_year of each of $memberIds in $scoutYearId. A
     * member with no active row that year is absent.
     *
     * @param array<int, int> $memberIds
     * @return array<int, int> member id => member_year id
     */
    public function findActiveMemberYearIds(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique($memberIds));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT member_id, id FROM member_years
             WHERE member_id IN (' . $placeholders . ') AND scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([...$memberIds, $scoutYearId]);

        $memberYearIds = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberYearIds[(int) $row['member_id']] = (int) $row['id'];
        }

        return $memberYearIds;
    }

    /**
     * The Desk address and name of each of $memberIds in $scoutYearId,
     * decrypted, in member id order — what a campaign e-mail is sent to
     * and who it names. A member with no address, or an empty one, is
     * absent: there is nobody to write to.
     *
     * @param array<int, int> $memberIds
     * @return array<int, array{member_id: int, email: string, name: string}>
     */
    public function findContacts(int $scoutYearId, array $memberIds): array
    {
        $memberIds = array_values(array_unique($memberIds));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT member_id, first_name_encrypted, last_name_encrypted, email_encrypted
             FROM member_years
             WHERE scout_year_id = ? AND member_id IN (' . $placeholders . ') AND is_active = 1
             ORDER BY member_id ASC'
        );
        $stmt->execute([$scoutYearId, ...$memberIds]);

        $contacts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['email_encrypted'] === null) {
                continue;
            }
            $email = trim($this->encryption->decrypt($row['email_encrypted'], 'member_years.email'));
            if ($email === '') {
                continue;
            }

            $contacts[] = [
                'member_id' => (int) $row['member_id'],
                'email' => $email,
                'name' => $this->decryptName($row['first_name_encrypted'], $row['last_name_encrypted']),
            ];
        }

        return $contacts;
    }

    private function decryptName(?string $firstNameEncrypted, ?string $lastNameEncrypted): string
    {
        $first = $firstNameEncrypted !== null
            ? $this->encryption->decrypt($firstNameEncrypted, 'member_years.first_name')
            : '';
        $last = $lastNameEncrypted !== null
            ? $this->encryption->decrypt($lastNameEncrypted, 'member_years.last_name')
            : '';

        return trim($first . ' ' . $last);
    }
}
