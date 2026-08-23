<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\Controller\SectionDocumentController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionDocumentRepository;
use Core\Member\SectionDocumentService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Pdf\PdfCompressor;
use Core\ScoutYear\ScoutYearResolver;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\CsrfGuard;
use Core\Security\RbacGuard;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionDocumentControllerTest extends TestCase
{
    private \PDO $pdo;
    private SectionDocumentController $controller;
    private SectionDocumentRepository $documentRepository;
    private string $storagePath;
    private int $sectionId;
    private int $otherSectionId;
    private int $scoutYearId;
    private \Core\Security\EncryptionService $encryption;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->documentRepository = new SectionDocumentRepository($this->pdo);
        $membershipRepository = new SectionMembershipRepository($this->pdo);
        $fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/section_document_controller_test_' . uniqid();
        $fileStorage = new EncryptedFileStorageService($fileRepository, $encryption, $this->storagePath);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('section_document_compression_enabled', '1', 'boolean', 'x', 'x');
        $settingService->register('section_document_compression_quality', PdfCompressor::QUALITY_BALANCED, 'select', 'x', 'x');
        $settingService->register('section_document_compression_backend', PdfCompressor::BACKEND_NONE, 'text', 'x', 'x', null, null, null, false);

        $service = new SectionDocumentService(
            $this->documentRepository, $membershipRepository, $fileStorage, $fileRepository,
            $sectionService, new ScoutYearService($this->pdo), new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)), $settingService,
            new PdfCompressor($this->storagePath . '/temp')
        );

        $this->encryption = $encryption;
        $this->controller = new SectionDocumentController(
            $this->createMock(\Twig\Environment::class),
            $service,
            new SectionStaffAuthorizationService(
                $connection,
                $encryption,
                $sectionService,
                new MemberEmailRepository($this->pdo, $encryption)
            ),
            new ScoutYearResolver(new \Core\Config\ScoutYearService($this->pdo), $settingService, new MemberYearRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('SEC_A', {$branchId})");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('SEC_B', {$branchId})");
        $this->otherSectionId = (int) $this->pdo->lastInsertId();

        // Every functional test below acts as an animateur of SEC_A — the
        // nominal case. The cloisonnement tests re-log as somebody else.
        $this->loginAsAnimateurOf('animateur@test.example', [$this->sectionId]);
    }

    /**
     * Links an account's address to a chief-role function on each of
     * $sectionIds, then logs it in — the only way this controller can see
     * an account as an animateur of a section (Core\Member\
     * SectionStaffAuthorizationService).
     *
     * @param array<int, int> $sectionIds
     */
    private function loginAsAnimateurOf(string $email, array $sectionIds, string $accountRole = 'chief'): void
    {
        if ($sectionIds !== []) {
            $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
            $memberId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc', $this->encryption->blindIndex(strtolower($email), 'email')]);
            $memberYearId = (int) $this->pdo->lastInsertId();

            $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('chief', 'Animateur', 'chief')");
            $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'chief'")->fetchColumn();

            foreach ($sectionIds as $sectionId) {
                $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)');
                $stmt->execute([$memberYearId, $functionId, $sectionId]);
            }
        }

        $this->startTestSession();
        \Core\Security\AuthSession::login(1, $email, $accountRole);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_FILES = [];
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    // --- RBAC boundary: routes declared exactly as public/index.php registers them ---

    private function buildRouter(): Router
    {
        $router = new Router();
        $router->addRoute('POST', '/chefs/staffs/documents', SectionDocumentController::class, 'add', 'chief');
        $router->addRoute('POST', '/chefs/staffs/documents/reorder', SectionDocumentController::class, 'reorder', 'chief');
        $router->addRoute('POST', '/chefs/staffs/documents/delete', SectionDocumentController::class, 'delete', 'chief');
        $router->addRoute('POST', '/chefs/staffs/documents/{id}', SectionDocumentController::class, 'update', 'chief');
        return $router;
    }

    public function testEveryDocumentRouteRequiresAtLeastChief(): void
    {
        $router = $this->buildRouter();
        $guard = new RbacGuard();

        foreach (['/chefs/staffs/documents', '/chefs/staffs/documents/reorder', '/chefs/staffs/documents/delete', '/chefs/staffs/documents/5'] as $path) {
            $resolved = $router->resolve(new Request('POST', $path, [], [], [], []));
            $this->assertNotNull($resolved, "route {$path} should resolve");
            $this->assertSame('chief', $resolved->roleMin);
        }

        // Denied one level below (intendant).
        $this->startTestSession();
        \Core\Security\AuthSession::login(1, 'intendant@test.example', 'intendant');
        $this->assertFalse($guard->check(Role::CHIEF));

        // Allowed at role_min.
        \Core\Security\AuthSession::login(1, 'chief@test.example', 'chief');
        $this->assertTrue($guard->check(Role::CHIEF));
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    // --- Functional / CSRF ---

    public function testAddRejectsAnInvalidCsrfTokenWithoutCreatingAnything(): void
    {
        $this->csrfToken();

        $response = $this->controller->add(
            new Request('POST', '/chefs/staffs/documents', [], ['section_id' => (string) $this->sectionId, 'scout_year_id' => (string) $this->scoutYearId, 'title' => 'X', '_csrf_token' => 'wrong'], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->documentRepository->findBySectionAndYear($this->sectionId, $this->scoutYearId));
    }

    public function testUpdateRejectsAnInvalidCsrfToken(): void
    {
        $this->csrfToken();
        $id = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'Doc', null, 100, null);

        $response = $this->controller->update($this->jsonRequest(['title' => 'New', '_csrf_token' => 'wrong']), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateSucceedsWithAValidCsrfToken(): void
    {
        $token = $this->csrfToken();
        $id = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'Doc', null, 100, null);

        $response = $this->controller->update($this->jsonRequest(['title' => 'New title', 'description' => 'New desc', '_csrf_token' => $token]), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('New title', $this->documentRepository->findById($id)->title);
    }

    public function testDeleteSucceedsWithAValidCsrfToken(): void
    {
        $token = $this->csrfToken();
        $id = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'Doc', null, 100, null);

        $response = $this->controller->delete($this->jsonRequest(['id' => $id, '_csrf_token' => $token]), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->documentRepository->findById($id));
    }

    public function testReorderSucceedsWithAValidCsrfToken(): void
    {
        $token = $this->csrfToken();
        $a = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'A', null, 100, null);
        $b = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'B', null, 100, null);

        $response = $this->controller->reorder($this->jsonRequest(['ids' => [$b, $a], '_csrf_token' => $token]), []);

        $this->assertSame(200, $response->getStatusCode());
        $docs = $this->documentRepository->findBySectionAndYear($this->sectionId, $this->scoutYearId);
        $this->assertSame($b, $docs[0]->id);
    }

    // --- Cloisonnement: role_min chief is the floor, the staffed section
    // is the boundary (SECURITY.md §3, ARCHITECTURE.md §8.33) ---

    public function testAddAcceptsASectionTheAccountAnimates(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->add($this->uploadRequest($this->sectionId, $token), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->documentRepository->findBySectionAndYear($this->sectionId, $this->scoutYearId));
    }

    public function testAddRefusesASectionTheAccountDoesNotAnimate(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->add($this->uploadRequest($this->otherSectionId, $token), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->documentRepository->findBySectionAndYear($this->otherSectionId, $this->scoutYearId));
    }

    /**
     * The UI never renders the control for another section — so the only
     * way this request exists is forged, and the server refuses it on its
     * own rather than because a template hid a button.
     */
    public function testUpdateRefusesADocumentOfAnotherSection(): void
    {
        $token = $this->csrfToken();
        $id = $this->documentRepository->create($this->otherSectionId, $this->scoutYearId, $this->makeFile(), 'Intact', null, 100, null);

        $response = $this->controller->update($this->jsonRequest(['title' => 'Détourné', '_csrf_token' => $token]), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Intact', $this->documentRepository->findById($id)->title);
    }

    public function testDeleteRefusesADocumentOfAnotherSection(): void
    {
        $token = $this->csrfToken();
        $id = $this->documentRepository->create($this->otherSectionId, $this->scoutYearId, $this->makeFile(), 'Intact', null, 100, null);

        $response = $this->controller->delete($this->jsonRequest(['id' => $id, '_csrf_token' => $token]), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->documentRepository->findById($id));
    }

    /**
     * A reorder carries many ids at once. Authorizing it id-by-id would
     * apply the caller's ordering to the documents that did pass and leave
     * the list inconsistent — so one id out of scope refuses the batch.
     */
    public function testReorderRefusesTheWholeBatchWhenOneIdIsOutOfScope(): void
    {
        $token = $this->csrfToken();
        $mine = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'A', null, 100, null);
        $mineToo = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'B', null, 100, null);
        $theirs = $this->documentRepository->create($this->otherSectionId, $this->scoutYearId, $this->makeFile(), 'C', null, 100, null);

        $response = $this->controller->reorder($this->jsonRequest(['ids' => [$mineToo, $mine, $theirs], '_csrf_token' => $token]), []);

        $this->assertSame(403, $response->getStatusCode());
        // Untouched: the two in-scope documents keep their original order.
        $docs = $this->documentRepository->findBySectionAndYear($this->sectionId, $this->scoutYearId);
        $this->assertSame($mine, $docs[0]->id);
    }

    public function testReorderRefusesAnUnknownDocumentId(): void
    {
        $token = $this->csrfToken();
        $mine = $this->documentRepository->create($this->sectionId, $this->scoutYearId, $this->makeFile(), 'A', null, 100, null);

        $response = $this->controller->reorder($this->jsonRequest(['ids' => [$mine, 999999], '_csrf_token' => $token]), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReorderRefusesAnEmptyBatch(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->reorder($this->jsonRequest(['ids' => [], '_csrf_token' => $token]), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAChiefOfSeveralSectionsWritesInAllOfThem(): void
    {
        $_SESSION = [];
        $this->loginAsAnimateurOf('multi@test.example', [$this->sectionId, $this->otherSectionId]);
        $token = $this->csrfToken();
        $id = $this->documentRepository->create($this->otherSectionId, $this->scoutYearId, $this->makeFile(), 'Doc', null, 100, null);

        $response = $this->controller->update($this->jsonRequest(['title' => 'Renommé', '_csrf_token' => $token]), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Renommé', $this->documentRepository->findById($id)->title);
    }

    public function testAdminWritesInASectionTheyDoNotAnimate(): void
    {
        $_SESSION = [];
        $this->loginAsAnimateurOf('cu@test.example', [], 'admin');
        $token = $this->csrfToken();
        $id = $this->documentRepository->create($this->otherSectionId, $this->scoutYearId, $this->makeFile(), 'Doc', null, 100, null);

        $response = $this->controller->update($this->jsonRequest(['title' => 'Renommé', '_csrf_token' => $token]), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testARefusalIsJournaledAtSecurityLevelWithIdentifiersOnly(): void
    {
        $token = $this->csrfToken();
        $id = $this->documentRepository->create($this->otherSectionId, $this->scoutYearId, $this->makeFile(), 'Carnet secret', null, 100, null);

        $this->controller->delete($this->jsonRequest(['id' => $id, '_csrf_token' => $token]), []);

        $row = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'section_document_access_denied'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('security', $row['level']);
        $this->assertStringContainsString((string) $id, $row['context']);
        $this->assertStringContainsString((string) $this->otherSectionId, $row['context']);
        // Never the document's own title, nor anything else it carries.
        $this->assertStringNotContainsString('Carnet secret', $row['context']);
        $this->assertStringNotContainsString('Carnet secret', (string) $row['description']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function uploadRequest(int $sectionId, string $token): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sdc');
        file_put_contents($tmp, '%PDF-1.4 test document');
        $_FILES['file'] = [
            'name' => 'camp.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ];

        return new Request('POST', '/chefs/staffs/documents', [], [
            'section_id' => (string) $sectionId,
            'scout_year_id' => (string) $this->scoutYearId,
            'title' => 'Carnet',
            '_csrf_token' => $token,
        ], [], []);
    }

    private function makeFile(): int
    {
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, encrypted) VALUES ('a" . uniqid() . ".pdf.enc', 'a.pdf', 'application/pdf', 100, 'identified', 1)");
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/chefs/staffs/documents', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
