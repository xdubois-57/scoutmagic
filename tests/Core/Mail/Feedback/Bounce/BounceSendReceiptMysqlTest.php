<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceStateRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The send receipt against the REAL engine, because this defect cannot
 * exist anywhere else.
 *
 * `BounceStateRepositoryTest` runs on SQLite like most of the suite, and
 * SQLite reports an UPDATE's MATCHED rows whether or not the value
 * changed. MySQL and MariaDB report rows CHANGED unless
 * `PDO::MYSQL_ATTR_FOUND_ROWS` is set, which this application does not
 * set — the trap `Core\Config\SettingRepository::replaceIfUnchanged()`
 * already documents for itself. An upsert that decides « no row exists »
 * from `rowCount()` is therefore correct on the test engine and wrong on
 * the production one, and no amount of SQLite coverage would ever say so.
 *
 * Carrying `#[Group('database')]` is not what makes a test reach MySQL —
 * the connection below is. The group is what lets CI's `database-mariadb`
 * job select it.
 */
#[Group('database')]
class BounceSendReceiptMysqlTest extends TestCase
{
    private \PDO $pdo;
    private BounceStateRepository $states;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $this->pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName),
                $user,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    // The same three attributes Core\Database\Connection
                    // opens with. FOUND_ROWS is absent there, and its
                    // absence is exactly what this test exists to cover:
                    // setting it here would test a connection the site
                    // never makes.
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $e) {
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised('No MySQL server configured (TEST_DB_HOST): ' . $e->getMessage());
        }

        $this->pdo->exec('DROP TABLE IF EXISTS mail_send_receipts');
        $this->pdo->exec(
            'CREATE TABLE mail_send_receipts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email_blind_index VARBINARY(64) NOT NULL,
                last_send_at DATETIME NOT NULL,
                UNIQUE KEY idx_msr_blind (email_blind_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->pdo->exec('DROP TABLE IF EXISTS mail_bounce_states');
        $this->pdo->exec(
            'CREATE TABLE mail_bounce_states (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email_encrypted BLOB NOT NULL,
                email_blind_index VARBINARY(64) NOT NULL,
                category VARCHAR(32) NOT NULL,
                severity VARCHAR(16) NOT NULL,
                status_code VARCHAR(16) NOT NULL,
                failures INT NOT NULL DEFAULT 0,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                blocked_at DATETIME NULL,
                notified_code VARCHAR(16) NULL,
                settling_since DATETIME NULL,
                UNIQUE KEY idx_mbs_blind (email_blind_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->states = new BounceStateRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS mail_send_receipts');
        $this->pdo->exec('DROP TABLE IF EXISTS mail_bounce_states');
    }

    /**
     * First: the engine really does behave the way the fix assumes. A
     * test built on a premise nobody checked is the shape that let this
     * defect through in the first place, so the premise is asserted here
     * rather than believed.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $this->pdo->prepare('INSERT INTO mail_send_receipts (email_blind_index, last_send_at) VALUES (?, ?)')
            ->execute(['probe', '2026-09-19 10:00:00']);

        $update = $this->pdo->prepare('UPDATE mail_send_receipts SET last_send_at = ? WHERE email_blind_index = ?');
        $update->execute(['2026-09-19 10:00:00', 'probe']);

        $this->assertSame(
            0,
            $update->rowCount(),
            'if this ever reports 1, FOUND_ROWS got turned on and the upsert below is no longer load-bearing.'
        );
    }

    /**
     * **Two messages to one address inside the same second**, which is
     * routine rather than a corner case: siblings share a parent's
     * mailbox, and a batch walks the recipient rows back to back.
     *
     * `last_send_at` is a `DATETIME`, so the second write stores an
     * identical value. An upsert reading `rowCount()` concludes the row
     * is missing, fires the INSERT, and the unique index raises a
     * `PDOException` — which `SendBatchHandler` does not catch, since it
     * catches `MailException` only, so it escapes and abandons a mailing
     * that is already half delivered.
     */
    public function testTwoSendsInsideTheSameSecondDoNotCollide(): void
    {
        $sameSecond = new \DateTimeImmutable('2026-09-19 10:00:00');

        // Vouched for, because this test is about the upsert and not
        // about who the site writes to: the `isOnFile()` lookup that
        // normally answers that reads two core tables this fixture
        // deliberately does not build, and stubbing them would put a
        // second thing under test.
        $this->states->recordSend('parent@exemple.be', $sameSecond, true);
        $this->states->recordSend('parent@exemple.be', $sameSecond, true);
        // And the same mailbox reached under a different spelling, which
        // is how two siblings' rows actually differ.
        $this->states->recordSend('PARENT@Exemple.BE', $sameSecond, true);

        $this->assertSame(
            '2026-09-19 10:00:00',
            $this->states->lastSendAt('parent@exemple.be')?->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mail_send_receipts')->fetchColumn(),
            'one address is one receipt, however many messages went to it.'
        );
    }

    /** And a later send still moves the date forward. */
    public function testALaterSendReplacesTheStoredDate(): void
    {
        $this->states->recordSend('parent@exemple.be', new \DateTimeImmutable('2026-09-19 10:00:00'), true);
        $this->states->recordSend('parent@exemple.be', new \DateTimeImmutable('2026-09-20 11:30:00'), true);

        $this->assertSame(
            '2026-09-20 11:30:00',
            $this->states->lastSendAt('parent@exemple.be')?->format('Y-m-d H:i:s')
        );
    }
}
