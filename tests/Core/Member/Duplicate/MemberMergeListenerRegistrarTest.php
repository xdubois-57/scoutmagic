<?php

declare(strict_types=1);

namespace Tests\Core\Member\Duplicate;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Duplicate\DuplicateMemberRepository;
use Core\Member\Duplicate\MemberMergeListenerRegistrar;
use Core\Member\Duplicate\MemberMergeListenerRegistry;
use Core\Member\Duplicate\MemberMergeRepository;
use Core\Member\Duplicate\MemberMergeService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1462: a module's listener catches up on every merge it has not
 * seen — the first time it is registered, and again after a merge made
 * while the module was disabled (its listener not registered at all).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MemberMergeListenerRegistrarTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private DuplicateMemberRepository $duplicates;
    private int $lastYear;
    private int $thisYear;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->duplicates = new DuplicateMemberRepository($this->pdo, $this->encryption);
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0), ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        [$this->lastYear, $this->thisYear] = array_map(
            'intval',
            $this->pdo->query('SELECT id FROM scout_years ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /** One request: a fresh registry, and the listeners of the modules enabled in it. */
    private function bootWith(?RecordingMergeListener $listener): MemberMergeService
    {
        $registry = new MemberMergeListenerRegistry();
        $repository = new MemberMergeRepository($this->pdo, $this->duplicates, $registry);
        $journal = new JournalService(new JournalRepository($this->pdo));
        if ($listener !== null) {
            (new MemberMergeListenerRegistrar(
                $registry,
                $repository,
                new SettingService(new SettingRepository($this->pdo)),
                $journal
            ))->register($listener);
        }

        return new MemberMergeService($repository, $this->duplicates, $journal);
    }

    private function member(string $deskId, int $yearId): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$id, $yearId, 'x', 'y']);

        return $id;
    }

    public function testAMergeMadeWhileTheModuleWasDisabledReachesItOnceEnabledAgain(): void
    {
        $enabled = new RecordingMergeListener([], 0);
        $this->bootWith($enabled);
        $this->assertSame([], $enabled->calls, 'nothing merged yet: nothing to catch up');

        $kept = $this->member('K', $this->lastYear);
        $duplicate = $this->member('D', $this->thisYear);
        $this->bootWith(null)->merge($kept, $duplicate, 1);

        $reEnabled = new RecordingMergeListener([], 0);
        $this->bootWith($reEnabled);
        $this->assertSame([[$kept, $duplicate]], $reEnabled->calls);

        $nextRequest = new RecordingMergeListener([], 0);
        $this->bootWith($nextRequest);
        $this->assertSame([], $nextRequest->calls, 'already caught up: not replayed on every request');
    }
}
