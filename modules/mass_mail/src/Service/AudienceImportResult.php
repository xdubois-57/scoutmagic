<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Modules\MassMail\Repository\Audience;

/**
 * A successful mail-merge import: the stored audience plus the
 * non-blocking warnings — across rows, a Tiers or an address on several
 * rows ("one row = one communication" makes those legitimate, but usually
 * unintended); within one row, an Email column ignored beside a Tiers (the
 * messages go to the member's known addresses) and an address written twice
 * in one cell (it gets one message for that row).
 */
final class AudienceImportResult
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public readonly Audience $audience,
        public readonly array $warnings
    ) {
    }
}
