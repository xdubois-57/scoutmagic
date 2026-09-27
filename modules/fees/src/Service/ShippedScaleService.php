<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Modules\Fees\Support\ShippedFederalScale;

/**
 * Decides whether the barème opens pre-filled with the federal scale the
 * site ships (`Support\ShippedFederalScale`, issue #355).
 *
 * Two conditions, both required:
 *
 * - **the barème was never saved** — a unit that saved it, even with its
 *   amounts left empty on purpose, has made it its own, and a shipped
 *   figure must never sit over it; offered again on every visit, it would
 *   be saved by the next unrelated change to the panel;
 * - **the file's year is the year the screen is about** — the effective
 *   scout year, the same one « Chercher les montants » compares its answer
 *   with. Last season's amounts in this season's fields would be three
 *   plausible numbers from the wrong year, which is exactly what the AI
 *   lookup refuses to propose.
 *
 * The answer has the shape `Support\SuggestedScale::take()` returns, so the
 * panel fills its fields through the one path it already has, plus
 * `origin` and `verified_on` so the banner can say where the figures come
 * from and when they were read. **Nothing is written**: this service holds
 * no repository of its own, and only « Enregistrer le barème » stores the
 * amounts.
 */
class ShippedScaleService
{
    public const ORIGIN = 'shipped';

    public function __construct(
        private HouseholdTariffService $tariffs,
        private string $path = ShippedFederalScale::PATH
    ) {
    }

    /**
     * @return array{
     *     url: string,
     *     year: string,
     *     amount_cents: array<string, int>,
     *     origin: string,
     *     verified_on: string,
     *     host: string
     * }|null
     */
    public function suggestionFor(string $scoutYearLabel): ?array
    {
        if ($this->tariffs->wasEverSaved()) {
            return null;
        }

        $scale = $this->load();
        if ($scale === null) {
            return null;
        }

        $current = FederalScaleLookupService::normalizeYear($scoutYearLabel);
        if ($current === null || $current !== $scale->year) {
            return null;
        }

        $host = (string) parse_url($scale->sourceUrl, PHP_URL_HOST);

        return [
            'url' => $scale->sourceUrl,
            'year' => $scale->year,
            'amount_cents' => $scale->amountCents,
            'origin' => self::ORIGIN,
            'verified_on' => $scale->verifiedOn,
            'host' => preg_replace('/^www\./', '', $host) ?? $host,
        ];
    }

    /**
     * The shipped file, or null when it cannot be read. A malformed file
     * cannot reach a release — `Tests\Modules\Fees\Support\
     * ShippedFederalScaleTest` loads the real one — so the only way here is
     * a partial deploy, and the honest answer then is the empty barème the
     * screen always had, not an error page over the whole report.
     */
    private function load(): ?ShippedFederalScale
    {
        try {
            return ShippedFederalScale::load($this->path);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }
}
