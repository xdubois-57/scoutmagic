<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Modules\Attestations\Value\PdfAnalysis;
use Modules\Attestations\Value\ReadAttestation;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

/**
 * Reads the federation's PDF and works out where one certificate ends and
 * the next begins, then who each one belongs to.
 *
 * Taken from the previous site, where it ran in production for several
 * years. It touches no database: it is given a file path and a name
 * directory and hands back a structure, which is what makes it testable
 * against a committed fixture.
 *
 * **The size of a document is DETECTED, never asked for.** The reader takes
 * the first text field of page 1 and walks forward until it meets it again;
 * that distance is how many pages one certificate occupies. No setting to
 * get wrong, no assumption about the federation's template, and the
 * mechanism survives a certificate that grows from one page to two.
 *
 * **It refuses rather than guesses.** If the page count is not a multiple
 * of the detected size, nothing is produced at all
 * (PageCountMismatchException). A split one page out of step would give
 * every family the next family's certificate — the worst thing this feature
 * can do, and nothing downstream would ever catch it: each certificate sits
 * on a member's page under a title that says nothing about whose name is
 * printed inside, so the mismatch is invisible unless somebody opens one and
 * reads it.
 *
 * **Matching runs over every text field of the certificate's pages**, not
 * just the first: the name sits wherever the template put it, and the only
 * thing this document carries is the name — no Desk identifier, no date of
 * birth.
 */
class AttestationPdfReader
{
    /**
     * How many of a page's text fields are considered when looking for a
     * name. A certificate's identity block is at the top; reading the whole
     * page would eventually meet a street name or a section name that
     * happens to fold onto somebody's name, and a wrong match here is a
     * wrong family. The first match wins, so a low ceiling also keeps the
     * cost linear in the page count rather than in the text volume.
     */
    private const MAX_FIELDS_PER_PAGE = 40;

    public function __construct(private ?Parser $parser = null)
    {
    }

    /**
     * @throws AttestationsException        when the file cannot be read as a PDF
     * @throws PageCountMismatchException   when the arithmetic does not fall right
     */
    public function analyze(string $pdfPath, MemberNameDirectory $directory): PdfAnalysis
    {
        $pages = $this->readPages($pdfPath);
        $pageCount = count($pages);

        if ($pageCount === 0) {
            throw new AttestationsException(
                'Ce fichier ne contient aucune page lisible. Vérifiez qu\'il s\'agit bien du PDF reçu de la fédération.'
            );
        }

        $pagesPerDocument = $this->detectPagesPerDocument($pages);

        if ($pageCount % $pagesPerDocument !== 0) {
            throw new PageCountMismatchException($pageCount, $pagesPerDocument);
        }

        $attestations = [];
        for ($first = 1; $first <= $pageCount; $first += $pagesPerDocument) {
            $last = $first + $pagesPerDocument - 1;
            $attestations[] = $this->readOne($pages, $first, $last, $directory);
        }

        return new PdfAnalysis($pageCount, $pagesPerDocument, $attestations);
    }

    /**
     * The first text field of page 1, met again.
     *
     * When it never recurs, the whole file is ONE certificate — which is
     * exactly right for a single-member document, and is what the previous
     * site did. It is also the one shape a reader should look at twice: a
     * ninety-page file arriving as a single line is visible at a glance on
     * the verification screen, which is where a human is meant to catch
     * what a parser cannot decide.
     *
     * @param list<list<string>> $pages
     */
    private function detectPagesPerDocument(array $pages): int
    {
        $marker = $this->firstField($pages[0] ?? []);
        if ($marker === null) {
            return count($pages);
        }

        // $pages is 0-based, so the index at which the marker recurs IS the
        // number of pages the first certificate occupied: a marker met
        // again at index 2 means pages 0 and 1 were one certificate.
        for ($index = 1; $index < count($pages); $index++) {
            if ($this->firstField($pages[$index]) === $marker) {
                return $index;
            }
        }

        return count($pages);
    }

