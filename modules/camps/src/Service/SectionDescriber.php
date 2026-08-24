<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Member\SectionService;

/**
 * Turns a camp's section ids into what the screens and the change history
 * show: a name and a colour.
 *
 * The colour comes from SectionService::colorForSection() and from
 * nowhere else. A unit renames its sections, and an administrator can
 * override a section's colour by hand — so a module mapping "Louveteaux"
 * to a hardcoded green would show the wrong colour on exactly the
 * installations that bothered to configure one, and no colour at all for
 * a section named "La Meute".
 *
 * A section id that no longer resolves is dropped rather than rendered as
 * a placeholder: schema.sql keeps no free-text fallback on purpose, and
 * "we no longer know" is the honest reading.
 */
class SectionDescriber
{
    public function __construct(private SectionService $sections)
    {
    }

    /**
     * @param int[] $sectionIds
     * @return array<int, array{id: int, name: string, color: string}>
     */
    public function describe(array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $found = $this->sections->findByIds($sectionIds);
        $described = [];
        foreach ($sectionIds as $id) {
            $section = $found[$id] ?? null;
            if ($section === null) {
                continue;
            }
            $described[] = [
                'id' => $section['id'],
                'name' => $section['name'] ?? $section['branch_name'],
                'color' => SectionService::colorForSection($section),
            ];
        }

        return $described;
    }

    /**
     * The same sections as one sentence, for the change history — which
     * stores strings and cannot render a badge. Empty reads as null so
     * the timeline shows "Sections · — → Éclaireurs" rather than an
     * empty span.
     *
     * @param int[] $sectionIds
     */
    public function describeAsText(array $sectionIds): ?string
    {
        $names = array_map(
            static fn(array $s): string => $s['name'],
            $this->describe($sectionIds)
        );

        return $names !== [] ? implode(', ', $names) : null;
    }
}
