<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Pdf;

/**
 * One section, as the printable document draws it — on the directory page
 * (one card) and on its own page (one header plus every portrait).
 *
 * $email is the SECTION's address. It is organizational rather than
 * personal (design.md §2.6, "Section email (organizational) -> Clear
 * VARCHAR"): it belongs to the section, survives a change of responsable,
 * and is therefore never filtered by the contacts setting — which is what
 * keeps the document useful when personal contacts are hidden.
 */
class SectionView
{
    /**
     * @param StaffView[] $staff every animateur of the section, the lead
     *        first when there is one — the directory card draws $lead, the
     *        section page draws all of them.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $branchName,
        /** Always resolved through Core\Member\SectionService::colorForSection(). */
        public readonly string $color,
        public readonly ?string $email,
        public readonly ?StaffView $lead,
        public readonly array $staff
    ) {
    }
}