    /**
     * @param list<list<string>> $pages
     */
    private function readOne(
        array $pages,
        int $firstPage,
        int $lastPage,
        MemberNameDirectory $directory
    ): ReadAttestation
    {
        $fallback = null;

        for ($page = $firstPage; $page <= $lastPage; $page++) {
            $fields = array_slice($pages[$page - 1], 0, self::MAX_FIELDS_PER_PAGE);

            foreach ($fields as $field) {
                $text = self::clean($field);
                if ($text === '') {
                    continue;
                }

                foreach (self::candidates($text) as $candidate) {
                    $memberIds = $directory->lookup($candidate);
                    if ($memberIds !== []) {
                        return new ReadAttestation($firstPage, $lastPage, $candidate, $memberIds);
                    }
                }

                // What the screen shows when nothing matched: the first
                // plausible-looking line of the certificate, so the reader
                // sees WHAT was read rather than an empty cell they cannot
                // act on. Two words or more, no digits — the shape of a
                // name rather than of a reference or an amount.
                if ($fallback === null && self::looksLikeAName($text)) {
                    $fallback = $text;
                }
            }
        }

        return new ReadAttestation($firstPage, $lastPage, $fallback, []);
    }

    /**
     * One list of text fields per page, in reading order.
     *
     * @return list<list<string>>
     */
    private function readPages(string $pdfPath): array
    {
        $content = @file_get_contents($pdfPath);
        if ($content === false || $content === '') {
            throw new AttestationsException(
                'Ce fichier n\'a pas pu être lu. Déposez-le à nouveau.'
            );
        }

        try {
            $document = ($this->parser ?? new Parser())->parseContent($content);
        } catch (\Throwable $e) {
            throw new AttestationsException(
                'Ce fichier n\'a pas pu être ouvert comme un PDF. S\'il est protégé par un mot de passe, '
                . 'demandez à la fédération une version qui ne l\'est pas.',
                0,
                $e
            );
        }

        return $this->pageFields($document);
    }

    /**
     * @return list<list<string>>
     */
    private function pageFields(Document $document): array
    {
        $pages = [];

        foreach ($document->getPages() as $page) {
            try {
                $fields = $page->getTextArray();
            } catch (\Throwable) {
                // A page whose text layer cannot be read is a page with no
                // fields, never a reason to abandon the file: the page
                // COUNT is what the arithmetic runs on, and a certificate
                // whose name could not be read comes out unmatched, which
                // is a line a human resolves rather than a batch nobody
                // can deposit.
                $fields = [];
            }

            $clean = [];
            foreach ($fields as $field) {
                $text = self::clean((string) $field);
                if ($text !== '') {
                    $clean[] = $text;
                }
            }

            $pages[] = $clean;
        }

        return $pages;
    }

    /** @param list<string> $fields */
    private function firstField(array $fields): ?string
    {
        return $fields[0] ?? null;
    }

    /**
     * What a text field might be a name of: the field itself, and — when it
     * carries a label — what follows the label.
     *
     * Templates routinely print « Bénéficiaire : DUPONT Jean », and a whole
     * field match alone leaves that line unmatched for a member the site
     * knows perfectly well. Splitting on an explicit separator is NOT
     * substring matching: each candidate is still looked up whole against
     * the directory, so « Rue Camille Delacroix 12 » (no separator, and no
     * whole-field match) still resolves to nobody. Widening this to "does
     * any part of the line look like a name" is what would start matching
     * street names to members, which on a nominative document is the one
     * class of error that must stay impossible.
     *
     * @return list<string>
     */
    private static function candidates(string $text): array
    {
        $candidates = [$text];

        foreach ([':', '–', '—'] as $separator) {
            $position = mb_strrpos($text, $separator);
            if ($position === false) {
                continue;
            }

            $tail = self::clean(mb_substr($text, $position + mb_strlen($separator)));
            if ($tail !== '' && $tail !== $text) {
                $candidates[] = $tail;
            }
        }

        return $candidates;
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function looksLikeAName(string $text): bool
    {
        return preg_match('/\d/', $text) !== 1
            && mb_strlen($text) <= 80
            && str_contains($text, ' ');
    }
}
