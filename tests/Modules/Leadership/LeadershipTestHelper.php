<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership;

use Modules\Leadership\Value\StaffFunctionRow;

/**
 * Builders for the leadership module's fixtures.
 *
 * StaffFunctionRow has fifteen constructor parameters because it carries a
 * whole `member_functions` row plus the identity fields the repository
 * decrypted alongside it; a test that spelled all fifteen out per case
 * would hide the one field it is actually about.
 */
final class LeadershipTestHelper
{
    /**
     * SQLite mirror of modules/leadership/schema.sql — same precedent as
     * Tests\Modules\Groups\GroupsTestHelper. Keep the two in step: the
     * real schema is MySQL, this copy is what the repository tests run
     * against.
     */
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE leadership_formation_levels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            raw_value TEXT NOT NULL,
            raw_value_key TEXT NOT NULL UNIQUE,
            step TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL
        )');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function staffRow(array $overrides = []): StaffFunctionRow
    {
        $defaults = [
            'memberId' => 1,
            'memberYearId' => 10,
            'memberFunctionId' => 100,
            'firstName' => 'Camille',
            'lastName' => 'Dupont',
            'totem' => null,
            'birthDate' => null,
            'email' => null,
            'scoutYearOffset' => 0,
            'formationLevel' => null,
            'functionLabel' => 'Animateur',
            'functionRole' => 'chief',
            'isMainFunction' => true,
            'sectionId' => 5,
            'sectionName' => 'Louveteaux',
            'functionStartDate' => null,
        ];

        /** @var array{memberId: int, memberYearId: int, memberFunctionId: int, firstName: string, lastName: string, totem: ?string, birthDate: ?string, email: ?string, scoutYearOffset: int, formationLevel: ?string, functionLabel: string, functionRole: string, isMainFunction: bool, sectionId: ?int, sectionName: ?string, functionStartDate: ?string} $values */
        $values = array_merge($defaults, $overrides);

        return new StaffFunctionRow(
            memberId: $values['memberId'],
            memberYearId: $values['memberYearId'],
            memberFunctionId: $values['memberFunctionId'],
            firstName: $values['firstName'],
            lastName: $values['lastName'],
            totem: $values['totem'],
            birthDate: $values['birthDate'],
            email: $values['email'],
            scoutYearOffset: $values['scoutYearOffset'],
            formationLevel: $values['formationLevel'],
            functionLabel: $values['functionLabel'],
            functionRole: $values['functionRole'],
            isMainFunction: $values['isMainFunction'],
            sectionId: $values['sectionId'],
            sectionName: $values['sectionName'],
            functionStartDate: $values['functionStartDate'],
        );
    }

    /**
     * A birth date placing somebody exactly $age years old on $on, to the
     * day — so a test about a legal threshold does not silently become a
     * test about whichever side of a birthday today happens to fall.
     */
    public static function birthDateForAge(int $age, \DateTimeImmutable $on): string
    {
        return $on->modify('-' . $age . ' years')->format('Y-m-d');
    }
}
