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
use Tests\UsesProductionEngine;

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
 * The tables are the ones the migration builds from `schema/core.sql`,
 * and the connection is `Core\Database\Connection`'s, through
 * `Tests\UsesProductionEngine`: a receipt table written out here, or a
 * `\PDO` opened with attributes of its own, would judge a schema and a
 * connection the site never has — and FOUND_ROWS, the one attribute this
 * test is about, is precisely the kind a hand-built connection gets wrong.
 *
 * Carrying `#[Group('database')]` is not what makes a test reach MySQL —
 * the fixture is. The group is what lets CI's `database-mariadb` job
 * select it.
 */
#[Group('database')]
class BounceSendReceiptMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private BounceStateRepository $states;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->states = new BounceStateRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
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
        // normally answers that reads member and account tables which are
        // empty here, and filling them would put a second thing under
        // test.
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
