<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Member\EffectiveAge;
use Core\Member\MemberProfile;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\TwigFunction;

/**
 * Regression coverage for SonarCloud Web:S6853 ("A form label must be
 * associated with a control and have accessible text"): 19 instances of
 * <label> used purely as decorative caption/group-heading/spacer text —
 * no `for` attribute, no wrapped control — across 9 templates. Every real
 * form control at each of these locations already has its own correctly
 * paired <label for="...">; the fix swaps the misused tag for a plain
 * <div>, keeping every class unchanged. These tests render the real
 * production templates and assert the previously-<label> text now
 * renders inside a non-<label> element.
 */
class DecorativeLabelMisuseTest extends TestCase
{
    private function createTwig(array $moduleNamespaces = []): Environment
    {
        $coreTemplateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = TwigFactory::create($coreTemplateDir, true, $moduleNamespaces);
        $twig->addFunction(new TwigFunction('param', fn (string $key): string => 'Test Unité'));

        return $twig;
    }

    private function assertTextIsNotInsideALabel(string $html, string $text): void
    {
        $this->assertStringContainsString($text, $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<label\b[^>]*>\s*' . preg_quote($text, '/') . '/u',
            $html,
            "Expected \"{$text}\" to no longer be rendered inside a <label> element."
        );
    }

    public function testSettingsEditModalPlaceholderLabelIsNotALabelElement(): void
    {
        $twig = $this->createTwig();
        $html = $twig->render('config/settings.html.twig', [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
        ]);

        $this->assertStringContainsString('<div class="form-label" id="settingEditLabel"></div>', $html);
        $this->assertDoesNotMatchRegularExpression('/<label\b[^>]*id="settingEditLabel"/', $html);
    }

    public function testMemberDetailCardGroupHeadingsAreNotLabelElements(): void
    {
        $twig = $this->createTwig();
        $member = new MemberProfile(
            memberYearId: 1, memberId: 1, deskId: 'D1', firstName: 'Jean', lastName: 'Dupont',
            totem: null, quali: null, gender: null, birthDate: null,
            phone: null, mobile: null, email: null,
            patrol: null, formationLevel: null, federationMailConsent: false, unitMailConsent: false,
            addresses: [], functions: [],
            scoutYearLabel: '2025-2026'
        );

        $html = $twig->render('admin/members/_detail_card.html.twig', [
            'member' => $member,
            'effective_age' => new EffectiveAge(null, null, null, null, null, null),
            'departure_leaving' => false,
            'departure_comment' => '',
        ]);

        $this->assertTextIsNotInsideALabel($html, 'Décalage année scoute');
        $this->assertTextIsNotInsideALabel($html, "Départ prévu l'année prochaine");
    }

    public function testMassMailComposeDialogGroupHeadingsAreNotLabelElements(): void
    {
        $twig = $this->createTwig(['mass_mail' => dirname(__DIR__, 3) . '/modules/mass_mail/views']);
        $html = $twig->render('@mass_mail/_compose_dialog.html.twig');

        $this->assertTextIsNotInsideALabel($html, 'Année(s) scoute(s)');
        $this->assertTextIsNotInsideALabel($html, 'Message');
        $this->assertTextIsNotInsideALabel($html, 'Pièces jointes');
        $this->assertTextIsNotInsideALabel($html, "Progression de l'envoi");
    }

    public function testMassMailConfigGroupHeadingsAreNotLabelElements(): void
    {
        $twig = $this->createTwig(['mass_mail' => dirname(__DIR__, 3) . '/modules/mass_mail/views']);
        $html = $twig->render('@mass_mail/config.html.twig', [
            'default_lists' => [],
            'custom_lists' => [],
            'custom_list_criteria' => [],
            'batch_size' => 50,
            'all_functions' => [],
            'all_sections' => [],
        ]);

        $this->assertTextIsNotInsideALabel($html, 'Fonctions (au moins une)');
        $this->assertTextIsNotInsideALabel($html, 'Sections (au moins une)');
    }

