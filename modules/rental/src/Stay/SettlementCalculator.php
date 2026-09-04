<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Support;

/**
 * What a stay comes to once it has happened (§6.21).
 *
 * Pure — no database, no clock, no session — like the pricing engine it
 * deliberately does not reuse.
 *
 * **It never modifies the agreed price.** The spec is emphatic, and the
 * separation is structural here: this takes the agreed quote as an input
 * and returns a new set of lines, so there is no code path by which a
 * settlement could write back. The two answer different questions — "what
 * did we agree?" and "what does it come to now?" — and letting the second
 * overwrite the first would destroy the evidence for exactly the disputes
 * this exists to settle.
 *
 * **Per-person lines are recomputed on the head count that actually turned
 * up**, everything else is carried across unchanged. A group that announced
 * forty and brought twenty-eight pays a tourist tax on twenty-eight; the
 * hall itself still costs what renting the hall costs. That asymmetry is
 * the same one §6.10 already draws for the billable minimum, for the same
 * reason.
 *
 * **Nothing here decides anything about a damage.** An incident enters the
 * total only if a human already decided it should (`IncidentDecision`).
 */
final class SettlementCalculator
{
    /**
     * @param MeterConsumption[] $consumptions
     * @param Incident[] $incidents
     * @param SettlementLine[] $manualLines Lines a manager added by hand.
     * @return array{
     *     lines: SettlementLine[],
     *     total_cents: int,
     *     balance_cents: int,
     *     security_deposit_withheld_cents: int,
     *     security_deposit_return_cents: int
     * }
     */
    public function compute(
        ?PriceQuote $agreedPrice,
        ?int $finalPersons,
        array $consumptions,
        array $incidents,
        array $manualLines,
        int $alreadyPaidCents,
        ?int $securityDepositCents
    ): array {
        $lines = array_merge(
            $this->agreedLines($agreedPrice, $finalPersons),
            $this->meterLines($consumptions),
            $this->incidentLines($incidents),
            array_values($manualLines)
        );

        $total = 0;
        foreach ($lines as $line) {
            $total += $line->amountCents;
        }

        $withheld = 0;
        foreach ($incidents as $incident) {
            if ($incident->decision->reducesSecurityDeposit()) {
                $withheld += $incident->effectiveAmountCents();
            }
        }

        // Never more than was actually held: withholding 800 € from a 500 €
        // deposit does not make the renter owe 300 € twice, and a manager
        // who wants the difference invoices it as a charge instead.
        $withheld = $securityDepositCents !== null ? min($withheld, $securityDepositCents) : 0;

        return [
            'lines' => $lines,
            'total_cents' => $total,
            // Deliberately signed: an overpayment is a real outcome, and
            // flooring it at zero is how a refund nobody knows about
            // happens.
            'balance_cents' => $total - $alreadyPaidCents,
            'security_deposit_withheld_cents' => $withheld,
            'security_deposit_return_cents' => max(0, ($securityDepositCents ?? 0) - $withheld),
        ];
    }

    /**
     * The agreed quote's lines, with the per-person ones re-scaled to the
     * head count that actually turned up.
     *
     * A quote line records its own quantity, so re-scaling is a ratio rather
     * than a re-derivation: it does not need to know which grid cell or
     * which fee produced the line, and it cannot disagree with the engine
     * about anything except the head count it was told to use.
     *
     * **The denominator is the line's own quantity, never `$quote->persons`.**
     * A per-person fee is billed on the head count the renter announced
     * (Pricing\RentalFee::amountForCents), while `$quote->persons` is that
     * count already raised to the billable minimum. The two differ exactly
     * when a minimum applies, and using the raised one re-scaled the fee
     * against a number it was never billed on: 20 people announced with a
     * minimum of 25 had their tourist tax cut to 16,00 € — and the detail
     * read "20 participants au lieu de 25" — when nobody had dropped out at
     * all.
     *
     * @return SettlementLine[]
     */
    private function agreedLines(?PriceQuote $agreedPrice, ?int $finalPersons): array
    {
        if ($agreedPrice === null) {
            return [];
        }

        $lines = [];
        foreach ($agreedPrice->lines as $line) {
            // A meter fee was never in the quote's total — it is settled
            // from a real reading below, and carrying its zero across would
            // put the same thing on the settlement twice.
            if ($line->isInformational) {
                continue;
            }

            $billedFor = $line->quantity;

            if ($finalPersons === null || !$this->isPerPerson($line) || $billedFor <= 0) {
                $lines[] = new SettlementLine($line->label, $line->amountCents, SettlementLine::ORIGIN_AGREED);
                continue;
            }

            if ($finalPersons === $billedFor) {
                // Nobody dropped out and nobody extra came: the line stands
                // as agreed, with no "au lieu de" note implying otherwise.
                $lines[] = new SettlementLine($line->label, $line->amountCents, SettlementLine::ORIGIN_AGREED);
                continue;
            }

            $rescaled = (int) round($line->amountCents * $finalPersons / $billedFor);
            $lines[] = new SettlementLine(
                $line->label,
                $rescaled,
                SettlementLine::ORIGIN_PER_PERSON,
                $finalPersons . ' participants au lieu de ' . $billedFor
            );
        }

        return $lines;
    }

    /**
     * A quote line that scales with the head count.
     *
     * A hand-edited line is deliberately excluded whatever its rule: a
     * manager who typed an amount has already decided what it is, and
     * re-scaling it behind their back is the same mistake §6.12 forbids for
     * re-quoting.
     */
    private function isPerPerson(PriceLine $line): bool
    {
        if ($line->isManual) {
            return false;
        }

        return $line->rule === PriceLine::RULE_FEE_PER_PERSON;
    }

    /**
     * @param MeterConsumption[] $consumptions
     * @return SettlementLine[]
     */
    private function meterLines(array $consumptions): array
    {
        $lines = [];
        foreach ($consumptions as $consumption) {
            // An incomplete or contradictory reading contributes nothing
            // and says so on screen instead: billing a guess is worse than
            // billing nothing.
            if (!$consumption->isBillable()) {
                continue;
            }

            $lines[] = new SettlementLine(
                $consumption->meter->label,
                $consumption->amountCents,
                SettlementLine::ORIGIN_METER,
                $consumption->formattedConsumption()
                . ($consumption->unitPriceCents !== null
                    ? ' × ' . Support::euros($consumption->unitPriceCents)
                    : '')
            );
        }

        return $lines;
    }

    /**
     * @param Incident[] $incidents
     * @return SettlementLine[]
     */
    private function incidentLines(array $incidents): array
    {
        $lines = [];
        foreach ($incidents as $incident) {
            // Only what a human decided to charge. A pending assessment, a
            // waived damage and one withheld from the deposit all
            // contribute nothing to this total.
            if (!$incident->decision->entersSettlement()) {
                continue;
            }

            $lines[] = new SettlementLine(
                self::truncate($incident->description, 120),
                $incident->effectiveAmountCents(),
                SettlementLine::ORIGIN_INCIDENT
            );
        }

        return $lines;
    }

    /**
     * The unit-price lookup a caller needs to turn a meter into a
     * consumption: the `meter` fee whose id the meter points at.
     *
     * @param RentalFee[] $fees
     */
    public static function feeFor(?int $feeId, array $fees): ?RentalFee
    {
        if ($feeId === null) {
            return null;
        }

        foreach ($fees as $fee) {
            if ($fee->id === $feeId && $fee->nature === RentalFee::NATURE_METER) {
                return $fee;
            }
        }

        return null;
    }

    private static function truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length)) . '…';
    }
}
