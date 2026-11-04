<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/** Read-only code equivalence for validation, before mappings may be created. */
final class DeskCodeIdentityRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Give equivalent codes the same identity, including codes not yet stored.
     * All three mapping columns use utf8mb4_unicode_ci on the installed schema;
     * SQLite's test schema uses binary equality. Ask that engine rather than
     * approximate its case, accent, width, ignorable and padding rules in PHP.
     *
     * One bound JSON array avoids a query per CSV row and placeholder limits.
     * Compare complete strings and group numeric ordinals: sorting/grouping a
     * LONGTEXT code itself could truncate comparisons at max_sort_length.
     * Only SELECT is issued: no mapping rows or explicit temporary tables.
     *
     * @param list<string> $codes
     * @return array<string, int> identities meaningful only within this batch
     */
    public function identify(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }

        $sql = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? <<<'SQL'
                WITH codes AS (
                    SELECT ordinal, code
                    FROM JSON_TABLE(?, '$[*]' COLUMNS (
                        ordinal FOR ORDINALITY,
                        code LONGTEXT CHARACTER SET utf8mb4 PATH '$'
                    )) AS raw_codes
                )
                SELECT lhs.ordinal, MIN(rhs.ordinal) AS identity
                FROM codes lhs JOIN codes rhs
                    ON lhs.code COLLATE utf8mb4_unicode_ci = rhs.code COLLATE utf8mb4_unicode_ci
                GROUP BY lhs.ordinal
                SQL
            : <<<'SQL'
                WITH codes AS (
                    SELECT CAST(key AS INTEGER) + 1 AS ordinal, value AS code FROM json_each(?)
                )
                SELECT lhs.ordinal, MIN(rhs.ordinal) AS identity
                FROM codes lhs JOIN codes rhs ON lhs.code = rhs.code
                GROUP BY lhs.ordinal
                SQL;
        $statement = $this->pdo->prepare($sql);
        $statement->execute([json_encode($codes, JSON_THROW_ON_ERROR)]);

        $identities = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $identities[$codes[(int) $row['ordinal'] - 1]] = (int) $row['identity'];
        }

        return $identities;
    }
}
