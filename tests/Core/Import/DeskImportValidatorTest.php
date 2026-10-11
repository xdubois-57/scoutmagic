<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\DeskCsvParser;
use Core\Import\DeskImportError;
use Core\Import\DeskImportRejectedException;
use Core\Import\DeskImportRule;
use Core\Import\DeskImportValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #854: a Desk export breaking any of the fourteen rules is refused as
 * a whole, and the refusal lists every violation. One refused case per rule,
 * the valid files that must still go through, and the message format the
 * administrator reads.
 *
 * Each case is a real file run through the real parser, built from the
 * committed sample export's own header and first line — so the cases stay
 * valid when the Desk format gains a column.
 */
class DeskImportValidatorTest extends TestCase
{
    /** Last day of the scout year these files are imported into (rule 13). */
    private const YEAR_END = '2026-08-31';

    /** « Today », fixed, for rule 1's « not in the future ». */
    private const TODAY = '2026-10-10';

    private string $fixturePath;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->fixturePath = dirname(__DIR__, 2) . '/fixtures/desk_export_sample.csv';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    // ── files that must go through ───────────────────────────────────

    public function testTheCommittedSampleExportIsValid(): void
    {
        $this->assertSame([], $this->messagesOf($this->validateFile($this->fixturePath)));
    }

    /**
     * A row a spreadsheet emptied but kept is a line of separators only. It
     * is as blank as an empty line: skipped, not refused as nine empty
     * columns.
     */
    public function testALineOfSeparatorsOnlyIsSkippedLikeABlankLine(): void
    {
        $empty = array_fill(0, count($this->header()), '');

        $errors = $this->validateLines([
            $this->line($this->row([])),
            $this->line($empty),
            $this->line([...$empty, '']),
            '  ' . $this->line($empty) . '  ',
        ]);

        $this->assertSame([], $this->messagesOf($errors));
    }

    /** Rule 7: the three values Desk writes all go through. */
    public function testEveryGenderDeskWritesIsAccepted(): void
    {
        $errors = $this->validateRows([
            $this->row(['Tiers' => 'T010', 'Genre' => 'F']),
            $this->row(['Tiers' => 'T011', 'Genre' => 'H']),
            $this->row(['Tiers' => 'T012', 'Genre' => 'X']),
        ]);

        $this->assertSame([], $this->messagesOf($errors));
    }

    public function testTheCommittedCommaExportIsValid(): void
    {
        $comma = dirname(__DIR__, 2) . '/fixtures/desk_export_comma.csv';

        $this->assertSame([], $this->messagesOf($this->validateFile($comma)));
    }

    /**
     * What the rules deliberately leave alone: an empty e-mail (16 members
     * of the reference dataset have none), empty function dates, booleans
     * in any case, an ancient Date début (a leader in post since 2020), and
     * the same date written in two of Desk's shapes on two lines.
     */
    public function testWhatTheRulesDeliberatelyAllow(): void
    {
        $errors = $this->validateRows([
            $this->row([
                'Email Tiers' => '', 'Date début' => '01/09/2020', 'Date fin' => '', 'fin de mandat' => '',
                'Fonction principale' => 'TRUE', 'Courrier fédération' => 'False', "Courrier d'unité" => 'tRuE',
            ]),
            $this->row([
                'Email Tiers' => '', 'Date de naissance' => '2012-03-15', 'Date début' => '01/09/2020',
                'Fonction principale' => 'TRUE', "Type d'adresse" => 'Adresse secondaire',
            ]),
        ]);

        $this->assertSame([], $this->messagesOf($errors));
    }

    // ── one refused case per rule ────────────────────────────────────

