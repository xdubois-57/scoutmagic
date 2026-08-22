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
 * non-blocking warnings (duplicate Tiers/addresses across rows — "one
 * row = one email" makes those legitimate, but usually unintended).
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
