<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use PHPUnit\Framework\TestCase;

/**
 * The supplementary invoice as a document type (#980, chantier decision 2.8):
 * generated and versioned like the invoice, never uploaded, and with no
 * text a manager writes.
 */
final class DocumentTypeTest extends TestCase
{
    public function testTheSupplementaryInvoiceIsGeneratedButHasNoTextToWrite(): void
    {
        $type = DocumentType::SUPPLEMENTARY_INVOICE;

        $this->assertSame('supplementary_invoice', $type->value);
        $this->assertSame('Facture complémentaire', $type->label());
        $this->assertTrue($type->isGenerated());
        $this->assertFalse($type->hasEditableText());
        $this->assertNotContains($type, DocumentType::uploadable());
        $this->assertSame(
            'facture-complementaire-LOC-K7Q2M4-v2.pdf',
            RentalDocument::fileNameFor($type, 'LOC-K7Q2M4', 2)
        );
    }

    public function testOnlyTheContractAndTheInvoiceHaveATextToWrite(): void
    {
        $editable = array_values(array_filter(
            DocumentType::cases(),
            static fn(DocumentType $type): bool => $type->hasEditableText()
        ));

        $this->assertSame([DocumentType::CONTRACT, DocumentType::INVOICE], $editable);
    }

    /** The column is VARCHAR(30) (modules/rental/schema.sql). */
    public function testEveryValueFitsTheColumn(): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->assertLessThanOrEqual(30, strlen($type->value), $type->value);
        }
    }
}
