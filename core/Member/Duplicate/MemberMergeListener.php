<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

/**
 * Optional hook a module implements to bring its own member-referencing
 * rows along when two `members` rows are merged (issue #1462), without core
 * knowing the module's tables (ARCHITECTURE.md §7.4, §8.80). The same shape
 * as {@see \Core\Import\DeskImportListener}: the composition root registers
 * an implementation only when its module is enabled.
 *
 * Core repoints its own tables (MemberMergeRepository::MEMBER_REFERENCES);
 * everything a module recorded under the absorbed id — a presence, a health
 * sheet, a reenrollment answer — used to stay there, invisible from the
 * kept identity and with nothing said about it. A listener is the module
 * saying what it holds, and moving it.
 *
 * ## Called inside the merge transaction
 *
 * {@see onMembersMerged()} runs after core's own repointing and before the
 * commit: a merge half-applied to core but not to a module is a history
 * split in two in a way nobody can see. A listener that throws rolls the
 * whole merge back. Bounded, replayable queries only — never a mail, an
 * HTTP call, a file on disk.
 *
 * ## Collisions are the module's rule, and must lose nothing silently
 *
 * The same fact can exist under both ids — a presence recorded twice for
 * one evening, one health sheet each. Each implementation writes down which
 * one is kept and why, and keeps the other reachable (or journals that it
 * did not) rather than dropping it unannounced.
 */
interface MemberMergeListener
{
    /**
     * What a merge of $duplicateMemberId would bring along from this
     * module, counted without moving anything — for the merge screen.
     * French labels, as the screen shows them, each with its count; an
     * empty array when the absorbed identity holds nothing here.
     *
     * @return array<string, int> label => count
     */
    public function mergePreview(int $duplicateMemberId): array;

    /**
     * Repoint this module's rows from $duplicateMemberId onto
     * $keptMemberId, applying the module's collision rule. Returns how many
     * rows were moved or merged, for the journal.
     */
    public function onMembersMerged(int $keptMemberId, int $duplicateMemberId): int;

    /**
     * The module id the journal counts this listener's rows under.
     */
    public function moduleId(): string;
}
