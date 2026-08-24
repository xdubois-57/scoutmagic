<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Service\MergeService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

class MergeServiceTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private CampRepository $camps;
    private ContactRepository $contacts;
    private LinkRepository $links;
    private DocumentRepository $documents;
    private ReviewRepository $reviews;
    private EditableContentService $editableContent;
    private EncryptionService $encryption;
    private MergeService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->encryption = $encryption;

        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->contacts = new ContactRepository($this->pdo, $encryption);
        $this->links = new LinkRepository($this->pdo);
        $this->documents = new DocumentRepository($this->pdo);
        $this->reviews = new ReviewRepository($this->pdo);
        $this->editableContent = new EditableContentService(new EditableContentRepository($this->pdo));
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));

        $this->service = new MergeService(
            $this->places, $this->camps, $this->contacts, $this->links, $this->documents,
            $this->reviews, $this->editableContent, $audit,
            new CampAlbumService($audit, null),
            $this->pdo
        );
    }

    // ── Merging places ──────────────────────────────────────────────

    public function testEveryStayFollowsTheMergedPlace(): void
    {
        $from = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $to = $this->places->create('Domaine de Mozet asbl', null, null, 'Mozet', null, null);
        $this->stay($from, '2024-07-19');
        $this->stay($from, '2020-07-19');
        $this->stay($to, '2026-07-19');

        $moved = $this->service->mergePlaces($this->place($from), $this->place($to), 42);

        $this->assertSame(2, $moved);
        $this->assertSame(3, $this->camps->countByPlace($to));
        $this->assertSame(0, $this->camps->countByPlace($from));
    }

    public function testTheLosingPlaceIsArchivedNeverDeleted(): void
    {
        $from = $this->places->create('A', null, null, 'X', null, null);
        $to = $this->places->create('B', null, null, 'X', null, null);

        $this->service->mergePlaces($this->place($from), $this->place($to), 42);

        // Deleting a place would take its stays' history with it, and the
        // history is the module.
        $this->assertNotNull($this->places->findById($from));
        $this->assertTrue($this->places->findById($from)?->isArchived);
        $this->assertSame([], array_filter(
            $this->places->findAllVisible(),
            static fn($p): bool => $p->id === $from
        ));
    }

    public function testAFieldPresentOnOneSideOnlyIsKept(): void
    {
        $from = $this->places->create('A', 'Rue du Tronquoy 4', '5340', 'Mozet', null, 'https://a.be');
        $to = $this->places->create('B', null, null, 'Mozet', null, null);

        $this->service->mergePlaces($this->place($from), $this->place($to), 42);

        $merged = $this->places->findById($to);
        $this->assertSame('Rue du Tronquoy 4', $merged?->address);
        $this->assertSame('https://a.be', $merged->websiteUrl);
        // The surviving place keeps its own name — a merge is not a rename.
        $this->assertSame('B', $merged->name);
    }

    public function testAManualPinBeatsAnAutomaticOne(): void
    {
        $from = $this->places->create('A', null, null, 'Mozet', null, null);
        $to = $this->places->create('B', null, null, 'Mozet', null, null);
        $this->places->setManualCoordinates($from, 50.443210, 5.001234);
        $this->places->recordGeocoding($to, 50.0, 5.5, new \DateTimeImmutable());

        $this->service->mergePlaces($this->place($from), $this->place($to), 42);

        // Somebody dragged that pin onto the actual field; an automatic
        // guess must not win just because it was written later.
        $merged = $this->places->findById($to);
        $this->assertEqualsWithDelta(50.443210, (float) $merged?->latitude, 0.000001);
        $this->assertTrue($merged->coordinatesAreManual);
    }

    public function testAPlaceWithNoPinTakesTheOtherSidesPin(): void
    {
        $from = $this->places->create('A', null, null, 'Mozet', null, null);
        $to = $this->places->create('B', null, null, 'Mozet', null, null);
        $this->places->recordGeocoding($from, 50.44, 5.00, new \DateTimeImmutable());

        $this->service->mergePlaces($this->place($from), $this->place($to), 42);

        $this->assertTrue($this->places->findById($to)?->hasCoordinates());
        // And it stays automatic, so a later correction still counts as
        // the first human decision about this place.
        $this->assertFalse($this->places->findById($to)->coordinatesAreManual);
    }

    public function testAPlaceCannotBeMergedWithItself(): void
    {
        $id = $this->places->create('A', null, null, 'X', null, null);

        $this->expectException(CampsException::class);
        $this->service->mergePlaces($this->place($id), $this->place($id), 42);
    }

    public function testThePreviewCountsWhatWillMoveBeforeAnythingHappens(): void
    {
        $from = $this->places->create('A', 'Rue X 1', null, 'Mozet', null, null);
        $to = $this->places->create('B', null, null, 'Mozet', null, null);
        $stay = $this->stay($from, '2024-07-19');
        $this->contacts->create($stay, 'Mme Lambert', null, 'l@example.org', null, null);
        $this->links->create($stay, 'https://x.be', null, null, null, 'x.be', null);

        $preview = $this->service->placeMergePreview($this->place($from), $this->place($to));

        $this->assertSame(1, $preview['stays']);
        $this->assertSame(1, $preview['contacts']);
        $this->assertSame(1, $preview['links']);
        $this->assertContains('adresse', $preview['fields']);
    }

    // ── Merging stays ───────────────────────────────────────────────

    public function testMergingAcrossTwoPlacesIsRefused(): void
    {
        $a = $this->places->create('A', null, null, 'X', null, null);
        $b = $this->places->create('B', null, null, 'Y', null, null);
        $from = $this->camp($this->stay($a, '2024-07-19'));
        $to = $this->camp($this->stay($b, '2024-07-19'));

        // Two stays at two different fields are two stays. Merge the
        // PLACES first — which is deliberately an admin action.
        $this->expectException(CampsException::class);
        $this->service->mergeCamps($from, $to, 42, $this->today());
    }

    public function testEverythingAttachedFollowsTheSurvivingStay(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $fromId = $this->stay($place, '2024-07-19');
        $toId = $this->stay($place, '2024-07-20');
        $this->contacts->create($fromId, 'Mme Lambert', null, 'l@example.org', null, null);
        $this->links->create($fromId, 'https://x.be', null, null, null, 'x.be', null);

        $this->service->mergeCamps($this->camp($fromId), $this->camp($toId), 42, $this->today());

        $this->assertCount(1, $this->contacts->findByCamp($toId));
        $this->assertCount(1, $this->links->findByCamp($toId));
        $this->assertNull($this->camps->findById($fromId));
    }

    public function testALosingValueIsWrittenIntoTheSurvivingNote(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $fromId = $this->stay($place, '2024-07-19', 240000);
        $toId = $this->stay($place, '2024-07-19', 265000);

        $lost = $this->service->mergeCamps($this->camp($fromId), $this->camp($toId), 42, $this->today());

        // Nothing is silently dropped — which is exactly what makes this
        // merge safe to open to every chief rather than to admins only.
        $this->assertNotEmpty($lost);
        $note = (string) $this->editableContent->get(CampService::noteKey($toId), '');
        $this->assertStringContainsString('Fusionné le 24/08/2026', $note);
        $this->assertStringContainsString('2 400,00 €', $note);
    }

    public function testTheLosingStaysOwnNoteIsCarriedOverAndCleared(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $fromId = $this->stay($place, '2024-07-19');
        $toId = $this->stay($place, '2024-07-20');
        $this->editableContent->set(CampService::noteKey($fromId), '<p>Accès camion étroit.</p>', 'rich_text', 1);

        $this->service->mergeCamps($this->camp($fromId), $this->camp($toId), 42, $this->today());

        $this->assertStringContainsString(
            'Accès camion étroit.',
            (string) $this->editableContent->get(CampService::noteKey($toId), '')
        );
        $this->assertNull($this->editableContent->get(CampService::noteKey($fromId)));
    }

    public function testAnEmptyFieldTakesTheOtherStaysValue(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $fromId = $this->stay($place, '2024-07-19', 240000, 61);
        $toId = $this->stay($place, '2024-07-19', null, null);

        $this->service->mergeCamps($this->camp($fromId), $this->camp($toId), 42, $this->today());

        $merged = $this->camps->findById($toId);
        $this->assertSame(240000, $merged?->priceCents);
        $this->assertSame(61, $merged->participantCount);
    }

    public function testSectionsAreUnionedNotReplaced(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $fromId = $this->camps->create($place, Camp::STAY_GRAND_CAMP, '2024-07-12', '2024-07-19', null, Camp::STATUS_CONFIRMED, null, null, null, null, [3]);
        $toId = $this->camps->create($place, Camp::STAY_GRAND_CAMP, '2024-07-12', '2024-07-19', null, Camp::STATUS_CONFIRMED, null, null, null, null, [4]);

        $this->service->mergeCamps($this->camp($fromId), $this->camp($toId), 42, $this->today());

        $this->assertSame([3, 4], $this->camps->findById($toId)?->sectionIds);
    }

    public function testASurvivingReviewIsNeverOverwritten(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $fromId = $this->stay($place, '2024-07-19');
        $toId = $this->stay($place, '2024-07-20');
        $this->reviews->save($fromId, 2, 'Bof.', null);
        $this->reviews->save($toId, 5, 'Excellent.', null);

        $this->service->mergeCamps($this->camp($fromId), $this->camp($toId), 42, $this->today());

        // Two reviews of one field are two opinions; silently replacing
        // the surviving one would lose the thing the module exists for.
        $this->assertSame(5, $this->reviews->findByCamp($toId)?->rating);
        $this->assertSame('Excellent.', $this->reviews->findByCamp($toId)->comment);
    }

    public function testAReviewMovesIntoAStayThatHasNone(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $fromId = $this->stay($place, '2024-07-19');
        $toId = $this->stay($place, '2024-07-20');
        $this->reviews->save($fromId, 4, 'Bon terrain.', null);

        $this->service->mergeCamps($this->camp($fromId), $this->camp($toId), 42, $this->today());

        $this->assertSame(4, $this->reviews->findByCamp($toId)?->rating);
        $this->assertNull($this->reviews->findByCamp($fromId));
    }

    public function testAStayCannotBeMergedWithItself(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $id = $this->stay($place, '2024-07-19');

        $this->expectException(CampsException::class);
        $this->service->mergeCamps($this->camp($id), $this->camp($id), 42, $this->today());
    }

    // ── What a merge must refuse, and what it must invalidate ───────

    public function testMergingIntoAnArchivedPlaceIsRefused(): void
    {
        // The stays would land on a row no ordinary screen shows: they
        // would simply vanish from the module, and the chief who pressed
        // the button would have no way of guessing where they went.
        $from = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $to = $this->places->create('Domaine de Mozet asbl', null, null, 'Mozet', null, null);
        $this->stay($from, '2024-07-19');
        $this->places->archive($to, true);

        try {
            $this->service->mergePlaces($this->place($from), $this->place($to), 42);
            $this->fail('An archived target must be refused.');
        } catch (CampsException $e) {
            $this->assertStringContainsString('archivé', $e->getMessage());
        }

        $this->assertSame(1, $this->camps->countByPlace($from));
    }

    public function testAPlaceMergeMarksBothSummariesStale(): void
    {
        // No stay was created or edited, only re-parented — so nothing
        // else would ever mark the surviving place's AI summary stale, and
        // it would go on describing a shorter history than the place has.
        $from = $this->places->create('A', null, null, 'X', null, null);
        $to = $this->places->create('B', null, null, 'X', null, null);
        $this->stay($from, '2024-07-19');
        $this->pdo->exec('UPDATE camp_places SET ai_summary_is_stale = 0');

        $this->service->mergePlaces($this->place($from), $this->place($to), 42);

        $this->assertSame(
            [1, 1],
            array_map('intval', $this->pdo
                ->query('SELECT ai_summary_is_stale FROM camp_places ORDER BY id')
                ->fetchAll(\PDO::FETCH_COLUMN))
        );
    }

    public function testACampMergeMarksThePlacesSummaryStale(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $from = $this->stay($place, '2024-07-19');
        $to = $this->stay($place, '2024-07-26');
        $this->pdo->exec('UPDATE camp_places SET ai_summary_is_stale = 0');

        $this->service->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT ai_summary_is_stale FROM camp_places WHERE id = ' . $place)->fetchColumn()
        );
    }

    public function testTheYearDroppedByAMergeIsWrittenIntoTheNote(): void
    {
        // The surviving stay knows only its year; the losing one has real
        // dates. The merged stay takes the dates — so what was dropped is
        // the surviving stay's YEAR, which comparing the losing side
        // against the surviving one could never say.
        $place = $this->places->create('A', null, null, 'X', null, null);
        $from = $this->stay($place, '2024-07-19');
        $to = $this->camps->create(
            $place, Camp::STAY_GRAND_CAMP, null, null, 2024, Camp::STATUS_CONFIRMED,
            null, null, null, null, []
        );

        $lost = $this->service->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());

        $this->assertNotSame([], $lost);
        $this->assertStringContainsString('2024', implode(' ', $lost));
        // And never the dates the merged stay actually carries.
        $this->assertStringNotContainsString('dates précédentes : 19 juillet 2024', implode(' ', $lost));
    }

    public function testAValueTheMergedStayKeepsIsNotReportedAsLost(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $from = $this->stay($place, '2024-07-19', 45000);
        $to = $this->stay($place, '2024-07-19');

        $lost = $this->service->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());

        // The surviving stay had no price, so it takes the losing one's:
        // nothing was lost, and saying "prix précédent" would be a lie.
        $this->assertSame([], $lost);
    }

    public function testTheMergedStaysAlbumIsNamedLikeEveryOtherAlbumOfAStay(): void
    {
        // This is the ONE place that could create an album for a stay
        // outside the photos page. Called "Camp", it tells a reader
        // browsing the gallery nothing at all — every other album of a
        // stay is "{lieu} — {dates}".
        $place = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $from = $this->stay($place, '2024-07-19');
        $to = $this->stay($place, '2024-07-26');

        $albums = $this->createMock(\Modules\Gallery\Api\DelegatedAlbumManager::class);
        $albums->method('findAlbum')->willReturn(new \Modules\Gallery\Api\DelegatedAlbum(1, 'x', '2024-07-19'));
        $albums->expects($this->once())
            ->method('ensureAlbum')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->stringContains('Domaine de Mozet — '),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(new \Modules\Gallery\Api\DelegatedAlbum(2, 'y', '2024-07-26'));

        $audit = $this->audit();
        $this->serviceWith(audit: $audit, albums: new CampAlbumService($audit, $albums))
            ->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());
    }

    // ── The correspondence follows the stay ─────────────────────────

    public function testEveryMessageOfTheLosingStayMovesToTheSurvivingOne(): void
    {
        // `inbound_mail` keys its messages on a reference of ours that no
        // constraint knows about. Left behind, they point at a stay row
        // that has just been deleted and are reachable from no screen.
        [$service, $inboundMail, $messages] = $this->withInboundMail();
        $place = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $from = $this->stay($place, '2024-07-19');
        $to = $this->stay($place, '2024-07-26');
        $messageId = $messages->create(
            1, 'INBOX', 1, 10, CampsMessageConsumer::CONSUMER_ID, 'camp-' . $from,
            LinkOrigin::SENDER, '<a@mail>', null, 'Le terrain', 'lambert@example.org', null,
            'Bonjour', '', new \DateTimeImmutable('2024-06-01')
        );

        $service->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());

        $this->assertSame(
            [],
            $inboundMail->findForReference(CampsMessageConsumer::CONSUMER_ID, 'camp-' . $from)
        );
        $moved = $inboundMail->findForReference(CampsMessageConsumer::CONSUMER_ID, 'camp-' . $to);
        $this->assertCount(1, $moved);
        $this->assertSame($messageId, $moved[0]->id);
    }

    public function testAMergeWithoutTheInboundMailModuleStillWorks(): void
    {
        // §7.5: an optional dependency, absent on most installations.
        $place = $this->places->create('A', null, null, 'X', null, null);
        $from = $this->stay($place, '2024-07-19');
        $to = $this->stay($place, '2024-07-26');

        $this->service->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());

        $this->assertNull($this->camps->findById($from));
    }

    // ── One unit of work ────────────────────────────────────────────

    public function testAFailureHalfwayThroughACampMergeLeavesEverythingWhereItWas(): void
    {
        // Without a transaction this used to leave contacts on a stay that
        // still exists, a note appended to a merge that never happened,
        // and two rows neither screen can describe.
        $place = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $from = $this->stay($place, '2024-07-19', 45000);
        $to = $this->stay($place, '2024-07-26', 50000);
        $this->contacts->create($from, 'Mme Lambert', 'Propriétaire', 'lambert@example.org', null, null);

        $service = $this->serviceWithFailingReviews();

        try {
            $service->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());
            $this->fail('The stub must have made the merge fail.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNotNull($this->camps->findById($from), 'The losing stay must survive a failed merge.');
        $this->assertCount(1, $this->contacts->findByCamp($from));
        $this->assertCount(0, $this->contacts->findByCamp($to));
        $this->assertSame(45000, $this->camp($from)->priceCents);
        $this->assertSame(50000, $this->camp($to)->priceCents);
    }

    public function testAFailureHalfwayThroughAPlaceMergeLeavesEverythingWhereItWas(): void
    {
        $from = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $to = $this->places->create('Domaine de Mozet asbl', null, null, 'Mozet', null, null);
        $this->stay($from, '2024-07-19');

        $service = $this->serviceWithFailingAudit();

        try {
            $service->mergePlaces($this->place($from), $this->place($to), 42);
            $this->fail('The stub must have made the merge fail.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertFalse($this->places->findById($from)?->isArchived);
        $this->assertSame(1, $this->camps->countByPlace($from));
        $this->assertSame(0, $this->camps->countByPlace($to));
    }

    /**
     * A service whose gallery half throws — the one call deliberately made
     * outside the transaction, and deliberately non-fatal.
     */
    public function testAGalleryFailureDoesNotUndoTheMerge(): void
    {
        $place = $this->places->create('A', null, null, 'X', null, null);
        $from = $this->stay($place, '2024-07-19');
        $to = $this->stay($place, '2024-07-26');

        $albums = new class (new AuditService(new AuditRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))) extends CampAlbumService {
            public function existingAlbumIdFor(Camp $camp): ?int
            {
                throw new \RuntimeException('gallery is down');
            }
        };
        $service = $this->serviceWith(albums: $albums);

        $service->mergeCamps($this->camp($from), $this->camp($to), 42, $this->today());

        $this->assertNull($this->camps->findById($from), 'The merge itself must have gone through.');
    }

    private function stay(int $placeId, string $endDate, ?int $priceCents = null, ?int $participants = null): int
    {
        return $this->camps->create(
            $placeId, Camp::STAY_GRAND_CAMP, $endDate, $endDate, null, Camp::STATUS_CONFIRMED,
            $priceCents, $participants, null, null, []
        );
    }

    private function camp(int $id): Camp
    {
        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);

        return $camp;
    }

    private function place(int $id): \Modules\Camps\Repository\Place
    {
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        return $place;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-24');
    }

    // ── Building variants of the service under test ─────────────────

    private function audit(): AuditService
    {
        return new AuditService(new AuditRepository($this->pdo, $this->encryption));
    }

    private function serviceWith(
        ?ReviewRepository $reviews = null,
        ?AuditService $audit = null,
        ?CampAlbumService $albums = null,
        ?InboundMailInterface $inboundMail = null
    ): MergeService {
        $audit ??= $this->audit();

        return new MergeService(
            $this->places,
            $this->camps,
            $this->contacts,
            $this->links,
            $this->documents,
            $reviews ?? $this->reviews,
            $this->editableContent,
            $audit,
            $albums ?? new CampAlbumService($audit, null),
            $this->pdo,
            $inboundMail
        );
    }

    /**
     * The real `inbound_mail` service over the test database, so the move
     * is exercised through the very API §7.11 offers and not a mock of it.
     *
     * @return array{MergeService, InboundMailService, InboundMessageRepository}
     */
    private function withInboundMail(): array
    {
        InboundMailTestHelper::createTables($this->pdo);
        $messages = new InboundMessageRepository($this->pdo, $this->encryption);
        $inboundMail = new InboundMailService(
            $messages,
            new InboundMailboxRepository($this->pdo, $this->encryption),
            new FileRepository($this->pdo)
        );

        return [$this->serviceWith(inboundMail: $inboundMail), $inboundMail, $messages];
    }

    private function serviceWithFailingReviews(): MergeService
    {
        return $this->serviceWith(reviews: new class ($this->pdo) extends ReviewRepository {
            public function findByCamp(int $campId): ?\Modules\Camps\Repository\Review
            {
                throw new \RuntimeException('the database went away mid-merge');
            }
        });
    }

    private function serviceWithFailingAudit(): MergeService
    {
        return $this->serviceWith(audit: new class ($this->auditRepository()) extends AuditService {
            public function record(
                string $entityType,
                int $entityId,
                string $fieldKey,
                ?string $from,
                ?string $to,
                \Core\Audit\AuditSource $source,
                ?string $summary = null,
                ?string $sourceReference = null,
                ?int $actorUserAccountId = null
            ): void {
                throw new \RuntimeException('the database went away mid-merge');
            }
        });
    }

    private function auditRepository(): AuditRepository
    {
        return new AuditRepository($this->pdo, $this->encryption);
    }
}
