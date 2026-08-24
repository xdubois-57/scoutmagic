<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsAttachmentController;
use Modules\Camps\Controller\CampsChiefController;
use Modules\Camps\Controller\CampsConfigController;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\PlaceService;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\ContactService;
use Modules\Camps\Service\DocumentService;
use Modules\Camps\Service\LinkService;
use Modules\Camps\Service\SectionDescriber;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;
use Twig\Environment;

/**
 * RBAC boundary through the REAL Router/RbacGuard pipeline, against the
 * role_min each route declares in module.json.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampsRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private CampsChiefController $chiefController;
    private CampsConfigController $configController;
    private int $accountId;
    private int $placeId;
    private int $campId;
    private int $contactId;
    private int $documentId;
    private int $linkId;
    private CampsAttachmentController $attachmentController;
    private \Modules\Camps\Controller\CampsMergeController $mergeController;
    private \Modules\Camps\Controller\CampsMailController $mailController;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com', 'user_accounts.email'), $encryption->blindIndex('chief@test.com', 'email')]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        $places = new PlaceRepository($this->pdo);
        $camps = new CampRepository($this->pdo, $encryption);
        $this->placeId = $places->create('Domaine de Mozet', 'Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique', null);
        $this->campId = $camps->create(
            $this->placeId, Camp::STAY_GRAND_CAMP, '2028-07-12', '2028-07-19', null,
            Camp::STATUS_CONFIRMED, 245000, 65, null, 'Thomas Dupont', []
        );

        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $sections = new SectionService(
            Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo)
        );

        $root = dirname(__DIR__, 4);
        $twig = TwigFactory::create($root . '/core/View/templates', false, ['camps' => $root . '/modules/camps/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $this->twig = $twig;

        $contacts = new ContactRepository($this->pdo, $encryption);
        $links = new LinkRepository($this->pdo);
        $documentRepo = new DocumentRepository($this->pdo);
        $contactService = new ContactService($contacts, $audit);
        $albumService = new CampAlbumService($audit, null);
        $reviewRepo = new \Modules\Camps\Repository\ReviewRepository($this->pdo);
        $reviewService = new \Modules\Camps\Service\ReviewService($reviewRepo, $audit);
        $archiveService = new \Modules\Camps\Service\PlaceArchiveService($places, $camps, $audit);
        $duplicates = new \Modules\Camps\Service\DuplicatePlaceDetector($places, null);
        $mergeService = new \Modules\Camps\Service\MergeService(
            $places, $camps, $contacts, $links, $documentRepo, $reviewRepo,
            new EditableContentService(new EditableContentRepository($this->pdo)), $audit, $albumService,
            $this->pdo
        );
        $this->mergeController = new \Modules\Camps\Controller\CampsMergeController(
            $twig, $places, $camps, $mergeService, $archiveService
        );
        $this->contactId = $contacts->create($this->campId, 'Mme Lambert', 'Propriétaire', 'lambert@example.org', null, null);
        $fileId = (new \Core\File\FileRepository($this->pdo))
            ->create('camps/1/contrat.pdf', 'contrat.pdf', 'application/pdf', 12, 'chief', 'camps', null);
        $this->documentId = $documentRepo->create($this->campId, 'Contrat', $fileId);
        $this->linkId = $links->create($this->campId, 'https://example.org', null, null, null, null, null);

        $this->attachmentController = new CampsAttachmentController(
            $twig, $camps, $places, $contacts, $links, $documentRepo,
            $contactService,
            new LinkService($links, $audit, null, null),
            new DocumentService(
                $documentRepo,
                new \Core\File\AttachedFileRemover(
                    new \Core\File\FileRepository($this->pdo), sys_get_temp_dir()
                ),
                new \Core\File\UploadHandler(new \Core\File\FileRepository($this->pdo), sys_get_temp_dir()),
                $audit
            ),
            $albumService,
            $reviewService,
            $reviewRepo
        );

        $this->chiefController = new CampsChiefController(
            $twig, $places, $camps,
            new PlaceService($places, $audit),
            new CampService($camps, $audit),
            new SectionDescriber($sections),
            $sections,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $audit,
            $settings,
            $contacts,
            $links,
            $documentRepo,
            $albumService,
            $reviewRepo,
            $reviewService,
            $duplicates,
            $archiveService
        );
        $this->configController = new CampsConfigController($twig, $settings);
        $this->mailController = new \Modules\Camps\Controller\CampsMailController(
            $twig, $camps, $places, $settings
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'list' => ['/chefs/camps', 'chief', 'index', 'chief', 'intendant'],
            'new camp form' => ['/chefs/camps/nouveau', 'chief', 'create', 'chief', 'intendant'],
            'place sheet' => ['/chefs/camps/lieux/{place}', 'chief', 'showPlace', 'chief', 'intendant'],
            'place form' => ['/chefs/camps/lieux/{place}/modifier', 'chief', 'editPlace', 'chief', 'intendant'],
            'camp detail' => ['/chefs/camps/sejours/{camp}', 'chief', 'showCamp', 'chief', 'intendant'],
            'camp form' => ['/chefs/camps/sejours/{camp}/modifier', 'chief', 'editCamp', 'chief', 'intendant'],
            'documents' => ['/chefs/camps/sejours/{camp}/documents', 'attachment', 'documents', 'chief', 'intendant'],
            'photos' => ['/chefs/camps/sejours/{camp}/photos', 'attachment', 'photos', 'chief', 'intendant'],
            'edit a contact' => ['/chefs/camps/contacts/{contact}', 'attachment', 'updateContact', 'chief', 'intendant'],
            'delete a contact' => ['/chefs/camps/contacts/{contact}/supprimer', 'attachment', 'deleteContact', 'chief', 'intendant'],
            // Removing a review is a chief's correction, not an erasure:
            // nothing about a person is involved, and a stale opinion no
            // staff stands behind has to be removable by the staff.
            'remove a review' => ['/chefs/camps/sejours/{camp}/avis/supprimer', 'attachment', 'deleteReview', 'chief', 'intendant'],
            // Erasure is the module's one admin-only action: it is
            // irreversible and reaches every stay that person appears on.
            'anonymise a contact' => ['/chefs/camps/contacts/{contact}/anonymiser', 'attachment', 'confirmAnonymise', 'admin', 'chief'],
            // Merging PLACES moves whole stays and touches every screen
            // the place appears on — admin only. Merging STAYS loses
            // nothing (values go into the note) and is open to chiefs.
            'merge a place' => ['/chefs/camps/lieux/{place}/fusionner', 'merge', 'choosePlaceMerge', 'admin', 'chief'],
            'merge a stay' => ['/chefs/camps/sejours/{camp}/fusionner', 'merge', 'chooseCampMerge', 'chief', 'intendant'],
            'configuration' => ['/config/camps', 'config', 'index', 'superadmin', 'admin'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testTheDeclaredRoleGetsThrough(
        string $path,
        string $controller,
        string $action,
        string $allowed,
        string $denied
    ): void {
        AuthSession::login($this->accountId, 'allowed@test.com', $allowed);

        $response = $this->frontController($path, $controller, $action, $allowed)
            ->handle(new Request('GET', $this->resolve($path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "role {$allowed} refused on {$path}");
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testOneLevelBelowIsRefusedByTheGuard(
        string $path,
        string $controller,
        string $action,
        string $allowed,
        string $denied
    ): void {
        AuthSession::login($this->accountId, 'denied@test.com', $denied);

        $response = $this->frontController($path, $controller, $action, $allowed)
            ->handle(new Request('GET', $this->resolve($path), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode(), "role {$denied} reached {$path}");
    }

    /**
     * Every POST route this module declares, at its own role_min and one
     * level below.
     *
     * A write route is where an RBAC hole actually costs something, and
     * these were the ones with no coverage at all: anonymisation, the two
     * merges, the configuration save, and every attach/discard on the
     * mail screen. The guard runs before the controller, so what is
     * asserted is only the boundary — a request that gets past it may
     * still be turned away by CSRF or by a missing body, and that is a
     * different test's business.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function postRouteProvider(): array
    {
        return [
            'create a stay' => ['/chefs/camps', 'chief', 'store', 'chief', 'intendant'],
            'update a place' => ['/chefs/camps/lieux/{place}', 'chief', 'updatePlace', 'chief', 'intendant'],
            'update a stay' => ['/chefs/camps/sejours/{camp}', 'chief', 'updateCamp', 'chief', 'intendant'],
            'regenerate a summary' => ['/chefs/camps/lieux/{place}/resume', 'chief', 'regenerateSummary', 'chief', 'intendant'],
            'add a contact' => ['/chefs/camps/sejours/{camp}/contacts', 'attachment', 'storeContact', 'chief', 'intendant'],
            'save a contact' => ['/chefs/camps/contacts/{contact}', 'attachment', 'updateContact', 'chief', 'intendant'],
            'delete a contact' => ['/chefs/camps/contacts/{contact}/supprimer', 'attachment', 'deleteContact', 'chief', 'intendant'],
            'anonymise a contact' => ['/chefs/camps/contacts/{contact}/anonymiser', 'attachment', 'anonymise', 'admin', 'chief'],
            'add a link' => ['/chefs/camps/sejours/{camp}/liens', 'attachment', 'storeLink', 'chief', 'intendant'],
            'delete a link' => ['/chefs/camps/liens/{link}/supprimer', 'attachment', 'deleteLink', 'chief', 'intendant'],
            'add a document' => ['/chefs/camps/sejours/{camp}/documents', 'attachment', 'storeDocument', 'chief', 'intendant'],
            'delete a document' => ['/chefs/camps/documents/{document}/supprimer', 'attachment', 'deleteDocument', 'chief', 'intendant'],
            'add a photo' => ['/chefs/camps/sejours/{camp}/photos', 'attachment', 'storePhoto', 'chief', 'intendant'],
            'delete a photo' => ['/chefs/camps/sejours/{camp}/photos/supprimer', 'attachment', 'deletePhoto', 'chief', 'intendant'],
            'save a review' => ['/chefs/camps/sejours/{camp}/avis', 'attachment', 'saveReview', 'chief', 'intendant'],
            'remove a review' => ['/chefs/camps/sejours/{camp}/avis/supprimer', 'attachment', 'deleteReview', 'chief', 'intendant'],
            'merge places' => ['/chefs/camps/lieux/{place}/fusionner', 'merge', 'mergePlace', 'admin', 'chief'],
            'archive a place' => ['/chefs/camps/lieux/{place}/archiver', 'merge', 'archivePlace', 'admin', 'chief'],
            'restore a place' => ['/chefs/camps/lieux/{place}/restaurer', 'merge', 'restorePlace', 'admin', 'chief'],
            'merge stays' => ['/chefs/camps/sejours/{camp}/fusionner', 'merge', 'mergeCamp', 'chief', 'intendant'],
            'attach a message' => ['/chefs/camps/courrier/{message}/rattacher', 'mail', 'attach', 'chief', 'intendant'],
            'discard a message' => ['/chefs/camps/courrier/{message}/supprimer', 'mail', 'discard', 'chief', 'intendant'],
            'apply a proposal' => ['/chefs/camps/propositions/{proposal}/appliquer', 'mail', 'applyProposal', 'chief', 'intendant'],
            'dismiss a proposal' => ['/chefs/camps/propositions/{proposal}/ignorer', 'mail', 'dismissProposal', 'chief', 'intendant'],
            'save the configuration' => ['/config/camps', 'config', 'save', 'superadmin', 'admin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postRouteProvider')]
    public function testTheDeclaredRoleGetsThroughOnEveryWriteRoute(
        string $path,
        string $controller,
        string $action,
        string $allowed,
        string $denied
    ): void {
        AuthSession::login($this->accountId, 'allowed@test.com', $allowed);

        $response = $this->frontController($path, $controller, $action, $allowed, 'POST')
            ->handle(new Request('POST', $this->resolve($path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "role {$allowed} refused on POST {$path}");
        // Not a crash either: a write route reached with no CSRF token and
        // no body must answer with a redirect or a rendered refusal, never
        // a 500 — a fatal here would make the assertion above vacuous.
        $this->assertLessThan(500, $response->getStatusCode(), "POST {$path} crashed for {$allowed}");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postRouteProvider')]
    public function testOneLevelBelowIsRefusedOnEveryWriteRoute(
        string $path,
        string $controller,
        string $action,
        string $allowed,
        string $denied
    ): void {
        AuthSession::login($this->accountId, 'denied@test.com', $denied);

        $response = $this->frontController($path, $controller, $action, $allowed, 'POST')
            ->handle(new Request('POST', $this->resolve($path), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode(), "role {$denied} reached POST {$path}");
    }

    /**
     * The provider above must list every POST route the manifest declares —
     * otherwise a route added later is a route nobody checks the boundary
     * of, which is exactly how the untested twenty came to exist.
     */
    public function testEveryDeclaredWriteRouteIsCovered(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/camps/module.json'),
            true
        );
        self::assertIsArray($manifest);

        $declared = [];
        foreach ($manifest['routes'] as $route) {
            if (strtoupper((string) ($route['method'] ?? 'GET')) === 'POST') {
                $declared[] = $route['controller'] . '::' . $route['action'];
            }
        }

        $covered = [];
        foreach (self::postRouteProvider() as [$path, $controller, $action]) {
            $covered[] = match ($controller) {
                'config' => CampsConfigController::class,
                'attachment' => CampsAttachmentController::class,
                'merge' => \Modules\Camps\Controller\CampsMergeController::class,
                'mail' => \Modules\Camps\Controller\CampsMailController::class,
                default => CampsChiefController::class,
            } . '::' . $action;
        }

        sort($declared);
        sort($covered);
        $this->assertSame($declared, $covered);
    }

    public function testAnAnonymousVisitorReachesNothing(): void
    {
        $response = $this->frontController('/chefs/camps', 'chief', 'index', 'chief')
            ->handle(new Request('GET', '/chefs/camps', [], [], [], []));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * A camp id that does not exist must answer 404 rather than render an
     * empty page — the ids here are sequential, and a blank camp sheet for
     * every number a visitor tries reads as "this exists but is empty".
     */
    public function testAnUnknownCampOrPlaceIsNotFound(): void
    {
        AuthSession::login($this->accountId, 'chief@test.com', 'chief');

        $camp = $this->frontController('/chefs/camps/sejours/{camp}', 'chief', 'showCamp', 'chief')
            ->handle(new Request('GET', '/chefs/camps/sejours/999999', [], [], [], []));
        $place = $this->frontController('/chefs/camps/lieux/{place}', 'chief', 'showPlace', 'chief')
            ->handle(new Request('GET', '/chefs/camps/lieux/999999', [], [], [], []));

        $this->assertSame(404, $camp->getStatusCode());
        $this->assertSame(404, $place->getStatusCode());
    }

    private function resolve(string $path): string
    {
        return str_replace(
            ['{place}', '{camp}', '{contact}', '{document}', '{link}', '{message}', '{proposal}'],
            [
                (string) $this->placeId,
                (string) $this->campId,
                (string) $this->contactId,
                (string) $this->documentId,
                (string) $this->linkId,
                '4242',
                '4242',
            ],
            $path
        );
    }

    private function frontController(
        string $path,
        string $controller,
        string $action,
        string $roleMin,
        string $method = 'GET'
    ): FrontController {
        $class = match ($controller) {
            'config' => CampsConfigController::class,
            'attachment' => CampsAttachmentController::class,
            'merge' => \Modules\Camps\Controller\CampsMergeController::class,
            'mail' => \Modules\Camps\Controller\CampsMailController::class,
            default => CampsChiefController::class,
        };
        $routePath = str_replace(
            ['{place}', '{camp}', '{contact}', '{document}', '{link}', '{message}', '{proposal}'],
            ['{id}', '{id}', '{id}', '{id}', '{id}', '{id}', '{id}'],
            $path
        );

        $router = new Router();
        $router->addRoute($method, $routePath, $class, $action, $roleMin);

        $configFile = sys_get_temp_dir() . '/test_camps_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $fc = new FrontController($router, $this->twig, new AppConfig($configFile));
        $fc->registerController($class, match ($controller) {
            'config' => $this->configController,
            'attachment' => $this->attachmentController,
            'merge' => $this->mergeController,
            'mail' => $this->mailController,
            default => $this->chiefController,
        });

        return $fc;
    }
}
