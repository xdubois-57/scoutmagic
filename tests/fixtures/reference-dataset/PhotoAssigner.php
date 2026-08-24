<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Decides which photo belongs to whom, and emits the manifest the builder
 * reads.
 *
 * Two rules shape everything here:
 *
 *   1. **An individual photo always belongs to a cadre**, never to an animé.
 *      The trombinoscope only renders chief/admin functions
 *      (SectionService::getSectionStaff()), so a photo on an animé would be a
 *      row nobody ever displays.
 *   2. **A photo belongs to a person, not to a year.** Almost every cadre gets
 *      exactly one row, in their first year; the site's own fallback
 *      (MemberPhotoService::resolveFileId()) produces their photo in the years
 *      after. That is what exercises the fallback instead of assuming it.
 *
 * The lot is never allowed to dictate the roster: if a section's staff has
 * more cadres than there are portraits, the extra cadres simply have none and
 * keep the initials avatar member_photo() renders. Nothing is shrunk to fit.
 *
 * The output manifest is committed and compared by `generate.php --check`, so
 * adding or removing a photo without regenerating is an error rather than a
 * silent shift.
 */
final class PhotoAssigner
{
    /**
     * @param array<string, Person> $people keyed by Tiers, in Tiers order
     */
    public function __construct(
        private readonly Rng $rng,
        private readonly array $people,
    ) {
    }

