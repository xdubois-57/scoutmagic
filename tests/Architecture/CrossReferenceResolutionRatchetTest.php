<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every `§N.M` this repository quotes is a section a reader is sent to.
 * One that exists nowhere is worse than no reference at all: the reader
 * looks, finds nothing, and concludes the rule is not written down.
 *
 * WHAT THIS FOUND
 *
 * 766 references to `ARCHITECTURE.md` resolved; one number did not, and it
 * was quoted 64 times in 53 files. Pushing the same check to every
 * document turned up a second family, and then a third: `§6.x` in the
 * rental module, `§11.x` in news, `§2.7` in sos_staff. They are not typos
 * and they do not wander — each one names a real rule, consistently, which
 * is what shows the rules are applied. They name it under the numbering of
 * a **per-module specification that no longer exists as a document**: each
 * module's spec was folded into `specifications.md`, where rental is §22
 * and news is §26, and the citations were never renumbered.
 *
 * That is the costly shape of wrong documentation. It is invisible until
 * somebody checks, and it propagates by copying the comment next door —
 * which is how one number reached 56 occurrences.
 *
 * WHY A RATCHET AND NOT A BAN
 *
 * 580 references still point nowhere. Renumbering them is not a
 * substitution: saying WHICH subsection of §22 each of rental's thirty-odd
 * numbers corresponds to is a reading job, and one of them (`§7.9`) named
 * a rule that was not written down anywhere at all — the maintainer had to
 * decide whether to write it or to point the citations at the section that
 * already held half of it (#454).
 *
 * So this file holds the line instead of drawing it:
 *
 *  - a **new** number that resolves nowhere fails, which is what stops the
 *    next disappeared numbering from taking root;
 *  - the total may not **grow**, which is what stops the known ones from
 *    spreading by copy;
 *  - a declared number that is gone fails too, so finishing the job is
 *    recorded here rather than leaving a stale list behind.
 *
 * The first batch is done: `§7.9` (64) now points at §8.6 and §8.58, and
 * `§6.7`/`§6.14` (62) at `specifications.md` §22.2 and §22.5.
 */
class CrossReferenceResolutionRatchetTest extends TestCase
{
    /** @var list<string> */
    private const DOCUMENTS = [
        'ARCHITECTURE.md',
        'specifications.md',
        'SECURITY.md',
        'design.md',
        'README.md',
        'AGENTS.md',
        'CONTRIBUTING.md',
    ];

    /** @var list<string> */
    private const SCANNED = ['core', 'modules', 'public', 'tests'];

    private const HOME = 'tests/Architecture/CrossReferenceResolutionRatchetTest.php';

    /**
     * The numbering left to renumber, by the module whose vanished spec it
     * belonged to. Shrinking this list is the work #454 describes; nothing
     * may be added to it.
     *
     * `6.6.2` is the odd one out and stays: it is **RFC 7489** §6.6.2, an
     * external standard, and the words "RFC 7489" sit on the line above
     * the number rather than beside it.
     *
     * @var list<string>
     */
    private const KNOWN_DANGLING = [
        // The rental module's own spec, now specifications.md §22.
        '6.5', '6.6', '6.8', '6.9', '6.10', '6.11', '6.12', '6.13', '6.14',
        '6.15', '6.16', '6.17', '6.18', '6.19', '6.20', '6.21', '6.22',
        '6.23', '6.24', '6.25', '6.26', '6.27', '6.28', '6.29', '6.30',
        '6.31', '6.32', '6.33', '6.34', '6.35', '6.36',
        // The news module's own spec, now specifications.md §26.
        '11.1', '11.2', '11.3', '11.4', '11.5', '11.6', '11.7', '11.8',
        '11.9', '11.10',
        // sos_staff's.
        '2.7',
        // RFC 7489, named a line above the number.
        '6.6.2',
    ];

    /**
     * What those numbers are quoted, in total, today. A ceiling rather
     * than an exact count: the point is that they must not spread, and a
     * reference deleted alongside the code that carried it is not a
     * regression to report.
     */
    private const OCCURRENCE_CEILING = 580;

    public function testNoNewCrossReferencePointsAtASectionThatDoesNotExist(): void
    {
        $sections = $this->declaredSections();
        $dangling = [];
        $occurrences = 0;
        $examples = [];

        foreach ($this->citations() as [$number, $where]) {
            if (isset($sections[$number])) {
                continue;
            }

            $dangling[$number] = true;
            $occurrences++;
            $examples[$number] ??= $where;
        }

        $found = array_keys($dangling);
        sort($found);
        $known = self::KNOWN_DANGLING;
        sort($known);

        $appeared = array_values(array_diff($found, $known));
        $this->assertSame(
            [],
            $appeared,
            "A cross-reference must point at a section that exists — a reader who looks and\n"
            . "finds nothing concludes the rule is not written down.\n\n"
            . implode("\n", array_map(
                static fn (string $n): string => sprintf('  §%s — first seen at %s', $n, $examples[$n]),
                $appeared
            ))
        );

        $this->assertSame(
            [],
            array_values(array_diff($known, $found)),
            'A number declared here resolves now — remove it from KNOWN_DANGLING (#454).'
        );

        $this->assertLessThanOrEqual(
            self::OCCURRENCE_CEILING,
            $occurrences,
            'The known dangling references must not spread; they propagate by copying the '
            . 'comment next door, which is how one of them reached 56 occurrences.'
        );
    }

    /**
     * @return array<string, true> every `§N.M` a reader could be sent to
     */
    private function declaredSections(): array
    {
        $sections = [];
        $root = dirname(__DIR__, 2);

        foreach (self::DOCUMENTS as $document) {
            $path = $root . '/' . $document;
            if (!is_file($path)) {
                continue;
            }

            preg_match_all(
                '/^#{2,6}\s*([0-9]+(?:\.[0-9]+)*)(?:bis|ter|quater|quinquies)?[.)]?\s/m',
                (string) file_get_contents($path),
                $found
            );
            foreach ($found[1] as $number) {
                $sections[$number] = true;
            }
        }

        return $sections;
    }

    /**
     * @return list<array{0: string, 1: string}> number, and where it is quoted
     */
    private function citations(): array
    {
        $citations = [];
        $root = dirname(__DIR__, 2);

        foreach (self::SCANNED as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js', 'twig'], true)) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);

                // This file quotes the numbers it is about, in prose, and
                // reading itself would report them as found in the wild.
                if ($relative === self::HOME) {
                    continue;
                }

                foreach (file($file->getPathname()) ?: [] as $index => $line) {
                    // An external standard carries its own numbering and is
                    // not this repository's to resolve.
                    if (preg_match('/(RFC\s*\d+|RGPD|GDPR|ISO\s*\d+)\s*§/', $line) === 1) {
                        continue;
                    }

                    preg_match_all('/§\s*([0-9]+\.[0-9]+(?:\.[0-9]+)*)/', $line, $found);
                    foreach ($found[1] as $number) {
                        $citations[] = [$number, $relative . ':' . ($index + 1)];
                    }
                }
            }
        }

        return $citations;
    }
}
