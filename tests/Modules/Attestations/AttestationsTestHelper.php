<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations;

use Core\Security\EncryptionService;

/**
 * Fixtures for the attestations module.
 *
 * The SQLite mirror of `modules/attestations/schema.sql` — same precedent
 * as Tests\Modules\Leadership\LeadershipTestHelper. Keep the two in step:
 * the real schema is MySQL, this copy is what the repository tests run
 * against.
 */
final class AttestationsTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE attestation_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            category TEXT NOT NULL,
            label TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'draft\',
            page_count INTEGER NOT NULL DEFAULT 0,
            pages_per_document INTEGER NOT NULL DEFAULT 0,
            document_count INTEGER NOT NULL DEFAULT 0,
            discarded_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            published_at TEXT NULL,
            created_by INTEGER NULL,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');
    }

    /** The two 32-byte keys every test in this tree encrypts with. */
    public static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    public static function createScoutYear(\PDO $pdo, string $label = '2025-2026'): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$label, substr($label, 0, 4) . '-09-01', substr($label, 5, 4) . '-08-31']);

        return (int) $pdo->lastInsertId();
    }

    /**
     * One member with one year row carrying the encrypted name — the only
     * two tables the name directory reads.
     *
     * @return int the persistent members.id
     */
    public static function createMember(
        \PDO $pdo,
        int $scoutYearId,
        string $firstName,
        string $lastName,
        ?string $deskId = null
    ): int {
        $stmt = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId ?? ('D' . bin2hex(random_bytes(4)))]);
        $memberId = (int) $pdo->lastInsertId();

        self::addMemberYear($pdo, $memberId, $scoutYearId, $firstName, $lastName);

        return $memberId;
    }

    /** A second (or third) season for a member who already exists. */
    public static function addMemberYear(
        \PDO $pdo,
        int $memberId,
        int $scoutYearId,
        string $firstName,
        string $lastName
    ): int {
        $encryption = self::encryption();

        $stmt = $pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $encryption->encrypt($firstName, 'member_years.first_name'),
            $encryption->encrypt($lastName, 'member_years.last_name'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** The committed golden fixture: five certificates of two pages. */
    public static function goldenFixturePath(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/pdf/attestations_batch_sample.pdf';
    }

    /**
     * A throwaway PDF written to the system temp directory, for the cases
     * the golden fixture deliberately does not cover (an odd page count, a
     * file that is not a PDF at all). The caller unlinks it.
     *
     * @param list<list<string>> $pages
     */
    public static function writeTemporaryPdf(array $pages): string
    {
        $path = sys_get_temp_dir() . '/attestations_test_' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($path, AttestationsPdfBuilder::build($pages));

        return $path;
    }
}