    /** @return array<string, array{array<string, string>, DeskImportRule, string}> */
    public static function oneBrokenCellProvider(): array
    {
        return [
            'rule 1 — empty birth date' => [
                ['Date de naissance' => ''],
                DeskImportRule::BIRTH_DATE,
                'Ligne 2 (T001) — Date de naissance vide : elle est obligatoire.',
            ],
            'rule 1 — a two-digit year, the issue\'s own example' => [
                ['Date de naissance' => '28/11/20'],
                DeskImportRule::BIRTH_DATE,
                'Ligne 2 (T001) — Date de naissance « 28/11/20 » illisible : format attendu JJ/MM/AAAA.',
            ],
            'rule 1 — an American date' => [
                ['Date de naissance' => '11/28/2020'],
                DeskImportRule::BIRTH_DATE,
                'Ligne 2 (T001) — Date de naissance « 11/28/2020 » illisible : format attendu JJ/MM/AAAA.',
            ],
            'rule 1 — a birth date in the future' => [
                ['Date de naissance' => '2099-01-01'],
                DeskImportRule::BIRTH_DATE,
                'Ligne 2 (T001) — Date de naissance « 2099-01-01 » invraisemblable : '
                    . 'elle doit être comprise entre le 01/01/1900 et aujourd\'hui.',
            ],
            'rule 1 — a birth date before 1900' => [
                ['Date de naissance' => '31/12/1899'],
                DeskImportRule::BIRTH_DATE,
                'Ligne 2 (T001) — Date de naissance « 31/12/1899 » invraisemblable : '
                    . 'elle doit être comprise entre le 01/01/1900 et aujourd\'hui.',
            ],
            'rule 4 — no Tiers' => [
                ['Tiers' => ''],
                DeskImportRule::TIERS,
                'Ligne 2 — Tiers vide : chaque ligne doit porter le code Tiers du membre.',
            ],
            'rule 6 — no Nom' => [
                ['Nom' => ''],
                DeskImportRule::NAME,
                'Ligne 2 (T001) — Nom vide : il est obligatoire.',
            ],
            'rule 6 — no Prenom' => [
                ['Prenom' => ''],
                DeskImportRule::NAME,
                'Ligne 2 (T001) — Prenom vide : il est obligatoire.',
            ],
            'rule 7 — a spreadsheet\'s M, which Desk never writes' => [
                ['Genre' => 'M'],
                DeskImportRule::GENDER,
                'Ligne 2 (T001) — Genre « M » : valeur attendue F, H ou X.',
            ],
            'rule 7 — a lower-case h' => [
                ['Genre' => 'h'],
                DeskImportRule::GENDER,
                'Ligne 2 (T001) — Genre « h » : valeur attendue F, H ou X.',
            ],
            'rule 7 — no gender' => [
                ['Genre' => ''],
                DeskImportRule::GENDER,
                'Ligne 2 (T001) — Genre vide : valeur attendue F, H ou X.',
            ],
            'rule 8 — a French spreadsheet\'s VRAI' => [
                ['Courrier fédération' => 'VRAI'],
                DeskImportRule::BOOLEAN,
                'Ligne 2 (T001) — Courrier fédération « VRAI » : valeur attendue true ou false.',
            ],
            'rule 8 — an empty consent' => [
                ["Courrier d'unité" => ''],
                DeskImportRule::BOOLEAN,
                "Ligne 2 (T001) — Courrier d'unité vide : valeur attendue true ou false.",
            ],
            'rule 9 — no FONCTION' => [
                ['FONCTION' => ''],
                DeskImportRule::FUNCTION_CODE,
                'Ligne 2 (T001) — FONCTION vide : elle est obligatoire.',
            ],
            'rule 10 — a malformed e-mail' => [
                ['Email Tiers' => 'jean.dupont@'],
                DeskImportRule::EMAIL,
                'Ligne 2 (T001) — Email Tiers « jean.dupont@ » : adresse e-mail invalide.',
            ],
            'rule 11 — an unreadable Date début' => [
                ['Date début' => '28/11/20'],
                DeskImportRule::FUNCTION_DATE,
                'Ligne 2 (T001) — Date début « 28/11/20 » illisible : format attendu JJ/MM/AAAA.',
            ],
            'rule 11 — an unreadable Date fin' => [
                ['Date fin' => '11/28/2020'],
                DeskImportRule::FUNCTION_DATE,
                'Ligne 2 (T001) — Date fin « 11/28/2020 » illisible : format attendu JJ/MM/AAAA.',
            ],
            'rule 11 — an unreadable fin de mandat' => [
                ['fin de mandat' => '31/02/2027'],
                DeskImportRule::FUNCTION_DATE,
                'Ligne 2 (T001) — fin de mandat « 31/02/2027 » illisible : format attendu JJ/MM/AAAA.',
            ],
            'rule 12 — a Section without its Branche' => [
                ['Branche' => ''],
                DeskImportRule::SECTION_WITHOUT_BRANCH,
                'Ligne 2 (T001) — Section « SV025L1 » sans Branche : une section doit être accompagnée de sa branche.',
            ],
            'rule 13 — a function starting after the scout year' => [
                ['Date début' => '01/09/2026'],
                DeskImportRule::FUNCTION_AFTER_YEAR,
                'Ligne 2 (T001) — Date début « 01/09/2026 » postérieure à la fin de l\'année scoute visée '
                    . '(31/08/2026) : le fichier vise-t-il la bonne année ?',
            ],
        ];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('oneBrokenCellProvider')]
    public function testOneBrokenCellRefusesTheFile(array $overrides, DeskImportRule $rule, string $message): void
    {
        $errors = $this->validateRows([$this->row($overrides)]);

        $this->assertSame([$message], $this->messagesOf($errors));
        $this->assertSame($rule, $errors[0]->rule);
        $this->assertSame(2, $errors[0]->lineNumber);
    }

