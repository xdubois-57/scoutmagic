<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * The state one import left behind, counted at the time.
 *
 * Not a diff — nothing here compares anything with anything. These are
 * properties of the file that was imported, and they are stored beside
 * the diff for the same reason: the report page has to describe the day
 * it happened, not today. Recomputing them at display time would produce
 * a page that quietly changes under a heading that says a date.
 *
 * Counts only, and every one of them is a consequence somebody can act
 * on in Desk:
 *
 * - **no usable address** — the member falls out of the household
 *   calculation (`Core\Member\AddressNormalizer`), and with it out of the
 *   whole fee-category verification;
 * - **no e-mail address** — they will never be able to log in;
 * - **active with neither function nor section** — they exist and belong
 *   nowhere, which no screen of the site knows how to show;
 * - **lines not retained** — the gap between the CSV's line count and the
 *   members it produced. Usually the normal one-row-per-(function ×
 *   address) shape rather than a problem, which is why the figure is
 *   shown with that explanation rather than as an error.
 */
final class ImportQuality
{
    public function __construct(
        public readonly int $withoutUsableAddress = 0,
        public readonly int $withoutEmail = 0,
        public readonly int $withoutFunctionOrSection = 0,
        public readonly int $linesNotRetained = 0
    ) {
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'without_usable_address' => $this->withoutUsableAddress,
            'without_email' => $this->withoutEmail,
            'without_function_or_section' => $this->withoutFunctionOrSection,
            'lines_not_retained' => $this->linesNotRetained,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['without_usable_address'] ?? 0),
            (int) ($data['without_email'] ?? 0),
            (int) ($data['without_function_or_section'] ?? 0),
            (int) ($data['lines_not_retained'] ?? 0)
        );
    }

    /**
     * Count what a parsed CSV would leave behind.
     *
     * Reads the parsed structure, never the database: these are questions
     * about the file, and asking them of the roster afterwards would
     * conflate what this import brought with what was already there.
     */
    public static function fromParsedImport(ParsedImport $parsed): self
    {
        $withoutAddress = 0;
        $withoutEmail = 0;
        $withoutFunctionOrSection = 0;

        foreach ($parsed->members as $member) {
            if (!self::hasUsableAddress($member)) {
                $withoutAddress++;
            }
            if ($member->email === null) {
                $withoutEmail++;
            }
            if (!self::hasFunctionOrSection($member)) {
                $withoutFunctionOrSection++;
            }
        }

        return new self(
            $withoutAddress,
            $withoutEmail,
            $withoutFunctionOrSection,
            max(0, $parsed->lineCount - count($parsed->members))
        );
    }

    /**
     * "Usable" means what the household calculation needs, which is a
     * street and a postal code — a city alone, or a postal code alone,
     * normalises to something no two members of the same family would
     * ever agree on.
     */
    private static function hasUsableAddress(ParsedMember $member): bool
    {
        foreach ($member->addresses as $address) {
            if ($address->street !== null && $address->postalCode !== null) {
                return true;
            }
        }

        return false;
    }

    private static function hasFunctionOrSection(ParsedMember $member): bool
    {
        foreach ($member->functions as $function) {
            if ($function->functionCode !== '' || $function->sectionCode !== null) {
                return true;
            }
        }

        return false;
    }
}
