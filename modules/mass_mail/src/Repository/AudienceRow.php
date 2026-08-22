<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * One data row of an imported mail-merge Excel file = one email to send.
 * Exactly one of $memberId (row had a "Tiers", resolved against
 * members.desk_id at import) or $email (the row's own "Email" column
 * value — possibly several addresses separated by ";") is set —
 * Service\AudienceImportService refuses the whole file otherwise.
 */
final class AudienceRow
{
    /**
     * @param array<string, string> $data The full row as {header: value} — the merge variables.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $audienceId,
        public readonly int $rowIndex,
        public readonly ?int $memberId,
        public readonly ?string $email,
        public readonly array $data
    ) {
    }
}
