<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\ShortUrlController;
use Core\Http\Request;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ShortUrlControllerTest extends TestCase
{
    private \PDO $pdo;
    private ShortUrlController $controller;

    private \Core\Security\EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE short_urls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            target_url_encrypted BLOB NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
        )');

        $this->encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $service = new ShortUrlService(new ShortUrlRepository($this->pdo, $this->encryption));
        $this->controller = new ShortUrlController(new Environment(new ArrayLoader([])), $service);
    }

    public function testResolveRedirectsToTheStoredTargetUrl(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO short_urls (code, target_url_encrypted) VALUES (?, ?)');
        $stmt->execute(['abc123', $this->encryption->encrypt('/news/7', 'short_urls.target_url')]);

        $response = $this->controller->resolve(new Request('GET', '/s/abc123', [], [], [], []), ['code' => 'abc123']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/news/7', $response->getHeaders()['Location']);
    }

    public function testResolveReturns404ForUnknownCode(): void
    {
        $response = $this->controller->resolve(new Request('GET', '/s/zzzzzz', [], [], [], []), ['code' => 'zzzzzz']);

        $this->assertSame(404, $response->getStatusCode());
    }
}
