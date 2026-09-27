<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Badge\Badge;
use Core\Badge\BadgeHolder;
use Core\Badge\BadgeHolders;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * Espace chefs d'U > Badges, the holders page (issue #621), assembled
 * through base.html.twig as it is served.
 */
final class BadgeHoldersTemplateTest extends TestCase
{
    public function testEachBadgeCarriesItsCountAndItsHoldersWithSectionAndFunction(): void
    {
        $html = $this->render([
            new BadgeHolders(new Badge(1, 'Infirmier', true, true), [
                new BadgeHolder(41, 'Claire Renard', 'Lutins', 'Animatrice'),
                new BadgeHolder(42, 'Thomas Lecomte', "Staff d'U", 'Trésorier'),
            ]),
            new BadgeHolders(new Badge(2, 'Trésorier', true, true), [
                new BadgeHolder(42, 'Thomas Lecomte', "Staff d'U", 'Trésorier'),
            ]),
        ]);

        $this->assertStringContainsString('Badges — 2026-2027', $html);
        $this->assertMatchesRegularExpression('~>Infirmier</h2>.*?2 porteurs~s', $html);
        $this->assertMatchesRegularExpression('~>Trésorier</h2>.*?1 porteur<~s', $html);
        $this->assertStringContainsString('Lutins · Animatrice', $html);
    }

    /** The member_year id, which /admin/members/{id} reads — see BadgeHolder. */
    public function testEachNameLinksToThePersonsSheet(): void
    {
        $html = $this->render([
            new BadgeHolders(new Badge(1, 'Infirmier', true, true), [new BadgeHolder(417, 'Claire Renard', 'Lutins', 'Animatrice')]),
        ]);

        $this->assertMatchesRegularExpression('~<a href="/admin/members/417"[^>]*>.*?Claire Renard~s', $html);
    }

    public function testAutomaticAndDeactivatedBadgesAreMarked(): void
    {
        $html = $this->render([
            new BadgeHolders(new Badge(3, 'Référent Lutins', true, true, 7), [new BadgeHolder(1, 'Marie Dubois', "Staff d'U", 'CU adjointe')]),
            new BadgeHolders(new Badge(4, 'Intendance', false, false), [new BadgeHolder(2, 'Ahmed Bensalah', "Staff d'U", 'Intendant')]),
            new BadgeHolders(new Badge(5, 'Communication', false, true), [new BadgeHolder(3, 'Sophie Martens', 'Baladins', 'Animatrice')]),
        ]);

        $this->assertMatchesRegularExpression('~>Référent Lutins</h2>\s*<span[^>]*>Automatique</span>~', $html);
        $this->assertMatchesRegularExpression('~>Intendance</h2>\s*<span[^>]*>Désactivé</span>~', $html);
        $this->assertDoesNotMatchRegularExpression('~>Communication</h2>\s*<span[^>]*>(?:Automatique|Désactivé)</span>~', $html);
        $this->assertStringContainsString('Un badge désactivé qui garde des porteurs reste affiché ici', $html);
    }

    /** Read only: no control, and a way to where badges are assigned. */
    public function testThePageAssignsNothingAndSendsToStaffs(): void
    {
        $html = $this->render([
            new BadgeHolders(new Badge(1, 'Infirmier', true, true), [new BadgeHolder(1, 'Claire Renard', 'Lutins', 'Animatrice')]),
        ]);

        $this->assertStringContainsString('<a href="/chefs/staffs">Staffs</a>', $html);
        $content = substr($html, (int) strpos($html, 'Badges — 2026-2027'));
        $this->assertStringNotContainsString('<form', $content);
        $this->assertStringNotContainsString('role="switch"', $content);
        $this->assertStringNotContainsString('Un badge désactivé', $html, 'No deactivated badge here, nothing to say about one.');
    }

    public function testAYearWithoutAnyBadgeSaysSo(): void
    {
        $html = $this->render([]);

        // Through the site's one empty state (design.md §7.7), which offers
        // the way to where badges are assigned.
        $this->assertMatchesRegularExpression('~<div data-badge-holders-empty>\s*<div class="[^"]*empty-state~', $html);
        $this->assertStringContainsString('Aucun badge n&#039;est attribué pour 2026-2027.', $html);
        $this->assertMatchesRegularExpression('~<a href="/chefs/staffs"[^>]*>[^<]*(?:<[^>]+>[^<]*)*Attribuer sur Staffs~', $html);
    }

    /** The tabs carry the years, never « Année en cours ». */
    public function testTheRailNamesTheYearAndSelectsIt(): void
    {
        $html = $this->render([]);

        $this->assertMatchesRegularExpression('~<a href="/admin/badges"[^>]*aria-current="page"[^>]*>\s*(?:<[^>]+>\s*)*2026-2027~', $html);
        $this->assertMatchesRegularExpression('~<a href="/admin/badges/configuration"[^>]*>\s*(?:<[^>]+>\s*)*Configuration~', $html);
        $this->assertStringNotContainsString('Année en cours', $html);
    }

    /** @param list<BadgeHolders> $groups */
    private function render(array $groups): string
    {
        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('current_path', '/admin/badges');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');

        return $twig->render('admin/badges/holders.html.twig', [
            'year_label' => '2026-2027',
            'groups' => $groups,
            'badges_current_year_label' => '2026-2027',
        ]);
    }
}
