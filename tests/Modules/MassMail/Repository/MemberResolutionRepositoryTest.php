<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\MemberResolutionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberResolutionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberResolutionRepository $repository;
    private int $scoutYearId;
    private int $sectionAId;
    private int $sectionBId;
    private int $functionAnimateurId;
    private int $functionChiefId;
    private int $badgeInfirmierId;
    private int $badgeTresorierId;
    private int $previousYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new MemberResolutionRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();

        $this->sectionAId = $this->createSection('LOU01', $branchId, 'Meute A');
        $this->sectionBId = $this->createSection('LOU02', $branchId, 'Meute B');

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'identified')");
        $this->functionAnimateurId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CHEF', 'Chef', 'chief')");
        $this->functionChiefId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO badges (name, is_default) VALUES ('Infirmier', 1)");
        $this->badgeInfirmierId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO badges (name, is_default) VALUES ('Trésorier', 1)");
        $this->badgeTresorierId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $this->previousYearId = (int) $this->pdo->lastInsertId();
    }

    private function assignBadge(int $memberYearId, int $badgeId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
        $stmt->execute([$memberYearId, $badgeId]);
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{member_id: int, member_year_id: int}
     */
    private function createMember(string $email, bool $active = true): array
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('John', 'member_years.first_name'), $this->encryption->encrypt('Doe', 'member_years.last_name'),
            $email !== '' ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== '' ? $this->encryption->blindIndex($email, 'email') : null,
            $active ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    private function assignFunction(int $memberYearId, int $functionId, int $sectionId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    /**
     * unit_mail_consent (the Desk "Courrier d'unité" column) is
     * deliberately never checked — a member with consent=0 (the SQLite
     * column default) must still be resolved.
     */
    public function testResolveSectionMembersIgnoresUnitMailConsent(): void
    {
        $member = $this->createMember('a@test.be');
        $this->assignFunction($member['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $stmt = $this->pdo->prepare('SELECT unit_mail_consent FROM member_years WHERE id = ?');
        $stmt->execute([$member['member_year_id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($member['member_id'], $resolved[0]['member_id']);
        $this->assertSame('a@test.be', $resolved[0]['email']);
    }

    public function testResolveSectionMembersOnlyIncludesTheGivenSection(): void
    {
        $inA = $this->createMember('a@test.be');
        $this->assignFunction($inA['member_year_id'], $this->functionAnimateurId, $this->sectionAId);
        $inB = $this->createMember('b@test.be');
        $this->assignFunction($inB['member_year_id'], $this->functionAnimateurId, $this->sectionBId);

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($inA['member_id'], $resolved[0]['member_id']);
    }

    public function testResolveActiveMembersExcludesInactiveMembers(): void
    {
        $active = $this->createMember('a@test.be', active: true);
        $inactive = $this->createMember('b@test.be', active: false);

        $resolved = $this->repository->resolveActiveMembers($this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($active['member_id'], $resolved[0]['member_id']);
    }

    public function testResolveChiefsOnlyIncludesChiefRoleAndAbove(): void
    {
        $chief = $this->createMember('chief@test.be');
        $this->assignFunction($chief['member_year_id'], $this->functionChiefId, $this->sectionAId);

        $animator = $this->createMember('animator@test.be');
        $this->assignFunction($animator['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $resolved = $this->repository->resolveChiefs($this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($chief['member_id'], $resolved[0]['member_id']);
    }

    public function testResolveCustomListRequiresBothFunctionAndSectionMatch(): void
    {
        // Qualifies: has functionChief in sectionA.
        $qualifies = $this->createMember('qualifies@test.be');
        $this->assignFunction($qualifies['member_year_id'], $this->functionChiefId, $this->sectionAId);

        // Does not qualify: has functionChief, but in sectionB (not selected).
        $wrongSection = $this->createMember('wrong-section@test.be');
        $this->assignFunction($wrongSection['member_year_id'], $this->functionChiefId, $this->sectionBId);

        // Does not qualify: in sectionA, but with functionAnimateur (not selected).
        $wrongFunction = $this->createMember('wrong-function@test.be');
        $this->assignFunction($wrongFunction['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $resolved = $this->repository->resolveCustomList(
            [$this->functionChiefId],
            [$this->sectionAId],
            [],
            $this->scoutYearId
        );

        $this->assertCount(1, $resolved);
        $this->assertSame($qualifies['member_id'], $resolved[0]['member_id']);
    }

    /**
     * An axis with nothing selected is an ABSENCE of constraint, not a
     * constraint nothing satisfies — « les chefs, toutes sections
     * confondues » is the list this makes expressible.
     */
    public function testAnEmptyAxisConstrainsNothing(): void
    {
        $chiefInA = $this->createMember('chief-a@test.be');
        $this->assignFunction($chiefInA['member_year_id'], $this->functionChiefId, $this->sectionAId);
        $chiefInB = $this->createMember('chief-b@test.be');
        $this->assignFunction($chiefInB['member_year_id'], $this->functionChiefId, $this->sectionBId);
        $animateurInA = $this->createMember('anim-a@test.be');
        $this->assignFunction($animateurInA['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        // No section selected: the function alone decides.
        $byFunction = $this->repository->resolveCustomList(
            [$this->functionChiefId],
            [],
            [],
            $this->scoutYearId
        );
        $this->assertEqualsCanonicalizing(
            [$chiefInA['member_id'], $chiefInB['member_id']],
            array_column($byFunction, 'member_id')
        );

        // No function selected: the section alone decides.
        $bySection = $this->repository->resolveCustomList(
            [],
            [$this->sectionAId],
            [],
            $this->scoutYearId
        );
        $this->assertEqualsCanonicalizing(
            [$chiefInA['member_id'], $animateurInA['member_id']],
            array_column($bySection, 'member_id')
        );
    }

    /**
     * D5's other half: no constraint on any axis is NOT « everybody ».
     * A list may legitimately hold nothing but its own addresses, and
     * « the whole unit » is what the « Membres actifs » default list is
     * for.
     */
    public function testAllThreeAxesEmptyResolvesToNobody(): void
    {
        $member = $this->createMember('somebody@test.be');
        $this->assignFunction($member['member_year_id'], $this->functionChiefId, $this->sectionAId);

        $this->assertSame([], $this->repository->resolveCustomList([], [], [], $this->scoutYearId));
    }

    public function testTheBadgeAxisIsOredWithinItselfAndAndedWithTheOthers(): void
    {
        $infirmierInA = $this->createMember('infirmier@test.be');
        $this->assignFunction($infirmierInA['member_year_id'], $this->functionChiefId, $this->sectionAId);
        $this->assignBadge($infirmierInA['member_year_id'], $this->badgeInfirmierId);

        $tresorierInB = $this->createMember('tresorier@test.be');
        $this->assignFunction($tresorierInB['member_year_id'], $this->functionChiefId, $this->sectionBId);
        $this->assignBadge($tresorierInB['member_year_id'], $this->badgeTresorierId);

        $noBadgeInA = $this->createMember('no-badge@test.be');
        $this->assignFunction($noBadgeInA['member_year_id'], $this->functionChiefId, $this->sectionAId);

        // OR within the axis: either badge qualifies.
        $eitherBadge = $this->repository->resolveCustomList(
            [],
            [],
            [$this->badgeInfirmierId, $this->badgeTresorierId],
            $this->scoutYearId
        );
        $this->assertEqualsCanonicalizing(
            [$infirmierInA['member_id'], $tresorierInB['member_id']],
            array_column($eitherBadge, 'member_id')
        );

        // AND with the section axis: the Trésorier is in the other section.
        $badgeAndSection = $this->repository->resolveCustomList(
            [],
            [$this->sectionAId],
            [$this->badgeInfirmierId, $this->badgeTresorierId],
            $this->scoutYearId
        );
        $this->assertCount(1, $badgeAndSection);
        $this->assertSame($infirmierInA['member_id'], $badgeAndSection[0]['member_id']);
    }

    /**
     * Badges are historised per scout year (member_badges.member_year_id,
     * ARCHITECTURE.md §8.11), so the axis reads the badge worn in the year
     * being resolved and never one worn in another.
     */
    public function testABadgeWornInAPastYearDoesNotCountForTheCurrentOne(): void
    {
        $member = $this->createMember('returning@test.be');
        $this->assignFunction($member['member_year_id'], $this->functionChiefId, $this->sectionAId);

        // The same person, last year, with the badge — and this year without.
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $member['member_id'],
            $this->previousYearId,
            $this->encryption->encrypt('John', 'member_years.first_name'),
            $this->encryption->encrypt('Doe', 'member_years.last_name'),
        ]);
        $this->assignBadge((int) $this->pdo->lastInsertId(), $this->badgeInfirmierId);

        $this->assertSame(
            [],
            $this->repository->resolveCustomList([], [], [$this->badgeInfirmierId], $this->scoutYearId)
        );
    }

    /**
     * A badge-only list must not silently acquire « and holds a function »
     * from a join nothing asked for — a Staff d'U member with a badge and
     * no member_functions row is still a recipient.
     */
    public function testABadgeOnlyListDoesNotRequireAFunction(): void
    {
        $badgeOnly = $this->createMember('badge-only@test.be');
        $this->assignBadge($badgeOnly['member_year_id'], $this->badgeInfirmierId);

        $resolved = $this->repository->resolveCustomList([], [], [$this->badgeInfirmierId], $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertSame($badgeOnly['member_id'], $resolved[0]['member_id']);
    }

    public function testAnInactiveMemberIsNeverResolvedByCriteria(): void
    {
        $inactive = $this->createMember('inactive@test.be', false);
        $this->assignFunction($inactive['member_year_id'], $this->functionChiefId, $this->sectionAId);
        $this->assignBadge($inactive['member_year_id'], $this->badgeInfirmierId);

        $this->assertSame(
            [],
            $this->repository->resolveCustomList([$this->functionChiefId], [], [], $this->scoutYearId)
        );
        $this->assertSame(
            [],
            $this->repository->resolveCustomList([], [], [$this->badgeInfirmierId], $this->scoutYearId)
        );
    }

    public function testEmailAddressIsDecryptedNotStoredCleartextRoundTrip(): void
    {
        $member = $this->createMember('secret@test.be');
        $this->assignFunction($member['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $stmt = $this->pdo->prepare('SELECT email_encrypted FROM member_years WHERE id = ?');
        $stmt->execute([$member['member_year_id']]);
        $rawStored = (string) $stmt->fetchColumn();
        $this->assertStringNotContainsString('secret@test.be', $rawStored);

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);
        $this->assertSame('secret@test.be', $resolved[0]['email']);
    }

    public function testMemberWithNoEmailResolvesWithNullEmail(): void
    {
        $member = $this->createMember('');
        $this->assignFunction($member['member_year_id'], $this->functionAnimateurId, $this->sectionAId);

        $resolved = $this->repository->resolveSectionMembers($this->sectionAId, $this->scoutYearId);

        $this->assertCount(1, $resolved);
        $this->assertNull($resolved[0]['email']);
    }

    // --- « Anciens » (default_former_members) ---------------------------

    private function createScoutYear(string $label, string $start, string $end): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$label, $start, $end]);
        return (int) $this->pdo->lastInsertId();
    }

    /** A member whose only rows are the ones a test asks for. */
    private function createBareMember(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid('', true) . "')");
        return (int) $this->pdo->lastInsertId();
    }

    private function addMemberYear(
        int $memberId,
        int $scoutYearId,
        string $email,
        bool $active = true,
        bool $consent = true
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index, is_active, unit_mail_consent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt('John', 'member_years.first_name'),
            $this->encryption->encrypt('Doe', 'member_years.last_name'),
            $email !== '' ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== '' ? $this->encryption->blindIndex($email, 'email') : null,
            $active ? 1 : 0,
            $consent ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return int[] the member ids resolved, in resolution order
     */
    private function resolveFormerMemberIds(int $minScoutYears = 2, int $maxYearsSinceDeparture = 0): array
    {
        return array_map(
            static fn(array $row): int => $row['member_id'],
            $this->repository->resolveFormerMembers($this->scoutYearId, $minScoutYears, $maxYearsSinceDeparture)
        );
    }

    /**
     * member_years is an ANNUAL snapshot: somebody who stayed three weeks
     * and somebody who stayed twelve months both have exactly one row.
     * The default of two distinct scout years is what tells them apart.
     */
    public function testAMemberOfASingleYearIsNotAFormerMemberAtTheDefault(): void
    {
        $onceOnly = $this->createBareMember();
        $this->addMemberYear($onceOnly, $this->previousYearId, 'once@test.be');

        $this->assertSame([], $this->resolveFormerMemberIds());

        // The same row, with the threshold lowered to one year, is one.
        $this->assertSame([$onceOnly], $this->resolveFormerMemberIds(minScoutYears: 1));
    }

    public function testAMemberActiveThisYearIsNeverAFormerMember(): void
    {
        $stillHere = $this->createBareMember();
        $olderYear = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');
        $this->addMemberYear($stillHere, $olderYear, 'chief@test.be');
        $this->addMemberYear($stillHere, $this->previousYearId, 'chief@test.be');
        $this->addMemberYear($stillHere, $this->scoutYearId, 'chief@test.be');

        $this->assertSame([], $this->resolveFormerMemberIds());
    }

    /**
     * D4: the list is dynamic and never materialised, so a former member
     * who comes back leaves it on the strength of the Desk import alone —
     * there is nothing to un-register them from.
     */
    public function testAReturningFormerMemberDisappearsWithNothingButAnImport(): void
    {
        $back = $this->createBareMember();
        $olderYear = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');
        $this->addMemberYear($back, $olderYear, 'back@test.be');
        $this->addMemberYear($back, $this->previousYearId, 'back@test.be');

        $this->assertSame([$back], $this->resolveFormerMemberIds());

        // The next import brings them back — nothing else happens.
        $this->addMemberYear($back, $this->scoutYearId, 'back@test.be');

        $this->assertSame([], $this->resolveFormerMemberIds());
    }

    public function testTheUpperBoundExcludesBeyondTheThresholdAndZeroDisablesIt(): void
    {
        $yearMinusTwo = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');
        $yearMinusThree = $this->createScoutYear('2022-2023', '2022-09-01', '2023-08-31');
        $yearMinusFour = $this->createScoutYear('2021-2022', '2021-09-01', '2022-08-31');

        $recent = $this->createBareMember();
        $this->addMemberYear($recent, $yearMinusThree, 'recent@test.be');
        $this->addMemberYear($recent, $yearMinusTwo, 'recent@test.be');

        $longGone = $this->createBareMember();
        $this->addMemberYear($longGone, $yearMinusFour, 'gone@test.be');
        $this->addMemberYear($longGone, $yearMinusThree, 'gone@test.be');

        // Two scout years back: the one who left after 2023-2024 stays.
        $this->assertSame([$recent], $this->resolveFormerMemberIds(maxYearsSinceDeparture: 2));

        // Three: both.
        $this->assertSame(
            [$recent, $longGone],
            $this->resolveFormerMemberIds(maxYearsSinceDeparture: 3)
        );

        // 0 disables the bound entirely.
        $this->assertSame([$recent, $longGone], $this->resolveFormerMemberIds(maxYearsSinceDeparture: 0));
    }

    /**
     * The last active year's address is the last one the unit ever knew,
     * and its scout_year_id is the only year the member's profile exists
     * for — the recipient row must carry it.
     */
    public function testTheAddressKeptIsTheOneOfTheLastActiveYear(): void
    {
        $member = $this->createBareMember();
        $olderYear = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');
        $this->addMemberYear($member, $olderYear, 'old-address@test.be');
        $this->addMemberYear($member, $this->previousYearId, 'last-address@test.be');

        $resolved = $this->repository->resolveFormerMembers($this->scoutYearId, 2, 0);

        $this->assertCount(1, $resolved);
        $this->assertSame('last-address@test.be', $resolved[0]['email']);
        $this->assertSame($this->previousYearId, $resolved[0]['scout_year_id']);
    }

    /**
     * The one list that asks for unit_mail_consent, the rest of this
     * repository deliberately ignoring it — see resolveFormerMembers().
     */
    public function testThisListAndOnlyThisListRequiresUnitMailConsent(): void
    {
        $refused = $this->createBareMember();
        $olderYear = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');
        $this->addMemberYear($refused, $olderYear, 'no@test.be', consent: false);
        $this->addMemberYear($refused, $this->previousYearId, 'no@test.be', consent: false);

        $this->assertSame([], $this->resolveFormerMemberIds());

        // The same member, resolved by a list that does not ask.
        $this->assertNotSame([], $this->repository->resolveActiveMembers($this->previousYearId));
    }

    /**
     * Consent is read from the row that WINS the « last active year »
     * reduction, never filtered before it. Filtered first, the winner
     * would be « the last active year that also happened to consent »:
     * somebody whose most recent snapshot says no would still be written
     * to, at the stale address of an older year, tagged with that older
     * year's id, and measured against the departure bound from the wrong
     * date.
     */
    public function testConsentIsReadFromTheLastActiveYearAndNotFromAnyEarlierOne(): void
    {
        $olderYear = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');

        $withdrew = $this->createBareMember();
        $this->addMemberYear($withdrew, $olderYear, 'old@test.be');
        $this->addMemberYear($withdrew, $this->previousYearId, 'new@test.be', consent: false);

        $this->assertSame(
            [],
            $this->resolveFormerMemberIds(),
            'the most recent signal is « no », and it is the one that counts'
        );

        // The mirror image: no on the older year, yes on the last one.
        $joined = $this->createBareMember();
        $this->addMemberYear($joined, $olderYear, 'old@test.be', consent: false);
        $this->addMemberYear($joined, $this->previousYearId, 'yes@test.be');

        $resolved = $this->repository->resolveFormerMembers($this->scoutYearId, 2, 0);

        $this->assertSame([$joined], array_column($resolved, 'member_id'));
        $this->assertSame('yes@test.be', $resolved[0]['email']);
        $this->assertSame($this->previousYearId, $resolved[0]['scout_year_id']);
    }

    public function testAnInactivePastRowIsNotAPastMembership(): void
    {
        $member = $this->createBareMember();
        $olderYear = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');
        $this->addMemberYear($member, $olderYear, 'x@test.be');
        $this->addMemberYear($member, $this->previousYearId, 'x@test.be', active: false);

        // One active past year only: below the default threshold of two.
        $this->assertSame([], $this->resolveFormerMemberIds());
    }

    public function testTheOldestKnownScoutYearIsTheOldestOneAnybodyWasImportedInto(): void
    {
        $this->assertNull($this->repository->oldestKnownScoutYearLabel());

        $this->createScoutYear('2019-2020', '2019-09-01', '2020-08-31');
        $this->assertNull(
            $this->repository->oldestKnownScoutYearLabel(),
            'A scout year with no member year is not a year the unit knows anybody from.'
        );

        $member = $this->createBareMember();
        $this->addMemberYear($member, $this->previousYearId, 'y@test.be');
        $this->assertSame('2024-2025', $this->repository->oldestKnownScoutYearLabel());

        $older = $this->createScoutYear('2021-2022', '2021-09-01', '2022-08-31');
        $this->addMemberYear($member, $older, 'y@test.be');
        $this->assertSame('2021-2022', $this->repository->oldestKnownScoutYearLabel());
    }
}
