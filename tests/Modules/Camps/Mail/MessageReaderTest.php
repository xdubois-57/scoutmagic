<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Modules\Camps\Mail\MessageReader;
use PHPUnit\Framework\TestCase;

/**
 * What this reads either fills an empty field on its own or argues with
 * a value a chief typed. Both deserve a high bar, so most of these tests
 * are about what it refuses.
 */
class MessageReaderTest extends TestCase
{
    private MessageReader $reader;

    protected function setUp(): void
    {
        $this->reader = new MessageReader();
    }

    /**
     * @dataProvider readableRanges
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('readableRanges')]
    public function testARangeStatedPlainlyIsRead(string $text, string $start, string $end): void
    {
        $this->assertSame(['start' => $start, 'end' => $end], $this->reader->readDateRange($text));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function readableRanges(): array
    {
        return [
            'one month' => ['Le terrain est libre du 12 au 19 juillet 2028.', '2028-07-12', '2028-07-19'],
            'two months' => ['du 30 avril au 3 mai 2026', '2026-04-30', '2026-05-03'],
            'across new year' => ['du 30 décembre au 2 janvier 2027', '2026-12-30', '2027-01-02'],
            'numeric' => ['Réservation du 12/07/2028 au 19/07/2028 confirmée.', '2028-07-12', '2028-07-19'],
            'accents missing' => ['du 1 au 8 aout 2027', '2027-08-01', '2027-08-08'],
            'inside a longer sentence' => [
                "Bonjour,\n\nnous confirmons que le pré est réservé du 5 au 15 juillet 2030 pour votre unité.",
                '2030-07-05',
                '2030-07-15',
            ],
        ];
    }

    /**
     * @dataProvider unreadableRanges
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableRanges')]
    public function testAnythingLessThanAPlainRangeIsRefused(string $text): void
    {
        $this->assertNull($this->reader->readDateRange($text));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableRanges(): array
    {
        return [
            // Far more often a meeting, an invoice or a deadline than the
            // day a camp starts.
            'a single date' => ['Rendez-vous le 12 juillet 2028.'],
            'a month with no days' => ['Nous serons complets en juillet 2028.'],
            'an impossible date' => ['du 31 au 32 février 2028'],
            'a backwards range' => ['du 19 au 12 juillet 2028'],
            'a made-up month' => ['du 12 au 19 juilletembre 2028'],
            'nothing at all' => ['Merci de votre message.'],
        ];
    }

    public function testASinglePriceIsRead(): void
    {
        $this->assertSame(245000, $this->reader->readPriceCents('Le montant total est de 2 450 €.'));
        $this->assertSame(245050, $this->reader->readPriceCents('Total : 2450,50 EUR'));
        $this->assertSame(48000, $this->reader->readPriceCents('480 euros pour le week-end'));
    }

    public function testTwoPricesMeanNoReadingAtAll(): void
    {
        // A quote naming a deposit and a total is precisely the message
        // where guessing wrong is most expensive — and the chief reading
        // it has the document in front of them anyway.
        $this->assertNull($this->reader->readPriceCents('Acompte de 500 € puis solde de 1 950 €.'));
    }

    public function testANumberWithoutACurrencyIsNotAPrice(): void
    {
        $this->assertNull($this->reader->readPriceCents('Nous pouvons accueillir 65 personnes.'));
        $this->assertNull($this->reader->readPriceCents('Téléphone : 081 58 00 00'));
    }
}
