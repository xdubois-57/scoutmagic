<?php

declare(strict_types=1);

namespace Tests\Core\File\Held;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\Held\HeldDocumentRepository;
use Core\File\Held\HeldDocumentService;
use Core\File\Held\InstalledAppFileInterceptor;
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
 * The one place a file answer becomes a viewer page (issue #502), and the
 * three conditions that decide it: a navigation, from the installed
 * application, answered with a file. Everything else goes out untouched —
 * a browser tab, a fetch(), a page.
 *
 * The viewer template is replaced by a one-line stand-in here: what the
 * real page shows is DocumentViewerTemplateTest's business.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class InstalledAppFileInterceptorTest extends TestCase
{
    private const PDF = "%PDF-1.4\nfiche santé\n%%EOF";

    private \PDO $pdo;
    private string $storagePath;
    private InstalledAppFileInterceptor $interceptor;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/held_interceptor_test_' . bin2hex(random_bytes(4));
        $files = new FileRepository($this->pdo);
        $service = new HeldDocumentService(
            new HeldDocumentRepository($this->pdo),
            new EncryptedFileStorageService(
                $files,
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                $this->storagePath
            ),
            $files,
            new JournalService(new JournalRepository($this->pdo))
        );
        $twig = new Environment(new ArrayLoader([
            'document_viewer.html.twig' => 'VIEWER name={{ name }} type={{ type_label }} size={{ size_bytes }}'
                . ' back={{ back_url }} held={{ document is null ? "no" : document.browserPath ~ "," ~ document.appPath }}',
        ]));
        $this->interceptor = new InstalledAppFileInterceptor($service, $twig);
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

    public function testAnInstalledAppNavigationToAPdfAttachmentGetsTheViewer(): void
    {
        $out = $this->intercept($this->request('GET', '/trombinoscope/pdf'), $this->pdf());

        $this->assertStringStartsWith('VIEWER', $out->getBody());
        $this->assertStringContainsString('name=fiche.pdf', $out->getBody());
        $this->assertStringContainsString('type=Document PDF', $out->getBody());
        $this->assertStringContainsString('size=' . strlen(self::PDF), $out->getBody());
        $this->assertMatchesRegularExpression('~held=/document/[a-f0-9]{64},/document/telecharger/[a-f0-9]{64}~', $out->getBody());
        $this->assertSame('text/html; charset=utf-8', $out->getHeaders()['Content-Type']);
        $this->assertSame('no-store', $out->getHeaders()['Cache-Control']);
        $this->assertSame(1, $this->heldCount());
    }

    public function testAFormPostThatGeneratesAFileGetsTheViewer(): void
    {
        // The parental authorisation: a POST, a document that cannot be
        // asked for again — the reason the file is kept rather than re-fetched.
        $request = $this->request('POST', '/members/7/autorisation-parentale', ['HTTP_REFERER' => 'https://unit.test/members/7']);

        $out = $this->intercept($request, $this->pdf());

        $this->assertStringStartsWith('VIEWER', $out->getBody());
        $this->assertStringContainsString('back=/members/7', $out->getBody());
    }

    public function testTheSameRequestInABrowserTabGetsTheFileUnchanged(): void
    {
        $file = $this->pdf();
        $request = new Request('GET', '/trombinoscope/pdf', [], [], [], ['HTTP_SEC_FETCH_DEST' => 'document']);

        $this->assertSame($file, $this->intercept($request, $file));
        $this->assertSame(0, $this->heldCount());
    }

    public function testAFetchGetsTheFileUnchanged(): void
    {
        $file = $this->pdf();

        $this->assertSame($file, $this->intercept($this->request('GET', '/trombinoscope/pdf', ['HTTP_SEC_FETCH_DEST' => 'empty']), $file));
        $this->assertSame($file, $this->intercept($this->request('GET', '/files/3', ['HTTP_SEC_FETCH_DEST' => 'image']), $file));
        $this->assertSame(0, $this->heldCount());
    }

    public function testWithoutSecFetchDestAnAcceptAskingForHtmlIsANavigation(): void
    {
        $request = $this->request('GET', '/chefs/membres/export', [
            'HTTP_SEC_FETCH_DEST' => null,
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8',
        ]);
        $xlsx = (new Response('PK...'))
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $out = $this->intercept($request, $xlsx);

        $this->assertStringContainsString('type=Tableur', $out->getBody());
        $this->assertStringContainsString('name=export', $out->getBody(), 'No announced name: the last segment of the path.');
    }

    public function testAPageARedirectAndAnErrorAreNeverTouched(): void
    {
        $request = $this->request('GET', '/members/7');
        $page = (new Response('<html></html>'))->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $undeclared = new Response('<html></html>');
        $redirect = (new Response('', 302))->setHeader('Location', '/login');
        $refused = (new Response('Interdit', 403))->setHeader('Content-Type', 'text/plain');

        foreach ([$page, $undeclared, $redirect, $refused] as $response) {
            $this->assertSame($response, $this->intercept($request, $response));
        }
        $this->assertSame(0, $this->heldCount());
    }

    public function testTheHeldDocumentsOwnRoutesAreNeverIntercepted(): void
    {
        $file = $this->pdf();

        $this->assertSame($file, $this->intercept($this->request('GET', '/document/' . str_repeat('a', 64)), $file));
        $this->assertSame($file, $this->intercept($this->request('GET', '/document/telecharger/' . str_repeat('a', 64)), $file));
    }

    public function testAnIcsFromAPublicPageIsAFileToo(): void
    {
        $ics = (new Response("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"))
            ->setHeader('Content-Type', 'text/calendar; charset=utf-8');

        $out = $this->intercept($this->request('GET', '/locations/suivi/1/abc/calendrier.ics'), $ics);

        $this->assertStringContainsString('type=Évènement d&#039;agenda', $out->getBody());
        $this->assertStringContainsString('name=calendrier.ics', $out->getBody());
    }

    public function testAStreamedTemporaryFileIsHeldAndThenDeleted(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'held_zip_');
        file_put_contents($temporary, 'PK zip bytes');
        $zip = (new Response())
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="album.zip"')
            ->setBodyFile($temporary, true);

        $out = $this->intercept($this->request('GET', '/gallery/4/download'), $zip);

        $this->assertStringContainsString('name=album.zip', $out->getBody());
        $this->assertStringContainsString('type=Archive ZIP', $out->getBody());
        $this->assertFileDoesNotExist($temporary, 'The temporary file send() would have deleted was left behind.');
    }

    public function testAFileTooLargeToHoldStillNeverReachesTheWindow(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'held_big_');
        $handle = fopen($path, 'w');
        ftruncate($handle, HeldDocumentService::MAX_BYTES + 1);
        fclose($handle);
        $big = (new Response())
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="album.zip"')
            ->setBodyFile($path);

        try {
            $out = $this->intercept($this->request('GET', '/gallery/4/download'), $big);
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('held=no', $out->getBody());
        $this->assertSame(0, $this->heldCount());
    }

    public function testABackAddressFromAnotherSiteIsNotFollowed(): void
    {
        $out = $this->intercept(
            $this->request('GET', '/trombinoscope/pdf', ['HTTP_REFERER' => 'https://evil.test/members/7']),
            $this->pdf()
        );

        $this->assertStringContainsString('back=/ ', $out->getBody());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dispositions(): iterable
    {
        yield 'quoted' => ['attachment; filename="Fiche santé.pdf"', 'Fiche santé.pdf'];
        yield 'bare' => ['attachment; filename=fiche.pdf', 'fiche.pdf'];
        yield 'rfc 6266 wins' => ['attachment; filename="fiche.pdf"; filename*=UTF-8\'\'Fiche%20sant%C3%A9.pdf', 'Fiche santé.pdf'];
        yield 'no path' => ['attachment; filename="../../etc/passwd"', '.. .. etc passwd'];
        yield 'escaped quote' => ['attachment; filename="a\"b.pdf"', 'a"b.pdf'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dispositions')]
    public function testTheFileNameIsReadFromTheDisposition(string $disposition, string $expected): void
    {
        $response = (new Response('x'))->setHeader('Content-Disposition', $disposition);

        $this->assertSame($expected, InstalledAppFileInterceptor::fileNameOf($response, '/x'));
    }

    public function testTheRawRequestCheckMatchesWhatTheInterceptorDecides(): void
    {
        $cookie = [InstalledAppFileInterceptor::COOKIE => 'standalone'];

        $this->assertTrue(InstalledAppFileInterceptor::isInstalledAppNavigation(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/files/3?x=1', 'HTTP_SEC_FETCH_DEST' => 'document'],
            $cookie
        ));
        $this->assertFalse(InstalledAppFileInterceptor::isInstalledAppNavigation(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/files/3', 'HTTP_SEC_FETCH_DEST' => 'document'],
            [InstalledAppFileInterceptor::COOKIE => 'browser']
        ));
        $this->assertFalse(InstalledAppFileInterceptor::isInstalledAppNavigation(
            ['REQUEST_METHOD' => 'DELETE', 'REQUEST_URI' => '/files/3', 'HTTP_SEC_FETCH_DEST' => 'document'],
            $cookie
        ));
        $this->assertFalse(InstalledAppFileInterceptor::isInstalledAppNavigation(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/files/3', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_ACCEPT' => 'text/html'],
            $cookie
        ));
    }

    /**
     * The whole guarantee rests on one call in the tail of public/index.php
     * — nothing in a template asks for it. So its place is checked: after
     * the controller ran, before the response is sent, and ahead of it the
     * conditional headers dropped before the request is even built.
     */
    public function testThePublicIndexRunsTheInterceptorOnEveryResponse(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 4) . '/public/index.php');

        $stripped = strpos($index, 'InstalledAppFileInterceptor::isInstalledAppNavigation($_SERVER, $_COOKIE)');
        $built = strpos($index, '$request = Request::fromGlobals();');
        $guard = strpos($index, 'ErrorHandler::guard(');
        $intercepted = strpos($index, 'new \\Core\\File\\Held\\InstalledAppFileInterceptor(');
        $sent = strrpos($index, '$response->send();');

        $this->assertNotFalse($stripped, 'The conditional headers of an installed-app navigation are no longer dropped.');
        $this->assertNotFalse($intercepted, 'public/index.php no longer runs InstalledAppFileInterceptor.');
        $this->assertLessThan($built, $stripped);
        $this->assertGreaterThan($guard, $intercepted);
        $this->assertLessThan($sent, $intercepted);
    }

    private function intercept(Request $request, Response $response): Response
    {
        return $this->interceptor->intercept($request, $response, 'the-session', null, new \DateTimeImmutable('2026-09-27 10:00:00'));
    }

    /**
     * An installed-app navigation to $path; $server overrides the defaults.
     *
     * @param array<string, mixed> $server
     */
    private function request(string $method, string $path, array $server = []): Request
    {
        $server = array_merge(['HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_HOST' => 'unit.test'], $server);

        return new Request($method, $path, [], [], [InstalledAppFileInterceptor::COOKIE => 'standalone'], $server);
    }

    private function pdf(): Response
    {
        return (new Response(self::PDF))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="fiche.pdf"');
    }

    private function heldCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM held_documents')->fetchColumn();
    }
}
