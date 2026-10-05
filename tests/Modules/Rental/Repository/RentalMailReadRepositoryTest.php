<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Repository\RentalMailReadRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * How far each person has read each booking's mail (#720).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalMailReadRepositoryTest extends TestCase
{
    private RentalMailReadRepository $reads;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($pdo);
        $this->reads = new RentalMailReadRepository($pdo);
    }

    public function testANeverOpenedBookingHasNoPosition(): void
    {
        $this->assertSame([], $this->reads->readUpTo(1, [10, 11]));
        $this->assertSame([], $this->reads->readUpTo(1, []));
    }

    public function testReadingMovesThePositionForward(): void
    {
        $this->reads->markRead(10, 1, 4);
        $this->reads->markRead(10, 1, 9);

        $this->assertSame([10 => 9], $this->reads->readUpTo(1, [10, 11]));
    }

    public function testAnOlderPageReloadedNeverBringsABadgeBack(): void
    {
        $this->reads->markRead(10, 1, 9);
        $this->reads->markRead(10, 1, 4);

        $this->assertSame([10 => 9], $this->reads->readUpTo(1, [10]));
    }

    public function testEachPersonHasTheirOwnPosition(): void
    {
        $this->reads->markRead(10, 1, 9);

        $this->assertSame([], $this->reads->readUpTo(2, [10]));
        $this->reads->markRead(10, 2, 3);
        $this->assertSame([10 => 3], $this->reads->readUpTo(2, [10]));
        $this->assertSame([10 => 9], $this->reads->readUpTo(1, [10]));
    }
}
