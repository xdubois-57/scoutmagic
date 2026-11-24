<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Repository;

use Core\File\PdfTextExtractor;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoiceReader;
use Modules\Fees\Repository\InvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Fees\Invoice\SyntheticInvoicePdf;
use Tests\UsesProductionEngine;

#[Group('database')]
final class InvoiceQuantityOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testAnAdvanceWithNegativeQuantityIsStoredOnTheDeclaredSchema(): void
    {
        $pdo = $this->productionEngine();
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2030-2031', '2030-09-01', '2031-08-31')");
        $yearId = (int) $pdo->lastInsertId();
        $pdf = SyntheticInvoicePdf::build([[
            'Facture n° DEMO-701',
            'COT_NORM Cotisation normale 40,00 2 80,00',
            'COT_ACOMPTE Deduction acompte 20,00 -1,00 -20,00',
            'TOTAL A PAYER 60,00',
        ]]);
        $invoice = (new InvoiceReader(new PdfTextExtractor(), new InvoiceParser()))->read($pdf)->invoice;
        self::assertNotNull($invoice);
        $repository = new InvoiceRepository($pdo);

        $id = $repository->store($invoice, $yearId, null, null, [], []);

        self::assertSame(-1, $repository->findLines($id)[1]->quantity);
    }
}
