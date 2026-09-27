<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Security\Role;
use Modules\Calendar\Service\RetroEventLinkRegistry;
use Modules\Retro\Api\RetroEventLinkLookupInterface;
use Modules\Retro\Api\RetroLinkSummary;
use PHPUnit\Framework\TestCase;

// This class shares its doubles between tests (set up once, or built by
// a helper): some tests set expectations on them, the others only need
// their answers, and PHPUnit would report each of those as a mock with
// no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class RetroEventLinkRegistryTest extends TestCase
{
    public function testEmptyRegistryBehavesExactlyLikeRetroBeingAbsent(): void
    {
        $registry = new RetroEventLinkRegistry();

        $this->assertFalse($registry->hasLinkedBoard(42));
        $this->assertNull($registry->findLinkedBoardLink(42, Role::CHIEF, 'chef@unite.be', 3));
    }

    public function testDelegatesToTheProvidedLookup(): void
    {
        $summary = new RetroLinkSummary('/r/tok', 'Rétro camp');
        $lookup = $this->createMock(RetroEventLinkLookupInterface::class);
        $lookup->expects($this->once())->method('hasLinkedBoard')->with(42)->willReturn(true);
        $lookup->expects($this->once())->method('findLinkedBoardLink')->with(42, Role::CHIEF, 'chef@unite.be', 3)->willReturn($summary);

        $registry = new RetroEventLinkRegistry();
        $registry->provide($lookup);

        $this->assertTrue($registry->hasLinkedBoard(42));
        $this->assertSame($summary, $registry->findLinkedBoardLink(42, Role::CHIEF, 'chef@unite.be', 3));
    }

    public function testASecondProviderIsRefusedRatherThanSilentlyShadowed(): void
    {
        $registry = new RetroEventLinkRegistry();
        $registry->provide($this->createMock(RetroEventLinkLookupInterface::class));

        $this->expectException(\LogicException::class);
        $registry->provide($this->createMock(RetroEventLinkLookupInterface::class));
    }

    public function testItPassesWhereACalendarServiceExpectsTheLookup(): void
    {
        // The whole point of the registry implementing the interface: the
        // calendar's services keep their ?RetroEventLinkLookupInterface
        // parameters and receive the registry through them unchanged.
        $this->assertInstanceOf(RetroEventLinkLookupInterface::class, new RetroEventLinkRegistry());
    }
}
