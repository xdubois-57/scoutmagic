<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Service\DateInput;
use Core\Service\DeskDateParser;

/**
 * Every rule a Desk export must satisfy before anything is written
 * (issue #854), checked in ONE pass that lists every violation.
 *
 * The import used to take each cell as it came: an empty or two-digit
 * birth date overwrote a good one and emptied Statistiques, a spreadsheet's
 * `VRAI` withdrew every mail consent, a cut line shifted its last columns
 * into nothing, a malformed e-mail created a login. Each of those was
 * silent. The answer chosen with the maintainer is the posture the header
 * validation and RosterReplacementGuard already have: refuse the WHOLE
 * file before the transaction opens, and say everything that is wrong at
 * once, so one correction round is enough.
 *
 * Pure: no database, no clock of its own — the scout year's end and
 * « today » are handed in, which is what lets every rule be tested on a
 * fixed date.
 *
 * Deliberately NOT checked, as decided in the issue: codes this site does
 * not know yet (function, branch, tariff, section — created and reported
 * in Correspondances Desk), and the free-text fields (totem, quali,
 * address, handicap, insurance).
 */
final class DeskImportValidator
{
    /**
     * What rule 7 accepts: the three values Desk writes — F, H (homme) and
     * X. A spreadsheet's M is refused rather than read as H, as decided
     * with the maintainer.
     */
    private const GENDERS = ['F', 'H', 'X'];

    /** @var list<string> rule 8 */
    private const BOOLEAN_COLUMNS = ['Fonction principale', 'Courrier fédération', "Courrier d'unité"];

    /** @var list<string> rule 11 */
    private const FUNCTION_DATE_COLUMNS = ['Date début', 'Date fin', 'fin de mandat'];

    /**
     * Rule 5: the columns every line of one Tiers must agree on. They are
     * the member's identity, read from the first line only
     * (DeskCsvParser::buildMember()), so a disagreement means a correction
     * made on one line was lost or won depending on line order.
     *
     * @var list<string>
     */
    private const IDENTITY_COLUMNS = ['Nom', 'Prenom', 'Genre', 'Date de naissance', 'Email Tiers'];

    /**
     * Columns whose cell is never quoted back in a message: the issue's
     * refusal format names no member, and these ARE the member's name.
     *
     * @var list<string>
     */
    private const NEVER_QUOTED = ['Nom', 'Prenom'];

    /**
     * How much of a cell a message quotes. A shifted line can put a whole
     * address in one cell; the sentence only needs enough to find it.
     */
    private const MAX_QUOTED_LENGTH = 40;

    /**
     * @param \DateTimeImmutable $scoutYearEnd last day of the scout year the file is imported into (rule 13)
     * @param \DateTimeImmutable $today what « in the future » means for a birth date (rule 1)
     * @return list<DeskImportError> sorted by line, then by rule; empty when the file may be imported
     */
    public function validate(
        ParsedImport $parsed,
        \DateTimeImmutable $scoutYearEnd,
        \DateTimeImmutable $today
    ): array {
        $errors = [];
        /** @var array<string, list<DeskCsvRow>> $byTiers */
        $byTiers = [];

        foreach ($parsed->rows as $row) {
            if (!$this->hasHeaderShape($row, $parsed->headerCount)) {
                // Rule 3. Nothing else is read from such a line: its cells
                // sit under the wrong headers, so its Tiers may be somebody's
                // name and its « birth date » a street number.
                $errors[] = new DeskImportError(
                    $row->lineNumber,
                    DeskImportRule::FIELD_COUNT,
                    sprintf(
                        'Ligne %d — %d champs au lieu des %d de la ligne d\'en-têtes : '
                            . 'la ligne est coupée ou décalée (un guillemet mal fermé, souvent).',
                        $row->lineNumber,
                        $row->fieldCount,
                        $parsed->headerCount
                    )
                );
                continue;
            }

            array_push($errors, ...$this->rowErrors($row, $scoutYearEnd, $today));

            $tiers = $row->value('Tiers');
            if ($tiers !== '') {
                $byTiers[$tiers][] = $row;
            }
        }

        foreach ($byTiers as $rows) {
            array_push($errors, ...$this->identityErrors($rows));
            array_push($errors, ...$this->mainFunctionErrors($rows));
        }

        usort(
            $errors,
            static fn(DeskImportError $a, DeskImportError $b): int
                => [$a->lineNumber, $a->rule->value] <=> [$b->lineNumber, $b->rule->value]
        );

        return $errors;
    }

