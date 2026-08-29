<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Service\AutoBoardCreationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AutoBoardCreationServiceTest extends TestCase
{
    private \PDO $pdo;
    private BoardRepository $boardRepository;
    private SettingService $settingService;
    private AutoBoardCreationService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);

        $this->boardRepository = new BoardRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->service = new AutoBoardCreationService(
            $this->boardRepository,
            $this->settingService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    public function testCreatesAChiefVisibleListedBoardDatedTheEventsLastDay(): void
    {
        $boardId = $this->service->createBoardForEvent(42, "Camp d'été", 'Baladins', '2026-08-05', '2026-08-10');

        $this->assertNotNull($boardId);
        $board = $this->boardRepository->findById($boardId);
        $this->assertNotNull($board);
        $this->assertSame("Rétrospective Camp d'été - Baladins - 2026-08-05", $board->title);
        $this->assertSame('2026-08-10', $board->boardDate);
        $this->assertSame(42, $board->calendarEventId);
        $this->assertSame('chief', $board->linkVisibility);
        $this->assertTrue($board->listed);
        $this->assertNull($board->createdBy);
    }

    public function testSingleDayEventBoardIsDatedTheStartDate(): void
    {
        $boardId = $this->service->createBoardForEvent(42, 'Réunion', 'Animateurs', '2026-08-05', null);

        $this->assertSame('2026-08-05', $this->boardRepository->findById((int) $boardId)?->boardDate);
    }

    public function testRefusesASecondBoardForTheSameEvent(): void
    {
        $first = $this->service->createBoardForEvent(42, 'Camp', 'Baladins', '2026-08-05', null);
        $second = $this->service->createBoardForEvent(42, 'Camp', 'Baladins', '2026-08-05', null);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM retro_boards WHERE calendar_event_id = 42')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testSchedulesTheAutoCloseUnderTheBoardReference(): void
    {
        $boardId = $this->service->createBoardForEvent(42, 'Camp', 'Baladins', '2026-08-05', null);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'retro' AND task_key = 'auto_close_board' AND reference = ?"
        );
        $stmt->execute(['board_' . $boardId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
        $this->assertSame('7d', $this->boardRepository->findById((int) $boardId)?->autoCloseDelay);
    }

    public function testUsesTheUnitsConfiguredDefaults(): void
    {
        $this->settingService->register('retro_default_max_comment_length', '140', 'number', 'L', 'd', 'retro');
        $this->settingService->register('retro_default_vote_budget', '5', 'number', 'B', 'd', 'retro');
        $this->settingService->set('retro_default_max_comment_length', '180', 'retro');
        $this->settingService->set('retro_default_vote_budget', '8', 'retro');

        $boardId = $this->service->createBoardForEvent(42, 'Camp', 'Baladins', '2026-08-05', null);

        $board = $this->boardRepository->findById((int) $boardId);
        $this->assertSame(180, $board?->maxCommentLength);
        $this->assertSame(8, $board?->voteBudget);
    }

    public function testJournalsTheCreationWithoutAnActor(): void
    {
        $this->service->createBoardForEvent(42, 'Camp', 'Baladins', '2026-08-05', null);

        $row = $this->pdo->query(
            "SELECT user_account_id FROM event_log WHERE event_type = 'board_auto_created_from_event'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertNull($row['user_account_id']);
    }
}
