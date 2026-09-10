<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Two rules that decide whether the scout-year transition hands the site
 * to the right people, and that nothing else in the codebase notices when
 * they are broken.
 *
 * **An authorization question never creates a scout year.**
 * `Core\Config\ScoutYearService::getCurrentYear()` calls `ensureYear()`,
 * which `INSERT`s a row the first time it is asked on or after 1
 * September. That is correct where it lives — a site must boot on a
 * plausible year — and wrong on the path that answers « may this person
 * come in », which runs on every request, including an anonymous one and
 * including a scheduled job. `Core\ScoutYear\AuthorizationYearService`
 * therefore reads with `findByLabel()`/`findById()` and lives with « that
 * year does not exist here ».
 *
 * **The session preview never reaches an authorization decision.** A
 * preview is chosen by the person it would authorise, and is accepted for
 * any year that ever existed. Built into the set of years a role may be
 * resolved in, it would let whoever was Staff d'U in 2019-2020 preview
 * that year and walk back in — which is how the first attempt at this
 * work went wrong. It decides what is displayed, never who may see it
 * (ARCHITECTURE.md §4 « Scout year »).
 *
 * The objection that a chef d'unité previewing a year they are not admin
 * in would lock themselves out is answered by the design rather than by
 * an exception: their role still comes from the public year, which the
 * preview does not touch.
 *
 * The tokenizer rather than a regex, deliberately: the files below all
 * document in prose why they do not do these things, and that
 * documentation must never be what fails this test.
 */
class AuthorizationYearsAreReadOnlyTest extends TestCase
{
    /**
     * The authorization path: the service that builds the set of years,
     * the value object it returns, and the two callers that turn that set
     * into a role and into a yes/no at the door.
     *
     * A new consumer of `AuthorizationYears` that answers an access
     * question belongs on this list, in the same change.
     */
    private const GUARDED_FILES = [
        'core/ScoutYear/AuthorizationYearService.php',
        'core/ScoutYear/AuthorizationYears.php',
        'core/ScoutYear/StaffYearEligibility.php',
        'core/Security/RoleResolver.php',
        'core/Security/SessionRevalidator.php',
    ];

    /** Both create a scout year as a side effect of being asked about one. */
    private const YEAR_CREATING_METHODS = ['getCurrentYear', 'ensureYear'];

    /** The session preview, by the two names it travels under. */
    private const PREVIEW_SYMBOLS = ['ScoutYearSession', 'getPreviewId'];

    public function testNoAuthorizationYearFileCreatesAScoutYear(): void
    {
        $offenders = $this->offenders(self::YEAR_CREATING_METHODS);

        $this->assertSame(
            [],
            $offenders,
            "These call a scout-year CREATING method on the authorization path:\n  "
            . implode("\n  ", $offenders)
            . "\nUse ScoutYearService::findByLabel()/findById(). getCurrentYear() calls ensureYear(), which INSERTs"
            . " a row on 1 September — a question about who may come in must not write one."
        );
    }

    public function testNoAuthorizationYearFileReadsTheSessionPreview(): void
    {
        $offenders = $this->offenders(self::PREVIEW_SYMBOLS);

        $this->assertSame(
            [],
            $offenders,
            "These reach the session preview from the authorization path:\n  "
            . implode("\n  ", $offenders)
            . "\nA preview is chosen by the person it would authorise, over any year that ever existed. It decides"
            . " what is displayed, never who may see it."
        );
    }

    /**
     * @param string[] $symbols
     * @return list<string>
     */
    private function offenders(array $symbols): array
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::GUARDED_FILES as $relativePath) {
            $absolute = $root . '/' . $relativePath;
            $this->assertFileExists($absolute, "{$relativePath} is guarded by this test but no longer exists.");

            foreach (self::codeIdentifiers($absolute) as [$name, $line]) {
                if (in_array($name, $symbols, true)) {
                    $offenders[] = "{$relativePath}:{$line} ({$name})";
                }
            }
        }

        sort($offenders);

        return $offenders;
    }

    /**
     * Identifiers that are real code — a name inside a comment, a
     * docblock or a string is not one.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function codeIdentifiers(string $path): array
    {
        $found = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                $found[] = [$token[1], $token[2]];
            }
        }

        return $found;
    }
}
