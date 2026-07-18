<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Controller\UploadController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class UploadControllerTest extends TestCase
{
    private \PDO $pdo;
    private string $tmpDir;
    private UploadController $controller;
    private MemberPhotoService $memberPhotoService;
    private JournalRepository $journalRepo;
    private int $memberId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->tmpDir = sys_get_temp_dir() . '/scoutmagic_upload_ctrl_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $fileRepo = new FileRepository($this->pdo);
        $uploadHandler = new class($fileRepo, $this->tmpDir) extends UploadHandler {
            protected function moveFile(string $from, string $to): bool
            {
                return copy($from, $to);
            }
        };

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->memberPhotoService = new MemberPhotoService(new MemberPhotoRepository($this->pdo));
        $this->journalRepo = new JournalRepository($this->pdo);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');

        $this->controller = new UploadController($twig, $uploadHandler, $editableContentService, $this->memberPhotoService);
        $this->controller->setJournalService(new JournalService($this->journalRepo));

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];
        AuthSession::login(1, 'admin@test.com', 'admin');
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
        $_SESSION = [];
    }

    public function testMemberPhotoContextSetsPhotoForMemberAndYear(): void
    {
        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'photo.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/trombinoscope',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $fileId = $this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId);
        $this->assertNotNull($fileId);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'member_photo_updated'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    public function testMemberPhotoContextReplacesExistingPhoto(): void
    {
        $tmpFile1 = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile1, 'name' => 'first.jpg', 'size' => filesize($tmpFile1), 'error' => UPLOAD_ERR_OK];
        $request1 = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/trombinoscope',
        ], [], []);
        $this->controller->store($request1, []);
        $firstFileId = $this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId);

        $tmpFile2 = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile2, 'name' => 'second.jpg', 'size' => filesize($tmpFile2), 'error' => UPLOAD_ERR_OK];
        $request2 = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/trombinoscope',
        ], [], []);
        $this->controller->store($request2, []);
        $secondFileId = $this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId);

        $this->assertNotSame($firstFileId, $secondFileId);

        unset($_FILES['file']);
    }

    public function testEditableImageContextIsUnaffected(): void
    {
        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'hero.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'editable_image',
            'key' => 'home.hero',
            'return_url' => '/',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM editable_contents WHERE content_key = 'home.hero'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    private function createTempImage(): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'img');
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $tmpFile);
        imagedestroy($img);
        return $tmpFile;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
