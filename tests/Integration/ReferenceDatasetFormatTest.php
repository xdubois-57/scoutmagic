<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Import\DeskCsvParser;
use Modules\Finance\Parser\BnpParser;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ReferenceDataset\BankBlueprint;
use Tests\Fixtures\ReferenceDataset\CalendarBlueprint;
use Tests\Fixtures\ReferenceDataset\CalendarSeeder;
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
 *     Core\Import\DeskCsvParser, and every committed statement through the
 *     REAL Modules\Finance\Parser\BnpParser? A change to EXPECTED_HEADERS, to
 *     the delimiter detection, to the boolean parsing or to the amount
 *     parsing breaks CI on the pull request that introduces it, instead of on
 *     the day somebody tries to build an instance;
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

    // ------------------------------------------------ responsables de section

    public function testExactlyOneCadrePerSectionAndYearCarriesTheLeadFunction(): void
    {
        // The trombinoscope's "responsable" is whoever holds a function
        // flagged `is_lead`. Flag a function two people hold and the
        // responsable becomes whichever row the query returns first; flag one
        // nobody holds and every section is headless. Both failures are
        // silent on the page, so they are caught here instead.
        $people = (new DatasetGenerator(self::datasetRoot()))->people();

        foreach (UnitBlueprint::YEARS as $year) {
            $holders = [];
            foreach ($people as $tiers => $person) {
                foreach (($person->years[$year] ?? null)?->functions ?? [] as $function) {
                    if ($function->functionCode === UnitBlueprint::SECTION_LEAD_FUNCTION) {
                        $holders[(string) $function->section][] = $tiers;
                    }
                }
            }

            foreach (UnitBlueprint::sectionsIn($year) as $handle) {
                $name = UnitBlueprint::SECTIONS[$handle]['name'];
                self::assertCount(
                    1,
                    $holders[$name] ?? [],
                    "« " . UnitBlueprint::SECTION_LEAD_FUNCTION . " » doit être porté par exactement une personne "
                    . "dans {$name} en {$year}.",
                );
            }

            self::assertSame(
                count(UnitBlueprint::sectionsIn($year)),
                count($holders),
                "Une section inexistante en {$year} porte un responsable.",
            );
        }
    }

    // ---------------------------------------------------- rythme hebdomadaire

    public function testTheWeeklyRuleProducesAScoutYearOfSaturdaysWithHolesInIt(): void
    {
        // The rule is the declarative half of the calendar, and this is the
        // arithmetic that turns it into dates: it runs without a database, so
        // a rhythm that quietly slid onto a Sunday, or a season that lost its
        // school holidays, fails here rather than on somebody's instance.
        foreach (UnitBlueprint::YEARS as $year) {
            $start = new \DateTimeImmutable(sprintf('%04d-09-01', UnitBlueprint::referenceYear($year)));

            foreach (CalendarBlueprint::MEETING_RULE as $branch => $rule) {
                $days = CalendarSeeder::occurrencesOf($year, $rule);

                self::assertGreaterThan(20, count($days), "Le rythme de {$branch} en {$year} est trop maigre.");
                self::assertLessThan(40, count($days), "Le rythme de {$branch} en {$year} n'a plus de trous.");

                foreach ($days as $day) {
                    self::assertSame(
                        $rule['weekday'],
                        (int) $start->modify('+' . $day . ' days')->format('N'),
                        "Une réunion de {$branch} en {$year} ne tombe pas le bon jour.",
                    );
                    self::assertFalse(
                        CalendarSeeder::isSchoolHoliday($day),
                        "Une réunion de {$branch} en {$year} tombe en pleines vacances scolaires.",
                    );
                }
            }
        }
    }

    // ---------------------------------------------------- relevés bancaires

    /**
     * @return array<string, array{string, string}>
     */
    public static function statements(): array
    {
        $cases = [];
        foreach (UnitBlueprint::YEARS as $year) {
            foreach (array_keys(BankBlueprint::ACCOUNTS) as $account) {
                $cases["{$year} {$account}"] = [$year, $account];
            }
        }

        return $cases;
    }

    #[DataProvider('statements')]
    public function testEachStatementIsAcceptedByTheRealBankParser(string $year, string $account): void
    {
        $path = self::datasetRoot() . '/' . BankBlueprint::fileFor($year, $account);
        self::assertFileExists($path, "Le relevé {$year}/{$account} est absent du dépôt.");

        $parser = new BnpParser();

        // The IBAN carried on every row must be the account's own:
        // ImportService::verifyIban() compares its blind index to the
        // account's and refuses the whole file otherwise.
        self::assertSame(
            BankBlueprint::compactIban(BankBlueprint::ACCOUNTS[$account]['iban']),
            $parser->extractSourceIban($path),
            "Le relevé {$year}/{$account} ne porte pas l'IBAN de son compte.",
        );

        $lines = $parser->parse($path);
        self::assertNotEmpty($lines, "Le relevé {$year}/{$account} ne contient aucune ligne exploitable.");

        foreach ($lines as $line) {
            self::assertNotSame('', $line->bankReference, 'Une ligne sans REFERENCE BANQUE ne peut pas être dédupliquée.');
            self::assertNotSame('', $line->label, 'Une ligne sans libellé ne montre rien à un trésorier.');
        }
    }

    #[DataProvider('statements')]
    public function testEveryStatementLineFallsInsideOneOfTheThreeExercises(string $year, string $account): void
    {
        // A finance exercise IS a scout year: FiscalYearRepository::
        // findForDate() resolves it straight out of scout_years, and
        // ImportService aborts the whole import on a date no exercise covers.
        $first = new \DateTimeImmutable(UnitBlueprint::referenceYear(UnitBlueprint::YEARS[0]) . '-09-01');
        $lastYear = UnitBlueprint::YEARS[count(UnitBlueprint::YEARS) - 1];
        $last = new \DateTimeImmutable((UnitBlueprint::referenceYear($lastYear) + 1) . '-08-31');

        foreach ((new BnpParser())->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor($year, $account)) as $line) {
            self::assertGreaterThanOrEqual($first, $line->transactionDate, 'Ligne antérieure au premier exercice du jeu de données.');
            self::assertLessThanOrEqual($last, $line->transactionDate, 'Ligne postérieure au dernier exercice du jeu de données.');
        }
    }

    #[DataProvider('statements')]
    public function testNoBankReferenceIsRepeatedInsideOneFile(string $year, string $account): void
    {
        // Within one file every reference must be unique, or deduplication
        // would silently drop a genuine second movement. Between two files it
        // is the opposite — see the overlap test below.
        $references = [];
        foreach ((new BnpParser())->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor($year, $account)) as $line) {
            self::assertArrayNotHasKey(
                $line->bankReference,
                $references,
                "La référence {$line->bankReference} apparaît deux fois dans le même relevé.",
            );
            $references[$line->bankReference] = true;
        }
    }

    public function testSuccessiveStatementsOverlapSoDeduplicationIsExercised(): void
    {
        $parser = new BnpParser();

        foreach (array_keys(BankBlueprint::ACCOUNTS) as $account) {
            $previous = [];
            foreach (UnitBlueprint::YEARS as $index => $year) {
                $current = array_map(
                    static fn (object $line): string => $line->bankReference,
                    $parser->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor($year, $account)),
                );

                if ($index > 0) {
                    self::assertCount(
                        BankBlueprint::OVERLAP_LINES,
                        array_intersect($previous, $current),
                        "Le relevé {$year}/{$account} ne recouvre plus le précédent : la déduplication n'est plus exercée.",
                    );
                }

                $previous = $current;
            }
        }
    }

    public function testTheTwoAwkwardAmountFormatsAreReadCorrectly(): void
    {
        // The two cases BnpParser::parseAmount() exists for. The dot-decimal
        // one is the regression that mattered: read as a thousands separator,
        // 35.98 imports as 3598,00 € with no error at all.
        $amounts = [];
        foreach ((new BnpParser())->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor(UnitBlueprint::YEARS[0], 'unite')) as $line) {
            $amounts[] = $line->amount;
        }

        self::assertContains(1284.50, $amounts, 'Le montant à séparateur de milliers (1.284,50) a disparu ou est mal lu.');
        self::assertContains(-35.98, $amounts, 'Le montant en décimale pointée (-35.98) a disparu ou est lu comme -3598.');
        self::assertNotContains(-3598.0, $amounts, 'La décimale pointée a été lue comme un séparateur de milliers.');
    }

    public function testTheRefusedLineNeverReachesTheImport(): void
    {
        // Statut != "Accepté" means the transaction never happened on the
        // account. It must be in the file and absent from the parse.
        $path = self::datasetRoot() . '/' . BankBlueprint::fileFor(UnitBlueprint::YEARS[0], 'unite');

        self::assertStringContainsString('Refusé', (string) file_get_contents($path), 'Le relevé ne contient plus de ligne refusée.');

        foreach ((new BnpParser())->parse($path) as $line) {
            self::assertNotSame(-142.60, $line->amount, 'La ligne refusée a été importée.');
        }
    }

    public function testTheInternalTransferHasBothSides(): void
    {
        // One debit on the unit account, one credit of the same amount on the
        // same day on the camp account, each naming the other's IBAN.
        $parser = new BnpParser();
        $year = UnitBlueprint::YEARS[0];

        $out = $this->findLineByAmount($parser->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor($year, 'unite')), -1500.00);
        $in = $this->findLineByAmount($parser->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor($year, 'camps')), 1500.00);

        self::assertNotNull($out, 'Le débit du virement interne a disparu.');
        self::assertNotNull($in, 'Le crédit du virement interne a disparu.');
        self::assertSame($out->transactionDate->format('Y-m-d'), $in->transactionDate->format('Y-m-d'));
        self::assertSame(BankBlueprint::compactIban(BankBlueprint::ACCOUNTS['camps']['iban']), $out->counterpartyAccount);
        self::assertSame(BankBlueprint::compactIban(BankBlueprint::ACCOUNTS['unite']['iban']), $in->counterpartyAccount);
    }

    public function testEveryMembershipPaymentCarriesAValidStructuredCommunication(): void
    {
        // The communications are declared in BankBlueprint and formatted by
        // the application's own StructuredCommunicationService::format(), so
        // the mod-97 check digits are right by construction. IT-06 creates the
        // matching expected receivables from the same list — if these two ever
        // drift apart, the "Paiements attendus" page reconciles nothing.
        foreach (UnitBlueprint::YEARS as $year) {
            $expected = BankBlueprint::communicationsFor($year);
            self::assertNotEmpty($expected, "Aucune cotisation déclarée pour {$year}.");

            $labels = array_map(
                static fn (object $line): string => $line->label,
                (new BnpParser())->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor($year, 'unite')),
            );

            foreach ($expected as $communication) {
                self::assertContains($communication, $labels, "La cotisation {$communication} n'apparaît pas sur le relevé de {$year}.");
                self::assertSame(
                    $communication,
                    StructuredCommunicationService::format(substr(preg_replace('/\D/', '', $communication) ?? '', 0, 10)),
                    'La communication structurée ne repasse pas le calcul mod-97.',
                );
            }
        }
    }

    /**
     * @param list<object> $lines
     */
    private function findLineByAmount(array $lines, float $amount): ?object
    {
        foreach ($lines as $line) {
            if (abs($line->amount - $amount) < 0.001) {
                return $line;
            }
        }

        return null;
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
