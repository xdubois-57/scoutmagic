<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Maintenance\Task\InstallUpdateHandler;
use Core\Maintenance\Task\RestoreBackupHandler;
use PHPUnit\Framework\TestCase;

/**
 * The process that replaces the files must never be the process that
 * migrates the schema.
 *
 * This replaces `Tests\Core\Database\SelfUpdateCompatibilityTest`, whose
 * subject moved rather than disappeared. That test pinned two
 * compatibility shims — six properties on `MigrationProgress`, one
 * parameter on `MigrationResult` — kept alive so the PREVIOUS version's
 * `MigrationRunner`, still in memory after `installFiles()` had replaced
 * the files under it, could keep working against those classes freshly
 * autoloaded from the new ones. The shims are gone, and they are gone
 * because that mixture cannot happen any more: both handlers now schedule
 * the migration and return, so a later pass — a different process, every
 * class loaded from the same files — does the migrating.
 *
 * What is asserted here is the condition that made the removal safe, and
 * nothing else. It is worth asserting structurally rather than
 * behaviourally because of how it fails: re-adding an inline `migrate()`
 * call to either handler compiles, passes every functional test, works
 * perfectly on an installation whose schema is already current — and
 * takes production down permanently the first time a release changes one
 * of those two classes, because every retry runs the same old code and
 * the site can never reach the version that would fix it. That is not
 * hypothetical: six consecutive rollbacks on scoutmagic.be over a single
 * removed constructor parameter.
 */
class SelfUpdateMigrationBoundaryTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function fileReplacingHandlers(): array
    {
        return [
            'an update installs the new files' => [InstallUpdateHandler::class, 'installFiles('],
            'a restore puts the old files back' => [RestoreBackupHandler::class, 'restoreFiles('],
        ];
    }

    /**
     * @param class-string $handler
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fileReplacingHandlers')]
    public function testTheMethodThatReplacesTheFilesNeverMigrates(string $handler, string $replacementCall): void
    {
        $method = $this->methodContaining($handler, $replacementCall);

        $this->assertNotNull(
            $method,
            "{$handler} no longer calls {$replacementCall} — this test's premise moved, check it still guards what it claims"
        );

        $this->assertStringNotContainsString(
            '->migrate(',
            $method,
            "The method that calls {$replacementCall} must not migrate: its own classes are the ones from a "
            . 'moment ago, while anything not yet loaded comes from the files it has just written. It must '
            . 'schedule the migration and return, so a different process does it.'
        );
    }

    /**
     * The other half, and it matters as much: an assertion that only said
     * "the replacing method does not migrate" would pass just as happily
     * on a handler that had stopped migrating altogether — a silently
     * un-migrated schema rather than a fixed one. So the same method must
     * hand the work off, and `resumeMigration()` must still be where it
     * lands.
     *
     * @param class-string $handler
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fileReplacingHandlers')]
    public function testTheReplacingMethodHandsTheMigrationToALaterPass(string $handler, string $replacementCall): void
    {
        $method = (string) $this->methodContaining($handler, $replacementCall);

        $this->assertStringContainsString(
            'scheduleMigrationResume(',
            $method,
            'not migrating here is only half of it — the migration still has to be handed to a later pass'
        );

        $this->assertStringContainsString(
            '->migrate(',
            $this->methodSource($handler, 'resumeMigration'),
            'and resumeMigration() is where it must land'
        );
    }

    /**
     * The source of the (one) method containing $needle, or null.
     */
    private function methodContaining(string $class, string $needle): ?string
    {
        foreach ((new \ReflectionClass($class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $source = $this->sourceOf($method);
            if (str_contains($source, $needle)) {
                return $source;
            }
        }

        return null;
    }

    private function methodSource(string $class, string $name): string
    {
        return $this->sourceOf(new \ReflectionMethod($class, $name));
    }

    private function sourceOf(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        $this->assertNotFalse($file);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $lines = (array) file($file);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
