<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Produces every generated file of the dataset, in memory.
 *
 * Deliberately writes nothing: `generate.php` writes, and the `--check` mode
 * and the test that invokes it compare. Keeping the production of the bytes
 * separate from the decision to put them on disk is what lets one code path
 * serve both.
 *
 * Generation is fully deterministic — one seeded Rng (UnitBlueprint::SEED),
 * consumed by exactly one sequence of calls — so two runs on two machines
 * produce identical bytes, which is the premise `--check` rests on.
 */
final class DatasetGenerator
{
    public const DESK_DIRECTORY = 'desk';
    public const PHOTO_MANIFEST = 'photos/assignments.csv';

    /** @var array<string, Person>|null */
    private ?array $people = null;

    /** @var list<array{file: string, kind: string, target: string, year: string, note: string}>|null */
    private ?array $photoRows = null;

    public function __construct(private readonly string $datasetRoot)
    {
    }

    /**
     * Every generated file, keyed by its path relative to the dataset root.
     *
     * @return array<string, string>
     */
    public function generate(): array
    {
        $people = $this->people();
        $writer = new DeskCsvWriter();

        $files = [];
        foreach (UnitBlueprint::YEARS as $year) {
            $files[self::DESK_DIRECTORY . '/' . $year . '.csv'] = $writer->write($people, $year);
        }
        $files[self::PHOTO_MANIFEST] = PhotoAssigner::toCsv($this->photoRows());

        return $files;
    }

    /**
     * What `--check` reports: one line per file that differs, plus every photo
     * of the lot that nothing references.
     *
     * An orphan photo is a divergence in its own right, not a warning: a file
     * added to `photos/` without regenerating would otherwise sit there
     * unused, looking like part of the dataset. The reverse — a cadre with no
     * photo — is a perfectly normal state of the site and fails nothing.
     *
     * @return list<string>
     */
    public function differences(): array
    {
        $differences = [];

        foreach ($this->generate() as $relativePath => $expected) {
            $absolute = $this->datasetRoot . '/' . $relativePath;
            if (!is_file($absolute)) {
                $differences[] = "{$relativePath} : absent du dépôt.";
                continue;
            }

            $actual = (string) file_get_contents($absolute);
            if ($actual !== $expected) {
                $differences[] = sprintf(
                    '%s : %d octets sur disque, %d attendus.',
                    $relativePath,
                    strlen($actual),
                    strlen($expected),
                );
            }
        }

        foreach ($this->orphanPhotos() as $file) {
            $differences[] = "photos/{$file} : aucun Tiers ni aucune section ne la référence.";
        }

        return $differences;
    }

    /** @return list<string> photos of the lot that no manifest row references */
    public function orphanPhotos(): array
    {
        $referenced = [];
        foreach ($this->photoRows() as $row) {
            $referenced[$row['file']] = true;
        }

        $orphans = [];
        foreach (PhotoLot::allFiles($this->datasetRoot) as $file) {
            if (!isset($referenced[$file])) {
                $orphans[] = $file;
            }
        }

        return $orphans;
    }

    /**
     * The counters printed after a run, in French — the same reporting
     * contract as the builder's.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $people = $this->people();
        $lines = ['', 'Effectifs par année :'];

        foreach (UnitBlueprint::YEARS as $year) {
            $members = 0;
            $animes = 0;
            $females = 0;
            foreach ($people as $person) {
                $personYear = $person->years[$year] ?? null;
                if ($personYear === null) {
                    continue;
                }
                $members++;
                $females += $person->gender === 'F' ? 1 : 0;
                foreach ($personYear->functions as $function) {
                    if ($function->functionCode === 'Animé') {
                        $animes++;
                        break;
                    }
                }
            }

            $lines[] = sprintf(
                '  %s : %3d membres, dont %3d animés et %3d cadres — %d %% de F',
                $year,
                $members,
                $animes,
                $members - $animes,
                $members > 0 ? (int) round($females / $members * 100) : 0,
            );
        }

        $individual = 0;
        $group = 0;
        foreach ($this->photoRows() as $row) {
            $row['kind'] === 'group' ? $group++ : $individual++;
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Photos : %d attributions individuelles, %d de groupe, %d orpheline(s).',
            $individual,
            $group,
            count($this->orphanPhotos()),
        );

        return $lines;
    }

    /** @return array<string, Person> */
    public function people(): array
    {
        if ($this->people === null) {
            $rng = new Rng(UnitBlueprint::SEED);
            $this->people = (new PopulationBuilder($rng, new PersonFactory($rng)))->build();
        }

        return $this->people;
    }

    /**
     * @return list<array{file: string, kind: string, target: string, year: string, note: string}>
     */
    public function photoRows(): array
    {
        if ($this->photoRows === null) {
            // A second Rng, seeded from the same constant but offset, so that
            // adding a photo to the lot cannot shift a single byte of the CSVs.
            // The two artefacts are regenerated together and must stay
            // independent of each other's size.
            $this->photoRows = (new PhotoAssigner(new Rng(UnitBlueprint::SEED + 1), $this->people()))
                ->assign($this->datasetRoot);
        }

        return $this->photoRows;
    }
}
