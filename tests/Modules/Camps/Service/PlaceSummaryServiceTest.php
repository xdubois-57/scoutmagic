<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\PlaceSummaryService;
use Modules\Camps\Service\ReviewService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class PlaceSummaryServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PlaceRepository $places;
    private CampRepository $camps;
    private ReviewRepository $reviews;
    private int $placeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository($this->pdo, $this->encryption);
        $this->reviews = new ReviewRepository($this->pdo);
        $this->placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
    }

    // ── What the model may see ──────────────────────────────────────

    public function testTheMaterialCarriesStaysPricesRatingsAndComments(): void
    {
        $camp = $this->stay('2024-07-19', 210000, 61);
        $this->reviews->save($camp, 5, 'Propriétaire très arrangeant.', null);

        $material = $this->service()->material($this->place());

        $this->assertStringContainsString('Domaine de Mozet', (string) $material);
        $this->assertStringContainsString('2 100,00 €', (string) $material);
        $this->assertStringContainsString('61 participants', (string) $material);
        $this->assertStringContainsString('note 5/5', (string) $material);
        $this->assertStringContainsString('Propriétaire très arrangeant.', (string) $material);
    }

    public function testContactsNeverReachTheModel(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 4, 'Bon terrain.', null);
        (new ContactRepository($this->pdo, $this->encryption))
            ->create($camp, 'Mme Lambert', 'Propriétaire', 'lambert@example.org', '+32 81 58 00 00', 'GSM du fils');

        $material = (string) $this->service()->material($this->place());

        // Their names and numbers have no business leaving this database
        // so a model can add an adjective.
        $this->assertStringNotContainsString('Lambert', $material);
        $this->assertStringNotContainsString('lambert@example.org', $material);
        $this->assertStringNotContainsString('81 58', $material);
    }

    public function testAPlaceWithNothingToSayProducesNoMaterial(): void
    {
        $this->stay('2024-07-19', null, null);

        // One undated, priceless, unreviewed stay sums up to nothing, and
        // a summary saying so is worse than no summary.
        $this->assertNull($this->service()->material($this->place()));
    }

    public function testAPlaceWithNoStayAtAllProducesNoMaterial(): void
    {
        $this->assertNull($this->service()->material($this->place()));
    }

    // ── Writing, and not writing ────────────────────────────────────

    public function testAGeneratedSummaryIsStoredAndDated(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 5, 'Excellent.', null);
        $llm = $this->availableLlm('Lieu apprécié, prix stable, accès étroit pour les camions.');

        $this->assertTrue($this->service($llm)->refresh($this->place()));

        $place = $this->place();
        $this->assertSame('Lieu apprécié, prix stable, accès étroit pour les camions.', $place->aiSummary);
        $this->assertNotNull($place->aiSummaryGeneratedAt);
        $this->assertFalse($place->aiSummaryIsStale);
    }

    public function testWithoutTheLlmModuleNothingIsWritten(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 5, 'Excellent.', null);

        $this->assertFalse($this->service()->refresh($this->place()));
        $this->assertNull($this->place()->aiSummary);
    }

    public function testAFailingModelKeepsYesterdaysSummary(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 5, 'Excellent.', null);
        $this->places->saveSummary($this->placeId, 'Un résumé écrit hier.');

        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('complete')->willThrowException(new LlmException('provider down'));

        $this->assertFalse($this->service($llm)->refresh($this->place()));
        // Yesterday's three sentences beat today's blank.
        $this->assertSame('Un résumé écrit hier.', $this->place()->aiSummary);
    }

    public function testAnEmptyAnswerIsNotStored(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 5, 'Excellent.', null);
        $this->places->saveSummary($this->placeId, 'Un résumé écrit hier.');

        $this->assertFalse($this->service($this->availableLlm('   '))->refresh($this->place()));
        $this->assertSame('Un résumé écrit hier.', $this->place()->aiSummary);
    }

    public function testAPlaceThatLostItsMaterialLosesItsSummaryToo(): void
    {
        $this->places->saveSummary($this->placeId, 'Un vieux résumé.');
        $this->stay('2024-07-19', null, null);

        $this->assertFalse($this->service($this->availableLlm('…'))->refresh($this->place()));
        $this->assertNull($this->place()->aiSummary);
    }

    // ── Staleness ───────────────────────────────────────────────────

    public function testAReviewMakesThePlacesSummaryStale(): void
    {
        $campId = $this->stay('2024-07-19', 210000);
        $this->places->saveSummary($this->placeId, 'Un résumé.');
        $this->assertFalse($this->place()->aiSummaryIsStale);

        $camp = $this->camps->findById($campId);
        $this->assertNotNull($camp);
        (new ReviewService(
            $this->reviews,
            new AuditService(new AuditRepository($this->pdo, $this->encryption)),
            $this->places
        ))->save($camp, ['rating' => '2'], null, 42, new \DateTimeImmutable('2026-08-24'));

        // A review is the single most summary-changing thing about a
        // place.
        $this->assertTrue($this->place()->aiSummaryIsStale);
    }

    public function testOnlyStalePlacesAreQueuedForRegeneration(): void
    {
        $fresh = $this->places->create('Frais', null, null, 'X', null, null);
        $this->places->saveSummary($fresh, 'À jour.');
        $this->places->markSummaryStale($this->placeId);

        $queued = array_map(static fn($p): int => $p->id, $this->places->findStaleSummaries(10));

        $this->assertSame([$this->placeId], $queued);
    }

    public function testTheQueueIsCappedSoOneImportIsNotTwentyModelCalls(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->places->markSummaryStale($this->places->create('P' . $i, null, null, 'X', null, null));
        }

        $this->assertCount(2, $this->places->findStaleSummaries(2));
    }

    public function testAnArchivedPlaceIsNeverRegenerated(): void
    {
        $this->places->markSummaryStale($this->placeId);
        $this->pdo->exec('UPDATE camp_places SET is_archived = 1');

        $this->assertSame([], $this->places->findStaleSummaries(10));
    }

    private function service(?LlmConnectorInterface $llm = null): PlaceSummaryService
    {
        return new PlaceSummaryService($this->places, $this->camps, $this->reviews, $llm);
    }

    private function availableLlm(string $content): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('complete')->willReturn(new LlmResponse($content, null, 100, 40));

        return $llm;
    }

    private function stay(string $endDate, ?int $priceCents, ?int $participants = null): int
    {
        return $this->camps->create(
            $this->placeId, Camp::STAY_GRAND_CAMP, $endDate, $endDate, null,
            Camp::STATUS_CONFIRMED, $priceCents, $participants, null, null, []
        );
    }

    private function place(): \Modules\Camps\Repository\Place
    {
        $place = $this->places->findById($this->placeId);
        $this->assertNotNull($place);

        return $place;
    }
}
