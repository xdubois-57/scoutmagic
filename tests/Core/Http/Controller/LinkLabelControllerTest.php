<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\LinkLabelController;
use Core\Http\LinkLabelFetchLogRepository;
use Core\Http\LinkLabelResolver;
use Core\Http\LinkPreviewCacheRepository;
use Core\Http\LinkPreviewService;
use Core\Http\Request;
use Core\Http\UrlMetadata;
use Core\Http\UrlMetadataFetcher;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * `POST /api/lien/libelle` from the browser's side (#840): a label or
 * null, a refusal without a token, and a 429 once the account's budget is
 * spent — never a reason why a page gave nothing (SECURITY.md §17).
 *
 * @group database
 */
#[Group('database')]
final class LinkLabelControllerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        AuthSession::login(7, 'chef@test.be', 'chief');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @param int $expectedFetches how many times the page may be read
     */
    private function controller(?UrlMetadata $page, int $expectedFetches = 1): LinkLabelController
    {
        $fetcher = $this->createMock(UrlMetadataFetcher::class);
        $fetcher->expects($this->exactly($expectedFetches))->method('fetch')->willReturn($page);
        $fetcher->expects($this->never())->method('fetchImageBytes');

        return new LinkLabelController(
            TestTwig::create(),
            new LinkLabelResolver(
                new LinkPreviewService($fetcher, new LinkPreviewCacheRepository($this->pdo)),
                new LinkLabelFetchLogRepository($this->pdo)
            )
        );
    }

    /**
     * A real Request with its raw body swapped, as HelpAssistantControllerTest
     * does: the CSRF guard reads this same object.
     *
     * @param array<string, string> $payload
     */
    private function request(array $payload, bool $withToken = true): Request
    {
        if ($withToken) {
            $payload['_csrf_token'] = CsrfGuard::generateToken();
        }

        return new class ('POST', '/api/lien/libelle', [], [], [], [], (string) json_encode($payload)) extends Request {
            /**
             * @param array<string, string> $query
             * @param array<string, mixed> $body
             * @param array<string, string> $cookies
             * @param array<string, mixed> $server
             */
            public function __construct(
                string $method,
                string $path,
                array $query,
                array $body,
                array $cookies,
                array $server,
                private string $raw
            ) {
                parent::__construct($method, $path, $query, $body, $cookies, $server);
            }

            public function getRawBody(): string
            {
                return $this->raw;
            }
        };
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testItAnswersThePageTitle(): void
    {
        $response = $this->controller(new UrlMetadata('Camp 2026', null, null, 'https://example.be/i.jpg'))
            ->suggest($this->request(['url' => 'https://example.be/camp']), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'label' => 'Camp 2026'], $this->decode($response->getBody()));
    }

    public function testAnUnreadablePageIsANullLabelAndNothingMore(): void
    {
        $response = $this->controller(null)->suggest($this->request(['url' => 'https://10.0.0.1/admin']), []);

        $this->assertSame(['success' => true, 'label' => null], $this->decode($response->getBody()));
    }

    public function testAnAddressWithNoPageIsNeverFetchedNorCounted(): void
    {
        $response = $this->controller(null, 0)->suggest($this->request(['url' => 'mailto:a@example.be']), []);

        $this->assertSame(['success' => true, 'label' => null], $this->decode($response->getBody()));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM link_label_fetch_log')->fetchColumn());
    }

    public function testItRefusesARequestWithoutItsToken(): void
    {
        $response = $this->controller(null, 0)->suggest($this->request(['url' => 'https://example.be/'], false), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testASpentBudgetIsA429ThatSaysWhatToDo(): void
    {
        $controller = $this->controller(null, 30);
        for ($i = 0; $i < 30; $i++) {
            $controller->suggest($this->request(['url' => 'https://example.be/page-' . $i]), []);
        }

        $response = $controller->suggest($this->request(['url' => 'https://example.be/one-more']), []);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('tapez le texte du lien vous-même', (string) $this->decode($response->getBody())['error']);
    }
}
