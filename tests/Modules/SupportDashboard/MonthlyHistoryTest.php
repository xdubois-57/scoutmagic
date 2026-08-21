<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportMonthlyAggregateRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\SupportDashboardFilters;
use Modules\SupportDashboard\Service\SupportDashboardService;
use Modules\SupportDashboard\Service\SupportHistoryPeriod;
use Modules\SupportDashboard\Task\FinalizeMonthlyAggregateHandler;
use Modules\SupportDashboard\Task\PurgeInstallationsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * IT-11: monthly contributions, finalisation, immutability, and the
 * history section's independence from the current-state filters.
 */
class MonthlyHistoryTest extends TestCase
{
    private \PDO $pdo;
    private SupportMonthlyAggregateRepository $aggregates;
    private SupportInstallationRepository $installations;
    private SettingService $settings;
    private TaskContext $context;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->aggregates = new SupportMonthlyAggregateRepository($this->pdo);
        $this->installations = new SupportInstallationRepository($this->pdo);

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('support_retention_months', '6', 'number', 'L', 'D', 'support_dashboard');
        $this->settings->clearCache();

        $this->storagePath = sys_get_temp_dir() . '/monthly_history_' . uniqid();
        mkdir($this->storagePath . '/keys', 0o777, true);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            new MailService('local', 'u@e.be', 'Unité', 'EX', new DkimManager($this->storagePath . '/keys'), 's1'),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    private static function monthsAgo(int $months): string
    {
        return (new \DateTimeImmutable('first day of this month'))
            ->modify('-' . $months . ' months')
            ->format('Y-m');
    }

    // --- contributions ---

    public function testThreeReportsFromOneInstallationInOneMonthAreOneContribution(): void
    {
        $month = self::monthsAgo(1);

        $this->aggregates->recordContribution($month, 'aaaa');
        $this->aggregates->recordContribution($month, 'aaaa');
        $this->aggregates->recordContribution($month, 'aaaa');

        $this->assertSame(1, $this->aggregates->countContributions($month));
    }

    public function testDistinctInstallationsEachCountOnce(): void
    {
        $month = self::monthsAgo(1);
        foreach (['aaaa', 'bbbb', 'cccc'] as $id) {
            $this->aggregates->recordContribution($month, $id);
            $this->aggregates->recordContribution($month, $id);
        }

        $this->assertSame(3, $this->aggregates->countContributions($month));
    }

    public function testAnAcceptedReportRecordsAContributionForTheCurrentMonth(): void
    {
        $intake = new StatisticsIntakeService(
            $this->installations,
            new SupportReportRateLimitRepository($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new JournalService(new JournalRepository($this->pdo)),
            $this->aggregates
        );

        $payload = [
            'statistics_schema_version' => 1,
            'installation_id' => 'aaaabbbbccccdddd',
            'instance_url' => 'https://unite.example.be',
        ];
        $body = (string) json_encode($payload);

        // First registration, then a second report from the same sender.
        $intake->receive($body, 'Bearer ' . str_repeat('f', 64), '203.0.113.1', true);
        $intake->receive($body, 'Bearer ' . str_repeat('f', 64), '203.0.113.1', true);

        $this->assertSame(
            1,
            $this->aggregates->countContributions((new \DateTimeImmutable())->format('Y-m')),
            'two reports in one month are one contribution'
        );
    }

    // --- finalisation ---

    public function testFinalizingAnEndedMonthProducesAnAggregate(): void
    {
        $month = self::monthsAgo(1);
        $this->aggregates->recordContribution($month, 'aaaa');
        $this->aggregates->recordContribution($month, 'bbbb');

        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        $aggregate = $this->aggregates->find($month);
        $this->assertNotNull($aggregate);
        $this->assertSame(2, (int) $aggregate['installation_count']);
    }

    public function testFinalizationDropsThatMonthsContributionRows(): void
    {
        $month = self::monthsAgo(1);
        $this->aggregates->recordContribution($month, 'aaaa');

        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        // The hard requirement: a finalised aggregate holds no individual
        // identifier — not in the aggregate, and not left behind next to it.
        $this->assertSame(0, $this->aggregates->countContributions($month));
        $remaining = (string) json_encode(
            $this->pdo->query('SELECT * FROM support_monthly_contributions')->fetchAll(\PDO::FETCH_ASSOC)
        );
        $this->assertStringNotContainsString('aaaa', $remaining);

        $aggregate = (string) json_encode($this->aggregates->find($month));
        $this->assertStringNotContainsString('aaaa', $aggregate);
        $this->assertStringNotContainsString('example.be', $aggregate);
    }

    public function testASecondFinalizationOfTheSameMonthHasNoEffect(): void
    {
        $month = self::monthsAgo(1);
        $this->aggregates->recordContribution($month, 'aaaa');

        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);
        $first = $this->aggregates->find($month);

        // A late report for an already-closed month must not reopen it.
        $this->aggregates->recordContribution($month, 'bbbb');
        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        $second = $this->aggregates->find($month);
        $this->assertSame($first['installation_count'], $second['installation_count']);
        $this->assertSame($first['finalized_at'], $second['finalized_at']);
        $this->assertFalse($this->aggregates->finalize($month));
    }

    public function testTheCurrentMonthIsNeverFinalized(): void
    {
        $current = (new \DateTimeImmutable())->format('Y-m');
        $this->aggregates->recordContribution($current, 'aaaa');

        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        // A month still running can still gain contributors, and an
        // aggregate is immutable once written.
        $this->assertNull($this->aggregates->find($current));
        $this->assertSame(1, $this->aggregates->countContributions($current));
    }

