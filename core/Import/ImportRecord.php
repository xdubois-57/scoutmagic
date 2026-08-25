<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * One Desk import, as the history screen and the report page read it.
 *
 * `import_journal` is the parent row of everything an import produced —
 * the CSV it consumed, the roster snapshot it froze — so this carries the
 * ids of both. No personal data: the importer is a `user_accounts.id`,
 * and turning that into a readable name is the view layer's job, done
 * once, for the handful of rows a screen actually shows.
 */
final class ImportRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $scoutYearId,
        public readonly ?int $userAccountId,
        public readonly int $lineCount,
        public readonly int $memberCount,
        public readonly int $newFunctionsCount,
        public readonly ?int $fileId,
        public readonly \DateTimeImmutable $importedAt
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['scout_year_id'],
            $row['user_account_id'] === null ? null : (int) $row['user_account_id'],
            (int) $row['line_count'],
            (int) $row['member_count'],
            (int) $row['new_functions_count'],
            $row['file_id'] === null ? null : (int) $row['file_id'],
            new \DateTimeImmutable((string) $row['imported_at'])
        );
    }
}
