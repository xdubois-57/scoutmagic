<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Member\Household\HouseholdKey;
use Core\Member\Household\HouseholdRepository;
use Core\Security\EncryptionService;
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
        $this->repository->markAcceptedEmailSent($ids['accepted_sent']);
        $this->repository->updateStatus($ids['encoded_unsent'], 'encoded', new \DateTimeImmutable());
        $this->repository->updateStatus($ids['encoded_sent'], 'encoded', new \DateTimeImmutable());
        $this->repository->markAcceptedEmailSent($ids['encoded_sent']);
        $this->repository->updateStatus($ids['refused_unsent'], 'refused', new \DateTimeImmutable());
        $this->repository->updateStatus($ids['refused_sent'], 'refused', new \DateTimeImmutable());
        $this->repository->markRefusedEmailSent($ids['refused_sent']);
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
        $this->repository->markRefusedEmailSent($ids['refused_unsent']);
        $this->assertSame(2, $this->repository->countDecisionEmailsToSendForYears([$this->scoutYearId]));

        // A refused request whose ACCEPTANCE e-mail went out earlier (the
        // decision changed) still owes its refusal e-mail.
        $this->repository->updateStatus($ids['accepted_sent'], 'refused', new \DateTimeImmutable());
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
}
