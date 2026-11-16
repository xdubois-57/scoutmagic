<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\Support\RequestExposingFederalScaleLookupService;
use Tests\Modules\LlmConnector\StubHttpServer;

/**
 * The request itself, against a real local HTTP server (#1281).
 *
 * {@see FederalScaleLookupServiceTest} replaces the network everywhere, which
 * is what makes it fast — and leaves the few lines that open the connection
 * unexecuted. Those lines carry decisions that matter: only a 200 is a page,
 * a redirect is never followed, an empty answer is no answer.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FederalScaleLookupRequestTest extends TestCase
{
    private RequestExposingFederalScaleLookupService $service;
    private ?StubHttpServer $server = null;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new RequestExposingFederalScaleLookupService(
            null,
            new SettingService(new SettingRepository($pdo)),
            new JournalService(new JournalRepository($pdo))
        );
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testAPageAnsweredWith200IsReturnedAndTheRequestIsAnHtmlGet(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 200, 'body' => '<html>cotisations</html>', 'headers' => ['Content-Type: text/html']],
        ]);

        $body = $this->service->request($this->server->baseUrl() . '/cotisations');

        $this->assertSame('<html>cotisations</html>', $body);
        $request = $this->server->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/cotisations', $request['uri']);
        $this->assertSame('text/html', $request['headers']['HTTP_ACCEPT'] ?? null);
        $this->assertNotSame('', $request['headers']['HTTP_USER_AGENT'] ?? '');
    }

    public function testAnythingButA200IsNotAPage(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 404, 'body' => '<html>introuvable</html>', 'headers' => ['Content-Type: text/html']],
        ]);

        $this->assertNull($this->service->request($this->server->baseUrl() . '/cotisations'));
    }

    public function testARedirectIsNotFollowed(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 301, 'body' => 'moved', 'headers' => ['Location: /ailleurs', 'Content-Type: text/html']],
            ['status' => 200, 'body' => '<html>ailleurs</html>', 'headers' => ['Content-Type: text/html']],
        ]);

        $this->assertNull($this->service->request($this->server->baseUrl() . '/cotisations'));
        $this->assertCount(1, $this->server->requests(), 'The redirect target must never be requested.');
    }

    public function testAnEmptyAnswerIsNoPage(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 200, 'body' => '', 'headers' => ['Content-Type: text/html']],
        ]);

        $this->assertNull($this->service->request($this->server->baseUrl() . '/cotisations'));
    }

    public function testARefusedConnectionIsNoPage(): void
    {
        $this->server = StubHttpServer::start([['status' => 200, 'body' => 'x']]);
        $url = $this->server->baseUrl() . '/cotisations';
        $this->server->stop();
        $this->server = null;

        $this->assertNull($this->service->request($url));
    }
}
