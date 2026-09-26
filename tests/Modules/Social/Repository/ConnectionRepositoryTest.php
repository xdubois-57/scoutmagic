<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Repository;

use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\ConnectionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * The connection rows: secrets encrypted, kept or dropped at the right
 * moments.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ConnectionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ConnectionRepository $repository;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->repository = new ConnectionRepository($this->pdo, H::encryption());
        $this->now = new \DateTimeImmutable('2026-09-26 10:00:00');
    }

    public function testNoSecretIsStoredInTheClear(): void
    {
        $this->repository->saveCredentials(SocialPlatform::Facebook, '123456', 'APP-SECRET');
        $this->repository->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE-TOKEN', null, $this->now);

        $raw = (string) $this->pdo->query('SELECT secrets FROM social_connections')->fetchColumn();
        $this->assertStringNotContainsString('APP-SECRET', $raw);
        $this->assertStringNotContainsString('PAGE-TOKEN', $raw);

        $secrets = $this->repository->secretsOf(SocialPlatform::Facebook);
        $this->assertSame('APP-SECRET', $secrets->appSecret);
        $this->assertSame('PAGE-TOKEN', $secrets->accessToken);
    }

    public function testAnEmptySecretKeepsTheOneOnFile(): void
    {
        $this->repository->saveCredentials(SocialPlatform::Instagram, '123456', 'FIRST');
        $this->repository->saveCredentials(SocialPlatform::Instagram, '123456', null);

        $this->assertSame('FIRST', $this->repository->secretsOf(SocialPlatform::Instagram)->appSecret);
    }

    public function testAnotherAppDropsTheConnection(): void
    {
        $this->repository->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->repository->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE-TOKEN', null, $this->now);

        $this->repository->saveCredentials(SocialPlatform::Facebook, '999999', 'S2');

        $connection = $this->repository->find(SocialPlatform::Facebook);
        $this->assertNotNull($connection);
        $this->assertFalse($connection->isConnected());
        $this->assertNull($connection->accountName);
        $this->assertSame('', $this->repository->secretsOf(SocialPlatform::Facebook)->accessToken);
    }

    public function testChoosingAPageDropsTheHeldUserToken(): void
    {
        $this->repository->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->repository->holdPendingUserToken(SocialPlatform::Facebook, 'USER-TOKEN');
        $this->assertTrue($this->repository->find(SocialPlatform::Facebook)?->awaitsPageChoice);

        $this->repository->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE-TOKEN', null, $this->now);

        $this->assertSame('', $this->repository->secretsOf(SocialPlatform::Facebook)->pendingUserToken);
        $this->assertFalse($this->repository->find(SocialPlatform::Facebook)?->awaitsPageChoice);
    }

    public function testARenewalAndACheckAreDated(): void
    {
        $this->repository->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->repository->connect(SocialPlatform::Instagram, '9', 'unite25', 'T1', $this->now->modify('+60 days'), $this->now);
        $later = $this->now->modify('+7 days');

        $this->repository->recordRefresh(SocialPlatform::Instagram, 'T2', $later->modify('+60 days'), $later);
        $this->repository->recordCheck(SocialPlatform::Instagram, false, $later);

        $connection = $this->repository->find(SocialPlatform::Instagram);
        $this->assertNotNull($connection);
        $this->assertSame('T2', $this->repository->secretsOf(SocialPlatform::Instagram)->accessToken);
        $this->assertEquals($later, $connection->tokenRefreshedAt);
        $this->assertEquals($later->modify('+60 days'), $connection->tokenExpiresAt);
        $this->assertFalse($connection->checkOk);
        $this->assertFalse($connection->isUsable($later));
        $this->assertSame('unite25', $connection->accountName, 'A failed check keeps the name.');
    }

    public function testSecretsThatNoLongerDecryptReadAsNone(): void
    {
        $this->repository->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $other = new ConnectionRepository(
            $this->pdo,
            new \Core\Security\EncryptionService(str_repeat('c', 32), str_repeat('d', 32))
        );

        $connection = $other->find(SocialPlatform::Facebook);
        $this->assertNotNull($connection);
        $this->assertFalse($connection->hasAppSecret);
    }

    public function testDisconnectingForgetsEverything(): void
    {
        $this->repository->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->repository->delete(SocialPlatform::Facebook);

        $this->assertNull($this->repository->find(SocialPlatform::Facebook));
        $this->assertSame('', $this->repository->secretsOf(SocialPlatform::Facebook)->appSecret);
    }
}