    /**
     * Rule 3. A line cut short (a quote a spreadsheet failed to close) used
     * to see its last columns become empty; a line with a cell too many had
     * every column after the extra one shifted. Nothing else is read from
     * such a line — its « Tiers » may be a name — so the message names no
     * Tiers and the line raises nothing but this.
     */
    public function testRule3ALineShorterOrLongerThanTheHeaderIsRefused(): void
    {
        $exact = array_slice($this->row([]), 0, count($this->header()));
        $short = array_slice($exact, 0, 50);
        $filledExtra = [...$exact, 'en trop'];
        $twoExtra = [...$exact, '', ''];

        $errors = $this->validateLines([$this->line($short), $this->line($filledExtra), $this->line($twoExtra)]);

        $this->assertSame([
            'Ligne 2 — 50 champs au lieu des 56 de la ligne d\'en-têtes : '
                . 'la ligne est coupée ou décalée (un guillemet mal fermé, souvent).',
            'Ligne 3 — 57 champs au lieu des 56 de la ligne d\'en-têtes : '
                . 'la ligne est coupée ou décalée (un guillemet mal fermé, souvent).',
            'Ligne 4 — 58 champs au lieu des 56 de la ligne d\'en-têtes : '
                . 'la ligne est coupée ou décalée (un guillemet mal fermé, souvent).',
        ], $this->messagesOf($errors));
        $this->assertSame(DeskImportRule::FIELD_COUNT, $errors[0]->rule);
    }

    /**
     * Rule 3's one tolerance: a real Desk export ends every line with a
     * « ; », which reads as one empty cell past the header's last column.
     * Such a line, and one without the trailing « ; », both go through.
     */
    public function testRule3OneEmptyCellPastTheHeaderIsDesksTrailingSeparator(): void
    {
        $exact = array_slice($this->row([]), 0, count($this->header()));

        $errors = $this->validateLines([
            $this->line([...$exact, '']),
            $this->line([...array_slice($exact, 0, -1), ...[$exact[count($exact) - 1]]]),
        ]);

        $this->assertSame([], $this->messagesOf($errors));
    }

    /**
     * Rule 5. Identity is read from a Tiers' first line only, so a
     * correction made on one of its lines was lost or won depending on line
     * order. Neither value is quoted — two of the five columns are the
     * member's name.
     *
     * @return array<string, array{string, string}>
     */
    public static function identityColumnProvider(): array
    {
        return [
            'Nom' => ['Nom', 'Dupond'],
            'Prenom' => ['Prenom', 'Jeanne'],
            'Genre' => ['Genre', 'F'],
            'Date de naissance' => ['Date de naissance', '16/03/2012'],
            'Email Tiers' => ['Email Tiers', 'autre@example.com'],
        ];
    }

    #[DataProvider('identityColumnProvider')]
    public function testRule5TheLinesOfOneTiersMustAgree(string $column, string $otherValue): void
    {
        $errors = $this->validateRows([
            $this->row([]),
            $this->row([$column => $otherValue, "Type d'adresse" => 'Adresse secondaire']),
        ]);

        $this->assertSame([
            "Ligne 3 (T001) — {$column} : diffère de la ligne 2 du même Tiers ; "
                . 'toutes les lignes d\'un membre doivent porter la même valeur.',
        ], $this->messagesOf($errors));
        $this->assertSame(DeskImportRule::CONSISTENT_IDENTITY, $errors[0]->rule);
        $this->assertStringNotContainsString($otherValue, $errors[0]->message);
    }

    public function testRule5AnEmailDifferingOnlyInCaseIsTheSameEmail(): void
    {
        $errors = $this->validateRows([
            $this->row([]),
            $this->row(['Email Tiers' => 'Jean.Dupont@Example.com', "Type d'adresse" => 'Adresse secondaire']),
        ]);

        $this->assertSame([], $this->messagesOf($errors));
    }

    public function testRule14AMemberWithNoMainFunctionIsRefused(): void
    {
        $errors = $this->validateRows([$this->row(['Fonction principale' => 'false'])]);

        $this->assertSame([
            'Ligne 2 (T001) — Fonction principale : aucune fonction n\'est marquée principale ; '
                . 'un membre doit en avoir exactement une.',
        ], $this->messagesOf($errors));
        $this->assertSame(DeskImportRule::MAIN_FUNCTION, $errors[0]->rule);
    }

