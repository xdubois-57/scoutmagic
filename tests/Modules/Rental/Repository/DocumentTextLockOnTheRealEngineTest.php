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
 * **In a database of its own, created and dropped by this class.** The
 * repository names its tables in its SQL, so they cannot be renamed for a
 * test — and a first version therefore dropped and rebuilt
 * `rental_documents` and `rental_booking_document_texts` in the shared
 * `TEST_DB_NAME`, in a reduced shape, while other database-backed classes
 * were using the same server. Whether that broke anything depended on the
 * order the suite happened to run in, which is not a property a test may
 * have. A throwaway schema costs one `CREATE DATABASE` and removes the
 * question.
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
    private \PDO $server;
    private string $schema = '';
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

        // A schema of this class's own, so nothing here touches a table
        // another database-backed class is using. Named per process, so two
        // runs against one server cannot collide either.
        $this->schema = 'sm_doc_lock_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $dsn = sprintf(
            'mysql:host=%s;port=%d',
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306)
        );

        try {
            $this->server = new \PDO(
                $dsn,
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $this->server->exec('CREATE DATABASE `' . $this->schema . '`');
            $this->server->exec('USE `' . $this->schema . '`');
        } catch (\Throwable $e) {
            // Without the right to create one, this class would have to
            // borrow the shared schema, which is what it exists not to do.
            self::markTestSkipped('A schema of its own could not be created: ' . $e->getMessage());
        }

        $this->pdo = $this->server;
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
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
        if ($this->schema !== '') {
            $this->server->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            $this->schema = '';
        }
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

    // ————— The two statements are one (#405, second review round) —————

    /**
     * **The row's write lock is real on this engine**, which is what the
     * fix leans on.
     *
     * The UPDATE and the disambiguation that follows it were two
     * independent statements at first. A concurrent SAVE landing between
     * them made the question find a row that no longer held what was
     * asked for, so it answered « refused » and the manager was told the
     * document had gone to the tenant. Nothing had. They share a
     * transaction now, and what makes that sufficient is that the UPDATE
     * takes the row's lock — measured here rather than assumed, the same
     * way this class measures `rowCount()`.
     *
     * A second connection is what makes this observable at all: one
     * process cannot interleave with itself.
     */
    public function testTheUpdateHoldsTheRowAgainstASecondConnection(): void
    {
        $this->repository->saveText(21, DocumentType::CONTRACT, '<p>Le texte de départ.</p>');

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->pdo->beginTransaction();
        $held = $this->pdo->prepare(
            'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = 21'
        );
        $held->execute(['<p>Écrit par A, pas encore validé.</p>']);

        try {
            $blocked = $other->prepare(
                'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = 21'
            );
            $blocked->execute(['<p>Écrit par B.</p>']);
            $this->pdo->rollBack();
            $this->fail(
                'a second connection changed the row while the first held it, so nothing stops a '
                    . 'concurrent save from slipping between the UPDATE and the question that follows it'
            );
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            $this->assertStringContainsString(
                'lock',
                strtolower($e->getMessage()),
                'the second write failed for some reason other than the lock this fix relies on'
            );
        }
    }

    /**
     * **And the fix itself: a concurrent save cannot land between the
     * UPDATE and the question that disambiguates it.**
     *
     * The three tests around this one all pass with
     * `beginTransaction()` deleted — two measure the engine, and the
     * caller's-transaction one borrows a transaction that is then never
     * this method's to open. So none of them held the fix, and removing
     * the line left the suite green. This test is the one that goes red:
     * it makes a second gestionnaire save the same booking at the only
     * instant where it used to do damage.
     *
     * With both statements inside one transaction, the UPDATE holds the
     * row, so that second save waits — and gives up after its own
     * one-second lock timeout, since the probe cannot outlive the call it
     * runs inside. Without it, the second save lands immediately, the
     * question finds a row that no longer says what was asked for, and
     * `saveText()` answers « refused »: the first gestionnaire is told
     * « ce document a été envoyé au locataire » about a document nobody
     * sent, and their text is the one that is gone.
     *
     * Unchanged text on purpose — that is the path that asks the
     * question at all.
     */
    public function testAConcurrentSaveCannotSlipBetweenTheUpdateAndTheQuestion(): void
    {
        $text = '<p>Le texte que le gestionnaire réenregistre tel quel.</p>';

        $competitor = $this->secondConnection();
        $competitor->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $slipped = null;
        $interleaving = $this->connectionThatInterleaves(
            function () use ($competitor, &$slipped): void {
                // Once: this is one concurrent save, not a retry loop.
                if ($slipped !== null) {
                    return;
                }

                try {
                    $second = $competitor->prepare(
                        'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = 27'
                    );
                    $second->execute(['<p>Écrit par un second gestionnaire.</p>']);
                    $slipped = true;
                } catch (\PDOException) {
                    $slipped = false;
                }
            }
        );

        $repository = new RentalDocumentRepository($interleaving);
        $repository->saveText(27, DocumentType::CONTRACT, $text);

        $written = $repository->saveText(27, DocumentType::CONTRACT, $text);

        $this->assertNotNull(
            $slipped,
            'the disambiguation query was never reached, so this test observed nothing'
        );
        $this->assertFalse(
            $slipped,
            'a concurrent save changed the row between the UPDATE and the question about it'
        );
        $this->assertTrue(
            $written,
            'an unchanged re-save was refused because another save slipped in, so the gestionnaire '
                . 'was told the document had gone to the tenant'
        );
        $this->assertSame($text, $this->repository->findText(27, DocumentType::CONTRACT));
    }

    /**
     * And nothing is left open behind it.
     *
     * A transaction this method opened and forgot would hold that lock
     * for the rest of the request, so the next save on the same booking
     * would wait for a connection nobody is going to commit.
     */
    public function testItLeavesNoTransactionOpen(): void
    {
        $this->repository->saveText(23, DocumentType::CONTRACT, '<p>Un.</p>');
        $this->assertFalse($this->pdo->inTransaction());

        // The disambiguation path too, which is the one that returns
        // early-ish and could forget the commit.
        $this->repository->saveText(23, DocumentType::CONTRACT, '<p>Un.</p>');
        $this->assertFalse($this->pdo->inTransaction());
    }

    /**
     * A caller who already owns a transaction keeps it.
     *
     * `beginTransaction()` on a connection that already has one throws,
     * and this repository is called from a service that may well have
     * opened one. So the transaction is taken only when nobody above owns
     * it — and the caller's own must still be theirs to commit when this
     * returns.
     */
    public function testItBorrowsTheCallersTransactionRatherThanOpeningASecond(): void
    {
        $this->pdo->beginTransaction();

        $this->assertTrue($this->repository->saveText(25, DocumentType::CONTRACT, '<p>Deux.</p>'));
        $this->assertTrue(
            $this->repository->saveText(25, DocumentType::CONTRACT, '<p>Deux.</p>'),
            'the unchanged re-save must still be accepted inside a caller\'s transaction'
        );
        $this->assertTrue($this->pdo->inTransaction(), 'the caller\'s transaction was committed for them');

        $this->pdo->commit();
        $this->assertSame('<p>Deux.</p>', $this->repository->findText(25, DocumentType::CONTRACT));
    }

    /** A connection of its own, on this class's own schema. */
    private function secondConnection(): \PDO
    {
        return new \PDO(
            $this->dsn(),
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    private function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306),
            $this->schema
        );
    }

    /**
     * A connection that runs `$probe` the moment `saveText()` reaches its
     * disambiguation query — after the UPDATE, before the question.
     *
     * That instant is the whole subject of this test, and one process
     * cannot pause itself there. `prepare()` is the seam because it is the
     * one call the repository makes between the two statements, and the
     * disambiguation query is recognisable by the alias it reads
     * `body_html` through; `findText()`'s own SELECT does not.
     *
     * Deliberately NOT a mock: the statements have to reach the real
     * engine for its locks to mean anything, which is the point of this
     * class.
     */
    private function connectionThatInterleaves(\Closure $probe): \PDO
    {
        return new class (
            $this->dsn(),
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: '',
            $probe
        ) extends \PDO {
            public function __construct(
                string $dsn,
                string $user,
                string $password,
                private \Closure $probe
            ) {
                parent::__construct($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            }

            /**
             * @param  array<int, mixed> $options
             */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 't.body_html = ?')) {
                    ($this->probe)();
                }

                return parent::prepare($query, $options);
            }
        };
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
