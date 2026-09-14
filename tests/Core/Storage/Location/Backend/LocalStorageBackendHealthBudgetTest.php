<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend;

use Core\Storage\Location\Backend\LocalStorageBackend;
use PHPUnit\Framework\TestCase;

/**
 * « Le dossier existe » stopped being a health check the moment a location
 * could name a network mount.
 *
 * A mount has a third state between working and failing: it can become
 * *slow*. Seconds per `stat()`, minutes for a listing. Without a budget
 * that state renders as a configuration page which never finishes — so the
 * one screen an administrator would use to repair the mount is the one
 * screen they cannot open, and the diagnosis becomes impossible exactly
 * when it is needed.
 *
 * **The clock is moved rather than waited on.** Every double below reads a
 * clock that jumps one second per read, so the budget is crossed by
 * arithmetic instead of by sleeping — a test that really waited would add
 * its own seconds to every CI run to prove a rule about subtraction.
 *
 * `testConnection()` reads the clock five times on the happy path: once to
 * start, then once before each of the four steps it may refuse to begin.
 * So a budget of N seconds is what decides which step is the one that
 * gives up, and the ladder of tests below walks every rung of it.
 */
class LocalStorageBackendHealthBudgetTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/scoutmagic-health-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) scandir($this->directory) ?: [] as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                @unlink($this->directory . '/' . $entry);
            }
        }
        @rmdir($this->directory);
    }

    /** A directory that answers instantly passes, and the budget is never in the way. */
    public function testAnOrdinaryDirectoryPassesWellInsideTheBudget(): void
    {
        $backend = new LocalStorageBackend($this->directory);

        $this->assertNull($backend->testConnection());
    }

    /**
     * The rule the roadmap asks for: a directory that has stopped
     * answering fails **at the end of the delay**, rather than blocking
     * the page that asked.
     */
    public function testADirectoryThatStopsAnsweringFailsOnTheBudgetRatherThanHanging(): void
    {
        $error = $this->slowBackend(1.0)->testConnection();

        $this->assertNotNull($error, 'A location that will not answer in time must not report itself healthy.');
        $this->assertStringContainsString('trop de temps à répondre', $error);
        $this->assertStringContainsString('délai maximal : 1 s', $error);
        $this->assertStringContainsString('dossier réseau', $error);
    }

    /**
     * The sentence names the step that ran out, because « en écrivant » and
     * « en relisant » send an administrator to different places — a
     * read-only mount and a full disk are not the same repair.
     *
     * @param float $budget      seconds the probe is allowed
     * @param string $expectedStep the French fragment naming where it gave up
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('budgets')]
    public function testTheRefusalNamesTheStepThatRanOutOfTime(float $budget, string $expectedStep): void
    {
        $this->assertStringContainsString($expectedStep, (string) $this->slowBackend($budget)->testConnection());
    }

    /** @return array<string, array{float, string}> */
    public static function budgets(): array
    {
        return [
            'gone before the folder could be found' => [1.0, 'en cherchant le dossier'],
            'gone before its permissions could be read' => [2.0, 'en vérifiant les droits du dossier'],
            'gone while the witness was being written' => [3.0, 'en écrivant un fichier témoin'],
            'gone while the witness was being read back' => [4.0, 'en relisant le fichier témoin'],
        ];
    }

    /**
     * A probe that gives up on time must not leave its witness behind: the
     * page is opened again, the check runs again, and a location whose
     * mount is merely slow would collect one more stray file every time.
     */
    public function testGivingUpOnTimeLeavesNoWitnessFileBehind(): void
    {
        // 3.0 expires on the check immediately after the witness has been
        // written — the one moment where giving up could strand a file.
        $error = $this->slowBackend(3.0)->testConnection();

        $this->assertNotNull($error);
        $this->assertSame([], $this->witnessFiles(), 'The witness file must be removed on every way out.');
    }

    /**
     * A round trip that completed slowly is a location that WORKS.
     *
     * Five clock reads at one second each means the whole check takes four
     * seconds of the double's time, and a five-second budget covers it —
     * so every step ran, and the verdict is healthy. Failing it instead
     * would take a working gallery offline over a disk that was merely
     * busy: the budget refuses to WAIT for the next operation, it never
     * withdraws a success already obtained.
     */
    public function testASlowButCompleteRoundTripIsStillHealthy(): void
    {
        $this->assertNull($this->slowBackend(5.0)->testConnection());
        $this->assertSame([], $this->witnessFiles(), 'A healthy check cleans up after itself too.');
    }

    /** A backend whose clock advances a second on every reading. */
    private function slowBackend(float $budgetSeconds): LocalStorageBackend
    {
        return new class ($this->directory, $budgetSeconds) extends LocalStorageBackend {
            private float $clock = 0.0;

            /** @phpstan-impure */
            protected function now(): float
            {
                $this->clock += 1.0;

                return $this->clock;
            }
        };
    }

    /** @return list<string> */
    private function witnessFiles(): array
    {
        $entries = array_filter(
            (array) scandir($this->directory),
            static fn ($entry): bool => is_string($entry) && str_starts_with($entry, '.scoutmagic-healthcheck-')
        );

        return array_values(array_map(static fn ($entry): string => (string) $entry, $entries));
    }
}