    /**
     * @return list<array{file: string, kind: string, target: string, year: string, note: string}>
     */
    public function assign(string $datasetRoot): array
    {
        $rows = [];
        foreach (self::groupRows() as $row) {
            $rows[] = $row;
        }
        foreach ($this->individualRows($datasetRoot) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array{file: string, kind: string, target: string, year: string, note: string}>
     */
    private static function groupRows(): array
    {
        $rows = [];
        foreach (PhotoLot::GROUP_PHOTOS as $handle => $byYear) {
            foreach ($byYear as $year => $file) {
                $rows[] = [
                    'file' => $file,
                    'kind' => 'group',
                    'target' => $handle,
                    'year' => $year,
                    'note' => '',
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{file: string, kind: string, target: string, year: string, note: string}>
     */
    private function individualRows(string $datasetRoot): array
    {
        $cadres = $this->cadres();
        $taken = [];
        $rows = [];

        $files = array_values(array_filter(
            PhotoLot::allFiles($datasetRoot),
            static fn (string $file): bool => str_contains($file, '_individual_'),
        ));

        foreach ($files as $file) {
            $gender = PhotoLot::INDIVIDUAL_GENDERS[$file] ?? null;
            if ($gender === null) {
                // A file nobody declared a gender for. Refusing here rather
                // than guessing keeps PhotoLot::INDIVIDUAL_GENDERS honest: a
                // photo added to the directory has to be looked at.
                throw new \RuntimeException(
                    "Aucun genre déclaré pour {$file} dans PhotoLot::INDIVIDUAL_GENDERS."
                );
            }

            $preferred = PhotoLot::PREFIX_SECTIONS[PhotoLot::prefixOf($file)] ?? [];
            $match = $this->pickCadre($cadres, $taken, $gender, $preferred);

            if ($match === null) {
                throw new \RuntimeException(
                    "Aucun cadre libre de genre {$gender} pour {$file} : le lot compte plus de portraits "
                    . 'que l\'unité ne compte de cadres de ce genre.'
                );
            }

            [$tiers, $firstYear, $onPreferredSection] = $match;
            $taken[$tiers] = true;
            $rows[] = [
                'file' => $file,
                'kind' => 'individual',
                'target' => $tiers,
                'year' => $firstYear,
                'note' => $onPreferredSection ? '' : 'hors-prefixe',
            ];
        }

        return [...$rows, ...$this->rePhotographRows($rows)];
    }

    /**
     * A few cadres are photographed again in a later year, with a DIFFERENT
     * file of the same gender: one row hits the exact year, and the other
     * replaces an existing photo. Both paths are otherwise never taken.
     *
     * @param list<array{file: string, kind: string, target: string, year: string, note: string}> $assigned
     * @return list<array{file: string, kind: string, target: string, year: string, note: string}>
     */
    private function rePhotographRows(array $assigned): array
    {
        $eligible = [];
        foreach ($assigned as $row) {
            $person = $this->people[$row['target']] ?? null;
            if ($person === null || $row['year'] !== UnitBlueprint::YEARS[0]) {
                continue;
            }
            $laterYear = $this->laterYearOf($person, $row['year']);
            if ($laterYear !== null) {
                $eligible[] = ['tiers' => $row['target'], 'year' => $laterYear, 'file' => $row['file']];
            }
        }

        $eligible = $this->rng->shuffle($eligible);
        $rows = [];

        foreach (array_slice($eligible, 0, PhotoLot::RE_PHOTOGRAPHED_COUNT) as $candidate) {
            $person = $this->people[$candidate['tiers']];
            $replacement = $this->anotherFileOfSameGender($assigned, $candidate['file'], $person->gender);
            if ($replacement === null) {
                continue;
            }
            $rows[] = [
                'file' => $replacement,
                'kind' => 'individual',
                'target' => $candidate['tiers'],
                'year' => $candidate['year'],
                'note' => 'rephotographie',
            ];
        }

        return $rows;
    }

    /**
     * Reuses a file already assigned to somebody else, on purpose: the lot is
     * finite, and two cadres sharing a portrait is a far smaller lie in a test
     * dataset than a cadre whose picture contradicts their record.
     *
     * @param list<array{file: string, kind: string, target: string, year: string, note: string}> $assigned
     */
    private function anotherFileOfSameGender(array $assigned, string $exclude, string $gender): ?string
    {
        $candidates = [];
        foreach ($assigned as $row) {
            if ($row['file'] === $exclude || $row['kind'] !== 'individual') {
                continue;
            }
            if ((PhotoLot::INDIVIDUAL_GENDERS[$row['file']] ?? '') === $gender) {
                $candidates[] = $row['file'];
            }
        }

        return $candidates === [] ? null : $this->rng->pick($candidates);
    }

    /**
     * Every cadre of the dataset: the Tiers, the first year they hold a
     * chief/admin-shaped function, and which section that was.
     *
     * @return list<array{tiers: string, year: string, section: ?string}>
     */
    private function cadres(): array
    {
        $cadres = [];

        foreach ($this->people as $tiers => $person) {
            foreach (UnitBlueprint::YEARS as $year) {
                $personYear = $person->years[$year] ?? null;
                if ($personYear === null) {
                    continue;
                }

                foreach ($personYear->functions as $function) {
                    $role = UnitBlueprint::FUNCTIONS[$function->functionCode] ?? 'identified';
                    if ($role !== 'chief' && $role !== 'admin') {
                        continue;
                    }

                    $cadres[] = [
                        'tiers' => $tiers,
                        'year' => $year,
                        'section' => $this->handleOf($function->section),
                    ];
                    continue 3;
                }
            }
        }

        return $cadres;
    }

    /**
     * @param list<array{tiers: string, year: string, section: ?string}> $cadres
     * @param array<string, true> $taken
     * @param list<string> $preferred
     * @return array{string, string, bool}|null
     */
    private function pickCadre(array $cadres, array $taken, string $gender, array $preferred): ?array
    {
        $fallback = null;

        foreach ($cadres as $cadre) {
            if (isset($taken[$cadre['tiers']]) || $this->people[$cadre['tiers']]->gender !== $gender) {
                continue;
            }

            // A unit chief has no section at all, which is exactly what the
            // `unite_` prefix is meant to land on.
            $section = $cadre['section'] ?? PhotoLot::UNIT_STAFF_HANDLE;
            if (in_array($section, $preferred, true)) {
                return [$cadre['tiers'], $cadre['year'], true];
            }

            $fallback ??= [$cadre['tiers'], $cadre['year'], false];
        }

        return $fallback;
    }

    private function handleOf(?string $sectionName): ?string
    {
        if ($sectionName === null) {
            return null;
        }

        foreach (UnitBlueprint::SECTIONS as $handle => $section) {
            if ($section['name'] === $sectionName) {
                return $handle;
            }
        }

        return null;
    }

    private function laterYearOf(Person $person, string $year): ?string
    {
        $index = (int) array_search($year, UnitBlueprint::YEARS, true);
        for ($i = $index + 1; $i < count(UnitBlueprint::YEARS); $i++) {
            if (isset($person->years[UnitBlueprint::YEARS[$i]])) {
                return UnitBlueprint::YEARS[$i];
            }
        }

        return null;
    }

    /**
     * The manifest, as a CSV a human can read in a diff and the builder can
     * parse without a dependency.
     *
     * @param list<array{file: string, kind: string, target: string, year: string, note: string}> $rows
     */
    public static function toCsv(array $rows): string
    {
        $lines = ['file;kind;target;year;note'];
        foreach ($rows as $row) {
            $lines[] = implode(';', [$row['file'], $row['kind'], $row['target'], $row['year'], $row['note']]);
        }

        return implode("\n", $lines) . "\n";
    }
}
