<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * Issue #743: the Staff d'U's authority is cumulative. A superadmin has it
 * without any member behind the account; an admin only as a real chef
 * d'unité; nobody without an address otherwise.
 */
final class UnitChiefAuthorityTest extends TestCase
{
    private function members(bool $isUnitChief): MemberService
    {
        $members = $this->createStub(MemberService::class);
        $members->method('isUnitChief')->willReturn($isUnitChief);

        return $members;
    }

    public function testASuperadminHasItWithoutAnyDeskMember(): void
    {
        $this->assertTrue($this->members(false)->hasUnitChiefAuthority(Role::SUPERADMIN, 'root@example.org', 1));
        $this->assertTrue($this->members(false)->hasUnitChiefAuthority(Role::SUPERADMIN, null, 1));
    }

    public function testAnAdminHasItOnlyAsARealChefDu(): void
    {
        $this->assertFalse($this->members(false)->hasUnitChiefAuthority(Role::ADMIN, 'admin@example.org', 1));
        $this->assertTrue($this->members(true)->hasUnitChiefAuthority(Role::ADMIN, 'chef@example.org', 1));
        $this->assertFalse($this->members(true)->hasUnitChiefAuthority(Role::ADMIN, null, 1));
    }
}
