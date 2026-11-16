<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Support;

use Modules\Fees\Service\FederalScaleLookupService;

/**
 * Makes the real `requestPage()` callable, nothing more (#1281).
 *
 * The request is protected so that a test can replace the network; this one
 * keeps it exactly as it is and only widens its visibility, so a test can
 * point it at a local HTTP server and see what it does with each answer —
 * and, above all, that it never follows a redirect.
 */
final class RequestExposingFederalScaleLookupService extends FederalScaleLookupService
{
    public function request(string $url): ?string
    {
        return $this->requestPage($url);
    }
}
