<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\MaintenanceGate;
use Core\Maintenance\UpdateHistory;
use Core\Maintenance\UpdateHistoryRepository;
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
}
