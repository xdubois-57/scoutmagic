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
 * **What is asserted is that no entry APPEARED**, never that the directory
 * is unchanged. `sys_get_temp_dir()` is shared with every other process on
 * the machine — the e2e coverage fragments (`scoutmagic-e2e-cov-…`), other
 * tests' own scratch files, and the CI runner's `runc-process…` — so a
 * whole-directory comparison fails whenever somebody else DELETES one of
 * theirs while the test runs. A file another process removed says nothing
 * about what the renderer wrote.
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
        Assert::assertSame(
            [],
            array_values(array_diff(self::entries(), $this->before)),
            $message !== '' ? $message : 'something was written to the shared temporary directory'
        );
    }

    /** @return list<string> */
    private static function entries(): array
    {
        $entries = scandir(sys_get_temp_dir());
        $entries = $entries === false ? [] : $entries;
        sort($entries);

        return array_values($entries);
    }
}
