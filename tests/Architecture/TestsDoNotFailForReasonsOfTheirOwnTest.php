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
     *
     * **Both readers need it**, which is a smell worth naming rather than
     * hiding: what actually earns an exemption here is that a fixture is
     * TEXT, and a reader that knew text from code would need none. The mail
     * guard reached exactly that conclusion on the same day, from the same
     * class of review finding, and dropped its own exemption for it. These
     * two readers should become one; that is a change of its own, not a
     * rider on this one.
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
            foreach (self::absencesSearchedInJson($source) as ['line' => $line, 'needle' => $needle]) {
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
            // The same exemption the other reader already needs, for the
            // same reason and now for a second time: the fixtures that
            // prove this reader recognises the forbidden form are written
            // in the forbidden form.
            if ($path === self::THE_FILE_THAT_CARRIES_THE_FIXTURES) {
                continue;
            }
            // Stripped first, for the same reason as the other reader: the
            // fixed test EXPLAINS the whole-directory comparison it no
            // longer does.
            if (self::comparesTheTemporaryDirectory(self::withoutComments($source))) {
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
     * Every literal needle whose haystack is a JSON rendering, with the
     * line it sits on. Comments are stripped first: the fix for #533
     * EXPLAINS the trap, and says `json_encode` three times while doing so.
     *
     * **A list, not a map keyed by line.** Keyed by line, two byte-identical
     * assertions in one file collapsed into a single entry — `strpos()`
     * always answers with the FIRST occurrence, so both resolved to the
     * same key. `tests/Core/Net/WhoisRegistrationTest.php` has two such
     * pairs today: the floor of assertions read was undercounted by them,
     * and had either been an offender, one of the two places would never
     * have been reported. No risky needle can be lost that way — identical
     * text carries an identical needle — but a guard whose own count and
     * locations are unreliable is unreliable exactly when it fires.
     *
     * @return list<array{line: int, needle: string}>
     */
    private static function absencesSearchedInJson(string $source): array
    {
        $code = self::withoutComments($source);

        $encoded = [];
        if (preg_match_all('/(\$\w+)\s*=\s*(?:\(string\)\s*)?json_encode\(/', $code, $assignments) > 0) {
            $encoded = array_unique($assignments[1]);
        }

        // A copy of a rendering is a rendering. Chased to a fixed point
        // rather than one hop: `$b = $a; $c = $b;` is the same claim made
        // twice, and a reader that answers for one and not the other
        // teaches the shape that gets past it. The loop terminates because
        // a pass either adds a name or stops.
        preg_match_all('/(\$\w+)\s*=\s*(\$\w+)\s*;/', $code, $copies, PREG_SET_ORDER);
        do {
            $before = count($encoded);
            foreach ($copies as [, $target, $origin]) {
                if (in_array($origin, $encoded, true) && !in_array($target, $encoded, true)) {
                    $encoded[] = $target;
                }
            }
        } while (count($encoded) > $before);

        $encoded = array_values($encoded);

        $needles = [];
        if (preg_match_all(
            '/assertStringNotContainsString\(\s*(.+?)\s*,\s*(.+?)\s*\)\s*;/s',
            $code,
            $calls,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) === 0) {
            return $needles;
        }

        foreach ($calls as $call) {
            // The offset comes from the match itself rather than from a
            // search for its text: two identical calls are two calls.
            [$whole, $offset] = $call[0];
            $needle = $call[1][0];
            $haystack = $call[2][0];

            if (!self::isAJsonRendering($haystack, $encoded)) {
                continue;
            }
            if (preg_match("/^'([^'\\\\]*)'$/", $needle, $literal) === 1
                || preg_match('/^"([^"$\\\\]*)"$/', $needle, $literal) === 1
            ) {
                $needles[] = [
                    'line' => substr_count($code, "\n", 0, $offset) + 1,
                    'needle' => $literal[1],
                ];
            }
        }

        unset($whole);

        return $needles;
    }

    /**
     * Whether this source scans the shared temporary directory, **however
     * it spells the path**.
     *
     * The first version read `scandir(sys_get_temp_dir())` and nothing
     * else, which left the form a reviewer found live in
     * `HealthSheetPdfServiceTest`: the directory put in a variable first,
     * then scanned twice around the render. That is the same
     * whole-directory comparison issue #535 is about, one rename away from
     * a rule that was supposed to have retired it — and it would have gone
     * red the first time a foreign process removed a file of its own.
     *
     * A name assigned the directory EXACTLY is tracked, not one built from
     * it: `sys_get_temp_dir() . '/official-documents-' . …` is a directory
     * of the test's own, and scanning that is not this rule's business.
     */
    private static function comparesTheTemporaryDirectory(string $code): bool
    {
        if (preg_match('/scandir\s*\(\s*sys_get_temp_dir\s*\(\s*\)\s*\)/', $code) === 1) {
            return true;
        }

        if (preg_match_all(
            '/(\$\w+)\s*=\s*sys_get_temp_dir\s*\(\s*\)\s*;/',
            $code,
            $assignments
        ) === 0) {
            return false;
        }

        foreach (array_unique($assignments[1]) as $variable) {
            if (preg_match('/scandir\s*\(\s*' . preg_quote($variable, '/') . '\s*\)/', $code) === 1) {
                return true;
            }
        }

        return false;
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
                // Its newlines stay. A comment removed outright shortens
                // the source, and every line number computed afterwards is
                // then a line number in a file nobody has — which is what
                // this reader reports to whoever has to go and fix the
                // offender. Almost every test file here opens with a
                // docblock, so the offset was wrong essentially always.
                $kept .= str_repeat("\n", substr_count($token[1], "\n"));
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

        $this->assertSame(['0478'], self::needlesIn($direct));
        $this->assertSame(['aaaa'], self::needlesIn($viaVariable));
        $this->assertSame([], self::absencesSearchedInJson($notJson), 'a raw column is not a JSON rendering');
        $this->assertSame([], self::absencesSearchedInJson($onlyInAComment), 'a comment is not code');
    }

    /**
     * **A copy of a JSON rendering is one too**, and a reviewer was right
     * that the reader stopped at the first assignment.
     *
     * Chased to a fixed point rather than one hop: `$b = $a; $c = $b;` is
     * the same claim made twice, and a reader that answers correctly for
     * one hop and not for two teaches people the shape that gets past it.
     */
    public function testACopyOfAJsonRenderingIsStillOne(): void
    {
        $aliased = <<<'PHP'
            $dump = (string) json_encode($rows);
            $snapshot = $dump;
            $this->assertStringNotContainsString('0478', $snapshot);
            PHP;
        $twice = <<<'PHP'
            $dump = (string) json_encode($rows);
            $copy = $dump;
            $again = $copy;
            $this->assertStringNotContainsString('aaaa', $again);
            PHP;

        $this->assertSame(['0478'], self::needlesIn($aliased));
        $this->assertSame(['aaaa'], self::needlesIn($twice));
    }

    /**
     * Two identical assertions are two assertions.
     *
     * Keyed by line, they used to collapse into one — which undercounted
     * the floor above and, had they offended, would have reported one of
     * the two places and lost the other.
     */
    public function testTwoIdenticalAssertionsAreBothReported(): void
    {
        $twice = <<<'PHP'
            $dump = (string) json_encode($rows);
            $this->assertStringNotContainsString('0478', $dump);
            $this->assertStringNotContainsString('0478', $dump);
            PHP;

        $found = self::absencesSearchedInJson($twice);

        $this->assertCount(2, $found, 'two byte-identical assertions collapsed into one');
        $this->assertSame([2, 3], array_column($found, 'line'), 'they were not reported at their own lines');
    }

    /**
     * And the line reported is the line in the file, not the line in the
     * comment-stripped copy the reader works on.
     */
    public function testTheLineReportedIsTheLineInTheFile(): void
    {
        $afterADocblock = <<<'PHP'
            /**
             * Three lines of prose,
             * then the code.
             */
            $dump = (string) json_encode($rows);
            $this->assertStringNotContainsString('0478', $dump);
            PHP;

        $this->assertSame(
            [6],
            array_column(self::absencesSearchedInJson($afterADocblock), 'line'),
            'the docblock above the code was not counted, so the reported line points nowhere'
        );
    }

    /**
     * The directory reader on literal source, in both directions.
     *
     * The floor above it is `assertSame([], $offenders)`, which a reader
     * that recognises nothing satisfies perfectly — so the shapes it must
     * catch, and the ones it must not, are spelled out here with known
     * answers.
     */
    public function testTheDirectoryReaderKnowsTheFormsItMustCatch(): void
    {
        $direct = <<<'PHP'
            $before = scandir(sys_get_temp_dir());
            PHP;
        $viaVariable = <<<'PHP'
            $directory = sys_get_temp_dir();
            $before = scandir($directory);
            PHP;
        $ownDirectory = <<<'PHP'
            $directory = sys_get_temp_dir() . '/official-documents-' . bin2hex(random_bytes(6));
            $written = scandir($directory);
            PHP;
        $anotherVariable = <<<'PHP'
            $directory = sys_get_temp_dir();
            $written = scandir($somewhereElse);
            PHP;

        $this->assertTrue(self::comparesTheTemporaryDirectory($direct));
        $this->assertTrue(
            self::comparesTheTemporaryDirectory($viaVariable),
            'the form a reviewer found live went unread'
        );
        $this->assertFalse(
            self::comparesTheTemporaryDirectory($ownDirectory),
            'a directory the test makes for itself is not the shared one'
        );
        $this->assertFalse(
            self::comparesTheTemporaryDirectory($anotherVariable),
            'scanning some other path is not this rule'
        );
    }

    /**
     * @return list<string>
     */
    private static function needlesIn(string $source): array
    {
        return array_column(self::absencesSearchedInJson($source), 'needle');
    }
}
