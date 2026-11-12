<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

final class EmailAttachment
{
    /**
     * What an attachment may be, by its real content, and how large — for
     * one a chief adds (Controller\MassMailController) and for one an
     * import brings (Service\EmailHistoryImportService) alike.
     */
    public const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        public readonly int $id,
        public readonly int $emailId,
        public readonly int $fileId
    ) {
    }
}
