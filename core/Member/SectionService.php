<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Database\Connection;
use Core\Security\EncryptionService;

class SectionService
{
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Get all sections with their branch info, ordered by branch sort_order then section name.
     *
     * @return array<int, array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int}>
     */
    public function getAllWithBranches(): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query(
            'SELECT s.id, s.desk_code, s.name, s.email, s.age_branch_id,
                    ab.label AS branch_name, ab.sort_order AS branch_sort_order
             FROM sections s
             JOIN age_branches ab ON s.age_branch_id = ab.id
             ORDER BY ab.sort_order, s.name, s.desk_code'
        );

        if ($stmt === false) {
            return [];
        }

        $sections = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sections[] = [
                'id' => (int) $row['id'],
                'desk_code' => (string) $row['desk_code'],
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'email' => $row['email'] !== null ? (string) $row['email'] : null,
                'age_branch_id' => (int) $row['age_branch_id'],
                'branch_name' => (string) $row['branch_name'],
                'branch_sort_order' => (int) $row['branch_sort_order'],
            ];
        }
        return $sections;
    }

    /**
     * Get a single section by ID with branch info.
     *
     * @return array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int, branch_name: string}|null
     */
    public function getSection(int $sectionId): ?array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare(
            'SELECT s.id, s.desk_code, s.name, s.email, s.age_branch_id,
                    ab.label AS branch_name
             FROM sections s
             JOIN age_branches ab ON s.age_branch_id = ab.id
             WHERE s.id = ?'
        );
        $stmt->execute([$sectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'age_branch_id' => (int) $row['age_branch_id'],
            'branch_name' => (string) $row['branch_name'],
        ];
    }

    /**
     * Get the staff (animateurs) for a section in the current scout year.
     * Returns decrypted MemberProfile objects for members whose function
     * is linked to this section.
     *
     * @return MemberProfile[]
     */
    public function getSectionStaff(int $sectionId, int $scoutYearId): array
    {
        $pdo = $this->connection->getPdo();

        // Find all distinct member_year IDs linked to this section
        $stmt = $pdo->prepare(
            'SELECT DISTINCT mf.member_year_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             WHERE mf.section_id = ? AND my.scout_year_id = ?'
        );
        $stmt->execute([$sectionId, $scoutYearId]);
        $memberYearIds = array_map(fn(array $row) => (int) $row['member_year_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        if (count($memberYearIds) === 0) {
            return [];
        }

        $profiles = [];
        foreach ($memberYearIds as $myId) {
            $profile = $this->hydrateMemberProfile($myId);
            if ($profile !== null) {
                $profiles[] = $profile;
            }
        }

        // Sort by display name
        usort($profiles, fn(MemberProfile $a, MemberProfile $b) =>
            strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $profiles;
    }

    /**
     * Get staff members who have an admin or chief role function but no section assignment.
     * These are considered part of "Staff d'U".
     *
     * @return MemberProfile[]
     */
    public function getUnitStaff(int $scoutYearId): array
    {
        $pdo = $this->connection->getPdo();

        // Find member_year IDs with admin/chief functions but no section
        $stmt = $pdo->prepare(
            'SELECT DISTINCT mf.member_year_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ?
               AND f.role IN (\'admin\', \'chief\')
               AND mf.section_id IS NULL'
        );
        $stmt->execute([$scoutYearId]);
        $memberYearIds = array_map(fn(array $row) => (int) $row['member_year_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        if (count($memberYearIds) === 0) {
            return [];
        }

        $profiles = [];
        foreach ($memberYearIds as $myId) {
            $profile = $this->hydrateMemberProfile($myId);
            if ($profile !== null) {
                $profiles[] = $profile;
            }
        }

        usort($profiles, fn(MemberProfile $a, MemberProfile $b) =>
            strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return $profiles;
    }

    /**
     * Update a section's configurable info (name, email).
     */
    public function updateSectionInfo(int $sectionId, ?string $name, ?string $email): void
    {
        $pdo = $this->connection->getPdo();
        $cleanName = $name !== null && trim($name) !== '' ? trim($name) : null;
        $cleanEmail = $email !== null && trim($email) !== '' ? trim($email) : null;

        $stmt = $pdo->prepare('UPDATE sections SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$cleanName, $cleanEmail, $sectionId]);
    }

    /**
     * Hydrate a MemberProfile from a member_year ID.
     */
    private function hydrateMemberProfile(int $memberYearId): ?MemberProfile
    {
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare('SELECT my.*, m.desk_id FROM member_years my JOIN members m ON my.member_id = m.id WHERE my.id = ?');
        $stmt->execute([$memberYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Load functions with branch/section info
        $stmt = $pdo->prepare(
            'SELECT mf.*, f.label as function_label, f.role as function_role,
                    ab.label as branch_name, s.name as section_name, s.desk_code as section_code
             FROM member_functions mf
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN age_branches ab ON mf.age_branch_id = ab.id
             LEFT JOIN sections s ON mf.section_id = s.id
             WHERE mf.member_year_id = ?'
        );
        $stmt->execute([$memberYearId]);
        $functions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fnRow) {
            $functions[] = new MemberFunctionInfo(
                functionLabel: $fnRow['function_label'],
                functionRole: $fnRow['function_role'],
                branchName: $fnRow['branch_name'],
                sectionName: $fnRow['section_name'],
                sectionCode: $fnRow['section_code'],
                isMainFunction: (bool) $fnRow['is_main_function'],
                startDate: $fnRow['start_date'],
                endDate: $fnRow['end_date'],
            );
        }

        // Get scout year label
        $stmt = $pdo->prepare('SELECT label FROM scout_years WHERE id = ?');
        $stmt->execute([$row['scout_year_id']]);
        $scoutYearLabel = (string) $stmt->fetchColumn();

        return new MemberProfile(
            memberYearId: (int) $row['id'],
            memberId: (int) $row['member_id'],
            deskId: $row['desk_id'],
            firstName: $this->encryption->decrypt($row['first_name_encrypted']),
            lastName: $this->encryption->decrypt($row['last_name_encrypted']),
            totem: $row['totem_encrypted'] ? $this->encryption->decrypt($row['totem_encrypted']) : null,
            quali: $row['quali_encrypted'] ? $this->encryption->decrypt($row['quali_encrypted']) : null,
            gender: $row['gender_encrypted'] ? $this->encryption->decrypt($row['gender_encrypted']) : null,
            birthDate: $row['birth_date_encrypted'] ? $this->encryption->decrypt($row['birth_date_encrypted']) : null,
            phone: $row['phone_encrypted'] ? $this->encryption->decrypt($row['phone_encrypted']) : null,
            mobile: $row['mobile_encrypted'] ? $this->encryption->decrypt($row['mobile_encrypted']) : null,
            email: $row['email_encrypted'] ? $this->encryption->decrypt($row['email_encrypted']) : null,
            patrol: $row['patrol_encrypted'] ? $this->encryption->decrypt($row['patrol_encrypted']) : null,
            formationLevel: $row['formation_level'],
            federationMailConsent: (bool) $row['federation_mail_consent'],
            unitMailConsent: (bool) $row['unit_mail_consent'],
            addresses: [],
            functions: $functions,
            scoutYearLabel: $scoutYearLabel
        );
    }
}
