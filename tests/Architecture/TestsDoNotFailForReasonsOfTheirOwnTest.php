<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Two ways a test fails for a reason that is not the defect it watches,
 * each found the expensive way and each now refused mechanically.
 *
 * **A needle a `\uXXXX` escape can spell** (issue #533). `json_encode`
 * writes every non-ASCII byte as `\uXXXX`, so the four hex digits of an
 * escape spell a short needle that the data does not contain. A ciphertext
 * is random bytes, so it produces such escapes constantly, and a test that
 * asks « this fragment is stored nowhere in the clear » of a
 * `json_encode()` of the rows fails at random, on a pull request that
 * touched nothing near it. A needle is at risk exactly when it fits INSIDE
 * one escape — `u?[0-9a-f]{1,4}` — because the `\` and the `u` beginning
 * the next escape break anything longer. « 0478 » and « aaaa » are that
 * shape; « Marie » and « aaaabbbbccccdddd » are not. The fix is
 * {@see \Tests\NothingInClear}, which searches value by value.
 *
 * **A whole-directory comparison of `sys_get_temp_dir()`** (issue #535).
 * That directory is shared with every other process, so a comparison of
 * its contents before and after fails whenever somebody ELSE deletes one
 * of theirs — and asserting only on what APPEARED, which is where the
 * issue and the first fix both stopped, is not enough: a foreign process
 * CREATES files there too. A red `Checks / test` proved it by naming one,
 * `runc-process380233456`, on a pull request that touched neither the
 * module nor the test. What may be asserted is that no entry appeared
 * that the code under test could have written, which is
 * {@see \Tests\Modules\OfficialDocuments\TemporaryDirectoryWatch}'s only
 * job — and the reason it is shared rather than copied is that it WAS
 * copied twice, and only one copy was ever fixed.
 *
 * Both readers are shown on literal fixtures below, in both directions: a
 * reader that finds nothing would pass this file while enforcing nothing,
 * and so would one that condemns everything.
 */
final class TestsDoNotFailForReasonsOfTheirOwnTest extends TestCase
{
    /**
     * Raised only by DELETING the last offender, never by an exemption.
     * It is a floor, not a budget.
     */
    private const AT_LEAST_THIS_MANY_ASSERTIONS_ARE_READ = 60;

    /** The one file allowed to compare the shared temporary directory. */
    private const THE_SHARED_WATCH = 'tests/Modules/OfficialDocuments/TemporaryDirectoryWatch.php';

    /**
     * This file, which carries both rules' counter-examples as literal
     * fixtures and would otherwise report itself. Asserted to BE this file
     * by {@see testTheExemptionNamesThisFileAndNothingElse()}, so it cannot
     * quietly become an exemption for somebody else's offender.
     */
    private const THE_FILE_THAT_CARRIES_THE_FIXTURES = 'tests/Architecture/TestsDoNotFailForReasonsOfTheirOwnTest.php';

    public function testNoAbsenceIsAssertedInsideAJsonRenderingWithASpellableNeedle(): void
    {
        $read = 0;
        $offenders = [];
        foreach (self::testSources() as $path => $source) {
            if ($path === self::THE_FILE_THAT_CARRIES_THE_FIXTURES) {
                continue;
            }
            foreach (self::absencesSearchedInJson($source) as $line => $needle) {
                ++$read;
                if (self::anEscapeCanSpell($needle)) {
                    $offenders[] = $path . ':' . $line . ' searches « ' . $needle . ' »';
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_ASSERTIONS_ARE_READ,
            $read,
            'the reader found almost nothing to examine, so it is no longer reading what it thinks it is'
        );
        $this->assertSame(
            [],
            $offenders,
            "these assertions can be failed by the encoding rather than by the data.\n"
            . "Search value by value through Tests\\NothingInClear instead:\n  "
            . implode("\n  ", $offenders) . "\n"
        );
    }

    public function testOnlyTheSharedWatchComparesTheTemporaryDirectory(): void
    {
        $offenders = [];
        foreach (self::testSources() as $path => $source) {
            if ($path === self::THE_SHARED_WATCH) {
                continue;
            }
            // Stripped first, for the same reason as the other reader: the
            // fixed test EXPLAINS the whole-directory comparison it no
            // longer does.
            if (preg_match('/scandir\s*\(\s*sys_get_temp_dir\s*\(\s*\)\s*\)/', self::withoutComments($source)) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'a comparison of the shared temporary directory fails when another process deletes a file of its own. '
            . 'Use Tests\\Modules\\OfficialDocuments\\TemporaryDirectoryWatch, which asserts that nothing APPEARED: '
            . implode(', ', $offenders)
        );
    }

    /**
     * The shared watch has to still BE there, and still be the thing the
     * rule above points at — a rule whose subject was renamed away is a
     * rule that quietly stopped applying.
     */
    public function testTheSharedWatchExistsAndComparesOnlyWhatAppeared(): void
    {
        $source = file_get_contents(self::repositoryRoot() . '/' . self::THE_SHARED_WATCH);
        $this->assertIsString($source);
        $this->assertStringContainsString('array_diff(self::entries(), $this->before)', $source);
        $this->assertStringContainsString('function assertNothingAppeared', $source);
    }

    public function testTheExemptionNamesThisFileAndNothingElse(): void
    {
        $this->assertSame(
            self::THE_FILE_THAT_CARRIES_THE_FIXTURES,
            str_replace(self::repositoryRoot() . '/', '', __FILE__),
            'the exemption must name this file, never another one'
        );
    }

    // ---------------------------------------------------------------- readers

    /**
     * A needle is at risk exactly when it fits inside ONE escape:
     * `\uXXXX` contributes a backslash, a `u` and four LOWERCASE hex
     * digits, and the backslash of the next escape breaks anything that
     * would span two.
     */
    private static function anEscapeCanSpell(string $needle): bool
    {
        return preg_match('/^u?[0-9a-f]{1,4}$/', $needle) === 1;
    }

    /**
     * Every literal needle whose haystack is a JSON rendering, keyed by
     * the line it sits on. Comments are stripped first: the fix for #533
     * EXPLAINS the trap, and says `json_encode` three times while doing so.
     *
     * @return array<int, string>
     */
    private static function absencesSearchedInJson(string $source): array
    {
        $code = self::withoutComments($source);

        $encoded = [];
        if (preg_match_all('/(\$\w+)\s*=\s*(?:\(string\)\s*)?json_encode\(/', $code, $assignments) > 0) {
            $encoded = array_unique($assignments[1]);
        }

        $needles = [];
        if (preg_match_all('/assertStringNotContainsString\(\s*(.+?)\s*,\s*(.+?)\s*\)\s*;/s', $code, $calls, PREG_SET_ORDER) === 0) {
            return $needles;
        }

        foreach ($calls as $call) {
            [$whole, $needle, $haystack] = $call;
            if (!self::isAJsonRendering($haystack, $encoded)) {
                continue;
            }
            if (preg_match("/^'([^'\\\\]*)'$/", $needle, $literal) === 1
                || preg_match('/^"([^"$\\\\]*)"$/', $needle, $literal) === 1
            ) {
                $offset = strpos($code, $whole);
                $needles[$offset === false ? 0 : substr_count($code, "\n", 0, $offset) + 1] = $literal[1];
            }
        }

        return $needles;
    }

    /** @param list<string> $encoded */
    private static function isAJsonRendering(string $haystack, array $encoded): bool
    {
        if (str_contains($haystack, 'json_encode')) {
            return true;
        }
        foreach ($encoded as $variable) {
            if (preg_match('/' . preg_quote($variable, '/') . '\b/', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Without this, the sentence explaining the trap trips the reader that
     * enforces it.
     */
    private static function withoutComments(string $source): string
    {
        $fragment = !str_contains($source, '<?php');
        $kept = '';
        foreach (token_get_all($fragment ? '<?php ' . $source : $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $fragment ? substr($kept, strlen('<?php ')) : $kept;
    }

    /** @return array<string, string> repository-relative path => source */
    private static function testSources(): array
    {
        $root = self::repositoryRoot();
        $sources = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tests'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source !== false) {
                $sources[str_replace($root . '/', '', $file->getPathname())] = $source;
            }
        }
        ksort($sources);

        return $sources;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    // --------------------------------------------------------------- fixtures

    /**
     * A reader that condemned everything would pass both tests above just
     * as well as a correct one, so it is shown on a needle it must accept.
     */
    public function testTheReaderAcceptsANeedleNoEscapeCouldSpell(): void
    {
        foreach (['Marie', 'aaaabbbbccccdddd', 'BE0123456789', 'example.be', '$2y$'] as $safe) {
            $this->assertFalse(self::anEscapeCanSpell($safe), "« {$safe} » cannot be spelled by an escape");
        }
    }

    /** And on the two that actually bit, plus the `u` the escape carries. */
    public function testTheReaderRefusesTheNeedlesThatActuallyBit(): void
    {
        foreach (['0478', '0495', 'aaaa', 'u0478', 'd1b8'] as $risky) {
            $this->assertTrue(self::anEscapeCanSpell($risky), "« {$risky} » fits inside one escape");
        }
    }

    /**
     * Not deduced: `json_encode` is asked, and it produces the needle out
     * of bytes that do not contain it.
     */
    public function testJsonEncodeReallySpellsTheNeedleOutOfBytesThatLackIt(): void
    {
        $bytes = "\u{0478}";
        $this->assertStringNotContainsString('0478', $bytes, 'the bytes themselves do not contain the needle');
        $this->assertStringContainsString('0478', (string) json_encode([$bytes]), 'the encoding invents it');
    }

    /**
     * The other direction for the source reader: it must find the shape in
     * a literal fixture, or it is finding nothing anywhere.
     */
    public function testTheSourceReaderFindsBothShapesAndIgnoresComments(): void
    {
        $direct = <<<'PHP'
            $this->assertStringNotContainsString('0478', (string) json_encode($offer));
            PHP;
        $viaVariable = <<<'PHP'
            $dump = (string) json_encode($rows);
            $this->assertStringNotContainsString('aaaa', $dump);
            PHP;
        $notJson = <<<'PHP'
            $this->assertStringNotContainsString('0478', $row['phone']);
            PHP;
        $onlyInAComment = <<<'PHP'
            // json_encode(["Ѹ"]) contains 0478, which is why this does not use it.
            $this->assertStringNotContainsString('0478', $row['phone']);
            PHP;

        $this->assertSame(['0478'], array_values(self::absencesSearchedInJson($direct)));
        $this->assertSame(['aaaa'], array_values(self::absencesSearchedInJson($viaVariable)));
        $this->assertSame([], self::absencesSearchedInJson($notJson), 'a raw column is not a JSON rendering');
        $this->assertSame([], self::absencesSearchedInJson($onlyInAComment), 'a comment is not code');
    }
}
