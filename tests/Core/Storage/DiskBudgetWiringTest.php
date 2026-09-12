<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use PHPUnit\Framework\TestCase;

/**
 * A ratchet: **no production construction of a quota-guarded service may
 * omit its `DiskBudget`.**
 *
 * The parameter is nullable and trailing on all three of them, so a caller
 * that forgets it does not fail to compile, does not fail a test, and does
 * not log anything — it simply writes past a full quota in silence, which
 * is the one failure `Core\Storage` exists to prevent. Two sites were
 * already wrong when this test was written: `public/index.php`'s
 * inbound-mail refresh screen and `public/scheduler-bootstrap.php`'s Camps
 * document service, the second being a whole composition root nobody had
 * looked at.
 *
 * Why nullable at all, then: a test constructing one of these directly has
 * no settings to build a budget from, and `Core\Http\Controller\
 * SetupController` runs before the application is wired — see the
 * exception below. Making the parameter required would push `, null`
 * through forty test sites to close a hole that only exists in production
 * wiring, so the rule is asserted where it applies instead.
 *
 * Textual, like `Tests\Core\Maintenance\BackupPairReservationTest`, and
 * for the same reason: the alternative is a runtime test per composition
 * root, which is how these sites came to have none.
 */
class DiskBudgetWiringTest extends TestCase
{
    /** Directories whose PHP files are production wiring. */
    private const ROOTS = ['core', 'modules', 'public'];

    /** Constructors whose last argument is the quota guard. */
    private const GUARDED = [
        'UploadHandler',
        'ChunkedUploadStore',
        'BackupService',
    ];

    /**
     * The constructions that legitimately have no budget, with the reason
     * each cannot have one.
     *
     * Both are in the installation wizard, and both for the same root
     * cause: a `DiskBudget` reads its declared quota out of `settings`,
     * and this controller runs while there is no database to read it from.
     *
     * - `BackupService` — `SetupController` dumps the database **before**
     *   the application exists, as the rescue copy taken ahead of a
     *   reinstall. Refusing it on a reading nobody can make would destroy
     *   the data the dump was there to save.
     * - `ChunkedUploadStore` — the portable restore accepts an archive in
     *   fragments at a point in the wizard where the operator has not yet
     *   reached a working site. The store still enforces its own
     *   `PORTABLE_UPLOAD_MAX_BYTES` ceiling on the assembled file, so this
     *   is an unmeasured write and not an unbounded one.
     *
     * @var array<string, string[]>
     */
    private const EXEMPT = [
        'core/Http/Controller/SetupController.php' => ['BackupService', 'ChunkedUploadStore'],
    ];

    public function testEveryProductionConstructionPassesADiskBudget(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            $relative = $this->relative($file);
            $source = (string) file_get_contents($file);

            foreach (self::GUARDED as $class) {
                foreach ($this->constructionsOf($source, $class) as $construction) {
                    if (str_contains($construction, 'DiskBudget') || str_contains($construction, 'diskBudget')) {
                        continue;
                    }
                    if (in_array($class, self::EXEMPT[$relative] ?? [], true)) {
                        continue;
                    }
                    $offenders[] = $relative . ' → new ' . $class;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These constructions write without a quota guard: ' . implode(', ', $offenders)
        );
    }

    /** The exemption has to name something real, or it is silently excusing nothing. */
    public function testTheExemptionStillPointsAtAnExistingConstruction(): void
    {
        foreach (self::EXEMPT as $relative => $classes) {
            $path = dirname(__DIR__, 3) . '/' . $relative;
            $this->assertFileExists($path, $relative . ' no longer exists — drop its exemption.');

            foreach ($classes as $class) {
                $this->assertNotSame(
                    [],
                    $this->constructionsOf((string) file_get_contents($path), $class),
                    $relative . ' no longer constructs ' . $class . ' — drop its exemption.',
                );
            }
        }
    }

    /** And the scan has to reach the roots it is about. */
    public function testTheScanSeesEveryCompositionRoot(): void
    {
        $seen = [];
        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::GUARDED as $class) {
                if ($this->constructionsOf($source, $class) !== []) {
                    $seen[] = $this->relative($file);
                    break;
                }
            }
        }

        foreach (['public/index.php', 'public/scheduler-bootstrap.php'] as $root) {
            $this->assertContains($root, $seen, $root . ' is no longer seen by this check.');
        }
    }

    /**
     * Every `new <class>(…)` in the source, each returned as the text from
     * the constructor's name to its closing parenthesis, so an argument on
     * a later line counts as part of it.
     *
     * @return string[]
     */
    private function constructionsOf(string $source, string $class): array
    {
        $found = [];
        $offset = 0;

        while (($at = strpos($source, 'new ', $offset)) !== false) {
            $offset = $at + 4;
            if (preg_match('/^\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*' . preg_quote($class, '/') . '\s*\(/', substr($source, $offset), $m) !== 1) {
                continue;
            }

            $cursor = $offset + strlen($m[0]);
            $depth = 1;
            $length = strlen($source);
            while ($cursor < $length && $depth > 0) {
                if ($source[$cursor] === '(') {
                    $depth++;
                } elseif ($source[$cursor] === ')') {
                    $depth--;
                }
                $cursor++;
            }

            $found[] = substr($source, $offset, $cursor - $offset);
        }

        return $found;
    }

    /** @return iterable<string> */
    private function phpFiles(): iterable
    {
        $root = dirname(__DIR__, 3);
        foreach (self::ROOTS as $directory) {
            $path = $root . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    yield $file->getPathname();
                }
            }
        }
    }

    private function relative(string $path): string
    {
        $root = dirname(__DIR__, 3) . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
