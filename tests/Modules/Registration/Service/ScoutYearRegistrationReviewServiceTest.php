<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\Registration\Api\MissingAcceptedRequest;
use Modules\Registration\Api\ReenrollmentCampaignState;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;
use Modules\Registration\Repository\ImportedMemberRepository;
use Modules\Registration\Repository\PassageRosterRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ScoutYearRegistrationReviewService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Api\ScoutYearRegistrationReviewProvider — what the "Année scoute"
 * workflow's checks read about the campaign, the requests, the departures
 * and the passages, for an explicit target year (chantier « Année scoute »
 * §2.3 to §2.5).
 *
 * The public year is 2026-2027, so the target year — the one the campaign
 * asks about and Passage targets — is 2027-2028.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearRegistrationReviewServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private ReenrollmentCampaignService $campaign;
    private RegistrationRequestRepository $requests;
    private SectionTransferRepository $transfers;
    private ScoutYearRegistrationReviewProvider $review;
    private int $publicYearId;
    private int $targetYearId;
    private int $louveteauxSectionId;
    private int $eclaireursASectionId;
    private int $eclaireursBSectionId;
    private int $animeFunctionId;
    private int $chiefFunctionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->publicYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $louveteaux = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $eclaireurs = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $this->louveteauxSectionId = $this->createSection('LOUV1', $louveteaux, 'Meute');
        $this->eclaireursASectionId = $this->createSection('ECLA1', $eclaireurs, 'Troupe A');
        $this->eclaireursBSectionId = $this->createSection('ECLA2', $eclaireurs, 'Troupe B');

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIME', 'Animé', 'identified')");
        $this->animeFunctionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CHEF', 'Animateur', 'chief')");
        $this->chiefFunctionId = (int) $this->pdo->lastInsertId();

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settingService);
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->publicYearId);
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '03-01', 'registration');
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_CLOSE_AT, '05-15', 'registration');

        $scoutYearService = new ScoutYearService($this->pdo);
        $passageService = RegistrationTestHelper::passageService($this->pdo, $this->encryption);
        $this->campaign = new ReenrollmentCampaignService(
            $this->settingService,
            new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo)),
            $scoutYearService,
            new ReenrollmentRepository($this->pdo, $this->encryption),
            $passageService
        );
        $this->requests = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $this->transfers = new SectionTransferRepository($this->pdo);

        $this->review = new ScoutYearRegistrationReviewService(
            $this->campaign,
            $passageService,
            new PassageRosterRepository($this->pdo, $this->encryption),
            $this->transfers,
            $this->requests,
            new ImportedMemberRepository($this->pdo, $this->encryption),
            $scoutYearService
        );
    }

    // ── The campaign (§2.3) ─────────────────────────────────────────

    public function testACampaignWhoseOpeningIsAheadHasNotStarted(): void
    {
        $state = $this->stateAt('2027-02-01');

        $this->assertSame(ReenrollmentCampaignState::NOT_STARTED, $state->state);
        $this->assertSame('2027-2028', $state->targetYearLabel);
        $this->assertSame('2027-03-01', $state->opensOn);
        $this->assertSame('2027-05-15', $state->closesOn);
        $this->assertFalse($state->isFinished());
    }

    /**
     * The opening day, before the cron pass that opens the campaign: the
     * date says started, but no family has been written to yet.
     */
    public function testTheOpeningDayIsNotFinishedBeforeTheOpeningRuns(): void
    {
        $this->assertSame(ReenrollmentCampaignState::NOT_STARTED, $this->stateAt('2027-03-01')->state);

        $this->campaign->open();
        $this->campaign->markDone(ReenrollmentCampaignService::MARKER_OPENED, '2027-05-15');
        $this->assertSame(ReenrollmentCampaignState::OPEN, $this->stateAt('2027-03-01')->state);
    }

    public function testACampaignWithItsSwitchOnIsOpen(): void
    {
        $this->campaign->open();

        $this->assertSame(ReenrollmentCampaignState::OPEN, $this->stateAt('2027-04-01')->state);
    }

    public function testACampaignClosedByHandBeforeItsDateIsFinished(): void
    {
        $this->assertSame(ReenrollmentCampaignState::FINISHED, $this->stateAt('2027-04-10')->state);
    }

    public function testACampaignPastItsCloseDateIsFinished(): void
    {
        $state = $this->stateAt('2027-06-01');

        $this->assertSame(ReenrollmentCampaignState::FINISHED, $state->state);
        $this->assertTrue($state->isFinished());
    }

    /**
     * Reopened by hand after its close: the switch wins, and the step's
     * reviewer sees it open again.
     */
    public function testACampaignReopenedAfterItsCloseIsOpen(): void
    {
        $this->campaign->open();

        $this->assertSame(ReenrollmentCampaignState::OPEN, $this->stateAt('2027-06-01')->state);
    }

    /**
     * Opened early by hand, with its opening e-mail gone out, then closed:
     * it did start, so it is finished. Never counted from the answers.
     */
    public function testACampaignOpenedEarlyThenClosedIsFinished(): void
    {
        $this->campaign->open();
        $this->assertSame(ReenrollmentCampaignState::OPEN, $this->stateAt('2027-02-10')->state);

        $this->campaign->markDone(
            ReenrollmentCampaignService::emailMarker(ReenrollmentCampaignService::EMAIL_OPENING),
            '2027-05-15'
        );
        $this->campaign->close();

        $this->assertSame(ReenrollmentCampaignState::FINISHED, $this->stateAt('2027-02-15')->state);
    }

    public function testNoDatesAndNoSwitchIsNoCampaign(): void
    {
        $this->settingService->setInternal(ReenrollmentCampaignService::SETTING_OPEN_AT, '', 'registration');

        $this->assertNull($this->review->reenrollmentCampaign($this->targetYearId, new \DateTimeImmutable('2027-04-01')));

        $this->campaign->open();
        $state = $this->stateAt('2027-04-01');
        $this->assertSame(ReenrollmentCampaignState::OPEN, $state->state);
        $this->assertNull($state->opensOn);
        $this->assertNull($state->closesOn);
    }

    /**
     * The switch belongs to the current campaign (2027-2028's). Asked about
     * the public year's own campaign, it is not read: that campaign closed
     * on 2026-05-15 and is finished.
     */
    public function testTheSwitchIsReadForTheCurrentCampaignOnly(): void
    {
        $this->campaign->open();

        $state = $this->review->reenrollmentCampaign($this->publicYearId, new \DateTimeImmutable('2027-04-01'));

        $this->assertNotNull($state);
        $this->assertSame(ReenrollmentCampaignState::FINISHED, $state->state);
        $this->assertSame('2026-05-15', $state->closesOn);
    }

    public function testAnUnknownYearHasNoCampaign(): void
    {
        $this->assertNull($this->review->reenrollmentCampaign(9999, new \DateTimeImmutable('2027-04-01')));
    }

    // ── The requests ───────────────────────────────────────────────

    public function testCountsTheTargetYearsWaitingRequests(): void
    {
        $this->createRequest($this->targetYearId, 'Léa', 'pending');
        $this->createRequest($this->targetYearId, 'Tom', 'accepted');
        $this->createRequest($this->targetYearId, 'Zoé', 'accepted', $this->louveteauxSectionId);
        $this->createRequest($this->targetYearId, 'Max', 'refused');
        // Another year's: not this year's to count.
        $this->createRequest($this->publicYearId, 'Eva', 'pending');

        $counts = $this->review->requestCounts($this->targetYearId);

        $this->assertSame(1, $counts->pending);
        $this->assertSame(2, $counts->accepted);
        $this->assertSame(1, $counts->acceptedWithoutIntendedSection);
    }

    // ── Departures and passages ────────────────────────────────────

    public function testListsTheAnnouncedLeaversOfTheYearBeforeTheTarget(): void
    {
        $leaver = $this->createMemberYear($this->publicYearId, 'Sacha', '2016-03-01', $this->louveteauxSectionId, leaving: true);
        $this->createMemberYear($this->publicYearId, 'Camille', '2016-03-01', $this->louveteauxSectionId);
        // A leaving animateur is not an animé.
        $this->createMemberYear($this->publicYearId, 'Chef', '1990-01-01', $this->louveteauxSectionId, leaving: true, chief: true);

        $leavers = $this->review->announcedLeavers($this->targetYearId);

        $this->assertCount(1, $leavers);
        $this->assertSame($leaver['member_id'], $leavers[0]->memberId);
        $this->assertSame($leaver['member_year_id'], $leavers[0]->memberYearId);
        $this->assertSame('Sacha Dupont', $leavers[0]->name);
        $this->assertSame($this->louveteauxSectionId, $leavers[0]->sectionId);
    }

    public function testATargetYearWithNoYearBeforeItHasNoLeaverAndNoPassage(): void
    {
        $this->assertSame([], $this->review->announcedLeavers($this->publicYearId));
        $this->assertSame([], $this->review->passagesWithoutDestination($this->publicYearId));
    }

    public function testListsThePassagesStillWithoutADestination(): void
    {
        // Born 2015: a last-rank Louveteau in 2026-2027, Éclaireur next year.
        $decided = $this->createMemberYear($this->publicYearId, 'Alex', '2015-06-01', $this->louveteauxSectionId);
        $undecided = $this->createMemberYear($this->publicYearId, 'Noa', '2015-06-01', $this->louveteauxSectionId);
        // Mid-branch: no passage at all.
        $this->createMemberYear($this->publicYearId, 'Jeune', '2017-06-01', $this->louveteauxSectionId);
        $this->transfers->setDestination($decided['member_id'], $this->targetYearId, $this->eclaireursASectionId);

        $passages = $this->review->passagesWithoutDestination($this->targetYearId);

        $this->assertCount(1, $passages);
        $this->assertSame($undecided['member_id'], $passages[0]->memberId);
        $this->assertSame('Noa Dupont', $passages[0]->name);
        $this->assertSame($this->louveteauxSectionId, $passages[0]->currentSectionId);
        $this->assertSame(2, $passages[0]->destinationOptionCount);
        $this->assertNotSame('', $passages[0]->branchYearLabel);
    }

    // ── Prepared against imported (§2.5) ───────────────────────────

    public function testALeaverActiveInTheTargetImportIsAGap(): void
    {
        $back = $this->createMemberYear($this->publicYearId, 'Sacha', '2016-03-01', $this->louveteauxSectionId, leaving: true);
        $gone = $this->createMemberYear($this->publicYearId, 'Robin', '2016-03-01', $this->louveteauxSectionId, leaving: true);
        $targetRow = $this->createMemberYear($this->targetYearId, 'Sacha', '2016-03-01', $this->louveteauxSectionId, memberId: $back['member_id']);
        // Imported, but inactive: not present and active.
        $this->createMemberYear($this->targetYearId, 'Robin', '2016-03-01', $this->louveteauxSectionId, memberId: $gone['member_id'], active: false);

        $gaps = $this->review->preparedImportGaps($this->targetYearId);

        $this->assertCount(1, $gaps->leaversStillActive);
        $this->assertSame($back['member_id'], $gaps->leaversStillActive[0]->memberId);
        $this->assertSame($targetRow['member_year_id'], $gaps->leaversStillActive[0]->memberYearId);
        $this->assertSame($this->louveteauxSectionId, $gaps->leaversStillActive[0]->sectionId);
    }

    public function testAPassageImportedIntoAnotherSectionIsAGap(): void
    {
        $elsewhere = $this->createMemberYear($this->publicYearId, 'Alex', '2015-06-01', $this->louveteauxSectionId);
        $asPlanned = $this->createMemberYear($this->publicYearId, 'Noa', '2015-06-01', $this->louveteauxSectionId);
        $notImported = $this->createMemberYear($this->publicYearId, 'Lou', '2015-06-01', $this->louveteauxSectionId);
        $this->transfers->setDestination($elsewhere['member_id'], $this->targetYearId, $this->eclaireursASectionId);
        $this->transfers->setDestination($asPlanned['member_id'], $this->targetYearId, $this->eclaireursASectionId);
        $this->transfers->setDestination($notImported['member_id'], $this->targetYearId, $this->eclaireursASectionId);
        $imported = $this->createMemberYear($this->targetYearId, 'Alex', '2015-06-01', $this->eclaireursBSectionId, memberId: $elsewhere['member_id']);
        $this->createMemberYear($this->targetYearId, 'Noa', '2015-06-01', $this->eclaireursASectionId, memberId: $asPlanned['member_id']);

        $gaps = $this->review->preparedImportGaps($this->targetYearId);

        $this->assertCount(1, $gaps->passagesElsewhere);
        $mismatch = $gaps->passagesElsewhere[0];
        $this->assertSame($elsewhere['member_id'], $mismatch->memberId);
        $this->assertSame($imported['member_year_id'], $mismatch->memberYearId);
        $this->assertSame('Alex Dupont', $mismatch->name);
        $this->assertSame($this->eclaireursASectionId, $mismatch->destinationSectionId);
        $this->assertSame($this->eclaireursBSectionId, $mismatch->importedSectionId);
    }

    public function testAnAcceptedRequestWhoseChildIsNotImportedIsAGap(): void
    {
        $missingId = $this->createRequest($this->targetYearId, 'Léa', 'accepted', $this->louveteauxSectionId);
        $this->createRequest($this->targetYearId, 'Tom', 'accepted');
        $this->createMemberYear($this->targetYearId, 'Tom', '2018-01-01', $this->louveteauxSectionId);

        $gaps = $this->review->preparedImportGaps($this->targetYearId);

        $this->assertCount(1, $gaps->acceptedRequestsMissing);
        $missing = $gaps->acceptedRequestsMissing[0];
        $this->assertSame($missingId, $missing->requestId);
        $this->assertSame('Léa Dupont', $missing->childName);
        $this->assertSame(MissingAcceptedRequest::STATUS_ACCEPTED, $missing->status);
        $this->assertSame($this->louveteauxSectionId, $missing->intendedSectionId);
    }

    /**
     * Homonyms: the reconciliation refuses to link a request matching two
     * imported members — and the child IS in the import. Never a gap.
     */
    public function testAnAmbiguousMatchIsNeverReportedMissing(): void
    {
        $this->createRequest($this->targetYearId, 'Tom', 'accepted');
        $this->createMemberYear($this->targetYearId, 'Tom', '2018-01-01', $this->louveteauxSectionId);
        $this->createMemberYear($this->targetYearId, 'Tom', '2018-01-01', $this->louveteauxSectionId);

        $this->assertSame([], $this->review->preparedImportGaps($this->targetYearId)->acceptedRequestsMissing);
    }

    public function testAnEncodedRequestWhoseMemberIsNotInTheImportIsAGap(): void
    {
        $present = $this->createMemberYear($this->targetYearId, 'Tom', '2018-01-01', $this->louveteauxSectionId);
        $earlier = $this->createMemberYear($this->publicYearId, 'Léa', '2018-01-01', $this->louveteauxSectionId);

        $linkedPresent = $this->createRequest($this->targetYearId, 'Tom', 'accepted');
        $this->requests->linkToMemberAndEncode($linkedPresent, $present['member_id'], new \DateTimeImmutable());
        $linkedAbsent = $this->createRequest($this->targetYearId, 'Léa', 'accepted');
        $this->requests->linkToMemberAndEncode($linkedAbsent, $earlier['member_id'], new \DateTimeImmutable());

        $gaps = $this->review->preparedImportGaps($this->targetYearId);

        $this->assertCount(1, $gaps->acceptedRequestsMissing);
        $this->assertSame($linkedAbsent, $gaps->acceptedRequestsMissing[0]->requestId);
        $this->assertSame(MissingAcceptedRequest::STATUS_ENCODED, $gaps->acceptedRequestsMissing[0]->status);
    }

    public function testNothingPreparedIsNoGap(): void
    {
        $this->assertTrue($this->review->preparedImportGaps($this->targetYearId)->isEmpty());
    }

    // ── Fixtures ───────────────────────────────────────────────────

    private function stateAt(string $date): ReenrollmentCampaignState
    {
        $state = $this->review->reenrollmentCampaign($this->targetYearId, new \DateTimeImmutable($date . ' 10:00:00'));
        $this->assertNotNull($state);

        return $state;
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
    private function createMemberYear(
        int $scoutYearId,
        string $firstName,
        string $birthDate,
        int $sectionId,
        bool $leaving = false,
        bool $chief = false,
        ?int $memberId = null,
        bool $active = true
    ): array {
        $memberId ??= RegistrationTestHelper::insertMember($this->pdo, 'DESK_' . uniqid('', true));

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       birth_date_encrypted, leaving, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
            $leaving ? 1 : 0,
            $active ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $branchId = (int) $this->pdo->query('SELECT age_branch_id FROM sections WHERE id = ' . $sectionId)->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $chief ? $this->chiefFunctionId : $this->animeFunctionId, $sectionId, $branchId]);

        return ['member_id' => $memberId, 'member_year_id' => $memberYearId];
    }

    private function createRequest(int $scoutYearId, string $firstName, string $status, ?int $intendedSectionId = null): int
    {
        $created = $this->requests->create($scoutYearId, [
            'parent_name' => 'Parent', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2018-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        if ($status !== 'pending') {
            $this->requests->updateStatus($created['id'], $status, $status === 'refused' ? new \DateTimeImmutable() : null);
        }
        if ($intendedSectionId !== null) {
            $this->requests->updateIntendedSection($created['id'], $intendedSectionId);
        }

        return $created['id'];
    }
}
