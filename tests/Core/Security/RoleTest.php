<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function testLevelValues(): void
    {
        $this->assertSame(0, Role::PUBLIC->level());
        $this->assertSame(1, Role::IDENTIFIED->level());
        $this->assertSame(2, Role::INTENDANT->level());
        $this->assertSame(3, Role::CHIEF->level());
        $this->assertSame(4, Role::ADMIN->level());
        $this->assertSame(5, Role::SUPERADMIN->level());
        $this->assertSame(Role::SUPERADMIN, Role::fromString('superadmin'));
        $this->assertTrue(Role::SUPERADMIN->hasAccess(Role::ADMIN));
        $this->assertFalse(Role::ADMIN->hasAccess(Role::SUPERADMIN));
    }

    public function testPublicHasAccessToPublic(): void
    {
        $this->assertTrue(Role::PUBLIC->hasAccess(Role::PUBLIC));
    }

    public function testPublicDoesNotHaveAccessToIdentified(): void
    {
        $this->assertFalse(Role::PUBLIC->hasAccess(Role::IDENTIFIED));
    }

    public function testAdminHasAccessToEverything(): void
    {
        $this->assertTrue(Role::ADMIN->hasAccess(Role::PUBLIC));
        $this->assertTrue(Role::ADMIN->hasAccess(Role::IDENTIFIED));
        $this->assertTrue(Role::ADMIN->hasAccess(Role::INTENDANT));
        $this->assertTrue(Role::ADMIN->hasAccess(Role::CHIEF));
        $this->assertTrue(Role::ADMIN->hasAccess(Role::ADMIN));
    }

    public function testIdentifiedDoesNotHaveAccessToIntendant(): void
    {
        $this->assertFalse(Role::IDENTIFIED->hasAccess(Role::INTENDANT));
    }

    public function testChiefHasAccessToIntendant(): void
    {
        $this->assertTrue(Role::CHIEF->hasAccess(Role::INTENDANT));
    }

    public function testFromStringWithValidValues(): void
    {
        $this->assertSame(Role::PUBLIC, Role::fromString('public'));
        $this->assertSame(Role::IDENTIFIED, Role::fromString('identified'));
        $this->assertSame(Role::INTENDANT, Role::fromString('intendant'));
        $this->assertSame(Role::CHIEF, Role::fromString('chief'));
        $this->assertSame(Role::ADMIN, Role::fromString('admin'));
    }

    public function testFromStringWithUnknownValueReturnsPublic(): void
    {
        $this->assertSame(Role::PUBLIC, Role::fromString('unknown'));
        $this->assertSame(Role::PUBLIC, Role::fromString(''));
        $this->assertSame(Role::PUBLIC, Role::fromString('wizard'));
    }
}
