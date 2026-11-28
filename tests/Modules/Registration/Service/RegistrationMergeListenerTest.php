<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Duplicate\MergeRowRepository;
use Modules\Registration\Service\RegistrationMergeListener;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Issue #1462: a family that answered the reenrollment under the absorbed
 * identity is no longer « sans réponse » after the merge, and the staff's
 * passage note follows.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RegistrationMergeListenerTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationMergeListener $listener;
    private int $kept;
    private int $duplicate;
    private int $year;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->listener = new RegistrationMergeListener(
            new MergeRowRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo))
        );
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)"
        );
        $this->year = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('K1'), ('D1')");
        [$this->kept, $this->duplicate] = array_map(
            'intval',
            $this->pdo->query('SELECT id FROM members ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function reenrollment(int $member, string $decision, string $at): void
    {
        $this->pdo->prepare(
            'INSERT INTO registration_reenrollments (member_id, scout_year_id, decision, answered_at)
             VALUES (?, ?, ?, ?)'
        )->execute([$member, $this->year, $decision, $at]);
    }

    private function decisionOf(int $member): ?string
    {
        $stmt = $this->pdo->prepare('SELECT decision FROM registration_reenrollments WHERE member_id = ?');
        $stmt->execute([$member]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function testTheAnswerAndThePassageNoteFollowTheMerge(): void
    {
        $this->reenrollment($this->duplicate, 'stays', '2026-06-01 10:00:00');
        $this->pdo->prepare('INSERT INTO registration_passage_notes (member_id, scout_year_id) VALUES (?, ?)')
            ->execute([$this->duplicate, $this->year]);

        $preview = $this->listener->mergePreview($this->duplicate);
        $this->assertSame(1, $preview['réponse(s) de réinscription']);
        $this->assertSame(1, $preview['note(s) de passage']);

        $this->assertSame(2, $this->listener->onMembersMerged($this->kept, $this->duplicate));

        $this->assertSame('stays', $this->decisionOf($this->kept));
        $this->assertNull($this->decisionOf($this->duplicate));
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM registration_passage_notes WHERE member_id = {$this->kept}"
        )->fetchColumn());
    }

    /**
     * Both identities answered for the same year: the answer given last is
     * kept, and the journal counts the collision — no name, no comment.
     */
    public function testTwoAnswersForOneYearKeepTheLatestAndAreJournalled(): void
    {
        $this->reenrollment($this->kept, 'leaves', '2026-05-01 10:00:00');
        $this->reenrollment($this->duplicate, 'stays', '2026-06-01 10:00:00');

        $this->listener->onMembersMerged($this->kept, $this->duplicate);

        $this->assertSame('stays', $this->decisionOf($this->kept));
        $context = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'registration_merge_collisions'"
        )->fetchColumn(), true);
        $this->assertSame(['registration_reenrollments' => 1], $context['collisions']);
    }
}
