<?php

declare(strict_types=1);

namespace Tests\Modules\Rental;

/**
 * SQLite mirrors of modules/rental/schema.sql, for the in-memory test
 * database — same approach as Tests\Modules\Registration\
 * RegistrationTestHelper. The column set must stay in step with the real
 * schema by hand: a column added there and forgotten here shows up as a
 * repository test failing on "no such column", which is the intended
 * failure mode.
 */
class RentalTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE rental_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_type TEXT NOT NULL,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            capacity INTEGER,
            quantity INTEGER NOT NULL DEFAULT 1,
            arrival_time TEXT,
            departure_time TEXT,
            emergency_phone_encrypted BLOB,
            is_archived INTEGER NOT NULL DEFAULT 0,
            is_public INTEGER NOT NULL DEFAULT 0,
            show_in_menu INTEGER NOT NULL DEFAULT 0,
            billing_unit TEXT NOT NULL DEFAULT "flat_stay",
            default_unit_price_cents INTEGER,
            minimum_amount_cents INTEGER,
            minimum_persons INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE rental_price_periods (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            recurs_yearly INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_renter_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            is_default INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_price_grid (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            period_id INTEGER,
            category_id INTEGER,
            unit_price_cents INTEGER NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE,
            FOREIGN KEY (period_id) REFERENCES rental_price_periods(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES rental_renter_categories(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_fees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            nature TEXT NOT NULL,
            amount_cents INTEGER NOT NULL,
            meter_unit TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_asset_managers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            is_renter_contact INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (asset_id, member_id),
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');
    }

    public static function insertMember(\PDO $pdo, string $deskId): int
    {
        $stmt = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * An active member_years row for $memberId, reachable by $email.
     *
     * The blind index has to be computed with the SAME EncryptionService the
     * code under test uses, since that is what
     * MemberService::getLinkedMembers() looks the address up by — passing
     * the service in rather than rebuilding one here is what keeps the two
     * keyed identically.
     */
    public static function insertMemberYear(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        int $memberId,
        int $scoutYearId,
        string $email,
        string $firstName = 'Prénom'
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO member_years (
                member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                email_encrypted, email_blind_index, is_active
             ) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $encryption->encrypt($firstName, 'member_years.first_name'),
            $encryption->encrypt('Nom', 'member_years.last_name'),
            $encryption->encrypt($email, 'member_years.email'),
            $encryption->blindIndex(strtolower(trim($email)), 'email'),
        ]);

        return (int) $pdo->lastInsertId();
    }
}
