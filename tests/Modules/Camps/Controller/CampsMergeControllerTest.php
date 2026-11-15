<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsMergeController;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\MergeService;
use Modules\Camps\Service\PlaceArchiveService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * What the merge and archive buttons say when the services refuse, and
 * that a refusal leaves every row where it was.
 *
 * Each refusal is reached the way a chief reaches it: a place archived in
 * the meantime, two stays of two different places, a place with a
 * confirmed stay still to come.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CampsMergeControllerTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private CampRepository $camps;
    private CampsMergeController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository($this->pdo, $encryption);
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $root = dirname(__DIR__, 4);

        $this->controller = new CampsMergeController(
            TwigFactory::create($root . '/core/View/templates', false, ['camps' => $root . '/modules/camps/views']),
            $this->places,
            $this->camps,
            new MergeService(
                $this->places,
                $this->camps,
                new ContactRepository($this->pdo, $encryption),
                new LinkRepository($this->pdo),
                new DocumentRepository($this->pdo),
                new ReviewRepository($this->pdo),
                new EditableContentService(new EditableContentRepository($this->pdo)),
                $audit,
                new CampAlbumService($audit, null),
                $this->pdo
            ),
            new PlaceArchiveService($this->places, $this->camps, $audit)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.com', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        FlashMessage::get();
    }

    public function testMergingIntoAnArchivedPlaceSaysWhyAndMovesNothing(): void
    {
        $from = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);
        $into = $this->places->create('Ferme du Bois (doublon)', null, null, 'Mozet', null, null);
        $this->places->archive($into, true);
        $campId = $this->aStay($from, '2027-07-12', '2027-07-19');

        $response = $this->controller->mergePlace(
            $this->post('/chefs/camps/lieux/' . $from . '/fusionner', ['target_id' => (string) $into]),
            ['id' => (string) $from]
        );

        $this->assertSame('/chefs/camps/lieux/' . $from . '/fusionner', $this->location($response));
        $this->assertSame(
            [
                'type' => 'error',
                'message' => 'Ce lieu est archivé : désarchivez-le d\'abord si vous voulez y regrouper des séjours.',
            ],
            FlashMessage::get()
        );
        $this->assertSame($from, $this->camps->findById($campId)?->placeId);
        $this->assertFalse($this->places->findById($from)?->isArchived);
    }

    public function testMergingStaysOfTwoPlacesSaysWhyAndKeepsBoth(): void
    {
        $first = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);
        $second = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $from = $this->aStay($first, '2027-07-12', '2027-07-19');
        $into = $this->aStay($second, '2027-07-12', '2027-07-19');

        $response = $this->controller->mergeCamp(
            $this->post('/chefs/camps/sejours/' . $from . '/fusionner', ['target_id' => (string) $into]),
            ['id' => (string) $from]
        );

        $this->assertSame('/chefs/camps/sejours/' . $from . '/fusionner', $this->location($response));
        $this->assertSame(
            [
                'type' => 'error',
                'message' => 'On ne fusionne que deux séjours d\'un même lieu. Fusionnez d\'abord les deux lieux.',
            ],
            FlashMessage::get()
        );
        $this->assertNotNull($this->camps->findById($from));
        $this->assertNotNull($this->camps->findById($into));
    }

    public function testArchivingAPlaceWithAConfirmedStayToComeSaysWhyAndKeepsItLive(): void
    {
        $placeId = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);
        $next = (int) (new \DateTimeImmutable('today'))->format('Y') + 1;
        $this->aStay($placeId, $next . '-07-12', $next . '-07-19');

        $response = $this->controller->archivePlace(
            $this->post('/chefs/camps/lieux/' . $placeId . '/archiver', []),
            ['id' => (string) $placeId]
        );

        $this->assertSame('/chefs/camps/lieux/' . $placeId, $this->location($response));
        $this->assertSame(
            [
                'type' => 'error',
                'message' => 'Ce lieu a 1 séjour confirmé à venir. Annulez-le d\'abord — archiver un lieu '
                    . 'le retire de la recherche, et un staff qui part en juillet en aurait besoin.',
            ],
            FlashMessage::get()
        );
        $this->assertFalse($this->places->findById($placeId)?->isArchived);
    }

    private function aStay(int $placeId, string $start, string $end): int
    {
        return $this->camps->create(
            $placeId, Camp::STAY_GRAND_CAMP, $start, $end, null, Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body): Request
    {
        return new Request('POST', $path, [], ['_csrf_token' => CsrfGuard::generateToken()] + $body, [], []);
    }

    private function location(Response $response): ?string
    {
        return $response->getHeaders()['Location'] ?? null;
    }
}
