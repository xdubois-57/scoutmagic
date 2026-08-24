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

        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->contacts = new ContactRepository($this->pdo, $encryption);
        $links = new LinkRepository($this->pdo);
        $documents = new DocumentRepository($this->pdo);
        $reviews = new ReviewRepository($this->pdo);
        $root = dirname(__DIR__, 4);

        $this->controller = new CampsAttachmentController(
            \Core\View\TwigFactory::create(
                $root . '/core/View/templates',
                false,
                ['camps' => $root . '/modules/camps/views']
            ),
            $camps,
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
            new CampAlbumService($audit, null),
            new ReviewService($reviews, $audit, $places),
            $reviews
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.com', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        \Core\Http\FlashMessage::get();
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
}
