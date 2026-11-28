<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

/**
 * A {@see MemberMergeListener} that only repoints columns (issue #1462):
 * what most modules need, written down as constants by the module's own
 * subclass — its tables, which core does not know, and their collision
 * rules.
 *
 * - UNIQUE: table => [member column, the rest of its unique index, which
 *   row stays — 'kept', or a column whose larger value wins]
 *   ({@see MergeRowRepository::repointUnique()});
 * - PLAIN: list of [table, member column] with no unique index — the row
 *   simply follows;
 * - PREVIEW: what the merge screen announces, label => [table, column],
 *   in the module's own words.
 *
 * `Tests\Architecture\ModuleMemberReferencesFollowTheMergeTest` holds every
 * module's member columns against these lists.
 */
abstract class RepointingMergeListener implements MemberMergeListener
{
    /** @var array<string, array{string, list<string>, string}> */
    protected const UNIQUE = [];

    /** @var list<array{string, string}> */
    protected const PLAIN = [];

    /** @var array<string, array{string, string}> */
    protected const PREVIEW = [];

    public function __construct(private MergeRowRepository $rows)
    {
    }

    public function mergePreview(int $duplicateMemberId): array
    {
        $preview = [];
        foreach (static::PREVIEW as $label => [$table, $column]) {
            $preview[$label] = $this->rows->count($table, $column, $duplicateMemberId);
        }

        return $preview;
    }

    public function onMembersMerged(int $keptMemberId, int $duplicateMemberId): int
    {
        $handled = 0;
        foreach (static::UNIQUE as $table => [$column, $scope, $winner]) {
            $handled += $this->rows->repointUnique(
                $table,
                $column,
                $scope,
                $winner,
                $keptMemberId,
                $duplicateMemberId
            )['handled'];
        }
        foreach (static::PLAIN as [$table, $column]) {
            $handled += $this->rows->repoint($table, $column, $keptMemberId, $duplicateMemberId);
        }

        return $handled;
    }
}
