<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Writes one scout year of people as a Desk export.
 *
 * The column list and its order are taken from
 * `tests/fixtures/desk_export_sample.csv`, which is what a real export looks
 * like: the 35 headers Core\Import\DeskCsvParser::EXPECTED_HEADERS requires,
 * then the 21 extra columns Desk appends and the parser tolerates.
 *
 * Two details are copied from the real thing rather than tidied up, and both
 * would break the import if "fixed":
 *
 *   - `Sizaine/Patrouillle` really is spelled with three Ls. It is the string
 *     validateHeaders() looks for.
 *   - `SECTION` (all-caps) is never read. It is filled here with a plausible
 *     but deliberately WRONG code, so that any code which started reading it
 *     would put members in visibly wrong sections instead of quietly working.
 *
 * One row per (function × address). Addresses are deduplicated by
 * `Type d'adresse` inside the parser; functions are not — so a member with two
 * addresses and one function produces two rows carrying the SAME function,
 * exactly as a real export does.
 */
final class DeskCsvWriter
{
    private const DELIMITER = ';';

    /**
     * The 35 columns the parser requires, in the order the real export emits
     * them. Kept as a literal rather than read from DeskCsvParser: this file
     * is a fixture of what Desk produces, and it must be able to disagree with
     * the parser loudly (that is the whole point of the format test) rather
     * than track it silently.
     *
     * @var list<string>
     */
    public const REQUIRED_HEADERS = [
        'Nom', 'Prenom', 'Genre', 'Date de naissance', 'Tél', 'GSM',
        'Email Tiers', 'Rue', 'No', 'Bte', 'cplt adr', 'Code Postal',
        'Ville', 'Pays', "Type d'adresse", 'Courrier fédération',
        "Courrier d'unité", 'Groupe unités', "Fonction au sein de l'unité",
        'FONCTION', 'Tiers', 'Branche', 'Section', 'SECTION',
        'Date début', 'Date fin', 'fin de mandat', 'Fonction principale',
        'Tarif', 'Totem', 'Quali', 'Sizaine/Patrouillle', 'Niveau formation',
        'Handicap', 'Assurance complémentaire',
    ];

    /** @return list<string> the required headers plus the tolerated extras */
    public static function headers(): array
    {
        $headers = self::REQUIRED_HEADERS;
        for ($i = 1; $i <= 20; $i++) {
            $headers[] = 'Champ libre ' . $i;
        }
        $headers[] = 'Zone 15';

        return $headers;
    }

    /**
     * @param array<string, Person> $people keyed by Tiers, in Tiers order
     */
    public function write(array $people, string $year): string
    {
        $lines = [implode(self::DELIMITER, self::headers())];

        foreach ($people as $person) {
            $personYear = $person->years[$year] ?? null;
            if ($personYear === null) {
                continue;
            }

            foreach ($personYear->functions as $function) {
                foreach ($person->addresses as $address) {
                    $lines[] = $this->row($person, $personYear, $function, $address);
                }
            }
        }

        // Trailing newline: every text file this repository commits ends with
        // one, and `git diff` says so loudly when one does not.
        return implode("\n", $lines) . "\n";
    }

    private function row(
        Person $person,
        PersonYear $personYear,
        FunctionAssignment $function,
        PostalAddress $address,
    ): string {
        $values = [
            'Nom' => $person->lastName,
            'Prenom' => $person->firstName,
            'Genre' => $person->gender,
            'Date de naissance' => $person->birthDate ?? '',
            'Tél' => $person->phone ?? '',
            'GSM' => $person->mobile ?? '',
            'Email Tiers' => $person->email ?? '',
            'Rue' => $address->street,
            'No' => $address->number,
            'Bte' => $address->box ?? '',
            'cplt adr' => $address->complement ?? '',
            'Code Postal' => $address->postalCode,
            'Ville' => $address->city,
            'Pays' => $address->country,
            "Type d'adresse" => $address->type,
            'Courrier fédération' => self::bool($person->federationMailConsent),
            "Courrier d'unité" => self::bool($person->unitMailConsent),
            'Groupe unités' => UnitBlueprint::UNIT_GROUP,
            "Fonction au sein de l'unité" => UnitBlueprint::UNIT_CODE,
            'FONCTION' => $function->functionCode,
            'Tiers' => $person->deskId,
            'Branche' => $function->branch ?? '',
            'Section' => $function->section ?? '',
            'SECTION' => $function->ignoredSectionCode ?? '',
            'Date début' => $function->startDate ?? '',
            'Date fin' => $function->endDate ?? '',
            'fin de mandat' => $function->mandateEnd ?? '',
            'Fonction principale' => self::bool($function->isMain),
            'Tarif' => $personYear->feeCode,
            'Totem' => $personYear->totem ?? '',
            'Quali' => $personYear->quali ?? '',
            'Sizaine/Patrouillle' => $personYear->patrol ?? '',
            'Niveau formation' => $personYear->formationLevel,
            'Handicap' => $person->handicap,
            'Assurance complémentaire' => $person->insurance ?? '',
        ];

        $cells = [];
        foreach (self::headers() as $header) {
            $cells[] = self::escape($values[$header] ?? '');
        }

        return implode(self::DELIMITER, $cells);
    }

    /**
     * Desk writes booleans as the literal words, and
     * DeskCsvParser::parseBool() accepts nothing else: anything that is not
     * exactly "true" (case-insensitively) reads as false.
     */
    private static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Quote only what has to be quoted, the way a spreadsheet export does —
     * a field containing the delimiter, a quote or a newline. Quoting
     * everything would parse identically but make the committed diff of a
     * one-cell change unreadable.
     */
    private static function escape(string $value): string
    {
        if (!str_contains($value, self::DELIMITER)
            && !str_contains($value, '"')
            && !str_contains($value, "\n")
            && !str_contains($value, "\r")
        ) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
