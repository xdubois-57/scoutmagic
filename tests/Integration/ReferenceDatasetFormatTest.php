<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Import\DeskCsvParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ReferenceDataset\DatasetGenerator;
use Tests\Fixtures\ReferenceDataset\PhotoLot;
use Tests\Fixtures\ReferenceDataset\ScenarioCatalog;
use Tests\Fixtures\ReferenceDataset\UnitBlueprint;

/**
 * The reference dataset still matches the parser, and the committed files
 * still match the generator.
 *
 * The fast, dumb half of the dataset's protection (chantier §4.2). It answers
 * two questions and nothing else:
 *
 *   - does every committed export still go through the REAL
 *     Core\Import\DeskCsvParser? A change to EXPECTED_HEADERS, to the
 *     delimiter detection or to the boolean parsing breaks CI on the pull
 *     request that introduces it, instead of on the day somebody tries to
 *     build an instance;
 *   - are the committed files still what the generator produces? A generator
 *     edited without re-running it is a divergence, not a difference of
 *     opinion — same mechanism, and the same reason, as
 *     `js-typecheck-baseline.json`.
 *
 * What this test deliberately does NOT do is assert on meaning: whether the
 * branch passages actually happened, whether Staff d'U ends up populated,
 * whether the emptied section went inactive. That needs a database and the
 * real import pipeline, and it lives in the end-to-end import test.
 *
 * @see tests/fixtures/reference-dataset/README.md
 */
