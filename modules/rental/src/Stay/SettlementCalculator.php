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
 * Nothing is « withheld from the security deposit » either (#980): a
 * charged damage is a line, and the deposit's share of what is kept follows
 * from the money (`securityDepositAtSettlement()`).
 */
final class SettlementCalculator
{
    /**
     * @param MeterConsumption[] $consumptions
     * @param Incident[] $incidents
     * @param SettlementLine[] $manualLines Lines a manager added by hand —
     *   the « Geste commercial » among them.
     * @param bool $paidIncludesSecurityDeposit whether $alreadyPaidCents
     *   came in on the booking's single receivable, which holds the
     *   security deposit too (issue #927). Then the balance is measured
     *   against the KEPT amount — the total — and a negative one is
     *   everything to pay back, the deposit's return included, with the
     *   deposit's share derived by securityDepositAtSettlement(). Without
     *   it (no Finance) the deposit is held by hand beside the rental: the
     *   balance is the total less what was paid, and the deposit is given
     *   back whole, a charged damage being on the balance already.
     * @return array{
     *     lines: SettlementLine[],
     *     total_cents: int,
     *     kept_cents: int,
     *     balance_cents: int,
     *     security_deposit_withheld_cents: int,
     *     security_deposit_return_cents: int,
     *     paid_includes_security_deposit: bool
     * }
     */
    public function compute(
        ?PriceQuote $agreedPrice,
        ?int $finalPersons,
        array $consumptions,
        array $incidents,
        array $manualLines,
        int $alreadyPaidCents,
        ?int $securityDepositCents,
        bool $paidIncludesSecurityDeposit = false
    ): array {
        $lines = array_merge(
            $this->agreedLines($agreedPrice, $finalPersons),
            $this->meterLines($consumptions),
            $this->incidentLines($incidents),
            array_values($manualLines)
        );
        $lines = self::capGestures($lines);

        $total = 0;
        foreach ($lines as $line) {
            $total += $line->amountCents;
        }

        // What the unit keeps of the money: the agreed rental, the
        // consumptions and the charged damage, less any gesture — the total,
        // and nothing beside it. A damage dearer than the deposit is kept in
        // full: the surplus is a balance still due, never lost to a cap.
        $kept = $total;

        $deposit = $paidIncludesSecurityDeposit
            ? self::securityDepositAtSettlement($securityDepositCents, $alreadyPaidCents, $kept)
            : ['withheld_cents' => 0, 'return_cents' => max(0, $securityDepositCents ?? 0)];

        return [
            'lines' => $lines,
            'total_cents' => $total,
            'kept_cents' => $kept,
            // Deliberately signed: an overpayment is a real outcome, and
            // flooring it at zero is how a refund nobody knows about
            // happens.
            'balance_cents' => $total - $alreadyPaidCents,
            'security_deposit_withheld_cents' => $deposit['withheld_cents'],
            'security_deposit_return_cents' => $deposit['return_cents'],
            // Said so the screen does not add the deposit's return to a
            // balance that already contains it: with one receivable, a
            // negative balance IS everything owed back, deposit included.
            'paid_includes_security_deposit' => $paidIncludesSecurityDeposit,
        ];
    }

    /**
     * What a settlement keeps of the security deposit, on the booking's
     * single receivable (#980, chantier §2.3): the refund owed comes first
     * out of the deposit, so
     *
     *     withheld = D − min(D, max(0, received − kept))
     *
     * and the rest of the deposit is given back. Received 818 € for a
     * 668 € rental and a 150 € deposit, a 30 € damage charged: 120 € go
     * back, all of them deposit, and 30 € of it are kept. A damage dearer
     * than the deposit keeps all of it and leaves a balance due. Derived
     * from the money rather than from any incident, which is what
     * « withhold » used to do — the same figure the settlement screen
     * names « dont la caution à rendre ».
     *
     * @return array{withheld_cents: int, return_cents: int}
     */
    public static function securityDepositAtSettlement(?int $depositCents, int $receivedCents, int $keptCents): array
    {
        $deposit = max(0, $depositCents ?? 0);
        $given = min($deposit, max(0, $receivedCents - $keptCents));

        return ['withheld_cents' => $deposit - $given, 'return_cents' => $given];
    }

    /**
     * A « Geste commercial » never takes the stay below nothing (issue
     * #927): each one is capped at what the other lines still come to, so
     * the total — and the amount the receivable is brought to on
     * validation — stays at 0 at worst, and the refund the screen shows is
     * the one Finance records.
     *
     * @param SettlementLine[] $lines
     * @return SettlementLine[]
     */
    private static function capGestures(array $lines): array
    {
        $available = 0;
        foreach ($lines as $line) {
            if ($line->origin !== SettlementLine::ORIGIN_GESTURE) {
                $available += $line->amountCents;
            }
        }

        $capped = [];
        foreach ($lines as $line) {
            if ($line->origin === SettlementLine::ORIGIN_GESTURE) {
                $discount = min(abs($line->amountCents), max(0, $available));
                if ($discount === 0) {
                    // Nothing left to take off: a 0,00 € line says nothing.
                    continue;
                }
                $available -= $discount;
                $line = new SettlementLine($line->label, -$discount, $line->origin, $line->detail);
            }
            $capped[] = $line;
        }

        return $capped;
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
            // Only what a human decided to charge. A pending assessment and
            // a waived damage contribute nothing to this total.
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
