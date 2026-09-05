<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Task\RefreshPlaceSummariesHandler;
use Modules\Camps\Task\ReviewReminderHandler;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * The two nightly tasks of the camps module, both measured at 0 %.
 *
 * Neither has a page anybody opens, so nothing ever ran them but a real
 * crontab. What they promise is in their own docblocks, and it is the
 * kind of promise that fails quietly:
 *
 * - **They re-arm themselves, and exactly once.** The scheduler here is
 *   driven by ordinary page loads, so two visitors can run the same task
 *   within one second; a blind re-arm queues two copies, then four. And a
 *   task that fails to re-arm at all simply stops being a nightly task,
 *   which nobody notices either.
 * - **The summary refresh touches STALE places only, and never more than
 *   its batch cap.** A model call is slow and costs money: a unit that
 *   has just imported twenty years of camps must not turn one run into
 *   twenty calls.
 * - **It degrades to "no summary" when the AI module is absent**, rather
 *   than reaching into a module that may not be installed
 *   (ARCHITECTURE.md § 7.5 on the scheduled path).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class NightlyCampsTasksTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    // ── the nightly rhythm ────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function nightlyTasks(): array
    {
        return [
            'place summaries' => ['summaries', 'refresh_place_summaries', '05:00:00'],
            'review reminder' => ['reminder', 'review_reminder', '06:00:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nightlyTasks')]
    public function testEachRunArmsTheNextOneForTomorrowMorning(string $task, string $key, string $at): void
    {
        $this->nightly($task);

        $runAt = (string) $this->pdo
            ->query("SELECT run_at FROM scheduled_actions WHERE task_key = '" . $key . "'")
            ->fetchColumn();

        $this->assertSame(
            (new \DateTimeImmutable('tomorrow'))->format('Y-m-d ') . $at,
            $runAt
        );
    }

    /**
     * Two page loads within the same second run this task twice. A blind
     * re-arm queues two copies of tomorrow's run — and then four.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nightlyTasks')]
    public function testTwoRunsInTheSameSecondArmOneSuccessor(string $task, string $key, string $at): void
    {
        $this->nightly($task);
        $this->nightly($task);

        $queued = $this->pdo
            ->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = '" . $key . "'")
            ->fetchColumn();

        $this->assertSame(1, (int) $queued);
    }

    /**
     * A nightly task that stops re-arming stops being nightly, and the
     * feature it drives simply goes quiet.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nightlyTasks')]
    public function testTheSuccessorIsArmedEvenWhenTheRunHadNothingToDo(string $task, string $key, string $at): void
    {
        $this->settings()->register('camps_ai_summary_enabled', '0', 'boolean', 'Résumés IA', 'Test.', 'camps');
        $this->settings()->set('camps_ai_summary_enabled', '0', 'camps');

        $this->nightly($task);

        $queued = $this->pdo
            ->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = '" . $key . "'")
            ->fetchColumn();

        $this->assertSame(1, (int) $queued);
    }

    // ── the summaries, and what they cost ─────────────────────────────

    public function testOnlyStalePlacesAreSentToTheModel(): void
    {
        $fresh = $this->createPlace('Le pré du fond', stale: false);
        $stale = $this->createPlace('La grange', stale: true);
        $llm = $this->countingConnector();

        $this->nightly('summaries', $llm);

        $this->assertSame(
            [$stale],
            $this->placesWithASummary(),
            'A model call per place per night, on places nothing changed about, is money for nothing.'
        );
        $this->assertNotContains($fresh, $this->placesWithASummary());
    }

    /**
     * A unit that has just imported twenty years of camps has twenty
     * stale places at once. One run must not become twenty model calls.
     */
    public function testARunNeverExceedsItsBatchCap(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->createPlace('Lieu ' . $i, stale: true);
        }

        $this->nightly('summaries', $this->countingConnector());

        $this->assertCount(
            10,
            $this->placesWithASummary(),
            'What is left stays stale and is picked up tomorrow.'
        );
    }

    public function testWithTheSummariesSwitchedOffNoPlaceIsSentAnywhere(): void
    {
        $this->createPlace('La grange', stale: true);
        $this->settings()->register('camps_ai_summary_enabled', '1', 'boolean', 'Résumés IA', 'Test.', 'camps');
        $this->settings()->set('camps_ai_summary_enabled', '0', 'camps');

        $this->nightly('summaries', $this->countingConnector());

        $this->assertSame([], $this->placesWithASummary());
    }

    /**
     * The connector is a capability, asked for at run time — a module
     * that is not installed has no classes to reach into, and this task
     * must answer "no summary" rather than fail (ARCHITECTURE.md § 7.5).
     */
    public function testWithoutTheAiModuleTheRunWritesNoSummaryAndStillArmsTomorrow(): void
    {
        $this->createPlace('La grange', stale: true);

        $this->nightly('summaries', null);

        $this->assertSame([], $this->placesWithASummary());
        $this->assertSame(
            1,
            (int) $this->pdo
                ->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'refresh_place_summaries'")
                ->fetchColumn()
        );
    }

    public function testTheJournalCountsTheSummariesAndOnlyWhenThereAreSome(): void
    {
        $this->createPlace('La grange', stale: true);

        $this->nightly('summaries', $this->countingConnector());

        $entries = (new JournalRepository($this->pdo))->search();
        $this->assertCount(1, $entries);
        $this->assertSame('place_summaries_refreshed', $entries[0]['event_type']);
    }

    public function testARunThatWroteNothingWritesNoJournalLineEither(): void
    {
        $this->nightly('summaries', $this->countingConnector());

        $this->assertSame(
            [],
            (new JournalRepository($this->pdo))->search(),
            'A line every night about a task with nothing to do is the flood, not the answer.'
        );
    }

    // ── harness ───────────────────────────────────────────────────────

    private function nightly(string $task, ?LlmConnectorInterface $llm = null): void
    {
        if ($task === 'summaries') {
            (new RefreshPlaceSummariesHandler($llm))->handle([], $this->taskContext());

            return;
        }

        (new ReviewReminderHandler())->handle([], $this->taskContext());
    }

    /**
     * A connector that answers, so the run gets as far as writing.
     */
    private function countingConnector(): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturn(
            new LlmResponse('Un lieu tranquille, bien desservi.', null, 120, 40)
        );

        return $llm;
    }

    /**
     * @return array<int, int> the ids of the places that now hold a summary
     */
    private function placesWithASummary(): array
    {
        return array_map(
            'intval',
            $this->pdo
                ->query("SELECT id FROM camp_places WHERE ai_summary IS NOT NULL AND ai_summary <> '' ORDER BY id")
                ->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function createPlace(string $name, bool $stale): int
    {
        $id = (new PlaceRepository($this->pdo))->create($name, 'Rue du Camp 1', '5000', 'Namur', null, null);

        // A dated stay AND something worth saying about it — a price or a
        // review. Without either, the service clears the summary rather
        // than write one that says nothing.
        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_camps (place_id, start_date, end_date, stay_type, status, price_cents)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, '2026-07-01', '2026-07-10', 'grand_camp', 'confirmed', 145000]);

        $this->pdo->prepare('UPDATE camp_places SET ai_summary_is_stale = ? WHERE id = ?')
            ->execute([$stale ? 1 : 0, $id]);

        return $id;
    }

    private function settings(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings(),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }
}
