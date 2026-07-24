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

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE short_urls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            target_url TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
        )');

        $service = new ShortUrlService(new ShortUrlRepository($this->pdo));
        $this->controller = new ShortUrlController(new Environment(new ArrayLoader([])), $service);
    }

    public function testResolveRedirectsToTheStoredTargetUrl(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO short_urls (code, target_url) VALUES (?, ?)');
        $stmt->execute(['abc123', '/news/7']);

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
