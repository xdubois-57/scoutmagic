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

    private function __construct()
    {
        $this->before = self::entries();
    }

    /** Start watching. Call {@see assertNothingAppeared()} afterwards. */
    public static function start(): self
    {
        return new self();
    }

    public function assertNothingAppeared(string $message = ''): void
    {
        $appeared = array_values(array_filter(
            array_diff(self::entries(), $this->before),
            static fn (string $entry): bool => preg_match(self::FOREIGN, $entry) !== 1
        ));

        Assert::assertSame(
            [],
            array_values($appeared),
            $message !== '' ? $message : 'something was written to the shared temporary directory'
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

    /** @return list<string> */
    private static function entries(): array
    {
        $entries = scandir(sys_get_temp_dir());
        $entries = $entries === false ? [] : $entries;
        sort($entries);

        return array_values($entries);
    }
}
