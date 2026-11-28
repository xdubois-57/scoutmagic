<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A mailing's message kept in the core's deferred queue (issue #1454), on
 * the engine production runs.
 *
 * What the engine decides here and SQLite does not:
 * - `status` is an ENUM, and a strict server refuses a value outside it —
 *   SQLite's test table is TEXT and stores 'deferred' whatever the schema
 *   says, so only this class can say the migration really widened it;
 * - `sent_at` is filled in by the server (`CURRENT_TIMESTAMP`) when the
 *   drain settles the message as sent;
 * - the settling UPDATEs are guarded by their WHERE clause alone, and the
 *   statuses they must leave alone are checked here rather than trusted;
 * - « Renvoyer » decides on `rowCount()`, which MySQL reports as rows
 *   CHANGED rather than matched — so whether the guarded write reports
 *   one row exactly when it moved one is the engine's answer to give.
 */
#[Group('database')]
final class DeferredDeliveriesOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private DeliveryRepository $deliveries;
    private int $emailId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->deliveries = new DeliveryRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOU', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'LOU01', 'Meute')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet', '<p>x</p>', {$sectionId}, 'default_active_members', 'sending')"
        );
        $this->emailId = (int) $this->pdo->lastInsertId();
    }

    /**
     * The premise: the migration built the column with the new value, and
     * this server refuses one outside the list — otherwise a green run
     * below would prove nothing about an installed site.
     */
    public function testTheStatusColumnIsAnEnumThatKnowsDeferredAndRefusesTheRest(): void
    {
        $type = (string) $this->pdo->query(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mass_mail_deliveries' AND COLUMN_NAME = 'status'"
        )->fetchColumn();
        $this->assertStringStartsWith('enum(', $type);
        $this->assertStringContainsString("'deferred'", $type);

        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
        $id = $this->deliveries->create($this->emailId, 'a@test.be');
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE mass_mail_deliveries SET status = ? WHERE id = ?')->execute(['parked', $id]);
    }

    public function testADeferredMessageIsNeitherSentNorPendingAndKeepsTheMailingUnsettled(): void
    {
        $id = $this->deliveries->create($this->emailId, 'a@test.be');

        $this->deliveries->recordDeferred($id);

        $delivery = $this->deliveries->findById($id);
        $this->assertSame(Delivery::STATUS_DEFERRED, $delivery?->status);
        $this->assertNull($delivery->sentAt);
        $this->assertSame(1, $delivery->attempts);
        $this->assertFalse($this->deliveries->hasAnyPending(), 'the batch chain has nothing left to send');
        $this->assertTrue($this->deliveries->hasUnsettled($this->emailId), 'but the mailing is not finished');
        $this->assertSame(
            ['pending' => 0, 'sent' => 0, 'error' => 0, 'unknown' => 0, 'deferred' => 1, 'total' => 1],
            $this->deliveries->countGroupedByStatus($this->emailId)
        );
    }

    public function testTheDrainSettlesADeferredMessageAsSentWithTheServersClock(): void
    {
        $id = $this->deliveries->create($this->emailId, 'a@test.be');
        $this->deliveries->recordDeferred($id);

        $this->deliveries->settleDeferredAsSent($id);

        $delivery = $this->deliveries->findById($id);
        $this->assertSame(Delivery::STATUS_SENT, $delivery?->status);
        $this->assertNotNull($delivery->sentAt);
        $this->assertLessThan(120, abs(time() - (int) strtotime((string) $delivery->sentAt)));
        $this->assertSame(1, $delivery->attempts, 'the attempt was counted when it was deferred');
        $this->assertFalse($this->deliveries->hasUnsettled($this->emailId));
    }

    public function testAnAbandonedMessageBecomesAnErrorWithItsReason(): void
    {
        $id = $this->deliveries->create($this->emailId, 'a@test.be');
        $this->deliveries->recordDeferred($id);

        $this->deliveries->settleDeferredAsAbandoned($id, 'Abandonné : délai dépassé');

        $delivery = $this->deliveries->findById($id);
        $this->assertSame(Delivery::STATUS_ERROR, $delivery?->status);
        $this->assertSame('Abandonné : délai dépassé', $delivery->errorMessage);
        $this->assertFalse($this->deliveries->hasUnsettled($this->emailId));
    }

    /**
     * A message « Renvoyer » put back in the batch queue, or one already
     * sent, is not the queue's to settle any more.
     */
    public function testSettlingLeavesAPendingOrASentMessageAlone(): void
    {
        $pending = $this->deliveries->create($this->emailId, 'a@test.be');
        $sent = $this->deliveries->create($this->emailId, 'b@test.be');
        $this->deliveries->recordSendSuccess($sent);

        $this->deliveries->settleDeferredAsSent($pending);
        $this->deliveries->settleDeferredAsAbandoned($sent, 'Abandonné');

        $this->assertSame(Delivery::STATUS_PENDING, $this->deliveries->findById($pending)?->status);
        $this->assertSame(Delivery::STATUS_SENT, $this->deliveries->findById($sent)?->status);
        $this->assertNull($this->deliveries->findById($sent)?->errorMessage);
    }

    /** One relaunched after its abandonment, and then sent, does become sent. */
    public function testARelaunchedAbandonedMessageThatLeavesBecomesSent(): void
    {
        $id = $this->deliveries->create($this->emailId, 'a@test.be');
        $this->deliveries->recordDeferred($id);
        $this->deliveries->settleDeferredAsAbandoned($id, 'Abandonné');

        $this->deliveries->settleDeferredAsSent($id);

        $delivery = $this->deliveries->findById($id);
        $this->assertSame(Delivery::STATUS_SENT, $delivery?->status);
        $this->assertNull($delivery->errorMessage);
    }

    /**
     * **« Renvoyer » is refused by the write, not by a snapshot** (issue
     * #1454): a message that became 'deferred' after the service read it
     * is left in the core's hands and the write says so; any other status
     * is put back and reported as one row; and a deferred message whose
     * outcome was lost is put back when the caller says so.
     */
    public function testResendIsGuardedByTheWriteAndReportsWhetherItMovedTheRow(): void
    {
        $deferred = $this->deliveries->create($this->emailId, 'a@test.be');
        $this->deliveries->recordDeferred($deferred);
        $error = $this->deliveries->create($this->emailId, 'b@test.be');
        $this->deliveries->recordSendFailure($error, 'Erreur');

        $this->assertFalse($this->deliveries->resend($deferred));
        $this->assertSame(Delivery::STATUS_DEFERRED, $this->deliveries->findById($deferred)?->status);
        $this->assertSame(1, $this->deliveries->findById($deferred)?->attempts, 'nothing was written');

        $this->assertTrue($this->deliveries->resend($error));
        $this->assertSame(Delivery::STATUS_PENDING, $this->deliveries->findById($error)?->status);
        $this->assertTrue($this->deliveries->resend($error), 'already pending: attempts still moves, one row');

        $this->assertTrue($this->deliveries->resend($deferred, lostDeferred: true));
        $this->assertSame(Delivery::STATUS_PENDING, $this->deliveries->findById($deferred)?->status);

        $this->assertFalse($this->deliveries->resend(999999), 'a message that does not exist');
    }

    /**
     * Two « Renvoyer » that read the same lost outcome (#1561 review): the
     * first puts the message back and moves `attempts`; a batch defers it
     * again; the second, written against the row it read, matches nothing
     * and cannot put a message the queue holds back to 'pending'.
     */
    public function testASecondResendOfTheSameLostOutcomeIsRefusedByTheWrite(): void
    {
        $id = $this->deliveries->create($this->emailId, 'a@test.be');
        $this->deliveries->recordDeferred($id);
        $read = (int) $this->deliveries->findById($id)?->attempts;

        $this->assertTrue($this->deliveries->resend($id, lostDeferred: true, expectedAttempts: $read));
        $this->deliveries->recordDeferred($id);

        $this->assertFalse($this->deliveries->resend($id, lostDeferred: true, expectedAttempts: $read));
        $this->assertSame(Delivery::STATUS_DEFERRED, $this->deliveries->findById($id)?->status);
    }
}
