<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Member\Duplicate\MergeRowRepository;
use Modules\Rental\Service\RentalMergeListener;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1462: an asset managed under the absorbed identity is still
 * theirs after the merge, an asset managed under both keeps the active
 * assignment, and what they recorded on a booking is still signed by them.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalMergeListenerTest extends TestCase
{
    public function testManagedAssetsFollowAndASharedOneKeepsTheActiveAssignment(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE rental_asset_managers (id INTEGER PRIMARY KEY, asset_id INTEGER NOT NULL,
             member_id INTEGER NOT NULL, is_active INTEGER NOT NULL, UNIQUE (asset_id, member_id))'
        );
        $plain = [];
        foreach ((new \ReflectionClass(RentalMergeListener::class))->getConstant('PLAIN') as [$table, $column]) {
            $plain[$table][] = $column;
        }
        foreach ($plain as $table => $columns) {
            $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, " . implode(' INTEGER NULL, ', $columns)
                . ' INTEGER NULL)');
        }
        $pdo->exec(
            'INSERT INTO rental_asset_managers (asset_id, member_id, is_active) VALUES (1, 10, 0), (1, 20, 1), (2, 20, 1)'
        );
        $pdo->exec('INSERT INTO rental_booking_comments (author_member_id) VALUES (20)');
        $listener = new RentalMergeListener(new MergeRowRepository($pdo));

        $this->assertSame(['bien(s) en location géré(s)' => 2], $listener->mergePreview(20));
        $listener->onMembersMerged(10, 20);

        $this->assertSame(
            [1 => 1, 2 => 1],
            array_map('intval', $pdo->query(
                'SELECT asset_id, is_active FROM rental_asset_managers WHERE member_id = 10 ORDER BY asset_id'
            )->fetchAll(\PDO::FETCH_KEY_PAIR))
        );
        $this->assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM rental_asset_managers WHERE member_id = 20'
        )->fetchColumn());
        $this->assertSame(10, (int) $pdo->query('SELECT author_member_id FROM rental_booking_comments')->fetchColumn());
    }
}
