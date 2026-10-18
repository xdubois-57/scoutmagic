<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\PriorityOrder;
use Modules\Registration\Service\PriorityOrderService;
use Modules\Registration\Service\Recommendation;
use Modules\Registration\Service\RecommendationService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The recommendation queue (issue #843, D3/D5), on real repositories and
 * the module's real occupancy (Service\SlotService over
 * Service\ForecastService).
 *
 * The fixture reproduces the mock-up's Louveteaux 1re and 2e année, with
 * the mock-up's request numbers, so the five explanations of the
 * chantier's acceptance criteria are checked word for word:
 *
 * - Louveteaux · 1re année, capacity 15, 6 places already taken (accepted
 *   requests): #416 (sibling, filed 08/10), #417 (refused « Pas de place »
 *   in 2025-2026, 04/10), #418 (05/10), #419 (09/10), #409 (03/10) and its
 *   possible duplicate #414 (07/10);
 * - Louveteaux · 2e année, capacity 15, 8 places taken: #398 (sibling);
 * - #401, born outside every age bracket.
 *
 * Each sibling declared there is still in the unit — an active enrolment
 * in the current scout year — which is what « Fratrie déjà dans l'unité »
 * reads (the maintainer's decision after IT-03): the tests named
 * …SiblingStillInTheUnit / …NoLongerInTheUnit vary exactly that.
 */
#[Group('database')]
class RecommendationServiceTest extends TestCase
{
    private const LOU1_BIRTH = '2019-05-04';
    private const LOU2_BIRTH = '2018-03-02';
    private const NO_ROOM = RegistrationRequest::REFUSAL_NO_ROOM;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private RegistrationRequestRepository $requests;
    private SlotCapacityRepository $capacities;
    private RecommendationService $service;
    private SlotService $slotService;
    private ScoutYearService $scoutYears;
    private int $louveteauxId;
    private int $pastYearId;
    private int $publicYearId;
    private int $targetYearId;
    private int $memberSeq = 0;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settingService);
        $this->settingService->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false
        );
        $this->settingService->register(
            ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false
        );

        $this->scoutYears = new ScoutYearService($this->pdo);
        $this->pastYearId = $this->scoutYears->ensureYear('2025-2026');
        $this->publicYearId = $this->scoutYears->ensureYear('2026-2027');
        $this->targetYearId = $this->scoutYears->ensureYear('2027-2028');
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->publicYearId);

        $this->louveteauxId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $this->capacities = new SlotCapacityRepository($this->pdo);
        $this->capacities->upsert($this->louveteauxId, 1, 15);
        $this->capacities->upsert($this->louveteauxId, 2, 15);

        $this->requests = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $this->slotService = RegistrationTestHelper::slotService($this->pdo, $this->encryption, $this->settingService);
        $this->service = new RecommendationService(
            $this->requests,
            $this->slotService,
            new AgeBracketRepository($this->pdo),
            $this->scoutYears,
            new ScoutYearResolver($this->scoutYears, $this->settingService, new MemberYearRepository($this->pdo)),
            new PriorityOrderService($this->settingService)
        );
    }

    /**
     * The mock-up's data set: the places already taken, then the pending
     * requests under their mock-up numbers.
     */
    private function seedMockupDataSet(): void
    {
        $this->accepted(6, self::LOU1_BIRTH);
        $this->accepted(8, self::LOU2_BIRTH);

        $refused = $this->file($this->pastYearId, 'Lucas', 'Mertens', '2019-02-11', '2025-10-02 09:00:00');
        $this->requests->updateStatus($refused, 'refused', new \DateTimeImmutable(), self::NO_ROOM);

        $this->file($this->targetYearId, 'Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00', 416, true);
        $this->file($this->targetYearId, 'Lucas', 'Mertens', '2019-02-11', '2026-10-04 10:00:00', 417);
        $this->file($this->targetYearId, 'Sofia', 'Claes', self::LOU1_BIRTH, '2026-10-05 10:00:00', 418);
        $this->file($this->targetYearId, 'Arthur', 'Noël', self::LOU1_BIRTH, '2026-10-09 10:00:00', 419);
        $this->file($this->targetYearId, 'Noah', 'Peeters', '2019-07-07', '2026-10-03 10:00:00', 409);
        $this->file($this->targetYearId, 'Noah', 'Peeters', '2019-07-07', '2026-10-07 10:00:00', 414);
        $this->file($this->targetYearId, 'Hugo', 'Renard', self::LOU2_BIRTH, '2026-09-28 10:00:00', 398, true);
        $this->file($this->targetYearId, 'Inès', 'Bouchat', '2024-01-01', '2026-09-29 10:00:00', 401);
    }

    public function testTheFiveExplanationsOfTheMockupAreReproducedWordForWord(): void
    {
        $this->seedMockupDataSet();

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame(
            "Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité.",
            $result[416]->explanation
        );
        $this->assertSame(
            'Oui — 1re demande classée sur 4 pour Louveteaux · 1re année. Avant elle, 7 places sur 15 seraient '
            . 'occupées (47 %), dans la limite du « Oui » (70 %). Classement favorisé par : ancienne demande restée '
            . 'sans place (2025-2026).',
            $result[417]->explanation
        );
        $this->assertSame(
            "Pas de recommandation tant que le doublon possible de la demande #409 n'est pas réglé.",
            $result[414]->explanation
        );
        $this->assertSame(
            'Oui — 2e demande classée sur 4 pour Louveteaux · 1re année. Avant elle, 8 places sur 15 seraient '
            . 'occupées (53 %), dans la limite du « Oui » (70 %).',
            $result[409]->explanation
        );
        $this->assertSame(
            "Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité.",
            $result[398]->explanation
        );
        $this->assertSame(
            "Pas de recommandation : l'année de naissance est hors des tranches d'âge de l'unité.",
            $result[401]->explanation
        );
    }

    /**
     * The whole queue of Louveteaux · 1re année: « Toujours Oui » first, then
     * the ranking, each judged on the occupancy before it; the duplicate and
     * the out-of-bracket request outside it, after it. Every queued request
     * is Oui here: 6 + 4 = 10 places of 15 before the last one, 67 %. The
     * requests left out follow, in order of filing.
     */
    public function testEachSlotHasItsOwnQueueJudgedOnTheOccupancyBeforeEachRequest(): void
    {
        $this->seedMockupDataSet();

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame([416, 417, 409, 418, 419, 398, 401, 414], array_keys($result));
        $this->assertSame(
            [
                [416, 'always', 1, null, 6, SlotMath::LEVEL_YES],
                [417, 'rank', 2, 1, 7, SlotMath::LEVEL_YES],
                [409, 'rank', 3, 2, 8, SlotMath::LEVEL_YES],
                [418, 'rank', 4, 3, 9, SlotMath::LEVEL_YES],
                [419, 'rank', 5, 4, 10, SlotMath::LEVEL_YES],
                [398, 'always', 1, null, 8, SlotMath::LEVEL_YES],
            ],
            array_map(
                static fn(Recommendation $r): array => [
                    $r->requestId, $r->kind, $r->position, $r->rank, $r->occupiedBefore, $r->level,
                ],
                array_values(array_filter($result, static fn(Recommendation $r): bool => $r->isQueued()))
            )
        );
        $this->assertSame(2, $result[398]->yearInBranch);
        $this->assertSame('Louveteaux · 2e année', $result[398]->slotLabel);
        $this->assertSame(409, $result[414]->duplicateOfRequestId);
        $this->assertNull($result[414]->level);
        $this->assertNull($result[401]->level);
        $this->assertFalse($result[401]->isQueued());
    }

    /**
     * The queue starts from SlotService's own occupancy — the same number
     * the capacity check and the public level read.
     */
    public function testTheQueueStartsFromTheSlotsOwnOccupancy(): void
    {
        $this->seedMockupDataSet();

        $occupied = null;
        $rows = $this->slotService->capacityBreakdownForYear($this->targetYearId, '2027-2028', $this->publicYearId);
        foreach ($rows as $row) {
            if ($row['age_branch_id'] === $this->louveteauxId && $row['year_in_branch'] === 1) {
                $occupied = $row['occupied'];
            }
        }

        $this->assertSame(6, $occupied);
        $this->assertSame($occupied, $this->service->forYear($this->targetYearId)[416]->occupiedBefore);
    }

    /**
     * A ranking changes the order of the ranked requests and leaves
     * « Toujours Oui » where it was.
     */
    public function testARankingReordersTheRankedRequestsWithoutTouchingToujoursOui(): void
    {
        $this->seedMockupDataSet();
        $this->saveOrder(['always' => ['sibling'], 'rank' => ['arrival', 'previous_no_room'], 'off' => []]);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame([416, 409, 417, 418, 419], array_slice(array_keys($result), 0, 5));
        $this->assertSame(Recommendation::KIND_ALWAYS, $result[416]->kind);
        $this->assertSame(6, $result[416]->occupiedBefore);
        // Still favoured by its old refusal — the criterion is evaluated,
        // it just comes second now.
        $this->assertStringEndsWith(
            'Classement favorisé par : ancienne demande restée sans place (2025-2026).',
            $result[417]->explanation
        );
    }

    /**
     * « Ancienne demande restée sans place » may be « Toujours Oui » too:
     * both « Toujours Oui » requests then go first, by date of filing.
     */
    public function testAnOldRefusalForLackOfRoomCanBeToujoursOui(): void
    {
        $this->seedMockupDataSet();
        $this->saveOrder(['always' => ['sibling', 'previous_no_room'], 'rank' => ['arrival'], 'off' => []]);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame([417, 416, 409, 418, 419], array_slice(array_keys($result), 0, 5));
        $this->assertSame(
            'Oui — règle « Toujours Oui » : déjà demandée en 2025-2026 et restée sans place.',
            $result[417]->explanation
        );
        $this->assertSame(7, $result[416]->occupiedBefore);
        $this->assertSame(
            'Oui — 1re demande classée sur 3 pour Louveteaux · 1re année. Avant elle, 8 places sur 15 seraient '
            . 'occupées (53 %), dans la limite du « Oui » (70 %).',
            $result[409]->explanation
        );
    }

    /**
     * A disabled criterion is not evaluated: the sibling is ranked like
     * anyone else, and the old refusal no longer favours anybody.
     */
    public function testADisabledCriterionIsNotEvaluated(): void
    {
        $this->seedMockupDataSet();
        $this->saveOrder(['always' => [], 'rank' => ['arrival'], 'off' => ['sibling', 'previous_no_room']]);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame([409, 417, 418, 416, 419], array_slice(array_keys($result), 0, 5));
        $this->assertSame(Recommendation::KIND_RANK, $result[416]->kind);
        $this->assertStringNotContainsString('Classement favorisé', $result[417]->explanation);
    }

    /**
     * A sibling in « Classement » is favoured there, and said to be.
     */
    public function testASiblingInTheRankingIsSaidToFavourTheRequest(): void
    {
        $this->seedMockupDataSet();
        $this->saveOrder(['always' => [], 'rank' => ['sibling', 'previous_no_room', 'arrival'], 'off' => []]);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame([416, 417, 409, 418, 419], array_slice(array_keys($result), 0, 5));
        $this->assertSame(
            'Oui — 1re demande classée sur 5 pour Louveteaux · 1re année. Avant elle, 6 places sur 15 seraient '
            . "occupées (40 %), dans la limite du « Oui » (70 %). Classement favorisé par : fratrie dans l'unité.",
            $result[416]->explanation
        );
    }

    /**
     * The sibling shapes that are still in the unit: an active enrolment in
     * the current scout year, or in the year the request targets — a
     * sibling already re-enrolled for it counts.
     *
     * @return array<string, array{array<string, bool>}>
     */
    public static function siblingsStillInTheUnit(): array
    {
        return [
            'enrolled this year' => [['public' => true]],
            'enrolled this year and re-enrolled for the target year' => [['public' => true, 'target' => true]],
            're-enrolled for the target year only' => [['target' => true]],
            'inactive this year but re-enrolled for the target year' => [['public' => false, 'target' => true]],
        ];
    }

    /**
     * The shapes that are not: no active enrolment in either year.
     *
     * @return array<string, array{array<string, bool>}>
     */
    public static function siblingsNoLongerInTheUnit(): array
    {
        return [
            'left: inactive this year, active last year' => [['past' => true, 'public' => false]],
            'left: enrolled last year only' => [['past' => true]],
            'inactive in the target year too' => [['public' => false, 'target' => false]],
            'never enrolled' => [[]],
        ];
    }

    /**
     * « Fratrie déjà dans l'unité » (the maintainer's decision after IT-03):
     * a declared sibling still in the unit makes the request « Toujours
     * Oui », with the explanation of the mock-up.
     *
     * @param array<string, bool> $enrolments
     */
    #[DataProvider('siblingsStillInTheUnit')]
    public function testADeclaredSiblingStillInTheUnitMakesTheRequestAlwaysYes(array $enrolments): void
    {
        $this->seedMockupDataSet();
        $this->declareSiblings(416, [$this->sibling($enrolments)]);

        $recommendation = $this->service->forYear($this->targetYearId)[416];

        $this->assertSame(Recommendation::KIND_ALWAYS, $recommendation->kind);
        $this->assertSame(SlotMath::LEVEL_YES, $recommendation->level);
        $this->assertSame(
            "Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité.",
            $recommendation->explanation
        );
    }

    /**
     * A declared sibling who has left the unit since counts for nothing:
     * the request falls back into the ranking, where it takes its place by
     * date of filing — 4th of 5, behind #417 (favoured), #409 and #418.
     *
     * @param array<string, bool> $enrolments
     */
    #[DataProvider('siblingsNoLongerInTheUnit')]
    public function testADeclaredSiblingNoLongerInTheUnitCountsForNothing(array $enrolments): void
    {
        $this->seedMockupDataSet();
        $this->declareSiblings(416, [$this->sibling($enrolments)]);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame([417, 409, 418, 416, 419], array_slice(array_keys($result), 0, 5));
        $this->assertSame(Recommendation::KIND_RANK, $result[416]->kind);
        $this->assertSame(
            'Oui — 4e demande classée sur 5 pour Louveteaux · 1re année. Avant elle, 9 places sur 15 seraient '
            . 'occupées (60 %), dans la limite du « Oui » (70 %).',
            $result[416]->explanation
        );
    }

    /**
     * In « Classement » too: a sibling who has left favours nothing, and is
     * not said to.
     */
    public function testASiblingWhoHasLeftDoesNotFavourTheRequestInTheRanking(): void
    {
        $this->seedMockupDataSet();
        $this->declareSiblings(416, [$this->sibling(['past' => true, 'public' => false])]);
        $this->saveOrder(['always' => [], 'rank' => ['sibling', 'previous_no_room', 'arrival'], 'off' => []]);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame([417, 409, 418, 416, 419], array_slice(array_keys($result), 0, 5));
        $this->assertStringNotContainsString('fratrie', $result[416]->explanation);
    }

    /**
     * Several siblings declared, one of whom has left: the one still in the
     * unit is enough.
     */
    public function testOneSiblingStillInTheUnitIsEnoughAmongSeveral(): void
    {
        $this->seedMockupDataSet();
        $this->declareSiblings(416, [
            $this->sibling(['past' => true, 'public' => false]),
            $this->sibling(['public' => true]),
        ]);

        $recommendation = $this->service->forYear($this->targetYearId)[416];

        $this->assertSame(Recommendation::KIND_ALWAYS, $recommendation->kind);
        $this->assertSame(
            "Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité.",
            $recommendation->explanation
        );
    }

    /**
     * Only « Toujours Oui » may take a slot past its capacity: with 5 places
     * and 6 already taken, the sibling is still Oui — warned — and every
     * ranked request is Non.
     */
    public function testOnlyToujoursOuiExceedsTheCapacity(): void
    {
        $this->seedMockupDataSet();
        $this->capacities->upsert($this->louveteauxId, 1, 5);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame(SlotMath::LEVEL_YES, $result[416]->level);
        $this->assertTrue($result[416]->exceedsCapacity());
        $this->assertSame(
            "Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité. Attention : Louveteaux · 1re année "
            . 'est déjà complète, cette acceptation dépasse la capacité.',
            $result[416]->explanation
        );
        foreach ([417, 409, 418, 419] as $id) {
            $this->assertSame(SlotMath::LEVEL_NO, $result[$id]->level, "#{$id}");
            $this->assertStringStartsWith(
                'Non — Louveteaux · 1re année serait complète avant elle.',
                $result[$id]->explanation
            );
        }
    }

    /**
     * At exactly the capacity the inclusive « Peut-être jusqu'à 100 % » still
     * answers Peut-être — and the explanation says accepting exceeds the
     * capacity; one place further it is Non.
     */
    public function testTheLastPlaceIsPeutEtreAndSaysItExceedsTheCapacity(): void
    {
        $this->seedMockupDataSet();
        $this->capacities->upsert($this->louveteauxId, 1, 7);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame(SlotMath::LEVEL_YES, $result[416]->level);
        $this->assertFalse($result[416]->exceedsCapacity());
        $this->assertSame(SlotMath::LEVEL_MAYBE, $result[417]->level);
        $this->assertSame(
            'Peut-être — 1re demande classée sur 4 pour Louveteaux · 1re année. Avant elle, 7 places sur 7 seraient '
            . 'occupées (100 %), au-delà de la limite du « Oui » (70 %). Accepter cette demande dépasse la capacité. '
            . 'Classement favorisé par : ancienne demande restée sans place (2025-2026).',
            $result[417]->explanation
        );
        $this->assertSame(SlotMath::LEVEL_NO, $result[409]->level);
    }

    /**
     * Beyond 100 %, « Peut-être » reaches past the capacity, and Non says
     * which limit it crossed.
     */
    public function testAPeutEtreThresholdBeyondOneHundredPercent(): void
    {
        $this->seedMockupDataSet();
        $this->capacities->upsert($this->louveteauxId, 1, 7);
        $this->settingService->set('registration_threshold_maybe_percent', '110', 'registration');

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame(SlotMath::LEVEL_MAYBE, $result[417]->level);
        $this->assertSame(
            'Non — 2e demande classée sur 4 pour Louveteaux · 1re année. Avant elle, 8 places sur 7 seraient '
            . 'occupées (114 %), au-delà de la limite du « Peut-être » (110 %).',
            $result[409]->explanation
        );
    }

    public function testAClosedSlotIsNonForRankedRequestsAndWarnsForToujoursOui(): void
    {
        $this->seedMockupDataSet();
        $this->capacities->upsert($this->louveteauxId, 1, 0);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame(
            "Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité. Attention : Louveteaux · 1re année "
            . 'est fermée aux inscriptions (capacité 0), cette acceptation dépasse la capacité.',
            $result[416]->explanation
        );
        $this->assertSame(SlotMath::LEVEL_NO, $result[417]->level);
        $this->assertSame(
            'Non — Louveteaux · 1re année est fermée aux inscriptions (capacité 0).',
            $result[417]->explanation
        );
    }

    public function testASlotWithoutLimitIsAlwaysOui(): void
    {
        $this->seedMockupDataSet();
        $this->capacities->upsert($this->louveteauxId, 1, null);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame(SlotMath::LEVEL_YES, $result[419]->level);
        $this->assertFalse($result[419]->exceedsCapacity());
        $this->assertSame("Oui — aucune limite n'est fixée pour Louveteaux · 1re année.", $result[419]->explanation);
    }

    /**
     * « 1 place … serait occupée », « aucune place … ne serait occupée ».
     */
    public function testThePlacesBeforeAgreeInNumber(): void
    {
        $this->file($this->targetYearId, 'Zoé', 'Un', self::LOU1_BIRTH, '2026-10-01 10:00:00');
        $this->file($this->targetYearId, 'Zoé', 'Deux', self::LOU1_BIRTH, '2026-10-02 10:00:00');

        $explanations = array_map(
            static fn(Recommendation $r): string => $r->explanation,
            array_values($this->service->forYear($this->targetYearId))
        );

        $this->assertSame(
            [
                'Oui — 1re demande classée sur 2 pour Louveteaux · 1re année. Avant elle, aucune place sur 15 ne '
                . 'serait occupée (0 %), dans la limite du « Oui » (70 %).',
                'Oui — 2e demande classée sur 2 pour Louveteaux · 1re année. Avant elle, 1 place sur 15 serait '
                . 'occupée (7 %), dans la limite du « Oui » (70 %).',
            ],
            $explanations
        );
    }

    /**
     * A duplicate is judged against the child's oldest LIVE request: an
     * accepted one counts (the pending one is left out, pointing at it), a
     * withdrawn one does not.
     */
    public function testADuplicateIsJudgedAgainstTheOldestLiveRequest(): void
    {
        $accepted = $this->file($this->targetYearId, 'Mila', 'Dumont', self::LOU1_BIRTH, '2026-09-01 10:00:00');
        $this->requests->updateStatus($accepted, 'accepted', null);
        $again = $this->file($this->targetYearId, 'Mila', 'Dumont', self::LOU1_BIRTH, '2026-10-01 10:00:00');

        $withdrawn = $this->file($this->targetYearId, 'Tom', 'Gérard', self::LOU1_BIRTH, '2026-09-01 10:00:00');
        $this->requests->updateStatus($withdrawn, 'withdrawn', new \DateTimeImmutable());
        $refiled = $this->file($this->targetYearId, 'Tom', 'Gérard', self::LOU1_BIRTH, '2026-10-02 10:00:00');

        $result = $this->service->forYear($this->targetYearId);

        $this->assertSame($accepted, $result[$again]->duplicateOfRequestId);
        $this->assertSame(
            "Pas de recommandation tant que le doublon possible de la demande #{$accepted} n'est pas réglé.",
            $result[$again]->explanation
        );
        $this->assertTrue($result[$refiled]->isQueued());
        $this->assertNull($result[$refiled]->duplicateOfRequestId);
    }

    /**
     * A child already holding a place keeps it as the request that stays,
     * whatever the filing order: an OLDER pending request of a child whose
     * later request was accepted is the duplicate, never a second place.
     */
    public function testAPendingRequestOfAChildAlreadyAcceptedIsTheDuplicateEvenWhenOlder(): void
    {
        $older = $this->file($this->targetYearId, 'Nina', 'Lenoir', self::LOU1_BIRTH, '2026-09-01 10:00:00');
        $accepted = $this->file($this->targetYearId, 'Nina', 'Lenoir', self::LOU1_BIRTH, '2026-10-01 10:00:00');
        $this->requests->updateStatus($accepted, 'accepted', null);

        $result = $this->service->forYear($this->targetYearId);

        $this->assertFalse($result[$older]->isQueued());
        $this->assertSame($accepted, $result[$older]->duplicateOfRequestId);
    }

    /**
     * The percentage never reads as within the threshold the explanation
     * says it is beyond: 19 places of 27 are 70.37 %, which rounds to
     * 70 — it is given to the tenth above instead.
     */
    public function testThePercentageNeverContradictsTheThresholdItIsBeyond(): void
    {
        $this->capacities->upsert($this->louveteauxId, 1, 27);
        $this->accepted(19, self::LOU1_BIRTH);
        $id = $this->file($this->targetYearId, 'Jules', 'Seuil', self::LOU1_BIRTH, '2026-10-01 10:00:00');

        $this->assertSame(
            'Peut-être — 1re demande classée sur 1 pour Louveteaux · 1re année. Avant elle, 19 places sur 27 '
            . 'seraient occupées (70,4 %), au-delà de la limite du « Oui » (70 %).',
            $this->service->forYear($this->targetYearId)[$id]->explanation
        );
    }

    /**
     * The same child is the same child only within one year: last year's
     * request is no duplicate, and only a refusal « Pas de place » of an
     * earlier year favours it — not « Autre », not one without a reason.
     */
    public function testOnlyAnEarlierRefusalForLackOfRoomCounts(): void
    {
        $other = $this->file($this->pastYearId, 'Léo', 'Autre', self::LOU1_BIRTH, '2025-10-01 10:00:00');
        $this->requests->updateStatus($other, 'refused', new \DateTimeImmutable(), RegistrationRequest::REFUSAL_OTHER);
        $none = $this->file($this->pastYearId, 'Léa', 'Sans', self::LOU1_BIRTH, '2025-10-01 10:00:00');
        $this->requests->updateStatus($none, 'refused', new \DateTimeImmutable());
        $sameYear = $this->file($this->targetYearId, 'Max', 'Même', self::LOU1_BIRTH, '2026-09-01 10:00:00');
        $this->requests->updateStatus($sameYear, 'refused', new \DateTimeImmutable(), self::NO_ROOM);

        $ids = [
            $this->file($this->targetYearId, 'Léo', 'Autre', self::LOU1_BIRTH, '2026-10-01 10:00:00'),
            $this->file($this->targetYearId, 'Léa', 'Sans', self::LOU1_BIRTH, '2026-10-02 10:00:00'),
            $this->file($this->targetYearId, 'Max', 'Même', self::LOU1_BIRTH, '2026-10-03 10:00:00'),
        ];

        $result = $this->service->forYear($this->targetYearId);

        foreach ($ids as $id) {
            $this->assertTrue($result[$id]->isQueued());
            $this->assertStringNotContainsString('Classement favorisé', $result[$id]->explanation);
        }
        $this->assertSame($ids, array_keys($result));
    }

    /**
     * Requests filed in the same second keep one order — the id's — and
     * two computations give the same answer.
     */
    public function testTheResultIsDeterministic(): void
    {
        $this->seedMockupDataSet();
        $tied = [
            $this->file($this->targetYearId, 'Égal', 'Un', self::LOU1_BIRTH, '2026-10-10 10:00:00'),
            $this->file($this->targetYearId, 'Égal', 'Deux', self::LOU1_BIRTH, '2026-10-10 10:00:00'),
        ];

        $first = $this->service->forYear($this->targetYearId);
        $second = $this->service->forYear($this->targetYearId);

        $this->assertEquals($first, $second);
        $this->assertSame(5, $first[$tied[0]]->rank);
        $this->assertSame(6, $first[$tied[1]]->rank);
    }

    /**
     * « Gérer les listes d'attente » off: nothing is computed at all.
     */
    public function testNothingIsComputedWhileWaitlistsAreNotManaged(): void
    {
        $this->seedMockupDataSet();
        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');

        $this->assertFalse($this->service->isAvailableForYear($this->targetYearId));
        $this->assertSame([], $this->service->forYear($this->targetYearId));
        $this->assertNull($this->service->forRequest($this->requireRequest(417)));
    }

    /**
     * A past scout year is consultation only (R8): no recommendation. The
     * current one — in-year registrations with the code — gets them.
     */
    public function testAPastYearGetsNoRecommendationAndTheCurrentOneDoes(): void
    {
        $past = $this->file($this->pastYearId, 'Ancien', 'Passé', '2018-05-05', '2025-10-01 10:00:00');
        $current = $this->file($this->publicYearId, 'Courant', 'Présent', '2018-05-05', '2026-10-01 10:00:00');

        $this->assertFalse($this->service->isAvailableForYear($this->pastYearId));
        $this->assertSame([], $this->service->forYear($this->pastYearId));
        $this->assertNull($this->service->forRequest($this->requireRequest($past)));

        $this->assertTrue($this->service->isAvailableForYear($this->publicYearId));
        $recommendation = $this->service->forRequest($this->requireRequest($current));
        $this->assertNotNull($recommendation);
        $this->assertSame('Louveteaux · 1re année', $recommendation->slotLabel);
    }

    /**
     * A request's « Créneau » (IT-11): the slot as an explanation names it,
     * or « Hors des tranches d'âge » — outside every bracket, or in a
     * branch the brackets no longer carry.
     */
    public function testARequestsSlotIsNamedAsTheMockUpNamesIt(): void
    {
        $brackets = [new \Modules\Registration\Repository\AgeBracket(7, 'Louveteaux', 20, 8, 4)];

        $this->assertSame(
            'Louveteaux · 1re année',
            RecommendationService::requestSlotLabel($brackets, ['age_branch_id' => 7, 'year_in_branch' => 1])
        );
        $this->assertSame(
            'Louveteaux · 3e année',
            RecommendationService::requestSlotLabel($brackets, ['age_branch_id' => 7, 'year_in_branch' => 3])
        );
        $this->assertSame("Hors des tranches d'âge", RecommendationService::requestSlotLabel($brackets, null));
        $this->assertSame(
            "Hors des tranches d'âge",
            RecommendationService::requestSlotLabel($brackets, ['age_branch_id' => 99, 'year_in_branch' => 1])
        );
    }

    /**
     * Only a pending request has a recommendation.
     */
    public function testOnlyAPendingRequestHasARecommendation(): void
    {
        $this->seedMockupDataSet();
        $this->requests->updateStatus(418, 'accepted', null);

        $this->assertNull($this->service->forRequest($this->requireRequest(418)));
        $this->assertArrayNotHasKey(418, $this->service->forYear($this->targetYearId));
        // And once accepted it occupies a place for those behind it.
        $this->assertSame(10, $this->service->forYear($this->targetYearId)[419]->occupiedBefore);
    }

    /**
     * An order stored in a shape this version cannot read is computed with
     * the defaults, never half-applied.
     */
    public function testAnUnreadableStoredOrderFallsBackToTheDefaults(): void
    {
        $this->seedMockupDataSet();
        $this->settingService->setInternal('registration_priority_order', '{"always":["arrival"]}', 'registration');

        $this->assertSame(
            "Oui — règle « Toujours Oui » : fratrie déjà présente dans l'unité.",
            $this->service->forYear($this->targetYearId)[416]->explanation
        );
    }

    /**
     * @param array<string, array<int, string>> $zones
     */
    private function saveOrder(array $zones): void
    {
        $order = PriorityOrder::fromZones($zones);
        $this->assertNotNull($order);
        (new PriorityOrderService($this->settingService))->save($order);
    }

    private function requireRequest(int $id): RegistrationRequest
    {
        $request = $this->requests->findById($id);
        $this->assertNotNull($request);

        return $request;
    }

    /**
     * $count accepted requests of the target year — places already taken.
     * One is filed through the repository; the others are copies of its
     * row, which saves a password hash per place (a quarter of a second
     * each) and changes nothing the occupancy reads.
     */
    private function accepted(int $count, string $birthDate): void
    {
        $id = $this->file($this->targetYearId, 'Pris', 'Accepté' . $birthDate, $birthDate, '2026-09-15 10:00:00');
        $this->requests->updateStatus($id, 'accepted', null);

        $columns = array_values(array_filter(
            array_column($this->pdo->query('PRAGMA table_info(registration_requests)')->fetchAll(), 'name'),
            static fn(string $column): bool => $column !== 'id'
        ));
        $list = implode(', ', $columns);
        $copy = $this->pdo->prepare("INSERT INTO registration_requests ({$list}) SELECT {$list} "
            . 'FROM registration_requests WHERE id = ?');
        for ($i = 1; $i < $count; $i++) {
            $copy->execute([$id]);
        }
    }

    /**
     * Files one request, filed at $receivedAt, renumbered to $id when given
     * (the mock-up's numbers), with a declared sibling when asked — one
     * still in the unit, enrolled in the current scout year.
     */
    private function file(
        int $yearId,
        string $firstName,
        string $lastName,
        string $birthDate,
        string $receivedAt,
        ?int $id = null,
        bool $sibling = false
    ): int {
        $siblings = $sibling ? [$this->sibling(['public' => true])] : [];
        $created = $this->requests->create($yearId, [
            'parent_name' => 'Parent ' . $lastName, 'child_last_name' => $lastName, 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, $siblings);
        $createdId = $created['id'];

        $this->pdo->prepare('UPDATE registration_requests SET received_at = ? WHERE id = ?')
            ->execute([$receivedAt, $createdId]);
        if ($id !== null) {
            $this->pdo->prepare('UPDATE registration_requests SET id = ? WHERE id = ?')->execute([$id, $createdId]);
            $this->pdo->prepare('UPDATE registration_request_siblings SET registration_request_id = ? '
                . 'WHERE registration_request_id = ?')->execute([$id, $createdId]);

            return $id;
        }

        return $createdId;
    }

    /**
     * A member who can be declared as a sibling, with one member_years row
     * per scout year named — active or not. No function in a section, so
     * the projection never places them in a slot: only « Fratrie déjà dans
     * l'unité » reads these rows.
     *
     * @param array<string, bool> $enrolments 'past' | 'public' | 'target' => is_active
     */
    private function sibling(array $enrolments): int
    {
        $years = ['past' => $this->pastYearId, 'public' => $this->publicYearId, 'target' => $this->targetYearId];
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'D' . (++$this->memberSeq));
        $insert = $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, '
            . 'last_name_encrypted, is_active) VALUES (?, ?, ?, ?, ?)');
        foreach ($enrolments as $year => $active) {
            $insert->execute([
                $memberId,
                $years[$year],
                $this->encryption->encrypt('Jules', 'member_years.first_name'),
                $this->encryption->encrypt('Vidal', 'member_years.last_name'),
                $active ? 1 : 0,
            ]);
        }

        return $memberId;
    }

    /**
     * Replaces the siblings declared on request $requestId by $memberIds.
     *
     * @param array<int> $memberIds
     */
    private function declareSiblings(int $requestId, array $memberIds): void
    {
        $this->pdo->prepare('DELETE FROM registration_request_siblings WHERE registration_request_id = ?')
            ->execute([$requestId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO registration_request_siblings (registration_request_id, member_id) VALUES (?, ?)'
        );
        foreach ($memberIds as $memberId) {
            $insert->execute([$requestId, $memberId]);
        }
    }
}
