<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Menu;

use Core\Member\MemberService;
use Core\Module\MenuEntry;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Core\View\MenuBuilder;
use Modules\Retro\Menu\RetroMenuHookService;
use PHPUnit\Framework\TestCase;

/**
 * Issue #347: « Quand on clique sur la page rétrospective pour les admin,
 * dans le menu, je m'attends à voir la page s'afficher. »
 *
 * The entry used to be a `label` in module.json, drawn from the route's
 * `role_min: admin` and nothing else, while the page itself asks for
 * membership of the Staff d'U section. These four tests are the two sides
 * of that gap, plus the year the question is asked in.
 */
final class RetroMenuHookServiceTest extends TestCase
{
    private const AUTHORIZATION_YEAR = 12;

    /**
     * @param string[] $unitChiefEmails
     */
    private function hook(array $unitChiefEmails, Role $viewerRole, int $year = self::AUTHORIZATION_YEAR): object
    {
        $memberService = new class ($unitChiefEmails, $year) extends MemberService {
            /**
             * @param string[] $unitChiefEmails
             */
            public function __construct(private array $unitChiefEmails, private int $year)
            {
            }

            public function isUnitChief(string $email, int $scoutYearId): bool
            {
                return $scoutYearId === $this->year && in_array($email, $this->unitChiefEmails, true);
            }
        };

        $resolver = new class ($year) extends ScoutYearResolver {
            public function __construct(private int $year)
            {
            }

            public function getAuthorizationYear(): EffectiveScoutYear
            {
                return new EffectiveScoutYear($this->year, '2025-2026', null);
            }
        };

        return new RetroMenuHookService($memberService, $resolver, $viewerRole);
    }

    /**
     * The defect itself: « Administrateur du site » clears the route's
     * `role_min` — Role::SUPERADMIN is above Role::ADMIN — and is not
     * therefore a chef d'U. Before the fix, the menu showed him the entry
     * and the page answered "Forbidden".
     */
    public function testASiteAdministratorWhoIsNotAChefDuIsOfferedNothing(): void
    {
        $this->assertSame(
            [],
            $this->hook([], Role::SUPERADMIN)->getMenuEntries('admin@example.org'),
            'the menu offers a link to a page this account cannot open — issue #347',
        );
        $this->assertSame([], $this->hook([], Role::ADMIN)->getMenuEntries('admin@example.org'));
    }

    /**
     * And the other half: hiding it from everybody would be a different
     * bug, quieter and just as real.
     */
    public function testAnActualChefDuIsOfferedTheEntry(): void
    {
        $entries = $this->hook(['chief@example.org'], Role::ADMIN)->getMenuEntries('chief@example.org');

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertInstanceOf(MenuEntry::class, $entry);
        $this->assertSame('Rétrospective', $entry->label);
        $this->assertSame('/config/retro', $entry->url);
        $this->assertSame(MenuBuilder::MENU_ESPACE_ADMIN, $entry->menuId);
        $this->assertSame('services', $entry->menuGroup);
        $this->assertSame('admin', $entry->roleMin);
    }

    /**
     * An anonymous visitor has no address to ask about, and a member
     * below `admin` cannot see this menu at all — so neither costs a
     * database lookup on every page of the site.
     */
    public function testNobodyBelowAdminIsAskedAboutAtAll(): void
    {
        $this->assertSame([], $this->hook(['chief@example.org'], Role::ADMIN)->getMenuEntries(null));
        $this->assertSame([], $this->hook(['chief@example.org'], Role::CHIEF)->getMenuEntries('chief@example.org'));
        $this->assertSame(
            [],
            $this->hook(['chief@example.org'], Role::IDENTIFIED)->getMenuEntries('chief@example.org'),
        );
    }

    /**
     * The question is asked in the AUTHORIZATION year, which is what the
     * controller asks in too — see RetroConfigController::
     * requireUnitChief(). Asked in any other year, a chef d'U looking at
     * another year would be offered nothing while the page let them in.
     */
    public function testTheQuestionIsAskedInTheAuthorizationYear(): void
    {
        $this->assertCount(
            1,
            $this->hook(['chief@example.org'], Role::ADMIN, 30)->getMenuEntries('chief@example.org'),
            'the hook asked about a year other than the one it resolved',
        );
    }
}
