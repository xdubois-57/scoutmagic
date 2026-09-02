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
            // The shape a camp site's own contract uses — labelled ends,
            // two-digit year, times attached. Reading only « du … au … »
            // is why a real booking contract said nothing to this module.
            'arrival and departure, two-digit year' => [
                "Arrivée:18-09-26  16:30:00\tDépart: 20-09-26  16:00:00",
                '2026-09-18',
                '2026-09-20',
            ],
            'arrival and departure, four-digit year' => [
                'Arrivee : 18/09/2026 Depart : 20/09/2026',
                '2026-09-18',
                '2026-09-20',
            ],
            'arrival and departure with « le »' => [
                'Arrivée le 18.09.2026, départ le 20.09.2026',
                '2026-09-18',
                '2026-09-20',
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
            // Two dates are not an arrival and a departure just because
            // there are two of them.
            'two dates with no labels' => ['Facture du 18-09-26, échéance 20-09-26.'],
            'labels with no dates' => ['Arrivée le matin, départ en soirée.'],
            'a departure before its arrival' => ['Arrivée : 20-09-26 Départ : 18-09-26'],
            'a day that does not exist' => ['Arrivée : 31-02-26 Départ : 20-09-26'],
            'labels three lines apart' => [
                'Arrivée : 18-09-26' . str_repeat(' — merci de votre confiance', 5) . ' Départ : 20-09-26',
            ],
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
        // A quote naming a deposit and a BALANCE states neither total: two
        // figures that both survive are two figures a human has to look at.
        $this->assertNull($this->reader->readPriceCents('Acompte de 500 € puis solde de 1 950 €.'));
        // And two real prices remain two real prices.
        $this->assertNull($this->reader->readPriceCents('Le terrain coûte 2450 € ou 2600 € selon la période.'));
    }

    public function testANumberWithoutACurrencyIsNotAPrice(): void
    {
        $this->assertNull($this->reader->readPriceCents('Nous pouvons accueillir 65 personnes.'));
        $this->assertNull($this->reader->readPriceCents('Téléphone : 081 58 00 00'));
    }

    // ── A contract always states more than one figure ───────────────────

    /**
     * The rule was « exactly ONE amount in the whole text », written for a
     * message body — and right there. But the reading now sees the
     * CONTRACT, and a contract always states at least a total and a
     * deposit, so the rule refused every single one of them, including the
     * documents that state their price most plainly.
     *
     * @return array<string, array{string, ?int}>
     */
    public static function pricesInDocuments(): array
    {
        return [
            'a deposit named after the total' => ['Total 2450 €, acompte 500 €.', 245000],
            'a deposit named before its figure' => ['Le prix est de 2450 €. Acompte : 500 €.', 245000],
            'the label after the figure' => ['1.468,80 €/Forfait + charges', 146880],
            'a security deposit and VAT' => ['2450 € tout compris, caution 300 €, TVA 210 €.', 245000],
            'the same deposit said twice' => [
                'Réception de l\'acompte : 490,00 €. Le montant de l\'acompte est de 490 euros.',
                null,
            ],
            'a discount is not a price' => ['Prix 2450 €, remise 100 €.', 245000],
            'a trailing label' => ['Forfait 2450 € TTC', 245000],
            'a trailing label that disqualifies' => ['2450 € de caution', null],
            // The line below must not reach back up: normalise() has
            // already turned the break into a space, and a window of
            // twenty-five threw the forfait away because of it.
            'a deposit on the next line' => [
                "Forfait : 1.468,80 EUR\nReception de l acompte : 490,00 EUR",
                146880,
            ],
            // Nothing survives: a balance and a deposit describe a total
            // stated somewhere this text does not reach.
            'only a deposit and a balance' => ['Acompte 500 €, solde 1950 €.', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pricesInDocuments')]
    public function testAnAmountThatIsNotTheStaysPriceIsEliminated(string $text, ?int $expected): void
    {
        $this->assertSame($expected, $this->reader->readPriceCents($text));
    }

    public function testEachAmountIsJudgedOnItsOwnLabel(): void
    {
        // The trap this rule fell into first: a window wide enough to see
        // the neighbour's word threw away the very figure it exists to
        // find. « acompte » belongs to 500, not to 2450.
        $this->assertSame(245000, $this->reader->readPriceCents('Total 2450 €, acompte 500 €.'));
        $this->assertSame(50000, $this->reader->readPriceCents('Solde 2450 €, à payer 500 €.'));
    }

    // ── How many people the stay is for ─────────────────────────────────

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function participantCounts(): array
    {
        return [
            'labelled before, as a contract writes it' => ['Nombre prévu:250', 250],
            'labelled after' => ['250 participants', 250],
            'in a sentence' => ['Nous serons 45 personnes.', 45],
            'nombre de participants' => ['Nombre de participants : 32', 32],
            // A contract is full of integers, and only the word next to one
            // separates a count from a postcode.
            'a postcode' => ['1653 Dworp', null],
            'a street number' => ['Rue de Dublin 21 - 1050 Bruxelles', null],
            'a year' => ['Bruxelles, le 30 août 2024', null],
            'two different counts' => ['40 participants, puis 60 personnes', null],
            'a count nobody camps with' => ['9000 personnes', null],
            'one person is a typo' => ['1 personne', null],
            'nothing at all' => ['Merci de votre message.', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('participantCounts')]
    public function testTheParticipantCountNeedsItsLabel(string $text, ?int $expected): void
    {
        $this->assertSame($expected, $this->reader->readParticipantCount($text));
    }

    public function testTheSameCountSaidTwiceIsStillOneCount(): void
    {
        $this->assertSame(
            250,
            $this->reader->readParticipantCount('Nombre prévu:250. Merci de confirmer les 250 participants.')
        );
    }
}
