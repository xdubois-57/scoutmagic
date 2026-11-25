<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Modules\Fees\Support\ShippedFederalScale;
use Modules\Fees\FeeTariff;

/**
 * Offers shipped amounts only for rows never saved and only for the
 * displayed season. A saved NULL or zero remains the unit's choice.
 * Older three-row scales can therefore gain IAmA and guests without
 * changing their existing amounts. This service never writes anything.
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
        $scale = $this->load();
        if ($scale === null) {
            return null;
        }

        $current = FederalScaleLookupService::normalizeYear($scoutYearLabel);
        if ($current === null || $current !== $scale->year) {
            return null;
        }

        $amounts = [];
        foreach ($scale->amountCents as $key => $cents) {
            if (!$this->tariffs->wasSaved(FeeTariff::from($key))) {
                $amounts[$key] = $cents;
            }
        }
        if ($amounts === []) {
            return null;
        }

        $host = (string) parse_url($scale->sourceUrl, PHP_URL_HOST);

        return [
            'url' => $scale->sourceUrl,
            'year' => $scale->year,
            'amount_cents' => $amounts,
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
