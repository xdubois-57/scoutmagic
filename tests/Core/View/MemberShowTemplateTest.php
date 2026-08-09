<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Member\MemberProfile;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * Full-page render of core/View/templates/members/show.html.twig (the
 * "Espace animés" member page) against the real filesystem loader —
 * unlike MemberPhotoFunctionTest (which renders member_photo() in
 * isolation via an ArrayLoader), this catches page-composition bugs that
 * only exist once the template is assembled through base.html.twig, such
 * as a missing stylesheet link. That's a real regression this test
 * reproduces: the page never linked components.css (base.html.twig
 * doesn't load it globally — every other page using member_photo()/
 * .member-photo opts in via {% block stylesheets %}), so every
 * .member-photo-header sizing rule was silently inert and the photo
 * rendered at its raw intrinsic size, no matter how correct the CSS
 * itself was.
 */
class MemberShowTemplateTest extends TestCase
{
    private function render(MemberProfile $member, bool $isSelf = true): string
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = TwigFactory::create($templateDir, true);
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@example.com');
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('current_path', '/members/1');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('effective_scout_year_id', 1);
        $twig->addGlobal('_member_photo_service', null);

        return $twig->render('members/show.html.twig', [
            'member' => $member,
            'is_self' => $isSelf,
            'show_contact' => true,
            'show_addresses' => true,
            'branch_card' => null,
            'section_info' => null,
            'recent_mass_mail_emails' => [],
            'member_documents' => [],
            'gallery_albums' => [],
            'trombinoscope_enabled' => false,
            'calendar_enabled' => false,
            'mass_mail_enabled' => false,
            'gallery_enabled' => false,
        ]);
    }

    private function makeMember(): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 1, memberId: 1, deskId: 'D1', firstName: 'jean', lastName: 'dupont',
            totem: 'Baloo', quali: null, gender: null, birthDate: null,
            phone: null, mobile: null, email: null,
            patrol: null, formationLevel: null, federationMailConsent: false, unitMailConsent: false,
            addresses: [], functions: [],
            scoutYearLabel: '2025-2026'
        );
    }

    public function testPageLinksComponentsCssSoMemberPhotoHeaderSizingActuallyApplies(): void
    {
        $html = $this->render($this->makeMember());

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/components.css">', $html);
    }

    public function testHeaderPhotoPlaceholderUsesTheSizedHeaderClass(): void
    {
        $html = $this->render($this->makeMember());

        $this->assertMatchesRegularExpression(
            '/class="[^"]*\bmember-photo-header\b[^"]*"/',
            $html
        );
    }
}
