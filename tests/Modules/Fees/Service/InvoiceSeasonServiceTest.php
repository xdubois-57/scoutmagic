<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Modules\Fees\Invoice\ParsedInvoice;
use Modules\Fees\Repository\InvoiceRepository;
use Modules\Fees\Service\InvoiceSeasonService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * The season read in the order it happened — and the one subtlety of the
 * running total: **each document's own TOTAL already nets out its own
 * negative lines.**
 *
 * The November deposit is deducted inside January's final invoice by a
 * negative line, so adding the deposit to the final's *gross* would count
 * that money twice. Summing the totals is what gives what the unit
 * actually paid.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceSeasonServiceTest extends TestCase
{
    private \PDO $pdo;
    private InvoiceRepository $invoices;
    private InvoiceSeasonService $service;
    private int $scoutYearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->invoices = new InvoiceRepository($this->pdo);
        $this->service = new InvoiceSeasonService($this->invoices);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
    }

    private function store(string $number, ?string $date, int $totalCents, ?int $scoutYearId = null): void
    {
        $this->invoices->store(
            new ParsedInvoice($number, $date, [], $totalCents, null, null, null, 0),
            $scoutYearId ?? $this->scoutYearId,
            null,
            null,
            [],
            []
        );
    }

    public function testAnEmptySeasonIsAnEmptySequenceAndAZeroTotal(): void
    {
        $this->assertSame([], $this->service->sequence($this->scoutYearId));
        $this->assertSame(0, $this->service->netTotalCents($this->scoutYearId));
    }

    public function testTheSequenceRunsOldestFirstWithACumulativeTotal(): void
    {
        // November's deposit, then a final invoice whose own total already
        // has that deposit deducted inside it.
        $this->store('F2025/000900', '2025-11-04', 30000);
        $this->store('F2026/000123', '2026-01-08', 106900);
        $this->store('F2026/000456', '2026-02-11', 4200);

        $sequence = $this->service->sequence($this->scoutYearId);

        $this->assertSame(
            ['F2025/000900', 'F2026/000123', 'F2026/000456'],
            array_map(static fn(array $row): string => $row['invoice']->documentNumber, $sequence)
        );
        $this->assertSame(
            [30000, 136900, 141100],
            array_map(static fn(array $row): int => $row['running_total_cents'], $sequence)
        );
    }

    /**
     * A régularisation in the unit's favour lowers the running total: the
     * sign is the document's own and is never hidden.
     */
    public function testACreditNoteLowersTheRunningTotal(): void
    {
        $this->store('F2026/000123', '2026-01-08', 106900);
        $this->store('F2026/000789', '2026-06-20', -12400);

        $sequence = $this->service->sequence($this->scoutYearId);

        $this->assertSame([106900, 94500], array_map(static fn(array $row): int => $row['running_total_cents'], $sequence));
        $this->assertSame(94500, $this->service->netTotalCents($this->scoutYearId));
    }

    public function testTheRunningTotalEndsOnTheSeasonTotal(): void
    {
        $this->store('F2025/000900', '2025-11-04', 30000);
        $this->store('F2026/000123', '2026-01-08', 106900);

        $sequence = $this->service->sequence($this->scoutYearId);

        $this->assertSame(
            $this->service->netTotalCents($this->scoutYearId),
            $sequence[array_key_last($sequence)]['running_total_cents']
        );
    }

    public function testAnotherSeasonIsNotInTheSequence(): void
    {
        $this->store('F2025/000001', '2025-01-08', 5000, $this->otherYearId);
        $this->store('F2026/000123', '2026-01-08', 106900);

        $this->assertCount(1, $this->service->sequence($this->scoutYearId));
        $this->assertSame(106900, $this->service->netTotalCents($this->scoutYearId));
    }
}
