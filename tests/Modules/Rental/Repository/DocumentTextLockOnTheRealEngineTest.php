<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Document\DocumentType;
use Modules\Rental\Repository\RentalDocumentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

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
 * **In a database of its own, holding the whole production schema**
 * (`Tests\UsesProductionEngine`). A first version dropped and rebuilt
 * `rental_documents` and `rental_booking_document_texts` in the shared
 * `TEST_DB_NAME`, in a reduced shape, while other database-backed classes
 * were using the same server; the one after it built the same reduced
 * shape in a throwaway schema. Both judged the lock on tables written out
 * by hand. These are the tables the migration builds from
 * `modules/rental/schema.sql` — unique key, foreign key into
 * `rental_bookings` and all — so every text here belongs to a real
 * booking, as it must on a site. The repository writes `updated_at`
 * explicitly, and an explicit value equal to the stored one is what makes
 * the whole row unchanged; that holds whether or not the migrated column
 * carries its `ON UPDATE CURRENT_TIMESTAMP` (issue #590), since an
 * assigned column is never bumped.
 *
 * The second connections the lock tests need are sessions on that same
 * database, opened with the attributes the site opens with.
 */
#[Group('database')]
final class DocumentTextLockOnTheRealEngineTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RentalDocumentRepository $repository;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new RentalDocumentRepository($this->pdo);

        $this->pdo->exec(
            "INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')"
        );
        $this->assetId = (int) $this->pdo->lastInsertId();
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
        $booking = $this->booking();
        $this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Identique.</p>');

        $statement = $this->pdo->prepare(
            'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = ?'
        );
        $statement->execute(['<p>Identique.</p>', $booking]);

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
        $booking = $this->booking();
        $text = '<p>Le texte que le gestionnaire enregistre deux fois.</p>';

        $this->assertTrue($this->repository->saveText($booking, DocumentType::CONTRACT, $text));
        $this->assertTrue(
            $this->repository->saveText($booking, DocumentType::CONTRACT, $text),
            'an unchanged re-save was reported as a document already sent to the tenant'
        );

        $this->assertSame($text, $this->repository->findText($booking, DocumentType::CONTRACT));
    }

    /**
     * And the lock itself still holds on this engine — the disambiguation
     * must not become a way through it. Identical text, so it takes the
     * same zero-changed-rows path as the case above and has to come out
     * the other way.
     */
    public function testTheSameTextAfterASendIsStillRefused(): void
    {
        $booking = $this->booking();
        $text = '<p>Le texte tel qu\'il est parti.</p>';
        $this->repository->saveText($booking, DocumentType::CONTRACT, $text);
        $this->markSent($booking);

        $this->assertFalse(
            $this->repository->saveText($booking, DocumentType::CONTRACT, $text),
            'identical text slipped past the lock because nothing changed'
        );
    }

    /** Different text after a send is refused too, and nothing is written. */
    public function testDifferentTextAfterASendIsRefusedAndChangesNothing(): void
    {
        $booking = $this->booking();
        $this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Ce qui est parti.</p>');
        $this->markSent($booking);

        $this->assertFalse($this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Écrit trop tard.</p>'));
        $this->assertSame('<p>Ce qui est parti.</p>', $this->repository->findText($booking, DocumentType::CONTRACT));
    }

    /** Different text before any send lands, so the guard is not « refuse everything ». */
    public function testDifferentTextBeforeAnySendLands(): void
    {
        $booking = $this->booking();
        $this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Première rédaction.</p>');

        $this->assertTrue($this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Seconde rédaction.</p>'));
        $this->assertSame('<p>Seconde rédaction.</p>', $this->repository->findText($booking, DocumentType::CONTRACT));
    }

    /**
     * A send of ANOTHER type does not lock this one: the `NOT EXISTS`
     * matches on the type as well as the booking, and a zero-changed-rows
     * answer must not be read as « something went out ».
     */
    public function testASendOfAnotherTypeDoesNotLockThisOne(): void
    {
        $booking = $this->booking();
        $text = '<p>Le contrat.</p>';
        $this->repository->saveText($booking, DocumentType::CONTRACT, $text);
        $this->markSent($booking, DocumentType::INVOICE);

        $this->assertTrue($this->repository->saveText($booking, DocumentType::CONTRACT, $text));
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
        $booking = $this->booking();
        $this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Le texte de départ.</p>');

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->pdo->beginTransaction();
        $held = $this->pdo->prepare(
            'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = ?'
        );
        $held->execute(['<p>Écrit par A, pas encore validé.</p>', $booking]);

        try {
            $blocked = $other->prepare(
                'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = ?'
            );
            $blocked->execute(['<p>Écrit par B.</p>', $booking]);
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
        $booking = $this->booking();
        $text = '<p>Le texte que le gestionnaire réenregistre tel quel.</p>';

        $competitor = $this->secondConnection();
        $competitor->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $slipped = null;
        $interleaving = $this->connectionThatInterleaves(
            function () use ($competitor, $booking, &$slipped): void {
                // Once: this is one concurrent save, not a retry loop.
                if ($slipped !== null) {
                    return;
                }

                try {
                    $second = $competitor->prepare(
                        'UPDATE rental_booking_document_texts SET body_html = ? WHERE booking_id = ?'
                    );
                    $second->execute(['<p>Écrit par un second gestionnaire.</p>', $booking]);
                    $slipped = true;
                } catch (\PDOException) {
                    $slipped = false;
                }
            }
        );

        $repository = new RentalDocumentRepository($interleaving);
        $repository->saveText($booking, DocumentType::CONTRACT, $text);

        // Re-saved until the question is actually ASKED, and the reason is
        // a clock tick rather than anything about the lock.
        //
        // `saveText()` reaches `alreadyHoldsUnsent()` — the query the probe
        // above watches for — only when the UPDATE changed nothing, since
        // `||` short-circuits on `rowCount() > 0`. And `updated_at` is part
        // of that UPDATE, written to the second:
        //
        //     same text, SAME second      → rowCount 0, question asked
        //     same text, next second      → rowCount 1, question skipped
        //
        // So a re-save that straddles a second boundary observes nothing,
        // and the guard below then reports it as if the fix had regressed.
        // That is what turned `Checks / database-mariadb` red on a pull
        // request touching none of this code, while the same job had been
        // green on the commit before it.
        //
        // Retrying converges immediately rather than by luck: an attempt
        // that skipped the question has just written `updated_at` to the
        // CURRENT second, so the next attempt lands inside it. The
        // concurrent save still happens ONCE — the probe stops itself the
        // moment it has fired.
        $written = false;
        for ($attempt = 0; $attempt < 5 && $slipped === null; $attempt++) {
            $written = $repository->saveText($booking, DocumentType::CONTRACT, $text);
        }

        $this->assertNotNull(
            $slipped,
            'the disambiguation query was never reached in five re-saves, so this test observed nothing'
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
        $this->assertSame($text, $this->repository->findText($booking, DocumentType::CONTRACT));
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
        $booking = $this->booking();
        $this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Un.</p>');
        $this->assertFalse($this->pdo->inTransaction());

        // The disambiguation path too, which is the one that returns
        // early-ish and could forget the commit.
        $this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Un.</p>');
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
        $booking = $this->booking();
        $this->pdo->beginTransaction();

        $this->assertTrue($this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Deux.</p>'));
        $this->assertTrue(
            $this->repository->saveText($booking, DocumentType::CONTRACT, '<p>Deux.</p>'),
            'the unchanged re-save must still be accepted inside a caller\'s transaction'
        );
        $this->assertTrue($this->pdo->inTransaction(), 'the caller\'s transaction was committed for them');

        $this->pdo->commit();
        $this->assertSame('<p>Deux.</p>', $this->repository->findText($booking, DocumentType::CONTRACT));
    }

    /**
     * A real booking for the texts and documents to belong to — the
     * foreign keys want one. Its id is read back rather than assumed:
     * auto-increment carries on from one test to the next.
     */
    private function booking(): int
    {
        $this->pdo->prepare(
            'INSERT INTO rental_bookings
                (asset_id, reference, arrival_date, departure_date,
                 renter_name_encrypted, renter_email_encrypted, renter_email_blind_index, tracking_token_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->assetId,
            'DOC-' . bin2hex(random_bytes(6)),
            '2026-10-01',
            '2026-10-03',
            'x',
            'x',
            str_repeat('a', 64),
            'x',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** A second session on this class's own database. */
    private function secondConnection(): \PDO
    {
        return self::productionEngineConnection($this->databaseName())->getPdo();
    }

    private function databaseName(): string
    {
        return (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
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
        $credentials = self::productionEngineCredentials();

        return new class (
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $credentials['host'],
                $credentials['port'],
                $this->databaseName()
            ),
            $credentials['user'],
            $credentials['password'],
            $probe
        ) extends \PDO {
            public function __construct(
                string $dsn,
                string $user,
                string $password,
                private \Closure $probe
            ) {
                // The attributes `Core\Database\Connection` opens with; this
                // has to be a subclass rather than that connection, since
                // `prepare()` is the seam.
                parent::__construct($dsn, $user, $password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]);
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
