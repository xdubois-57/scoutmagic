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
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $scoutYearId,
        public readonly ?int $sectionId,
        public readonly ?string $closedAt,
        public readonly string $lastActivityAt,
        public readonly int $createdByMemberId,
        public readonly string $createdAt
    ) {
    }

    public function isClosed(): bool
    {
        return $this->closedAt !== null;
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
