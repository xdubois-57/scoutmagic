<?php

declare(strict_types=1);

namespace Tests\Modules\Banner\Menu;

use Core\Member\MemberService;
use Core\Module\MenuEntry;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Core\View\MenuBuilder;
use Modules\Banner\Menu\BannerMenuHookService;
use PHPUnit\Framework\TestCase;

/**
 * The twin of Tests\Modules\Retro\Menu\RetroMenuHookServiceTest, for the
 * page that had the same defect as issue #347's and had simply not been
 * clicked yet: `/config/banner` is `role_min: admin` and
 * BannerConfigController asks for Staff d'U membership.
 */
final class BannerMenuHookServiceTest extends TestCase
{
    public const AUTHORIZATION_YEAR = 4;

    /**
     * @param string[] $unitChiefEmails
     */
    private function hook(array $unitChiefEmails, Role $viewerRole): BannerMenuHookService
    {
        $memberService = new class ($unitChiefEmails) extends MemberService {
            /**
             * @param string[] $unitChiefEmails
             */
            public function __construct(private array $unitChiefEmails)
            {
            }

            public function isUnitChief(string $email, int $scoutYearId): bool
            {
                return $scoutYearId === BannerMenuHookServiceTest::AUTHORIZATION_YEAR
                    && in_array($email, $this->unitChiefEmails, true);
            }
        };

        $resolver = new class () extends ScoutYearResolver {
            public function __construct()
            {
            }

            public function getAuthorizationYear(): EffectiveScoutYear
            {
                return new EffectiveScoutYear(BannerMenuHookServiceTest::AUTHORIZATION_YEAR, '2025-2026', null);
            }
        };

        return new BannerMenuHookService($memberService, $resolver, $viewerRole);
    }

    /**
     * An admin who is not a real chef d'unité gets nothing: that role can
     * come from any Desk function mapped to it (issue #347 — no link to a
     * page this account cannot open).
     */
    public function testAnAdminWhoIsNotAChefDuIsOfferedNothing(): void
    {
        $this->assertSame([], $this->hook([], Role::ADMIN)->getMenuEntries('admin@example.org'));
    }

    /**
     * Issue #743: a superadmin with no member behind the account inherits
     * the Staff d'U's entry — and the page it leads to opens for them.
     */
    public function testASuperadminWithoutAnyDeskMemberIsOfferedTheEntry(): void
    {
        $this->assertCount(1, $this->hook([], Role::SUPERADMIN)->getMenuEntries('superadmin@example.org'));
    }

    public function testAChiefIsOfferedNothingEvenWhenStaffDu(): void
    {
        $this->assertSame([], $this->hook(['chief@example.org'], Role::CHIEF)->getMenuEntries('chief@example.org'));
    }


    public function testAnActualChefDuIsOfferedTheEntry(): void
    {
        $entries = $this->hook(['chief@example.org'], Role::ADMIN)->getMenuEntries('chief@example.org');

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertInstanceOf(MenuEntry::class, $entry);
        $this->assertSame('Bannière', $entry->label);
        $this->assertSame('/config/banner', $entry->url);
        $this->assertSame(MenuBuilder::MENU_ESPACE_ADMIN, $entry->menuId);
        $this->assertSame('communication', $entry->menuGroup);
        $this->assertSame('admin', $entry->roleMin);
    }

    public function testNobodyBelowAdminIsAskedAboutAtAll(): void
    {
        $this->assertSame([], $this->hook(['chief@example.org'], Role::ADMIN)->getMenuEntries(null));
        $this->assertSame([], $this->hook(['chief@example.org'], Role::CHIEF)->getMenuEntries('chief@example.org'));
    }
}
