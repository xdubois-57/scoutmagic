<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/** What {@see EmailHistoryImportInterface::import()} wrote. */
final class ImportedEmailResult
{
    public function __construct(
        public readonly int $emailId,
        public readonly int $deliveries,
        public readonly int $targets,
        public readonly int $attachments,
        public readonly int $attachmentsRefused
    ) {
    }
}
