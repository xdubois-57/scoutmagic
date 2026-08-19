<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * One member's membership in one section for one scout year, bucketed by
 * role. The bucket is derived from functions.role exactly the way
 * SectionService::getSectionStaff()/getSectionAnimes() already split
 * chief/admin (staff) from everyone else — this only adds a third bucket
 * (intendant) so all three can be told apart at once, instead of two
 * separate positive/negative queries. No new notion of "animateur" /
 * "intendant" / "animé" is introduced: it is still exactly functions.role.
 */
final class SectionRosterEntry
{
    public const BUCKET_ANIMATEUR = 'animateur';
    public const BUCKET_INTENDANT = 'intendant';
    public const BUCKET_ANIME = 'anime';

    public function __construct(
        public readonly int $sectionId,
        public readonly int $ageBranchId,
        public readonly int $memberYearId,
        public readonly int $memberId,
        public readonly string $bucket,
        public readonly string $functionLabel,
        public readonly bool $isMainFunction
    ) {
    }

    public static function bucketForRole(string $functionRole): string
    {
        return match ($functionRole) {
            'intendant' => self::BUCKET_INTENDANT,
            'chief', 'admin' => self::BUCKET_ANIMATEUR,
            default => self::BUCKET_ANIME,
        };
    }
}
