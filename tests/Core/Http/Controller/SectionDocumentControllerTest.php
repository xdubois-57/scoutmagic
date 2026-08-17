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
use Core\Member\SectionDocumentRepository;
use Core\Member\SectionDocumentService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\Pdf\PdfCompressor;
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
    private int $scoutYearId;

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

        $this->controller = new SectionDocumentController($this->createMock(\Twig\Environment::class), $service);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('SEC_A', {$branchId})");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
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
