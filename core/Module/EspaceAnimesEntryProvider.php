<?php

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to contribute extra per-visitor
 * entries to the "Espace animés" menu, alongside the core-built per-member
 * entries — without core depending on the module directly. Same precedent
 * as Core\Module\HomeBannerProvider / SectionResponsableProvider
 * (ARCHITECTURE.md §7.4). Introduced for the registration module: a
 * submitted, not-yet-a-member registration request gets its own entry,
 * sorted alongside the member entries (same MenuBuilder::GROUP_DYNAMIC
 * group) and before the separator.
 */
interface EspaceAnimesEntryProvider
{
    /**
     * Every entry this module wants to add for the identified visitor
     * whose session email is $email — typically none, one, or a handful.
     *
     * @return array<int, array{label: string, url: string, subtitle: ?string}>
     */
    public function getEntriesForEmail(string $email): array;
}
