<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Repository;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\MemberAddress;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Security\EncryptionService;

/**
 * Everything that reads a member's annual record and decrypts it.
 *
 * `ARCHITECTURE.md` §13 and `SECURITY.md` §1 both say « Repository is the
 * only layer that touches PDO », and §5 « only Repositories call
 * EncryptionService ». `Core\Member\MemberService` and
 * `Core\Member\SectionService` did neither: they took a `Connection`,
 * prepared their own statements and decrypted eighteen columns between
 * them. Nothing was broken by it — the queries are all prepared, no
 * parameter is concatenated — and that is exactly why it survived: the
 * cost of a layering violation is paid by the next person to look, in
 * `core/Member/Repository/`, for code that was not there.
 *
 * **Two hydrations, deliberately different, where there used to be two
 * copies that had drifted.**
 *
 * `MemberFunctionInfo::deduplicate()`'s own docblock warned that « both
 * hydration paths build exactly this list … and two copies of the rule
 * would eventually answer differently ». It had already happened, and on
 * more than the function list: the same `MemberProfile` type came back in
 * two shapes depending on which service built it.
 *
 * |                          | one member          | a roster             |
 * | ------------------------ | ------------------- | -------------------- |
 * | addresses                | loaded, 7 columns   | none                 |
 * | handicap, insurance      | loaded              | absent               |
 * | badges                   | absent              | loaded               |
 * | queries                  | 3 per member        | 4 for the whole set  |
 *
 * The difference is kept, because it is the right difference and two
 * modules already document their dependence on it: a trombinoscope
 * hydrating sixty people must not pay sixty address lookups nobody reads,
 * and a member's own page must have the addresses. What changes is that it
 * is now one class saying so, rather than two that each thought they were
 * the only one.
 */
final class MemberProfileRepository
{
    private MemberBadgeRepository $badges;