    public function testFinanceReceiptsListSpacerLabelsAreNotLabelElements(): void
    {
        $twig = $this->createTwig(['finance' => dirname(__DIR__, 3) . '/modules/finance/views']);
        $html = $twig->render('@finance/receipts/list.html.twig', [
            'no_accounts' => false,
            'selected_account' => ['id' => 1, 'name' => 'Compte test'],
            'search' => '',
            'pending_only' => false,
            'current_path' => '/finance/receipts',
        ]);

        // Both spacer labels share the same &nbsp; text, so assert directly
        // on the markup rather than via assertTextIsNotInsideALabel().
        $this->assertStringContainsString('<div class="form-label small d-block">&nbsp;</div>', $html);
        $this->assertDoesNotMatchRegularExpression('/<label\b[^>]*class="form-label small d-block">&nbsp;/', $html);
    }

    public function testGalleryAlbumFormGroupHeadingsAreNotLabelElements(): void
    {
        $twig = $this->createTwig(['gallery' => dirname(__DIR__, 3) . '/modules/gallery/views']);

        $htmlNewAlbum = $twig->render('@gallery/album_form.html.twig', [
            'album' => null,
            'allow_local' => true,
            'allow_external' => true,
            'locations' => [],
            'sections' => [],
            'csrf_token' => 'test',
            'site_name' => 'Test Unité',
        ]);
        $this->assertTextIsNotInsideALabel($htmlNewAlbum, "Type d'album");

        $htmlLocalAlbum = $twig->render('@gallery/album_form.html.twig', [
            'album' => (object) ['id' => 1, 'type' => 'local', 'storageLocationId' => 1, 'title' => 'Album', 'coverMediaId' => null, 'migrationStatus' => null],
            'allow_local' => true,
            'allow_external' => false,
            'locations' => [],
            'sections' => [],
            'csrf_token' => 'test',
            'site_name' => 'Test Unité',
            'video_upload_allowed' => true,
            'max_media_per_album' => 50,
            'media' => [],
        ]);
        $this->assertTextIsNotInsideALabel($htmlLocalAlbum, 'Emplacement de stockage');
    }

    public function testGalleryLocationFormGroupHeadingIsNotALabelElement(): void
    {
        $twig = $this->createTwig(['gallery' => dirname(__DIR__, 3) . '/modules/gallery/views']);
        $html = $twig->render('@gallery/location_form.html.twig', [
            'location' => null,
            'referenced_count' => 0,
            'csrf_token' => 'test',
            'site_name' => 'Test Unité',
            'gallery_s3_ai_available' => false,
        ]);

        $this->assertTextIsNotInsideALabel($html, 'Type de stockage');
    }

    public function testRetroConfigGroupHeadingsAreNotLabelElements(): void
    {
        $twig = $this->createTwig(['retro' => dirname(__DIR__, 3) . '/modules/retro/views']);
        $html = $twig->render('@retro/config.html.twig', [
            'board' => null,
            'calendar_enabled' => false,
            'events' => [],
            'default_vote_budget' => 10,
            'default_max_comment_length' => 160,
            'default_notify_email' => 'chef@example.org',
            'link_visibilities' => [['value' => 'chief', 'label' => 'Chef']],
        ]);

        $this->assertTextIsNotInsideALabel($html, 'Découverte du lien');
        $this->assertTextIsNotInsideALabel($html, 'Mode de vote');
        $this->assertTextIsNotInsideALabel($html, 'Visibilité des votes');
        $this->assertTextIsNotInsideALabel($html, 'Anti-doublon des votes');
    }

    public function testRegistrationPublicSiblingGroupHeadingIsNotALabelElement(): void
    {
        $twig = $this->createTwig(['registration' => dirname(__DIR__, 3) . '/modules/registration/views']);
        $html = $twig->render('@registration/public.html.twig', [
            'target_year_label' => '2025-2026',
            'parcours_image_file_id' => 0,
            'waitlist_enabled' => false,
            'birth_years_by_branch' => [],
            'form_available' => true,
            'sections' => [],
            'is_identified' => true,
            'sibling_candidates' => [
                ['member_id' => 1, 'name' => 'Jean Dupont'],
            ],
            'sticky' => [],
        ]);

        $this->assertTextIsNotInsideALabel($html, "Frères et sœurs déjà membres de l'unité");
    }
}
