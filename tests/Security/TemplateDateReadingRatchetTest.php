<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Twig's own `|date()` filter is banned in templates, with one exception
 * named below.
 *
 * `Tests\Security\StoredDateReadingRatchetTest` closed this trap on the
 * PHP side and stopped at the file extension: it scans `.php`, so nothing
 * ever looked at the templates. Twig's filter has the *same* edge —
 * internally it builds a `DateTime` from whatever it is given — so a
 * stored value that is not a date raises, and Twig wraps it in a
 * `RuntimeError` that takes the WHOLE page down with a 500, not just the
 * field.
 *
 * That is not hypothetical. A member's `birth_date` arrives from the
 * federation's roster import, not from a form this project validates, and
 * one member's was stored as `15/09/2014`. Every visit to that member's
 * health sheet answered 500 — for anybody, not only the reporter — and
 * the field is not editable from the site, so there was no way round it
 * from the screen either. `HealthSheetFilling::date()` had read the very
 * same column through `DateInput::fromStorage()` all along; only the
 * template parsed it itself.
 *
 * The replacements all route through that reader and answer '' for what
 * it refuses: `|date_fr`, `|datetime_fr`, `|time_fr` and `|french_date`
 * for what a person reads, `|iso_date` and `|iso_datetime_local` for the
 * `value` of a date input. An empty field is a field somebody can see is
 * empty; a 500 is a page nobody can use.
 *
 * BOTH DIRECTIONS, like `StoredDateReadingRatchetTest`: a new call fails,
 * and so does a stale entry for a file that no longer has one.
 */
class TemplateDateReadingRatchetTest extends TestCase
{
    /**
     * The only form allowed, and why.
     *
     * `'now'|date(...)` reads a literal, never a column — there is no
     * stored value to be malformed, so the edge this test exists for
     * cannot be reached. Both sites want the machine format of the
     * current moment: one to compare a probe's expiry against it, one to
     * default a new album to today.
     *
     * A filter over `now` would be a filter that reads no input, which is
     * a function; Twig already has `date()` for that and this is its
     * spelling.
     *
     * @var array<string, int> relative path => number of allowed calls
     */
    private const DELIBERATE = [
        'modules/support_dashboard/views/partials/probes_table.html.twig' => 1,
        'modules/gallery/views/album_form.html.twig' => 1,
    ];

    /** @var list<string> */
    private const SCANNED = ['core/View/templates', 'modules'];

    public function testNoTemplateParsesAStoredDateItself(): void
    {
        $offenders = [];
        $seen = [];
        $repoRoot = dirname(__DIR__, 2);

        foreach ($this->templates($repoRoot) as $relative => $path) {
            $count = 0;

            foreach (file($path) ?: [] as $number => $line) {
                foreach ($this->rawDateCallsIn($line) as $call) {
                    // A literal is not a stored value; see DELIBERATE.
                    if (str_ends_with(rtrim($call), "'now'")) {
                        $count++;
                        continue;
                    }

                    $offenders[] = sprintf('%s:%d — %s', $relative, $number + 1, trim($line));
                }
            }

            if ($count > 0) {
                $seen[$relative] = $count;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A template must not parse a stored date itself: Twig's |date() raises on a value\n"
            . "that is not one, and the RuntimeError takes the whole page down with a 500.\n"
            . "Use |date_fr, |datetime_fr, |time_fr or |french_date to show one, and |iso_date\n"
            . "or |iso_datetime_local for the value of a date input — all of them read through\n"
            . "Core\\Service\\DateInput::fromStorage() and answer '' for what it refuses.\n\n"
            . implode("\n", $offenders)
        );

        ksort($seen);
        $expected = self::DELIBERATE;
        ksort($expected);
        $this->assertSame(
            $expected,
            $seen,
            "The list of deliberate |date() calls on a literal is out of date. Remove the entry\n"
            . 'if the call is gone; add one, with its reason in the docblock, if a new literal needs it.'
        );
    }

    /**
     * Every `|date(` on the line, with what precedes it — enough to tell a
     * literal from a variable.
     *
     * @return list<string>
     */
    private function rawDateCallsIn(string $line): array
    {
        if (preg_match_all('/(\S*)\|\s*date\(/', $line, $matches) === 0) {
            return [];
        }

        return array_map(static fn (string $before): string => $before, $matches[1]);
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function templates(string $repoRoot): array
    {
        $found = [];

        foreach (self::SCANNED as $directory) {
            if (!is_dir($repoRoot . '/' . $directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoRoot . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }

                $found[substr($file->getPathname(), strlen($repoRoot) + 1)] = $file->getPathname();
            }
        }

        return $found;
    }
}