    public function testSeveralConsecutiveMissedMonthsAreCaughtUpInOneRun(): void
    {
        foreach ([1, 2, 3] as $offset) {
            $this->aggregates->recordContribution(self::monthsAgo($offset), 'inst' . $offset);
        }

        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        foreach ([1, 2, 3] as $offset) {
            $this->assertNotNull(
                $this->aggregates->find(self::monthsAgo($offset)),
                'a receiver idle for a quarter closes every ended month on its next run'
            );
        }
    }

    public function testItAlwaysReschedulesItself(): void
    {
        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        $this->assertNotNull(
            (new SchedulerService(new SchedulerRepository($this->pdo)))
                ->find('support_dashboard', FinalizeMonthlyAggregateHandler::TASK_KEY, FinalizeMonthlyAggregateHandler::REFERENCE)
        );
    }

    // --- history never rewritten ---

    public function testDeletingAnInstallationLeavesAFinalizedAggregateUntouched(): void
    {
        $month = self::monthsAgo(1);
        $this->aggregates->recordContribution($month, 'aaaa');
        $this->aggregates->recordContribution($month, 'bbbb');
        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        $payload = ['statistics_schema_version' => 1, 'installation_id' => 'aaaa'];
        $id = $this->installations->register('aaaa', password_hash('s', PASSWORD_DEFAULT), (string) json_encode($payload), StatisticsIntakeService::denormalize($payload));
        $this->installations->delete($id);

        $this->assertSame(2, (int) $this->aggregates->find($month)['installation_count']);
    }

    public function testRetentionLeavesAFinalizedAggregateUntouched(): void
    {
        $month = self::monthsAgo(1);
        $this->aggregates->recordContribution($month, 'aaaa');
        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);

        $payload = ['statistics_schema_version' => 1, 'installation_id' => 'aaaa'];
        $id = $this->installations->register('aaaa', password_hash('s', PASSWORD_DEFAULT), (string) json_encode($payload), StatisticsIntakeService::denormalize($payload));
        $this->pdo->prepare('UPDATE support_installations SET last_received_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable('-9 months'))->format('Y-m-d H:i:s'), $id]);

        (new PurgeInstallationsHandler())->handle([], $this->context);

        $this->assertNull($this->installations->findById($id), 'the installation is gone');
        $this->assertSame(1, (int) $this->aggregates->find($month)['installation_count'], 'the history is not');
    }

    // --- the history section ---

    private function service(): SupportDashboardService
    {
        return new SupportDashboardService($this->installations, $this->settings, $this->aggregates);
    }

    private function finalizeMonths(int ...$offsets): void
    {
        foreach ($offsets as $offset) {
            $this->aggregates->recordContribution(self::monthsAgo($offset), 'inst' . $offset);
        }
        (new FinalizeMonthlyAggregateHandler())->handle([], $this->context);
    }

    public function testTheDefaultPeriodIsTwelveMonths(): void
    {
        $this->assertSame(12, SupportHistoryPeriod::default()->months);
        $this->assertSame(12, SupportHistoryPeriod::fromQuery(['history' => 'nonsense'])->months);
        $this->assertSame(6, SupportHistoryPeriod::fromQuery(['history' => '6'])->months);
        $this->assertSame(24, SupportHistoryPeriod::fromQuery(['history' => '24'])->months);
        $this->assertNull(SupportHistoryPeriod::fromQuery(['history' => 'all'])->months, 'all means the whole history');
    }

    public function testThePeriodCutsTheSeriesAndAllKeepsEverything(): void
    {
        $this->finalizeMonths(1, 2, 3, 4, 5, 6, 7, 8);

        $this->assertCount(6, $this->service()->buildHistory(SupportHistoryPeriod::fromQuery(['history' => '6']))['series']);
        $this->assertCount(8, $this->service()->buildHistory(SupportHistoryPeriod::fromQuery(['history' => 'all']))['series']);
        $this->assertCount(8, $this->service()->buildHistory(SupportHistoryPeriod::default())['series'], '8 months is inside a 12-month window');
    }

    public function testTheSeriesIsOldestFirst(): void
    {
        $this->finalizeMonths(3, 1, 2);

        $months = array_column($this->service()->buildHistory(SupportHistoryPeriod::default())['series'], 'month');
        $sorted = $months;
        sort($sorted);

        $this->assertSame($sorted, $months);
    }

    public function testTheHistoryDoesNotMoveWhenTheCurrentStateFiltersDo(): void
    {
        $this->finalizeMonths(1, 2, 3);

        $baseline = $this->service()->buildHistory(SupportHistoryPeriod::default())['series'];

        // Every current-state filter, search and sort at once. None of them
        // is even an argument to buildHistory() — that is the point.
        $noisy = SupportDashboardFilters::fromQuery([
            'q' => 'nothing-matches-this',
            'status' => SupportDashboardFilters::STATUS_STALE,
            'version' => '9.9.9',
            'auto_update' => 'disabled',
            'sort' => 'active_members',
            'dir' => 'asc',
            'page' => '3',
        ]);
        $this->assertSame([], $this->service()->buildView($noisy)['rows'], 'the table really is empty under those filters');

        $this->assertSame(
            $baseline,
            $this->service()->buildHistory(SupportHistoryPeriod::default())['series'],
            'the history must be untouched by current-state filtering'
        );
    }

    public function testAnEmptyHistoryIsReportedAsEmptyRatherThanFabricated(): void
    {
        $history = $this->service()->buildHistory(SupportHistoryPeriod::default());

        $this->assertSame([], $history['series']);
        $this->assertFalse($history['has_data']);
    }
}
