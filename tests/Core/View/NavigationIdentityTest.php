<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Security\UserAccount;
use Core\View\NavigationIdentity;
use PHPUnit\Framework\TestCase;

/**
 * The name the navigation writes beside the photo is the first name saved in
 * the account's own profile — never one of the members linked to its e-mail
 * address (issue #1360).
 */
class NavigationIdentityTest extends TestCase
{
    private function account(?string $firstName, ?string $lastName = 'Martin'): UserAccount
    {
        return new UserAccount(7, 'camille@example.org', $firstName, $lastName, null, false, null);
    }

    public function testTheNameIsTheFirstNameOfTheAccountProfile(): void
    {
        $this->assertSame('Camille', NavigationIdentity::displayName($this->account('Camille'), 'camille@example.org'));
    }

    /** A compound first name is written as it was saved, not cut at its first word. */
    public function testACompoundFirstNameIsKeptWhole(): void
    {
        $this->assertSame('Marie-Hélène', NavigationIdentity::displayName($this->account('Marie-Hélène'), 'x@example.org'));
        $this->assertSame('Anne Sophie', NavigationIdentity::displayName($this->account('Anne Sophie'), 'x@example.org'));
    }

    public function testSurroundingSpacesAreDropped(): void
    {
        $this->assertSame('Camille', NavigationIdentity::displayName($this->account('  Camille '), 'x@example.org'));
    }

    public function testTheLastNameIsNotPartOfIt(): void
    {
        $this->assertSame('Camille', NavigationIdentity::displayName($this->account('Camille', 'Dupont-Martin'), 'x@example.org'));
    }

    public function testAProfileWithoutAFirstNameFallsBackToTheEmailAddressNeverToAMember(): void
    {
        $this->assertSame('camille@example.org', NavigationIdentity::displayName($this->account(null), 'camille@example.org'));
        $this->assertSame('camille@example.org', NavigationIdentity::displayName($this->account(''), 'camille@example.org'));
        $this->assertSame('camille@example.org', NavigationIdentity::displayName($this->account('   '), 'camille@example.org'));
    }

    public function testAnUnknownAccountFallsBackToTheEmailAddress(): void
    {
        $this->assertSame('camille@example.org', NavigationIdentity::displayName(null, 'camille@example.org'));
    }

    public function testAVisitorWhoIsNotIdentifiedGetsNoName(): void
    {
        $this->assertSame('', NavigationIdentity::displayName(null, ''));
    }
}
