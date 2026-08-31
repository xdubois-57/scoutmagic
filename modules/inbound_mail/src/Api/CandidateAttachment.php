<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What a consumer learns about an attachment **while deciding**, before
 * anything is written.
 *
 * **Metadata only, and that is the whole design.** There is no `bytes`
 * property and no file id, because there is no file yet: `analyze()` runs
 * inside the synchronisation, and reading a PDF there would blow through
 * `max_execution_time` on shared hosting — leaving the cursor unmoved, so
 * the identical doomed run repeats on every tick. Anything needing the
 * content goes to `analyzeStored()`, which gets an `InboundMessage` and a
 * bounded, deferred pass.
 *
 * `mimeType` is what the bytes actually are, sniffed by
 * `Service\AttachmentPolicy` — never what the email claimed. A message
 * announcing `application/pdf` over a ZIP is the ordinary shape of an
 * attack, not an edge case.
 */
class CandidateAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $sizeBytes
    ) {
    }
}
