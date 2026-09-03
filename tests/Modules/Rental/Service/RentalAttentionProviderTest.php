<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Modules\InboundMail\Api\InboundMailInterface;
use Modules\Rental\Service\RentalAttentionProvider;
use PHPUnit\Framework\TestCase;

class RentalAttentionProviderTest extends TestCase
{
    private function inboundMailWith(int $waiting): InboundMailInterface
    {
        return new class ($waiting) implements InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            public function __construct(private int $waiting)
            {
            }

            public function countCandidatesFor(string $consumerId): int
            {
                return $consumerId === 'rental' ? $this->waiting : 99;
            }
        };
    }

    public function testNothingWaitingIsNoPoint(): void
    {
        $this->assertSame([], (new RentalAttentionProvider($this->inboundMailWith(0)))->collect(1));
    }

    public function testMessagesWaitingBecomeOnePointThatOpensTheMail(): void
    {
        $points = (new RentalAttentionProvider($this->inboundMailWith(3)))->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('3 messages reçus attendent une décision sur une réservation', $points[0]->title);
        $this->assertSame('/courrier?association=proposed', $points[0]->actionUrl);
    }

    public function testTheSingularReadsAsFrench(): void
    {
        $this->assertSame(
            '1 message reçu attend une décision sur une réservation',
            (new RentalAttentionProvider($this->inboundMailWith(1)))->collect(1)[0]->title
        );
    }
}
