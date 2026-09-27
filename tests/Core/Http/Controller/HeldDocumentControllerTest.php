<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\Held\HeldDocument;
use Core\File\Held\HeldDocumentRepository;
use Core\File\Held\HeldDocumentService;
use Core\Http\Controller\HeldDocumentController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * GET /document/{token} and GET /document/telecharger/{token} (issue #502):
 * what they send, and that every refusal is the same bare 404.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HeldDocumentControllerTest extends TestCase
{
    private const SESSION = 'app-session';

    private \PDO $pdo;
    private string $storagePath;
    private HeldDocumentService $service;
    private string $sessionId = self::SESSION;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/held_controller_test_' . bin2hex(random_bytes(4));
        $files = new FileRepository($this->pdo);
        $this->service = new HeldDocumentService(
            new HeldDocumentRepository($this->pdo),
            new EncryptedFileStorageService(
                $files,
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                $this->storagePath
            ),
            $files,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->storagePath);
        }
    }

    public function testTheBrowserRouteServesTheFileInlineOnceAndNeverCached(): void
    {
        $held = $this->hold();

        $first = $this->controller()->browser($this->get(), ['token' => $held->browserToken]);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('%PDF fiche', $first->getBody());
        $headers = $first->getHeaders();
        $this->assertSame('application/pdf', $headers['Content-Type']);
        $this->assertSame('private, no-store', $headers['Cache-Control']);
        $this->assertStringStartsWith('inline; filename="Fiche sant_.pdf"', $headers['Content-Disposition']);
        $this->assertStringContainsString("filename*=UTF-8''Fiche%20sant%C3%A9.pdf", $headers['Content-Disposition']);

        $this->assertBareNotFound($this->controller()->browser($this->get(), ['token' => $held->browserToken]));
    }

    public function testTheBrowserRouteNeedsNoSession(): void
    {
        $held = $this->hold();
        $this->sessionId = '';

        $this->assertSame(200, $this->controller()->browser($this->get(), ['token' => $held->browserToken])->getStatusCode());
    }

    public function testTheApplicationRouteDownloadsForItsOwnSessionOnly(): void
    {
        $held = $this->hold();

        $mine = $this->controller()->download($this->get(), ['token' => $held->appToken]);
        $this->assertSame(200, $mine->getStatusCode());
        $this->assertStringStartsWith('attachment;', $mine->getHeaders()['Content-Disposition']);

        $this->sessionId = 'someone-else';
        $this->assertBareNotFound($this->controller()->download($this->get(), ['token' => $held->appToken]));
    }

    public function testUnknownMalformedAndMissingKeysAllAnswerTheSameBareNotFound(): void
    {
        $this->hold();

        $this->assertBareNotFound($this->controller()->browser($this->get(), ['token' => str_repeat('0', 64)]));
        $this->assertBareNotFound($this->controller()->browser($this->get(), ['token' => 'nope']));
        $this->assertBareNotFound($this->controller()->browser($this->get(), []));
        $this->assertBareNotFound($this->controller()->download($this->get(), ['token' => str_repeat('0', 64)]));
    }

    public function testAnExpiredBrowserKeyAnswersTheSameBareNotFound(): void
    {
        $held = $this->hold();
        $later = new \DateTimeImmutable('2026-09-27 10:05:00');

        $controller = new HeldDocumentController(
            new Environment(new ArrayLoader([])),
            $this->service,
            static fn(): \DateTimeImmutable => $later,
            fn(): string => $this->sessionId
        );

        $this->assertBareNotFound($controller->browser($this->get(), ['token' => $held->browserToken]));
    }

    private function hold(): HeldDocument
    {
        return $this->service->hold(
            '%PDF fiche',
            'application/pdf',
            'Fiche santé.pdf',
            self::SESSION,
            null,
            new \DateTimeImmutable('2026-09-27 10:00:00')
        );
    }

    private function controller(): HeldDocumentController
    {
        return new HeldDocumentController(
            new Environment(new ArrayLoader([])),
            $this->service,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-09-27 10:01:00'),
            fn(): string => $this->sessionId
        );
    }

    private function get(): Request
    {
        return new Request('GET', '/document/x', [], [], [], []);
    }

    private function assertBareNotFound(Response $response): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getBody());
        $this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
    }
}
