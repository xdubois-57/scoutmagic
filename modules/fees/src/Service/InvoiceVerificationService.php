<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Member\SectionService;
use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Repository\InvoiceRepository;
use Modules\Fees\Repository\RosterSnapshotRepository;
use Modules\Fees\Value\ReconstitutedLine;
use Modules\Fees\Value\RosterSnapshotMember;
use Modules\Fees\Value\StoredInvoice;
use Modules\Fees\Value\StoredInvoiceLine;

/**
 * "Did the federation count the right number of people?"
 *
 * Per (reference, section): what was billed, what the roster snapshot held,
 * and the gap. The expected figure comes from the **snapshot** — what Desk
 * contained on the day — and never from a tariff calculation. That is the
 * line between this report and « Justesse des tarifs »: one verifies the
 * count, the other verifies the categories, and confusing them produces a
 * screen that accuses the federation of an error the unit made.
 *
 * `member_years.leaving` is deliberately ignored here: Desk held those
 * people and the federation billed them, so the expected count includes
 * them. Excluding them would manufacture a discrepancy on every household
 * with a departure announced.
 */
class InvoiceVerificationService
{
    public function __construct(
        private InvoiceRepository $invoices,
        private RosterSnapshotRepository $snapshots,
        private HouseholdTariffService $tariffs,
        private SectionService $sections
    ) {
    }

    /**
     * @return ReconstitutedLine[]
     */
    public function reconstitutedLines(StoredInvoice $invoice): array
    {
        $snapshotMembers = $invoice->snapshotId === null ? null : $this->snapshots->findMembers($invoice->snapshotId);
        $sectionLabels = $this->sectionLabels();

        return array_map(
            fn(StoredInvoiceLine $line): ReconstitutedLine => $this->reconstitute($line, $snapshotMembers, $sectionLabels),
            $this->invoices->findLines($invoice->id)
        );
    }

    /**
     * How many of an invoice's lines disagree with the snapshot — the
     * figure the season's list carries next to each document.
     *
     * A line the site cannot judge counts as no discrepancy: silence is
     * not an accusation.
     */
    public function countDiscrepancies(StoredInvoice $invoice): int
    {
        $count = 0;
        foreach ($this->reconstitutedLines($invoice) as $line) {
            if ($line->isDetermined() && !$line->matches()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * How far apart the roster the invoice was checked against and the
     * invoice itself are, in days — shown rather than hidden, because one
     * day of drift is enough to produce differences that are not
     * differences.
     */
    public function snapshotDateGapInDays(StoredInvoice $invoice): ?int
    {
        if ($invoice->snapshotId === null || $invoice->issueDate === null) {
            return null;
        }

        foreach ($this->snapshots->findAllForYear($invoice->scoutYearId) as $snapshot) {
            if ($snapshot->id !== $invoice->snapshotId) {
                continue;
            }
            $issued = new \DateTimeImmutable($invoice->issueDate);

            return (int) $issued->diff($snapshot->takenAt->setTime(0, 0))->format('%r%a');
        }

        return null;
    }

    /**
     * @param RosterSnapshotMember[]|null $snapshotMembers
     * @param array<int, string> $sectionLabels
     */
    private function reconstitute(StoredInvoiceLine $line, ?array $snapshotMembers, array $sectionLabels): ReconstitutedLine
    {
        $sectionLabel = $line->sectionId === null ? null : ($sectionLabels[$line->sectionId] ?? $line->sectionCode);
        $expected = null;
        $reason = null;

        if ($snapshotMembers === null) {
            $reason = ReconstitutedLine::UNDETERMINED_NO_SNAPSHOT;
        } elseif ($line->nature === InvoiceLine::NATURE_ADJUSTMENT) {
            $reason = ReconstitutedLine::UNDETERMINED_GLOBAL_ADJUSTMENT;
        } elseif ($line->sectionId === null) {
            $reason = ReconstitutedLine::UNDETERMINED_NO_SECTION;
        } else {
            $expected = $this->expectedQuantity($line, $snapshotMembers);
            if ($expected === null) {
                $reason = ReconstitutedLine::UNDETERMINED_UNKNOWN_REFERENCE;
            }
        }

        return new ReconstitutedLine(
            $line->reference,
            $line->descriptor,
            $line->sectionId,
            $sectionLabel,
            $line->unitPriceCents,
            $line->quantity,
            $line->amountCents,
            $expected,
            $reason
        );
    }

    /**
     * @param RosterSnapshotMember[] $snapshotMembers
     * @return int|null null when the reference says nothing this site can count
     */
    private function expectedQuantity(StoredInvoiceLine $line, array $snapshotMembers): ?int
    {
        $inSection = array_values(array_filter(
            $snapshotMembers,
            static fn(RosterSnapshotMember $m): bool => $m->sectionId === $line->sectionId
        ));

        // A reduction tied to a formation level counts the people who hold
        // it, not the people on a tariff.
        if ($line->nature === InvoiceLine::NATURE_REDUCTION) {
            if (!self::mentionsBrevet($line)) {
                return null;
            }

            return count(array_filter(
                $inSection,
                static fn(RosterSnapshotMember $m): bool => BrevetDetector::isBrevet($m->formationLevel)
            ));
        }

        $category = FeeCategoryClassifier::classify($line->reference, $line->descriptor);
        if ($category === null) {
            // An unknown reference disables the only check this line had.
            // It never blocks anything, and the screen says so.
            return null;
        }

        return count(array_filter(
            $inSection,
            fn(RosterSnapshotMember $m): bool => $this->tariffs->categoryForFeeCategoryId($m->feeCategoryId) === $category
        ));
    }

    private static function mentionsBrevet(StoredInvoiceLine $line): bool
    {
        return BrevetDetector::isBrevet($line->reference . ' ' . $line->descriptor);
    }

    /** @return array<int, string> */
    private function sectionLabels(): array
    {
        $labels = [];
        foreach ($this->sections->getAllWithBranches(true) as $section) {
            $labels[(int) $section['id']] = (string) ($section['name'] ?? $section['desk_code']);
        }

        return $labels;
    }
}
