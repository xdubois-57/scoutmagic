<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Gallery\Api\DelegatedAlbum;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Api\GalleryException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * What a stay's photos do when the gallery refuses (issue #449, lot 7).
 *
 * This service is the camps side of a module boundary: it wraps
 * `Gallery\Api\DelegatedAlbumManager` and turns `GalleryException` into one
 * of two things. Six `catch` blocks, none of them ever executed before this
 * file existed — because no file tested this class at all.
 *
 * **Four absorb** — `albumIdFor()`, `existingAlbumIdFor()`, `listMedia()`
 * and `movePhotos()` answer null, null, [] and 0. Surviving is the point: the
 * class docblock argues it (« a module whose main job is not photos must not
 * become unusable because the gallery is disabled ») and `albumIdFor()` names
 * a legitimate refusal to survive — a storage location that cannot host a
 * delegated album at all.
 *
 * **But the page draws a false conclusion from that null, which is issue
 * #637.** `CampsAttachmentController` computes `album_available` as
 * `isAvailable() && $albumId !== null`, so an absorbed `GalleryException`
 * makes `photos.html.twig` print « le module Galerie est désactivé sur ce
 * site » while the module is enabled. Absorbing is right; naming a cause
 * that is not the cause is not. The tests below pin the surviving page AND
 * record that conflation rather than blessing it.
 *
 * **Two translate** — `addPhoto()` and `deletePhoto()` re-throw
 * `CampsException($e->getMessage(), 0, $e)`. Three things matter there and
 * each is asserted: the gallery's own sentence reaches the chief rather than
 * a generic one, the cause is chained for the log, and the audit entry —
 * written AFTER the try — is not recorded for something that did not happen.
 *
 * Every test carries its own contrast: a gallery that works first, so that
 * the null, the empty list, the zero or the missing audit entry afterwards
 * means « it refused » and not merely « this method can only ever answer
 * that ».
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampAlbumServiceTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private AuditService $audit;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
    }

    private function makeCamp(): Camp
    {
        $id = $this->camps->create(
            1,
            Camp::STAY_GRAND_CAMP,
            '2028-07-12',
            '2028-07-19',
            null,
            Camp::STATUS_CONFIRMED,
            null,
            null,
            null,
            null,
            []
        );
        $camp = $this->camps->findById($id);
        self::assertNotNull($camp);

        return $camp;
    }

    /**
     * A gallery that answers every call by refusing, with one reason.
     *
     * `GalleryException` is the module contract's own exception and absorbing
     * it is precisely what these six blocks exist for, so a stub throwing it
     * is the production condition rather than an artifice — unlike a broken
     * table, which is what an unreachable database would need.
     */
    private function serviceRefusing(string $reason): CampAlbumService
    {
        $gallery = $this->createStub(DelegatedAlbumManager::class);
        $gallery->method('ensureAlbum')->willThrowException(new GalleryException($reason));
        $gallery->method('findAlbum')->willThrowException(new GalleryException($reason));
        $gallery->method('listMedia')->willThrowException(new GalleryException($reason));
        $gallery->method('addMedia')->willThrowException(new GalleryException($reason));
        $gallery->method('deleteMedia')->willThrowException(new GalleryException($reason));
        $gallery->method('moveMedia')->willThrowException(new GalleryException($reason));

        return new CampAlbumService($this->audit, $gallery);
    }

    /** A gallery that works, for the half of each test that gives the other half its meaning. */
    private function serviceAnswering(): CampAlbumService
    {
        $gallery = $this->createStub(DelegatedAlbumManager::class);
        $gallery->method('ensureAlbum')->willReturn(new DelegatedAlbum(42, 'Grand camp 2028', '2028-07-19'));
        $gallery->method('findAlbum')->willReturn(new DelegatedAlbum(42, 'Grand camp 2028', '2028-07-19'));
        $gallery->method('listMedia')->willReturn([
            new DelegatedMedia(7, 'photo', 'done', 1, 'feu-de-camp.jpg', '2028-07-20 10:00:00'),
        ]);
        $gallery->method('moveMedia')->willReturn(3);
        // The two translating methods need their happy path too, or their
        // tests have no contrast — and `DelegatedMedia` is final, so this is
        // a real value object rather than a double of one.
        $gallery->method('addMedia')->willReturn(
            new DelegatedMedia(7, 'photo', 'done', 1, 'feu-de-camp.jpg', '2028-07-20 10:00:00')
        );

        return new CampAlbumService($this->audit, $gallery);
    }

    private function auditTotal(int $campId): int
    {
        return $this->audit->page(CampService::ENTITY_TYPE, $campId, 1, 10)->total;
    }

    public function testAnAlbumThatCannotBeCreatedLeavesTheCampPageStanding(): void
    {
        $camp = $this->makeCamp();

        // The contrast: a working gallery hands back an id, so the null below
        // is the refusal and not this method's only possible answer.
        $this->assertSame(42, $this->serviceAnswering()->albumIdFor($camp, 'Grand camp 2028', 3));

        $refused = $this->serviceRefusing('Cet emplacement de stockage ne peut pas héberger un album délégué.')
            ->albumIdFor($camp, 'Grand camp 2028', 3);

        // Reaching this line at all is the assertion: without the catch the
        // GalleryException would leave the method and take the camp page with
        // it. What the page then SAYS about that null is issue #637 — it reads
        // it as « the gallery module is disabled », which is a cause and not
        // the cause. Absorbing here is right; the sentence upstairs is not.
        $this->assertNull($refused);
    }

    public function testAskingWhetherAStayHasPhotosAnswersNoWhenTheGalleryCannotBeAsked(): void
    {
        $camp = $this->makeCamp();

        $this->assertSame(42, $this->serviceAnswering()->existingAlbumIdFor($camp));

        // Never create-if-missing: this is the question a merge asks about the
        // losing stay, and it must not answer by creating an empty album.
        $this->assertNull($this->serviceRefusing('La galerie est indisponible.')->existingAlbumIdFor($camp));
    }

    public function testAPhotoListThatCannotBeReadIsEmptyRatherThanFatal(): void
    {
        $this->assertCount(1, $this->serviceAnswering()->listMedia(42));

        $this->assertSame([], $this->serviceRefusing('Album introuvable.')->listMedia(42));
    }

    public function testAMergeWhosePhotosCannotBeMovedReportsNoneMovedRatherThanFailing(): void
    {
        // A merge is a multi-step gesture and the photo move is its last,
        // deliberately non-fatal step. `MergeService::movePhotos()` wraps the
        // whole sequence in its own `catch (\Throwable)`, so the merge is
        // protected either way — what this branch adds is a COUNT rather than
        // a silence, which is what the caller reports.
        $this->assertSame(3, $this->serviceAnswering()->movePhotos(42, 43));

        $this->assertSame(0, $this->serviceRefusing('Album de destination introuvable.')->movePhotos(42, 43));
    }

    public function testAPhotoRefusedByTheGalleryTellsTheChiefTheGallerysOwnReason(): void
    {
        $camp = $this->makeCamp();

        // The contrast, and it is load-bearing twice over: it proves the audit
        // entry IS written when the upload works, so the unchanged total below
        // means « refused » rather than « this class never audits anything ».
        // Nothing else in the suite exercised that success path.
        $this->serviceAnswering()->addPhoto($camp, 42, ['name' => 'feu.jpg'], 9);
        $this->assertSame(1, $this->auditTotal($camp->id));

        $before = $this->auditTotal($camp->id);
        $reason = 'Ce type de fichier n’est pas accepté dans une galerie.';

        try {
            $this->serviceRefusing($reason)->addPhoto($camp, 42, ['name' => 'film.exe'], 9);
            self::fail('the refused upload was accepted');
        } catch (CampsException $e) {
            // The gallery's sentence, verbatim. A chief whose file was refused
            // for its type needs to read that, not « une erreur est survenue » —
            // and this service is the only place that could have replaced it.
            $this->assertSame($reason, $e->getMessage());
            // Chained, so the log keeps the cause the message cannot carry.
            $this->assertInstanceOf(GalleryException::class, $e->getPrevious());
        }

        // The half that is state rather than words: `record()` sits AFTER the
        // try, so a `catch` that flashed and fell through would leave « Photo
        // ajoutée » in the audit trail of a stay that gained no photo.
        $this->assertSame($before, $this->auditTotal($camp->id));
    }

    public function testAPhotoThatCouldNotBeDeletedSaysWhyAndIsNotAuditedAsDeleted(): void
    {
        $camp = $this->makeCamp();

        // Same contrast, same reason: a deletion that works leaves « Photo
        // supprimée » behind, so the unchanged total below carries weight.
        $this->serviceAnswering()->deletePhoto($camp, 42, 7, 9);
        $this->assertSame(1, $this->auditTotal($camp->id));

        $before = $this->auditTotal($camp->id);
        $reason = 'Ce média appartient à un autre album.';

        try {
            $this->serviceRefusing($reason)->deletePhoto($camp, 42, 7, 9);
            self::fail('the refused deletion was reported as done');
        } catch (CampsException $e) {
            $this->assertSame($reason, $e->getMessage());
            $this->assertInstanceOf(GalleryException::class, $e->getPrevious());
        }

        $this->assertSame($before, $this->auditTotal($camp->id));
    }

    public function testWithoutTheGalleryAddingAPhotoSaysSoWhileDeletingOneIsASilentNoOp(): void
    {
        $camp = $this->makeCamp();
        $withoutGallery = new CampAlbumService($this->audit, null);

        // The asymmetry is deliberate and undocumented, so it is pinned here:
        // asking to ADD a photo with no gallery is a request that cannot be
        // honoured and says why, while asking to DELETE one is already true.
        try {
            $withoutGallery->addPhoto($camp, 42, ['name' => 'feu.jpg'], 9);
            self::fail('an upload was accepted with no gallery module');
        } catch (CampsException $e) {
            $this->assertStringContainsString('le module Galerie est désactivé', $e->getMessage());
        }

        $withoutGallery->deletePhoto($camp, 42, 7, 9);

        $this->assertFalse($withoutGallery->isAvailable());
        $this->assertSame(0, $this->auditTotal($camp->id), 'a no-op was audited');
    }
}
