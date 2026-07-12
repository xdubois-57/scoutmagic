<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Security\EncryptionService;
use Core\Security\Role;

class MemberService
{
    public function __construct(
        private MemberYearRepository $memberYearRepo,
        private EncryptionService $encryption,
        private Connection $connection
    ) {
    }

    /**
     * Get all members linked to an email for a given scout year.
     * Returns decrypted MemberProfile objects.
     *
     * @return MemberProfile[]
     */
    public function getLinkedMembers(string $email, int $scoutYearId): array
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);
        $memberYearRows = $this->memberYearRepo->findAllByEmail($blindIndex, $scoutYearId);

        $profiles = [];
        foreach ($memberYearRows as $row) {
            $profiles[] = $this->hydrateMemberProfile($row);
        }
        return $profiles;
    }

    /**
     * Get a single member's full profile for a given scout year.
     * Includes: identity, addresses, functions, section info.
     * All personal data decrypted.
     *
     * @throws MemberNotFoundException
     */
    public function getMemberProfile(int $memberYearId): MemberProfile
    {
        $row = $this->memberYearRepo->findById($memberYearId);
        if ($row === null) {
            throw new MemberNotFoundException("Member year {$memberYearId} not found");
        }

        return $this->hydrateMemberProfile($row);
    }

    /**
     * Check if a user account (by email) has access to a specific member_year.
     *
     * Access granted if:
     * - The member_year's email matches the user's email (same blind index), OR
     * - The user's role is chief or admin (can see all members).
     *
     * This is the fine-grained access check beyond the route's role_min.
     */
    public function canAccess(string $userEmail, int $memberYearId, string $userRole): bool
    {
        // Chiefs and admins can access any member
        $role = Role::fromString($userRole);
        if ($role->hasAccess(Role::CHIEF)) {
            return true;
        }

        // Check email match via blind index
        $row = $this->memberYearRepo->findById($memberYearId);
        if ($row === null) {
            return false;
        }

        $userBlindIndex = $this->encryption->blindIndex(strtolower(trim($userEmail)));
        return $row['email_blind_index'] === $userBlindIndex;
    }

    /**
     * Convert a database row (with joined data) into a MemberProfile.
     *
     * @param array<string, mixed> $row
     */
    private function hydrateMemberProfile(array $row): MemberProfile
    {
        $pdo = $this->connection->getPdo();

        // Load addresses
        $stmt = $pdo->prepare('SELECT * FROM member_addresses WHERE member_year_id = ?');
        $stmt->execute([$row['id']]);
        $addresses = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $addrRow) {
            $addresses[] = new MemberAddress(
                type: $addrRow['address_type'],
                street: $addrRow['street_encrypted'] ? $this->encryption->decrypt($addrRow['street_encrypted']) : null,
                number: $addrRow['number_encrypted'] ? $this->encryption->decrypt($addrRow['number_encrypted']) : null,
                box: $addrRow['box_encrypted'] ? $this->encryption->decrypt($addrRow['box_encrypted']) : null,
                complement: $addrRow['complement_encrypted'] ? $this->encryption->decrypt($addrRow['complement_encrypted']) : null,
                postalCode: $addrRow['postal_code_encrypted'] ? $this->encryption->decrypt($addrRow['postal_code_encrypted']) : null,
                city: $addrRow['city_encrypted'] ? $this->encryption->decrypt($addrRow['city_encrypted']) : null,
                country: $addrRow['country_encrypted'] ? $this->encryption->decrypt($addrRow['country_encrypted']) : null,
            );
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
        $stmt->execute([$row['id']]);
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
            addresses: $addresses,
            functions: $functions,
            scoutYearLabel: $scoutYearLabel
        );
    }
}
