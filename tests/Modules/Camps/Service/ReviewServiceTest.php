<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\ReviewService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class ReviewServiceTest extends TestCase
{
    private const TODAY = '2026-08-24';

    private \PDO $pdo;
    private CampRepository $camps;
    private ReviewRepository $reviews;
    private AuditService $audit;
    private ReviewService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->reviews = new ReviewRepository($this->pdo);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->service = new ReviewService($this->reviews, $this->audit);
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
    }

    // ── When a review may be written ────────────────────────────────

    public function testAStayStillToComeCannotBeReviewedYet(): void
    {
        $camp = $this->camp('2028-07-19');

        $this->assertFalse($this->service->isOpen($camp, $this->today()));
        $this->expectException(CampsException::class);
        $this->service->save($camp, ['rating' => '4'], null, 42, $this->today());
    }

    public function testAPastStayCanBeReviewed(): void
    {
        $camp = $this->camp('2026-07-19');

        $this->service->save($camp, ['rating' => '4', 'comment' => 'Très bon terrain.'], null, 42, $this->today());

        $review = $this->reviews->findByCamp($camp->id);
        $this->assertSame(4, $review?->rating);
        $this->assertSame('Très bon terrain.', $review->comment);
    }

    public function testAStayStillToConfirmCanBeReviewedOnceItsDatesArePast(): void
    {
        // Nobody updated the status before leaving; the camp still
        // happened, and the review is what a future staff needs.
        $camp = $this->camp('2026-07-19', Camp::STATUS_TO_CONFIRM);

        $this->service->save($camp, ['rating' => '5'], null, 42, $this->today());

        $this->assertSame(5, $this->reviews->findByCamp($camp->id)?->rating);
    }

    public function testAYearOnlyStayOpensOnceItsYearIsOver(): void
    {
        $thisYear = $this->camp(null, Camp::STATUS_CONFIRMED, 2026);
        $lastYear = $this->camp(null, Camp::STATUS_CONFIRMED, 2024);

        $this->assertFalse($this->service->isOpen($thisYear, $this->today()));
        $this->assertTrue($this->service->isOpen($lastYear, $this->today()));
    }

    // ── The cancelled-stay rule ─────────────────────────────────────

    public function testACancelledStayCanBeReviewed(): void
    {
        // A place that cancelled three weeks before departure is exactly
        // what a future staff needs to know.
        $camp = $this->camp('2026-07-19', Camp::STATUS_CANCELLED);

        $this->service->save(
            $camp,
            ['comment' => 'Annulé par le propriétaire six semaines avant le départ.'],
            null,
            42,
            $this->today()
        );

        $this->assertNotNull($this->reviews->findByCamp($camp->id));
    }

    public function testACancelledStayIsRefusedARatingServerSide(): void
    {
        $camp = $this->camp('2026-07-19', Camp::STATUS_CANCELLED);

        $this->assertFalse($this->service->allowsRating($camp));
        // Not just hidden in the form: a form is a suggestion, a service
        // is the answer.
        $this->expectException(CampsException::class);
        $this->service->save($camp, ['rating' => '1', 'comment' => 'x'], null, 42, $this->today());
    }

    public function testACancelledStayNeverFeedsThePlacesDisplayedRating(): void
    {
        $good = $this->camp('2024-07-19');
        $this->service->save($good, ['rating' => '5'], null, 42, $this->today());

        // Written straight to the repository, bypassing the service, to
        // prove the exclusion is enforced on the READ side too — a row
        // that got there some other way must still not count.
        $cancelled = $this->camp('2026-07-19', Camp::STATUS_CANCELLED);
        $this->reviews->save($cancelled->id, 1, 'Annulé', null);

        $latest = $this->reviews->latestRatingForPlace(1);
        $this->assertSame(5, $latest['rating']);
        $this->assertSame(2024, $latest['year']);
    }

    // ── An empty review is not a review ─────────────────────────────

    public function testAReviewWithNeitherANoteNorACommentIsRefused(): void
    {
        $camp = $this->camp('2026-07-19');

        $this->expectException(CampsException::class);
        $this->service->save($camp, ['rating' => '', 'comment' => '   '], null, 42, $this->today());
    }

    /**
     * @dataProvider badRatings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRatings')]
    public function testARatingOutsideOneToFiveIsRefused(string $value): void
    {
        $camp = $this->camp('2026-07-19');

        $this->expectException(CampsException::class);
        $this->service->save($camp, ['rating' => $value], null, 42, $this->today());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badRatings(): array
    {
        return ['zero' => ['0'], 'six' => ['6'], 'negative' => ['-1'], 'words' => ['excellent']];
    }

    // ── One review per camp, and the history it writes ──────────────

    public function testSavingTwiceReplacesTheReviewRatherThanAddingOne(): void
    {
        $camp = $this->camp('2026-07-19');

        $this->service->save($camp, ['rating' => '3'], null, 42, $this->today());
        $this->service->save($camp, ['rating' => '5', 'comment' => 'Revu à la hausse.'], null, 42, $this->today());

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_reviews')->fetchColumn());
        $this->assertSame(5, $this->reviews->findByCamp($camp->id)?->rating);
    }

    public function testTheHistoryRecordsTheRatingButNeverTheComment(): void
    {
        $camp = $this->camp('2026-07-19');

        $this->service->save(
            $camp,
            ['rating' => '4', 'comment' => 'L\'accès camion est étroit, prévoir une remorque plus petite.'],
            null,
            42,
            $this->today()
        );

        $entry = $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, 10)->entries[0];
        $this->assertSame('Avis ajouté', $entry->summary);
        $this->assertSame('4/5, commentaire', $entry->toValue);
        // A timeline is not a diff viewer: two paragraphs per edit would
        // bury every other change on it.
        $this->assertStringNotContainsString('remorque', (string) $entry->toValue);
    }

    public function testEditingAReviewRecordsWhatItWas(): void
    {
        $camp = $this->camp('2026-07-19');
        $this->service->save($camp, ['rating' => '2'], null, 42, $this->today());
        $this->service->save($camp, ['rating' => '5'], null, 42, $this->today());

        $entry = $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, 10)->entries[0];
        $this->assertSame('Avis modifié', $entry->summary);
        $this->assertSame('2/5', $entry->fromValue);
        $this->assertSame('5/5', $entry->toValue);
    }

    // ── The place's displayed rating ────────────────────────────────

    public function testAPlaceShowsItsMostRecentRatingNeverAnAverage(): void
    {
        $this->service->save($this->camp('2020-07-19'), ['rating' => '1'], null, 42, $this->today());
        $this->service->save($this->camp('2024-07-19'), ['rating' => '5'], null, 42, $this->today());

        // An average would be 3 and would hide that the place has since
        // been excellent — and would say nothing about when.
        $latest = $this->reviews->latestRatingForPlace(1);
        $this->assertSame(5, $latest['rating']);
        $this->assertSame(2024, $latest['year']);
    }

    public function testAMoreRecentStayWithoutARatingDoesNotHideTheOlderOne(): void
    {
        $this->service->save($this->camp('2022-07-19'), ['rating' => '4'], null, 42, $this->today());
        $this->service->save($this->camp('2025-07-19'), ['comment' => 'Rien à signaler.'], null, 42, $this->today());

        // "The most recent stay that actually HAS one" — a comment-only
        // review must not blank the place's rating.
        $this->assertSame(4, $this->reviews->latestRatingForPlace(1)['rating']);
        $this->assertSame(2022, $this->reviews->latestRatingForPlace(1)['year']);
    }

    public function testAPlaceWithNoRatedStayHasNoRating(): void
    {
        $this->camp('2024-07-19');

        $this->assertNull($this->reviews->latestRatingForPlace(1));
    }

    private function camp(?string $endDate, string $status = Camp::STATUS_CONFIRMED, ?int $yearOnly = null): Camp
    {
        $id = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, $endDate, $endDate, $yearOnly, $status,
            null, null, null, null, []
        );
        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);

        return $camp;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }
}
