<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

class Backup
{
    /** @var string[] */
    public const TYPES = ['database', 'full_config', 'full_no_gallery', 'full_with_gallery', 'auto_update', 'auto_reset', 'auto_backup'];

    /** @var string[] */
    public const STATUSES = ['pending', 'in_progress', 'completed', 'failed'];

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly ?int $fileId,
        public readonly ?int $dbDumpFileId,
        public readonly string $status,
        public readonly ?int $requestedBy,
        public readonly ?string $errorMessage,
        public readonly string $createdAt,
        public readonly ?string $completedAt
    ) {
    }
}
