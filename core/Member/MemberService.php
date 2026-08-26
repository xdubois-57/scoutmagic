<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Service\DateInput;

class MemberService
{
    /**
     * $temporaryMemberProvider is deliberately optional and trailing: only
     * public/index.php's composition root has a session to resolve an
     * override against, while scheduled tasks, module factories and the
     * whole test suite construct this service with three arguments and get
     * the plain email-only behavior. Same backward-compatibility shape as
     * Core\Http\FrontController's optional OfflineWhitelist.
     *
     * $memberEmailRepo is optional for the same reason and follows
     * Core\Security\RoleResolver's own optional dependency on it exactly:
     * given one, every "which members is this address linked to" question
     * below also resolves a member reachable only through a currently-
     * 'valid' secondary address (Core\Member\MemberEmailService), which is
     * what makes logging in with such an address land on that member's
     * page rather than on an empty one.
     */
    public function __construct(
        private MemberYearRepository $memberYearRepo,
        private EncryptionService $encryption,
        private Connection $connection,
        private ?TemporaryMemberProviderInterface $temporaryMemberProvider = null,
        private ?MemberEmailRepository $memberEmailRepo = null
    ) {
    }

    /**
     * getLinkedMembers() results for the lifetime of this instance. The
     * composition root resolves the same visitor's linked members several
     * times per request (session boot, nav rendering, controllers), each
     * resolution costing blind-index lookups plus a full profile
     * hydration per member. Linked members change only through imports
     * and the temporary-member override, and every override change
     * redirects before anything re-reads — so a per-request memo is safe.
     *
     * @var array<string, MemberProfile[]>
     */
    private array $linkedMembersCache = [];

    /**
     * Get all members linked to an email for a given scout year.
     * Returns decrypted MemberProfile objects.
     *
     * @return MemberProfile[]
     */
    public function getLinkedMembers(string $email, int $scoutYearId): array
    {
        $cacheKey = strtolower(trim($email)) . "\0" . $scoutYearId;
        if (array_key_exists($cacheKey, $this->linkedMembersCache)) {
            return $this->linkedMembersCache[$cacheKey];
        }

        return $this->linkedMembersCache[$cacheKey] = $this->loadLinkedMembers($email, $scoutYearId);
    }

    /**
     * @return MemberProfile[]
     */
    private function loadLinkedMembers(string $email, int $scoutYearId): array
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');
        $memberYearRows = [
            ...$this->memberYearRepo->findAllByEmail($blindIndex, $scoutYearId),
            ...$this->memberYearRowsViaSecondaryEmail($blindIndex, $scoutYearId),
        ];

        $profiles = [];
        $seenMemberYearIds = [];
        foreach ($memberYearRows as $row) {
            if (in_array((int) $row['id'], $seenMemberYearIds, true)) {
                // The same member can match both paths (their Desk address
                // re-added as one of their own secondary addresses).
                continue;
            }

            $seenMemberYearIds[] = (int) $row['id'];
            $profiles[] = $this->hydrateMemberProfile($row);
        }

        $temporaryRow = $this->resolveTemporaryMemberRow($email, $scoutYearId);
        if ($temporaryRow !== null && !in_array((int) $temporaryRow['id'], $seenMemberYearIds, true)) {
            $profiles[] = $this->hydrateMemberProfile($temporaryRow);
        }

