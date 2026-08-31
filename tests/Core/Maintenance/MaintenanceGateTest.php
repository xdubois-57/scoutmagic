<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\MaintenanceGate;
use Core\Maintenance\UpdateHistory;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Scheduler\SchedulerContinuationRoute;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;

class MaintenanceGateTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    private function inProgressHistory(): UpdateHistory
    {
        return new UpdateHistory(
            id: 1,
            versionFrom: '1.0.0',
            versionTo: 'dev-abc1234',
            status: 'installing',
            dependenciesChanged: false,
            errorMessage: null,
            backupId: null,
            requestedBy: null,
            startedAt: date('Y-m-d H:i:s'),
            completedAt: null
        );
    }

    /**
     * @return UpdateHistoryRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function repositoryReturning(?UpdateHistory $history): UpdateHistoryRepository
    {
        $repository = $this->createMock(UpdateHistoryRepository::class);
        $repository->method('findInProgress')->willReturn($history);
        return $repository;
    }

    public function testAllowsThroughWhenNoUpdateIsInProgress(): void
    {
        $gate = new MaintenanceGate($this->repositoryReturning(null));

        $this->assertNull($gate->checkBlocking(false));
    }

    public function testBlocksAnAnonymousVisitorDuringAnUpdate(): void
    {
        $this->startTestSession();
        $history = $this->inProgressHistory();
        $gate = new MaintenanceGate($this->repositoryReturning($history));

        $this->assertSame($history, $gate->checkBlocking(false));
    }

    public function testBlocksAnIdentifiedNonAdminVisitorDuringAnUpdate(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.com', 'chief');
        $history = $this->inProgressHistory();
        $gate = new MaintenanceGate($this->repositoryReturning($history));

        $this->assertSame($history, $gate->checkBlocking(false));
    }

    public function testAllowsAnAlreadyLoggedInSuperadminThrough(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'root@test.com', 'superadmin');
        $gate = new MaintenanceGate($this->repositoryReturning($this->inProgressHistory()));

        $this->assertNull($gate->checkBlocking(false));
    }

    public function testAdminRoleAloneIsNotEnoughToBypass(): void
    {
        // "admin" ("Chef d'Unité") is one level below "superadmin" — only
        // the top role gets the automatic bypass; everyone else, including
        // admin, needs the URL param to get through a stuck update.
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.com', 'admin');
        $history = $this->inProgressHistory();
        $gate = new MaintenanceGate($this->repositoryReturning($history));

        $this->assertSame($history, $gate->checkBlocking(false));
    }

    public function testTheUrlBypassLetsAnUnauthenticatedVisitorThrough(): void
    {
        $this->startTestSession();
        $gate = new MaintenanceGate($this->repositoryReturning($this->inProgressHistory()));

        $this->assertNull($gate->checkBlocking(true));
    }

    /**
     * The regression that cost six updates in forty-eight hours on
     * scoutmagic.be: Task\InstallUpdateHandler sets the status to
     * 'migrating' and hops onto this route so another process finishes the
     * job, and the gate answered that hop with the maintenance page —
     * because the row it gates on is the very update the hop came to
     * advance. Nothing else was going to run it: the poor man's cron at
     * the end of public/index.php is throttled to once a minute and had
     * just been stamped by the pass that ran the install.
     */
    public function testTheSchedulerContinuationEndpointIsNeverGated(): void
    {
        $this->startTestSession();
        $gate = new MaintenanceGate($this->repositoryReturning($this->inProgressHistory()));

        $this->assertNull($gate->checkBlocking(false, SchedulerContinuationRoute::PATH));
    }

    public function testTheContinuationExemptionDoesNotLeakToOtherPaths(): void
    {
        $this->startTestSession();
        $history = $this->inProgressHistory();
        $gate = new MaintenanceGate($this->repositoryReturning($history));

        $this->assertSame($history, $gate->checkBlocking(false, '/api/scheduler'));
        $this->assertSame($history, $gate->checkBlocking(false, '/'));
    }

    /**
     * findInProgress() marks a stale row failed as a side effect. The hop
     * must not be what triggers that on the update it is finishing, so the
     * exemption is checked before the repository is asked anything at all.
     */
    public function testTheExemptedRouteNeverAsksTheRepository(): void
    {
        $this->startTestSession();
        $repository = $this->createMock(UpdateHistoryRepository::class);
        $repository->expects($this->never())->method('findInProgress');

        $this->assertNull(
            (new MaintenanceGate($repository))->checkBlocking(false, SchedulerContinuationRoute::PATH)
        );
    }
}
