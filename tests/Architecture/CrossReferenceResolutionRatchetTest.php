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
 *
 * WHAT IT LOOKS AT, AND WHY EACH PART IS NEEDED
 *
 * The source trees **and the documentation**. Leaving the documents out
 * while claiming to cover every citation in the repository was the same
 * defect one level up: they cite each other constantly, and `§8.6bis` —
 * quoted inside `ARCHITECTURE.md` itself, resolving nowhere — is what the
 * scan found the moment it looked there.
 *
 * A citation that **names a document** is answered by that document or by
 * nothing. Merged into one map, `ARCHITECTURE.md §22.2` resolved against
 * `specifications.md`: a reader sent somewhere real and still wrong, which
 * is the exact failure this file exists to catch. That is how
 * `specifications.md §8.47` was found on `VolumeUsage`: §8.47 is real,
 * and it is `ARCHITECTURE.md`'s.
 *
 * A citation naming a file that is **not** one of the documents —
 * « `core/View/rgpd_default.html` §2.12 » — is out of scope: it sends the
 * reader to that file, whose numbering is its own.
 *
 * A **bare** number resolves against any document, and additionally
 * against the file it is written in when that file is Markdown. The
 * chantier documents number their own preamble and quote it constantly
 * (« relève de §0.1 »), and a self-reference is exactly the citation a
 * reader has no trouble following.
 *
 * The **suffix is part of the number**, and it is read as *whatever
 * letters follow* rather than matched against a list. This document
 * carries nine — `bis` through `decies` — and a list that stopped at
 * `quinquies` did two wrong things at once: `### 8.49sexies` was not
 * registered as a heading at all, and a citation to `§8.71undecies` was
 * truncated to `§8.71`, which resolves. An unknown suffix has to fail
 * loudly rather than be quietly dropped, and only an open reading does
 * that.
 */
class CrossReferenceResolutionRatchetTest extends TestCase
{
    /**
     * The documents a citation can name.
     *
     * Matched by BASENAME, because that is how they are cited: nobody
     * writes the path. The reference dataset's own README is here for that
     * reason — `tests/` cites « README.md §8.2 » meaning that one, and the
     * repository's root README has no §8.2.
     *
     * @var list<string>
     */
    private const DOCUMENTS = [
        'ARCHITECTURE.md',
        'specifications.md',
        'SECURITY.md',
        'design.md',
        'README.md',
        'AGENTS.md',
        'CONTRIBUTING.md',
        'tests/fixtures/reference-dataset/README.md',
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
        // Two external standards whose name sits a line above the number
        // rather than beside it, so the strip below cannot see it:
        // RFC 7489 §6.6.2, and RFC 7208 §4.6.4.
        '6.6.2', '4.6.4',
        // A chantier journal quoting the numbering of the brief it was
        // handed — a document that was never committed, and whose own
        // §0.7 the entry is reporting a divergence from.
        '0.7',
    ];

    /**
     * What those numbers are quoted, in total, today. A ceiling rather
     * than an exact count: the point is that they must not spread, and a
     * reference deleted alongside the code that carried it is not a
     * regression to report.
     *
     * It moved 580 → 584 when the scan was widened to the documentation,
     * then 584 → 638 when `.sql` joined it — those occurrences were always
     * there and were simply not being looked at. A ceiling that rises
     * because the measurement got honest is not the same event as one that
     * rises because a comment was copied, and only the second is what this
     * number guards.
     *
     * 638 → 639 is a third kind again: one more occurrence of a number
     * already declared here, written on another branch while this one was
     * open. Raising it is right, and having to raise it is the mechanism
     * working — somebody looks each time, which is more than the list
     * itself asks for.
     */
    private const OCCURRENCE_CEILING = 639;

