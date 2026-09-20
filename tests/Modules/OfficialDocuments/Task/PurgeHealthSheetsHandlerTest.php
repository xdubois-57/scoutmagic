<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Task;

use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Task\PurgeHealthSheetsHandler;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;

/**
 * The one job on this installation that deletes children's health data, on
 * its own, at four in the morning, with nobody watching.
 *
 * Which is exactly why it is tested against the REAL engine rather than a
 * double: what it does is a `DELETE … WHERE last_used_at < ?` over a table
 * whose schema is MySQL's, and a suite that asserted on a mock would prove
 * that the code calls the method it calls.
 *
 * Three properties, in order of what they would cost:
 *
 * 1. **A sheet still in use is not deleted.** The failure mode is a family
 *    losing their child's medical answers because they only ever printed
 *    the form and never retyped it.
 * 2. **A sheet nobody has touched IS deleted**, or the privacy notice's
 *    retention promise is false on every installation, with no symptom.
 * 3. **The journal carries the member id and nothing else** — no field
 *    name, no count of what was in it, no name.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PurgeHealthSheetsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private HealthSheetRepository $repository;
    private int $stale;
    private int $fresh;

    /** @var list<array{category: string, action: string, context: array<string, mixed>}> */
    private array $journalled = [];

    protected function setUp(): void
    {
        $this->pdo = $this->connect();
        $this->pdo->exec('DROP TABLE IF EXISTS official_documents_health_sheets');
        $this->pdo->exec($this->createTableStatement());
        // The handler re-arms itself through the real SchedulerService, so
        // the queue it writes into has to exist. Which is the point: it
        // makes the rescheduling assertable rather than merely believed.
        $this->pdo->exec('DROP TABLE IF EXISTS scheduled_actions');
        $this->pdo->exec(self::scheduledActionsTable());

        $this->repository = new HealthSheetRepository($this->pdo, self::encryption());

        $this->stale = random_int(1_000_000, 4_999_999);
        $this->fresh = random_int(5_000_000, 9_999_999);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS official_documents_health_sheets');
        $this->pdo->exec('DROP TABLE IF EXISTS scheduled_actions');
    }

    // ---------------------------------------------------------------
    // What the purge keeps and what it takes
    // ---------------------------------------------------------------

    public function testASheetNobodyHasTouchedForTheRetentionPeriodIsDeleted(): void
    {
        $this->repository->save($this->stale, self::sheet(), self::monthsAgo(20));

        $this->handle();

        $this->assertNull($this->repository->findForMember($this->stale));
    }

    /**
     * **The one that would cost a family their data.** `last_used_at` moves
     * on a save AND on a document being generated (IT-04), so a household
     * that prints last September's form without retyping a word has not
     * abandoned their sheet.
     */
    public function testASheetUsedRecentlyIsLeftAlone(): void
    {
        $this->repository->save($this->fresh, self::sheet(), self::monthsAgo(20));
        // Exactly what downloading the document does.
        $this->repository->touch($this->fresh, self::monthsAgo(1));

        $this->handle();

        $this->assertNotNull($this->repository->findForMember($this->fresh));
    }

    /**
     * One pass, both kinds of row: the purge must not be an all-or-nothing
     * sweep.
     */
    public function testOnePassTakesTheStaleSheetAndKeepsTheOther(): void
    {
        $this->repository->save($this->stale, self::sheet(), self::monthsAgo(24));
        $this->repository->save($this->fresh, self::sheet(), self::monthsAgo(2));

        $this->handle();

        $this->assertNull($this->repository->findForMember($this->stale));
        $this->assertNotNull($this->repository->findForMember($this->fresh));
    }

    /**
     * A sheet exactly at the edge stays. Retention is « after eighteen
     * months », and rounding the wrong way deletes a month early — on data
     * a family cannot get back from anywhere.
     */
    public function testASheetJustInsideTheWindowIsKept(): void
    {
        $this->repository->save($this->fresh, self::sheet(), self::monthsAgo(17));

        $this->handle();

        $this->assertNotNull($this->repository->findForMember($this->fresh));
    }

    // ---------------------------------------------------------------
    // The setting
    // ---------------------------------------------------------------

    /**
     * The unit's own retention wins over the default — read fresh on every
     * run, and **scoped to this module**: a setting read without its module
     * id answers the default silently, which is issue #433's whole story.
     */
    public function testTheUnitsOwnRetentionIsHonoured(): void
    {
        $this->repository->save($this->stale, self::sheet(), self::monthsAgo(7));

        $this->handle(retentionMonths: 6);

        $this->assertNull($this->repository->findForMember($this->stale));
    }

    /**
     * A retention nobody has configured is eighteen months, and so is one
     * somebody typed a zero into: « 0 » in a box labelled « durée de
     * conservation » must not mean « delete everything tonight ».
     */
    public function testAnAbsentOrZeroRetentionFallsBackToTheDefault(): void
    {
        $this->repository->save($this->stale, self::sheet(), self::monthsAgo(12));
        $this->repository->save($this->fresh, self::sheet(), self::monthsAgo(1));

        $this->handle(retentionMonths: 0);

        // Twelve months is inside the default eighteen, so nothing goes.
        $this->assertNotNull($this->repository->findForMember($this->stale));
        $this->assertNotNull($this->repository->findForMember($this->fresh));
        $this->assertSame(
            PurgeHealthSheetsHandler::DEFAULT_RETENTION_MONTHS,
            18,
            'The documented default and the code must agree.'
        );
    }

    // ---------------------------------------------------------------
    // What gets written down
    // ---------------------------------------------------------------

    /**
     * The journal entry carries the member id and NOTHING else. Not a field
     * name, not a count of what the sheet held, not a name — the purge must
     * not write down the health data it exists to destroy.
     */
    public function testTheJournalCarriesTheMemberIdAndNothingElse(): void
    {
        $this->repository->save($this->stale, self::sheet(), self::monthsAgo(20));

        $this->handle();

        $entries = array_values(array_filter(
            $this->journalled,
            fn(array $entry): bool => ($entry['context']['member_id'] ?? null) === $this->stale
        ));

        $this->assertCount(1, $entries, 'A purged sheet must leave exactly one trace.');
        $this->assertSame('official_documents', $entries[0]['category']);
        $this->assertSame('health_sheet_purged', $entries[0]['action']);
        $this->assertSame(['member_id' => $this->stale], $entries[0]['context']);
    }

    /**
     * A pass that removed nothing says nothing. A nightly « 0 fiche
     * effacée » is housekeeping, and it is what buries the lines that
     * matter.
     */
    public function testAPassThatDeletesNothingWritesNothing(): void
    {
        $this->repository->save($this->fresh, self::sheet(), self::monthsAgo(1));

        $this->handle();

        $this->assertSame([], $this->journalled);
    }

    /**
     * And no health answer ever reaches the journal, whatever the entry
     * says. Asserted over the whole of what was written rather than over
     * one key, because the way this fails is a helpful context field added
     * later by somebody who did not know.
     */
    public function testNoHealthAnswerEverReachesTheJournal(): void
    {
        $this->repository->save($this->stale, self::sheet(), self::monthsAgo(20));

        $this->handle();

        $written = json_encode($this->journalled, JSON_UNESCAPED_UNICODE);
        foreach (['Arachides', 'Lhoëst', 'asthma', 'Xavier Dubois', '0470'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, (string) $written);
        }
    }

    // ---------------------------------------------------------------
    // The chain
    // ---------------------------------------------------------------

    /**
     * **The invariant that outlives every other one here.** A recurring
     * task that does not queue its successor runs exactly once, and a
     * retention promise that ran once is a retention promise that is false
     * from the second day on — with nothing anywhere to say so.
     */
    public function testTheTaskQueuesItsOwnSuccessor(): void
    {
        $this->handle();

        $this->assertSame(
            1,
            $this->pendingOccurrences(),
            'The purge did not re-arm itself, so it would run exactly once.'
        );
    }

    /**
     * And it re-arms even when the pass threw. A database hiccup must not
     * be a retention rule that silently stops — which is what the `finally`
     * is for, and what a `try` without one would quietly undo.
     */
    public function testTheTaskQueuesItsSuccessorEvenWhenTheRunFails(): void
    {
        // The sheets table vanishing mid-pass is as good a failure as any,
        // and closer to the real one than a thrown double would be.
        $this->pdo->exec('DROP TABLE official_documents_health_sheets');

        try {
            $this->handle();
        } catch (\PDOException) {
            // Expected: what matters is what the `finally` did.
        }

        $this->assertSame(1, $this->pendingOccurrences(), 'A run that threw stopped the chain for good.');
    }

    /**
     * A second pass does not queue a second chain: `rearmAfter()` is the
     * guarded form, so a duplicate collapses instead of living for ever
     * (Tests\Architecture\RecurringTasksRearmTest has what the unguarded
     * one did to a production journal).
     */
    public function testASecondPassDoesNotQueueASecondChain(): void
    {
        $this->handle();
        $this->handle();

        $this->assertSame(1, $this->pendingOccurrences());
    }

    private function pendingOccurrences(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_actions
             WHERE module_id = 'official_documents' AND task_key = ? AND status = 'pending'"
        );
        $stmt->execute([PurgeHealthSheetsHandler::TASK_KEY]);

        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------

    /**
     * Run the handler as the scheduler would.
     *
     * Deliberately through `handle()` and not through the service: what is
     * being tested includes the wiring — that the handler builds its own
     * repository from the context, reads its setting in the right scope,
     * and hands the journal through.
     */
    private function handle(?int $retentionMonths = null): void
    {
        (new PurgeHealthSheetsHandler())->handle([], $this->context($retentionMonths));
    }

    private function context(?int $retentionMonths): TaskContext
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $journal = $this->createMock(JournalService::class);
        $journal->method('log')->willReturnCallback(
            function (string $category, string $action, string $level, string $message, $context = []): bool {
                $this->journalled[] = [
                    'category' => $category,
                    'action' => $action,
                    'context' => is_array($context) ? $context : [],
                ];

                return true;
            }
        );

        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static function (string $key, ?string $moduleId = null, mixed $default = null) use ($retentionMonths) {
                // The scope is part of what is asserted: a read without the
                // module id must not find this value (issue #433).
                if ($key === PurgeHealthSheetsHandler::RETENTION_SETTING && $moduleId === 'official_documents') {
                    return $retentionMonths === null ? null : (string) $retentionMonths;
                }

                return $default;
            }
        );

        return new TaskContext(
            $connection,
            self::encryption(),
            $this->createStub(MailService::class),
            $journal,
            $settings,
            $this->createStub(UserAccountRepository::class),
            sys_get_temp_dir()
        );
    }

    private static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    private static function monthsAgo(int $months): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify("-{$months} months");
    }

    private static function sheet(): HealthSheet
    {
        return HealthSheet::fromArray([
            'contact1_name' => 'Xavier Dubois',
            'contact1_phone' => '0470 00 00 00',
            'doctor_last_name' => 'Lhoëst',
            'allergies' => 'Arachides',
            'conditions' => ['asthma' => true],
        ]);
    }

    private function connect(): \PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';

        try {
            return new \PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }
    }

    /**
     * Core's own `scheduled_actions`, with the foreign key stripped —
     * `user_accounts` is not migrated into this shared database, and the
     * column is never set by a self-rescheduling task anyway.
     */
    private static function scheduledActionsTable(): string
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/schema/core.sql');
        $start = strpos($sql, 'CREATE TABLE scheduled_actions (');
        $end = strpos($sql, ';', (int) $start);
        $statement = substr($sql, (int) $start, (int) $end - (int) $start);

        return (string) preg_replace('/,\s*\n\s*CONSTRAINT fk_sa_requested_by[^\n]*\n/', "\n", $statement);
    }

    /** The module's own schema.sql, with the foreign key stripped. */
    private function createTableStatement(): string
    {
        $sql = (string) file_get_contents(
            dirname(__DIR__, 4) . '/modules/official_documents/schema.sql'
        );

        return (string) preg_replace('/,\s*\n\s*CONSTRAINT fk_odhs_member[^\n]*\n/', "\n", $sql);
    }
}
