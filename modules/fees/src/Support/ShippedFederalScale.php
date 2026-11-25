<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Support;

use Core\ExternalSource\FederalScale;
use Core\Service\DateInput;
use Modules\Fees\FeeTariff;
use Modules\Fees\Service\FederalScaleLookupService;

/**
 * The federal scale shipped with the site (issue #355, « Le barème
 * livré »): `modules/fees/data/federal-scale.json`, five per-person
 * amounts and the annual paid guest window, their scout year, source page
 * and verification date.
 *
 * Two readers, one file:
 *
 * - the barème panel pre-fills its available fields from it when the unit has
 *   entered no amount yet and the file's year is the year the screen is
 *   about (`Modules\Fees\Service\ShippedScaleService`) — a proposal, never
 *   a write;
 * - `scripts/check-external-sources.php` hands {@see self::toFederalScale()}
 *   to `Core\ExternalSource\ExternalSourceChecker` as its reference, so the
 *   weekly check and the release gate report a divergence the day the
 *   federal page stops saying what this file says.
 *
 * **Read strictly, and refused whole.** Every key must be present with
 * the right type and nothing else may be: a scout year that normalises to
 * itself, an https URL, a real `YYYY-MM-DD` date, and five amounts in
 * cents inside the range a cotisation can plausibly be. Both day/month
 * bounds must be valid and ordered in the season's ending year. A malformed file
 * throws — `Tests\Modules\Fees\Support\ShippedFederalScaleTest` loads the
 * real one, so a bad edit fails the build rather than a page.
 *
 * Updating it each season is a data change: year, amounts, paid period and
 * verification date in one commit — the weekly check says when.
 */
final class ShippedFederalScale
{
    /** The file this class reads by default. */
    public const PATH = __DIR__ . '/../../data/federal-scale.json';

    private const KEYS = ['year', 'source_url', 'verified_on', 'amount_cents', 'guest_period'];

    /** Same plausibility bounds as the AI lookup, for the same reason. */
    private const MIN_AMOUNT_CENTS = FederalScaleLookupService::MIN_AMOUNT_CENTS;
    private const MAX_AMOUNT_CENTS = FederalScaleLookupService::MAX_AMOUNT_CENTS;

    /**
     * @param array<string, int> $amountCents keyed by FeeTariff value, always the five
     */
    private function __construct(
        public readonly string $year,
        public readonly string $sourceUrl,
        public readonly string $verifiedOn,
        public readonly array $amountCents,
        public readonly string $guestFromDayMonth,
        public readonly string $guestUntilDayMonth,
    ) {
    }

    /**
     * @throws \UnexpectedValueException when the file is missing or malformed
     */
    public static function load(string $path = self::PATH): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            throw new \UnexpectedValueException('The shipped federal scale file is missing: ' . $path);
        }

        return self::fromJson($contents);
    }

    /**
     * @throws \UnexpectedValueException when the JSON does not describe a scale
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('The shipped federal scale is not valid JSON.', 0, $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \UnexpectedValueException('The shipped federal scale must be a JSON object.');
        }

        $keys = array_keys($data);
        sort($keys);
        $expected = self::KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new \UnexpectedValueException(
                'The shipped federal scale must carry exactly: ' . implode(', ', self::KEYS) . '.'
            );
        }

        $year = self::year($data['year']);
        [$from, $until] = self::period($data['guest_period'], $year);

        return new self(
            $year,
            self::sourceUrl($data['source_url']),
            self::verifiedOn($data['verified_on']),
            self::amounts($data['amount_cents']),
            $from,
            $until
        );
    }

    /** The same figures as the external sources check compares them. */
    public function toFederalScale(): FederalScale
    {
        return new FederalScale(
            $this->amountCents[FeeTariff::NORMAL->value],
            $this->amountCents[FeeTariff::COUPLE->value],
            $this->amountCents[FeeTariff::FAMILY->value],
            $this->amountCents[FeeTariff::IAMA->value],
            $this->amountCents[FeeTariff::GUEST->value],
            $this->guestFromDayMonth,
            $this->guestUntilDayMonth,
            $this->year
        );
    }

    private static function year(mixed $raw): string
    {
        if (!is_string($raw) || FederalScaleLookupService::normalizeYear($raw) !== $raw) {
            throw new \UnexpectedValueException('The shipped federal scale year must read YYYY-YYYY.');
        }

        return $raw;
    }

    private static function sourceUrl(mixed $raw): string
    {
        if (
            !is_string($raw)
            || filter_var($raw, FILTER_VALIDATE_URL) === false
            || parse_url($raw, PHP_URL_SCHEME) !== 'https'
        ) {
            throw new \UnexpectedValueException('The shipped federal scale source must be an https URL.');
        }

        return $raw;
    }

    private static function verifiedOn(mixed $raw): string
    {
        if (!is_string($raw) || !DateInput::isIso($raw)) {
            throw new \UnexpectedValueException('The shipped federal scale verification date must read YYYY-MM-DD.');
        }

        return $raw;
    }

    /**
     * @return array<string, int>
     */
    private static function amounts(mixed $raw): array
    {
        $categories = array_map(
            static fn (FeeTariff $c): string => $c->value,
            FeeTariff::cases()
        );
        if (!is_array($raw) || array_is_list($raw) || count($raw) !== count($categories)) {
            throw new \UnexpectedValueException(
                'The shipped federal scale must give exactly the amounts ' . implode(', ', $categories) . '.'
            );
        }

        $amounts = [];
        foreach ($categories as $category) {
            $cents = $raw[$category] ?? null;
            if (!is_int($cents) || $cents < self::MIN_AMOUNT_CENTS || $cents > self::MAX_AMOUNT_CENTS) {
                throw new \UnexpectedValueException(
                    "The shipped federal scale amount '{$category}' must be a whole number of cents between "
                    . self::MIN_AMOUNT_CENTS . ' and ' . self::MAX_AMOUNT_CENTS . '.'
                );
            }
            $amounts[$category] = $cents;
        }

        return $amounts;
    }

    /** @return array{string, string} */
    private static function period(mixed $raw, string $year): array
    {
        if (
            !is_array($raw) || count($raw) !== 2
            || !is_string($raw['start'] ?? null) || !is_string($raw['end'] ?? null)
        ) {
            throw new \UnexpectedValueException(
                'The shipped guest period must contain start and end day/month strings.'
            );
        }
        $from = DateInput::parse('!d/m/Y', $raw['start'] . '/' . substr($year, 5, 4));
        $until = DateInput::parse('!d/m/Y', $raw['end'] . '/' . substr($year, 5, 4));
        if ($from === null || $until === null || $until < $from) {
            throw new \UnexpectedValueException(
                'The shipped guest period must be valid and ordered in the season ending year.'
            );
        }

        return [$raw['start'], $raw['end']];
    }
}
