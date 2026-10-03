<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Modules\Rental\Pricing\PriceQuote;

/**
 * What a contract says about its booking, as one hash (#708, IT-20).
 *
 * **Generic rather than a list of cases.** A contract is void the moment
 * the booking stops saying what it says — new dates, another head count, a
 * price or one of its lines changed, the renter's details corrected, the
 * asset renamed — whichever screen the change came from: an accepted
 * request, an accepted proposal, a correction a manager made elsewhere. So
 * nothing lists the gestures that void a contract; the hash taken at
 * generation is compared with the booking's own, and a difference is the
 * answer.
 *
 * Only what the contract itself states counts. An internal comment, a
 * payment recorded, a step ticked are not on the page the renter signed,
 * and change nothing here; nor does the date of the day the PDF was made,
 * which `date_du_jour` would otherwise turn into a contract voided every
 * midnight.
 */
final class ContractFingerprint
{
    /** The keywords that describe the booking, in a fixed order. */
    private const KEYS = [
        'reference',
        'bien',
        'date_arrivee',
        'date_depart',
        'heure_arrivee',
        'heure_depart',
        'nuits',
        'participants',
        'quantite',
        'prix_total',
        'acompte',
        'caution',
        'locataire_nom',
        'locataire_organisation',
        'locataire_email',
        'locataire_telephone',
        'locataire_adresse',
        'locataire_tva',
    ];

    /**
     * @param array<string, string|null> $values `RentalDocumentService::valuesFor()`
     * @param ?PriceQuote $price the booking's price in force, whose lines a
     *   contract may print even where no keyword names them
     */
    public static function of(array $values, ?PriceQuote $price): string
    {
        $said = [];
        foreach (self::KEYS as $key) {
            $value = $values[$key] ?? null;
            $said[$key] = $value !== null ? trim($value) : null;
        }

        $lines = [];
        foreach ($price->lines ?? [] as $line) {
            $lines[] = [$line->label, $line->quantity, $line->unitPriceCents, $line->amountCents];
        }
        $said['lignes'] = $lines;

        return hash('sha256', (string) json_encode($said, JSON_UNESCAPED_UNICODE));
    }
}
