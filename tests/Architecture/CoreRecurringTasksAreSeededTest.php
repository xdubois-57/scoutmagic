<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Scheduler\CoreTaskHandlers;
use PHPUnit\Framework\TestCase;

/**
 * A core task that re-arms itself must also be armed once, by a composition
 * root — or it never runs at all (issue #575).
 *
 * A recurring chain has two halves. The handler re-arms its successor at the
 * end of every run (`rearm()`/`rearmAfter()`); a composition root arms the
 * FIRST occurrence (`seed()`), on every request, idempotently. Only the first
 * half is visible where the task is written, and it reads as complete:
 * `PurgeSeedCopiesHandler` and `PurgeDmarcReportsHandler` were declared in
 * `CoreTaskHandlers`, registered on both runners, re-arming themselves
 * faithfully — and never seeded. So they never ran: no seed copy ever turned
 * into « jamais arrivé », the routing that reads those verdicts had nothing
 * to read, and the ninety-day retentions the RGPD page promises were not
 * enforced. Nothing failed, nothing logged; a task that never runs has no
 * error to report.
 *
 * The rule, then: every handler in `CoreTaskHandlers` whose source re-arms
 * itself is named by a `seed('core', …)` in `public/index.php`,
 * `public/cron.php` or `public/scheduler-bootstrap.php`. One-shot tasks —
 * a backup asked for from a button, a support package, a notification batch
 * — never re-arm, and are left alone.
 *
 * **What it reads, and therefore what it cannot see.** The task key of a
 * seed is its second argument, taken as written: a string literal, or a
 * `Class::CONSTANT` resolved through the class. A key held in a variable
 * would not be recognised — none of core's seeds is written that way, and
 * `testEverySeedKeyIsReadable()` fails the day one is, rather than letting
 * the scan quietly miss it. Module tasks are out of scope: they are
 * declared in their manifests and seeded by their own `bootstrap()`.
 */
final class CoreRecurringTasksAreSeededTest extends TestCase
{
    private const COMPOSITION_ROOTS = [
        'public/index.php',
        'public/cron.php',
        'public/scheduler-bootstrap.php',
    ];

    public function testEveryRecurringCoreTaskIsSeeded(): void
    {
        $seeded = self::seededCoreKeys();

        $unseeded = [];
        foreach (self::recurringCoreTasks() as $key => $class) {
            if (!isset($seeded[$key])) {
                $unseeded[] = $key . ' (' . $class . ')';
            }
        }

        $this->assertSame(
            [],
            $unseeded,
            "These core tasks re-arm themselves after every run, but no composition root ever arms\n"
            . "the first one, so they never run. Add a \$schedulerService->seed('core', …) next to the\n"
            . "other core seeds in public/index.php:\n  " . implode("\n  ", $unseeded)
        );
    }

    /**
     * The scan is only worth anything if it reads what is really there: a
     * pattern that stopped matching would find no seed, and a test that
     * finds no recurring task would pass for ever.
     */
    public function testTheScanFindsBothHalves(): void
    {
        $this->assertGreaterThanOrEqual(15, count(self::recurringCoreTasks()), 'recurring core tasks found');
        $this->assertGreaterThanOrEqual(15, count(self::seededCoreKeys()), 'core seeds found');
        $this->assertArrayHasKey('auto_backup', self::seededCoreKeys(), 'a literal key is read');
        $this->assertArrayHasKey(
            \Core\Mail\Task\PurgeSentEmailClaimsHandler::TASK_KEY,
            self::seededCoreKeys(),
            'a Class::TASK_KEY key is resolved'
        );
    }

    /** A seed whose key this scan cannot read would slip past the rule above. */
    public function testEverySeedKeyIsReadable(): void
    {
        $unreadable = [];
        foreach (self::coreSeedKeyExpressions() as [$file, $expression]) {
            if (self::resolve($expression) === null) {
                $unreadable[] = $file . ': ' . $expression;
            }
        }

        $this->assertSame([], $unreadable, 'Write the task key as a literal or a Class::CONSTANT.');
    }

    /**
     * @return array<string, class-string> task key => handler, for the handlers that re-arm themselves
     */
    private static function recurringCoreTasks(): array
    {
        $recurring = [];
        foreach (CoreTaskHandlers::all() as $key => $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $source = $file !== false ? (string) file_get_contents($file) : '';
            if (preg_match('/->\s*rearm(?:After)?\s*\(/', $source) === 1) {
                $recurring[$key] = $class;
            }
        }

        return $recurring;
    }

    /**
     * @return array<string, true>
     */
    private static function seededCoreKeys(): array
    {
        $keys = [];
        foreach (self::coreSeedKeyExpressions() as [, $expression]) {
            $key = self::resolve($expression);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * The second argument of every `seed('core', …)` / `seedAfter('core', …)`
     * in the composition roots, as written.
     *
     * @return list<array{string, string}> [file, expression]
     */
    private static function coreSeedKeyExpressions(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (self::COMPOSITION_ROOTS as $relative) {
            $path = $root . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            preg_match_all(
                "/->\\s*seed(?:After)?\\s*\\(\\s*'core'\\s*,\\s*([^,]+?)\\s*,/",
                (string) file_get_contents($path),
                $matches
            );
            foreach ($matches[1] as $expression) {
                $found[] = [$relative, trim($expression)];
            }
        }

        return $found;
    }

    private static function resolve(string $expression): ?string
    {
        if (preg_match("/^'([a-z0-9_]+)'$/", $expression, $literal) === 1) {
            return $literal[1];
        }
        if (preg_match('/^\\\\?([A-Za-z0-9_\\\\]+)::([A-Z_]+)$/', $expression, $constant) === 1) {
            $name = $constant[1] . '::' . $constant[2];

            return defined($name) ? (string) constant($name) : null;
        }

        return null;
    }
}
