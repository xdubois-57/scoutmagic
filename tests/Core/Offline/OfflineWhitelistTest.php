<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Offline;

use Core\Offline\HelpPages;
use Core\Offline\LinkedMemberPages;
use Core\Offline\OfflineWhitelist;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * The declaration of what the installed app holds offline (#883): fixed
 * pages and providers, by role — and which responses may carry an ETag.
 */
final class OfflineWhitelistTest extends TestCase
{
    private OfflineWhitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = new OfflineWhitelist();
    }

    /** @return list<string> */
    private function fixedPathsFor(Role $role): array
    {
        return array_values(array_filter(array_column($this->whitelist->getEntriesForRole($role), 'path')));
    }

    public function testTheCoreHoldsItsFixedPagesAndTwoProviders(): void
    {
        $this->assertSame(['/', '/contact', '/sections', '/rgpd'], $this->fixedPathsFor(Role::PUBLIC));
        $this->assertSame(
            ['/', '/contact', '/sections', '/rgpd', '/account', '/notifications', '/chefs/staffs'],
            $this->fixedPathsFor(Role::INTENDANT)
        );

        $providers = array_column(array_filter($this->whitelist->getAllEntries(), static fn ($e) => $e['provider'] !== null), 'prefix', 'provider');
        $this->assertSame([LinkedMemberPages::class => '/members', HelpPages::class => '/aide'], $providers);
    }

    public function testOnlyTheHelpComesLast(): void
    {
        $last = array_values(array_filter($this->whitelist->getAllEntries(), static fn ($e) => $e['last']));

        $this->assertCount(1, $last);
        $this->assertSame(HelpPages::class, $last[0]['provider']);
    }

    public function testNeverIncludesFileServingOrPrivateRoutes(): void
    {
        foreach ($this->whitelist->getAllEntries() as $entry) {
            foreach ([$entry['path'], $entry['prefix']] as $declared) {
                if ($declared === null) {
                    continue;
                }
                foreach (['/files', '/finance', '/config', '/inscriptions', '/documents'] as $private) {
                    $this->assertFalse(OfflineWhitelist::isUnder($declared, $private), "{$declared} is under {$private}");
                }
            }
        }
    }

    public function testAModuleEntryIsFilteredByRole(): void
    {
        $this->whitelist->registerModuleEntries('test', [
            ['path' => '/chefs/test', 'provider' => null, 'prefix' => null, 'label' => 'Test', 'role_min' => 'chief', 'last' => false],
        ]);

        $this->assertNotContains('/chefs/test', $this->fixedPathsFor(Role::IDENTIFIED));
        $this->assertContains('/chefs/test', $this->fixedPathsFor(Role::CHIEF));
    }

    public function testAnEtagIsGivenToAFixedPageAndToAnythingUnderAProvidersPrefix(): void
    {
        $this->assertTrue($this->whitelist->isEtagEligible('/contact'));
        $this->assertTrue($this->whitelist->isEtagEligible('/members/12'));
        $this->assertTrue($this->whitelist->isEtagEligible('/aide'));
        $this->assertTrue($this->whitelist->isEtagEligible('/aide/installer-application'));
    }

    public function testNoEtagOutsideTheList(): void
    {
        foreach (['/contact/extra', '/members-list', '/aidez-nous', '/finance', '/files/12'] as $path) {
            $this->assertFalse($this->whitelist->isEtagEligible($path), $path);
        }
    }

    public function testIsUnderMeansThePrefixOrAPageBelowIt(): void
    {
        $this->assertTrue(OfflineWhitelist::isUnder('/news', '/news'));
        $this->assertTrue(OfflineWhitelist::isUnder('/news/41', '/news'));
        $this->assertFalse(OfflineWhitelist::isUnder('/newsletter', '/news'));
        $this->assertFalse(OfflineWhitelist::isUnder('/', '/news'));
    }
}
