<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageLink;

/**
 * What one `AnalysisResultApplier::applyAndReport()` actually wrote —
 * only the NEW rows, because both callbacks fire once per row, ever.
 */
final class AppliedAnalysis
{
    /**
     * @param array<int, array{consumerId: string, link: MessageLink}> $links
     * @param array<int, array{consumerId: string, candidate: MessageCandidate}> $candidates
     */
    public function __construct(
        public readonly array $links = [],
        public readonly array $candidates = []
    ) {
    }
}
