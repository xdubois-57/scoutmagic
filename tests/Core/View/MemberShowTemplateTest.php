<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Member\MemberProfile;
use Core\Module\FormationPathView;
use Core\ExternalSource\ExternalSources;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

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
        $twig = TestTwig::create();
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

    public function testInactiveLoginReactivationIsAnImmediateUndoOnlyOfferedOnAnOwnedAddress(): void
    {
        $row = new \Core\Member\MemberEmail(7, 1, 'parent@example.org', 'manual', 'inactive',
            null, null, null, null, new \DateTimeImmutable(), new \DateTimeImmutable());
        $html = $this->render($this->makeMember(), true, ['member_emails' => [$row]]);
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $form = (new \DOMXPath($doc))->query('//form[@action="/members/1/emails/7/reactivate"]')->item(0);
        $this->assertNotNull($form);
        $this->assertFalse($form->hasAttribute('data-confirm'));
        $this->assertStringNotContainsString('/emails/7/reactivate',
            $this->render($this->makeMember(), false, ['member_emails' => [$row]]));
    }

    /**
     * Issue #1101: whoever holds an added address reaches everything about
     * the member, every year — said where the address is typed, and tied
     * to the field for a screen reader. The old sentence about group-mail
     * unsubscription is gone (maintainer's request).
     */
    public function testTheAddAddressBoxSaysWhatAnAddressGivesAccessTo(): void
    {
        $html = (string) preg_replace('/\s+/', ' ', $this->render($this->makeMember(), true));

        $this->assertStringContainsString(
            'Une adresse ajoutée donne accès à toutes les informations personnelles de ce membre, '
                . 'années passées comprises',
            $html
        );
        $this->assertStringContainsString('Une adresse retirée perd aussitôt tout cet accès.', $html);
        $this->assertMatchesRegularExpression(
            '#<input type="email" name="email"[^>]*aria-describedby="member-email-add-scope"#',
            $html
        );
        $this->assertStringNotContainsString(
            'La désinscription des e-mails groupés ne change pas votre accès au site',
            $html
        );
    }

    public function testGroupMailConsentIsShownBesideAValidAddressAndRestoredByItsOwnAction(): void
    {
        $row = new \Core\Member\MemberEmail(7, 1, 'parent@example.org', 'manual', 'valid',
            null, null, null, new \DateTimeImmutable(), null, new \DateTimeImmutable());
        $context = ['member_emails' => [$row], 'member_email_group_mail_unsubscribed' => [7 => true]];
        $html = $this->render($this->makeMember(), true, $context);
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $forms = (new \DOMXPath($doc))->query('//form[@action="/members/1/emails/7/restore-group-mail"]');
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertSame('Rétablir les e-mails groupés', $form->getAttribute('data-confirm-label'));
        $this->assertStringContainsString('tous les groupes', $form->getAttribute('data-confirm'));
        $this->assertStringContainsString('Le consentement aux e-mails groupés sera rétabli', $form->getAttribute('data-confirm'));
        $this->assertStringContainsString("La validité de l'adresse pour la connexion et les suspensions pour rebonds restent inchangées", $form->getAttribute('data-confirm'));
        $this->assertStringNotContainsString('recevra de nouveau', $form->getAttribute('data-confirm'));
        $this->assertStringContainsString('Désinscrite des e-mails groupés', $html);
        $this->assertStringNotContainsString('/emails/7/reactivate', $html);
        $this->assertStringNotContainsString('/emails/7/restore-group-mail',
            $this->render($this->makeMember(), false, $context));
        $withoutModule = $this->render($this->makeMember(), true, ['member_emails' => [$row]]);
        $this->assertStringNotContainsString('Désinscrite des e-mails groupés', $withoutModule);
        $this->assertStringNotContainsString('/emails/7/restore-group-mail', $withoutModule);
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

        // Through the asset() cache-busting helper, so the href carries ?v=….
        $this->assertMatchesRegularExpression('~<link rel="stylesheet" href="/assets/css/components\.css\?v=[^"]+">~', $html);
    }

    /**
     * @return array{label: string, logo_file_id: ?int, default_logo: ?string, explanation_url: string, insignia_placement_url: string}
     */
    private function branchCard(): array
    {
        return [
            'label' => 'Louveteaux',
            'logo_file_id' => null,
            'default_logo' => null,
            'explanation_url' => 'https://lesscouts.be/fr/site-parents/le-parcours-scout',
            'insignia_placement_url' => ExternalSources::INSIGNIA_PLACEMENT_PAGE,
        ];
    }

    /**
     * Issue #473 — where to sew the insignia, on the branch card, UNDER
     * « En savoir plus » and as a discreet link rather than a second
     * button: the card is a third of the page at its widest.
     */
    public function testTheBranchCardLinksTheInsigniaGuideUnderTheButtonAsAPlainLink(): void
    {
        $html = $this->render($this->makeMember(), true, ['branch_card' => $this->branchCard()]);

        $this->assertSame(1, preg_match_all(
            '#<a href="([^"]+)" target="_blank" rel="noopener" class="([^"]*)">Où coudre les insignes sur l\'uniforme \?#u',
            $html,
            $m
        ), 'The insignia link is on the branch card, once.');
        $this->assertSame(ExternalSources::INSIGNIA_PLACEMENT_PAGE, $m[1][0]);
        $this->assertStringNotContainsString('btn', $m[2][0], 'A discreet link, not a second button.');

        $button = strpos($html, 'En savoir plus');
        $link = strpos($html, 'Où coudre les insignes');
        $this->assertNotFalse($button);
        $this->assertGreaterThan($button, $link, 'The link sits under « En savoir plus ».');
        // Inside the same card: no card boundary between the two.
        $this->assertStringNotContainsString('class="card', substr($html, $button, $link - $button));
    }

    public function testNoInsigniaLinkWithoutABranchCard(): void
    {
        $html = $this->render($this->makeMember());

        $this->assertStringNotContainsString(ExternalSources::INSIGNIA_PLACEMENT_PAGE, $html);
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
     * The leadership module's formation card is self-only, and the
     * template's own `is_self` test is the second of the two locks (the
     * first is MemberPageService, which does not even build the data for
     * anybody else). Both are here on purpose: this page renders for a
     * chief/admin too, so the route's authorization is not the question.
     */
    public function testTheFormationCardIsRenderedOnTheMembersOwnPage(): void
    {
        $html = $this->render($this->makeMember(), true, [
            'formation_path' => new FormationPathView(
                steps: [
                    ['label' => 'T1', 'reached' => true, 'current' => false],
                    ['label' => 'T2', 'reached' => true, 'current' => true],
                    ['label' => 'T3', 'reached' => false, 'current' => false],
                    ['label' => 'BACV', 'reached' => false, 'current' => false],
                ],
                currentLabel: 'Deuxième étape (T2) atteinte',
                nextLabel: 'T3',
                isRecognised: true,
            ),
        ]);

        $this->assertStringContainsString('Mon niveau de formation', $html);
        $this->assertStringContainsString('Deuxième étape (T2) atteinte', $html);
    }

    /**
     * Roadmap IT-20: the card shows the level reached and nothing else.
     * The path stopped being a single queue when Pi-days and the Woodbadge
     * got boxes of their own, so drawing milestones and a next step here
     * would promise a sequence that does not exist for everybody — the
     * tracking stays on the Encadrement page, which is built for it.
     */
    public function testTheCardShowsNeitherMilestonesNorANextStep(): void
    {
        $html = $this->render($this->makeMember(), true, [
            'formation_path' => new FormationPathView(
                steps: [
                    ['label' => 'T1', 'reached' => true, 'current' => false],
                    ['label' => 'T2', 'reached' => true, 'current' => true],
                    ['label' => 'T3', 'reached' => false, 'current' => false],
                    ['label' => 'BACV', 'reached' => false, 'current' => false],
                ],
                currentLabel: 'Deuxième étape (T2) atteinte',
                nextLabel: 'T3',
                isRecognised: true,
            ),
        ]);

        $this->assertStringNotContainsString('Prochaine étape connue', $html);
        $this->assertStringNotContainsString('rounded-pill', $html);
        $this->assertStringNotContainsString('étape actuelle', $html);
    }

    public function testTheFormationCardIsAbsentWhenSomebodyElseIsLooking(): void
    {
        $html = $this->render($this->makeMember(), false, [
            'formation_path' => new FormationPathView(
                steps: [['label' => 'T1', 'reached' => true, 'current' => true]],
                currentLabel: 'Première étape (T1) atteinte',
                nextLabel: 'T2',
                isRecognised: true,
            ),
        ]);

        $this->assertStringNotContainsString('Mon niveau de formation', $html);
        $this->assertStringNotContainsString('Première étape (T1) atteinte', $html);
    }

    /**
     * An unresolvable Desk value says so, and nothing is deduced from it.
     */
    public function testAnUnrecognisedLevelSaysSoAndDeducesNothing(): void
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
        $this->assertStringContainsString("Votre équipe", $html);
    }

    public function testTheCardIsAbsentAltogetherWhenTheModuleIsDisabled(): void
    {
        $html = $this->render($this->makeMember(), true);

        $this->assertStringNotContainsString('Mon niveau de formation', $html);
    }

    /**
     * « Communications récentes » lists a communication nothing of which
     * left for this member (issue #999) — and must say so, never let it
     * read as sent.
     */
    /**
     * An email imported from a former site (data_migration, D11) carries
     * the « Importé » badge on the member page, as in the mass-mail list.
     */
    public function testAnImportedCommunicationCarriesTheImportedBadge(): void
    {
        $item = ['id' => 8, 'subject' => "Infos camp d'été 2024", 'sent_at' => '2024-05-21 18:30:00',
            'section_name' => 'Éclaireurs', 'merge_purged' => false, 'not_sent' => false];
        $context = ['mass_mail_enabled' => true];

        $imported = $this->render(
            $this->makeMember(),
            true,
            $context + ['recent_mass_mail_emails' => [$item + ['imported' => true]]],
        );
        $native = $this->render(
            $this->makeMember(),
            true,
            $context + ['recent_mass_mail_emails' => [$item + ['imported' => false]]],
        );

        $this->assertStringContainsString('>Importé<', $imported);
        $this->assertStringNotContainsString('>Importé<', $native);
    }

    /**
     * An imported communication the former site recorded as failed reads
     * « Non envoyée » like a native one, never as sent.
     */
    public function testAnImportedCommunicationRecordedAsFailedIsMarkedNonEnvoyee(): void
    {
        $item = ['id' => 9, 'subject' => 'Rappel', 'sent_at' => '2024-05-21 18:30:00',
            'section_name' => 'Éclaireurs', 'merge_purged' => false, 'not_sent' => false, 'imported' => true];
        $context = ['mass_mail_enabled' => true];

        $failed = $this->render(
            $this->makeMember(),
            true,
            $context + ['recent_mass_mail_emails' => [$item + ['failed' => true]]],
        );
        $unknown = $this->render(
            $this->makeMember(),
            true,
            $context + ['recent_mass_mail_emails' => [$item + ['failed' => false]]],
        );

        $this->assertStringContainsString('Non envoyée', $failed);
        $this->assertStringNotContainsString('Non envoyée', $unknown);
    }

    public function testACommunicationNotSentIsMarkedNonEnvoyee(): void
    {
        $item = ['id' => 7, 'subject' => 'Réunion', 'sent_at' => '2026-01-01 10:00:00', 'section_name' => 'Meute A', 'merge_purged' => false];
        $context = ['mass_mail_enabled' => true];

        $notSent = $this->render($this->makeMember(), true, $context + ['recent_mass_mail_emails' => [$item + ['not_sent' => true]]]);
        $sent = $this->render($this->makeMember(), true, $context + ['recent_mass_mail_emails' => [$item + ['not_sent' => false]]]);

        $this->assertStringContainsString('Réunion', $notSent);
        $this->assertStringContainsString('Non envoyée', $notSent);
        $this->assertStringContainsString('Réunion', $sent);
        $this->assertStringNotContainsString('Non envoyée', $sent);
    }

    /**
     * Section card of the member page (« Informations essentielles »).
     *
     * @return array<string, mixed>
     */
    private function sectionInfo(?MemberProfile $responsable): array
    {
        return [
            'section' => ['id' => 5, 'name' => 'Louveteaux', 'desk_code' => 'L1', 'email' => null],
            'responsable' => $responsable,
            'badge_assignments' => [],
            'referent_holders' => [],
            'next_event' => null,
            'functions_this_year' => [],
        ];
    }

    private function responsable(bool $withAddress): MemberProfile
    {
        $addresses = $withAddress
            ? [new \Core\Member\MemberAddress('home', 'Rue de la Paix', '12', null, null, '5000', 'Namur', 'Belgique')]
            : [];

        return new MemberProfile(
            memberYearId: 2, memberId: 2, deskId: 'D2', firstName: 'marie', lastName: 'durand',
            totem: 'Akela', quali: null, gender: null, birthDate: null,
            phone: null, mobile: null, email: null,
            patrol: null, formationLevel: null, federationMailConsent: false, unitMailConsent: false,
            addresses: $addresses, functions: [],
            scoutYearLabel: '2025-2026'
        );
    }

    /**
     * The « Informations essentielles » block whose label reads $label, as
     * the DOM element that holds the label and its value.
     */
    private function essentialBlock(string $html, string $label): ?\DOMElement
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query(
            "//h2[normalize-space(.)='Informations essentielles']/following::span"
            . "[contains(concat(' ', normalize-space(@class), ' '), ' text-body-secondary ')]"
            . "[normalize-space(.)='" . $label . "'][1]/parent::div"
        );

        return $nodes !== false && $nodes->length > 0 && $nodes->item(0) instanceof \DOMElement
            ? $nodes->item(0)
            : null;
    }

    /**
     * @return list<string> the class tokens of the element's direct children
     *         that carry text, keyed by their visible text
     */
    private function classesOf(\DOMElement $block, string $containing): array
    {
        $xpath = new \DOMXPath($block->ownerDocument);
        $found = $xpath->query('.//*[contains(normalize-space(.), ' . "'" . $containing . "'" . ')][not(*)]', $block);
        $this->assertNotFalse($found);
        $this->assertGreaterThan(0, $found->length, 'no element holds « ' . $containing . ' »');
        $element = $found->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element);

        return array_values(array_filter(explode(' ', $element->getAttribute('class'))));
    }

    public function testTheSectionHeadValueIsSmallLikeTheOtherValuesOfTheCard(): void
    {
        $html = $this->render($this->makeMember(), true, ['section_info' => $this->sectionInfo(null)]);

        $block = $this->essentialBlock($html, 'Section');
        $this->assertNotNull($block);
        $classes = $this->classesOf($block, 'Louveteaux');
        $this->assertContains('small', $classes);
        $this->assertNotContains('text-body-secondary', $classes);
    }

    public function testTheSectionLeaderNameAndAddressUseTheSameValueStyleAsTheOtherValues(): void
    {
        $html = $this->render(
            $this->makeMember(),
            true,
            ['section_info' => $this->sectionInfo($this->responsable(true))]
        );

        $block = $this->essentialBlock($html, 'Responsable');
        $this->assertNotNull($block);

        $name = $this->classesOf($block, 'Akela');
        $this->assertContains('small', $name);
        $this->assertNotContains('text-body-secondary', $name);

        // The address stays under the name, in the value style: small, and
        // no longer the grey a label wears (issue #1347).
        $address = $this->classesOf($block, 'Rue de la Paix');
        $this->assertContains('small', $address);
        $this->assertNotContains('text-body-secondary', $address);
        $this->assertStringContainsString('Namur', $block->textContent);
    }

    public function testTheLeaderLabelKeepsTheDiscreetLabelStyle(): void
    {
        $html = $this->render(
            $this->makeMember(),
            true,
            ['section_info' => $this->sectionInfo($this->responsable(true))]
        );

        $block = $this->essentialBlock($html, 'Responsable');
        $this->assertNotNull($block, 'the label must keep the label colour');
    }

    public function testALeaderWithoutAnAddressShowsTheNameAndNoAddressLine(): void
    {
        $html = $this->render(
            $this->makeMember(),
            true,
            ['section_info' => $this->sectionInfo($this->responsable(false))]
        );

        $block = $this->essentialBlock($html, 'Responsable');
        $this->assertNotNull($block);
        $this->assertContains('small', $this->classesOf($block, 'Akela'));
        $this->assertStringNotContainsString('Namur', $block->textContent);
        $this->assertStringNotContainsString('Rue de la Paix', $block->textContent);
        $xpath = new \DOMXPath($block->ownerDocument);
        $lines = $xpath->query('./div', $block);
        $this->assertNotFalse($lines);
        $this->assertSame(0, $lines->length, 'no address line without an address');
    }

    public function testASectionWithoutALeaderHasNoLeaderBlock(): void
    {
        $html = $this->render($this->makeMember(), true, ['section_info' => $this->sectionInfo(null)]);

        $this->assertNull($this->essentialBlock($html, 'Responsable'));
        $this->assertNotNull($this->essentialBlock($html, 'Section'));
    }
}
