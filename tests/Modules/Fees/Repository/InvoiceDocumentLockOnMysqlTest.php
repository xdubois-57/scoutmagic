<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Repository;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\PdfTextExtractor;
use Core\Import\RosterSnapshotRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Badge\MemberBadgeRepository;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoiceReader;
use Modules\Fees\Repository\InvoiceMemberMatchRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Modules\Fees\Service\InvoiceImportService;
use Modules\Fees\Value\InvoiceImportOutcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * One import of a given document at a time, on the engine that has the
 * lock (#1548 review): the lookup across years and the write must not
 * interleave with another import of the same PDF, or both find nothing
 * and both store it — the unique index is per year. SQLite has no
 * advisory lock, so only the production engine can show the loser stand
 * down.
 */
#[Group('database')]
final class InvoiceDocumentLockOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testAnImportOfADocumentAnotherConnectionIsImportingStandsDownAndStoresNothing(): void
    {
        $pdo = $this->productionEngine();
        $year = (new ScoutYearService($pdo))->ensureYear('2025-2026');
        $pdf = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/pdf/federation_invoice_sample.pdf');

        $other = self::productionEngineConnection((string) $pdo->query('SELECT DATABASE()')->fetchColumn())->getPdo();
        $this->assertTrue((new InvoiceRepository($other))->lockDocumentNumber('F2026/000123'));

        try {
            $outcome = $this->service($pdo)->import($pdf, $year, null);
        } finally {
            (new InvoiceRepository($other))->unlockDocumentNumber('F2026/000123');
        }

        $this->assertSame(InvoiceImportOutcome::IN_PROGRESS, $outcome->status);
        $this->assertSame('F2026/000123', $outcome->documentNumber);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
        // Released once the first import is done: the next one may go.
        $this->assertTrue((new InvoiceRepository($pdo))->lockDocumentNumber('F2026/000123'));
        (new InvoiceRepository($pdo))->unlockDocumentNumber('F2026/000123');
    }

    private function service(\PDO $pdo): InvoiceImportService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new InvoiceImportService(
            new InvoiceReader(new PdfTextExtractor(), new InvoiceParser()),
            new InvoiceRepository($pdo),
            new InvoiceMemberMatchRepository($pdo, $encryption),
            new RosterSnapshotRepository($pdo),
            new SectionService(
                new SectionRepository(Connection::withPdo($pdo)),
                new MemberProfileRepository(Connection::withPdo($pdo), $encryption, new MemberBadgeRepository($pdo))
            ),
            new JournalService(new JournalRepository($pdo)),
            new ScoutYearService($pdo)
        );
    }
}
