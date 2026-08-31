<?php

declare(strict_types=1);

namespace Tests\Modules\Registration;

class RegistrationTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE registration_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            parent_name_encrypted BLOB NOT NULL,
            child_last_name_encrypted BLOB NOT NULL,
            child_first_name_encrypted BLOB NOT NULL,
            gender_encrypted BLOB NOT NULL,
            birth_date_encrypted BLOB NOT NULL,
            street_encrypted BLOB NOT NULL,
            number_encrypted BLOB NOT NULL,
            postal_code_encrypted BLOB NOT NULL,
            city_encrypted BLOB NOT NULL,
            email_encrypted BLOB NOT NULL,
            email_blind_index TEXT NOT NULL,
            phone1_encrypted BLOB NOT NULL,
            phone2_encrypted BLOB,
            remarks_encrypted BLOB,
            name_dob_blind_index TEXT NOT NULL,
            desired_section_id INTEGER,
            status TEXT NOT NULL DEFAULT "pending",
            received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tracking_token_hash TEXT NOT NULL,
            intended_section_id INTEGER,
            fee_category_id INTEGER,
            internal_notes_encrypted BLOB,
            linked_member_id INTEGER UNIQUE,
            accepted_email_sent_at TEXT,
            refused_email_sent_at TEXT,
            final_at TEXT,
            address_normalized_blind_index TEXT,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (desired_section_id) REFERENCES sections(id),
            FOREIGN KEY (intended_section_id) REFERENCES sections(id),
            FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
            FOREIGN KEY (linked_member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE registration_request_siblings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration_request_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            UNIQUE(registration_request_id, member_id),
            FOREIGN KEY (registration_request_id) REFERENCES registration_requests(id),
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE registration_secondary_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration_request_id INTEGER NOT NULL,
            email_encrypted BLOB NOT NULL,
            email_blind_index TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            confirmation_token_hash TEXT,
            confirmation_expires_at TEXT,
            last_confirmation_sent_at TEXT,
            confirmed_at TEXT,
            deactivated_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(registration_request_id, email_blind_index),
            FOREIGN KEY (registration_request_id) REFERENCES registration_requests(id)
        )');

        $pdo->exec('CREATE TABLE registration_slot_capacities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            age_branch_id INTEGER NOT NULL,
            year_in_branch INTEGER NOT NULL,
            -- Nullable, exactly like modules/registration/schema.sql: NULL is
            -- "pas de limite", 0 is "branche fermée", and a test database that
            -- refused NULL would make the distinction untestable.
            capacity INTEGER NULL,
            UNIQUE(age_branch_id, year_in_branch),
            FOREIGN KEY (age_branch_id) REFERENCES age_branches(id)
        )');

        $pdo->exec('CREATE TABLE registration_year_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL UNIQUE,
            code TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
        )');

        $pdo->exec('CREATE TABLE registration_section_transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            target_scout_year_id INTEGER NOT NULL,
            destination_section_id INTEGER NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(member_id, target_scout_year_id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (target_scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (destination_section_id) REFERENCES sections(id)
        )');
    }

    /**
     * @return array{id: int}
     */
    public static function insertScoutYear(\PDO $pdo, string $label, string $startDate, string $endDate): int
    {
        $stmt = $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)');
        $stmt->execute([$label, $startDate, $endDate]);

        return (int) $pdo->lastInsertId();
    }

    public static function insertAgeBranch(\PDO $pdo, string $deskCode, string $label, int $sortOrder): int
    {
        $stmt = $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $label, $sortOrder]);

        return (int) $pdo->lastInsertId();
    }

    public static function insertMember(\PDO $pdo, string $deskId): int
    {
        $stmt = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);

        return (int) $pdo->lastInsertId();
    }
}
