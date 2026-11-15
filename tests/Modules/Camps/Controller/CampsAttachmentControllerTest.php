<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Camps\Controller\CampsAttachmentController;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\ContactService;
use Modules\Camps\Service\DocumentService;
use Modules\Camps\Service\LinkService;
use Modules\Camps\Service\ReviewService;
use Modules\Gallery\Api\DelegatedAlbumManager;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * The contact form, end to end through the controller.
 *
 * The service's own tests can be written against any payload the author
 * imagines; only a test that posts what the BROWSER posts can catch a
 * validator that disagrees with the picker feeding it — which is exactly
 * what happened to the role field.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampsAttachmentControllerTest extends TestCase
{
    private \PDO $pdo;
    private ContactRepository $contacts;
    private CampsAttachmentController $controller;
    private int $campId;
    private ?string $galleryStorage = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $places = new PlaceRepository($this->pdo);
        $camps = new CampRepository($this->pdo, $encryption);
        $placeId = $places->create('Domaine de Mozet', null, null, 'Mozet', 'Belgique', null);
        $this->campId = $camps->create(
            $placeId,
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

        $this->contacts = new ContactRepository($this->pdo, $encryption);
        $this->controller = $this->controllerWith(null);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.com', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        \Core\Http\FlashMessage::get();
        if ($this->galleryStorage !== null && is_dir($this->galleryStorage)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->galleryStorage, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
            }
            rmdir($this->galleryStorage);
        }
    }

    /**
     * The controller as the composition root builds it, with the gallery
     * module present or absent.
     */
    private function controllerWith(?DelegatedAlbumManager $gallery): CampsAttachmentController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $places = new PlaceRepository($this->pdo);
        $links = new LinkRepository($this->pdo);
        $documents = new DocumentRepository($this->pdo);
        $reviews = new ReviewRepository($this->pdo);
        $root = dirname(__DIR__, 4);

        return new CampsAttachmentController(
            \Core\View\TwigFactory::create(
                $root . '/core/View/templates',
                false,
                ['camps' => $root . '/modules/camps/views', 'inbound_mail' => $root . '/modules/inbound_mail/views']
            ),
            new CampRepository($this->pdo, $encryption),
            $places,
            $this->contacts,
            $links,
            $documents,
            new ContactService($this->contacts, $audit),
            new LinkService($links, $audit, null, null),
            new DocumentService(
                $documents,
                new \Core\File\AttachedFileRemover(
                    new \Core\File\FileRepository($this->pdo), sys_get_temp_dir()
                ),
                new \Core\File\UploadHandler(new \Core\File\FileRepository($this->pdo), sys_get_temp_dir()),
                $audit
            ),
            new CampAlbumService($audit, $gallery),
            new ReviewService($reviews, $audit, $places),
            $reviews
        );
    }

    /**
     * Exactly what modules/camps/views/camp.html.twig sends: the role
     * field carries the picker's KEY, because that is what the <option>
     * values are built from.
     *
     * @param array<string, string> $overrides
     */
    private function postContact(array $overrides = []): Response
    {
        $body = array_merge([
            '_csrf_token' => CsrfGuard::generateToken(),
            'name' => 'Mme Lambert',
            'role_label' => 'proprietaire',
            'email' => 'lambert@example.org',
            'phone' => '081 58 00 00',
            'other_details' => '',
        ], $overrides);

        return $this->controller->storeContact(
            new Request('POST', '/chefs/camps/sejours/' . $this->campId . '/contacts', [], $body, [], []),
            ['id' => (string) $this->campId]
        );
    }

    public function testTheFormsOwnPayloadCreatesAContact(): void
    {
        $response = $this->postContact();

        $this->assertSame(302, $response->getStatusCode());
        $contacts = $this->contacts->findByCamp($this->campId);
        $this->assertCount(1, $contacts, 'The real form payload must be accepted.');
        $this->assertSame('Propriétaire', $contacts[0]->roleLabel);
    }

    public function testAContactCanBeCreatedWithoutARole(): void
    {
        $this->postContact(['role_label' => '']);

        $this->assertNull($this->contacts->findByCamp($this->campId)[0]->roleLabel);
    }

    public function testAForgedRoleIsRefusedAndNothingIsWritten(): void
    {
        $this->postContact(['role_label' => 'concierge']);

        $this->assertSame([], $this->contacts->findByCamp($this->campId));
    }

    public function testTheEditFormUpdatesTheContact(): void
    {
        $this->postContact();
        $contact = $this->contacts->findByCamp($this->campId)[0];

        $response = $this->controller->updateContact(
            new Request('POST', '/chefs/camps/contacts/' . $contact->id, [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'name' => 'Mme Lambert-Dupuis',
                'role_label' => 'gestionnaire',
                'email' => 'lambert@example.org',
                'phone' => '',
                'other_details' => 'Préfère le téléphone le soir.',
            ], [], []),
            ['id' => (string) $contact->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $updated = $this->contacts->findById($contact->id);
        $this->assertNotNull($updated);
        $this->assertSame('Mme Lambert-Dupuis', $updated->name);
        $this->assertSame('Gestionnaire', $updated->roleLabel);
    }

    public function testDeletingAContactRemovesItFromTheStay(): void
    {
        $this->postContact();
        $contact = $this->contacts->findByCamp($this->campId)[0];

        $response = $this->controller->deleteContact(
            new Request('POST', '/chefs/camps/contacts/' . $contact->id . '/supprimer', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            ['id' => (string) $contact->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->contacts->findByCamp($this->campId));
    }

    public function testAnUnknownContactIsNotFound(): void
    {
        $response = $this->controller->updateContact(
            new Request('POST', '/chefs/camps/contacts/999999', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            ['id' => '999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── Refusals: what the chief is told, and what is left alone ────

    public function testAnEditWithAMalformedAddressSaysWhyAndKeepsTheContact(): void
    {
        $this->postContact();
        $contact = $this->contacts->findByCamp($this->campId)[0];

        $response = $this->controller->updateContact(
            new Request('POST', '/chefs/camps/contacts/' . $contact->id, [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'name' => 'Mme Lambert-Dupuis',
                'role_label' => 'gestionnaire',
                'email' => 'lambert.example.org',
                'phone' => '',
                'other_details' => '',
            ], [], []),
            ['id' => (string) $contact->id]
        );

        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            ['type' => 'error', 'message' => 'Cette adresse e-mail n\'est pas une adresse valide.'],
            \Core\Http\FlashMessage::get()
        );
        $kept = $this->contacts->findById($contact->id);
        $this->assertSame('Mme Lambert', $kept?->name);
        $this->assertSame('lambert@example.org', $kept->email);
    }

    public function testALinkThatIsNotAWebAddressSaysWhyAndStoresNothing(): void
    {
        $response = $this->controller->storeLink(
            new Request('POST', '/chefs/camps/sejours/' . $this->campId . '/liens', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'url' => 'javascript:alert(1)',
            ], [], []),
            ['id' => (string) $this->campId]
        );

        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            ['type' => 'error', 'message' => 'Cette adresse n\'est pas une adresse web valide.'],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame([], (new LinkRepository($this->pdo))->findByCamp($this->campId));
    }

    public function testADocumentOfARefusedTypeSaysWhyAndStoresNothing(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'camps_refused_doc_');
        file_put_contents($path, "<?php echo 'pas un document';\n");
        $_FILES['document'] = [
            'tmp_name' => $path, 'name' => 'notes.php', 'size' => filesize($path), 'error' => UPLOAD_ERR_OK,
        ];

        try {
            $response = $this->controller->storeDocument(
                new Request('POST', '/chefs/camps/sejours/' . $this->campId . '/documents', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                    'title' => 'Notes',
                ], [], []),
                ['id' => (string) $this->campId]
            );
        } finally {
            unset($_FILES['document']);
            @unlink($path);
        }

        $this->assertSame(
            '/chefs/camps/sejours/' . $this->campId . '/documents',
            $response->getHeaders()['Location'] ?? null
        );
        $this->assertSame(
            ['type' => 'error', 'message' => 'Type de fichier non autorisé (text/x-php).'],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame([], (new DocumentRepository($this->pdo))->findByCamp($this->campId));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testAReviewBeforeTheStayHasHappenedSaysWhyAndStoresNothing(): void
    {
        // The stay of this suite is in July 2028: still to come.
        $response = $this->controller->saveReview(
            new Request('POST', '/chefs/camps/sejours/' . $this->campId . '/avis', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'rating' => '4',
                'comment' => 'Terrain plat.',
            ], [], []),
            ['id' => (string) $this->campId]
        );

        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            [
                'type' => 'error',
                'message' => 'L\'avis s\'ouvrira au retour du camp — il n\'y a encore rien à raconter.',
            ],
            \Core\Http\FlashMessage::get()
        );
        $this->assertNull((new ReviewRepository($this->pdo))->findByCamp($this->campId));
    }

    // ── Photos, through the real gallery module ─────────────────────

    public function testAPhotoTheGalleryRefusesTellsTheChiefTheGallerysReason(): void
    {
        $controller = $this->controllerWith($this->gallery());
        $path = (string) tempnam(sys_get_temp_dir(), 'camps_refused_photo_');
        file_put_contents($path, "Ceci n'est pas une photo.\n");
        $_FILES['photo'] = [
            'tmp_name' => $path, 'name' => 'feu-de-camp.jpg', 'size' => filesize($path), 'error' => UPLOAD_ERR_OK,
        ];

        try {
            $response = $controller->storePhoto(
                new Request('POST', '/chefs/camps/sejours/' . $this->campId . '/photos', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                ], [], []),
                ['id' => (string) $this->campId]
            );
        } finally {
            unset($_FILES['photo']);
            @unlink($path);
        }

        $this->assertSame(
            '/chefs/camps/sejours/' . $this->campId . '/photos',
            $response->getHeaders()['Location'] ?? null
        );
        $this->assertSame(
            ['type' => 'error', 'message' => 'Type de fichier non autorisé.'],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_media')->fetchColumn());
        $this->assertNotContains('Photo ajoutée', $this->historySummaries());
    }

    public function testDeletingAPhotoOfAnotherAlbumSaysWhyAndIsNotRecordedAsDone(): void
    {
        $controller = $this->controllerWith($this->gallery());

        $response = $controller->deletePhoto(
            new Request('POST', '/chefs/camps/sejours/' . $this->campId . '/photos/supprimer', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'media_id' => '999',
            ], [], []),
            ['id' => (string) $this->campId]
        );

        $this->assertSame(
            '/chefs/camps/sejours/' . $this->campId . '/photos',
            $response->getHeaders()['Location'] ?? null
        );
        $this->assertSame(
            ['type' => 'error', 'message' => 'Ce média n\'appartient pas à cet album.'],
            \Core\Http\FlashMessage::get()
        );
        $this->assertNotContains('Photo supprimée', $this->historySummaries());
    }

    /**
     * The gallery module as a scheduled task gets it, through its own
     * published factory: every piece of it real, over this suite's
     * database and a storage directory of its own.
     */
    private function gallery(): DelegatedAlbumManager
    {
        \Tests\Modules\Gallery\GalleryTestHelper::createTables($this->pdo);
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) "
            . "VALUES ('2027-2028', '2027-09-01', '2028-08-31', 1)"
        );
        $this->galleryStorage = sys_get_temp_dir() . '/camps-photos-' . bin2hex(random_bytes(6));
        mkdir($this->galleryStorage, 0777, true);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return \Modules\Gallery\Api\DelegatedAlbumManagerFactory::fromTaskContext(new \Core\Scheduler\TaskContext(
            \Core\Database\Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(\Core\Mail\MailService::class),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo)),
            new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
            new \Core\Security\UserAccountRepository($this->pdo, $encryption),
            $this->galleryStorage
        ));
    }

    /**
     * @return list<?string> the stay's history, newest first
     */
    private function historySummaries(): array
    {
        $audit = new AuditService(new AuditRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        ));

        return array_map(
            static fn($entry): ?string => $entry->summary,
            $audit->page(\Modules\Camps\Service\CampService::ENTITY_TYPE, $this->campId, 1, 50)->entries
        );
    }
}
