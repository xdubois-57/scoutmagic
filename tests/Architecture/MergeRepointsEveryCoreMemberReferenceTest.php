<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Member\Duplicate\MemberMergeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1462: `member_notes` was a core table holding a member, and the
 * merge did not know it — a chef d'unité's note on the absorbed identity
 * vanished. Every foreign key of the core schema onto `members(id)` is
 * now either repointed by a merge (MEMBER_REFERENCES) or listed, with its
 * reason, as not repointed — so the next core table is not forgotten.
 */
final class MergeRepointsEveryCoreMemberReferenceTest extends TestCase
{
    public function testEveryCoreReferenceToAMemberIsRepointedOrExplicitlyLeftAlone(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/core.sql');
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(\w+) \((.*?)\n\)/s', $schema, $tables, PREG_SET_ORDER);

        $found = [];
        foreach ($tables as [, $table, $body]) {
            preg_match_all('/FOREIGN KEY \((\w+)\) REFERENCES members\(id\)/', $body, $keys);
            foreach ($keys[1] as $column) {
                $found[] = $table . '.' . $column;
            }
        }
        sort($found);

        $handled = array_merge(
            array_map(
                static fn(string $table, string $column): string => $table . '.' . $column,
                array_keys(MemberMergeRepository::memberReferences()),
                array_values(MemberMergeRepository::memberReferences())
            ),
            array_keys(MemberMergeRepository::NOT_REPOINTED)
        );
        sort($handled);

        $this->assertNotEmpty($found, 'the schema parse found no reference at all');
        $this->assertSame(
            $found,
            $handled,
            'A core table references members(id) and the merge neither repoints it '
            . '(MemberMergeRepository::MEMBER_REFERENCES) nor says why not (NOT_REPOINTED).'
        );
    }
}
