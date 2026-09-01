<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Scheduler\CronPassLock;
use PHPUnit\Framework\TestCase;

/**
 * `public/cron.php` takes the single-flight lock, takes it BEFORE it does
 * anything, and stands down silently when it cannot have it.
 *
 * Asserted structurally because of the one thing `Database\
 * DeploymentMigration` already records about this file: **no test and no
 * browser ever executes a cron script**. Whatever lives inline there is
 * code nothing can check — the lock itself lives in a class a test can
 * reach (`Tests\Core\Scheduler\CronPassLockTest` exercises it against two
 * real connections), and what is left here is the three properties of the
 * call site that would silently disappear in a refactor:
 *
 * 1. the lock is asked for at all;
 * 2. it is asked for BEFORE the schema migration, the abandoned-install
 *    sweep and the task pass — a lock taken after the expensive work is a
 *    lock that protects nothing;
 * 3. the pass that does not get it prints NOTHING. Anything a cron script
 *    writes to stdout becomes an email from the host's cron daemon, once
 *    per minute; a ten-minute backup would otherwise send ten "skipped"
 *    emails, and an operator who learns to ignore that mailbox is an
 *    operator who will miss the one message that mattered.
 */
class CronPassSingleFlightTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $source = @file_get_contents(dirname(__DIR__, 2) . '/public/cron.php');
        $this->assertNotFalse($source, 'public/cron.php must be readable');
        $this->source = $source;
    }

    public function testTheCronEntryPointTakesTheSingleFlightLock(): void
    {
        $this->assertStringContainsString(
            'CronPassLock::acquire(',
            $this->source,
            'public/cron.php must serialise itself against the previous minute\'s pass'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workThatMustHappenUnderTheLock(): array
    {
        return [
            'the schema migration (900 s budget)' => ['DeploymentMigration::run('],
            'the abandoned-install sweep' => ['AbandonedInstallSweeper::sweep('],
            'the task pass itself' => ['$runner->processOverdue('],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('workThatMustHappenUnderTheLock')]
    public function testTheLockIsTakenBeforeAnyOfTheWork(string $call): void
    {
        $acquire = strpos($this->source, 'CronPassLock::acquire(');
        $work = strpos($this->source, $call);

        $this->assertIsInt($acquire);
        $this->assertIsInt($work, "public/cron.php no longer contains {$call} — check this test still guards what it claims");
        $this->assertLessThan(
            $work,
            $acquire,
            "{$call} must run under the lock, not beside it"
        );
    }

    /**
     * The refusal branch: an exit and nothing else between the acquire and
     * the closing brace. `echo`, `print`, `fwrite`, `printf` and friends
     * all become an email every minute.
     */
    public function testAPassThatCannotGetTheLockExitsWithoutPrintingAnything(): void
    {
        $start = strpos($this->source, 'if (!\Core\Scheduler\CronPassLock::acquire(');
        $this->assertIsInt($start, 'the refusal must be a guard clause, so this test can read it');

        $end = strpos($this->source, '}', $start);
        $this->assertIsInt($end);
        $branch = substr($this->source, $start, $end - $start);

        $this->assertStringContainsString('exit(0)', $branch, 'a skipped pass is normal operation, not a failure');
        foreach (['echo', 'print', 'fwrite', 'printf', 'var_dump', 'error_log'] as $output) {
            $this->assertStringNotContainsString(
                $output,
                $branch,
                "a skipped pass must print nothing: {$output} in this branch is one cron email per minute"
            );
        }
    }

    /**
     * A lock nobody names the same way twice is no lock at all, and the
     * name is what the three advisory locks in this codebase are told
     * apart by.
     */
    public function testTheLockNameIsDistinctFromTheOtherAdvisoryLocks(): void
    {
        $this->assertNotSame(\Core\Maintenance\InstallLock::NAME, CronPassLock::NAME);
        $this->assertNotSame('scoutmagic_schema_migration', CronPassLock::NAME);
    }
}
