<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Modules\Finance\Service\FinanceAttentionProvider;
use Modules\InboundMail\Api\InboundMailInterface;
use PHPUnit\Framework\TestCase;

class FinanceAttentionProviderTest extends TestCase
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
                return $consumerId === 'finance' ? $this->waiting : 99;
            }
        };
    }

    public function testNothingWaitingIsNoPoint(): void
    {
        $this->assertSame([], (new FinanceAttentionProvider($this->inboundMailWith(0)))->collect(1));
    }

    public function testReceiptsWaitingBecomeOnePointThatOpensTheReceipts(): void
    {
        $points = (new FinanceAttentionProvider($this->inboundMailWith(2)))->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('2 reçus arrivés par e-mail attendent la confirmation d\'un trésorier', $points[0]->title);
        $this->assertSame('/finance/receipts', $points[0]->actionUrl);
    }
}
