<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\ReceiptMailSourceRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * What finance remembers of each inbound attachment, and which memories end
 * a pass over it (#1342, #1406).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ReceiptMailSourceRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ReceiptMailSourceRepository $sources;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->sources = new ReceiptMailSourceRepository($this->pdo);
    }

    public function testAPersonsRefusalIsNeitherThroughFinanceNorSettled(): void
    {
        $this->sources->record(10, 1, null, ReceiptMailSourceRepository::REASON_PERSON_REFUSED);

        $this->assertFalse($this->sources->has(10), 'a later pass may still file it');
        $this->assertFalse($this->sources->settled(10), 'and the catch-up is not finished with it');
    }

    public function testARefusalForTheStoreIsStillTerminal(): void
    {
        $this->sources->record(11, 1, null, ReceiptMailSourceRepository::REASON_NOT_FILED);

        $this->assertTrue($this->sources->has(11));
        $this->assertTrue($this->sources->settled(11));
    }

    public function testAPersonsRefusalNeverReplacesWhatIsAlreadyKnown(): void
    {
        $this->sources->record(12, 1, null, ReceiptMailSourceRepository::REASON_NOT_FILED);
        $this->sources->record(12, 1, null, ReceiptMailSourceRepository::REASON_PERSON_REFUSED);

        $this->assertSame(
            ReceiptMailSourceRepository::REASON_NOT_FILED,
            $this->sources->forInboundAttachments([12])[12]['reason']
        );
    }

    public function testAFilingReplacesAPersonsRefusal(): void
    {
        $this->pdo->exec("INSERT INTO finance_attachments (id, file_id, mime_type, original_filename, status, uploaded_at)
            VALUES (5, 1, 'application/pdf', 'a.pdf', 'active', '2026-09-01 00:00:00')");
        $this->sources->record(13, 1, null, ReceiptMailSourceRepository::REASON_PERSON_REFUSED);

        $this->sources->record(13, 1, 5, ReceiptMailSourceRepository::REASON_MANUAL);

        $row = $this->sources->forInboundAttachments([13])[13];
        $this->assertSame(ReceiptMailSourceRepository::REASON_MANUAL, $row['reason']);
        $this->assertSame(5, $row['attachment_id']);
    }

    /**
     * The catch-up gives up on an unreadable file after MAX_UNREADABLE_TRIES
     * runs, which counts the row's attempts. A person's refusal has no
     * attempts to advance: left alone, an attachment that later turns out
     * unreadable would be walked on every run, for ever.
     */
    public function testAPersonsRefusalOnAFileThatIsUnreadableEventuallySettles(): void
    {
        $this->sources->record(14, 1, null, ReceiptMailSourceRepository::REASON_PERSON_REFUSED);

        for ($run = 1; $run <= ReceiptMailSourceRepository::MAX_UNREADABLE_TRIES; $run++) {
            $this->assertFalse($this->sources->settled(14), "run $run: still being tried");
            $this->sources->markUnreadable(14, 1);
        }

        $this->assertTrue($this->sources->settled(14), 'the walk leaves it after its tries');
        $this->assertSame(
            ReceiptMailSourceRepository::REASON_UNREADABLE,
            $this->sources->forInboundAttachments([14])[14]['reason']
        );
    }

    public function testAnUnreadableFileCountsItsTriesAsBefore(): void
    {
        $this->sources->markUnreadable(15, 1);
        $this->sources->markUnreadable(15, 1);

        $this->assertFalse($this->sources->settled(15));
        $this->sources->markUnreadable(15, 1);
        $this->assertTrue($this->sources->settled(15));
    }
}
