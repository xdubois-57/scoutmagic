<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Core\Security\EncryptionService;
use Modules\Retro\Repository\RateLimitRepository;
use Modules\Retro\Service\RateLimitService;
use Modules\Retro\Service\RetroException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RateLimitServiceTest extends TestCase
{
    private RateLimitService $service;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new RateLimitService(new RateLimitRepository($pdo), $encryption);
    }

    public function testIdentifierHashNeverReturnsTheRawCookieValue(): void
    {
        $hash = $this->service->identifierHash(null, 'my-cookie-value', 'session-id');

        $this->assertStringNotContainsString('my-cookie-value', $hash);
    }

    public function testIdentifierHashIsStableForTheSameCookie(): void
    {
        $a = $this->service->identifierHash(null, 'cookie-x', 'session-1');
        $b = $this->service->identifierHash(null, 'cookie-x', 'session-2');

        $this->assertSame($a, $b);
    }

    public function testIdentifierHashFallsBackToSessionIdWhenNoIpOrCookie(): void
    {
        $a = $this->service->identifierHash(null, null, 'session-a');
        $b = $this->service->identifierHash(null, null, 'session-b');

        $this->assertNotSame($a, $b);
    }

    public function testIdentifierHashKeysOnTheIpNotTheAttackerControlledCookie(): void
    {
        // Same IP, different cookie/session → same bucket: discarding the
        // cookie (or rotating the session) can no longer mint a fresh bucket
        // for the paid endpoints (audit M13).
        $a = $this->service->identifierHash('203.0.113.7', 'cookie-1', 'session-1');
        $b = $this->service->identifierHash('203.0.113.7', 'cookie-2', 'session-2');
        $this->assertSame($a, $b);

        // Different IP → different bucket.
        $c = $this->service->identifierHash('203.0.113.8', 'cookie-1', 'session-1');
        $this->assertNotSame($a, $c);

        // The IP wins over the cookie fallback entirely.
        $withoutCookie = $this->service->identifierHash('203.0.113.7', null, 'session-x');
        $this->assertSame($a, $withoutCookie);
    }

    public function testCheckAndRecordAllowsActionsUnderTheLimit(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');

        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        $this->assertTrue(true);
    }

    public function testCheckAndRecordThrowsPastTheLimit(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($hash, 'comment');
    }

    public function testCheckAndRecordThrownExceptionHasRateLimitedType(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        try {
            $this->service->checkAndRecord($hash, 'comment');
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_RATE_LIMITED, $e->type);
        }
    }

    public function testCommentAndVoteLimitsAreIndependent(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        // 'vote' has a separate, higher limit — must not be affected by
        // 'comment' having just been exhausted.
        $this->service->checkAndRecord($hash, 'vote');
        $this->assertTrue(true);
    }

    /**
     * checkAndRecord() falls back to PHP_INT_MAX for an action type that is
     * not in LIMITS, so adding a call site without adding the entry throttles
     * nothing at all. "shorten" backs a public, AI-billed route.
     */
    public function testShortenIsThrottled(): void
    {
        $hash = $this->service->identifierHash(null, null, 'session-shorten');

        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord($hash, 'shorten');
        }

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($hash, 'shorten');
    }

    public function testShortenLimitIsIndependentFromTheCommentLimit(): void
    {
        $hash = $this->service->identifierHash(null, null, 'session-mixed');

        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord($hash, 'shorten');
        }

        // Exhausting the shorten budget must not consume the comment budget.
        $this->service->checkAndRecord($hash, 'comment');

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($hash, 'shorten');
    }
}