    /**
     * Rule 3: as many cells as the header — or exactly one more when that
     * last one is empty, because a real Desk export ends every line with a
     * « ; ». Anything else means the line is cut or shifted.
     */
    private function hasHeaderShape(DeskCsvRow $row, int $headerCount): bool
    {
        return $row->fieldCount === $headerCount
            || ($row->fieldCount === $headerCount + 1 && $row->extraCellsEmpty);
    }

    /**
     * Rules 1, 4, 6–13: what one line can be judged on alone.
     *
     * @return list<DeskImportError>
     */
    private function rowErrors(DeskCsvRow $row, \DateTimeImmutable $scoutYearEnd, \DateTimeImmutable $today): array
    {
        $errors = [];
        $line = $row->lineNumber;
        $add = function (DeskImportRule $rule, string $text) use (&$errors, $row, $line): void {
            $errors[] = new DeskImportError($line, $rule, $this->prefix($row) . $text);
        };

        if ($row->value('Tiers') === '') {
            $add(DeskImportRule::TIERS, 'Tiers vide : chaque ligne doit porter le code Tiers du membre.');
        }

        foreach (['Nom', 'Prenom'] as $column) {
            if ($row->value($column) === '') {
                $add(DeskImportRule::NAME, "{$column} vide : il est obligatoire.");
            }
        }

        $gender = $row->value('Genre');
        if (!in_array($gender, self::GENDERS, true)) {
            $add(DeskImportRule::GENDER, $this->cell('Genre', $gender) . ' : valeur attendue F, H ou X.');
        }

        $birthError = $this->birthDateError($row->value('Date de naissance'), $today);
        if ($birthError !== null) {
            $add(DeskImportRule::BIRTH_DATE, $birthError);
        }

        $email = $row->value('Email Tiers');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $add(DeskImportRule::EMAIL, $this->cell('Email Tiers', $email) . ' : adresse e-mail invalide.');
        }

        foreach (self::BOOLEAN_COLUMNS as $column) {
            $value = $row->value($column);
            if (!in_array(strtolower($value), ['true', 'false'], true)) {
                $add(DeskImportRule::BOOLEAN, $this->cell($column, $value) . ' : valeur attendue true ou false.');
            }
        }

        if ($row->value('FONCTION') === '') {
            $add(DeskImportRule::FUNCTION_CODE, 'FONCTION vide : elle est obligatoire.');
        }

        foreach (self::FUNCTION_DATE_COLUMNS as $column) {
            $value = $row->value($column);
            if ($value === '') {
                continue;
            }
            $date = DeskDateParser::parse($value);
            if ($date === null) {
                $add(
                    DeskImportRule::FUNCTION_DATE,
                    $this->cell($column, $value) . ' illisible : format attendu JJ/MM/AAAA.'
                );
                continue;
            }
            if ($column === 'Date début' && $date->format('Y-m-d') > $scoutYearEnd->format('Y-m-d')) {
                $add(
                    DeskImportRule::FUNCTION_AFTER_YEAR,
                    $this->cell($column, $value) . ' postérieure à la fin de l\'année scoute visée ('
                        . $scoutYearEnd->format('d/m/Y') . ') : le fichier vise-t-il la bonne année ?'
                );
            }
        }

        $section = $row->value('Section');
        if ($section !== '' && $row->value('Branche') === '') {
            $add(
                DeskImportRule::SECTION_WITHOUT_BRANCH,
                $this->cell('Section', $section) . ' sans Branche : une section doit être accompagnée de sa branche.'
            );
        }

