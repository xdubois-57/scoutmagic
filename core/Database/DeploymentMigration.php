<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * The deployment-time migration: the whole declared schema, in one pass.
 *
 * `public/cron.php` is the best place in the application to migrate, and
 * for a long time it was the only one that built a `MigrationRunner` and
 * never called it — dead code that nothing flagged, because no test and
 * no browser ever executes a cron script. That is the reason this lives
 * in a class rather than inline in the script: a seam a test can reach.
 *
 * Nothing here is load-bearing on its own. A unit with no real crontab is
 * still served by the deploy path, by the status poll of an update in
 * progress, and in the last resort by the migration-in-progress page.
 */
final class DeploymentMigration
{
    /**
     * Fifteen minutes, and deliberately not "no budget at all".
     *
     * A real crontab runs under the CLI SAPI, where `max_execution_time`
     * is 0: a single pass finishes however large the change, with no
     * checkpoint to resume from and nobody waiting on it. But passing
     * something absurd would only trade one failure mode — a half-migrated
     * schema — for a worse one: a cron pass that never returns and gets
     * killed mid-DDL by whatever supervises it. Fifteen minutes is far
     * beyond the largest measured migration (the slowest of 133 production
     * updates took 831 s in total, migration included) while still being a
     * number a supervisor can outlive.
     */
    public const CRON_BUDGET_SECONDS = 900;

    /**
     * @param string $basePath the installation root, the directory holding
     *                         `schema/` and `modules/`
     */
    public static function run(
        Connection $connection,
        string $basePath,
        int $budgetSeconds = self::CRON_BUDGET_SECONDS
    ): MigrationResult {
        return (new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser(),
            $budgetSeconds
        ))->migrate(SchemaFiles::all($basePath));
    }
}