    public function testRule14AMemberWithTwoMainFunctionsIsRefused(): void
    {
        $errors = $this->validateRows([
            $this->row([]),
            $this->row(['Branche' => 'Baladins', 'Section' => 'SV025B1']),
        ]);

        $this->assertSame([
            'Ligne 2 (T001) — Fonction principale : 2 fonctions différentes sont marquées principales ; '
                . 'un membre doit en avoir exactement une.',
        ], $this->messagesOf($errors));
    }

    /**
     * Desk writes one line per (function × address): one main function on
     * two lines because the member has two addresses is still one.
     */
    public function testRule14OneMainFunctionRepeatedOnTwoAddressLinesIsOne(): void
    {
        $errors = $this->validateRows([
            $this->row([]),
            $this->row(["Type d'adresse" => 'Adresse secondaire', 'Rue' => 'Avenue du Parc']),
        ]);

        $this->assertSame([], $this->messagesOf($errors));
    }

    /**
     * The same function written in two of Desk's date shapes on its two
     * address lines is still one function, not two main ones.
     */
    public function testRule14TheSameFunctionDatedInTwoShapesIsOne(): void
    {
        $errors = $this->validateRows([
            $this->row(['Date début' => '01/09/2025']),
            $this->row([
                "Type d'adresse" => 'Adresse secondaire', 'Rue' => 'Avenue du Parc', 'Date début' => '2025-09-01',
            ]),
        ]);

        $this->assertSame([], $this->messagesOf($errors));
    }

    /**
     * One function marked main on one address line and not on the other:
     * DeskImportService, whose key includes the flag, would store it twice.
     * The line that contradicts the first one is the one named.
     */
    public function testRule14TheLinesOfOneFunctionMustAgreeOnTheMainFlag(): void
    {
        $errors = $this->validateRows([
            $this->row([]),
            $this->row([
                "Type d'adresse" => 'Adresse secondaire', 'Rue' => 'Avenue du Parc', 'Fonction principale' => 'false',
            ]),
        ]);

        $this->assertSame([
            'Ligne 3 (T001) — Fonction principale : la même fonction est marquée principale sur une ligne '
                . 'et pas sur une autre ; toutes ses lignes doivent porter la même valeur.',
        ], $this->messagesOf($errors));
        $this->assertSame(DeskImportRule::MAIN_FUNCTION, $errors[0]->rule);
    }

    /** A refused « Fonction principale » is reported once, by rule 8, not again as « no main function ». */
    public function testRule14IsNotRepeatedForAFlagRule8AlreadyRefused(): void
    {
        $errors = $this->validateRows([$this->row(['Fonction principale' => 'oui'])]);

        $this->assertSame(
            ['Ligne 2 (T001) — Fonction principale « oui » : valeur attendue true ou false.'],
            $this->messagesOf($errors)
        );
    }

    // ── the refusal as a whole ───────────────────────────────────────

    /**
     * The issue's « Terminé quand »: the refusal lists every faulty line,
     * not the first one — sorted by line, then by rule, so the
     * administrator can walk the file top to bottom.
     */
    public function testEveryErrorOfEveryLineIsListedInLineOrder(): void
    {
        $errors = $this->validateRows([
            $this->row(['Tiers' => 'T010', 'Date de naissance' => '28/11/20', 'Genre' => 'M']),
            $this->row(['Tiers' => 'T011']),
            $this->row(['Tiers' => 'T012', 'Email Tiers' => 'pas-un-email', 'Courrier fédération' => 'VRAI']),
            $this->row(['Tiers' => 'T013', 'Date de naissance' => '']),
        ]);

        $this->assertSame([
            [2, DeskImportRule::BIRTH_DATE],
            [2, DeskImportRule::GENDER],
            [4, DeskImportRule::BOOLEAN],
            [4, DeskImportRule::EMAIL],
            [5, DeskImportRule::BIRTH_DATE],
        ], array_map(static fn(DeskImportError $e): array => [$e->lineNumber, $e->rule], $errors));
    }

    /** A refusal names a line, a Tiers, a column, a cell — never the member. */
    public function testNoMessageNamesTheMember(): void
    {
        $errors = $this->validateRows([
            $this->row(['Date de naissance' => '28/11/20', 'Genre' => 'M', 'Email Tiers' => 'x@']),
            $this->row(['Nom' => 'Durand', 'Prenom' => 'Paul', "Type d'adresse" => 'Adresse secondaire']),
        ]);

        $this->assertNotEmpty($errors);
        foreach ($errors as $error) {
            $this->assertStringNotContainsString('Dupont', $error->message);
            $this->assertStringNotContainsString('Jean', $error->message);
            $this->assertStringNotContainsString('Durand', $error->message);
            $this->assertStringNotContainsString('Paul', $error->message);
        }
    }