    /**
     * `$badges` is optional and trailing: it is a repository over this very
     * connection, and making every composition root build one only to hand
     * it straight back is ceremony. It stays injectable because a caller
     * that decorates its PDO — the badge tests do — must be able to say so,
     * and because only the roster shape reads badges at all: the
     * single-member path never touches it.
     */
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption,
        ?MemberBadgeRepository $badges = null
    ) {
        $this->badges = $badges ?? new MemberBadgeRepository($connection->getPdo());
    }

    /**
     * One member's full record, from a `member_years` row already read.
     *
     * Takes the row rather than an id because every caller already has one
     * — they arrive here from a lookup by email, by member and year, or
     * from the session's temporary-member override — and re-reading it
     * would be a query per member for nothing.
     *
     * @param  array<string, mixed> $row
     */
    public function hydrateFromRow(array $row): MemberProfile
    {
        $pdo = $this->connection->getPdo();
        $memberYearId = (int) $row['id'];

        $stmt = $pdo->prepare('SELECT * FROM member_addresses WHERE member_year_id = ?');
        $stmt->execute([$memberYearId]);
        $addresses = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $addrRow) {
            $addresses[] = new MemberAddress(
                type: $addrRow['address_type'],
                street: $this->readOptional($addrRow['street_encrypted'] ?? null, 'member_addresses.street'),
                number: $this->readOptional($addrRow['number_encrypted'] ?? null, 'member_addresses.number'),
                box: $this->readOptional($addrRow['box_encrypted'] ?? null, 'member_addresses.box'),
                complement: $this->readOptional(
                    $addrRow['complement_encrypted'] ?? null,
                    'member_addresses.complement'
                ),
                postalCode: $this->readOptional(
                    $addrRow['postal_code_encrypted'] ?? null,
                    'member_addresses.postal_code'
                ),
                city: $this->readOptional($addrRow['city_encrypted'] ?? null, 'member_addresses.city'),
                country: $this->readOptional($addrRow['country_encrypted'] ?? null, 'member_addresses.country'),
            );
        }

        $functions = MemberFunctionInfo::deduplicate($this->functionsFor($pdo, [$memberYearId])[$memberYearId] ?? []);

        $stmt = $pdo->prepare('SELECT label FROM scout_years WHERE id = ?');
        $stmt->execute([$row['scout_year_id']]);
        $scoutYearLabel = (string) $stmt->fetchColumn();

        return new MemberProfile(
            ...$this->commonFields($row),
            handicap: !empty($row['handicap_encrypted'])
                ? $this->encryption->decrypt($row['handicap_encrypted'], 'member_years.handicap')
                : null,
            supplementaryInsurance: $row['supplementary_insurance'] ?? null,
            addresses: $addresses,
            functions: $functions,
            scoutYearLabel: $scoutYearLabel,
        );
    }

    /**
     * A whole roster, in four queries rather than four per person.
     *
     * No addresses — see the class docblock, and
     * `Modules\OfficialDocuments\Service\ParentalAuthorizationService`,
     * which says so in its own words because it depends on it.
     *
     * @param  int[] $memberYearIds
     * @return array<int, MemberProfile> keyed by member_year id; an id that
     *         resolves to no row is simply absent.
     */
    public function hydrateMany(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return [];
        }

        $pdo = $this->connection->getPdo();
        $placeholders = implode(', ', array_fill(0, count($memberYearIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT my.*, m.desk_id FROM member_years my JOIN members m ON my.member_id = m.id WHERE my.id IN "
                . "({$placeholders})"
        );
        $stmt->execute($memberYearIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $functionsByMemberYear = array_map(
            [MemberFunctionInfo::class, 'deduplicate'],
            $this->functionsFor($pdo, $memberYearIds)
        );

        $yearIds = array_values(array_unique(array_map(fn(array $row) => (int) $row['scout_year_id'], $rows)));
        $yearPlaceholders = implode(', ', array_fill(0, count($yearIds), '?'));
        $stmt = $pdo->prepare("SELECT id, label FROM scout_years WHERE id IN ({$yearPlaceholders})");
        $stmt->execute($yearIds);
        $labelsByYear = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $yearRow) {
            $labelsByYear[(int) $yearRow['id']] = (string) $yearRow['label'];
        }

        $badgesByMemberYear = $this->badges->getActiveBadgesForMemberYears($memberYearIds);

        $profiles = [];
        foreach ($rows as $row) {
            $memberYearId = (int) $row['id'];
            $profiles[$memberYearId] = new MemberProfile(
                ...$this->commonFields($row),
                addresses: [],
                functions: $functionsByMemberYear[$memberYearId] ?? [],
                scoutYearLabel: $labelsByYear[(int) $row['scout_year_id']] ?? '',
                badges: $badgesByMemberYear[$memberYearId] ?? []
            );
        }

        return $profiles;
    }

    /**
     * The blind index of an address, for the lookups keyed on it.
     *
     * A thin method on purpose, and it earns its place: the context string
     * `'email'` is what makes two blind indexes comparable, and a second
     * place spelling it is a second place that can spell it differently.
     * The services that ask this question — « is this address the one on
     * that record » — keep the decision and lose the cryptography.
     */
    public function emailBlindIndex(string $email): string
    {
        return $this->encryption->blindIndex(strtolower(trim($email)), 'email');
    }

    /**
     * Display names for rows already read, keyed by persistent member id.
     *
     * Takes rows rather than ids because the caller has them: this is the
     * decryption half of a question whose query half belongs to
     * `Core\Import\MemberYearRepository`. A member with no readable name is
     * absent rather than present as an empty string — « we do not know » and
     * « their name is blank » are different answers, and only the caller
     * knows what to print for the first.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function displayNamesFrom(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $totem = $this->readOptional($row['totem_encrypted'] ?? null, 'member_years.totem');
            $firstName = $this->readOptional($row['first_name_encrypted'] ?? null, 'member_years.first_name') ?? '';
            $displayName = $totem !== null && $totem !== '' ? $totem : $firstName;

            if ($displayName !== '') {
                $names[(int) $row['member_id']] = $displayName;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function emailsFrom(array $rows): array
    {
        $emails = [];
        foreach ($rows as $row) {
            $email = trim($this->readOptional($row['email_encrypted'] ?? null, 'member_years.email') ?? '');
            if ($email !== '') {
                $emails[(int) $row['member_id']] = $email;
            }
        }

        return $emails;
    }

    /**
     * « First Last », from rows already keyed by persistent member id.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function fullNamesFrom(array $rows): array
    {
        $names = [];
        foreach ($rows as $memberId => $row) {
            $first = $this->readOptional($row['first_name_encrypted'] ?? null, 'member_years.first_name') ?? '';
            $last = $this->readOptional($row['last_name_encrypted'] ?? null, 'member_years.last_name') ?? '';

            $display = trim($first . ' ' . $last);
            if ($display !== '') {
                $names[(int) $memberId] = $display;
            }
        }

        return $names;
    }

    /**
     * A roster's rows with their personal columns read.
     *
     * The birth date IS returned, and that is deliberate: the caller filters
     * on an age and then builds entries that carry no date at all, so the
     * boundary is applied once, here is where it can be, and nothing
     * downstream is handed the dates it filtered on. Which age counts as
     * old enough is a decision, and decisions stay in the Service.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return list<array{
     *     member_id: int, first_name: string, last_name: string,
     *     totem: ?string, birth_date: ?string, section_name: ?string, function_label: ?string
     * }>
     */
    public function rosterEntriesFrom(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'member_id' => (int) $row['member_id'],
                'first_name' => $this->readOptional(
                    $row['first_name_encrypted'] ?? null,
                    'member_years.first_name'
                ) ?? '',
                'last_name' => $this->readOptional($row['last_name_encrypted'] ?? null, 'member_years.last_name')
                    ?? '',
                'totem' => $this->readOptional($row['totem_encrypted'] ?? null, 'member_years.totem'),
                'birth_date' => $this->readOptional(
                    $row['birth_date_encrypted'] ?? null,
                    'member_years.birth_date'
                ),
                'section_name' => isset($row['section_name']) && $row['section_name'] !== ''
                    ? (string) $row['section_name']
                    : null,
                'function_label' => isset($row['function_label']) && $row['function_label'] !== ''
                    ? (string) $row['function_label']
                    : null,
            ];
        }

        return $entries;
    }

    /**
     * The fields both shapes read the same way.
     *
     * Spread into the constructor rather than copied into each caller: this
     * list IS the drift that `MemberFunctionInfo::deduplicate()` predicted,
     * and a second copy of it would drift again.
     *
     * @param  array<string, mixed> $row
     * @return array{
     *     memberYearId: int,
     *     memberId: int,
     *     deskId: string,
     *     firstName: string,
     *     lastName: string,
     *     totem: ?string,
     *     quali: ?string,
     *     gender: ?string,
     *     birthDate: ?string,
     *     phone: ?string,
     *     mobile: ?string,
     *     email: ?string,
     *     patrol: ?string,
     *     formationLevel: ?string,
     *     federationMailConsent: bool,
     *     unitMailConsent: bool,
     *     scoutYearOffset: int
     * }
     */
    private function commonFields(array $row): array
    {
        return [
            'memberYearId' => (int) $row['id'],
            'memberId' => (int) $row['member_id'],
            'deskId' => (string) $row['desk_id'],
            'firstName' => $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
            'lastName' => $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
            'totem' => $this->readOptional($row['totem_encrypted'] ?? null, 'member_years.totem'),
            'quali' => $this->readOptional($row['quali_encrypted'] ?? null, 'member_years.quali'),
            'gender' => $this->readOptional($row['gender_encrypted'] ?? null, 'member_years.gender'),
            'birthDate' => $this->readOptional($row['birth_date_encrypted'] ?? null, 'member_years.birth_date'),
            'phone' => $this->readOptional($row['phone_encrypted'] ?? null, 'member_years.phone'),
            'mobile' => $this->readOptional($row['mobile_encrypted'] ?? null, 'member_years.mobile'),
            'email' => $this->readOptional($row['email_encrypted'] ?? null, 'member_years.email'),
            'patrol' => $this->readOptional($row['patrol_encrypted'] ?? null, 'member_years.patrol'),
            'formationLevel' => $row['formation_level'] !== null ? (string) $row['formation_level'] : null,
            'federationMailConsent' => (bool) $row['federation_mail_consent'],
            'unitMailConsent' => (bool) $row['unit_mail_consent'],
            'scoutYearOffset' => (int) ($row['scout_year_offset'] ?? 0),
        ];
    }

    /**
     * Functions with their branch and section, grouped by member year.
     *
     * NOT deduplicated here: the single-member path and the batch one both
     * call `MemberFunctionInfo::deduplicate()` on the result, and that
     * method's docblock is where the rule lives.
     *
     * @param  int[] $memberYearIds
     * @return array<int, list<MemberFunctionInfo>>
     */
    private function functionsFor(\PDO $pdo, array $memberYearIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($memberYearIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT mf.*, f.label as function_label, f.role as function_role,
                    ab.label as branch_name, s.name as section_name, s.desk_code as section_code
             FROM member_functions mf
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN age_branches ab ON mf.age_branch_id = ab.id
             LEFT JOIN sections s ON mf.section_id = s.id
             WHERE mf.member_year_id IN ({$placeholders})"
        );
        $stmt->execute($memberYearIds);

        $byMemberYear = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $fnRow) {
            $byMemberYear[(int) $fnRow['member_year_id']][] = new MemberFunctionInfo(
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

        return $byMemberYear;
    }

    private function readOptional(mixed $value, string $context): ?string
    {
        return $value !== null && $value !== '' ? $this->encryption->decrypt($value, $context) : null;
    }
}
