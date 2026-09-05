<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Registration\Task\AutoAssignPassageHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The hourly pass that fills in the passage destinations nobody has to
 * choose — an animé whose next branch has exactly one visible section is
 * not a decision, and asking a chef to click it is asking a question
 * with one answer.
 *
 * The assignment rule itself belongs to `Service\PassageService`, and is
 * covered where it lives (Controller\PassageControllerTest). What was
 * covered nowhere — the handler was measured at 0 % — is the frame around
 * it, and the frame is what makes it a recurring task at all:
 *
 * - it re-arms itself for the next hour, exactly once, however many page
 *   loads happen to run it in the same second;
 * - it says something only when it did something, because a journal line
 *   every hour about a pass with nothing to do is the flood rather than
 *   the answer;
 * - it prepares next year's scout year rather than assuming somebody
 *   already created it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AutoAssignPassageHandlerTest extends TestCase
{
    private \PDO $pdo;
    private int $currentYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);

        $this->currentYearId = RegistrationTestHelper::insertScoutYear(
            $this->pdo,
            '2026-2027',
            '2026-09-01',
            '2027-08-31'
        );

        $settings = $this->settings();
        $settings->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->currentYearId,
            'text',
            'Année publique',
            'Test.'
        );
        $settings->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);
    }

    public function testTheNextHoursPassIsArmed(): void
    {
        (new AutoAssignPassageHandler())->handle([], $this->context());

        $this->assertSame(1, $this->queuedRuns());
    }

    public function testTheNextPassIsAnHourAwayNeitherSoonerNorLater(): void
    {
        // Both ends: a pass armed for five minutes hammers the section
        // tables all day, one armed for six hours stops being hourly —
        // and a lower bound alone would let the second through.
        $notAfter = (new \DateTimeImmutable('+70 minutes'))->format('Y-m-d H:i:s');

        (new AutoAssignPassageHandler())->handle([], $this->context());

        $runAt = (string) $this->pdo
            ->query("SELECT run_at FROM scheduled_actions WHERE task_key = 'auto_assign_passage'")
            ->fetchColumn();

        $this->assertGreaterThan(
            (new \DateTimeImmutable('+50 minutes'))->format('Y-m-d H:i:s'),
            $runAt
        );
        $this->assertLessThanOrEqual($notAfter, $runAt);
    }

    public function testTwoRunsInTheSameSecondArmOneSuccessor(): void
    {
        (new AutoAssignPassageHandler())->handle([], $this->context());
        (new AutoAssignPassageHandler())->handle([], $this->context());

        $this->assertSame(1, $this->queuedRuns(), 'Two visitors are not two passes.');
    }

    public function testAPassWithNothingObviousToAssignStaysSilent(): void
    {
        (new AutoAssignPassageHandler())->handle([], $this->context());

        $this->assertSame([], (new JournalRepository($this->pdo))->search());
    }

    /**
     * The destinations it fills in belong to NEXT year, which may not
     * exist yet the first time this runs.
     */
    public function testItPreparesNextYearRatherThanAssumingItExists(): void
    {
        (new AutoAssignPassageHandler())->handle([], $this->context());

        $labels = $this->pdo->query('SELECT label FROM scout_years ORDER BY label')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('2027-2028', $labels);
    }

    public function testTheYearItPreparesIsCreatedOnceNotOncePerPass(): void
    {
        (new AutoAssignPassageHandler())->handle([], $this->context());
        $this->pdo->exec('DELETE FROM scheduled_actions');
        (new AutoAssignPassageHandler())->handle([], $this->context());

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM scout_years WHERE label = '2027-2028'")
            ->fetchColumn();

        $this->assertSame(1, $count);
    }

    // ── harness ───────────────────────────────────────────────────────

    private function queuedRuns(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'auto_assign_passage'")
            ->fetchColumn();
    }

    private function settings(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings(),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }
}
