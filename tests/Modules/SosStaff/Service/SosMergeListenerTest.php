<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Member\Duplicate\MergeRowRepository;
use Modules\SosStaff\Service\SosMergeListener;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1462: a duty planned under the absorbed identity is still on the
 * planning after the merge, and a day planned under both keeps the kept
 * identity's.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class SosMergeListenerTest extends TestCase
{
    public function testDutiesFollowAndADayPlannedTwiceKeepsTheKeptIdentitys(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE sos_oncall_assignments (id INTEGER PRIMARY KEY, member_id INTEGER NOT NULL,
             assignment_date TEXT NOT NULL, state TEXT NOT NULL, UNIQUE (member_id, assignment_date))'
        );
        $pdo->exec('CREATE TABLE sos_settings (id INTEGER PRIMARY KEY, default_number_member_id INTEGER NULL)');
        $pdo->exec(
            "INSERT INTO sos_oncall_assignments (member_id, assignment_date, state) VALUES
             (10, '2026-12-01', 'unavailable'), (20, '2026-12-01', 'oncall'), (20, '2026-12-02', 'oncall')"
        );
        $pdo->exec('INSERT INTO sos_settings (default_number_member_id) VALUES (20)');
        $listener = new SosMergeListener(new MergeRowRepository($pdo));

        $this->assertSame(['jour(s) au planning des gardes SOS' => 2], $listener->mergePreview(20));
        $listener->onMembersMerged(10, 20);

        $this->assertSame(
            ['2026-12-01' => 'unavailable', '2026-12-02' => 'oncall'],
            $pdo->query('SELECT assignment_date, state FROM sos_oncall_assignments WHERE member_id = 10
                         ORDER BY assignment_date')->fetchAll(\PDO::FETCH_KEY_PAIR)
        );
        $this->assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM sos_oncall_assignments WHERE member_id = 20'
        )->fetchColumn());
        $this->assertSame(10, (int) $pdo->query('SELECT default_number_member_id FROM sos_settings')->fetchColumn());
    }
}
