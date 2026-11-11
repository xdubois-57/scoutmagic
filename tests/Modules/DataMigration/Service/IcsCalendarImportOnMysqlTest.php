<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Service;

use Core\Config\ScoutYearService;
use Core\File\ChunkedUploadStore;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Calendar\Service\CalendarImportWriter;
use Modules\DataMigration\Importer\IcsCalendarImporter;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\UploadStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\IcsCalendarStack;
use Tests\UsesProductionEngine;

/**
 * The ICS import end to end, on the engine production runs: the form,
 * the download, the analysis, the confirmation, the execution in slices
 * through the calendar module's real write contract, the re-run, and the
 * importing account's rights resolved for real from its account.
 *
 * On MariaDB/MySQL because the deduplication key is the correspondence
 * table's VARBINARY unique key (a UID compared byte for byte), and every
 * event is written in the module's own transaction together with its
 * correspondence row.
 */
#[Group('database')]
final class IcsCalendarImportOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const ADDRESS = IcsCalendarStack::ADDRESS;

    private \PDO $pdo;
    private string $storage;
    private RunService $service;
    private IcsCalendarStack $stack;
    private IcsCalendarImporter $importer;
    private CalendarImportWriter $writer;
    private int $calendarId;
    private int $adminId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_ics_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        $encryption = new EncryptionService(random_bytes(32), random_bytes(32));
        $this->stack = new IcsCalendarStack($this->pdo, $this->productionEngineSchemaConnection(), $encryption);
        $this->importer = $this->stack->importer;
        $this->writer = $this->stack->writer;
        $this->calendarId = $this->stack->calendarId;
        $this->adminId = $this->stack->adminId;
        $scoutYears = new ScoutYearService($this->pdo);

        $runs = new RunRepository($this->pdo, $encryption);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears($scoutYears);
        $this->service = new RunService(
            $runs,
            new ImportEngine($this->pdo, $runs, new ItemMappingRepository($this->pdo), $years, $uploads, new ManifestReader(), $journal, 0.0),
            $years,
            $uploads,
            new ChunkedUploadStore($this->storage),
            $journal
        );
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testACalendarIsCopiedOnceInBrusselsTimeWithoutAnyNotification(): void
    {
        $run = $this->drive($this->accept(self::ADDRESS));

        $this->assertSame(Run::ANALYSED, $run->state);
        $this->assertSame(8, $run->analysis['total']);
        $this->assertSame(7, $run->analysis['to_import']);
        $this->assertSame(['Antérieur au 11/11/2024 (date minimale)' => 1], $run->analysis['ignored']);
        $this->assertSame(0, $this->eventCount(), 'The analysis wrote an event.');

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(7, $run->report['imported']);
        $this->assertSame(7, $this->eventCount());
        $this->assertSame(
            ['2025-06-20', null, '20:00:00', '22:00:00'],
            $this->when('Conseil d\'unité (heure UTC)')
        );
        $this->assertSame(['2025-07-20', '2025-07-30', null, null], $this->when('Grand camp'));
        $this->assertSame([], $this->stack->dispatched->getArrayCopy(), 'An imported event was announced to the members.');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        // Every event of the fixture is past: no reminder at all.
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'calendar'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_events WHERE auto_create_retro = 1')->fetchColumn());
        $this->assertSame(
            [hash('sha256', self::ADDRESS), 'ics', 'event', 'calendar_event'],
            array_values($this->pdo->query('SELECT installation_id, format, source_kind, target_type FROM data_migration_items LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [])
        );
    }

    /**
     * Read again from the same address — typed as webcal:// this time —
     * nothing is created twice: every event is « déjà présent ».
     */
    public function testTheSameAddressImportedAgainCreatesNothing(): void
    {
        $this->drive($this->service->confirm($this->drive($this->accept(self::ADDRESS)), []) ?? $this->fail('Not confirmed.'));

        $again = $this->drive($this->accept('webcal://agenda.exemple.test/private-abc/basic.ics'));
        $this->assertSame(7, $again->analysis['present']);
        $this->assertSame(0, $again->analysis['to_import']);
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(7, $again->report['present']);
        $this->assertSame(7, $this->eventCount());
    }

    public function testRestartingTheSameRunCreatesNothingEither(): void
    {
        $done = $this->drive($this->service->confirm($this->drive($this->accept(self::ADDRESS)), []) ?? $this->fail('Not confirmed.'));

        $again = $this->drive($this->service->restart($this->importer, $done, $this->adminId) ?? $this->fail('No restart.'));
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(7, $again->report['present']);
        $this->assertSame(7, $this->eventCount());
    }

    /**
     * The report keeps the categories apart: recurring, cancelled and
     * unreadable each have their own reason, and the plain events are
     * imported.
     */
    public function testRecurringCancelledAndUnreadableEventsAreCountedApart(): void
    {
        $recurring = $this->drive($this->service->confirm($this->drive($this->accept(IcsCalendarStack::RECURRENCES)), []) ?? $this->fail('Not confirmed.'));
        $this->assertSame(1, $recurring->report['imported']);
        $this->assertSame(['Événement récurrent, exclu' => 4], $recurring->report['ignored']);

        $incomplete = $this->drive($this->service->confirm($this->drive($this->accept(IcsCalendarStack::INCOMPLETE)), []) ?? $this->fail('Not confirmed.'));
        $this->assertSame(1, $incomplete->report['imported']);
        $this->assertSame(1, $incomplete->report['ignored']['Événement annulé']);
        $this->assertSame(1, $incomplete->report['ignored']['Illisible : UID en double dans le fichier']);
        $this->assertSame(7, array_sum(array_map(
            'intval',
            array_filter($incomplete->report['ignored'], static fn (string $r): bool => str_starts_with($r, 'Illisible : '), ARRAY_FILTER_USE_KEY)
        )));
        $this->assertSame(0, $incomplete->report['errors']);
        $this->assertSame(2, $this->eventCount());
        // D8: the report shows a day and a UID, never a title.
        $this->assertStringNotContainsString('Second du nom', (string) json_encode($incomplete->report, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The account's rights come from its account, now: a super-admin
     * writes to the calendar, an account without any function writes
     * nowhere, and a deactivated one neither.
     */
    public function testTheImportingAccountsRightsAreResolvedFromTheAccount(): void
    {
        $this->assertTrue($this->writer->canWriteTo($this->adminId, $this->calendarId));

        $nobody = $this->stack->accounts->create('parent@exemple.test')->id;
        $this->assertSame([], $this->writer->writableCalendars($nobody));
        $this->assertFalse($this->writer->canWriteTo($nobody, $this->calendarId));

        $this->stack->accounts->deactivate($this->adminId);
        $this->assertFalse($this->writer->canWriteTo($this->adminId, $this->calendarId));
    }

    /**
     * Revalidated at the moment of writing: an account that lost its
     * rights after the analysis imports nothing, each event ignored with
     * the reason — not one written, not one counted as an error.
     */
    public function testRightsLostAfterTheAnalysisStopEveryWrite(): void
    {
        $run = $this->drive($this->accept(self::ADDRESS));
        $this->stack->accounts->deactivate($this->adminId);

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(0, $run->report['imported']);
        $this->assertSame(7, $run->report['ignored']['Calendrier de destination non modifiable']);
        $this->assertSame(0, $run->report['errors']);
        $this->assertSame(0, $this->eventCount());
    }

    private function accept(string $address): Run
    {
        return $this->service->acceptForm(
            $this->importer,
            ['adresse' => $address, 'calendrier' => (string) $this->calendarId, 'date_minimale' => '2024-11-11'],
            $this->adminId
        );
    }

    private function drive(Run $run): Run
    {
        for ($i = 0; $i < 200 && in_array($run->state, [Run::UPLOADED, Run::CONFIRMED, Run::RUNNING], true); $i++) {
            $run = $this->service->advance($this->importer, $run);
        }

        return $run;
    }

    private function eventCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn();
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}
     */
    private function when(string $title): array
    {
        $statement = $this->pdo->prepare('SELECT start_date, end_date, start_time, end_time FROM calendar_events WHERE title = ?');
        $statement->execute([$title]);
        $row = $statement->fetch(\PDO::FETCH_NUM) ?: $this->fail('No event ' . $title);

        return [$row[0], $row[1], $row[2], $row[3]];
    }
}
