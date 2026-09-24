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
        self::assertFileExists($path, "The Desk export for {$year} is missing from the repository.");

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

        self::assertGreaterThanOrEqual(170, count($parsed->members), "The unit in {$year} is too small to be credible.");
        self::assertLessThanOrEqual(190, count($parsed->members), "The unit in {$year} is too large to be credible.");
        self::assertGreaterThanOrEqual(250, $parsed->lineCount, "The {$year} export holds too few lines.");
        self::assertLessThanOrEqual(300, $parsed->lineCount, "The {$year} export holds too many lines.");
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
                "Tiers {$tiers}, pinned by scenario "
                . ScenarioCatalog::scenarioOf($tiers) . ", appears in no export.",
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
                "Tiers {$tiers} sits in the range reserved for scenarios without being declared in ScenarioCatalog.",
            );
        }
    }

    public function testTheCommittedFilesAreWhatTheGeneratorProduces(): void
    {
        $differences = (new DatasetGenerator(self::datasetRoot()))->differences();

        self::assertSame(
            [],
            $differences,
            "The committed files no longer match the generator.\n"
            . implode("\n", $differences)
            . "\nRe-run « php tests/fixtures/reference-dataset/generate.php » and commit the result.",
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
            'some photos of the batch are referenced by no member and no section',
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
                "The portrait {$file} has no declared gender: the member's Genre cannot be made consistent with it.",
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
                "The manifest references {$row['file']}, which is not in the batch.",
            );

            if ($row['kind'] === 'group') {
                self::assertContains($row['target'], $sections, "Unknown section in the manifest: {$row['target']}.");
                continue;
            }

            self::assertArrayHasKey($row['target'], $people, "Unknown Tiers in the manifest: {$row['target']}.");
            self::assertArrayHasKey(
                $row['year'],
                $people[$row['target']]->years,
                "The manifest gives {$row['target']} a photo for {$row['year']}, a year in which that member does not exist.",
            );
            self::assertSame(
                PhotoLot::INDIVIDUAL_GENDERS[$row['file']],
                $people[$row['target']]->gender,
                "The Genre of {$row['target']} contradicts the photo {$row['file']} assigned to them.",
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
                "Tiers {$row['target']} carries an individual photo without ever being a « cadre ».",
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
                    "« " . UnitBlueprint::SECTION_LEAD_FUNCTION . " » must be held by exactly one person "
                    . "in {$name} in {$year}.",
                );
            }

            self::assertSame(
                count(UnitBlueprint::sectionsIn($year)),
                count($holders),
                "A section that does not exist in {$year} carries a leader.",
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

                self::assertGreaterThan(20, count($days), "The rhythm of {$branch} in {$year} is too thin.");
                self::assertLessThan(40, count($days), "The rhythm of {$branch} in {$year} has no gaps left.");

                foreach ($days as $day) {
                    self::assertSame(
                        $rule['weekday'],
                        (int) $start->modify('+' . $day . ' days')->format('N'),
                        "A meeting of {$branch} in {$year} does not fall on the right day.",
                    );
                    self::assertFalse(
                        CalendarSeeder::isSchoolHoliday($day),
                        "A meeting of {$branch} in {$year} falls in the middle of the school holidays.",
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
        self::assertFileExists($path, "The {$year}/{$account} statement is missing from the repository.");

        $parser = new BnpParser();

        // The IBAN carried on every row must be the account's own:
        // ImportService::verifyIban() compares its blind index to the
        // account's and refuses the whole file otherwise.
        self::assertSame(
            BankBlueprint::compactIban(BankBlueprint::ACCOUNTS[$account]['iban']),
            $parser->extractSourceIban($path),
            "The {$year}/{$account} statement does not carry its own account's IBAN.",
        );

        $lines = $parser->parse($path);
        self::assertNotEmpty($lines, "The {$year}/{$account} statement holds no usable line.");

        foreach ($lines as $line) {
            self::assertNotSame('', $line->bankReference, 'a line with no REFERENCE BANQUE cannot be deduplicated');
            self::assertNotSame('', $line->label, 'a line with no label shows a treasurer nothing');
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
            self::assertGreaterThanOrEqual($first, $line->transactionDate, 'a line earlier than the first fiscal year of the dataset');
            self::assertLessThanOrEqual($last, $line->transactionDate, 'a line later than the last fiscal year of the dataset');
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
                "Reference {$line->bankReference} appears twice in the same statement.",
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
                        "The {$year}/{$account} statement no longer overlaps the previous one: deduplication is no longer exercised.",
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

        self::assertContains(1284.50, $amounts, 'the amount with a thousands separator (1.284,50) is gone, or is misread');
        self::assertContains(-35.98, $amounts, 'the amount with a dotted decimal (-35.98) is gone, or is read as -3598');
        self::assertNotContains(-3598.0, $amounts, 'the dotted decimal was read as a thousands separator');
    }

    public function testTheRefusedLineNeverReachesTheImport(): void
    {
        // Statut != "Accepté" means the transaction never happened on the
        // account. It must be in the file and absent from the parse.
        $path = self::datasetRoot() . '/' . BankBlueprint::fileFor(UnitBlueprint::YEARS[0], 'unite');

        self::assertStringContainsString('Refusé', (string) file_get_contents($path), 'the statement no longer holds a rejected line');

        foreach ((new BnpParser())->parse($path) as $line) {
            self::assertNotSame(-142.60, $line->amount, 'the rejected line was imported');
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

        self::assertNotNull($out, 'the debit of the internal transfer is gone');
        self::assertNotNull($in, 'the credit of the internal transfer is gone');
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
            self::assertNotEmpty($expected, "No fee is declared for {$year}.");

            $labels = array_map(
                static fn (object $line): string => $line->label,
                (new BnpParser())->parse(self::datasetRoot() . '/' . BankBlueprint::fileFor($year, 'unite')),
            );

            foreach ($expected as $communication) {
                self::assertContains($communication, $labels, "Fee {$communication} does not appear on the {$year} statement.");
                self::assertSame(
                    $communication,
                    StructuredCommunicationService::format(substr(preg_replace('/\D/', '', $communication) ?? '', 0, 10)),
                    'the structured communication no longer passes the mod-97 check',
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
