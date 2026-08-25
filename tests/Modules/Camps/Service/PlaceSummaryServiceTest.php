<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\PlaceSummaryService;
use Modules\Camps\Service\SectionDescriber;
use Modules\Camps\Service\SummaryOutcome;
use Modules\Camps\Service\ReviewService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;
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

    /**
     * The reported bug: a chief wrote three paragraphs about a field in
     * the stay's NOTE — the box whose own help text says "tout ce qu'un
     * staff suivant devrait savoir" — and the generated summary said the
     * place had no qualitative review. It did not: `material()` never read
     * the note, although both this service's docblock and the setting
     * shown to the administrator listed notes among what goes in.
     *
     * The note is the most valuable input here. A place sheet without it
     * summarises the paperwork of a camp and none of the camping.
     */
    public function testTheStaysOwnNoteReachesTheModel(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->writeNote($camp, '<p>Le camp était super. La cuisine était petite mais bien.</p>'
            . '<p>Par contre il n\'y avait pas d\'endroit pour faire un tabou pour les chefs.</p>'
            . '<p>Les animés ont eu un peu froid la nuit, penser à demander de prendre des '
            . 'couvertures chaudes.</p>');

        $material = (string) $this->service()->material($this->place());

        $this->assertStringContainsString('La cuisine était petite mais bien', $material);
        $this->assertStringContainsString('pas d\'endroit pour faire un tabou', $material);
        $this->assertStringContainsString('couvertures chaudes', $material);
        // Plain text: markup is not information, and tokens cost money.
        $this->assertStringNotContainsString('<p>', $material);
    }

    /**
     * Two paragraphs run together read as one sentence to a model —
     * "bien.Par contre" — and that is what stripping the tags naively
     * produced on the very note that was reported.
     */
    public function testParagraphsDoNotRunIntoEachOtherOnceTheMarkupIsGone(): void
    {
        $camp = $this->stay('2024-07-19', null);
        $this->writeNote($camp, '<p>La cuisine était petite mais bien.</p><p>Par contre il n\'y avait pas '
            . 'd\'endroit pour un tabou.</p>');

        $material = (string) $this->service()->material($this->place());

        $this->assertStringContainsString('bien. Par contre', $material);
        $this->assertStringNotContainsString('bien.Par contre', $material);
    }

    /**
     * A note alone is a place worth summarising. It used to take a review
     * or a price, so a stay documented entirely in its note summed up to
     * "rien à résumer".
     */
    public function testANoteAloneIsAlreadySomethingToSummarise(): void
    {
        $camp = $this->stay('2024-07-19', null);
        $this->writeNote($camp, '<p>Terrain en pente, prévoir des sardines longues.</p>');

        $this->assertNotNull($this->service()->material($this->place()));
    }

    public function testWhichSectionsWentReachesTheModelByName(): void
    {
        $this->pdo->exec(
            "INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO sections (id, name, desk_code, age_branch_id, is_active) VALUES (7, 'La Meute', 'MEU', 1, 1)"
        );
        $sectionId = 7;
        $camp = $this->camps->create(
            $this->placeId, Camp::STAY_GRAND_CAMP, '2024-07-12', '2024-07-19', null,
            Camp::STATUS_CONFIRMED, 210000, 61, null, null, [$sectionId]
        );
        $this->reviews->save($camp, 4, 'Bon terrain.', null);

        $material = (string) $this->service()->material($this->place());

        // A field that suits Baladins is not the field that suits
        // Pionniers, and the summary is read to choose one.
        $this->assertStringContainsString($this->sectionName($sectionId), $material);
    }

    /**
     * A note has no natural size — a chief can write four screens — and a
     * place can hold twenty stays. Both are bounded, and the place keeps
     * its most RECENT stays: a place is what it was like last time.
     */
    public function testAVeryLongNoteIsBoundedRatherThanSentWhole(): void
    {
        $camp = $this->stay('2024-07-19', null);
        $this->writeNote($camp, '<p>' . str_repeat('Terrain en pente. ', 400) . '</p>');

        $material = (string) $this->service()->material($this->place());

        $this->assertLessThan(2000, mb_strlen($material));
        $this->assertStringEndsWith('…', $material);
    }

    public function testAnOldStayIsDroppedBeforeTheMaterialGrowsUnbounded(): void
    {
        for ($year = 2005; $year <= 2024; $year++) {
            $camp = $this->stay($year . '-07-19', 210000);
            $this->writeNote($camp, '<p>' . str_repeat('Note de ' . $year . '. ', 90) . '</p>');
        }

        $material = (string) $this->service()->material($this->place());

        $this->assertLessThanOrEqual(12000, mb_strlen($material));
        // The recent ones are the ones that survive.
        $this->assertStringContainsString('Note de 2024', $material);
        $this->assertStringNotContainsString('Note de 2005', $material);
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

        $this->assertSame(SummaryOutcome::Written, $this->service($llm)->refresh($this->place()));

        $place = $this->place();
        $this->assertSame('Lieu apprécié, prix stable, accès étroit pour les camions.', $place->aiSummary);
        $this->assertNotNull($place->aiSummaryGeneratedAt);
        $this->assertFalse($place->aiSummaryIsStale);
    }

    public function testWithoutTheLlmModuleNothingIsWritten(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 5, 'Excellent.', null);

        $this->assertSame(SummaryOutcome::Unavailable, $this->service()->refresh($this->place()));
        $this->assertNull($this->place()->aiSummary);
    }

    public function testAFailingModelKeepsYesterdaysSummary(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 5, 'Excellent.', null);
        $this->places->saveSummary($this->placeId, 'Un résumé écrit hier.');

        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willThrowException(new LlmException('provider down'));

        $this->assertSame(SummaryOutcome::ModelRefused, $this->service($llm)->refresh($this->place()));
        // Yesterday's three sentences beat today's blank.
        $this->assertSame('Un résumé écrit hier.', $this->place()->aiSummary);
    }

    public function testAnEmptyAnswerIsNotStored(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 5, 'Excellent.', null);
        $this->places->saveSummary($this->placeId, 'Un résumé écrit hier.');

        $this->assertSame(SummaryOutcome::EmptyAnswer, $this->service($this->availableLlm('   '))->refresh($this->place()));
        $this->assertSame('Un résumé écrit hier.', $this->place()->aiSummary);
    }

    public function testAPlaceThatLostItsMaterialLosesItsSummaryToo(): void
    {
        $this->places->saveSummary($this->placeId, 'Un vieux résumé.');
        $this->stay('2024-07-19', null, null);

        $this->assertSame(SummaryOutcome::NothingToSummarise, $this->service($this->availableLlm('…'))->refresh($this->place()));
        $this->assertNull($this->place()->aiSummary);
    }

    /**
     * The bug this test exists for: a connector with a provider and a
     * model — but none on the tier this service asks for — answered
     * `isAvailable()` yes, so the place sheet offered « Écrire le résumé
     * maintenant », and the click came back with "il n'y a pas assez à
     * raconter" on a place that had a rating AND a comment. Nothing
     * reached the journal either, because Service\LlmConnectorService
     * refuses a missing model before it ever calls a provider.
     *
     * The material was never the problem, and the answer must not blame
     * it.
     */
    public function testAConnectorWithNoModelForThisTierIsNotOfferedAtAll(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 4, 'Terrain plat, eau au robinet.', null);

        $service = $this->service($this->llmWithNoCheapModel());

        $this->assertFalse($service->isAvailable());
        $this->assertSame(SummaryOutcome::Unavailable, $service->refresh($this->place()));
        $this->assertNull($this->place()->aiSummary);
    }

    public function testAPlaceWithARatingAndACommentHasSomethingToSummarise(): void
    {
        $camp = $this->stay('2024-07-19', null);
        $this->reviews->save($camp, 4, 'Terrain plat, eau au robinet.', null);

        // Neither of these is "pas assez à raconter", and each one alone
        // is enough.
        $this->assertNotNull($this->service()->material($this->place()));
        $this->assertSame(
            SummaryOutcome::Written,
            $this->service($this->availableLlm('Terrain apprécié.'))->refresh($this->place())
        );
    }

    public function testARatingAloneIsAlreadySomethingToSummarise(): void
    {
        $camp = $this->stay('2024-07-19', null);
        $this->reviews->save($camp, 4, null, null);

        $this->assertNotNull($this->service()->material($this->place()));
    }

    /**
     * Every outcome a chief can land on says what actually happened —
     * the whole point of the enum. "Not written" used to be one boolean
     * for five different situations, and the sentence the page showed
     * named the one cause that was almost never it.
     */
    public function testEveryOutcomeCarriesItsOwnSentence(): void
    {
        $seen = [];
        foreach (SummaryOutcome::cases() as $outcome) {
            $this->assertNotSame('', $outcome->message(), $outcome->name);
            $seen[$outcome->message()] = true;
        }

        $this->assertCount(count(SummaryOutcome::cases()), $seen);
        $this->assertTrue(SummaryOutcome::Written->wasWritten());
        $this->assertFalse(SummaryOutcome::NothingToSummarise->wasWritten());
    }

    /**
     * The reported bug, second half. The `cheap` model on the reporting
     * installation is a hybrid reasoning model, and MAX_TOKENS was sized
     * for the ANSWER: the model spent all of it thinking, returned
     * nothing with finish_reason "length", and the page said "il n'y a
     * pas assez à raconter" about a stay carrying a rating AND a comment.
     *
     * Truncation is now its own answer, and it never overwrites a good
     * summary with a fragment.
     */
    public function testAnAnswerCutOffAtTheTokenCapIsSaidSoAndStoresNothing(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 4, 'Terrain plat, eau au robinet.', null);
        $this->places->saveSummary($this->placeId, 'Un résumé écrit hier.');

        $this->assertSame(
            SummaryOutcome::AnswerCutOff,
            $this->service($this->llmCutOff())->refresh($this->place())
        );
        $this->assertSame('Un résumé écrit hier.', $this->place()->aiSummary);
    }

    public function testEvenAHalfWrittenAnswerIsRefused(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 4, 'Terrain plat.', null);
        $this->places->saveSummary($this->placeId, 'Un résumé écrit hier.');

        // Three sentences that stop in the middle of the second are not a
        // summary — and the one from yesterday was.
        $this->assertSame(
            SummaryOutcome::AnswerCutOff,
            $this->service($this->llmCutOff('Terrain plat, accueil constant. Le prix a'))->refresh($this->place())
        );
        $this->assertSame('Un résumé écrit hier.', $this->place()->aiSummary);
    }

    /**
     * The budget has to cover the model's reasoning, not just its three
     * sentences — that is the whole lesson of the bug above, and a cap
     * sized for the answer alone is what brought it about.
     */
    public function testTheTokenBudgetLeavesRoomForAModelThatThinks(): void
    {
        $camp = $this->stay('2024-07-19', 210000);
        $this->reviews->save($camp, 4, 'Terrain plat.', null);

        $asked = null;
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturnCallback(
            function (\Modules\LlmConnector\Api\LlmRequest $request) use (&$asked): LlmResponse {
                $asked = $request;

                return new LlmResponse('Terrain apprécié.', null, 100, 40);
            }
        );

        $this->service($llm)->refresh($this->place());

        $this->assertNotNull($asked);
        // The measured case: a 176-token prompt whose model produced 400
        // tokens of reasoning and no answer, against a 400 cap. Anything
        // in that neighbourhood is a cap sized for the answer again.
        $this->assertGreaterThanOrEqual(2000, (int) $asked->maxTokens);
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
        return new PlaceSummaryService(
            $this->places,
            $this->camps,
            $this->reviews,
            $this->notes(),
            new SectionDescriber(new SectionService(
                Connection::withPdo($this->pdo),
                $this->encryption,
                new MemberBadgeRepository($this->pdo)
            )),
            $llm
        );
    }

    private function notes(): EditableContentService
    {
        return new EditableContentService(new EditableContentRepository($this->pdo));
    }

    /** Writes a stay's free-text note the way the stay form does. */
    private function writeNote(int $campId, string $html): void
    {
        $this->notes()->set(CampService::noteKey($campId), $html, 'html', 1);
    }

    private function availableLlm(string $content): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturn(new LlmResponse($content, null, 100, 40));

        return $llm;
    }

    /**
     * A reasoning model that spent the whole token cap thinking: the
     * request succeeded, the journal says "LLM request completed", and
     * the answer is empty with finish_reason "length".
     */
    private function llmCutOff(string $content = ''): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturn(new LlmResponse($content, null, 120, 400, true));

        return $llm;
    }

    /**
     * A connector that IS configured — a provider, a model — but not for
     * the tier this service asks for. `complete()` refuses before it ever
     * reaches a provider, which is why nothing lands in the journal.
     */
    private function llmWithNoCheapModel(): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(false);
        $llm->method('complete')->willThrowException(LlmException::noModel(LlmTier::CHEAP));

        return $llm;
    }

    private function stay(string $endDate, ?int $priceCents, ?int $participants = null): int
    {
        return $this->camps->create(
            $this->placeId, Camp::STAY_GRAND_CAMP, $endDate, $endDate, null,
            Camp::STATUS_CONFIRMED, $priceCents, $participants, null, null, []
        );
    }

    private function sectionName(int $sectionId): string
    {
        $sections = new SectionService(
            Connection::withPdo($this->pdo),
            $this->encryption,
            new MemberBadgeRepository($this->pdo)
        );
        $found = $sections->findByIds([$sectionId]);
        $this->assertArrayHasKey($sectionId, $found);

        return (string) $found[$sectionId]['name'];
    }

    private function place(): \Modules\Camps\Repository\Place
    {
        $place = $this->places->findById($this->placeId);
        $this->assertNotNull($place);

        return $place;
    }
}
