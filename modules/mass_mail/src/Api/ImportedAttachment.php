<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/**
 * An attachment of an imported email: a local file the caller has already
 * checked against its declared fingerprint, and the name to show. The
 * module copies it into its own storage; the caller removes `$path`.
 */
final class ImportedAttachment
{
    public function __construct(
        public readonly string $path,
        public readonly string $originalName
    ) {
    }
}
