<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * What a manager decided about a damage (§6.23), in three states (#980).
 *
 * **The financial decision stays human.** Nothing in this module turns a
 * damage into a charge on its own. An automatic scale would be wrong about
 * exactly the case that matters — the group that broke something and
 * immediately said so — and §6.17 already refuses one for cancellations
 * for the same reason.
 *
 * `PENDING` is a real state: an assessment in progress must never reach the
 * renter's page (§6.26), and this is the flag that keeps it off. A manager
 * picks it too, to note what the assessment waits for, and a decided
 * incident may go back to it.
 *
 * There is no « withheld from the security deposit » any more: it kept the
 * same money as a charge, capped at the deposit, so the surplus of a damage
 * dearer than the deposit vanished. A charged damage enters the settlement,
 * and what the deposit covers follows from the money
 * (`SettlementCalculator::securityDepositAtSettlement()`).
 */
enum IncidentDecision: string
{
    case PENDING = 'pending';
    /** Added to the final settlement, so the renter pays it. */
    case CHARGE = 'charge';
    case WAIVE = 'waive';

    /**
     * The value #980 retired. `IncidentDecisionMigration` converts it to
     * `charge` once per installation; one still stored reads as CHARGE.
     */
    public const LEGACY_WITHHOLD = 'withhold';

    /**
     * A stored value, read defensively: the retired one is a charge (it kept
     * the same money), anything unknown is still pending.
     */
    public static function fromStored(string $value): self
    {
        if ($value === self::LEGACY_WITHHOLD) {
            return self::CHARGE;
        }

        return self::tryFrom($value) ?? self::PENDING;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => "En cours d'évaluation",
            self::CHARGE => 'Facturer',
            self::WAIVE => 'Ne pas facturer',
        };
    }

    /** What picking this state does, said under the choice in « Trancher ». */
    public function consequence(): string
    {
        return match ($this) {
            self::PENDING => "Rien n'est facturé tant que l'évaluation est en cours : le décompte ne se valide "
                . "pas et la caution reste retenue. Le locataire n'en voit ni la description ni le montant.",
            self::CHARGE => "Le montant s'ajoute au décompte. S'il dépasse la caution, le surplus est demandé "
                . 'au locataire.',
            self::WAIVE => "Rien n'est facturé pour cet incident. Le motif reste interne : le locataire ne le "
                . 'voit pas.',
        };
    }

    /** Whether this incident's amount enters the final settlement's total. */
    public function entersSettlement(): bool
    {
        return $this === self::CHARGE;
    }

    /**
     * Whether this incident may be shown to the renter.
     *
     * A damage still being assessed must not appear on their page: they
     * would read a proposed figure as a demand, and the whole point of
     * `PENDING` is that nobody has decided yet (§6.26).
     */
    public function isVisibleToRenter(): bool
    {
        return $this !== self::PENDING;
    }

    /** @return self[] The ones a manager may pick in « Trancher » — all three, in that order. */
    public static function decidable(): array
    {
        return [self::PENDING, self::CHARGE, self::WAIVE];
    }
}
