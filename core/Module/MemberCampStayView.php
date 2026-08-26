<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * One stay this member's section went on, as the admin member page draws
 * it.
 *
 * Presentation-ready, like every DTO in this folder: the module hands
 * over a place name, a period already worded and a path, rather than its
 * own camp vocabulary. Core never learns what a `stay_type` is, and the
 * module can reword « Grand camp » without touching a core template.
 */
final class MemberCampStayView
{
    /**
     * @param string $placeLabel  where they went
     * @param string $periodLabel when, already worded — a real range when the
     *        stay carries dates, a bare year when all the unit remembers is
     *        "on est allés là en 2012"
     * @param string $sectionName which of the member's sections went
     * @param string $scoutYearLabel the scout year the stay falls in
     * @param string $path        site-absolute path of the stay's own page
     */
    public function __construct(
        public readonly string $placeLabel,
        public readonly string $periodLabel,
        public readonly string $sectionName,
        public readonly string $scoutYearLabel,
        public readonly string $path,
    ) {
    }
}