    public function testTheExceptionSaysHowManyErrorsAndListsThem(): void
    {
        $errors = $this->validateRows([
            $this->row(['Tiers' => 'T010', 'Date de naissance' => '']),
            $this->row(['Tiers' => 'T011', 'Genre' => 'M']),
        ]);
        $exception = new DeskImportRejectedException($errors);

        $this->assertSame(
            'Import refusé : le fichier contient 2 erreurs, et rien n\'a été importé. '
                . 'Corrigez-les dans Desk, exportez à nouveau et déposez le nouveau fichier.',
            $exception->summary()
        );
        $this->assertSame($this->messagesOf($errors), $exception->listedMessages());
        $this->assertSame(
            implode("\n", [$exception->summary(), ...$this->messagesOf($errors)]),
            $exception->getMessage()
        );
        $this->assertSame(['birth_date' => 1, 'gender' => 1], $exception->countsByRule());
    }

    /**
     * A whole column reformatted by a spreadsheet is one error per line. The
     * page lists MAX_LISTED_ERRORS of them and sums up the rest; the summary
     * and the journal count every one.
     */
    public function testAVeryLongListIsCappedAndTheRestCounted(): void
    {
        $total = DeskImportRejectedException::MAX_LISTED_ERRORS + 50;
        $errors = [];
        for ($i = 0; $i < $total; $i++) {
            $errors[] = new DeskImportError($i + 2, DeskImportRule::BIRTH_DATE, "Ligne {$i}");
        }
        $exception = new DeskImportRejectedException($errors);

        $listed = $exception->listedMessages();
        $this->assertCount(DeskImportRejectedException::MAX_LISTED_ERRORS + 1, $listed);
        $this->assertSame('… et 50 autres erreurs.', $listed[array_key_last($listed)]);
        $this->assertStringContainsString("contient {$total} erreurs", $exception->summary());
        $this->assertSame(['birth_date' => $total], $exception->countsByRule());
    }

    public function testASingleErrorIsWrittenInTheSingular(): void
    {
        $exception = new DeskImportRejectedException([
            new DeskImportError(2, DeskImportRule::GENDER, 'Ligne 2 (T001) — Genre « M » : valeur attendue F, H ou X.'),
        ]);

        $this->assertStringContainsString('contient 1 erreur, et', $exception->summary());
        $this->assertStringContainsString('Corrigez-la dans Desk', $exception->summary());
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * The sample export's first data line (T001, Jean Dupont, Louveteaux,
     * main function), with the named cells overridden.
     *
     * @param array<string, string> $overrides
     * @return list<string>
     */
    private function row(array $overrides): array
    {
        $header = $this->header();
        $lines = file($this->fixturePath, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $cells = str_getcsv($lines[1], ';', '"', '');

        foreach ($overrides as $column => $value) {
            $index = array_search($column, $header, true);
            self::assertIsInt($index, "unknown CSV column '{$column}'");
            $cells[$index] = $value;
        }

        return array_map('strval', $cells);
    }

    /** @return list<string> */
    private function header(): array
    {
        $lines = file($this->fixturePath, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return array_map('strval', str_getcsv($lines[0], ';', '"', ''));
    }

    /** @param list<string> $cells */
    private function line(array $cells): string
    {
        return implode(';', $cells);
    }

    /**
     * @param list<list<string>> $rows
     * @return list<DeskImportError>
     */
    private function validateRows(array $rows): array
    {
        return $this->validateLines(array_map(fn(array $row): string => $this->line($row), $rows));
    }

    /**
     * @param list<string> $lines data lines, written after the sample's header
     * @return list<DeskImportError>
     */
    private function validateLines(array $lines): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'desk');
        $this->tempFiles[] = $path;
        file_put_contents($path, implode("\n", [$this->line($this->header()), ...$lines]) . "\n");

        return $this->validateFile($path);
    }

    /** @return list<DeskImportError> */
    private function validateFile(string $path): array
    {
        return (new DeskImportValidator())->validate(
            (new DeskCsvParser())->parse($path),
            new \DateTimeImmutable(self::YEAR_END),
            new \DateTimeImmutable(self::TODAY)
        );
    }

    /**
     * @param list<DeskImportError> $errors
     * @return list<string>
     */
    private function messagesOf(array $errors): array
    {
        return array_map(static fn(DeskImportError $error): string => $error->message, $errors);
    }
}
