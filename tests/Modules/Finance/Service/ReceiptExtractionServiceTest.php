<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ReceiptExtractionServiceTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerService $schedulerService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
    }

    public function testIsAvailableFalseWhenConnectorIsNull(): void
    {
        $service = new ReceiptExtractionService($this->schedulerService, null);

        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableFalseWhenConnectorReportsUnavailable(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(false);

        $service = new ReceiptExtractionService($this->schedulerService, $connector);

        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableTrueWhenConnectorReportsAvailable(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);

        $service = new ReceiptExtractionService($this->schedulerService, $connector);

        $this->assertTrue($service->isAvailable());
    }

    public function testScheduleExtractionIsNoOpWhenConnectorIsNull(): void
    {
        $service = new ReceiptExtractionService($this->schedulerService, null);

        $service->scheduleExtraction(42);

        $this->assertNull($this->schedulerService->find('finance', 'extract_receipt_data', 'attachment-42'));
    }

    public function testScheduleExtractionIsNoOpWhenConnectorUnavailable(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(false);
        $connector->expects($this->never())->method('complete');

        $service = new ReceiptExtractionService($this->schedulerService, $connector);
        $service->scheduleExtraction(42);

        $this->assertNull($this->schedulerService->find('finance', 'extract_receipt_data', 'attachment-42'));
    }

    public function testScheduleExtractionSchedulesTaskWhenAvailable(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);

        $service = new ReceiptExtractionService($this->schedulerService, $connector);
        $service->scheduleExtraction(42);

        $scheduled = $this->schedulerService->find('finance', 'extract_receipt_data', 'attachment-42');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testExtractionNeverCallsCompleteItself(): void
    {
        // scheduleExtraction() only enqueues a task — the actual
        // LlmConnectorInterface::complete() call happens in
        // Task\ExtractReceiptDataHandler, never synchronously here.
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->expects($this->never())->method('complete');

        $service = new ReceiptExtractionService($this->schedulerService, $connector);
        $service->scheduleExtraction(1);

        $this->addToAssertionCount(1);
    }
}
