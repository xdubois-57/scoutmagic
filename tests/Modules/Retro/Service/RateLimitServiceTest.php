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
        $hash = $this->service->identifierHash('my-cookie-value', 'session-id');

        $this->assertStringNotContainsString('my-cookie-value', $hash);
    }

    public function testIdentifierHashIsStableForTheSameCookie(): void
    {
        $a = $this->service->identifierHash('cookie-x', 'session-1');
        $b = $this->service->identifierHash('cookie-x', 'session-2');

        $this->assertSame($a, $b);
    }

    public function testIdentifierHashFallsBackToSessionIdWhenNoCookie(): void
    {
        $a = $this->service->identifierHash(null, 'session-a');
        $b = $this->service->identifierHash(null, 'session-b');

        $this->assertNotSame($a, $b);
    }

    public function testCheckAndRecordAllowsActionsUnderTheLimit(): void
    {
        $hash = $this->service->identifierHash('cookie', 'session');

        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        $this->assertTrue(true);
    }

    public function testCheckAndRecordThrowsPastTheLimit(): void
    {
        $hash = $this->service->identifierHash('cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($hash, 'comment');
    }

    public function testCheckAndRecordThrownExceptionHasRateLimitedType(): void
    {
        $hash = $this->service->identifierHash('cookie', 'session');
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
        $hash = $this->service->identifierHash('cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        // 'vote' has a separate, higher limit — must not be affected by
        // 'comment' having just been exhausted.
        $this->service->checkAndRecord($hash, 'vote');
        $this->assertTrue(true);
    }
}
