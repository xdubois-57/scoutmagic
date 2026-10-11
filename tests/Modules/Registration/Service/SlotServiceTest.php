<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\ReenrollmentAnswer;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SlotServiceTest extends TestCase
{
    private \PDO $pdo;
    private SlotService $service;
    private AgeBracketRepository $bracketRepository;
    private RegistrationRequestRepository $requestRepository;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private SlotCapacityRepository $capacityRepository;
    private int $baladinsId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->encryption = $encryption;

        $settingService = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($settingService);
        $this->settingService = $settingService;

        $this->bracketRepository = new AgeBracketRepository($this->pdo);
        $this->capacityRepository = new SlotCapacityRepository($this->pdo);
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->requestRepository = $requestRepository;

        $this->service = RegistrationTestHelper::slotService($this->pdo, $encryption, $settingService);

        $this->baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
    }

    public function testBirthYearsByBranchListsOneYearPerYearInBranch(): void
    {
        $result = $this->service->birthYearsByBranch('2026-2027');

        $this->assertArrayHasKey($this->baladinsId, $result);
        $this->assertSame('Baladins', $result[$this->baladinsId]['branch_label']);
        $this->assertSame(
            [SlotMath::birthYearForSlot($this->bracketRepository->findForBranch($this->baladinsId), 1, 2026),
             SlotMath::birthYearForSlot($this->bracketRepository->findForBranch($this->baladinsId), 2, 2026)],
            $result[$this->baladinsId]['birth_years']
        );
    }

    public function testPublicLevelsWithNoMembersAndNoAcceptedRequestsAreAllAvailable(): void
    {
        $this->capacityRepository->upsert($this->baladinsId, 1, 20);
        $this->capacityRepository->upsert($this->baladinsId, 2, 20);
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $levels = $this->service->publicLevelsByBirthYear($targetYearId, '2027-2028', $currentYearId);

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $expectedYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $this->assertContains($expectedYear1, $levels[SlotMath::LEVEL_YES]);
    }

    public function testPublicLevelsReflectAcceptedRequestsNotPendingOnes(): void
    {
        $this->capacityRepository->upsert($this->baladinsId, 1, 2);
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        // Two accepted requests against a capacity-2 slot fill it — 100 %,
        // the last « Peut-être » with the default thresholds — and a third,
        // still-pending one must NOT count (accepted requests are real
        // commitments, undecided ones aren't), or the slot would read Non.
        for ($i = 0; $i < 3; $i++) {
            $created = $requestRepository->create($targetYearId, [
                'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F' . $i,
                'gender' => 'F', 'birth_date' => $birthYear1 . '-06-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'p' . $i . '@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ], null, []);
            if ($i < 2) {
                $requestRepository->updateStatus($created['id'], 'accepted', null);
            }
        }

        $levels = $this->service->publicLevelsByBirthYear($targetYearId, '2027-2028', $currentYearId);

        $this->assertContains($birthYear1, $levels[SlotMath::LEVEL_MAYBE]);
        $this->assertNotContains($birthYear1, $levels[SlotMath::LEVEL_NO]);
    }

    public function testBirthYearSlotsForPublicListsYearInBranchWithoutLevelWhenWaitlistDisabled(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $rows = $this->service->birthYearSlotsForPublic('2027-2028', []);

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);
        $birthYear2 = SlotMath::birthYearForSlot($bracket, 2, 2027);

        $row1 = current(array_filter($rows, fn($r) => $r['birth_year'] === $birthYear1));
        $this->assertNotFalse($row1);
        $this->assertSame('Baladins', $row1['branch_label']);
        $this->assertSame(1, $row1['year_in_branch']);
        $this->assertNull($row1['level']);

        $row2 = current(array_filter($rows, fn($r) => $r['birth_year'] === $birthYear2));
        $this->assertNotFalse($row2);
        $this->assertSame(2, $row2['year_in_branch']);
    }

    /**
     * The public page narrows « Section souhaitée » to the branch the
     * typed birth date lands in, and it does that in the browser, from
     * these rows — so a row that names the branch without identifying it
     * leaves the list unfilterable.
     *
     * The ID, not the label: it is the identity
     * Controller\PublicRegistrationController::resolveDesiredSectionId()
     * validates the submitted section against, and matching on anything
     * else would be a second reading of one relation. When those two
     * disagree the form offers a section the server then drops, silently
     * — which is issue #264.
     */
    public function testBirthYearSlotsForPublicIdentifyTheBranchAndDoNotOnlyNameIt(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $rows = $this->service->birthYearSlotsForPublic('2027-2028', []);

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $row = current(array_filter($rows, fn($r) => $r['birth_year'] === $birthYear));
        $this->assertNotFalse($row);
        $this->assertSame(
            $this->baladinsId,
            $row['age_branch_id'],
            'A public slot row no longer carries the branch id, so the browser cannot tell which sections '
            . 'belong to the branch a birth date lands in and offers all of them.',
        );

        foreach ($rows as $each) {
            $this->assertArrayHasKey('age_branch_id', $each);
        }
    }

    /**
     * The rule this whole change exists for, at the service level: a
     * branch with NO recorded capacity is unlimited, so it is never
     * announced full — whatever the projected headcount or the accepted
     * requests happen to be: always Oui, « Disponible ».
     */
    public function testASlotWithNoRecordedCapacityIsNeverAnnouncedFull(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        // No capacity row at all for this slot, and accepted requests
        // piling up against it.
        $this->acceptRequests($targetYearId, $birthYear1, 5);

        $levels = $this->service->publicLevelsByBirthYear($targetYearId, '2027-2028', $currentYearId);

        $this->assertNotContains($birthYear1, $levels[SlotMath::LEVEL_NO]);
        $this->assertNotContains($birthYear1, $levels[SlotMath::LEVEL_MAYBE]);
        $this->assertContains($birthYear1, $levels[SlotMath::LEVEL_YES]);
    }

    /**
     * Same thing for a capacity a chief explicitly CLEARED (a NULL row,
     * not a missing one) — the state "j'ai vidé la case" must behave
     * exactly like "je n'y ai jamais touché", and not like a 0.
     */
    public function testAnExplicitlyClearedCapacityBehavesAsNoLimitNotAsZero(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $this->capacityRepository->upsert($this->baladinsId, 1, null);
        $this->acceptRequests($targetYearId, $birthYear1, 3);

        $levels = $this->service->publicLevelsByBirthYear($targetYearId, '2027-2028', $currentYearId);
        $this->assertContains($birthYear1, $levels[SlotMath::LEVEL_YES]);
        $this->assertNotContains($birthYear1, $levels[SlotMath::LEVEL_NO]);
    }

    /**
     * And the other half of the distinction: 0 still closes a branch. If
     * this ever passes for the same reason as the two tests above, the
     * NULL/0 separation has collapsed.
     */
    public function testACapacityOfZeroStillClosesTheSlot(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $this->capacityRepository->upsert($this->baladinsId, 1, 0);

        $levels = $this->service->publicLevelsByBirthYear($targetYearId, '2027-2028', $currentYearId);

        $this->assertContains($birthYear1, $levels[SlotMath::LEVEL_NO]);
        $this->assertNotContains($birthYear1, $levels[SlotMath::LEVEL_YES]);
    }

    /**
     * The chief-facing table reports "pas de limite" as an absence, not as
     * a number: no capacity and no remaining count — two nulls rather than
     * zeroes a reader would take for "complet" — and the level is Oui.
     */
    public function testCapacityBreakdownReportsNoLimitAsNullNeverAsZero(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $this->capacityRepository->upsert($this->baladinsId, 2, 0);

        $rows = $this->service->capacityBreakdownForYear($targetYearId, '2027-2028', $currentYearId);

        $unlimited = current(array_filter($rows, fn(array $r) => $r['year_in_branch'] === 1));
        $this->assertNotFalse($unlimited);
        $this->assertNull($unlimited['capacity']);
        $this->assertNull($unlimited['remaining']);
        $this->assertSame(SlotMath::LEVEL_YES, $unlimited['level']);

        $closed = current(array_filter($rows, fn(array $r) => $r['year_in_branch'] === 2));
        $this->assertNotFalse($closed);
        $this->assertSame(0, $closed['capacity']);
        $this->assertSame(0, $closed['remaining']);
        $this->assertSame(SlotMath::LEVEL_NO, $closed['level']);
    }

    /**
     * R1 (docs/chantiers/CHANTIER-formulaire-inscription.md): an accepted
     * request occupies ONE place — never two, never zero — whether or not
     * it has since been encoded in Desk. The canonical encoded case: the
     * reconciliation linked the request to the member the TARGET year's
     * Desk import produced.
     */
    public function testAnEncodedRequestOccupiesExactlyOnePlace(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $sectionId = $this->createSection('BALA1', $this->baladinsId);

        // Born 2021: six in 2027, Baladins 1st year next year.
        $requestId = $this->acceptRequest($targetYearId, '2021-06-01');
        $member = $this->createMemberYear($targetYearId, '2021-06-01', $sectionId);
        $this->requestRepository->linkToMemberAndEncode($requestId, $member, new \DateTimeImmutable());

        $slot = $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 1);

        $this->assertSame(
            1,
            $slot['projected'] + $slot['accepted'],
            'An encoded request must occupy exactly one place: counted twice it closes a slot too early, '
            . 'counted zero times it opens one that is already taken.'
        );
    }

    /**
     * R1, the other three shapes of the same rule. Accepted and not
     * encoded: the request itself. Encoded to a member the projection
     * already holds (a child Desk had in the CURRENT year, projected
     * forward): that member, moved to « Demandes acceptées », not counted
     * a second time as a projected animé. Encoded to a member Desk has in
     * neither year (a returning child linked by hand to the tiers number
     * of an earlier year): the request's own birth date.
     */
    public function testEveryOtherShapeOfAnAcceptedRequestOccupiesExactlyOnePlace(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $sectionId = $this->createSection('BALA1', $this->baladinsId);

        // Accepted, not encoded.
        $this->acceptRequest($targetYearId, '2021-03-01');

        // Encoded to a current-year Baladin (born 2020: Baladins 1st year
        // now, 2nd year next year).
        $current = $this->createMemberYear($currentYearId, '2020-03-01', $sectionId);
        $request = $this->acceptRequest($targetYearId, '2020-03-01');
        $this->requestRepository->linkToMemberAndEncode($request, $current, new \DateTimeImmutable());

        // Encoded to a member with no row in either year.
        $returning = RegistrationTestHelper::insertMember($this->pdo, 'DESK_RETURNING');
        $request = $this->acceptRequest($targetYearId, '2020-04-01');
        $this->requestRepository->linkToMemberAndEncode($request, $returning, new \DateTimeImmutable());

        $first = $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 1);
        $second = $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 2);

        $this->assertSame(['projected' => 0, 'accepted' => 1, 'occupied' => 1], [
            'projected' => $first['projected'], 'accepted' => $first['accepted'], 'occupied' => $first['occupied'],
        ]);
        $this->assertSame(
            ['projected' => 0, 'accepted' => 2, 'occupied' => 2],
            ['projected' => $second['projected'], 'accepted' => $second['accepted'], 'occupied' => $second['occupied']],
            'The encoded child already in Desk counts once, as an accepted request; the returning child '
            . 'Desk does not have yet counts once too, from the request.'
        );
    }

    /**
     * Desk has the final word once it has a target-year row: an encoded
     * child whose row says they are leaving occupies no place, and the
     * request is not brought back in through its birth date.
     */
    public function testAnEncodedChildDeskMarksAsLeavingNextYearOccupiesNoPlace(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $sectionId = $this->createSection('BALA1', $this->baladinsId);

        $requestId = $this->acceptRequest($targetYearId, '2021-06-01');
        $member = $this->createMemberYear($targetYearId, '2021-06-01', $sectionId, leaving: true);
        $this->requestRepository->linkToMemberAndEncode($requestId, $member, new \DateTimeImmutable());

        $this->assertSame(0, $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 1)['occupied']);
    }

    /**
     * A target-year Desk row the animé roster does not read — no function in
     * a section yet — is not Desk saying the child is gone: the encoded
     * request still occupies its place, through its birth date.
     */
    public function testAnEncodedChildDeskHasNotPlacedInASectionYetStillOccupiesOnePlace(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $requestId = $this->acceptRequest($targetYearId, '2021-06-01');
        $member = $this->createMemberYear($targetYearId, '2021-06-01', null);
        $this->requestRepository->linkToMemberAndEncode($requestId, $member, new \DateTimeImmutable());

        $slot = $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 1);
        $this->assertSame(
            ['projected' => 0, 'accepted' => 1],
            ['projected' => $slot['projected'], 'accepted' => $slot['accepted']]
        );
    }

    /**
     * D4: the projection is ForecastService's, which honours
     * `scout_year_offset`. A Baladin held back a year stays in Baladins
     * 2nd year rather than moving up; one moved ahead skips a year; and a
     * child held back so far that today's age is below every branch still
     * arrives next year — the case the simplified projection, which only
     * shifted today's occupants of a slot, dropped.
     */
    public function testTheProjectionPlacesAnimesHeldBackOrMovedAheadByTheirRealYear(): void
    {
        $louveteauxId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $baladins = $this->createSection('BALA1', $this->baladinsId);
        $louveteaux = $this->createSection('LOUV1', $louveteauxId);

        // Born 2019: Baladins 2nd year now, Louveteaux 1st year next year.
        $this->createMemberYear($currentYearId, '2019-03-01', $baladins);
        // Same age, held back a year: still Baladins 2nd year next year.
        $this->createMemberYear($currentYearId, '2019-03-01', $baladins, scoutYearOffset: -1);
        // Born 2018, moved ahead: Louveteaux 3rd year next year, not 2nd.
        $this->createMemberYear($currentYearId, '2018-03-01', $louveteaux, scoutYearOffset: 1);
        // Born 2020, held back: five today, Baladins 1st year next year.
        $this->createMemberYear($currentYearId, '2020-03-01', $baladins, scoutYearOffset: -1);

        $projected = [];
        foreach ($this->service->capacityBreakdownForYear($targetYearId, '2027-2028', $currentYearId) as $row) {
            $projected[$row['branch_label'] . ' ' . $row['year_in_branch']] = $row['projected'];
        }

        $this->assertSame([
            'Baladins 1' => 1,
            'Baladins 2' => 1,
            'Louveteaux 1' => 1,
            'Louveteaux 2' => 0,
            'Louveteaux 3' => 1,
            'Louveteaux 4' => 0,
        ], $projected);
    }

    /**
     * D4: a family that has not answered the reenrollment is counted as
     * staying, and shown as such (« dont N sans réponse »). Only a
     * departure takes a child out — the family's « il ne revient pas »,
     * which sets `member_years.leaving`, or the staff's own tick. A family
     * that answered they leave but whom the staff kept (the box unticked)
     * counts, and is not « sans réponse ».
     */
    public function testAnUnansweredReenrollmentCountsAsStayingAndIsShownAsSuch(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $sectionId = $this->createSection('BALA1', $this->baladinsId);
        $answers = new ReenrollmentRepository($this->pdo, $this->encryption);

        // All born 2020: Baladins 1st year now, 2nd year next year.
        $this->createMemberYear($currentYearId, '2020-03-01', $sectionId); // silent
        $this->createMemberYear($currentYearId, '2020-03-01', $sectionId); // silent
        $stays = $this->createMemberYear($currentYearId, '2020-03-01', $sectionId);
        $answers->saveAnswer($stays, $targetYearId, ReenrollmentAnswer::DECISION_REENROLLED, null, null, null, []);
        $leaves = $this->createMemberYear($currentYearId, '2020-03-01', $sectionId, leaving: true);
        $answers->saveAnswer($leaves, $targetYearId, ReenrollmentAnswer::DECISION_LEAVING, null, null, null, []);
        $keptByStaff = $this->createMemberYear($currentYearId, '2020-03-01', $sectionId);
        $answers->saveAnswer($keptByStaff, $targetYearId, ReenrollmentAnswer::DECISION_LEAVING, null, null, null, []);
        // Marked leaving by the staff without any family answer.
        $this->createMemberYear($currentYearId, '2020-03-01', $sectionId, leaving: true);

        $slot = $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 2);

        $this->assertSame(4, $slot['projected'], 'two silent, one staying, one kept by the staff');
        $this->assertSame(2, $slot['silent']);
    }

    /**
     * A child Desk already has for the target year is a fact, not a
     * forecast: counted, never « sans réponse ».
     */
    public function testAChildAlreadyInDeskForTheTargetYearIsNeverSansReponse(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $sectionId = $this->createSection('BALA1', $this->baladinsId);

        $member = $this->createMemberYear($currentYearId, '2020-03-01', $sectionId);
        $this->createMemberYear($targetYearId, '2020-03-01', $sectionId, memberId: $member);

        $slot = $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 2);

        $this->assertSame(1, $slot['projected'], 'the same child, once — not once per year');
        $this->assertSame(0, $slot['silent']);
    }

    /**
     * The level reads occupancy (projected + accepted) through the two
     * stored thresholds, bound included.
     */
    public function testTheLevelReadsTheOccupancyThroughTheStoredThresholds(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $sectionId = $this->createSection('BALA1', $this->baladinsId);
        $this->capacityRepository->upsert($this->baladinsId, 2, 10);

        // Born 2020: Baladins 2nd year next year. Four projected animés,
        // four accepted requests: 8 places of 10, 80 %.
        for ($i = 0; $i < 4; $i++) {
            $this->createMemberYear($currentYearId, '2020-03-01', $sectionId);
            $this->acceptRequest($targetYearId, '2020-05-01');
        }

        $slot = $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 2);
        $this->assertSame(8, $slot['occupied']);
        $this->assertSame(2, $slot['remaining']);
        $this->assertSame(SlotMath::LEVEL_MAYBE, $slot['level'], '80 % is above the default 70 %');

        $this->settingService->set(SlotService::SETTING_THRESHOLD_YES, '80', 'registration');
        $this->assertSame(
            SlotMath::LEVEL_YES,
            $this->breakdownRow($targetYearId, $currentYearId, $this->baladinsId, 2)['level'],
            '« Oui jusqu\'à 80 % » includes 80 %'
        );
    }

    public function testLevelThresholdsDefaultToSeventyAndOneHundred(): void
    {
        $this->assertSame(['yes' => 70, 'maybe' => 100], $this->service->levelThresholds());
    }

    public function testLevelThresholdsReadTheStoredPair(): void
    {
        $this->settingService->set(SlotService::SETTING_THRESHOLD_YES, '50', 'registration');
        $this->settingService->set(SlotService::SETTING_THRESHOLD_MAYBE, '120', 'registration');

        $this->assertSame(['yes' => 50, 'maybe' => 120], $this->service->levelThresholds());
        $this->assertFalse($this->service->storedThresholdsAreUnusable());
    }

    /**
     * An unusable stored pair falls back to the defaults as a PAIR: keeping
     * the valid half would mix a stored value with a default, which is how
     * an inverted pair would be applied after all.
     */
    public function testAnUnusableStoredPairFallsBackToTheDefaultsAsAPair(): void
    {
        $this->settingService->set(SlotService::SETTING_THRESHOLD_YES, '110', 'registration');
        $this->settingService->set(SlotService::SETTING_THRESHOLD_MAYBE, '90', 'registration');

        $this->assertSame(['yes' => 70, 'maybe' => 100], $this->service->levelThresholds());
        $this->assertTrue($this->service->storedThresholdsAreUnusable());
    }

    public function testSeedMissingCapacitiesWritesTheDefaultToTheDatabase(): void
    {
        $seeded = $this->service->seedMissingCapacities();

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $this->assertSame($bracket->durationYears, $seeded);

        for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
            $this->assertSame(
                SlotService::DEFAULT_CAPACITY,
                $this->capacityRepository->capacityFor($this->baladinsId, $yearInBranch),
                'the default must be stored, not merely displayed'
            );
        }
    }

    public function testSeedMissingCapacitiesIsIdempotentAndNeverOverwrites(): void
    {
        $this->capacityRepository->upsert($this->baladinsId, 1, 7);
        $this->capacityRepository->upsert($this->baladinsId, 2, null);

        $this->assertSame(0, $this->service->seedMissingCapacities());
        $this->assertSame(7, $this->capacityRepository->capacityFor($this->baladinsId, 1));
        $this->assertNull(
            $this->capacityRepository->capacityFor($this->baladinsId, 2),
            'a box the chief emptied on purpose must not be re-seeded'
        );
    }

    /**
     * Seeded slots behave like any other from then on: the number is real,
     * so the level is computed from it rather than from an absence.
     */
    public function testASeededSlotThenBehavesLikeAnOrdinaryCapacity(): void
    {
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $this->service->seedMissingCapacities();

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);
        $this->acceptRequests($targetYearId, $birthYear1, SlotService::DEFAULT_CAPACITY + 1);

        $levels = $this->service->publicLevelsByBirthYear($targetYearId, '2027-2028', $currentYearId);
        $this->assertContains($birthYear1, $levels[SlotMath::LEVEL_NO]);
    }

    private function acceptRequest(int $targetYearId, string $birthDate): int
    {
        $created = $this->requestRepository->create($targetYearId, [
            'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F' . uniqid(),
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => uniqid() . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        return $created['id'];
    }

    private function createSection(string $deskCode, int $branchId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$deskCode, $branchId, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * One member_years row, an animé of $sectionId (none when null) —
     * returns the member id, a new member unless $memberId is given.
     */
    private function createMemberYear(
        int $scoutYearId,
        string $birthDate,
        ?int $sectionId,
        ?int $memberId = null,
        bool $leaving = false,
        int $scoutYearOffset = 0
    ): int {
        if ($memberId === null) {
            $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK_' . uniqid());
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                birth_date_encrypted, gender_encrypted, leaving, scout_year_offset)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $scoutYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
            $this->encryption->encrypt('F', 'member_years.gender'),
            $leaving ? 1 : 0,
            $scoutYearOffset,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        if ($sectionId === null) {
            // A Desk row with no function in a section yet: a member, not an animé.
            return $memberId;
        }

        $this->pdo->exec(
            "INSERT OR IGNORE INTO functions (desk_code, label, role)
             VALUES ('identified', 'Fn identified', 'identified')"
        );
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $branchStmt = $this->pdo->prepare('SELECT age_branch_id FROM sections WHERE id = ?');
        $branchStmt->execute([$sectionId]);
        $branchId = (int) $branchStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberId;
    }

    /**
     * @return array<string, mixed>
     */
    private function breakdownRow(int $targetYearId, int $currentYearId, int $branchId, int $yearInBranch): array
    {
        foreach ($this->service->capacityBreakdownForYear($targetYearId, '2027-2028', $currentYearId) as $row) {
            if ($row['age_branch_id'] === $branchId && $row['year_in_branch'] === $yearInBranch) {
                return $row;
            }
        }
        $this->fail('No breakdown row for this slot.');
    }

    /**
     * Creates $count ACCEPTED requests whose birth date falls in $birthYear.
     */
    private function acceptRequests(int $targetYearId, int $birthYear, int $count): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        for ($i = 0; $i < $count; $i++) {
            $created = $requestRepository->create($targetYearId, [
                'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F' . $i,
                'gender' => 'F', 'birth_date' => $birthYear . '-06-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'p' . $i . '@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ], null, []);
            $requestRepository->updateStatus($created['id'], 'accepted', null);
        }
    }

    public function testBirthYearSlotsForPublicIncludesLevelWhenWaitlistEnabled(): void
    {
        $this->capacityRepository->upsert($this->baladinsId, 1, 2);
        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bracket = $this->bracketRepository->findForBranch($this->baladinsId);
        $birthYear1 = SlotMath::birthYearForSlot($bracket, 1, 2027);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        for ($i = 0; $i < 3; $i++) {
            $created = $requestRepository->create($targetYearId, [
                'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F' . $i,
                'gender' => 'F', 'birth_date' => $birthYear1 . '-06-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'p' . $i . '@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ], null, []);
            $requestRepository->updateStatus($created['id'], 'accepted', null);
        }

        // Three accepted against two places: 150 %, beyond « Peut-être ».
        $rows = $this->service->birthYearSlotsForPublic(
            '2027-2028',
            $this->service->publicLevelsByBirthYear($targetYearId, '2027-2028', $currentYearId)
        );

        $row1 = current(array_filter($rows, fn($r) => $r['birth_year'] === $birthYear1));
        $this->assertNotFalse($row1);
        $this->assertSame(SlotMath::LEVEL_NO, $row1['level']);
    }
}
