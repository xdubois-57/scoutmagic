<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\Member\MemberProfile;

/**
 * Optional hook a module can implement to resolve a section's designated
 * "responsable" for the core Sections page
 * (Core\Http\Controller\PageController::sections()) — without core
 * depending on the module directly. Same precedent as
 * Core\Module\HomeBannerProvider / HomeNewsProvider (ARCHITECTURE.md
 * §7.4). The trombinoscope module implements this using its existing
 * per-function "responsable" flag (Core\Module\FunctionFlagsProvider).
 */
interface SectionResponsableProvider
{
    /**
     * The member profile of the section's designated "responsable" for the
     * given scout year, or null when none is configured.
     */
    public function getResponsable(int $sectionId, int $scoutYearId): ?MemberProfile;

    /**
     * getResponsable() for many sections at once — what a page listing
     * every section asks, so it costs one pass rather than one per section.
     *
     * @param array<int, int> $sectionIds
     * @return array<int, ?MemberProfile> keyed by section id, every requested id present
     */
    public function getResponsables(array $sectionIds, int $scoutYearId): array;
}
