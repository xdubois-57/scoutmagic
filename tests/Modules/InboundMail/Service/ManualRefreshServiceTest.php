<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\ManualRefreshService;
use Modules\InboundMail\Service\SyncReport;
use PHPUnit\Framework\TestCase;

final class ManualRefreshServiceTest extends TestCase
{
    public function testTheManualOriginAndActorReachTheSyncService(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 10:00:00');
        $sync = $this->createMock(MailboxSyncService::class);
        $report = new SyncReport();
        $sync->expects(self::once())->method('syncAll')
            ->with($now, MailboxSyncService::ORIGIN_MANUAL, 42)->willReturn($report);
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturn('');
        $repository = $this->createMock(SettingRepository::class);
        $repository->expects(self::exactly(2))->method('updateValue');
        $service = new ManualRefreshService(static fn(): MailboxSyncService => $sync, $settings, $repository);

        self::assertSame(ManualRefreshService::summarise($report), $service->refresh($now, 42));
    }

    public function testAnExistingLockNeverBuildsASyncOrJournalsAMailboxError(): void
    {
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturn('2026-11-21 10:00:00');
        $repository = $this->createMock(SettingRepository::class);
        $repository->expects(self::never())->method('updateValue');
        $service = new ManualRefreshService(static function (): MailboxSyncService {
            self::fail('A locked refresh must not open a mailbox.');
        }, $settings, $repository);

        $result = $service->refresh(new \DateTimeImmutable('2026-11-21 10:01:00'), 42);
        self::assertFalse($result['ok']);
        self::assertSame('warning', $result['level']);
    }
}
