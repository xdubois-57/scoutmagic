<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Security\Role;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;
use Modules\Gallery\Service\DelegatedAlbumAccessRegistry;
use PHPUnit\Framework\TestCase;

class DelegatedAlbumAccessRegistryTest extends TestCase
{
    public function testDeniesWhenNoCheckerIsRegistered(): void
    {
        $registry = new DelegatedAlbumAccessRegistry([]);

        $this->assertFalse($registry->isAllowed('some_owner_type', 1, Role::CHIEF, []));
    }

    public function testDeniesWhenNoCheckerSupportsTheOwnerType(): void
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->method('supports')->willReturn(false);
        $checker->expects($this->never())->method('isAllowed');
        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertFalse($registry->isAllowed('some_owner_type', 1, Role::CHIEF, []));
    }

    public function testDelegatesToTheCheckerThatSupportsTheOwnerType(): void
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->expects($this->once())->method('supports')->with('some_owner_type')->willReturn(true);
        $checker->expects($this->once())->method('isAllowed')->with(42, Role::CHIEF, [1, 2])->willReturn(true);
        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertTrue($registry->isAllowed('some_owner_type', 42, Role::CHIEF, [1, 2]));
    }

    public function testReturnsFalseWhenTheSupportingCheckerDenies(): void
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->expects($this->once())->method('supports')->willReturn(true);
        $checker->expects($this->once())->method('isAllowed')->willReturn(false);
        $registry = new DelegatedAlbumAccessRegistry([$checker]);

        $this->assertFalse($registry->isAllowed('some_owner_type', 42, Role::CHIEF, []));
    }

    public function testTheFirstMatchingCheckerWins(): void
    {
        $first = $this->createMock(DelegatedAlbumAccessChecker::class);
        $first->expects($this->once())->method('supports')->willReturn(true);
        $first->expects($this->once())->method('isAllowed')->willReturn(true);
        $second = $this->createMock(DelegatedAlbumAccessChecker::class);
        $second->expects($this->never())->method('supports');
        $registry = new DelegatedAlbumAccessRegistry([$first, $second]);

        $this->assertTrue($registry->isAllowed('some_owner_type', 42, Role::CHIEF, []));
    }
}
