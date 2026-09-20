<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Http\Request;
use Core\Security\ProfileCompletionGate;
use Core\Security\Role;
use Core\Security\UserAccount;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the interstitial screen, stated one by one.
 *
 * Each of them is a way this can go wrong that nobody would see: a gate
 * that blocks the public routes leaves a screen that cannot draw itself,
 * one that blocks the logout locks a person out of their own site, and one
 * that waves an unknown `role_min` through is a gate with a hole in it.
 */
final class ProfileCompletionGateTest extends TestCase
{
    private static function account(?string $firstName, ?string $lastName): UserAccount
    {
        return new UserAccount(7, 'parent@example.be', $firstName, $lastName, null, false, null);
    }

    private static function get(string $path): Request
    {
        return new Request('GET', $path, [], [], [], []);
    }

    public function testACompleteProfileIsNeverIntercepted(): void
    {
        $gate = ProfileCompletionGate::forAccount(self::account('Camille', 'Renard'));

        $this->assertFalse($gate->blocks(self::get('/members/12'), Role::IDENTIFIED->value));
    }

    public function testAnAnonymousVisitorHasNoProfileToComplete(): void
    {
        $gate = ProfileCompletionGate::forAccount(null);

        $this->assertFalse($gate->blocks(self::get('/members/12'), Role::IDENTIFIED->value));
    }

    /**
     * Each half on its own, because an `&&` written as an `||` passes the
     * "both missing" case and lets half the accounts straight through.
     */
    public function testEitherNameMissingIsEnoughToInterrupt(): void
    {
        foreach ([[null, 'Renard'], ['Camille', null], [null, null], ['  ', 'Renard'], ['Camille', ' ']] as $names) {
            [$firstName, $lastName] = $names;
            $gate = ProfileCompletionGate::forAccount(self::account($firstName, $lastName));

            $this->assertTrue(
                $gate->blocks(self::get('/members/12'), Role::IDENTIFIED->value),
                sprintf('(%s, %s) should not count as a complete profile', var_export($firstName, true), var_export($lastName, true))
            );
        }
    }

    /**
     * Every role, no exemption — the chef d'unité and the superadmin
     * included, since the first account of every installation is one.
     */
    public function testNoRoleIsExemptFromTheScreen(): void
    {
        $gate = ProfileCompletionGate::forAccount(self::account(null, null));

        foreach ([Role::IDENTIFIED, Role::INTENDANT, Role::CHIEF, Role::ADMIN, Role::SUPERADMIN] as $role) {
            $this->assertTrue(
                $gate->blocks(self::get('/some/page'), $role->value),
                $role->value . ' must not be exempt'
            );
        }
    }

    /**
     * The screen renders through base.html.twig, which asks for the web
     * manifest and the unit's icons — all `role_min: public` routes. A gate
     * that intercepted those would serve a page that cannot draw itself.
     */
    public function testAPublicRouteIsNeverIntercepted(): void
    {
        $gate = ProfileCompletionGate::forAccount(self::account(null, null));

        $this->assertFalse($gate->blocks(self::get('/manifest.webmanifest'), Role::PUBLIC->value));
        $this->assertFalse($gate->blocks(self::get('/rgpd'), Role::PUBLIC->value));
    }

    public function testTheScreenItselfAndTheLogoutStayReachable(): void
    {
        $gate = ProfileCompletionGate::forAccount(self::account(null, null));

        $this->assertFalse($gate->blocks(self::get(ProfileCompletionGate::PATH), Role::IDENTIFIED->value));
        $this->assertFalse(
            $gate->blocks(new Request('POST', ProfileCompletionGate::PATH, [], [], [], []), Role::IDENTIFIED->value)
        );
        $this->assertFalse(
            $gate->blocks(new Request('POST', '/logout', [], [], [], []), Role::IDENTIFIED->value)
        );
    }

    /**
     * Fail-closed: a `role_min` nobody recognises is treated as non-public
     * and blocked. `Role::fromString()` answers PUBLIC for an unknown
     * value, so reading the verdict through it would have been the hole.
     */
    public function testAnUnknownRoleMinIsBlockedRatherThanWavedThrough(): void
    {
        $gate = ProfileCompletionGate::forAccount(self::account(null, null));

        $this->assertTrue($gate->blocks(self::get('/some/page'), 'pubIic'));
        $this->assertTrue($gate->blocks(self::get('/some/page'), ''));
    }

    public function testIsCompleteIsTheOneDefinitionSharedByTheScreenAndTheForm(): void
    {
        $this->assertTrue(ProfileCompletionGate::isComplete(self::account('Camille', 'Renard')));
        $this->assertFalse(ProfileCompletionGate::isComplete(self::account('', 'Renard')));
        $this->assertFalse(ProfileCompletionGate::isComplete(self::account('Camille', "\t")));
    }
}
