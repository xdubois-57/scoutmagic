<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Task\AutoCreateRetroHandler;
use Modules\Retro\Repository\BoardRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AutoCreateRetroHandlerTest extends TestCase
{
    private \PDO $pdo;
    private CalendarEventRepository $eventRepository;
    private BoardRepository $boardRepository;
    private TaskContext $context;
    private int $calendarId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        RetroTestHelper::createTables($this->pdo);

        $this->pdo->exec("INSERT INTO calendar_calendars (name, visibility) VALUES ('Animateurs', 'public')");
        $this->calendarId = (int) $this->pdo->lastInsertId();

        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $this->boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        // Board creation now reaches the handler as a capability
        // (Modules\Retro\Api\RetroBoardCreationInterface, IT-08): the
        // registration below mirrors public/scheduler-bootstrap.php, and
        // TaskCapabilities' own live enablement re-check is what makes
        // the default context (retro not enabled) resolve it to null.
        $this->context = $this->buildContext(['calendar']);
    }

    /**
     * @param string[] $enabledModuleIds
     */
    private function buildContext(array $enabledModuleIds): TaskContext
    {
        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn($enabledModuleIds);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $capabilities = new \Core\Scheduler\TaskCapabilities($moduleManager);
        $capabilities->register(
            \Modules\Retro\Api\RetroBoardCreationInterface::class,
            'retro',
            fn (): object => new \Modules\Retro\Service\AutoBoardCreationService(
                $this->boardRepository,
                new SettingService(new SettingRepository($this->pdo)),
                new \Core\Scheduler\SchedulerService(new \Core\Scheduler\SchedulerRepository($this->pdo)),
                new JournalService(new JournalRepository($this->pdo))
            )
        );

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir(),
            null,
            $capabilities
        );
    }

    private function enableRetro(): void
    {
        $this->context = $this->buildContext(['calendar', 'retro']);
    }

    private function createEvent(bool $autoCreateRetro = true, ?string $endDate = '2026-08-10'): int
    {
        return $this->eventRepository->create(
            $this->calendarId, 'Camp d\'été', '2026-08-05', $endDate, null, null, null, null, null, $autoCreateRetro
        );
    }

    public function testCreatesABoardLinkedToTheEventWhenRetroIsEnabled(): void
    {
        $this->enableRetro();
        $eventId = $this->createEvent();

        (new AutoCreateRetroHandler())->handle(['event_id' => $eventId], $this->context);

        $board = $this->boardRepository->findByCalendarEventId($eventId);
        $this->assertNotNull($board);
        $this->assertSame('Rétrospective Camp d\'été - Animateurs - 2026-08-05', $board->title);
        $this->assertSame('2026-08-10', $board->boardDate);
    }

    public function testFallsBackToTheStartDateAsBoardDateWhenTheEventHasNoEndDate(): void
    {
        $this->enableRetro();
        $eventId = $this->createEvent(true, null);

        (new AutoCreateRetroHandler())->handle(['event_id' => $eventId], $this->context);

        $board = $this->boardRepository->findByCalendarEventId($eventId);
        $this->assertSame('2026-08-05', $board->boardDate);
    }

    public function testDoesNothingWhenRetroIsNotEnabled(): void
    {
        $eventId = $this->createEvent();

        (new AutoCreateRetroHandler())->handle(['event_id' => $eventId], $this->context);

        $this->assertNull($this->boardRepository->findByCalendarEventId($eventId));
    }

    public function testIsIdempotentWhenABoardIsAlreadyLinked(): void
    {
        $this->enableRetro();
        $eventId = $this->createEvent();
        $this->boardRepository->create(
            'Board manuel', '2026-08-10', $eventId, bin2hex(random_bytes(8)), null, true,
            'unlimited', 5, true, 'cookie', 140, 'none', null, 3
        );

        (new AutoCreateRetroHandler())->handle(['event_id' => $eventId], $this->context);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM retro_boards WHERE calendar_event_id = ' . $eventId)->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testIgnoresADeletedEvent(): void
    {
        $this->enableRetro();

        // Must not throw.
        (new AutoCreateRetroHandler())->handle(['event_id' => 999999], $this->context);
        $this->assertTrue(true);
    }

    public function testIgnoresAMissingEventIdInThePayload(): void
    {
        $this->enableRetro();

        (new AutoCreateRetroHandler())->handle([], $this->context);
        $this->assertTrue(true);
    }

    public function testLogsToTheJournal(): void
    {
        $this->enableRetro();
        $eventId = $this->createEvent();

        (new AutoCreateRetroHandler())->handle(['event_id' => $eventId], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_auto_created_from_event'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testSchedulesTheBoardsAutoCloseOnTheRetroSide(): void
    {
        $this->enableRetro();
        $eventId = $this->createEvent();

        (new AutoCreateRetroHandler())->handle(['event_id' => $eventId], $this->context);

        $board = $this->boardRepository->findByCalendarEventId($eventId);
        $this->assertNotNull($board);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'retro' AND task_key = 'auto_close_board' AND reference = ?"
        );
        $stmt->execute(['board_' . $board->id]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
