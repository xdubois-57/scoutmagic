<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * `DateTimeImmutable::createFromFormat()` is called in exactly one place
 * in this codebase, and that place is `Core\Service\DateInput`.
 *
 * The reason is a bug that had been copy-pasted twenty times. The idiom
 * everyone wrote —
 *
 *     $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
 *     return $d !== false && $d->format('Y-m-d') === $value;
 *
 * — reads as total, and is, except for one input: a `$value` containing a
 * NUL byte makes PHP raise a **ValueError**, not return `false`. Every
 * site that wrote it therefore turned `2026-01-01%00` into an uncaught
 * exception and a 500. A dynamic scan found two of them; the difference
 * between two and twenty was which pages the scan happened to reach, not
 * which sites were sound.
 *
 * That is the class of bug an audit is for: not a failure of care at any
 * one site — the round-trip check shows the authors were being careful —
 * but a shared idiom whose sharp edge is invisible at every copy. The
 * same reasoning, and the same shape of test, as
 * `HttpsDetectionConvergenceTest`.
 *
 * **What this does NOT cover, deliberately.** `new DateTimeImmutable($v)`
 * has the opposite trap — it throws on "../../.." but silently answers
 * *the current moment* for `""`, `"now"` and `"a\0b"` — and there are
 * some forty call sites, nearly all reading a column that should hold a
 * timestamp. `DateInput::fromStorage()` is the safe replacement and is
 * used where a value's origin is untrusted, but converting the rest is a
 * migration of its own and is not claimed here. A reader of this file
 * should not conclude that every date parse in the project is guarded —
 * only every `createFromFormat`.
 */
class DateParsingConvergenceTest extends TestCase
{
    private const HOME = 'core/Service/DateInput.php';

    public function testCreateFromFormatIsCalledInExactlyOnePlace(): void
    {
        $offenders = [];
        $repoRoot = dirname(__DIR__, 2);

        foreach ($this->sourceFiles($repoRoot) as $relativePath) {
            if ($relativePath === self::HOME) {
                continue;
            }

            $contents = (string) file_get_contents($repoRoot . '/' . $relativePath);
            foreach (explode("\n", $contents) as $number => $line) {
                if (str_contains($line, 'createFromFormat(')) {
                    $offenders[] = $relativePath . ':' . ($number + 1) . ' — ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Parse the date through Core\\Service\\DateInput instead.\n"
            . "createFromFormat() raises a ValueError — not `false` — when the value carries a NUL byte,\n"
            . "so the usual `!== false` guard lets that one input through as an uncaught exception:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * The home itself must keep the guard it exists for. Deleting the
     * control-character check would leave every caller exactly as exposed
     * as before, with no test failing anywhere else.
     */
    public function testTheOnePlaceStillGuardsTheInput(): void
    {
        $home = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::HOME);

        $this->assertStringContainsString('\x00-\x1F\x7F', $home);
    }

    /**
     * @return list<string> repository-relative paths
     */
    private function sourceFiles(string $repoRoot): array
    {
        $files = [];

        foreach (['core', 'modules', 'public', 'bootstrap'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoRoot . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($repoRoot) + 1);
                }
            }
        }

        sort($files);

        return $files;
    }
}
