<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Import\FeeCategoryRepository;
use Core\Service\DateInput;
use Modules\Fees\FeeTariff;

/** Validates and stores one complete scale, including its annual guest policy. */
class TariffConfigurationService
{
    public const SETTING_GUEST_FROM = 'fees_guest_from';
    public const SETTING_GUEST_UNTIL = 'fees_guest_until';
    public const SETTING_YEAR = 'fees_scale_year';

    public function __construct(
        private HouseholdTariffService $tariffs,
        private FeeCategoryRepository $feeCategories,
        private SettingService $settings
    ) {
    }

    /** @return array{from: string, until: string, year: string} */
    public function fields(): array
    {
        $read = fn(string $key): string => (string) $this->settings->get($key, 'fees', '');

        return [
            'from' => $read(self::SETTING_GUEST_FROM),
            'until' => $read(self::SETTING_GUEST_UNTIL),
            'year' => $read(self::SETTING_YEAR),
        ];
    }

    /** The year explicitly confirmed by saving the scale, never inferred for legacy rows. */
    public function referenceYear(): ?string
    {
        $year = $this->fields()['year'];

        return FederalScaleLookupService::normalizeYear($year) === $year ? $year : null;
    }

    /** @return array{from: \DateTimeImmutable, until: \DateTimeImmutable}|null */
    public function periodForYear(string $year): ?array
    {
        $fields = $this->fields();

        return self::periodFromFields($year, $fields['from'], $fields['until']);
    }

    /** A canonical annual date, valid in the season's ENDING calendar year. */
    public static function dayMonthOrNull(mixed $value, string $year): ?string
    {
        $year = FederalScaleLookupService::normalizeYear($year);
        if ($year === null || !is_string($value) || preg_match('/^(\d{1,2})\/(\d{1,2})$/D', trim($value), $m) !== 1) {
            return null;
        }
        $date = sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);

        return DateInput::parse('!d/m/Y', $date . '/' . substr($year, 5, 4)) === null ? null : $date;
    }

    /** Refuses a form kept open across a scout-year switch. */
    public static function requireFormYear(mixed $postedYear, string $year): string
    {
        $normalised = FederalScaleLookupService::normalizeYear($year);
        if ($normalised === null || !is_string($postedYear) || $postedYear !== $normalised) {
            throw new SettingException("L’année scoute affichée a changé. Rechargez le barème avant de continuer.");
        }

        return $normalised;
    }

    /** @param array<string, mixed> $input */
    public function save(string $year, array $input): void
    {
        $year = self::requireFormYear($input['scale_year'] ?? null, $year);
        $period = self::periodFromFields($year, $input['guest_from'] ?? null, $input['guest_until'] ?? null);
        if ($period === null) {
            throw new SettingException(
                'Indiquez deux dates valides au format jour/mois, dans l’ordre de début et de fin.'
            );
        }
        $rows = $this->validatedRows($input);

        try {
            $this->tariffs->transactionally(function () use ($rows, $period, $year): void {
                foreach ($rows as [$tariff, $feeId, $cents]) {
                    $this->tariffs->save($tariff, $feeId, $cents);
                }
                $this->settings->setManyInternal([
                    self::SETTING_GUEST_FROM => $period['from']->format('d/m'),
                    self::SETTING_GUEST_UNTIL => $period['until']->format('d/m'),
                    self::SETTING_YEAR => $year,
                ], 'fees');
            });
        } catch (SettingException $error) {
            throw new SettingException(
                'Le barème n’a pas pu être enregistré. Rechargez la page puis réessayez.',
                0,
                $error
            );
        } finally {
            $this->settings->clearCache();
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{FeeTariff, ?int, ?int}>
     */
    private function validatedRows(array $input): array
    {
        $known = array_column($this->feeCategories->findAll(), 'id');
        $claimed = [];
        $rows = [];
        foreach (FeeTariff::cases() as $tariff) {
            $feeId = self::feeId($input['fee_category_' . $tariff->value] ?? null, $known);
            if ($feeId !== null && in_array($feeId, $claimed, true)) {
                throw new SettingException('Un tarif Desk ne peut correspondre à deux lignes du barème.');
            }
            if ($feeId !== null) {
                $claimed[] = $feeId;
            }
            $rows[] = [$tariff, $feeId, self::amount($input['amount_' . $tariff->value] ?? null, $tariff)];
        }

        return $rows;
    }

    /** @param list<int> $known */
    private static function feeId(mixed $raw, array $known): ?int
    {
        if ($raw === '') {
            return null;
        }
        if ((!is_string($raw) && !is_int($raw)) || !ctype_digit((string) $raw)) {
            throw new SettingException('Choisissez un tarif Desk dans la liste proposée.');
        }
        $id = (int) $raw;
        if (!in_array($id, $known, true)) {
            throw new SettingException(
                'Ce tarif Desk n’est plus disponible. Rechargez la page puis choisissez-le à nouveau.'
            );
        }

        return $id;
    }

    private static function amount(mixed $raw, FeeTariff $tariff): ?int
    {
        if (!is_string($raw)) {
            throw new SettingException('Complétez le montant « ' . $tariff->label() . ' », ou laissez-le vide.');
        }
        $raw = str_replace([' ', "\u{a0}", "\u{202f}"], '', trim($raw));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/D', $raw, $m) !== 1) {
            throw new SettingException('Le montant « ' . $tariff->label()
                . ' » doit être positif ou nul, avec au plus deux décimales.');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** @return array{from: \DateTimeImmutable, until: \DateTimeImmutable}|null */
    public static function periodFromFields(string $year, mixed $from, mixed $until): ?array
    {
        $normalised = FederalScaleLookupService::normalizeYear($year);
        $from = self::dayMonthOrNull($from, $year);
        $until = self::dayMonthOrNull($until, $year);
        if ($normalised === null || $from === null || $until === null) {
            return null;
        }
        $calendarYear = substr($normalised, 5, 4);
        $start = DateInput::parse('!d/m/Y', $from . '/' . $calendarYear);
        $end = DateInput::parse('!d/m/Y', $until . '/' . $calendarYear);

        return $start === null || $end === null || $end < $start ? null : ['from' => $start, 'until' => $end];
    }
}
