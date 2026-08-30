<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Service\DateInput;
use Modules\Attestations\Repository\Batch;
use Modules\Attestations\Repository\BatchLine;
use Modules\Attestations\Repository\BatchLineRepository;

/**
 * Tells the reader that a member already has one — and stops there.
 *
 * **A warning, never a block.** A member present in two PDFs gets two
 * documents and they coexist. The site cannot tell whether the second is a
 * correction the federation sent or a legitimate top-up, and replacing
 * automatically would make a document disappear that nobody asked to
 * remove. So the line is flagged, the reader unticks it or does not, and
 * the decision stays where the information is.
 *
 * **Two shapes of duplicate, one sentence.** Between two batches — the
 * ordinary case, since partial files are the norm — and inside a single
 * batch, where the same member appears twice in one deposited file. The
 * second was left open by the specification and is decided here: it is the
 * same fact, so it gets the same warning and the same freedom to untick.
 *
 * The reconciliation is on **member + category + scout year**, and that is
 * the only thing the category is for. Matching on the label instead would
 * confuse a tax certificate with an attendance certificate — two perfectly
 * legitimate documents for the same person in the same season.
 */
class DuplicateDetector
{
    public function __construct(private BatchLineRepository $lines)
    {
    }

    /**
     * @param list<BatchLine> $lines the batch's own lines, in page order
     *
     * @return array<int, string> line id => the French sentence to show
     *                            beside it; a line with nothing to say is
     *                            absent rather than mapped to an empty
     *                            string
     */
    public function warningsFor(Batch $batch, array $lines): array
    {
        $memberIds = [];
        foreach ($lines as $line) {
            if ($line->memberId !== null) {
                $memberIds[] = $line->memberId;
            }
        }

        $elsewhere = $this->lines->findPublishedOccurrences(
            array_values(array_unique($memberIds)),
            $batch->category->value,
            $batch->scoutYearId,
            $batch->id
        );

        $withinBatch = $this->countWithinBatch($lines);

        $warnings = [];
        foreach ($lines as $line) {
            $memberId = $line->memberId;
            if ($memberId === null) {
                continue;
            }

            $sentences = [];

            foreach ($elsewhere[$memberId] ?? [] as $occurrence) {
                $sentences[] = sprintf(
                    'Ce membre a déjà reçu une %s pour cette année scoute, le %s (« %s »).',
                    mb_strtolower($batch->category->label()),
                    $this->frenchDate($occurrence['published_at']),
                    $occurrence['label']
                );
            }

            if (($withinBatch[$memberId] ?? 0) > 1) {
                $sentences[] = sprintf(
                    'Ce membre apparaît %d fois dans ce lot.',
                    $withinBatch[$memberId]
                );
            }

            if ($sentences !== []) {
                $warnings[$line->id] = implode(' ', $sentences);
            }
        }

        return $warnings;
    }

    /**
     * @param list<BatchLine> $lines
     * @return array<int, int> member id => how many lines of this batch name them
     */
    private function countWithinBatch(array $lines): array
    {
        $counts = [];
        foreach ($lines as $line) {
            if ($line->memberId !== null) {
                $counts[$line->memberId] = ($counts[$line->memberId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * A stored instant read through the one door (SECURITY.md §35): never
     * `new DateTimeImmutable($stored)`, which answers *now* for an empty
     * string and would date a duplicate to today.
     */
    private function frenchDate(?string $stored): string
    {
        $date = DateInput::fromStorage($stored);

        return $date === null ? 'une date inconnue' : $date->format('d/m/Y');
    }
}
