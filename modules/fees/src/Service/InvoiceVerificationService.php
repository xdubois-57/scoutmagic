<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Member\HouseholdFeeCategory;
use Core\Member\SectionService;
use Core\Service\DateInput;
use Modules\Fees\HouseholdCategoryLabel;
use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Core\Import\RosterSnapshotRepository;
use Modules\Fees\Value\NominativeDiscrepancy;
use Modules\Fees\Value\ReconstitutedLine;
use Core\Import\RosterSnapshotMember;
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
        private SectionService $sections,
        private HouseholdDetailRepository $names,
        /**
         * How a Desk formation wording is read. Defaults to the detector's
         * own fallback reading, which is what a unit without the
         * leadership module gets (roadmap IT-21).
         */
        private ?BrevetDetector $brevets = null
    ) {
        $this->brevets ??= new BrevetDetector();
    }

    /**
     * @return ReconstitutedLine[]
     */
    public function reconstitutedLines(StoredInvoice $invoice): array
    {
        $snapshotMembers = $invoice->snapshotId === null ? null : $this->snapshots->findMembers($invoice->snapshotId);
        $sectionLabels = $this->sectionLabels();

        return array_map(
            fn(StoredInvoiceLine $line): ReconstitutedLine => $this->reconstitute($line, $snapshotMembers,
                $sectionLabels),
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
     * Where the invoice and the roster disagree **about a person**.
     *
     * The count check ({@see reconstitutedLines()}) says a section is one
     * short; this says who. Five different things, deliberately kept
     * apart because each names a different thing to go and do — see
     * {@see NominativeDiscrepancy}.
     *
     * Everything here is read against the snapshot the invoice was tied to
     * at import, never against today's roster: a member who has since left
     * was on the invoice legitimately, and reporting them would be an
     * accusation manufactured by the passage of time.
     *
     * @return NominativeDiscrepancy[]
     */
    public function nominativeDiscrepancies(StoredInvoice $invoice): array
    {
        if ($invoice->snapshotId === null) {
            return [];
        }

        $lines = $this->invoices->findLines($invoice->id);
        $snapshot = $this->indexByMemberId($this->snapshots->findMembers($invoice->snapshotId));
        $sectionLabels = $this->sectionLabels();
        $prices = $this->pricesByCategory($lines);
        $billedSections = $this->billedSectionIds($lines);

        $found = [];
        $billed = [];

        foreach ($lines as $line) {
            if ($line->nature !== InvoiceLine::NATURE_FEE) {
                continue;
            }
            $billedCategory = FeeCategoryClassifier::classify($line->reference, $line->descriptor);

            foreach ($line->memberIds as $memberId) {
                $billed[$memberId] = true;
                $member = $snapshot[$memberId] ?? null;
                if ($member === null) {
                    // Billed, and the roster of the day did not hold them.
                    // The count check already reports the section as one
                    // over; naming them here would need a name the site
                    // does not have, so this stays a counted line rather
                    // than a nominative one.
                    continue;
                }

                if ($member->leaving) {
                    $found[] = $this->discrepancy(
                        NominativeDiscrepancy::BILLED_BUT_LEAVING,
                        $memberId,
                        $sectionLabels[$line->sectionId] ?? null,
                        $sectionLabels[$member->sectionId] ?? null,
                        $billedCategory,
                        $this->tariffs->categoryForFeeCategoryId($member->feeCategoryId),
                        $line->unitPriceCents
                    );
                }

                if (
                    $line->sectionId !== null
                    && $member->sectionId !== null
                    && $line->sectionId !== $member->sectionId
                ) {
                    // No amount, ever: the tariff is the same on either
                    // section, so a figure here would put euros on a
                    // difference that is not money.
                    $found[] = $this->discrepancy(
                        NominativeDiscrepancy::DIFFERENT_SECTION,
                        $memberId,
                        $sectionLabels[$line->sectionId] ?? null,
                        $sectionLabels[$member->sectionId] ?? null,
                        $billedCategory,
                        $this->tariffs->categoryForFeeCategoryId($member->feeCategoryId),
                        null
                    );
                }

                $rosterCategory = $this->tariffs->categoryForFeeCategoryId($member->feeCategoryId);
                if ($billedCategory !== null && $rosterCategory !== null && $billedCategory !== $rosterCategory) {
                    $expectedPrice = $prices[$rosterCategory->value] ?? null;
                    $found[] = $this->discrepancy(
                        NominativeDiscrepancy::DIFFERENT_CATEGORY,
                        $memberId,
                        $sectionLabels[$line->sectionId] ?? null,
                        $sectionLabels[$member->sectionId] ?? null,
                        $billedCategory,
                        $rosterCategory,
                        $expectedPrice === null ? null : $line->unitPriceCents - $expectedPrice
                    );
                }
            }
        }

        foreach ($this->missingFromInvoice($snapshot, $billed, $billedSections) as [$member, $category]) {
            $found[] = $this->discrepancy(
                NominativeDiscrepancy::NOT_ON_INVOICE,
                $member->memberId,
                null,
                $sectionLabels[$member->sectionId] ?? null,
                null,
                $category,
                isset($prices[$category->value]) ? -$prices[$category->value] : null
            );
        }

        foreach ($this->brevetsNotReduced($lines, $snapshot) as [$member, $reductionCents]) {
            $found[] = $this->discrepancy(
                NominativeDiscrepancy::BREVET_REDUCTION_MISSING,
                $member->memberId,
                null,
                $sectionLabels[$member->sectionId] ?? null,
                null,
                $this->tariffs->categoryForFeeCategoryId($member->feeCategoryId),
                abs($reductionCents)
            );
        }

        return $this->withNames($found, $invoice->scoutYearId);
    }

    /**
     * How many people the document billed that this site could not tie to
     * a member. Not a nominative discrepancy — there is no name to show,
     * by construction — but the number belongs on the report, because a
     * verification of 40 people that quietly checked 34 is worse than no
     * verification.
     */
    public function unmatchedPeopleCount(StoredInvoice $invoice): int
    {
        $count = 0;
        foreach ($this->invoices->findLines($invoice->id) as $line) {
            $count += $line->unmatchedPeopleCount;
        }

        return $count;
    }

    /**
     * Held by Desk, in a section this invoice bills, on a household
     * tariff — and named by no fee line.
     *
     * Restricted to the sections the document actually bills: an invoice
     * that covers three sections out of five is not "missing" the other
     * two, and reporting their whole roster as absent would bury the one
     * real omission under a hundred false ones. A member on a tariff
     * outside the three is skipped for the same reason the count check
     * skips their line — the site cannot say what they should cost.
     *
     * @param array<int, RosterSnapshotMember> $snapshot
     * @param array<int, true> $billed
     * @param array<int, true> $billedSections
     * @return array<array{RosterSnapshotMember, HouseholdFeeCategory}>
     */
    private function missingFromInvoice(array $snapshot, array $billed, array $billedSections): array
    {
        $missing = [];
        foreach ($snapshot as $member) {
            if (isset($billed[$member->memberId]) || $member->sectionId === null) {
                continue;
            }
            if (!isset($billedSections[$member->sectionId])) {
                continue;
            }
            $category = $this->tariffs->categoryForFeeCategoryId($member->feeCategoryId);
            if ($category === null) {
                continue;
            }
            $missing[] = [$member, $category];
        }

        return $missing;
    }

    /**
     * Brevetés the document did not reduce — **only on a document that
     * reduces somebody**.
     *
     * An invoice carrying no brevet line at all is not an invoice that
     * forgot one: the federation may bill the reduction separately, or
     * this may be a deposit. Flagging every breveté of the unit on such a
     * document would be a page of false alarms, which is how a report
     * stops being read.
     *
     * @param StoredInvoiceLine[] $lines
     * @param array<int, RosterSnapshotMember> $snapshot
     * @return array<array{RosterSnapshotMember, int}>
     */
    private function brevetsNotReduced(array $lines, array $snapshot): array
    {
        $reduced = [];
        $unitPriceBySection = [];
        foreach ($lines as $line) {
            if ($line->nature !== InvoiceLine::NATURE_REDUCTION || !self::mentionsBrevet($line)) {
                continue;
            }
            $unitPriceBySection[$line->sectionId ?? 0] = $line->unitPriceCents;
            foreach ($line->memberIds as $memberId) {
                $reduced[$memberId] = true;
            }
        }

        if ($unitPriceBySection === []) {
            return [];
        }

        $missing = [];
        foreach ($snapshot as $member) {
            if (isset($reduced[$member->memberId]) || !$this->brevets->isBrevet($member->formationLevel)) {
                continue;
            }
            // Only where the document actually applied the reduction: a
            // section it never mentions is not a section it forgot.
            if (!array_key_exists($member->sectionId ?? 0, $unitPriceBySection)) {
                continue;
            }
            $missing[] = [$member, $unitPriceBySection[$member->sectionId ?? 0]];
        }

        return $missing;
    }

    /**
     * What this document charges for each household tariff.
     *
     * Read off the document itself rather than off the barème a chef
     * d'unité typed: this is what the federation charged **on this
     * invoice**, which is the only price a difference on this invoice can
     * honestly be costed at. First occurrence wins; a document pricing one
     * tariff two ways is not something to average.
     *
     * @param StoredInvoiceLine[] $lines
     * @return array<string, int> household category => unit price in cents
     */
    private function pricesByCategory(array $lines): array
    {
        $prices = [];
        foreach ($lines as $line) {
            if ($line->nature !== InvoiceLine::NATURE_FEE) {
                continue;
            }
            $category = FeeCategoryClassifier::classify($line->reference, $line->descriptor);
            if ($category === null) {
                continue;
            }
            $prices[$category->value] ??= $line->unitPriceCents;
        }

        return $prices;
    }

    /**
     * @param StoredInvoiceLine[] $lines
     * @return array<int, true>
     */
    private function billedSectionIds(array $lines): array
    {
        $sections = [];
        foreach ($lines as $line) {
            if ($line->nature === InvoiceLine::NATURE_FEE && $line->sectionId !== null) {
                $sections[$line->sectionId] = true;
            }
        }

        return $sections;
    }

    /**
     * @param RosterSnapshotMember[] $members
     * @return array<int, RosterSnapshotMember>
     */
    private function indexByMemberId(array $members): array
    {
        $indexed = [];
        foreach ($members as $member) {
            $indexed[$member->memberId] = $member;
        }

        return $indexed;
    }

    private function discrepancy(
        string $kind,
        int $memberId,
        ?string $billedSectionLabel,
        ?string $rosterSectionLabel,
        ?HouseholdFeeCategory $billedCategory,
        ?HouseholdFeeCategory $rosterCategory,
        ?int $costCents
    ): NominativeDiscrepancy {
        return new NominativeDiscrepancy(
            $kind,
            $memberId,
            '',
            '',
            null,
            $billedSectionLabel,
            $rosterSectionLabel,
            $billedCategory === null ? null : HouseholdCategoryLabel::for($billedCategory),
            $rosterCategory === null ? null : HouseholdCategoryLabel::for($rosterCategory),
            $costCents
        );
    }

    /**
     * The names, fetched once for the whole report and grafted on at the
     * end — neither the snapshot nor a stored invoice holds one, on
     * purpose, so this is the single join back to a readable person.
     *
     * @param NominativeDiscrepancy[] $discrepancies
     * @return NominativeDiscrepancy[]
     */
    private function withNames(array $discrepancies, int $scoutYearId): array
    {
        if ($discrepancies === []) {
            return [];
        }

        $names = $this->names->findNamesByMemberId(
            array_map(static fn(NominativeDiscrepancy $d): int => $d->memberId, $discrepancies),
            $scoutYearId
        );

        $named = array_map(
            static function (NominativeDiscrepancy $d) use ($names): NominativeDiscrepancy {
                $name = $names[$d->memberId] ?? ['first_name' => '', 'last_name' => '', 'totem' => null];

                return new NominativeDiscrepancy(
                    $d->kind,
                    $d->memberId,
                    $name['first_name'],
                    $name['last_name'],
                    $name['totem'],
                    $d->billedSectionLabel,
                    $d->rosterSectionLabel,
                    $d->billedCategoryLabel,
                    $d->rosterCategoryLabel,
                    $d->costCents
                );
            },
            $discrepancies
        );

        usort(
            $named,
            static fn(NominativeDiscrepancy $a, NominativeDiscrepancy $b): int
                => [$a->rank(), $a->lastName, $a->firstName] <=> [$b->rank(), $b->lastName, $b->firstName]
        );

        return $named;
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
            $issued = DateInput::fromStorage($invoice->issueDate);
            if ($issued === null) {
                return null;
            }

            return (int) $issued->diff($snapshot->takenAt->setTime(0, 0))->format('%r%a');
        }

        return null;
    }

    /**
     * @param RosterSnapshotMember[]|null $snapshotMembers
     * @param array<int, string> $sectionLabels
     */
    private function reconstitute(
        StoredInvoiceLine $line,
        ?array $snapshotMembers,
        array $sectionLabels
    ): ReconstitutedLine
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
                fn(RosterSnapshotMember $m): bool => $this->brevets->isBrevet($m->formationLevel)
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

    /**
     * Whether a line is ABOUT a brevet reduction — read from the document's
     * own words, never through a unit's mapping of what its people's Desk
     * wordings mean (see BrevetDetector::mentionsBrevet()).
     */
    private static function mentionsBrevet(StoredInvoiceLine $line): bool
    {
        return BrevetDetector::mentionsBrevet($line->reference . ' ' . $line->descriptor);
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
