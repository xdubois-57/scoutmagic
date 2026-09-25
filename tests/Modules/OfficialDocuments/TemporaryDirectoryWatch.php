<?php

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments;

use PHPUnit\Framework\Assert;

/**
 * « Nothing was written to the temporary directory » — asked once, so the
 * two tests that ask it cannot answer it differently.
 *
 * The rule this serves is the Documents chantier's, and it matters on
 * shared hosting: a temporary file is a file somebody else's process can
 * read, so a renderer that leaks one leaks a health sheet.
 *
 * **Two things are asserted away, and the second cost a red CI to learn.**
 * `sys_get_temp_dir()` is shared with every other process on the machine,
 * so a whole-directory comparison fails when somebody else DELETES one of
 * theirs — and asserting only on what APPEARED, which is where issue #535
 * and the first version of this class stopped, is not enough either: a
 * foreign process CREATES files there too, and the CI failure that proved
 * it named one, `runc-process380233456`, appearing inside the render's own
 * window.
 *
 * So what is asserted is that no entry appeared THAT THIS RENDERER COULD
 * HAVE WRITTEN. The two foreign shapes are named below rather than
 * tolerated silently, and anything else appearing still fails — the test
 * keeps its teeth for the only thing it is about.
 *
 * It is a shared helper rather than a private method copied twice because
 * it already was copied twice, and only one copy was fixed:
 * `ParentalAuthorizationPdfServiceTest` learnt the lesson from a red CI
 * job, `TemplateGridTest` kept the whole-directory comparison and failed
 * the same way months later, on a documentation-only pull request
 * (issue #535). One reader, one reason, one place to correct.
 */
final class TemporaryDirectoryWatch
{
    /** @var list<string> */
    private array $before;

    private function __construct(private readonly string $directory)
    {
        $this->before = self::entries($this->directory);
    }

    /**
     * Start watching. Call {@see assertNothingAppeared()} afterwards.
     *
     * `$directory` exists **only so that a failed scan is observable**, the
     * same way a clock is injected elsewhere in this suite. Production
     * callers pass nothing; one test points it at a path that is not there,
     * because that is the only way to reach the branch below and the
     * alternative is a guard nobody has ever seen work.
     */
    public static function start(?string $directory = null): self
    {
        return new self($directory ?? sys_get_temp_dir());
    }

    public function assertNothingAppeared(string $message = ''): void
    {
        $appeared = array_values(array_filter(
            array_diff(self::entries($this->directory), $this->before),
            static fn (string $entry): bool => preg_match(self::FOREIGN, $entry) !== 1
        ));

        // The entries are NAMED in the message, not left to the array
        // diff. A CI failure here is read once, by somebody who does not
        // have the directory in front of them, and « two arrays are not
        // identical » sends them looking for a file the log never told
        // them about — which is how the first version of this watch cost a
        // re-run instead of a reading.
        Assert::assertSame(
            [],
            $appeared,
            ($message !== '' ? $message : 'something was written to the shared temporary directory')
                . ($appeared === [] ? '' : ' — ' . implode(', ', $appeared))
        );
    }

    /**
     * Entries another process writes into the shared directory, which say
     * nothing about the code under test.
     *
     * `runc-process…` is the container runtime's own bookkeeping — it is
     * what made `Checks / test` red on a pull request that touched neither
     * this module nor this test. `scoutmagic-e2e-cov-…` is this
     * repository's browser-coverage fragments, written by the e2e job's
     * processes. Neither name is one a PDF renderer could produce, which is
     * the whole reason they can be named at all: a filter on « anything
     * that appeared » would have emptied the assertion.
     */
    private const FOREIGN = '/^(runc-process|scoutmagic-e2e-cov-)/';

    /**
     * @return list<string>
     *
     * **A failed scan FAILS, it does not read as an empty directory.**
     * `scandir()` documents `false` among its answers, and the old code
     * turned it into `[]` — which, on the closing scan, makes the
     * difference empty and `assertNothingAppeared()` pass without ever
     * having looked for the file it exists to find. A reviewer named it,
     * and it is precisely the defect this whole pull request is about: a
     * test that goes green for a reason that has nothing to do with what
     * it guards.
     */
    private static function entries(string $directory): array
    {
        $entries = scandir($directory);

        Assert::assertNotFalse(
            $entries,
            "the temporary directory {$directory} could not be read, so nothing below was actually checked"
        );

        sort($entries);

        return array_values($entries);
    }
}
