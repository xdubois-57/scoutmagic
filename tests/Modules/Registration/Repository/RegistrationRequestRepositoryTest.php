<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Member\Household\HouseholdKey;
use Core\Member\Household\HouseholdRepository;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationRequestRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $repository;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $memberSequence = 0;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    /**
     * @return array{
     *   parent_name: string, child_last_name: string, child_first_name: string,
     *   gender: string, birth_date: string, street: string, number: string,
     *   postal_code: string, city: string, email: string, phone1: string,
     *   phone2: ?string, remarks: ?string,
     *   previous_unit_answer?: ?string, previous_unit_name?: ?string
     * }
     */
    private function sampleFields(array $overrides = []): array
    {
        return array_merge([
            'parent_name' => 'Marie Dupont',
            'child_last_name' => 'Dupont',
            'child_first_name' => 'Léa',
            'gender' => 'F',
            'birth_date' => '2019-05-12',
            'street' => 'Rue de la Paix',
            'number' => '12',
            'postal_code' => '1000',
            'city' => 'Bruxelles',
            'email' => 'marie.dupont@example.com',
            'phone1' => '0470123456',
            'phone2' => null,
            'remarks' => null,
        ], $overrides);
    }

    public function testCreateEncryptsFieldsAndDefaultsToPending(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);

        $row = $this->pdo->query('SELECT * FROM registration_requests WHERE id = ' . $created['id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        // Encrypted at rest: the raw column must never contain the plaintext.
        $this->assertStringNotContainsString('Dupont', (string) $row['child_last_name_encrypted']);
        $this->assertStringNotContainsString('marie.dupont@example.com', (string) $row['email_encrypted']);
        $this->assertNotSame('', $row['tracking_token_hash']);

        $found = $this->repository->findById($created['id']);
        $this->assertNotNull($found);
        $this->assertSame('Léa', $found->childFirstName);
        $this->assertSame('Dupont', $found->childLastName);
        $this->assertSame('marie.dupont@example.com', $found->email);
        $this->assertSame(RegistrationRequestRepository::normalizeEmail($found->email), 'marie.dupont@example.com');
    }

    /**
     * Issue #331 — the question a chief needs answered before encoding in
     * Desk. A caller that does not supply it at all (the reference
     * dataset's seeder, every request filed before the column existed)
     * gets NULL, which means « the question was not put » and is a third
     * state next to « oui » and « non ».
     */
    public function testCreateKeepsTheAnswerAboutAPreviousUnitAndEncryptsTheUnitName(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields([
            'previous_unit_answer' => RegistrationRequest::PREVIOUS_UNIT_YES,
            'previous_unit_name' => '57e Unité Saint-Michel',
        ]), null, []);

        $row = $this->pdo->query('SELECT * FROM registration_requests WHERE id = ' . $created['id'])
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('yes', $row['previous_unit_answer']);
        $this->assertStringNotContainsString('Saint-Michel', (string) $row['previous_unit_name_encrypted']);

        $found = $this->repository->findById($created['id']);
        $this->assertNotNull($found);
        $this->assertTrue($found->hasPreviousUnit());
        $this->assertSame('57e Unité Saint-Michel', $found->previousUnitName);
    }

    public function testCreateLeavesTheQuestionUnansweredWhenTheCallerDoesNotAskIt(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);

        $found = $this->repository->findById($created['id']);
        $this->assertNotNull($found);
        $this->assertNull($found->previousUnitAnswer);
        $this->assertFalse($found->hasPreviousUnit());
        $this->assertNull($found->previousUnitName);
    }

    /**
     * A « non » carrying a unit name is a contradiction, and the name is
     * the half that goes: the Controller already drops it, and the
     * Repository refuses it again for any other caller.
     */
    public function testCreateNeverStoresAUnitNameAlongsideANegativeAnswer(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields([
            'previous_unit_answer' => RegistrationRequest::PREVIOUS_UNIT_NO,
            'previous_unit_name' => '57e Unité Saint-Michel',
        ]), null, []);

        $found = $this->repository->findById($created['id']);
        $this->assertNotNull($found);
        $this->assertSame('no', $found->previousUnitAnswer);
        $this->assertNull($found->previousUnitName);
    }

    /**
     * The column is an ENUM of two values plus NULL. Anything else is
     * stored as « not asked » rather than as a value MySQL would coerce
     * to the empty string behind everybody's back.
     */
    public function testCreateRefusesAnAnswerOutsideTheTwoTheColumnAccepts(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields([
            'previous_unit_answer' => 'peut-être',
            'previous_unit_name' => '57e Unité Saint-Michel',
        ]), null, []);

        $found = $this->repository->findById($created['id']);
        $this->assertNotNull($found);
        $this->assertNull($found->previousUnitAnswer);
        $this->assertNull($found->previousUnitName);
    }

    public function testCreatePersistsSiblingLinks(): void
    {
        $memberId1 = RegistrationTestHelper::insertMember($this->pdo, 'M1');
        $memberId2 = RegistrationTestHelper::insertMember($this->pdo, 'M2');

        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [$memberId1, $memberId2, $memberId1]);

        $siblingIds = $this->repository->findSiblingMemberIds($created['id']);
        sort($siblingIds);
        $this->assertSame([$memberId1, $memberId2], $siblingIds);
    }

    /**
     * « Fratrie déjà dans l'unité » reads the declared siblings still in the
     * unit (issue #843, the maintainer's decision after IT-03): an active
     * enrolment in the current scout year, or in the year the request
     * itself targets — per request, not one year for the whole batch —,
     * under the identity a merged member was folded into. Every screen
     * showing the siblings keeps reading the declared ones, the departed
     * included.
     */
    public function testPresentSiblingsAreTheDeclaredOnesStillEnrolledNowOrInTheRequestsYear(): void
    {
        $pastYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $currentYearId = $this->scoutYearId;

        $bothYears = $this->enrolledMember([$currentYearId => true, $targetYearId => true]);
        $thisYear = $this->enrolledMember([$currentYearId => true]);
        $reEnrolled = $this->enrolledMember([$currentYearId => false, $targetYearId => true]);
        $left = $this->enrolledMember([$pastYearId => true, $currentYearId => false]);
        $lastYearOnly = $this->enrolledMember([$pastYearId => true]);
        $neverEnrolled = $this->enrolledMember([]);
        // Merged since into an identity enrolled this year: the merge moved
        // the member_years, not the link the family declared.
        $merged = $this->enrolledMember([]);
        $this->pdo->prepare('UPDATE members SET merged_into_member_id = ? WHERE id = ?')
            ->execute([$this->enrolledMember([$currentYearId => true]), $merged]);

        $declared = [$bothYears, $thisYear, $reEnrolled, $left, $lastYearOnly, $neverEnrolled, $merged];
        $forTarget = $this->repository->create($targetYearId, $this->sampleFields(), null, $declared)['id'];
        // Filed for the current year: the target year is not its year, so
        // an enrolment there alone does not count for it.
        $inYear = $this->repository->create($currentYearId, $this->sampleFields(), null, [$reEnrolled, $left])['id'];
        $none = $this->repository->create($targetYearId, $this->sampleFields(), null, [])['id'];
        $notAsked = $this->repository->create($targetYearId, $this->sampleFields(), null, [$thisYear])['id'];

        $present = $this->repository->findPresentSiblingMemberIdsForRequests(
            [$forTarget, $inYear, $none, $forTarget],
            $currentYearId
        );

        $expected = [$bothYears, $thisYear, $reEnrolled, $merged];
        sort($expected);
        $this->assertSame([$forTarget => $expected], $present);
        $this->assertArrayNotHasKey($notAsked, $present);
        $this->assertSame([], $this->repository->findPresentSiblingMemberIdsForRequests([], $currentYearId));

        // What the Passage page, the fiche and the export read: every
        // declared sibling, whoever has left since.
        $allDeclared = $this->repository->findSiblingMemberIdsForRequests([$forTarget, $inYear]);
        sort($allDeclared[$forTarget]);
        sort($declared);
        $this->assertSame($declared, $allDeclared[$forTarget]);
        $this->assertCount(2, $allDeclared[$inYear]);
        $this->assertSame([$forTarget => 7], $this->repository->countSiblingsForRequests([$forTarget]));
        $this->assertCount(7, $this->repository->findSiblingMemberIds($forTarget));
    }

    /**
     * A member with one member_years row per scout year given, active or
     * not.
     *
     * @param array<int, bool> $enrolments scout year id => is_active
     */
    private function enrolledMember(array $enrolments): int
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'SIB' . (++$this->memberSequence));
        $insert = $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, '
            . 'last_name_encrypted, is_active) VALUES (?, ?, ?, ?, ?)');
        foreach ($enrolments as $yearId => $active) {
            $insert->execute([
                $memberId,
                $yearId,
                $this->encryption->encrypt('Jules', 'member_years.first_name'),
                $this->encryption->encrypt('Dupont', 'member_years.last_name'),
                $active ? 1 : 0,
            ]);
        }

        return $memberId;
    }

    public function testVerifyTrackingTokenAcceptsCorrectTokenOnly(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);

        $this->assertTrue($this->repository->verifyTrackingToken($created['id'], $created['tracking_token']));
        $this->assertFalse($this->repository->verifyTrackingToken($created['id'], 'wrong-token'));
        $this->assertFalse($this->repository->verifyTrackingToken(999999, $created['tracking_token']));
    }

    public function testFindAllByEmailBlindIndexMatchesOnlySameEmail(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(['email' => 'a@example.com']), null, []);
        $this->repository->create($this->scoutYearId, $this->sampleFields(['email' => 'b@example.com']), null, []);

        $blindIndex = $this->encryption->blindIndex(RegistrationRequestRepository::normalizeEmail('a@example.com'), 'registration_email');
        $matches = $this->repository->findAllByEmailBlindIndex($blindIndex);

        $this->assertCount(1, $matches);
        $this->assertSame($created['id'], $matches[0]->id);
    }

    public function testFindAllForYearOnlyReturnsRequestsForThatYearOldestFirst(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $first = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Aa']), null, []);
        $this->repository->create($otherYearId, $this->sampleFields(), null, []);
        $second = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Bb']), null, []);

        $all = $this->repository->findAllForYear($this->scoutYearId);
        $this->assertCount(2, $all);
        $this->assertSame($first['id'], $all[0]->id);
        $this->assertSame($second['id'], $all[1]->id);
    }

    /**
     * Core\Member\Household\HouseholdService enumerates every household of
     * a scout year at once, so it asks for a batch of addresses in one
     * query rather than one query per household.
     */
    /**
     * The encoded requests of a year, decrypted, with the member each was
     * linked to — and nothing else: not an accepted one still waiting for
     * Desk, not another year's.
     */
    public function testFindEncodedForYearReturnsOnlyThatYearsEncodedRequests(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK_ENCODED');
        $otherMemberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK_OTHER');

        $encoded = $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields(['birth_date' => '2020-02-03']),
            null,
            []
        );
        $this->repository->linkToMemberAndEncode($encoded['id'], $memberId, new \DateTimeImmutable());
        $accepted = $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields(['child_first_name' => 'Zoé']),
            null,
            []
        );
        $this->repository->updateStatus($accepted['id'], 'accepted', null);
        $elsewhere = $this->repository->create(
            $otherYearId,
            $this->sampleFields(['child_first_name' => 'Tom']),
            null,
            []
        );
        $this->repository->linkToMemberAndEncode($elsewhere['id'], $otherMemberId, new \DateTimeImmutable());

        $found = $this->repository->findEncodedForYear($this->scoutYearId);

        $this->assertCount(1, $found);
        $this->assertSame($encoded['id'], $found[0]->id);
        $this->assertSame($memberId, $found[0]->linkedMemberId);
        $this->assertSame('2020-02-03', $found[0]->birthDate);
    }

    public function testCountHouseholdsAtAddressesCountsOnlyAcceptedAndEncodedRequestsOfThatYear(): void
    {
        $paix = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);
        $paixSecond = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Noé']), null, []);
        $paixPending = $this->repository->create($this->scoutYearId, $this->sampleFields(['child_first_name' => 'Zoé']), null, []);
        $louise = $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields(['street' => 'Avenue Louise', 'number' => '10']),
            null,
            []
        );
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $otherYear = $this->repository->create($otherYearId, $this->sampleFields(), null, []);

        $now = new \DateTimeImmutable('2026-10-01 12:00:00');
        $this->repository->updateStatus($paix['id'], 'accepted', null);
        $this->repository->updateStatus($paixSecond['id'], 'encoded', $now);
        $this->repository->updateStatus($louise['id'], 'accepted', null);
        $this->repository->updateStatus($otherYear['id'], 'accepted', null);
        // $paixPending stays pending — a request that may still be refused
        // must not inflate anyone's household.

        $paixIndex = $this->blindIndexFor($paix['id']);
        $louiseIndex = $this->blindIndexFor($louise['id']);

        $counts = $this->repository->countRequestsInHouseholds(
            [HouseholdKey::fromStorable($paixIndex), HouseholdKey::fromStorable($louiseIndex)],
            $this->scoutYearId
        );

        $this->assertSame(2, $counts[$paixIndex]);
        $this->assertSame(1, $counts[$louiseIndex]);
        $this->assertSame(
            $this->repository->countRequestsInHousehold(HouseholdKey::fromStorable($paixIndex), $this->scoutYearId, null),
            $counts[$paixIndex]
        );
    }

    public function testCountHouseholdsAtAddressesLeavesAnAddressWithNoRequestOutRatherThanReportingZero(): void
    {
        $created = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, []);
        $this->repository->updateStatus($created['id'], 'accepted', null);

        $counts = $this->repository->countRequestsInHouseholds(
            [
                HouseholdKey::fromStorable($this->blindIndexFor($created['id'])),
                HouseholdKey::fromStorable('an-index-nobody-lives-at'),
            ],
            $this->scoutYearId
        );

        $this->assertArrayNotHasKey('an-index-nobody-lives-at', $counts);
        $this->assertCount(1, $counts);
    }

    public function testCountHouseholdsAtAddressesOnAnEmptyListRunsNoQuery(): void
    {
        $this->assertSame([], $this->repository->countRequestsInHouseholds([], $this->scoutYearId));
    }

    /**
     * A request is stored under the household the core derives for its
     * address — the core's key, not a second derivation of it (issue #630).
     * The same address typed with other spacing and case lands in the same
     * household, because the core normalizes it; the module never does.
     */
    public function testARequestIsStoredUnderTheHouseholdTheCoreDerives(): void
    {
        $created = $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields(['street' => '  rue de la PAIX ', 'number' => '12']),
            null,
            []
        );

        $household = (new HouseholdRepository($this->pdo, $this->encryption))
            ->keyForAddress('Rue de la Paix', '12', null, $this->sampleFields()['postal_code']);

        $this->assertNotNull($household);
        $this->assertSame($household->storable(), $this->blindIndexFor($created['id']));
    }

    private function blindIndexFor(int $requestId): string
    {
        $stmt = $this->pdo->prepare('SELECT address_normalized_blind_index FROM registration_requests WHERE id = ?');
        $stmt->execute([$requestId]);

        return (string) $stmt->fetchColumn();
    }

    public function testNameDobBlindIndexIsStableAcrossCaseAndWhitespace(): void
    {
        $normalizedA = RegistrationRequestRepository::normalizeForNameDobBlindIndex('Dupont', 'Léa', '2019-05-12');
        $normalizedB = RegistrationRequestRepository::normalizeForNameDobBlindIndex('  DUPONT ', ' léa', '2019-05-12');

        $this->assertSame($normalizedA, $normalizedB);
    }

    // ------------------------------------------------------------------
    // The three figures of the management dashboard (issue #843, IT-01)
    // ------------------------------------------------------------------

    /**
     * One request per interesting state, in the year under test, plus the
     * same set in another year that must never leak into the counts.
     *
     * @return array<string, int> label => request id, for the year under test
     */
    private function seedDashboardStates(int $scoutYearId): array
    {
        $ids = [];
        foreach ([
            'pending', 'accepted_unsent', 'accepted_sent', 'encoded_unsent', 'encoded_sent',
            'refused_unsent', 'refused_sent', 'withdrawn',
        ] as $label) {
            $ids[$label] = $this->repository->create(
                $scoutYearId,
                $this->sampleFields(['child_first_name' => ucfirst($label), 'email' => $label . '@example.com']),
                null,
                []
            )['id'];
        }

        $this->repository->updateStatus($ids['accepted_unsent'], 'accepted', null);
        $this->repository->updateStatus($ids['accepted_sent'], 'accepted', null);
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $ids['accepted_sent'], 'accepted');
        $this->repository->updateStatus($ids['encoded_unsent'], 'encoded', new \DateTimeImmutable());
        $this->repository->updateStatus($ids['encoded_sent'], 'encoded', new \DateTimeImmutable());
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $ids['encoded_sent'], 'accepted');
        $this->repository->updateStatus($ids['refused_unsent'], 'refused', new \DateTimeImmutable());
        $this->repository->updateStatus($ids['refused_sent'], 'refused', new \DateTimeImmutable());
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $ids['refused_sent'], 'refused');
        $this->repository->updateStatus($ids['withdrawn'], 'withdrawn', new \DateTimeImmutable());

        return $ids;
    }

    public function testCountPendingForYearsCountsOnlyUndecidedRequestsOfThoseYears(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $this->seedDashboardStates($this->scoutYearId);
        $this->seedDashboardStates($otherYearId);

        $this->assertSame(1, $this->repository->countPendingForYears([$this->scoutYearId]));
        $this->assertSame(2, $this->repository->countPendingForYears([$this->scoutYearId, $otherYearId]));
    }

    /**
     * The definition the family's own page applies: an acceptance — still
     * `accepted` or already `encoded` — and a refusal whose e-mail has not
     * left. A sent one, a withdrawal and a pending request send nothing
     * more.
     */
    public function testCountDecisionEmailsToSendCountsEveryDecisionTheFamilyCannotSeeYet(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $ids = $this->seedDashboardStates($this->scoutYearId);
        $this->seedDashboardStates($otherYearId);

        // accepted_unsent, encoded_unsent, refused_unsent.
        $this->assertSame(3, $this->repository->countDecisionEmailsToSendForYears([$this->scoutYearId]));

        // Sending the refusal takes it out of the count, and only it.
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $ids['refused_unsent'], 'refused');
        $this->assertSame(2, $this->repository->countDecisionEmailsToSendForYears([$this->scoutYearId]));

        // A refused request whose ACCEPTANCE e-mail went out earlier (the
        // decision changed) still owes its refusal e-mail.
        $this->repository->updateStatus($ids['accepted_sent'], 'refused', new \DateTimeImmutable());
        $this->assertSame(3, $this->repository->countDecisionEmailsToSendForYears([$this->scoutYearId]));

        // Accepted again after that: the first acceptance e-mail announced
        // an earlier decision, and this one is owed its own (R3).
        $this->repository->updateStatus($ids['accepted_sent'], 'pending', null);
        $this->repository->updateStatus($ids['accepted_sent'], 'accepted', null);
        $this->assertSame(3, $this->repository->countDecisionEmailsToSendForYears([$this->scoutYearId]));
    }

    /**
     * The count and the family's own page are two readings of one rule,
     * and must never drift apart: a decision whose e-mail is owed is
     * exactly a request the family still sees « en attente » although it
     * is not pending (Service\RequestStatusService::visibleStatus()).
     */
    public function testTheDecisionEmailCountIsExactlyWhatTheFamilyCannotSeeYet(): void
    {
        $this->seedDashboardStates($this->scoutYearId);
        $statusService = new \Modules\Registration\Service\RequestStatusService(
            $this->repository,
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
        );

        $hidden = array_filter(
            $this->repository->findAllForYear($this->scoutYearId),
            static fn(RegistrationRequest $request) => $request->status !== RegistrationRequest::STATUS_PENDING
                && $statusService->visibleStatus($request) === RegistrationRequest::STATUS_PENDING
        );

        $this->assertNotEmpty($hidden);
        $this->assertSame(count($hidden), $this->repository->countDecisionEmailsToSendForYears([$this->scoutYearId]));
    }

    /**
     * IT-01's definition, read from the decision e-mail table (IT-05): an
     * e-mail planned, failed or interrupted has not left, and the decision
     * stays counted until one of ITS decision is sent.
     */
    public function testAPlannedOrFailedDecisionEmailIsStillToSend(): void
    {
        $ids = $this->seedDashboardStates($this->scoutYearId);
        $emails = new DecisionEmailRepository($this->pdo);
        $now = new \DateTimeImmutable();
        $emails->insert($ids['accepted_unsent'], 'accepted', 'scheduled', $now->modify('+1 day'), 1, false, $now);
        $failed = (int) $emails->insert($ids['refused_unsent'], 'refused', 'sending', null, 1, false, $now);
        $emails->markFailed($failed, 'mail_transport', 'Échec.', $now);

        $this->assertSame(3, $this->repository->countDecisionEmailsToSendForYears([$this->scoutYearId]));
    }

    /**
     * D8: the status write and the end of the old decision's e-mails are
     * one unit of work. The planned e-mail is cancelled, and the count
     * comes back to the caller for its journal entry.
     */
    public function testUpdateStatusSupersedesTheOldDecisionsEmails(): void
    {
        $id = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [])['id'];
        $this->repository->updateStatus($id, 'accepted', null);
        $emails = new DecisionEmailRepository($this->pdo);
        $now = new \DateTimeImmutable();
        $sent = RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $id, 'accepted');
        $planned = (int) $emails->insert($id, 'accepted', 'scheduled', $now->modify('+1 day'), 2, true, $now);

        $this->assertSame(1, $this->repository->updateStatus($id, 'refused', $now));

        $this->assertSame('cancelled', $emails->findById($planned)?->state);
        $this->assertSame('sent', $emails->findById($sent)?->state);
        $this->assertNotNull($emails->findById($sent)?->supersededAt);
        $this->assertSame(0, $this->repository->updateStatus($id, 'pending', null));
    }

    /**
     * What a request read carries: the first send of its CURRENT
     * decision's e-mail, and nothing for an earlier decision — in a single
     * read and in a list read alike.
     */
    public function testARequestCarriesOnlyItsCurrentDecisionsEmailDate(): void
    {
        $toldThenRefused = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [])['id'];
        $this->repository->updateStatus($toldThenRefused, 'accepted', null);
        RegistrationTestHelper::recordDecisionEmailSent(
            $this->pdo,
            $toldThenRefused,
            'accepted',
            new \DateTimeImmutable('2026-09-01 08:00:00')
        );
        $told = $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields(['child_first_name' => 'Noa', 'email' => 'noa@example.com']),
            null,
            []
        )['id'];
        $this->repository->updateStatus($told, 'accepted', null);
        RegistrationTestHelper::recordDecisionEmailSent(
            $this->pdo,
            $told,
            'accepted',
            new \DateTimeImmutable('2026-09-02 08:00:00')
        );

        $this->repository->updateStatus($toldThenRefused, 'refused', new \DateTimeImmutable());

        $single = $this->repository->findById($toldThenRefused);
        $this->assertNull($single?->decisionEmailSentAt);
        $this->assertNull($single?->acceptedEmailSentAt(), 'an acceptance e-mail is shown only for an acceptance');
        $this->assertNull($single?->refusedEmailSentAt());

        $listed = [];
        foreach ($this->repository->findAllForYear($this->scoutYearId) as $request) {
            $listed[$request->id] = $request->decisionEmailSentAt?->format('Y-m-d H:i:s');
        }
        $this->assertSame([$toldThenRefused => null, $told => '2026-09-02 08:00:00'], $listed);
        $this->assertSame(
            '2026-09-02 08:00:00',
            $this->repository->findById($told)?->acceptedEmailSentAt()?->format('Y-m-d H:i:s')
        );
    }

    /**
     * Encoding keeps an acceptance: its e-mail still announces the
     * decision. A request linked from another state has changed decision,
     * and what was written for the old one is obsolete — a refusal still
     * planned is cancelled, not left to leave after the child was encoded.
     */
    public function testEncodingKeepsTheAcceptanceEmailsAndSupersedesAnyOther(): void
    {
        $emails = new DecisionEmailRepository($this->pdo);
        $now = new \DateTimeImmutable();

        $accepted = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [])['id'];
        $this->repository->updateStatus($accepted, 'accepted', null);
        $told = RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $accepted, 'accepted');
        $member = RegistrationTestHelper::insertMember($this->pdo, 'D1');
        $this->repository->linkToMemberAndEncode($accepted, $member, $now);
        $this->assertTrue($emails->findById($told)?->isCurrent());
        $this->assertNotNull($this->repository->findById($accepted)?->decisionEmailSentAt);

        $refused = $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields(['child_first_name' => 'Noa', 'email' => 'noa@example.com']),
            null,
            []
        )['id'];
        $this->repository->updateStatus($refused, 'refused', $now);
        $planned = (int) $emails->insert($refused, 'refused', 'scheduled', $now->modify('+1 day'), 1, false, $now);
        $member = RegistrationTestHelper::insertMember($this->pdo, 'D2');
        $this->repository->linkToMemberAndEncode($refused, $member, $now);
        $this->assertSame('cancelled', $emails->findById($planned)?->state);
        $this->assertNull($emails->findActiveForRequest($refused));
    }

    /**
     * R7: a request's decision e-mails go with it — explicitly, since the
     * SQLite fixture does not enforce the schema's cascade.
     */
    public function testDeleteByIdTakesTheRequestsDecisionEmailsWithIt(): void
    {
        $gone = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [])['id'];
        $this->repository->updateStatus($gone, 'refused', new \DateTimeImmutable());
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $gone, 'refused');

        $this->repository->deleteById($gone);

        $this->assertNull($this->repository->findById($gone));
        $this->assertSame([], (new DecisionEmailRepository($this->pdo))->findForRequest($gone));
    }

    public function testCountAcceptedNotEncodedCountsOnlyAcceptedRequestsOfThoseYears(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $this->seedDashboardStates($this->scoutYearId);
        $this->seedDashboardStates($otherYearId);

        // accepted_unsent and accepted_sent — the encoded ones are in Desk.
        $this->assertSame(2, $this->repository->countAcceptedNotEncodedForYears([$this->scoutYearId]));
        $this->assertSame(4, $this->repository->countAcceptedNotEncodedForYears([$this->scoutYearId, $otherYearId]));
    }

    public function testTheDashboardCountsAreZeroForNoYearAndIgnoreARepeatedYear(): void
    {
        $this->seedDashboardStates($this->scoutYearId);

        $this->assertSame(0, $this->repository->countPendingForYears([]));
        $this->assertSame(0, $this->repository->countDecisionEmailsToSendForYears([]));
        $this->assertSame(0, $this->repository->countAcceptedNotEncodedForYears([]));
        $this->assertSame(1, $this->repository->countPendingForYears([$this->scoutYearId, $this->scoutYearId]));
    }

    /**
     * The refusal reason (issue #843, D6) is written with the status, read
     * back on the request, and never outlives the refusal: any other
     * transition clears it, and a reason is only ever stored with
     * « refused ».
     */
    public function testTheRefusalReasonIsStoredWithTheRefusalAndClearedByAnyOtherStatus(): void
    {
        $id = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [])['id'];
        $this->assertNull($this->repository->findById($id)?->refusalReason);

        $this->repository->updateStatus($id, 'refused', new \DateTimeImmutable(), RegistrationRequest::REFUSAL_NO_ROOM);
        $this->assertSame(RegistrationRequest::REFUSAL_NO_ROOM, $this->repository->findById($id)?->refusalReason);

        $this->repository->updateStatus($id, 'refused', new \DateTimeImmutable(), RegistrationRequest::REFUSAL_OTHER);
        $this->assertSame(RegistrationRequest::REFUSAL_OTHER, $this->repository->findById($id)?->refusalReason);

        $this->repository->updateStatus($id, 'pending', null, RegistrationRequest::REFUSAL_NO_ROOM);
        $this->assertNull($this->repository->findById($id)?->refusalReason);
        $stored = $this->pdo->query("SELECT refusal_reason FROM registration_requests WHERE id = {$id}")->fetchColumn();
        $this->assertNull($stored);
    }

    /**
     * A refusal without a reason — every caller that does not ask for one
     * yet — and an unknown reason both store none.
     */
    public function testARefusalWithoutAKnownReasonStoresNone(): void
    {
        $id = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [])['id'];

        $this->repository->updateStatus($id, 'refused', new \DateTimeImmutable());
        $this->assertNull($this->repository->findById($id)?->refusalReason);

        $this->repository->updateStatus($id, 'refused', new \DateTimeImmutable(), 'full');
        $this->assertNull($this->repository->findById($id)?->refusalReason);
        $this->assertSame('refused', $this->repository->findById($id)?->status);
    }

    /**
     * transactional() (issue #843, IT-04): the work commits as a whole, or
     * a throw rolls all of it back and is rethrown; inside a transaction
     * already open, it is that one that decides.
     */
    public function testTransactionalCommitsTheWholeWorkOrNoneOfIt(): void
    {
        $id = $this->repository->create($this->scoutYearId, $this->sampleFields(), null, [])['id'];

        $this->repository->transactional(function () use ($id): void {
            $this->repository->updateStatus($id, 'accepted', null);
            $this->repository->updateInternalNotes($id, 'Gardée');
        });
        $this->assertSame('accepted', $this->repository->findById($id)?->status);
        $this->assertSame('Gardée', $this->repository->findById($id)?->internalNotes);

        try {
            $this->repository->transactional(function () use ($id): void {
                $this->repository->updateStatus($id, 'refused', new \DateTimeImmutable(), 'other');
                throw new \RuntimeException('note write failed');
            });
            $this->fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('note write failed', $e->getMessage());
        }
        $this->assertSame('accepted', $this->repository->findById($id)?->status);
        $this->assertFalse($this->pdo->inTransaction());

        $this->pdo->beginTransaction();
        $this->repository->transactional(function () use ($id): void {
            $this->repository->updateInternalNotes($id, 'Dans la transaction ouverte');
        });
        $this->assertTrue($this->pdo->inTransaction(), 'an outer transaction is left for its owner to end');
        $this->pdo->rollBack();
        $this->assertSame('Gardée', $this->repository->findById($id)?->internalNotes);
    }

    /**
     * The queue's candidates: the live requests of one year — pending,
     * accepted, encoded — in order of filing, with the birth date and the
     * child key and nothing else of the fiche.
     */
    public function testQueueCandidatesAreTheLiveRequestsOfOneYearInOrderOfFiling(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $create = fn(array $fields): int => $this->repository->create(
            $this->scoutYearId,
            $this->sampleFields($fields),
            null,
            []
        )['id'];
        $late = $create(['child_first_name' => 'Tard']);
        $early = $create(['birth_date' => '2018-01-02']);
        $refused = $create(['child_first_name' => 'Non']);
        $this->repository->create($otherYearId, $this->sampleFields(), null, []);
        $this->pdo->exec("UPDATE registration_requests SET received_at = '2026-10-05 10:00:00' WHERE id = {$late}");
        $this->pdo->exec("UPDATE registration_requests SET received_at = '2026-10-01 10:00:00' WHERE id = {$early}");
        $this->repository->updateStatus($early, 'accepted', null);
        $this->repository->updateStatus($refused, 'refused', new \DateTimeImmutable());

        $candidates = $this->repository->findQueueCandidatesForYear($this->scoutYearId);

        $this->assertSame([$early, $late], array_map(static fn($c) => $c->id, $candidates));
        $this->assertSame(['accepted', 'pending'], array_map(static fn($c) => $c->status, $candidates));
        // Only a pending request's birth date is decrypted: it is the one
        // placed in a slot.
        $this->assertNull($candidates[0]->birthDate);
        $this->assertSame('2019-05-12', $candidates[1]->birthDate);
        $this->assertSame('2026-10-01 10:00:00', $candidates[0]->receivedAt->format('Y-m-d H:i:s'));
        $this->assertSame(
            $this->encryption->blindIndex(
                RegistrationRequestRepository::normalizeForNameDobBlindIndex('Dupont', 'Tard', '2019-05-12'),
                'registration_name_dob'
            ),
            $candidates[1]->nameDobBlindIndex
        );
    }

    /**
     * « Ancienne demande restée sans place »: per child key, the most recent
     * EARLIER year with a refusal « Pas de place » — not « Autre », not a
     * refusal without a reason, not a refusal of the same year or a later
     * one.
     */
    public function testNoRoomRefusalsAreReadPerChildFromEarlierYearsOnly(): void
    {
        $older = RegistrationTestHelper::insertScoutYear($this->pdo, '2024-2025', '2024-09-01', '2025-08-31');
        $previous = RegistrationTestHelper::insertScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $later = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $refuse = function (int $yearId, string $firstName, ?string $reason): string {
            $fields = $this->sampleFields(['child_first_name' => $firstName]);
            $id = $this->repository->create($yearId, $fields, null, [])['id'];
            $this->repository->updateStatus($id, 'refused', new \DateTimeImmutable(), $reason);

            return $this->encryption->blindIndex(
                RegistrationRequestRepository::normalizeForNameDobBlindIndex('Dupont', $firstName, '2019-05-12'),
                'registration_name_dob'
            );
        };
        $twice = $refuse($older, 'Deux', RegistrationRequest::REFUSAL_NO_ROOM);
        $refuse($previous, 'Deux', RegistrationRequest::REFUSAL_NO_ROOM);
        $other = $refuse($previous, 'Autre', RegistrationRequest::REFUSAL_OTHER);
        $none = $refuse($previous, 'Sans', null);
        $sameYear = $refuse($this->scoutYearId, 'Même', RegistrationRequest::REFUSAL_NO_ROOM);
        $laterYear = $refuse($later, 'Après', RegistrationRequest::REFUSAL_NO_ROOM);

        $labels = $this->repository->findNoRoomRefusalYearLabels(
            [$twice, $other, $none, $sameYear, $laterYear, $twice],
            '2026-09-01'
        );

        $this->assertSame([$twice => '2025-2026'], $labels);
        $this->assertSame([], $this->repository->findNoRoomRefusalYearLabels([], '2026-09-01'));
    }
}
