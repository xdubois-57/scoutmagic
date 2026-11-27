<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * One row of discussion_groups. Carries ids and flags only — never a
 * member's name or contact detail (see schema.sql's header).
 */
class DiscussionGroup
{
    /** Every member of the group may publish — the default. */
    public const POSTING_MEMBERS = 'members';

    /**
     * Only the group's moderators publish. Commenting, reacting and
     * answering a poll stay open to every member either way — see
     * Service\GroupAccessService::canPost() versus canParticipate().
     */
    public const POSTING_MODERATORS = 'moderators';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $scoutYearId,
        public readonly ?int $sectionId,
        // When the group was archived — by a moderator, by the inactivity
        // task or by the end-of-year task — and NULL while it is active.
        // Stored in the `closed_at` column, kept under its old name on
        // purpose (issue #1350): the schema comparator never drops a
        // column, so a rename would strand every date already written.
        // NOT the whole answer to « is it archived? »: a group of a past
        // scout year is archived before any date reaches it, which is why
        // nothing reads this alone — Service\GroupArchiveRule does.
        public readonly ?string $archivedAt,
        public readonly string $lastActivityAt,
        // NULL for a group Task\EnsureSectionGroupsHandler created — see
        // schema.sql for why an automatic creation names no creator.
        public readonly ?int $createdByMemberId,
        public readonly string $createdAt,
        // Last, nullable, so every existing positional construction of
        // this DTO (tests included) keeps working unchanged — same
        // append-only discipline as Modules\Gallery\Repository\Album's
        // own trailing owner_type/owner_id pair. Set once, lazily, by
        // Service\PostMediaService::ensureAlbumId() on the group's first
        // media post.
        public readonly ?int $galleryAlbumId = null,
        // Appended last, nullable, for the same append-only reason as
        // galleryAlbumId above: every existing positional construction of
        // this DTO keeps working unchanged. An optional one-liner saying
        // what the group is for, editable by a moderator.
        public readonly ?string $description = null,
        // Appended last for the same append-only reason as the two above.
        // One of the POSTING_* constants; anything else read from a row
        // is normalised to POSTING_MEMBERS by the repository, so a
        // caller never has to defend against a third value.
        public readonly string $postingPolicy = self::POSTING_MEMBERS
    ) {
    }

    /**
     * A section group is one created for a section and a scout year; an
     * invitation group has no section of its own (it may still have
     * invited sections, in discussion_group_sections).
     */
    public function isSectionGroup(): bool
    {
        return $this->sectionId !== null;
    }
}
