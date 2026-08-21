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
            min_nights INTEGER NOT NULL DEFAULT 0,
            max_nights INTEGER NOT NULL DEFAULT 0,
            min_notice_days INTEGER NOT NULL DEFAULT 0,
            max_horizon_days INTEGER NOT NULL DEFAULT 0,
            allowed_arrival_weekdays TEXT NOT NULL DEFAULT "",
            max_persons INTEGER,
            buffer_nights INTEGER NOT NULL DEFAULT 0,
            payments_enabled INTEGER NOT NULL DEFAULT 0,
            finance_account_id INTEGER,
            deposit_mode TEXT NOT NULL DEFAULT "none",
            deposit_amount_cents INTEGER,
            deposit_percentage INTEGER,
            deposit_due_days INTEGER,
            balance_due_days INTEGER,
            vat_exemption_note TEXT,
            calendar_publication_enabled INTEGER NOT NULL DEFAULT 0,
            calendar_id INTEGER,
            calendar_publish_from TEXT NOT NULL DEFAULT "confirmation",
            security_deposit_mode TEXT NOT NULL DEFAULT "none",
            security_deposit_amount_cents INTEGER,
            security_deposit_due_days INTEGER,
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

        $pdo->exec('CREATE TABLE rental_reference_sequences (
            year INTEGER NOT NULL PRIMARY KEY,
            last_sequence INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE rental_bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            reference TEXT NOT NULL UNIQUE,
            arrival_date TEXT NOT NULL,
            departure_date TEXT NOT NULL,
            units INTEGER NOT NULL DEFAULT 1,
            estimated_persons INTEGER,
            renter_category_id INTEGER,
            renter_name_encrypted BLOB NOT NULL,
            renter_email_encrypted BLOB NOT NULL,
            renter_email_blind_index TEXT NOT NULL,
            renter_phone_encrypted BLOB,
            renter_organisation_encrypted BLOB,
            purpose_encrypted BLOB,
            renter_comment_encrypted BLOB,
            status TEXT NOT NULL DEFAULT "received",
            received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            final_at TEXT,
            hold_until TEXT,
            hold_origin TEXT,
            estimated_price_snapshot TEXT,
            estimated_total_cents INTEGER,
            agreed_price_snapshot TEXT,
            agreed_total_cents INTEGER,
            rental_receivable_id INTEGER,
            rental_communication TEXT,
            deposit_amount_cents INTEGER,
            deposit_due_date TEXT,
            balance_due_date TEXT,
            security_deposit_receivable_id INTEGER,
            security_deposit_communication TEXT,
            security_deposit_amount_cents INTEGER,
            security_deposit_due_date TEXT,
            security_deposit_status TEXT NOT NULL DEFAULT "none",
            security_deposit_returned_cents INTEGER,
            security_deposit_withheld_cents INTEGER,
            security_deposit_returned_at TEXT,
            security_deposit_note_encrypted BLOB,
            settlement_last_version INTEGER NOT NULL DEFAULT 0,
            inventory_snapshotted INTEGER NOT NULL DEFAULT 0,
            billing_name_encrypted BLOB,
            billing_address_encrypted BLOB,
            billing_country TEXT,
            billing_vat_number_encrypted BLOB,
            billing_enterprise_number_encrypted BLOB,
            billing_email_encrypted BLOB,
            billing_reference_encrypted BLOB,
            conditions_version TEXT,
            conditions_hash TEXT,
            conditions_accepted_at TEXT,
            privacy_version TEXT,
            privacy_hash TEXT,
            privacy_acknowledged_at TEXT,
            tracking_token_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');
        $pdo->exec('CREATE TABLE rental_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            units INTEGER NOT NULL DEFAULT 1,
            reason TEXT,
            created_by_member_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_booking_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            author_member_id INTEGER,
            body_encrypted BLOB NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_booking_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            from_value TEXT,
            to_value TEXT,
            summary TEXT,
            actor_member_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            document_type TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            is_for_renter INTEGER NOT NULL DEFAULT 0,
            generated_snapshot TEXT,
            sent_at TEXT,
            created_by_member_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_booking_document_texts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            document_type TEXT NOT NULL,
            body_html TEXT NOT NULL,
            last_version INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (booking_id, document_type),
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_meters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            meter_kind TEXT NOT NULL DEFAULT "other",
            unit TEXT NOT NULL DEFAULT "",
            fee_id INTEGER,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_meter_readings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            meter_id INTEGER NOT NULL,
            phase TEXT NOT NULL,
            value_milli INTEGER NOT NULL,
            read_at TEXT NOT NULL,
            file_id INTEGER,
            comment TEXT,
            recorded_by_member_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (booking_id, meter_id, phase),
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_inventory_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_booking_inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            arrival_state TEXT NOT NULL DEFAULT "not_checked",
            departure_state TEXT NOT NULL DEFAULT "not_checked",
            arrival_note TEXT,
            departure_note TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            description_encrypted BLOB NOT NULL,
            proposed_amount_cents INTEGER,
            decision TEXT NOT NULL DEFAULT "pending",
            decided_amount_cents INTEGER,
            decided_at TEXT,
            decided_by_member_id INTEGER,
            file_id INTEGER,
            created_by_member_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_settlements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            final_persons INTEGER,
            lines_snapshot TEXT,
            total_cents INTEGER NOT NULL DEFAULT 0,
            already_paid_cents INTEGER NOT NULL DEFAULT 0,
            balance_cents INTEGER NOT NULL DEFAULT 0,
            security_deposit_withheld_cents INTEGER,
            security_deposit_return_cents INTEGER,
            is_validated INTEGER NOT NULL DEFAULT 0,
            validated_at TEXT,
            validated_by_member_id INTEGER,
            created_by_member_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE rental_change_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            origin TEXT NOT NULL,
            kind TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            proposed_arrival_date TEXT,
            proposed_departure_date TEXT,
            proposed_units INTEGER,
            proposed_persons INTEGER,
            proposed_price_snapshot TEXT,
            proposed_total_cents INTEGER,
            message_encrypted BLOB,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at TEXT,
            decided_by_member_id INTEGER,
            FOREIGN KEY (booking_id) REFERENCES rental_bookings(id) ON DELETE CASCADE
        )');

        self::createComplianceTables($pdo);
    }

    /**
     * The compliance register and the sent-reminders ledger (§6.33, §6.29).
     */
    public static function createComplianceTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE rental_compliance_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            file_id INTEGER,
            expires_on TEXT,
            remark TEXT,
            reminded_on TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE rental_booking_aggregates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id INTEGER NOT NULL,
            stay_month TEXT NOT NULL,
            occupied_days INTEGER NOT NULL DEFAULT 0,
            amount_cents INTEGER NOT NULL DEFAULT 0,
            scout_year_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE rental_reminders_sent (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_type TEXT NOT NULL,
            subject_id INTEGER NOT NULL,
            reminder_key TEXT NOT NULL,
            sent_on TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (subject_type, subject_id, reminder_key)
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
