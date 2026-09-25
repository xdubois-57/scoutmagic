<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Database\Connection;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Repository\RentalDocumentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The one thing about the document-text lock that only the real engine can
 * answer: what `rowCount()` counts.
 *
 * **Against MySQL, and not through `DatabaseTestHelper::createTestDatabase()`.**
 * That helper builds an in-memory SQLite whatever `TEST_DB_*` says, and
 * SQLite's `changes()` counts the rows an UPDATE MATCHED. MySQL's
 * `rowCount()` counts the rows it CHANGED — this application does not set
 * `PDO::MYSQL_ATTR_FOUND_ROWS` — so the two disagree on exactly one case,
 * and it is the case that mattered: an UPDATE whose WHERE matched a row
 * already holding those values.
 *
 * `RentalDocumentRepository::saveText()` read its answer off `rowCount()`,
 * so on MySQL a manager double-clicking « Enregistrer » on unedited text
 * was told « ce document a été envoyé au locataire » about a document
 * nobody had sent, and the write was dropped. Every SQLite-backed test of
 * that method was green over it.
 *
 * **Two tables, built here rather than from the module's schema**, because
 * these are the only two `saveText()` touches and the foreign key to
 * `rental_bookings` would drag the module's whole schema in for nothing.
 * The column types and the `ON UPDATE CURRENT_TIMESTAMP` are copied from
 * `modules/rental/schema.sql` verbatim — the timestamp clause matters,
 * since the repository writes `updated_at` explicitly and an explicit
 * value equal to the stored one is what makes the whole row unchanged.
 */
#[Group('database')]
final class DocumentTextLockOnTheRealEngineTest extends TestCase
{
    private \PDO $pdo;
    private RentalDocumentRepository $repository;

    protected function setUp(): void
    {
        $connection = new Connection(
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306),
            getenv('TEST_DB_NAME') ?: 'test_db',
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: ''
        );

        $result = $connection->testConnection();
        if ($result !== true) {
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised('Database connection not available: ' . $result);
        }

        $this->pdo = $connection->getPdo();
        $this->pdo->exec('DROP TABLE IF EXISTS rental_booking_document_texts');
        $this->pdo->exec('DROP TABLE IF EXISTS rental_documents');
        $this->pdo->exec(
            'CREATE TABLE rental_booking_document_texts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id INT UNSIGNED NOT NULL,
                document_type VARCHAR(30) NOT NULL,
                body_html MEDIUMTEXT NOT NULL,
                last_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rental_booking_document_text (booking_id, document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE rental_documents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id INT UNSIGNED NOT NULL,
                file_id INT UNSIGNED NOT NULL,
                document_type VARCHAR(30) NOT NULL,
                version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                is_for_renter TINYINT(1) NOT NULL DEFAULT 0,
                source ENUM(\'manual\', \'email\') NOT NULL DEFAULT \'manual\',
                generated_snapshot MEDIUMTEXT NULL,
                sent_at DATETIME NULL,
                created_by_member_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->repository = new RentalDocumentRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS rental_booking_document_texts');
        $this->pdo->exec('DROP TABLE IF EXISTS rental_documents');
    }

    /**
     * First, the engine's semantic, measured rather than assumed.
     *
     * If a future MySQL, or a connection option added elsewhere, made
     * `rowCount()` report matched rows, the case below would stop being
     * the case this class exists for — and a test that silently stopped
     * testing anything is worse than one that says so.
     */
    public function testThisEngineCountsChangedRowsRatherThanMatchedOnes(): void
    {
        $this->repository->saveText(1, DocumentType::CONTRACT, '<p>Identique.</p>');

        $statement = $this->pdo->prepare(
            'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = 1'
        );
        $statement->execute(['<p>Identique.</p>']);

        $this->assertSame(
            0,
            $statement->rowCount(),
            'this engine reports matched rows, so the defect this class guards is not reachable here '
                . 'and the guarantee needs re-deriving rather than re-asserting'
        );
    }

    /**
     * The defect: saving the same text twice, inside the same second.
     */
    public function testSavingTheSameTextTwiceIsNotReadAsASend(): void
    {
        $text = '<p>Le texte que le gestionnaire enregistre deux fois.</p>';

        $this->assertTrue($this->repository->saveText(7, DocumentType::CONTRACT, $text));
        $this->assertTrue(
            $this->repository->saveText(7, DocumentType::CONTRACT, $text),
            'an unchanged re-save was reported as a document already sent to the tenant'
        );

        $this->assertSame($text, $this->repository->findText(7, DocumentType::CONTRACT));
    }

    /**
     * And the lock itself still holds on this engine — the disambiguation
     * must not become a way through it. Identical text, so it takes the
     * same zero-changed-rows path as the case above and has to come out
     * the other way.
     */
    public function testTheSameTextAfterASendIsStillRefused(): void
    {
        $text = '<p>Le texte tel qu\'il est parti.</p>';
        $this->repository->saveText(9, DocumentType::CONTRACT, $text);
        $this->markSent(9);

        $this->assertFalse(
            $this->repository->saveText(9, DocumentType::CONTRACT, $text),
            'identical text slipped past the lock because nothing changed'
        );
    }

    /** Different text after a send is refused too, and nothing is written. */
    public function testDifferentTextAfterASendIsRefusedAndChangesNothing(): void
    {
        $this->repository->saveText(11, DocumentType::CONTRACT, '<p>Ce qui est parti.</p>');
        $this->markSent(11);

        $this->assertFalse($this->repository->saveText(11, DocumentType::CONTRACT, '<p>Écrit trop tard.</p>'));
        $this->assertSame('<p>Ce qui est parti.</p>', $this->repository->findText(11, DocumentType::CONTRACT));
    }

    /** Different text before any send lands, so the guard is not « refuse everything ». */
    public function testDifferentTextBeforeAnySendLands(): void
    {
        $this->repository->saveText(13, DocumentType::CONTRACT, '<p>Première rédaction.</p>');

        $this->assertTrue($this->repository->saveText(13, DocumentType::CONTRACT, '<p>Seconde rédaction.</p>'));
        $this->assertSame('<p>Seconde rédaction.</p>', $this->repository->findText(13, DocumentType::CONTRACT));
    }

    /**
     * A send of ANOTHER type does not lock this one: the `NOT EXISTS`
     * matches on the type as well as the booking, and a zero-changed-rows
     * answer must not be read as « something went out ».
     */
    public function testASendOfAnotherTypeDoesNotLockThisOne(): void
    {
        $text = '<p>Le contrat.</p>';
        $this->repository->saveText(15, DocumentType::CONTRACT, $text);
        $this->markSent(15, DocumentType::INVOICE);

        $this->assertTrue($this->repository->saveText(15, DocumentType::CONTRACT, $text));
    }

    private function markSent(int $bookingId, ?DocumentType $type = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rental_documents (booking_id, file_id, document_type, sent_at)
             VALUES (?, 1, ?, ?)'
        );
        $statement->execute([
            $bookingId,
            ($type ?? DocumentType::CONTRACT)->value,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
