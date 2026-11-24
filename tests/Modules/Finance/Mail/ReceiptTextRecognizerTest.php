<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use Modules\Finance\Mail\ReceiptTextRecognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The local filter of #1342 (decision 2): a word a receipt prints AND an
 * amount stated as money, both, before anything reaches the sorting pile.
 */
class ReceiptTextRecognizerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function receipts(): array
    {
        return [
            'the fixture invoice' => ["FACTURE Delhaize\nDate: 01/09/2026\nTotal: 42.50 EUR"],
            'a till ticket with no currency on its total line' => ["COLRUYT\nPain 2,10\nTOTAL 12,50\nBancontact"],
            'a euro sign before the amount' => ["Ticket de caisse\n€ 8,90"],
            'thousands with a dot' => ["Facture n° 2026-118\nMontant à payer : 1.234,00 €"],
            'accents and capitals folded' => ['REÇU — 15,00 EUR'],
            'Dutch' => ["Kassaticket\nTotaal 23,40"],
            'Dutch VAT and an amount' => ['Factuur 77 — BTW 21 % — 121,00 EUR'],
            'English' => ['Invoice #42 — Amount due 19.99'],
            'a sum ending a sentence' => ['Ticket de caisse : total 12,50.'],
            'a sum with a Belgian dash' => ['Facture : 12,50.- EUR'],
        ];
    }

    #[DataProvider('receipts')]
    public function testAReceiptIsRecognised(string $text): void
    {
        $this->assertTrue((new ReceiptTextRecognizer())->looksLikeAReceipt($text));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notReceipts(): array
    {
        return [
            'nothing at all' => [''],
            'a word and no amount' => ['Le total des inscrits est de 42.'],
            'an amount and no word' => ['Week-end à 12,50 € par enfant, merci de prévoir un pique-nique.'],
            // The booking contract camps reads: a price, no receipt word.
            'a booking contract' => ["CENTRE DE CAMP LE GRAND PRE\nArrivee: 18-09-26\nForfait: 1.468,80 EUR"],
            'a total far from any amount, and an amount with no currency' => ["Total des présences\n\nTaille 12,50"],
            'a word inside another word' => ['Nous sommes totalement ravis : 12,50 points.'],
            'a date is not a sum' => ['Total des présences au 12.09.2026 : 42'],
            'nor with slashes or hyphens' => ["Facture du 12/09/2026\nTotal au 01-09-2026"],
        ];
    }

    #[DataProvider('notReceipts')]
    public function testWhatIsNotAReceiptStaysOut(string $text): void
    {
        $this->assertFalse((new ReceiptTextRecognizer())->looksLikeAReceipt($text));
    }
}
