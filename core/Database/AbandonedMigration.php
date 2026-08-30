<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * The record MigrationRunner leaves when it gives up: the same statements
 * failed on several consecutive passes, so the attempt was abandoned and
 * the schema hash cached anyway — because a site held on the migration
 * progress page forever is a worse outcome than a schema missing a column.
 *
 * That trade-off is only defensible if the second outcome is visible, so
 * this is what the Maintenance page's banner is built from. It exists as a
 * type rather than as an array decoded in two places because the writer
 * (MigrationRunner) and the reader (Core\Http\Controller\
 * MaintenanceController) are on opposite sides of the application and
 * would otherwise each carry their own idea of the shape.
 */
final class AbandonedMigration
{
    /**
     * @param array<string> $failedStatements DDL text only — a statement
     *   names tables and columns, never a row's contents, so this is safe
     *   to render on a page and to carry into a support package.
     */
    public function __construct(
        public readonly string $at,
        public readonly array $failedStatements
    ) {
    }

    /**
     * Null for "no abandonment on record", which is also the answer for a
     * malformed row — a corrupted setting must never break the Maintenance
     * page, and a banner nobody can explain is worse than none.
     */
    public static function fromJson(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['at']) || !is_string($data['at'])) {
            return null;
        }

        $statements = is_array($data['failed_statements'] ?? null)
            ? array_values(array_filter($data['failed_statements'], 'is_string'))
            : [];

        return new self($data['at'], $statements);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['at' => $this->at, 'failed_statements' => $this->failedStatements];
    }
}