        return $profiles;
    }

    /**
     * The active member_year rows for $scoutYearId of every member this
     * address reaches as a currently-'valid' secondary address — empty
     * without a MemberEmailRepository, and empty for a 'pending' or
     * 'inactive' row, which grants nothing anywhere (same rule as
     * Core\Security\RoleResolver).
     *
     * @return array<int, array<string, mixed>>
     */
    private function memberYearRowsViaSecondaryEmail(string $blindIndex, int $scoutYearId): array
    {
        if ($this->memberEmailRepo === null) {
            return [];
        }

        return $this->memberYearRepo->findAllByMemberIds(
            $this->memberEmailRepo->findMemberIdsByValidBlindIndex($blindIndex),
            $scoutYearId
        );
    }

    /**
     * Whether $blindIndex is a currently-'valid' secondary address of the
     * persistent member $memberId.
     */
    private function isValidSecondaryEmailOf(int $memberId, string $blindIndex): bool
    {
        if ($this->memberEmailRepo === null) {
            return false;
        }

        return in_array($memberId, $this->memberEmailRepo->findMemberIdsByValidBlindIndex($blindIndex), true);
    }

    /**
     * The member_years row of the session's temporary member (§8.42), when
     * there is one AND it belongs to $scoutYearId — null otherwise.
     *
     * The year check is what makes the override behave correctly across a
     * scout-year preview instead of needing to be purged on every year
     * switch: a member_years row belongs to exactly one year, so previewing
     * another year simply stops resolving it, and switching back restores
     * it. Callers that ask about a past year therefore never see a member
     * temporarily added against the current one.
     *
     * @return array<string, mixed>|null
     */
    private function resolveTemporaryMemberRow(string $email, int $scoutYearId): ?array
    {
        $memberYearId = $this->temporaryMemberProvider?->resolveMemberYearId($email);
        if ($memberYearId === null) {
            return null;
        }

        $row = $this->memberYearRepo->findById($memberYearId);
        if ($row === null || (int) $row['scout_year_id'] !== $scoutYearId) {
            return null;
        }

        // findAllByEmail() only ever returns is_active = 1 rows, and an
        // injected member has to be indistinguishable from a genuinely
        // linked one — nothing downstream expects an inactive member_year
        // in this list. This also closes the case where a member is
        // deactivated by a Desk import while an override still names them.
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return null;
        }

        return $row;
    }

    /**
     * From a list of linked members, return the one with the highest permission level.
     * Returns null if the list is empty.
     *
     * @param MemberProfile[] $members
     */
    public static function getHighestRoleMember(array $members): ?MemberProfile
    {
        $best = null;
        $bestLevel = -1;
        foreach ($members as $member) {
            $mainFn = $member->getMainFunction();
            if ($mainFn !== null) {
                $level = Role::fromString($mainFn->functionRole)->level();
                if ($level > $bestLevel) {
                    $bestLevel = $level;
                    $best = $member;
                }
            } elseif ($best === null) {
                $best = $member;
            }
        }
        return $best ?? ($members[0] ?? null);
    }

    /**
     * Every `members.id` active for a scout year.
     *
     * Ids only, deliberately: a caller building a "pick a member" list pairs
     * this with findDisplayNamesByMemberIds() below and never receives the
     * full decrypted profiles — loading every member's addresses, functions
     * and badges to render a checklist of names would decrypt the whole
     * member table for nothing.
     *
     * @return int[]
     */
    public function findActiveMemberIdsForYear(int $scoutYearId): array
    {
        return $this->memberYearRepo->findActiveMemberIdsForYear($scoutYearId);
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
     * Resolve a member's profile from their persistent identity
     * (members.id) rather than a member_year id — for callers that only
     * hold onto the persistent id across scout years (e.g. a module
     * storing a long-lived "which member" reference, like the SOS
     * module's duty roster). Null (not an exception) when the member has
     * no row for that year — a perfectly normal state, not an error.
     */
    public function findProfileByMemberAndYear(int $memberId, int $scoutYearId): ?MemberProfile
    {
        $row = $this->memberYearRepo->findByMemberAndYear($memberId, $scoutYearId);
        if ($row === null) {
            return null;
        }

        try {
            return $this->getMemberProfile($row['id']);
        } catch (MemberNotFoundException $e) {
            return null;
        }
    }

    /**
     * Whether $email's highest-role linked member (for $scoutYearId) is a
     * chef d'unité specifically — narrower than Role::ADMIN, which any
     * function an admin has separately confirmed with that role also
     * carries, not only the STAFFDU-membership one this checks. Shared by
     * any module that needs to gate something to actual unit chiefs (e.g.
     * Modules\Retro\Service\BoardService's own moderation/visibility
     * checks, Modules\Banner\Controller\BannerConfigController).
     */
    public function isUnitChief(string $email, int $scoutYearId): bool
    {
        $linkedMembers = $this->getLinkedMembers($email, $scoutYearId);
        if ($linkedMembers === []) {
            return false;
        }

        $best = self::getHighestRoleMember($linkedMembers);
        return $best?->getMainFunction()?->sectionCode === UnitStaffSectionService::DESK_CODE;
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

        $userBlindIndex = $this->encryption->blindIndex(strtolower(trim($userEmail)), 'email');
        if ($row['email_blind_index'] === $userBlindIndex) {
            return true;
        }

        // A confirmed secondary address of that same member passes too:
        // the member added it themselves and confirmed it from that
        // mailbox, which is exactly the "this is really you" evidence the
        // Desk address carries.
        if ($this->isValidSecondaryEmailOf((int) $row['member_id'], $userBlindIndex)) {
            return true;
        }

        // A temporary member (ARCHITECTURE.md §8.42) passes this check too.
        // It matters most where canAccess() is called with 'identified'
        // rather than the caller's real role — Core\Http\Controller\
        // MemberController::show()'s $isSelf probe, which is what decides
        // whether the member page renders its owner-only affordances
        // (private documents, own-photo replacement) rather than the staff
        // view an admin would otherwise get.
        return $this->temporaryMemberProvider?->resolveMemberYearId($userEmail) === $memberYearId;
    }

    /**
     * The scout_year_id a member_year row belongs to — MemberProfile only
     * carries the year's label, not its numeric id, so callers that need
     * the id (e.g. the member page, for section-staff/badge/document
     * lookups scoped to this exact year) resolve it here rather than
     * re-parsing the label.
     */
    public function getScoutYearIdForMemberYear(int $memberYearId): ?int
    {
        $row = $this->memberYearRepo->findById($memberYearId);
        return $row !== null ? (int) $row['scout_year_id'] : null;
    }

    /**
     * Whether $email is linked (blind-index email match, same rule as
     * canAccess()) to the persistent member $memberId for $scoutYearId —
     * used where the authorization boundary must be "this is really you",
     * not "you outrank this member" (e.g. the member page photo upload,
     * which deliberately has no chief/admin bypass outside configuration
     * mode — see Core\Http\Controller\UploadController::store()).
     */
    public function isLinkedToMember(string $email, int $memberId, int $scoutYearId): bool
    {
        $row = $this->memberYearRepo->findByMemberAndYear($memberId, $scoutYearId);
        if ($row === null) {
            return false;
        }

        $userBlindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');
        if ($row['email_blind_index'] === $userBlindIndex) {
            return true;
        }

        // Same widening as canAccess(): a currently-'valid' secondary
        // address of this member is that member.
        if ($this->isValidSecondaryEmailOf($memberId, $userBlindIndex)) {
            return true;
        }

        // A temporary member (§8.42) counts as linked here too. This is the
        // deliberate widening of the "this is really you" boundary that
        // feature carries: the member page's own photo upload, which has no
        // chief/admin bypass of its own, becomes reachable while the
        // override is active.
        $temporaryRow = $this->resolveTemporaryMemberRow($email, $scoutYearId);

        return $temporaryRow !== null && (int) $temporaryRow['member_id'] === $memberId;
    }

    /**
     * Display names (totem ?? first name, §4's convention) for a batch of
     * persistent member ids, in one query.
     *
     * The cheap counterpart to findProfileByMemberAndYear() for a page
     * that shows many members and needs nothing but their names — a group
     * feed signing every post with its author. hydrateMemberProfile()
     * would issue several queries per member for addresses, functions and
     * badges that such a page never reads. A member id with no active
     * member_year for the year is simply absent from the result.
     *
     * @param int[] $memberIds
     * @return array<int, string> members.id => display name
     */
    public function findDisplayNamesByMemberIds(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $names = [];
        foreach ($this->memberYearRepo->findAllByMemberIds($memberIds, $scoutYearId) as $row) {
            $totem = $row['totem_encrypted'] !== null ? $this->encryption->decrypt($row['totem_encrypted'], 'member_years.totem') : null;
            $firstName = $row['first_name_encrypted'] !== null ? $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name') : '';
            $displayName = $totem !== null && $totem !== '' ? $totem : $firstName;

            if ($displayName !== '') {
                $names[(int) $row['member_id']] = $displayName;
            }
        }

        return $names;
    }

    /**
     * Email addresses for a batch of persistent member ids, in one query.
     *
     * The same trade as findDisplayNamesByMemberIds() above, for the other
     * thing a page full of members usually wants: who to write to. A
     * caller building a recipient list one findProfileByMemberAndYear()
     * at a time issues several queries PER RECIPIENT for addresses,
     * functions and badges it never reads — on a page that then sends one
     * email each anyway.
     *
     * A member with no active member_year for the year, or none with an
     * address, is simply absent from the result.
     *
     * @param int[] $memberIds
     * @return array<int, string> members.id => email
     */
    public function findEmailsByMemberIds(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $emails = [];
        foreach ($this->memberYearRepo->findAllByMemberIds($memberIds, $scoutYearId) as $row) {
            if (($row['email_encrypted'] ?? null) === null) {
                continue;
            }

            $email = trim($this->encryption->decrypt($row['email_encrypted'], 'member_years.email'));
            if ($email !== '') {
                $emails[(int) $row['member_id']] = $email;
            }
        }

        return $emails;
    }

    /**
     * The active roster of one scout year, as picker entries.
     *
     * The batched counterpart to getLinkedMembers()/findProfileByMemberAndYear()
     * for a screen that has to *search* the whole unit rather than display a
     * handful of people: two queries and one decryption pass for everybody,
     * against three queries per member for the profile-shaped alternative.
     *
     * **$minimumAge is applied here, and the birth date never leaves.**
     * MemberDirectoryEntry carries no date of birth at all, so a caller that
     * needs "sixteen and over" gets exactly that and is never handed the
     * dates it filtered on. A member whose birth date is not encoded in Desk
     * is **kept**: an incomplete Desk record must not silently make an adult
     * volunteer unselectable, and the alternative — dropping them — fails in
     * the direction nobody can debug from the screen.
     *
     * @param ?\DateTimeImmutable $today Injectable only so the age boundary is testable on a fixed date.
     * @return MemberDirectoryEntry[] Ordered by display name.
     */
    public function findDirectoryForYear(
        int $scoutYearId,
        ?int $minimumAge = null,
        ?\DateTimeImmutable $today = null
    ): array {
        $today ??= new \DateTimeImmutable('today');
        $entries = [];

        foreach ($this->memberYearRepo->findActiveRosterForYear($scoutYearId) as $row) {
            $birthDate = $row['birth_date_encrypted'] !== null
                ? $this->encryption->decrypt($row['birth_date_encrypted'], 'member_years.birth_date')
                : null;

            if ($minimumAge !== null && !self::isAtLeast($birthDate, $minimumAge, $today)) {
                continue;
            }

            $firstName = $row['first_name_encrypted'] !== null
                ? $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name')
                : '';
            $lastName = $row['last_name_encrypted'] !== null
                ? $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name')
                : '';
            $totem = $row['totem_encrypted'] !== null
                ? $this->encryption->decrypt($row['totem_encrypted'], 'member_years.totem')
                : null;

            $displayName = $totem !== null && $totem !== '' ? $totem : $firstName;
            if ($displayName === '') {
                continue;
            }

            $entries[] = new MemberDirectoryEntry(
                memberId: (int) $row['member_id'],
                displayName: $displayName,
                firstName: $firstName,
                lastName: $lastName,
                totem: $totem !== '' ? $totem : null,
                sectionName: isset($row['section_name']) && $row['section_name'] !== ''
                    ? (string) $row['section_name']
                    : null,
                functionLabel: isset($row['function_label']) && $row['function_label'] !== ''
                    ? (string) $row['function_label']
                    : null
            );
        }

        usort($entries, static fn(MemberDirectoryEntry $a, MemberDirectoryEntry $b) =>
            strcasecmp($a->label(), $b->label()));

        return $entries;
    }

    /**
     * Whether a birth date makes somebody at least $minimumAge years old
     * today — on the **real** date, never on the branch-derived effective
     * age of Core\Member\MemberYearService, whose scout-year offset is a
     * chief's correction to a member's course and would answer "sixteen"
     * for someone who is fifteen.
     *
     * An unparseable or absent date answers true: see findDirectoryForYear().
     */
    private static function isAtLeast(?string $birthDate, int $minimumAge, \DateTimeImmutable $today): bool
    {
        if ($birthDate === null || trim($birthDate) === '') {
            return true;
        }

        $born = DateInput::fromStorage($birthDate);
        if ($born === null) {
            return true;
        }

        return $born->diff($today)->y >= $minimumAge;
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
                street: $addrRow['street_encrypted'] ? $this->encryption->decrypt($addrRow['street_encrypted'], 'member_addresses.street') : null,
                number: $addrRow['number_encrypted'] ? $this->encryption->decrypt($addrRow['number_encrypted'], 'member_addresses.number') : null,
                box: $addrRow['box_encrypted'] ? $this->encryption->decrypt($addrRow['box_encrypted'], 'member_addresses.box') : null,
                complement: $addrRow['complement_encrypted'] ? $this->encryption->decrypt($addrRow['complement_encrypted'], 'member_addresses.complement') : null,
                postalCode: $addrRow['postal_code_encrypted'] ? $this->encryption->decrypt($addrRow['postal_code_encrypted'], 'member_addresses.postal_code') : null,
                city: $addrRow['city_encrypted'] ? $this->encryption->decrypt($addrRow['city_encrypted'], 'member_addresses.city') : null,
                country: $addrRow['country_encrypted'] ? $this->encryption->decrypt($addrRow['country_encrypted'], 'member_addresses.country') : null,
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
            firstName: $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
            lastName: $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
            totem: $row['totem_encrypted'] ? $this->encryption->decrypt($row['totem_encrypted'], 'member_years.totem') : null,
            quali: $row['quali_encrypted'] ? $this->encryption->decrypt($row['quali_encrypted'], 'member_years.quali') : null,
            gender: $row['gender_encrypted'] ? $this->encryption->decrypt($row['gender_encrypted'], 'member_years.gender') : null,
            birthDate: $row['birth_date_encrypted'] ? $this->encryption->decrypt($row['birth_date_encrypted'], 'member_years.birth_date') : null,
            phone: $row['phone_encrypted'] ? $this->encryption->decrypt($row['phone_encrypted'], 'member_years.phone') : null,
            mobile: $row['mobile_encrypted'] ? $this->encryption->decrypt($row['mobile_encrypted'], 'member_years.mobile') : null,
            email: $row['email_encrypted'] ? $this->encryption->decrypt($row['email_encrypted'], 'member_years.email') : null,
            patrol: $row['patrol_encrypted'] ? $this->encryption->decrypt($row['patrol_encrypted'], 'member_years.patrol') : null,
            formationLevel: $row['formation_level'],
            federationMailConsent: (bool) $row['federation_mail_consent'],
            unitMailConsent: (bool) $row['unit_mail_consent'],
            handicap: !empty($row['handicap_encrypted']) ? $this->encryption->decrypt($row['handicap_encrypted'], 'member_years.handicap') : null,
            supplementaryInsurance: $row['supplementary_insurance'] ?? null,
            addresses: $addresses,
            functions: $functions,
            scoutYearLabel: $scoutYearLabel,
            scoutYearOffset: (int) ($row['scout_year_offset'] ?? 0)
        );
    }

    /**
     * Update a member's scout year offset (-1, 0, or +1). Plain operational
     * flag, not personal data — no encryption involved.
     */
    public function updateScoutYearOffset(int $memberYearId, int $offset): void
    {
        $this->memberYearRepo->updateScoutYearOffset($memberYearId, $offset);
    }
}
