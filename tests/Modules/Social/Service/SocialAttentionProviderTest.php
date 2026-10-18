<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Attention\AttentionPoint;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Service\SocialAttentionProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * Issue #718 § C point 3: a connection that expired or was refused at its
 * last check, one point per platform, read from what the check stored.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class SocialAttentionProviderTest extends TestCase
{
    private ConnectionRepository $connections;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
        $this->connections = new ConnectionRepository($pdo, H::encryption());
        $this->now = new \DateTimeImmutable('2026-10-18 10:00:00');
    }

    /** @return AttentionPoint[] */
    private function points(): array
    {
        return (new SocialAttentionProvider($this->connections, $this->now))->collect(1);
    }

    private function connect(SocialPlatform $platform, ?string $expiresAt = null): void
    {
        $this->connections->saveCredentials($platform, '123456', 'APP-SECRET');
        $this->connections->connect(
            $platform,
            '42',
            'Unité 25',
            'TOKEN',
            $expiresAt === null ? null : new \DateTimeImmutable($expiresAt),
            $this->now->modify('-30 days')
        );
    }

    public function testNoConnectionRaisesNothing(): void
    {
        $this->assertSame([], $this->points());
    }

    public function testAWorkingConnectionRaisesNothing(): void
    {
        $this->connect(SocialPlatform::Facebook, '2026-12-01 00:00:00');
        $this->connect(SocialPlatform::Instagram);

        $this->assertSame([], $this->points());
    }

    public function testAnExpiredAuthorisationIsAPointAskingToReconnect(): void
    {
        $this->connect(SocialPlatform::Instagram, '2026-10-17 00:00:00');

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame("L'autorisation d'Instagram a expiré", $points[0]->title);
        $this->assertStringContainsString('Meta refuse de renouveler', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('Reconnecter Instagram', $points[0]->actionLabel);
        $this->assertSame('/config/reseaux-sociaux', $points[0]->actionUrl);
    }

    public function testARefusedLastCheckIsAPoint(): void
    {
        $this->connect(SocialPlatform::Facebook);
        $this->connections->recordCheck(SocialPlatform::Facebook, false, $this->now);

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame('Meta refuse la connexion de la Page Facebook', $points[0]->title);
    }

    public function testAPassingCheckClearsTheRefusal(): void
    {
        $this->connect(SocialPlatform::Facebook);
        $this->connections->recordCheck(SocialPlatform::Facebook, false, $this->now->modify('-1 day'));
        $this->connections->recordCheck(SocialPlatform::Facebook, true, $this->now);

        $this->assertSame([], $this->points());
    }

    public function testOnePointPerPlatform(): void
    {
        $this->connect(SocialPlatform::Facebook, '2026-10-01 00:00:00');
        $this->connect(SocialPlatform::Instagram, '2026-10-01 00:00:00');

        $this->assertCount(2, $this->points());
    }

    /** Credentials saved but no account attached yet: not connected, nothing to say here. */
    public function testCredentialsWithoutAnAccountRaiseNothing(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'APP-SECRET');

        $this->assertSame([], $this->points());
    }
}
