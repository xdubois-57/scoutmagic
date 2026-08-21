<?php

declare(strict_types=1);

namespace Tests\Core\Url;

use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ShortUrlServiceTest extends TestCase
{
    private \PDO $pdo;
    private ShortUrlService $service;

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

        $this->service = new ShortUrlService(new ShortUrlRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))));
    }

    public function testCreateShortUrlReturnsA6CharCode(): void
    {
        $code = $this->service->createShortUrl('/news/12', 1);

        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{6}$/', $code);
    }

    public function testResolveReturnsTheStoredTargetUrl(): void
    {
        $code = $this->service->createShortUrl('/news/42', null);

        $this->assertSame('/news/42', $this->service->resolve($code));
    }

    public function testResolveReturnsNullForUnknownCode(): void
    {
        $this->assertNull($this->service->resolve('ZZZZZZ'));
    }

    public function testEachCallGeneratesADistinctCode(): void
    {
        $codes = [];
        for ($i = 0; $i < 20; $i++) {
            $codes[] = $this->service->createShortUrl('/news/' . $i, null);
        }

        $this->assertCount(20, array_unique($codes));
    }

    public function testTheTargetUrlIsNeverStoredInPlaintext(): void
    {
        // Callers shorten URLs that embed bearer tokens (/r/{token} retro
        // links, news edit links) — the stored target must not hand those
        // to a DB read.
        $secretTarget = '/r/' . bin2hex(random_bytes(32));
        $code = $this->service->createShortUrl($secretTarget, null);

        $row = $this->pdo->query('SELECT target_url_encrypted FROM short_urls')->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString($secretTarget, (string) $row['target_url_encrypted']);

        $this->assertSame($secretTarget, $this->service->resolve($code));
    }
}
