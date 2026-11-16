<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Database\AdvisoryLock;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\SupportIntakeGuard;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1113, on the production engine: the two quotas count with date
 * comparisons and a JOIN, and check-then-write under named advisory locks
 * — neither of which SQLite exercises (its AdvisoryLock always succeeds).
 * A second session on the same database plays the concurrent request.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SupportIntakeGuardOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private ?\PDO $requester = null;
    private ?\PDO $holder = null;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

        $this->requester = $this->productionEngine();
        $this->holder = self::productionEngineConnection(
            (string) $this->requester->query('SELECT DATABASE()')->fetchColumn()
        )->getPdo();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    protected function tearDown(): void
    {
        if ($this->holder instanceof \PDO) {
            AdvisoryLock::release($this->holder, SupportIntakeGuard::REGISTRATION_LOCK);
            AdvisoryLock::release($this->holder, SupportIntakeGuard::ARCHIVE_LOCK);
        }
        $this->holder = null;
        $this->requester = null;
    }

    /** The quota reads first_seen_at against a PHP cutoff, which register() writes from PHP too. */
    public function testRegistrationsAreCountedOverTheWindowOnly(): void
    {
        $installations = new SupportInstallationRepository($this->pdo());
        $installations->register('recente-00000001', 'x', '', []);
        $installations->register('recente-00000002', 'x', '', []);
        $this->pdo()->exec(
            "INSERT INTO support_installations (installation_id, secret_hash, first_seen_at, last_received_at)
             VALUES ('ancienne-0000001', 'x', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY)"
        );

        $since = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->assertSame(2, $installations->countFirstSeenSince($since));
    }

    /**
     * A first ticket registers its installation with no report, which the
     * JSON column must accept: an empty string there is refused by both
     * production engines, and every first-ticket registration failed.
     */
    public function testARowWithoutAReportCanBeRegistered(): void
    {
        $installations = new SupportInstallationRepository($this->pdo());

        $rowId = $installations->register('premier-ticket-01', 'x', '', [], false, false);

        $row = $installations->findById($rowId);
        $this->assertNotNull($row);
        $this->assertNull($row['payload']);
        $this->assertSame(0, (int) $row['established']);
    }

    /** A new id arriving while another is being registered is refused, and nothing is written. */
    public function testARegistrationIsRefusedWhileAnotherIsInProgress(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holderPdo(), SupportIntakeGuard::REGISTRATION_LOCK));
        $ran = false;

        $result = $this->guard()->registerWithinQuota(function () use (&$ran): int {
            $ran = true;

            return 1;
        });

        $this->assertNull($result);
        $this->assertFalse($ran);
        $this->assertSame(0, $this->guard()->quotaRefusalsSince(SupportIntakeGuard::QUOTA_NEW_INSTALLATIONS, 60));
    }

    /** The lock lives for the registration only: afterwards another session can take it. */
    public function testTheRegistrationLockIsReleasedWhenItReturns(): void
    {
        $this->assertSame(7, $this->guard()->registerWithinQuota(static fn (): int => 7));

        $this->assertTrue(AdvisoryLock::acquire($this->holderPdo(), SupportIntakeGuard::REGISTRATION_LOCK));
    }

    /** The archives' size is the stored files', joined on the real `files` table. */
    public function testArchiveBytesSumTheFilesStillAttached(): void
    {
        $installations = new SupportInstallationRepository($this->pdo());
        $tickets = new SupportTicketRepository($this->pdo(), $this->encryption);
        $mine = $installations->register('archives-0000001', 'x', '', []);
        $other = $installations->register('archives-0000002', 'x', '', []);

        $this->attachArchiveOf($tickets, $mine, 1000);
        $this->attachArchiveOf($tickets, $mine, 500);
        $this->attachArchiveOf($tickets, $other, 250);

        $this->assertSame(1500, $tickets->archiveBytes($mine));
        $this->assertSame(1750, $tickets->archiveBytes());
    }

    /** An archive arriving while another is being stored is told « busy », not « full ». */
    public function testAnArchiveIsHeldBackWhileAnotherIsBeingStored(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holderPdo(), SupportIntakeGuard::ARCHIVE_LOCK));

        $result = $this->guard()->storeArchiveWithinQuota(1, 10, static function (): void {
            throw new \LogicException('nothing may be stored while the lock is held elsewhere');
        });

        $this->assertSame('busy', $result);
        $this->assertSame(0, $this->guard()->quotaRefusalsSince(SupportIntakeGuard::QUOTA_ARCHIVE_STORAGE, 60));
    }

    private function attachArchiveOf(SupportTicketRepository $tickets, int $installationRowId, int $bytes): void
    {
        $reference = $tickets->create(
            $installationRowId,
            TicketCategory::of('desk_import'),
            'Une description.',
            'chef@unite.be',
            '1.0.33',
            '8.4.3'
        );
        $this->pdo()->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
             VALUES (?, 'support.zip', 'application/zip', ?, 'superadmin')"
        )->execute(['support-tickets/' . $reference . '.zip', $bytes]);
        $fileId = (int) $this->pdo()->lastInsertId();
        $tickets->attachArchive((int) ($tickets->findByReference($reference)['id'] ?? 0), $fileId);
    }

    private function guard(): SupportIntakeGuard
    {
        return new SupportIntakeGuard(
            $this->pdo(),
            new SupportReportRateLimitRepository($this->pdo()),
            new SupportInstallationRepository($this->pdo()),
            new SupportTicketRepository($this->pdo(), $this->encryption),
            $this->encryption
        );
    }

    private function pdo(): \PDO
    {
        \assert($this->requester instanceof \PDO);

        return $this->requester;
    }

    private function holderPdo(): \PDO
    {
        \assert($this->holder instanceof \PDO);

        return $this->holder;
    }
}
