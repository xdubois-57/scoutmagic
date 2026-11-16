<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Attention\AttentionPoint;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Service\SupportIntakeAttentionProvider;
use Modules\SupportDashboard\Service\SupportIntakeGuard;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1113: a quota that refuses in silence reads, from the receiver,
 * exactly like a quiet day. Each refusal leaves a marker, and the
 * attention page says so for a day.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SupportIntakeAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SupportIntakeGuard $guard;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->guard = SupportDashboardTestHelper::intakeGuard($this->pdo, $this->encryption);
    }

    public function testNothingRefusedIsNothingToSay(): void
    {
        $this->assertSame([], $this->provider()->collect(1));
    }

    public function testARefusedRegistrationIsAnUrgentPoint(): void
    {
        $this->refuseRegistration();
        $this->refuseRegistration();

        $points = $this->provider()->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('Des tickets venant de nouvelles installations ont été refusés', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertStringStartsWith('2 fois', $points[0]->why);
        $this->assertSame('/support-dashboard', $points[0]->actionUrl);
    }

    public function testARefusedArchiveIsAPointOfItsOwn(): void
    {
        $this->refuseArchive();

        $points = $this->provider()->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('Des archives de diagnostic ont été refusées faute de place', $points[0]->title);
        $this->assertStringStartsWith('Une fois', $points[0]->why);
        $this->assertStringContainsString('5 Go en tout, 300 Mo par installation', $points[0]->why);
        $this->assertSame('/support-dashboard/tickets', $points[0]->actionUrl);
    }

    /** A day later the point is gone: it says what is happening, not what once did. */
    public function testARefusalOlderThanADayIsNoLongerShown(): void
    {
        $this->pdo->prepare('INSERT INTO support_report_rate_limits (ip_hash, created_at) VALUES (?, ?)')->execute([
            $this->encryption->blindIndex('support_quota:' . SupportIntakeGuard::QUOTA_NEW_INSTALLATIONS),
            (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->provider()->collect(1));
    }

    /** An address's own counter is never mistaken for a quota marker. */
    public function testAnAddressSpendingItsWindowRaisesNoPoint(): void
    {
        for ($i = 0; $i < SupportIntakeGuard::REQUESTS_PER_ADDRESS + 3; $i++) {
            $this->guard->admitRequest('203.0.113.1');
        }

        $this->assertSame([], $this->provider()->collect(1));
    }

    private function provider(): SupportIntakeAttentionProvider
    {
        return new SupportIntakeAttentionProvider($this->guard);
    }

    private function refuseRegistration(): void
    {
        $this->pdo->exec('DELETE FROM support_installations');
        $stmt = $this->pdo->prepare(
            "INSERT INTO support_installations (installation_id, secret_hash, first_seen_at, last_received_at)
             VALUES (?, 'x', ?, ?)"
        );
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        for ($i = 0; $i < SupportIntakeGuard::NEW_INSTALLATIONS_PER_HOUR; $i++) {
            $stmt->execute(['seed-installation-' . $i, $now, $now]);
        }

        $this->assertNull($this->guard->registerWithinQuota(static fn (): int => 1));
    }

    private function refuseArchive(): void
    {
        $result = $this->guard->storeArchiveWithinQuota(
            1,
            SupportIntakeGuard::ARCHIVE_QUOTA_PER_INSTALLATION_BYTES + 1,
            static function (): void {
                throw new \LogicException('an archive over the quota must not be stored');
            }
        );

        $this->assertSame(SupportIntakeGuard::QUOTA_ARCHIVE_STORAGE, $result);
    }
}
