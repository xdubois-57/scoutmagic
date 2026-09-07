<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Modules\Calendar\Service\PresenceEventCleanupRegistry;
use Modules\Presences\Api\PresenceEventCleanupInterface;
use PHPUnit\Framework\TestCase;

class PresenceEventCleanupRegistryTest extends TestCase
{
    public function testEmptyRegistryBehavesExactlyLikePresencesBeingAbsent(): void
    {
        $registry = new PresenceEventCleanupRegistry();

        $registry->forgetEvent(42);

        $this->assertTrue(true, 'Deleting an event with presences off is a no-op, never an error.');
    }

    public function testDelegatesToTheProvidedCleanup(): void
    {
        $cleanup = $this->createMock(PresenceEventCleanupInterface::class);
        $cleanup->expects($this->once())->method('forgetEvent')->with(42);

        $registry = new PresenceEventCleanupRegistry();
        $registry->provide($cleanup);

        $registry->forgetEvent(42);
    }

    public function testASecondProviderIsRefusedRatherThanSilentlyShadowed(): void
    {
        $registry = new PresenceEventCleanupRegistry();
        $registry->provide($this->createMock(PresenceEventCleanupInterface::class));

        $this->expectException(\LogicException::class);
        $registry->provide($this->createMock(PresenceEventCleanupInterface::class));
    }

    public function testItPassesWhereTheCalendarExpectsTheCleanup(): void
    {
        // The whole point of the registry implementing the interface:
        // CalendarEventService keeps its ?PresenceEventCleanupInterface
        // parameter and receives the registry through it unchanged.
        $this->assertInstanceOf(PresenceEventCleanupInterface::class, new PresenceEventCleanupRegistry());
    }
}
