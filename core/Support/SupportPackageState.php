<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

/**
 * The two `settings` keys that say which support package currently exists
 * and when it was produced (ARCHITECTURE.md §8.47).
 *
 * Two settings rather than a table: exactly one package is ever kept, so a
 * table would hold at most one row and buy nothing. The generation stamp is
 * kept here rather than read back off `files.created_at` because the
 * retention rule is this feature's own, not the file store's.
 */
final class SupportPackageState
{
    public const FILE_ID = 'support_package_file_id';
    public const GENERATED_AT = 'support_package_generated_at';
}
