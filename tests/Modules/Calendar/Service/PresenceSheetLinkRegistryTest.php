<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Security\Role;
use Modules\Calendar\Service\PresenceSheetLinkRegistry;
use Modules\Presences\Api\PresenceSheetLink;
use Modules\Presences\Api\PresenceSheetLinkLookupInterface;
use PHPUnit\Framework\TestCase;

class PresenceSheetLinkRegistryTest extends TestCase
{
    public function testEmptyRegistryBehavesExactlyLikePresencesBeingAbsent(): void
    {
        $registry = new PresenceSheetLinkRegistry();

        $this->assertNull($registry->findSheetLink(42, Role::CHIEF, 'akela@unite.be', 3));
    }

    public function testDelegatesToTheProvidedLookup(): void
    {
        $link = new PresenceSheetLink('https://sv025.be/s/K7m2Qa');
        $lookup = $this->createMock(PresenceSheetLinkLookupInterface::class);
        $lookup->expects($this->once())
            ->method('findSheetLink')
            ->with(42, Role::CHIEF, 'akela@unite.be', 3)
            ->willReturn($link);

        $registry = new PresenceSheetLinkRegistry();
        $registry->provide($lookup);

        $this->assertSame($link, $registry->findSheetLink(42, Role::CHIEF, 'akela@unite.be', 3));
    }

    public function testASecondProviderIsRefusedRatherThanSilentlyShadowed(): void
    {
        $registry = new PresenceSheetLinkRegistry();
        $registry->provide($this->createMock(PresenceSheetLinkLookupInterface::class));

        $this->expectException(\LogicException::class);
        $registry->provide($this->createMock(PresenceSheetLinkLookupInterface::class));
    }

    public function testItPassesWhereThePersonalFeedExpectsTheLookup(): void
    {
        // The whole point of the registry implementing the interface: the
        // personal feed keeps its ?PresenceSheetLinkLookupInterface
        // parameter and receives the registry through it unchanged.
        $this->assertInstanceOf(PresenceSheetLinkLookupInterface::class, new PresenceSheetLinkRegistry());
    }
}