    public function testNoNewCrossReferencePointsAtASectionThatDoesNotExist(): void
    {
        $sections = $this->declaredSections();
        $dangling = [];
        $occurrences = 0;
        $examples = [];

        foreach ($this->citations() as [$number, $where, $document, $ownPath]) {
            // A citation naming a document is answered by that document, or
            // by nothing.
            if ($document !== '') {
                if (isset($sections[$document][$number])) {
                    continue;
                }
            } else {
                // Naming none, it is asking "anywhere" — and inside a
                // Markdown file its own headings are part of anywhere.
                if (isset($sections[''][$number])) {
                    continue;
                }
                if ($ownPath !== '' && isset($this->headingsOf($ownPath)[$number])) {
                    continue;
                }
            }

            $key = $document === '' ? $number : $document . ' §' . $number;
            $dangling[$key] = true;
            $occurrences++;
            $examples[$key] ??= $where;
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
                static fn (string $n): string => sprintf(
                    '  %s — first seen at %s',
                    str_contains($n, ' ') ? $n : '§' . $n,
                    $examples[$n]
                ),
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
     * The `### N.M` headings one file declares, suffix included.
     *
     * @return array<string, true>
     */
    private function headingsOf(string $path): array
    {
        static $cache = [];

        if (isset($cache[$path])) {
            return $cache[$path];
        }

        $headings = [];
        preg_match_all(
            '/^#{2,6}\\s*([0-9]+(?:\\.[0-9]+)*)([a-z]+)?[.)]?\\s/m',
            is_file($path) ? (string) file_get_contents($path) : '',
            $found,
            PREG_SET_ORDER
        );
        foreach ($found as $heading) {
            $headings[$heading[1] . ($heading[2] ?? '')] = true;
        }

        return $cache[$path] = $headings;
    }

    /**
     * Every `§N.M` a reader could be sent to, **per document**.
     *
     * Per document and not merged, because a citation that names one —
     * `ARCHITECTURE.md §22.2` — is answered by that document or by nothing:
     * merging the headings into one map made it resolve against
     * `specifications.md` §22.2, which is a reader sent somewhere real and
     * still wrong. The empty key holds the union, for the citations that
     * name no document and are therefore asking "anywhere".
     *
     * The suffix is part of the key. `§8.6bis` and `§8.6` are two
     * sections, and dropping the suffix on one side and stopping before it
     * on the other made either satisfy the other.
     *
     * @return array<string, array<string, true>> document (or '') => numbers
     */
    private function declaredSections(): array
    {
        $sections = ['' => []];
        $root = dirname(__DIR__, 2);

        foreach (self::DOCUMENTS as $document) {
            $path = $root . '/' . $document;
            $basename = basename($document);
            $sections[$basename] ??= [];
            if (!is_file($path)) {
                continue;
            }

            foreach (array_keys($this->headingsOf($path)) as $number) {
                $sections[$basename][$number] = true;
                $sections[''][$number] = true;
            }
        }

        return $sections;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}> the
     *         number, where it is quoted, the document it names (empty when it
     *         names none) and, for a Markdown file, its own path
     */
    private function citations(): array
    {
        $citations = [];
        $root = dirname(__DIR__, 2);

        $documents = array_map(static fn (string $d): string => basename($d), self::DOCUMENTS);

        foreach ($this->scannedFiles($root) as $relative => $path) {
            // A Markdown file answers its own bare citations: the chantier
            // documents number their preamble and quote it throughout.
            $self = str_ends_with($relative, '.md') ? $path : '';

            foreach (file($path) ?: [] as $index => $line) {
                // An external standard carries its own numbering and is not
                // this repository's to resolve. Only the reference itself is
                // removed, never the line: skipping the whole line let an
                // invalid citation sitting beside an RFC one escape entirely.
                $line = (string) preg_replace(
                    '/(RFC\s*\d+|RGPD|GDPR|ISO\s*\d+)\s*§\s*[0-9]+(?:\.[0-9]+)*/',
                    '',
                    $line
                );

                preg_match_all(
                    '/(?:`?([A-Za-z][A-Za-z0-9_\/.-]*\.[a-z]{2,4})`?\s*)?§\s*'
                    . '([0-9]+\.[0-9]+(?:\.[0-9]+)*)([a-z]+)?/',
                    $line,
                    $found,
                    PREG_SET_ORDER
                );
                foreach ($found as $citation) {
                    $named = $citation[1] === '' ? '' : basename($citation[1]);

                    // A file that is not one of the documents sends the
                    // reader to ITS numbering, which is not this
                    // repository's to resolve.
                    if ($named !== '' && !in_array($named, $documents, true)) {
                        continue;
                    }

                    $citations[] = [
                        $citation[2] . ($citation[3] ?? ''),
                        $relative . ':' . ($index + 1),
                        $named,
                        $self,
                    ];
                }
            }
        }

        return $citations;
    }

    /**
     * Everywhere a citation can be written: the source trees, and the
     * documentation itself.
     *
     * The documents were outside the scan while the class claimed every
     * citation in the repository was inside it — and they cite each other
     * constantly, so an invalid reference between two of them was exactly
     * the kind this file exists to catch and exactly the kind it could not
     * see. The set stays explicit: the seven documents of `DOCUMENTS`, plus
     * `docs/`, which is the one directory this repository keeps prose in.
     *
     * @return array<string, string> relative path => absolute path
     */
    private function scannedFiles(string $root): array
    {
        $found = [];

        foreach (self::DOCUMENTS as $document) {
            if (is_file($root . '/' . $document)) {
                $found[$document] = $root . '/' . $document;
            }
        }

        foreach ([...self::SCANNED, 'docs'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (!in_array($file->getExtension(), ['php', 'js', 'twig', 'md', 'sql'], true)) {
                    continue;
                }

                $found[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }

        // This file quotes the numbers it is about, in prose, and reading
        // itself would report them as found in the wild.
        unset($found[self::HOME]);

        return $found;
    }
}
