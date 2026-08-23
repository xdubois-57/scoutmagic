<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Makes one fictional person, and the small pieces one is made of.
 *
 * Shared by ScenarioPeople (which writes its members out by hand) and
 * PopulationBuilder (which grows the filler), so that a scenario member and a
 * filler member are indistinguishable in the CSV. If they were built by two
 * different code paths, a test could accidentally pass because its subject was
 * the only member shaped a certain way.
 *
 * Every draw goes through the one Rng instance handed in, and the two callers
 * run in a fixed order, which is what makes the whole dataset reproducible.
 */
final class PersonFactory
{
    /**
     * Not 50: a unit is rarely balanced, and a dataset where every chart is a
     * straight line gives Prévisions and Statistiques nothing to show
     * (scenario 24).
     */
    public const GENDER_F_PERCENT = 46;

    /** @var array<string, true> */
    private array $usedEmails = [];

    private int $lastNameCursor = 0;

    public function __construct(private readonly Rng $rng)
    {
    }

    /**
     * @param ?int    $birthYear      null for the member who has no birth date
     * @param ?string $branch         drives how likely a second address is
     * @param ?string $forcedGender   'F'/'M' when a photo already decided it
     */
    public function make(
        string $tiers,
        ?int $birthYear,
        ?string $branch,
        ?string $lastName = null,
        ?PostalAddress $sharedAddress = null,
        ?string $sharedEmail = null,
        ?string $forcedGender = null,
        bool $withEmail = true,
        bool $withLandline = true,
        string $handicap = 'false',
        ?string $insurance = null,
    ): Person {
        $gender = $forcedGender ?? ($this->rng->chance(self::GENDER_F_PERCENT) ? 'F' : 'M');
        $firstName = $this->rng->pick($gender === 'F' ? UnitBlueprint::FIRST_NAMES_F : UnitBlueprint::FIRST_NAMES_M);
        $lastName ??= $this->nextLastName();

        $addresses = [$sharedAddress ?? $this->makeAddress('Domicile')];
        if ($sharedAddress === null && $this->rng->chance(self::secondAddressChance($branch))) {
            $addresses[] = $this->makeAddress('Adresse secondaire');
        }

        $serial = (int) substr($tiers, 1);

        $email = null;
        if ($sharedEmail !== null) {
            $email = $sharedEmail;
        } elseif ($withEmail && $this->rng->chance(88)) {
            $email = $this->makeEmail($firstName, $lastName);
        }

        return new Person(
            deskId: $tiers,
            lastName: $lastName,
            firstName: $firstName,
            gender: $gender,
            birthDate: $birthYear !== null ? $this->makeBirthDate($birthYear) : null,
            phone: $withLandline && $this->rng->chance(45) ? self::landline($serial) : null,
            mobile: self::mobile($serial),
            email: $email,
            addresses: $addresses,
            handicap: $handicap,
            insurance: $insurance ?? ($this->rng->chance(10) ? $this->rng->pick(UnitBlueprint::INSURANCES) : null),
            federationMailConsent: $this->rng->chance(80),
            unitMailConsent: $this->rng->chance(92),
        );
    }

    private static function secondAddressChance(?string $branch): int
    {
        return match ($branch) {
            'Baladins', 'Louveteaux', 'Éclaireurs' => UnitBlueprint::TWO_ADDRESS_PERCENT_CHILD,
            'Pionniers' => UnitBlueprint::TWO_ADDRESS_PERCENT_TEEN,
            default => UnitBlueprint::TWO_ADDRESS_PERCENT_ADULT,
        };
    }

    public function makeAddress(string $type): PostalAddress
    {
        [$postalCode, $city] = $this->rng->pick(UnitBlueprint::TOWNS);

        return new PostalAddress(
            type: $type,
            street: $this->rng->pick(UnitBlueprint::STREETS),
            number: (string) $this->rng->int(1, 180) . ($this->rng->chance(8) ? $this->rng->pick(['A', 'B', 'C']) : ''),
            box: $this->rng->chance(10) ? (string) $this->rng->int(1, 12) : null,
            complement: $this->rng->chance(6) ? 'Bât. ' . $this->rng->pick(['Nord', 'Sud', 'Est']) : null,
            postalCode: $postalCode,
            city: $city,
        );
    }

    public function makeBirthDate(int $year): string
    {
        $month = $this->rng->int(1, 12);
        $day = $this->rng->int(1, 28);

        return sprintf('%02d/%02d/%04d', $day, $month, $year);
    }

    public function makeEmail(string $firstName, string $lastName): string
    {
        $base = self::slug($firstName) . '.' . self::slug($lastName);
        $email = $base . '@example.com';

        $suffix = 2;
        while (isset($this->usedEmails[$email])) {
            $email = $base . $suffix . '@example.com';
            $suffix++;
        }
        $this->usedEmails[$email] = true;

        return $email;
    }

    /** A parent's address, shared by a whole sibling group (scenarios 17-18). */
    public function makeHouseholdEmail(string $lastName): string
    {
        $email = 'famille.' . self::slug($lastName) . '@example.org';
        $this->usedEmails[$email] = true;

        return $email;
    }

    /**
     * Structurally unassignable numbers: no Belgian subscriber block starts
     * with 000000. A real-looking prefix with an impossible tail, so nobody's
     * phone ever rings because of this dataset.
     */
    public static function landline(int $serial): string
    {
        return sprintf('+32 2 000 %02d %02d', intdiv($serial, 100) % 100, $serial % 100);
    }

    public static function mobile(int $serial): string
    {
        return sprintf('+32 470 00 %02d %02d', intdiv($serial, 100) % 100, $serial % 100);
    }

    /** ASCII, lowercase, no separator — an address, not a display name. */
    public static function slug(string $value): string
    {
        $ascii = strtr($value, [
            'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'Ç' => 'c', 'ç' => 'c',
            'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Î' => 'i', 'Ï' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ô' => 'o', 'Ö' => 'o', 'ô' => 'o', 'ö' => 'o',
            'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Œ' => 'oe', 'œ' => 'oe',
        ]);

        $ascii = strtolower($ascii);
        $ascii = str_replace([' ', "'", '’', '-'], '', $ascii);

        return preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';
    }

    /**
     * Surnames are handed out in table order rather than drawn at random, so
     * the four specimens at the top of UnitBlueprint::LAST_NAMES — the accent,
     * the apostrophe, the particle, the hyphen — are guaranteed to appear
     * rather than merely likely to.
     */
    public function nextLastName(): string
    {
        $names = UnitBlueprint::LAST_NAMES;
        $name = $names[$this->lastNameCursor % count($names)];
        $this->lastNameCursor++;

        return $name;
    }
}
