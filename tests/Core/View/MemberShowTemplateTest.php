<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Member\MemberProfile;
use Core\Module\FormationPathView;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * Full-page render of core/View/templates/members/show.html.twig (the
 * "Espace membres" member page) against the real filesystem loader —
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
    /**
     * @param array<string, mixed> $extraContext
     */
    private function render(MemberProfile $member, bool $isSelf = true, array $extraContext = []): string
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

        return $twig->render('members/show.html.twig', $extraContext + [
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
            'formation_path' => null,
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

    /**
     * The leadership module's training-path card is self-only, and the
     * template's own `is_self` test is the second of the two locks (the
     * first is MemberPageService, which does not even build the data for
     * anybody else). Both are here on purpose: this page renders for a
     * chief/admin too, so the route's authorization is not the question.
     */
    public function testTheFormationPathCardIsRenderedOnTheMembersOwnPage(): void
    {
        $html = $this->render($this->makeMember(), true, [
            'formation_path' => new FormationPathView(
                steps: [
                    ['label' => 'T1', 'reached' => true, 'current' => false],
                    ['label' => 'T2', 'reached' => true, 'current' => true],
                    ['label' => 'T3', 'reached' => false, 'current' => false],
                    ['label' => 'Brevet', 'reached' => false, 'current' => false],
                ],
                currentLabel: 'Deuxième étape (T2) atteinte',
                nextLabel: 'T3',
                isRecognised: true,
            ),
        ]);

        $this->assertStringContainsString('Mon parcours de formation', $html);
        $this->assertStringContainsString('Deuxième étape (T2) atteinte', $html);
        $this->assertStringContainsString('Prochaine étape connue : T3', $html);
    }

    public function testTheFormationPathCardIsAbsentWhenSomebodyElseIsLooking(): void
    {
        $html = $this->render($this->makeMember(), false, [
            'formation_path' => new FormationPathView(
                steps: [['label' => 'T1', 'reached' => true, 'current' => true]],
                currentLabel: 'Première étape (T1) atteinte',
                nextLabel: 'T2',
                isRecognised: true,
            ),
        ]);

        $this->assertStringNotContainsString('Mon parcours de formation', $html);
        $this->assertStringNotContainsString('Première étape (T1) atteinte', $html);
    }

    /**
     * An unresolvable Desk value draws no milestones at all and says so —
     * a half-filled path would be the site asserting progress it cannot
     * read.
     */
    public function testAnUnrecognisedLevelDrawsNoPath(): void
    {
        $html = $this->render($this->makeMember(), true, [
            'formation_path' => new FormationPathView(
                steps: [
                    ['label' => 'T1', 'reached' => false, 'current' => false],
                    ['label' => 'T2', 'reached' => false, 'current' => false],
                ],
                currentLabel: "Le niveau encodé dans Desk n'est pas reconnu par le site",
                nextLabel: null,
                isRecognised: false,
            ),
        ]);

        // Twig escapes the apostrophe, so match the half that survives.
        $this->assertStringContainsString('pas reconnu par le site', $html);
        $this->assertStringNotContainsString('Prochaine étape connue', $html);
        // No milestone chip is drawn at all.
        $this->assertStringNotContainsString('rounded-pill', $html);
    }

    public function testTheCardIsAbsentAltogetherWhenTheModuleIsDisabled(): void
    {
        $html = $this->render($this->makeMember(), true);

        $this->assertStringNotContainsString('Mon parcours de formation', $html);
    }
}
