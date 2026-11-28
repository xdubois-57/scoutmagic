<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

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
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\PresenceMergeListener;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * Issue #1462: merge two identities of one animé, then read the sheet again
 * — the evenings pointed under the absorbed identity come along.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PresenceMergeListenerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceRepository $presences;
    private MemberMergeService $merge;
    private int $kept;
    private int $duplicate;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();
        $this->presences = new PresenceRepository($this->pdo, $this->encryption);
        $journal = new JournalService(new JournalRepository($this->pdo));

        $lastYear = PresencesTestHelper::createScoutYear($this->pdo, '2024-2025', '2024-09-01', '2025-08-31');
        $thisYear = PresencesTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $section = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->kept = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $lastYear, 'Basile', 'Hargot', 'animated', $section
        )['memberId'];
        $this->duplicate = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $thisYear, 'Basile', 'Hargot', 'animated', $section
        )['memberId'];

        $registry = new MemberMergeListenerRegistry();
        $registry->register(new PresenceMergeListener($this->presences, $journal));
        $duplicates = new DuplicateMemberRepository($this->pdo, $this->encryption);
        $this->merge = new MemberMergeService(
            new MemberMergeRepository($this->pdo, $duplicates, $registry),
            $duplicates,
            $journal
        );
    }

    public function testTheEveningsOfTheAbsorbedIdentityFollowTheMerge(): void
    {
        $this->presences->saveStatus(10, $this->duplicate, PresenceStatus::PRESENT, null);
        $this->presences->saveComment(11, $this->duplicate, 'Malade.', null);

        $this->assertSame(
            ['soirée(s) de présence' => 2],
            $this->merge->preview($this->kept, $this->duplicate)->moduleItems
        );
        $this->merge->merge($this->kept, $this->duplicate, 1);

        $this->assertSame(PresenceStatus::PRESENT, $this->presences->find(10, $this->kept)?->status);
        $this->assertSame('Malade.', $this->presences->find(11, $this->kept)?->comment);
        $this->assertSame(0, $this->presences->countForMember($this->duplicate));
    }

    /**
     * An evening pointed under both identities keeps the decision written
     * last, and the journal says how many such evenings there were.
     */
    public function testAnEveningRecordedTwiceKeepsTheLatestDecisionAndSaysSo(): void
    {
        $this->presences->saveStatus(10, $this->kept, PresenceStatus::ABSENT, null);
        $this->presences->saveStatus(10, $this->duplicate, PresenceStatus::PRESENT, null);
        $this->pdo->exec(
            "UPDATE presences_records SET updated_at = '2026-01-01 10:00:00' WHERE member_id = {$this->kept}"
        );
        $this->pdo->exec(
            "UPDATE presences_records SET updated_at = '2026-01-02 10:00:00' WHERE member_id = {$this->duplicate}"
        );
        $this->presences->saveStatus(11, $this->kept, PresenceStatus::EXCUSED, null);
        $this->presences->saveStatus(11, $this->duplicate, PresenceStatus::ABSENT, null);
        $this->pdo->exec(
            "UPDATE presences_records SET updated_at = '2026-01-05 10:00:00'
             WHERE member_id = {$this->kept} AND calendar_event_id = 11"
        );
        $this->pdo->exec(
            "UPDATE presences_records SET updated_at = '2026-01-03 10:00:00'
             WHERE member_id = {$this->duplicate} AND calendar_event_id = 11"
        );

        $this->merge->merge($this->kept, $this->duplicate, 1);

        $this->assertSame(PresenceStatus::PRESENT, $this->presences->find(10, $this->kept)?->status);
        $this->assertSame(PresenceStatus::EXCUSED, $this->presences->find(11, $this->kept)?->status);
        $this->assertSame(2, $this->presences->countForMember($this->kept));
        $context = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'presences_merge_collisions'"
        )->fetchColumn(), true);
        $this->assertSame(2, $context['collisions']);
        $merged = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'member_merged'"
        )->fetchColumn(), true);
        $this->assertSame(['presences' => 2], $merged['module_rows_moved']);
    }

    /**
     * Evenings pointed under an identity merged before presences could
     * follow a merge are brought along by the catch-up.
     */
    public function testTheCatchUpBringsTheEveningsOfAnEarlierMergeAlong(): void
    {
        $this->presences->saveStatus(10, $this->duplicate, PresenceStatus::PRESENT, null);
        $this->pdo->prepare('UPDATE members SET merged_into_member_id = ? WHERE id = ?')
            ->execute([$this->kept, $this->duplicate]);
        $repository = new MemberMergeRepository(
            $this->pdo,
            new DuplicateMemberRepository($this->pdo, $this->encryption)
        );

        $handled = $repository->catchUp(
            new PresenceMergeListener($this->presences, new JournalService(new JournalRepository($this->pdo)))
        );

        $this->assertSame(1, $handled);
        $this->assertSame(PresenceStatus::PRESENT, $this->presences->find(10, $this->kept)?->status);
    }

    /**
     * The whole path: the module is disabled (no listener registered) when
     * the merge happens, then enabled again — the registrar's catch-up moves
     * the evenings, once.
     */
    public function testAMergeMadeWhilePresencesWasDisabledIsFollowedOnceItIsEnabledAgain(): void
    {
        $this->presences->saveStatus(10, $this->duplicate, PresenceStatus::PRESENT, null);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $duplicates = new DuplicateMemberRepository($this->pdo, $this->encryption);
        $boot = function (bool $presencesEnabled) use ($journal, $duplicates): MemberMergeService {
            $registry = new MemberMergeListenerRegistry();
            $repository = new MemberMergeRepository($this->pdo, $duplicates, $registry);
            if ($presencesEnabled) {
                (new MemberMergeListenerRegistrar(
                    $registry,
                    $repository,
                    new SettingService(new SettingRepository($this->pdo)),
                    $journal
                ))->register(new PresenceMergeListener($this->presences, $journal));
            }

            return new MemberMergeService($repository, $duplicates, $journal);
        };

        $boot(false)->merge($this->kept, $this->duplicate, 1);
        $this->assertSame(1, $this->presences->countForMember($this->duplicate), 'disabled: nothing moved');

        $boot(true);
        $this->assertSame(PresenceStatus::PRESENT, $this->presences->find(10, $this->kept)?->status);
        $this->assertSame(0, $this->presences->countForMember($this->duplicate));

        $boot(true);
        $this->assertSame(1, $this->presences->countForMember($this->kept), 'not replayed on the next request');
    }
}