        return $errors;
    }

    private function birthDateError(string $value, \DateTimeImmutable $today): ?string
    {
        if ($value === '') {
            return 'Date de naissance vide : elle est obligatoire.';
        }

        $date = DeskDateParser::parse($value);
        if ($date === null) {
            return $this->cell('Date de naissance', $value) . ' illisible : format attendu JJ/MM/AAAA.';
        }

        if (!DeskDateParser::isPlausibleBirthDate($date, $today)) {
            $earliest = DateInput::requireFromStorage(DeskDateParser::EARLIEST_BIRTH_DATE, 'earliest birth date');

            return $this->cell('Date de naissance', $value) . ' invraisemblable : elle doit être comprise entre le '
                . $earliest->format('d/m/Y') . ' et aujourd\'hui.';
        }

        return null;
    }

    /**
     * Rule 5. Every later line of a Tiers is compared with its first one;
     * the message names the line it disagrees with and quotes neither
     * value, since two of the five columns are the member's name.
     *
     * @param list<DeskCsvRow> $rows the lines of one Tiers, in file order
     * @return list<DeskImportError>
     */
    private function identityErrors(array $rows): array
    {
        $errors = [];
        $first = $rows[0];

        foreach (array_slice($rows, 1) as $row) {
            foreach (self::IDENTITY_COLUMNS as $column) {
                $value = $this->comparable($column, $row->value($column));
                if ($value === $this->comparable($column, $first->value($column))) {
                    continue;
                }
                $errors[] = new DeskImportError(
                    $row->lineNumber,
                    DeskImportRule::CONSISTENT_IDENTITY,
                    $this->prefix($row) . sprintf(
                        '%s : diffère de la ligne %d du même Tiers ; toutes les lignes d\'un membre '
                            . 'doivent porter la même valeur.',
                        $column,
                        $first->lineNumber
                    )
                );
            }
        }

        return $errors;
    }

    /**
     * Two spellings of one fact are the same fact: `15/03/2012` and
     * `2012-03-15`, or an e-mail differing only in case (it is stored
     * lower-cased anyway).
     */
    private function comparable(string $column, string $value): string
    {
        return match ($column) {
            'Date de naissance' => DeskDateParser::toIso($value) ?? $value,
            'Email Tiers' => strtolower($value),
            default => $value,
        };
    }

    /**
     * One function's identity across its address lines: its code, branch,
     * section and dates, each date in one spelling (`01/09/2025` and
     * `2025-09-01` are the same start).
     */
    private function functionKey(DeskCsvRow $row): string
    {
        $date = static fn(string $value): string => DeskDateParser::toIso($value) ?? $value;

        return implode("\x1F", [
            $row->value('FONCTION'),
            $row->value('Branche'),
            $row->value('Section'),
            $date($row->value('Date début')),
            $date($row->value('Date fin')),
            $date($row->value('fin de mandat')),
        ]);
    }

    /**
     * Rule 14: exactly one main function per member.
     *
     * Counted on DISTINCT functions, not on lines: Desk writes one line per
     * (function × address), so a member with two addresses carries their
     * one main function on two lines, and that is still one. The key is the
     * function's own columns with its dates normalised — the tuple
     * DeskImportService deduplicates on, the main flag aside.
     *
     * The lines of one function must also agree on that flag: marked main
     * on one address line and not on the other, the service — whose key
     * includes the flag — would store it twice, one of each.
     *
     * Skipped for a Tiers whose « Fonction principale » rule 8 already
     * refused: the count would only repeat that error in other words.
     *
     * @param list<DeskCsvRow> $rows
     * @return list<DeskImportError>
     */
    private function mainFunctionErrors(array $rows): array
    {
        /** @var array<string, string> $flagOf the first flag seen for each distinct function */
        $flagOf = [];
        $mainFunctions = [];
        foreach ($rows as $row) {
            $flag = strtolower($row->value('Fonction principale'));
            if ($flag !== 'true' && $flag !== 'false') {
                return [];
            }
            $key = $this->functionKey($row);
            if (isset($flagOf[$key]) && $flagOf[$key] !== $flag) {
                return [new DeskImportError(
                    $row->lineNumber,
                    DeskImportRule::MAIN_FUNCTION,
                    $this->prefix($row) . 'Fonction principale : la même fonction est marquée principale '
                        . 'sur une ligne et pas sur une autre ; toutes ses lignes doivent porter la même valeur.'
                )];
            }
            $flagOf[$key] = $flag;
            if ($flag === 'true') {
                $mainFunctions[$key] = true;
            }
        }

        $count = count($mainFunctions);
        if ($count === 1) {
            return [];
        }

        $first = $rows[0];

        return [new DeskImportError(
            $first->lineNumber,
            DeskImportRule::MAIN_FUNCTION,
            $this->prefix($first) . ($count === 0
                ? 'Fonction principale : aucune fonction n\'est marquée principale'
                : sprintf('Fonction principale : %d fonctions différentes sont marquées principales', $count))
                . ' ; un membre doit en avoir exactement une.'
        )];
    }

    /** « Ligne 12 (T0280) — », or « Ligne 12 — » for a line without Tiers. */
    private function prefix(DeskCsvRow $row): string
    {
        $tiers = $row->value('Tiers');

        return $tiers === ''
            ? sprintf('Ligne %d — ', $row->lineNumber)
            : sprintf('Ligne %d (%s) — ', $row->lineNumber, $this->shorten($tiers));
    }

    /** « Genre « H » », or the column alone for an empty cell or a name. */
    private function cell(string $column, string $value): string
    {
        if ($value === '') {
            return "{$column} vide";
        }
        if (in_array($column, self::NEVER_QUOTED, true)) {
            return $column;
        }

        return "{$column} « {$this->shorten($value)} »";
    }

    private function shorten(string $value): string
    {
        return mb_strlen($value) > self::MAX_QUOTED_LENGTH
            ? mb_substr($value, 0, self::MAX_QUOTED_LENGTH) . '…'
            : $value;
    }
}
