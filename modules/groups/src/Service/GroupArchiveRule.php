<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\ScoutYearService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Groups\Repository\DiscussionGroup;

/**
 * THE answer to « is this group archived? » (issue #1350) — the one state
 * a group can be in besides active, asked by every consumer in this module
 * so that there is never a second, competing notion of "read-only".
 *
 * A group is archived when either:
 *
 * - **it carries an archive date** (`discussion_groups.closed_at`, mapped
 *   to DiscussionGroup::$archivedAt): a moderator archived it, the
 *   inactivity task did, or the end-of-year task did
 *   (Service\GroupLifecycleService); or
 * - **its scout year is over**, measured against the PUBLIC year. This
 *   second branch is what refuses participation from the very first
 *   second after the public year changes, before the daily task has had
 *   the chance to stamp a date on it.
 *
 * Two choices here are the whole point and must not drift:
 *
 * - **The reference is the public year**
 *   (ScoutYearResolver::getCurrentPublicYear()), never the year a session
 *   happens to be on (GroupSessionContext::$effectiveScoutYearId, which
 *   for a chief may be the staff year being prepared). A group's status is
 *   a fact about the unit, not about who is looking: a preview of another
 *   year changes nothing, displayed or stored.
 * - **"Past" compares PERIODS, not ids.** A year is past when it ended
 *   before the public year started. Ids are insertion order, not
 *   chronology — a year imported late would otherwise read as past, and
 *   a future year prepared in advance would read as past to anybody whose
 *   session is on the current one. A group with no year, or of a future
 *   year, is never past.
 *
 * Years are read once per instance (one query for every year, one for the
 * public year), so asking about a whole list of groups costs no query per
 * group. A year id the cache does not know — created after the first
 * read — triggers one reload rather than a wrong answer.
 *
 * Mirrored in SQL by Repository\GroupRepository::countActiveCreatedBy()
 * and findActiveOfYearsEndedBefore(), both fed publicYearStartDate() from
 * here, so the database and this class cannot disagree about which years
 * are over.
 */
class GroupArchiveRule
{
    /** @var array<int, ?string>|null scout year id => end_date, null for an id that resolves to no year */
    private ?array $endDates = null;

    private ?string $publicYearStart = null;

    public function __construct(
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * Read-only for its members, listed under « Archives », whatever the
     * cause: a date, or a year that is over.
     */
    public function isArchived(DiscussionGroup $group): bool
    {
        return $group->archivedAt !== null || $this->isOfPastYear($group);
    }

    /**
     * Whether the group's scout year ended before the public year began.
     * False for a year-less group and for a group of the current or of a
     * future year.
     */
    public function isOfPastYear(DiscussionGroup $group): bool
    {
        if ($group->scoutYearId === null) {
            return false;
        }

        $endDate = $this->endDateOf($group->scoutYearId);
        if ($endDate === null) {
            // A year that no longer resolves is no evidence of anything;
            // archiving on it would lock a group for a missing row.
            return false;
        }

        return $endDate < $this->publicYearStartDate();
    }

    /**
     * A moderator may reopen an archived group unless it belongs to a past
     * year — that archive is final, whatever year the session previews.
     */
    public function canReopen(DiscussionGroup $group): bool
    {
        return $this->isArchived($group) && !$this->isOfPastYear($group);
    }

    /**
     * The first day of the public year, `Y-m-d` — every year that ended
     * before it is past. Exposed so the repository queries that mirror
     * this rule compare against the very same date.
     */
    public function publicYearStartDate(): string
    {
        return $this->publicYearStart ??= (string) $this->scoutYearResolver->getCurrentPublicYear()['start_date'];
    }

    private function endDateOf(int $scoutYearId): ?string
    {
        if ($this->endDates === null || !array_key_exists($scoutYearId, $this->endDates)) {
            $this->endDates = [];
            foreach ($this->scoutYearService->getAll() as $year) {
                $this->endDates[(int) $year['id']] = (string) $year['end_date'];
            }
            // Remembered as unknown, so a dangling id costs one reload and
            // not one per group that carries it.
            $this->endDates[$scoutYearId] ??= null;
        }

        return $this->endDates[$scoutYearId] ?? null;
    }
}