final class ReferenceDatasetFormatTest extends TestCase
{
    private static function datasetRoot(): string
    {
        return dirname(__DIR__) . '/fixtures/reference-dataset';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scoutYears(): array
    {
        $cases = [];
        foreach (UnitBlueprint::YEARS as $year) {
            $cases[$year] = [$year];
        }

        return $cases;
    }

    #[DataProvider('scoutYears')]
    public function testEachExportIsAcceptedByTheRealParser(string $year): void
    {
        $path = self::datasetRoot() . '/' . DatasetGenerator::DESK_DIRECTORY . '/' . $year . '.csv';
        self::assertFileExists($path, "L'export Desk de {$year} est absent du dépôt.");

        // DeskImportService::import() unlinks the file it is handed, and so
        // would anything else built on the same habit. Parse a copy, never the
        // committed fixture — this is the same precaution
        // Tests\Core\Import\DeskImportServiceTest takes, for the same reason.
        $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset');
        copy($path, $copy);

        try {
            $parsed = (new DeskCsvParser())->parse($copy);
        } finally {
            @unlink($copy);
        }

        self::assertGreaterThanOrEqual(170, count($parsed->members), "L'unité de {$year} est trop petite pour être crédible.");
        self::assertLessThanOrEqual(190, count($parsed->members), "L'unité de {$year} est trop grande pour être crédible.");
        self::assertGreaterThanOrEqual(250, $parsed->lineCount, "L'export de {$year} compte trop peu de lignes.");
        self::assertLessThanOrEqual(300, $parsed->lineCount, "L'export de {$year} compte trop de lignes.");
    }

    #[DataProvider('scoutYears')]
    public function testEveryPinnedTiersOfAScenarioExistsSomewhere(string $year): void
    {
        $present = $this->tiersPresentIn($year);
        $everywhere = [];
        foreach (UnitBlueprint::YEARS as $anyYear) {
            $everywhere += $this->tiersPresentIn($anyYear);
        }

        foreach (ScenarioCatalog::pinnedTiers() as $tiers) {
            self::assertArrayHasKey(
                $tiers,
                $everywhere,
                "Le Tiers {$tiers}, épinglé par le scénario "
                . ScenarioCatalog::scenarioOf($tiers) . ", n'apparaît dans aucun export.",
            );
        }

        // Nothing in the dataset may use a Tiers reserved for the scenarios
        // without the catalogue knowing about it: an unclaimed T00xx would be
        // a member somebody meant to pin and forgot to.
        foreach (array_keys($present) as $tiers) {
            $serial = (int) substr((string) $tiers, 1);
            if ($serial >= ScenarioCatalog::FILLER_FIRST_ID) {
                continue;
            }
            self::assertNotNull(
                ScenarioCatalog::scenarioOf((string) $tiers),
                "Le Tiers {$tiers} occupe la plage réservée aux scénarios sans être déclaré dans ScenarioCatalog.",
            );
        }
    }

    public function testTheCommittedFilesAreWhatTheGeneratorProduces(): void
    {
        $differences = (new DatasetGenerator(self::datasetRoot()))->differences();

        self::assertSame(
            [],
            $differences,
            "Les fichiers commités ne correspondent plus au générateur.\n"
            . implode("\n", $differences)
            . "\nRelancez « php tests/fixtures/reference-dataset/generate.php » et committez le résultat.",
        );
    }

    public function testEveryPhotoOfTheLotIsClaimedBySomebody(): void
    {
        // The asymmetry is deliberate: an orphan photo is an error, a cadre
        // with no photo is not. The lot must never quietly grow a file nobody
        // uses; a staff must never shrink to match the lot.
        self::assertSame(
            [],
            (new DatasetGenerator(self::datasetRoot()))->orphanPhotos(),
            'Des photos du lot ne sont référencées par aucun Tiers ni aucune section.',
        );
    }

    public function testEveryIndividualPhotoHasADeclaredGender(): void
    {
        foreach (PhotoLot::allFiles(self::datasetRoot()) as $file) {
            if (!str_contains($file, '_individual_')) {
                continue;
            }
            self::assertArrayHasKey(
                $file,
                PhotoLot::INDIVIDUAL_GENDERS,
                "Le portrait {$file} n'a pas de genre déclaré : le Genre du membre ne peut pas être rendu cohérent avec lui.",
            );
        }
    }

    public function testEveryPhotoAssignmentPointsAtSomethingThatExists(): void
    {
        $generator = new DatasetGenerator(self::datasetRoot());
        $people = $generator->people();
        $sections = array_keys(UnitBlueprint::SECTIONS);
        $sections[] = PhotoLot::UNIT_STAFF_HANDLE;

        foreach ($generator->photoRows() as $row) {
            self::assertFileExists(
                self::datasetRoot() . '/' . PhotoLot::DIRECTORY . '/' . $row['file'],
                "Le manifeste référence {$row['file']}, qui n'est pas dans le lot.",
            );

            if ($row['kind'] === 'group') {
                self::assertContains($row['target'], $sections, "Section inconnue dans le manifeste : {$row['target']}.");
                continue;
            }

            self::assertArrayHasKey($row['target'], $people, "Tiers inconnu dans le manifeste : {$row['target']}.");
            self::assertArrayHasKey(
                $row['year'],
                $people[$row['target']]->years,
                "Le manifeste attribue une photo à {$row['target']} pour {$row['year']}, année où ce membre n'existe pas.",
            );
            self::assertSame(
                PhotoLot::INDIVIDUAL_GENDERS[$row['file']],
                $people[$row['target']]->gender,
                "Le Genre de {$row['target']} contredit la photo {$row['file']} qui lui est attribuée.",
            );
        }
    }

    public function testAnIndividualPhotoOnlyEverBelongsToACadre(): void
    {
        // The trombinoscope renders chief/admin functions and nothing else
        // (SectionService::getSectionStaff()), so a portrait on an animé would
        // be a row no page ever shows.
        $generator = new DatasetGenerator(self::datasetRoot());
        $people = $generator->people();

        foreach ($generator->photoRows() as $row) {
            if ($row['kind'] !== 'individual') {
                continue;
            }

            $roles = [];
            foreach ($people[$row['target']]->years as $personYear) {
                foreach ($personYear->functions as $function) {
                    $roles[] = UnitBlueprint::FUNCTIONS[$function->functionCode] ?? 'identified';
                }
            }

            self::assertNotEmpty(
                array_intersect($roles, ['chief', 'admin']),
                "Le Tiers {$row['target']} porte une photo individuelle sans jamais être cadre.",
            );
        }
    }

    /**
     * @return array<string, true>
     */
    private function tiersPresentIn(string $year): array
    {
        $path = self::datasetRoot() . '/' . DatasetGenerator::DESK_DIRECTORY . '/' . $year . '.csv';
        $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset');
        copy($path, $copy);

        try {
            $parsed = (new DeskCsvParser())->parse($copy);
        } finally {
            @unlink($copy);
        }

        $present = [];
        foreach ($parsed->members as $member) {
            $present[$member->deskId] = true;
        }

        return $present;
    }
}
