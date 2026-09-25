<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Protection\ProtectionRepatriation;
use Core\Storage\Location\Protection\RepatriationResult;
use Core\Storage\Location\Protection\StorageProtection;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use Core\Storage\Location\Protection\Task\RepatriateFromCopyHandler;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Bringing back, from the copy, what the source no longer has.
 *
 * **The one operation of this subsystem that writes to the SOURCE**, and
 * the only one an administrator asks for by hand — on the day a restore
 * has just left them with rows and no files. So what it says afterwards
 * is not decoration: it is the only account of what an operation nobody
 * can watch actually did.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RepatriateFromCopyHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private TaskContext $context;
    private StorageProtectionRepository $protections;
    private int $protectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->storagePath = sys_get_temp_dir() . '/repatriate_handler_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        $locations = new StorageLocationRepository($this->pdo, $encryption);
        $source = $locations->create(
            StorageLocationType::Local,
            'Galerie',
            new LocalLocationConfig('gallery'),
            null
        );
        $destination = $locations->create(
            StorageLocationType::Local,
            'NAS',
            new LocalLocationConfig('nas'),
            null
        );

        $this->protections = new StorageProtectionRepository($this->pdo);
        $this->protectionId = $this->protections->save($source, $destination, 30, 24, true);

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
    }

    /** A repatriation that answers a fixed result, whatever it is asked. */
    private function answering(RepatriationResult $result): ProtectionRepatriation
    {
        return new class ($result) extends ProtectionRepatriation {
            public function __construct(private readonly RepatriationResult $answer)
            {
                parent::__construct(
                    new \Core\Storage\Location\Protection\StorageInventoryStore(),
                    new \Core\Storage\Location\Protection\ProtectedCopier()
                );
            }

            public function run(
                StorageProtection $protection,
                StorageBackendInterface $source,
                StorageBackendInterface $destination,
                string $sourceLabel,
                callable $hasTimeLeft,
                ?string $fromCursor = null
            ): RepatriationResult {
                return $this->answer;
            }
        };
    }

    /** @return list<array<string, mixed>> */
    private function journalRows(string $eventType): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ?');
        $stmt->execute([$eventType]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function scheduledRows(): array
    {
        return $this->pdo->query(
            "SELECT * FROM scheduled_actions WHERE task_key = '" . RepatriateFromCopyHandler::TASK_KEY . "'"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testAPayloadWithoutAProtectionIsIgnoredRatherThanFatal(): void
    {
        (new RepatriateFromCopyHandler($this->answering(new RepatriationResult(true, null, 0, 0))))
            ->handle([], $this->context);

        $this->assertSame([], $this->journalRows('storage_repatriation_done'));
        $this->assertSame([], $this->scheduledRows());
    }

    public function testARelationThatNoLongerExistsIsIgnored(): void
    {
        $this->protections->delete($this->protectionId);

        (new RepatriateFromCopyHandler($this->answering(new RepatriationResult(true, null, 3, 3))))
            ->handle(['protection_id' => $this->protectionId], $this->context);

        $this->assertSame([], $this->journalRows('storage_repatriation_done'));
    }

    public function testAFinishedRepatriationSaysHowMuchCameBackAndNamesNoFile(): void
    {
        (new RepatriateFromCopyHandler($this->answering(
            new RepatriationResult(true, null, 40, 12, ['12/marie-dupont.jpg' => 'Échec.'])
        )))->handle(['protection_id' => $this->protectionId], $this->context);

        $rows = $this->journalRows('storage_repatriation_done');
        $this->assertCount(1, $rows);
        $message = (string) $rows[0]['description'];
        $this->assertStringContainsString('12 fichier(s) remis en place', $message);
        // **Counts, never keys.** A journal entry is carried by a support
        // package that leaves the installation, and an object key is a
        // path that may carry somebody's name.
        $this->assertStringNotContainsString('marie-dupont', $message);
        $this->assertSame([], $this->scheduledRows(), 'nothing left to come back for');
    }

    /**
     * **A repatriation that spans several runs reports the whole of what
     * it did, not the last twenty seconds of it.**
     *
     * The counters start again on every run — the object that holds them
     * is built fresh each time — so the closing line reported the final
     * slice while reading as a total: « 12 fichiers remis en place »
     * after putting nine thousand back. The nightly pass carries
     * `pass_seen_count` across its own runs for exactly this reason.
     */
    public function testTheClosingLineCountsEveryRunAndNotJustTheLast(): void
    {
        $handler = new RepatriateFromCopyHandler($this->answering(
            new RepatriationResult(false, '12/m.jpg', 500, 500)
        ));
        $handler->handle(['protection_id' => $this->protectionId], $this->context);

        $scheduled = $this->scheduledRows();
        $this->assertCount(1, $scheduled, 'an unfinished repatriation comes straight back');
        $payload = json_decode((string) $scheduled[0]['payload'], true);
        $this->assertIsArray($payload);
        $this->assertSame('12/m.jpg', $payload['cursor']);
        $this->assertSame(500, $payload['restored_so_far']);

        // The run that finishes it, carrying what the first one did.
        (new RepatriateFromCopyHandler($this->answering(new RepatriationResult(true, null, 20, 20))))
            ->handle($payload, $this->context);

        $rows = $this->journalRows('storage_repatriation_done');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('520 fichier(s) remis en place', (string) $rows[0]['description']);
    }

    /**
     * A repatriation that threw says so — and says that nothing was
     * deleted, which is the question an administrator running this after
     * a disaster actually has.
     */
    public function testARepatriationThatThrewIsJournalledAndNotRescheduled(): void
    {
        $throwing = new class extends ProtectionRepatriation {
            public function __construct()
            {
                parent::__construct(
                    new \Core\Storage\Location\Protection\StorageInventoryStore(),
                    new \Core\Storage\Location\Protection\ProtectedCopier()
                );
            }

            public function run(
                StorageProtection $protection,
                StorageBackendInterface $source,
                StorageBackendInterface $destination,
                string $sourceLabel,
                callable $hasTimeLeft,
                ?string $fromCursor = null
            ): RepatriationResult {
                throw new \RuntimeException('la copie a cessé de répondre');
            }
        };

        (new RepatriateFromCopyHandler($throwing))
            ->handle(['protection_id' => $this->protectionId], $this->context);

        $rows = $this->journalRows('storage_repatriation_failed');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Rien n\'a été supprimé', (string) $rows[0]['description']);
        $this->assertSame([], $this->scheduledRows());
        $this->assertSame([], $this->journalRows('storage_repatriation_done'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
