<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * A secondary e-mail address of an imported request, in the state the old
 * site had it in: `valid` (confirmed there) or `pending` (not yet). Filed
 * as is, without a confirmation e-mail (Api\RequestImportWriter).
 */
final class ImportedSecondaryEmail
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';

    public function __construct(
        public readonly string $email,
        public readonly string $status
    ) {
    }
}
