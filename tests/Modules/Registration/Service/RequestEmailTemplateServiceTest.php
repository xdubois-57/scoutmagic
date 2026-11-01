<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailTemplateService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The four request e-mails of « Gabarits » (issue #843, D10): the
 * catalogue, the two states, saving and restoring a body, the substitution
 * every sender goes through — and, since issue #932, the rule that the three
 * e-mails to a family carry `{{ lien_suivi }}` while the unit's alert never
 * does.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RequestEmailTemplateServiceTest extends TestCase
{
    private const ACCEPTED = RequestEmailTemplateService::KEY_ACCEPTED;
    private const REFUSED = RequestEmailTemplateService::KEY_REFUSED;
    private const RECEIPT = RequestEmailTemplateService::KEY_RECEIPT;
    private const ALERT = RequestEmailTemplateService::KEY_UNIT_ALERT;

    private \PDO $pdo;
    private EditableContentService $content;
    private RequestEmailTemplateService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->content = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->service = new RequestEmailTemplateService($this->content);
    }

    public function testTheCatalogueHoldsTheFourRequestEmailsInTheMockUpsOrder(): void
    {
        $templates = $this->service->all();

        $this->assertSame(
            [self::RECEIPT, self::ALERT, self::ACCEPTED, self::REFUSED],
            array_map(static fn($t) => $t->key, $templates)
        );
        $this->assertSame(
            [
                'Accusé de réception de la demande',
                'Alerte à l\'unité',
                'Acceptation de la demande',
                'Refus de la demande',
            ],
            array_map(static fn($t) => $t->name, $templates)
        );
        $this->assertSame(
            ['accuse-de-reception', 'alerte-unite', 'acceptation', 'refus'],
            array_map(static fn($t) => $t->slug, $templates)
        );
        // All four ship with a body (issue #932); the three that go to a
        // family must carry the tracking link, the unit's alert must not.
        $this->assertSame(
            [true, false, true, true],
            array_map(static fn($t) => $t->requiresTrackingLink(), $templates)
        );
        $this->assertSame('refus', $this->service->findBySlug('refus')?->slug);
        $this->assertNull($this->service->findBySlug('inconnu'));
    }

    /**
     * Each template declares exactly the variables its sender hands over —
     * and its shipped body uses only those.
     */
    public function testEachTemplateDeclaresTheVariablesItsSenderSubstitutes(): void
    {
        $family = ['prenom_enfant', 'annee_scoute', 'lien_suivi', 'nom_unite'];
        foreach ([self::RECEIPT, self::ACCEPTED, self::REFUSED] as $key) {
            $this->assertSame($family, $this->service->findByKey($key)->variables->keywords(), $key);
        }
        $this->assertSame(
            ['prenom_enfant', 'annee_scoute', 'creneau', 'date_reception', 'lien_fiche'],
            $this->service->findByKey(self::ALERT)->variables->keywords()
        );

        foreach ([self::RECEIPT, self::ALERT, self::ACCEPTED, self::REFUSED] as $key) {
            $template = $this->service->findByKey($key);
            $this->assertSame([], $this->service->unknownVariablesIn($template, $template->defaultBody), $key);
        }
    }

    /**
     * Issue #932: the shipped text of each family e-mail carries the link,
     * the unit's alert carries the staff fiche's link and never the
     * family's secret.
     */
    public function testEveryFamilyDefaultCarriesTheTrackingLinkAndTheAlertDoesNot(): void
    {
        foreach ([self::RECEIPT, self::ACCEPTED, self::REFUSED] as $key) {
            $default = $this->service->findByKey($key)->defaultBody;
            $this->assertTrue(RequestEmailTemplateService::hasTrackingLink($default), $key);
            $this->assertStringContainsString('href="{{ lien_suivi }}"', $default, $key);
        }

        $alert = $this->service->findByKey(self::ALERT)->defaultBody;
        $this->assertStringNotContainsString('lien_suivi', $alert);
        $this->assertStringContainsString('{{ lien_fiche }}', $alert);
        $this->assertFalse(RequestEmailTemplateService::hasTrackingLink($alert));
    }

    public function testTheAcceptanceAndRefusalDefaultsSayWhatTheyAnnounce(): void
    {
        $accepted = RequestEmailTemplateService::substitute($this->service->bodyFor(self::ACCEPTED), [
            'prenom_enfant' => 'Léa', 'annee_scoute' => '2026-2027', 'nom_unite' => 'Unité Test',
            'lien_suivi' => 'https://exemple.be/inscriptions/suivi/4/abc',
        ]);
        $refused = RequestEmailTemplateService::substitute($this->service->bodyFor(self::REFUSED), [
            'prenom_enfant' => 'Léa', 'annee_scoute' => '2026-2027', 'nom_unite' => 'Unité Test',
            'lien_suivi' => 'https://exemple.be/inscriptions/suivi/4/abc',
        ]);

        $this->assertStringContainsString('Léa', $accepted);
        $this->assertStringContainsString('a été acceptée', $accepted);
        $this->assertStringContainsString('href="https://exemple.be/inscriptions/suivi/4/abc"', $accepted);
        $this->assertStringContainsString("n'a pas été retenue", $refused);
        $this->assertStringContainsString('href="https://exemple.be/inscriptions/suivi/4/abc"', $refused);
        $this->assertStringNotContainsString('{{', $accepted . $refused);
        // No refusal reason is invented.
        $this->assertStringNotContainsString('place', $refused);
    }

    public function testFreshInstallStates(): void
    {
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::RECEIPT));
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::ALERT));
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::ACCEPTED));
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::REFUSED));
        foreach ([self::RECEIPT, self::ALERT, self::ACCEPTED, self::REFUSED] as $key) {
            $this->assertSame($this->service->findByKey($key)->defaultBody, $this->service->bodyFor($key), $key);
        }
    }

    public function testSavingABodyMakesItCustomAndStoresItSanitised(): void
    {
        $this->service->save(
            self::ACCEPTED,
            '<p>Bienvenue {{ prenom_enfant }} ! {{ lien_suivi }}</p><script>alert(1)</script>',
            7
        );

        $this->assertSame(RequestEmailTemplateService::STATE_CUSTOM, $this->service->stateOf(self::ACCEPTED));
        $stored = (string) $this->content->get(self::ACCEPTED);
        $this->assertStringContainsString('Bienvenue {{ prenom_enfant }} !', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertSame($stored, $this->service->bodyFor(self::ACCEPTED));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankBodies(): array
    {
        return [
            'empty' => [''],
            'an emptied paragraph' => ['<p>&nbsp;</p>'],
            'a raw no-break space' => ["<p>\u{00A0} </p>"],
            'only a script, dropped by the sanitiser' => ['<script>Bonjour</script>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blankBodies')]
    public function testABodyThatRendersAsNothingIsRefusedAndNothingIsStored(string $html): void
    {
        try {
            $this->service->save(self::REFUSED, $html, 7);
            $this->fail('a blank body was accepted');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('ne peut pas être vide', $e->getMessage());
        }

        $this->assertNull($this->content->get(self::REFUSED));
        $this->assertSame($this->service->findByKey(self::REFUSED)->defaultBody, $this->service->bodyFor(self::REFUSED));
    }

    public function testABlankStoredBodyFallsBackToTheDefault(): void
    {
        // Written by another path (the generic editable-content endpoint):
        // what renders as nothing is not a body, so the default goes out.
        $this->content->set(self::ACCEPTED, '<p>&nbsp;</p>', 'rich_text', 1);
        $this->content->set(self::RECEIPT, '<p>&nbsp;</p>', 'rich_text', 1);

        foreach ([self::ACCEPTED, self::RECEIPT] as $key) {
            $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf($key), $key);
            $this->assertSame($this->service->findByKey($key)->defaultBody, $this->service->bodyFor($key), $key);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesWithTheLink(): array
    {
        return [
            'spaced' => ['<p>Suivez : {{ lien_suivi }}</p>'],
            'unspaced, as typed by hand' => ['<p>Suivez : {{lien_suivi}}</p>'],
            'in a link target' => ['<p><a href="{{ lien_suivi }}">suivre</a></p>'],
            'in a link, percent-encoded by the sanitiser' => ['<p><a href="%7B%7B%20lien_suivi%20%7D%7D">suivre</a></p>'],
            'split by markup the editor dropped inside it' => ['<p>Suivez : {{ lien<b>_</b>suivi }}</p>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesWithTheLink')]
    public function testAFamilyBodyCarryingTheLinkInAnySpellingIsSaved(string $html): void
    {
        $this->assertTrue(RequestEmailTemplateService::hasTrackingLink($html));

        $this->service->save(self::ACCEPTED, $html, 7);

        $this->assertSame(RequestEmailTemplateService::STATE_CUSTOM, $this->service->stateOf(self::ACCEPTED));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesWithoutTheLink(): array
    {
        return [
            'no variable at all' => ['<p>Bienvenue !</p>'],
            'another variable' => ['<p>Bienvenue {{ prenom_enfant }}</p>'],
            'a misspelt link variable' => ['<p>Suivez : {{ lien_de_suivi }}</p>'],
            'the staff fiche link instead' => ['<p>{{ lien_fiche }}</p>'],
            'the name without braces' => ['<p>Suivez : lien_suivi</p>'],
            'the variable inside a script the sanitiser drops' => ['<p>Bienvenue</p><script>{{ lien_suivi }}</script>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesWithoutTheLink')]
    public function testAFamilyBodyWithoutTheLinkIsRefusedWithAHelpfulMessageAndNothingIsStored(string $html): void
    {
        foreach ([self::RECEIPT, self::ACCEPTED, self::REFUSED] as $key) {
            try {
                $this->service->save($key, $html, 7);
                $this->fail("a body without the link was accepted for {$key}");
            } catch (RegistrationException $e) {
                $this->assertSame(RequestEmailTemplateService::MISSING_LINK_MESSAGE, $e->getMessage());
                $this->assertStringContainsString('{{ lien_suivi }}', $e->getMessage());
                $this->assertStringContainsString('Rétablir le texte par défaut', $e->getMessage());
            }

            $this->assertNull($this->content->get($key), $key);
        }
    }

    public function testTheUnitAlertNeedsNoTrackingLinkAndSavesWithout(): void
    {
        $this->service->save(self::ALERT, '<p>Nouvelle demande : {{ prenom_enfant }} {{ lien_fiche }}</p>', 7);

        $this->assertSame(RequestEmailTemplateService::STATE_CUSTOM, $this->service->stateOf(self::ALERT));
    }

    /**
     * The link is judged on what would be STORED: a body that arrived with
     * the variable inside an element the sanitiser drops leaves without it.
     */
    public function testTheLinkIsJudgedAfterSanitisation(): void
    {
        $this->expectException(RegistrationException::class);

        $this->service->save(self::REFUSED, '<p>Désolés</p><script>{{ lien_suivi }}</script>', 7);
    }

    public function testSavingTheDefaultAgainLeavesTheTemplateOnTheDefault(): void
    {
        $this->service->save(self::RECEIPT, '<p>Mon texte {{ prenom_enfant }} {{ lien_suivi }}</p>', 7);
        $this->assertSame(RequestEmailTemplateService::STATE_CUSTOM, $this->service->stateOf(self::RECEIPT));

        // What the editor posts back: the default, its placeholders spaced
        // differently and its link encoded by the sanitiser.
        $default = (string) $this->service->findByKey(self::RECEIPT)->defaultBody;
        $resent = str_replace('{{ prenom_enfant }}', '{{prenom_enfant}}', $default);
        $this->assertNotSame($default, $resent);
        $this->service->save(self::RECEIPT, $resent, 7);

        $this->assertNull($this->content->get(self::RECEIPT), 'the row is gone: the default is followed');
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::RECEIPT));
    }

    public function testAnEncodedSpellingOfTheDefaultStillRemovesTheOverride(): void
    {
        $this->service->save(self::RECEIPT, '<p>Mon texte {{prenom_enfant}} {{lien_suivi}}</p>', 7);
        $default = (string) $this->service->findByKey(self::RECEIPT)->defaultBody;
        $encoded = str_replace(
            ['{{ prenom_enfant }}', '{{ lien_suivi }}'],
            ['{{%20pren%6fm_enfant%20}}', '%7B%7B%20lien_suivi%20%7D%7D'],
            $default
        );

        $this->service->save(self::RECEIPT, $encoded, 7);

        $this->assertSame([null, RequestEmailTemplateService::STATE_DEFAULT], [
            $this->content->get(self::RECEIPT),
            $this->service->stateOf(self::RECEIPT),
        ]);
    }

    public function testUnknownVariableWarningsRespectEachTokensEncodingExactlyOnce(): void
    {
        $body = 'Élise : {{%70renom_enfant}} {{ Prenom enfant }} %7B%7B%2570renom_enfant%7D%7D';

        $this->assertSame(['Prenom enfant', '%70renom_enfant'], $this->service->unknownVariablesIn(
            $this->service->findByKey(self::RECEIPT),
            $body
        ));
    }

    public function testRestoringTheDefaultForgetsTheUnitsBody(): void
    {
        $this->service->save(self::ALERT, '<p>Nouvelle demande : {{ prenom_enfant }}</p>', 7);

        $this->service->restoreDefault(self::ALERT);

        $this->assertNull($this->content->get(self::ALERT));
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::ALERT));
    }

    /**
     * Issue #932: « Rétablir le texte par défaut » gives a working link
     * back, on the acceptance and the refusal as on the receipt.
     */
    public function testRestoringADecisionDefaultGivesTheLinkBack(): void
    {
        foreach ([self::RECEIPT, self::ACCEPTED, self::REFUSED] as $key) {
            $this->service->save($key, '<p>Mon texte {{ lien_suivi }}</p>', 7);
            $this->assertSame(RequestEmailTemplateService::STATE_CUSTOM, $this->service->stateOf($key), $key);

            $this->service->restoreDefault($key);

            $this->assertNull($this->content->get($key), $key);
            $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf($key), $key);
            $this->assertTrue(RequestEmailTemplateService::hasTrackingLink($this->service->bodyFor($key)), $key);
        }
    }

    /**
     * Every spelling a variable reaches storage in: inserted by the editor
     * (spaced), typed by hand (unspaced), inside a link (percent-encoded by
     * the sanitiser), split by markup the editor dropped inside it. The
     * value is escaped; a name nobody declared stays visible.
     */
    public function testSubstitutionReadsEverySpellingAndEscapesTheValue(): void
    {
        $body = '<p>{{ prenom_enfant }} / {{prenom_enfant}} / {{ pre<b>nom</b>_enfant }}</p>'
            . '<p><a href="%7B%7B%20lien_suivi%20%7D%7D">suivre</a> {{ inconnu }}</p>';

        $html = RequestEmailTemplateService::substitute($body, [
            'prenom_enfant' => 'Maëlle <b>',
            'lien_suivi' => 'https://exemple.be/suivi/1/abc?x=1&y=2',
        ]);

        $this->assertSame(3, substr_count($html, 'Maëlle &lt;b&gt;'));
        $this->assertStringContainsString('href="https://exemple.be/suivi/1/abc?x=1&amp;y=2"', $html);
        $this->assertStringContainsString('{{ inconnu }}', $html);
        $this->assertStringNotContainsString('prenom_enfant', $html);
    }

    public function testTheEditPageNamesTheVariablesATemplateDoesNotDeclare(): void
    {
        $template = $this->service->findByKey(self::ACCEPTED);

        $this->assertSame(
            ['creneau', 'lien_fiche', 'Prenom_enfant', 'prenom enfant', 'prenon'],
            $this->service->unknownVariablesIn(
                $template,
                '<p>{{ prenom_enfant }} {{ creneau }} <a href="%7B%7Blien_fiche%7D%7D">x</a></p>'
                    . '<p>{{ Prenom_enfant }} {{ prenom enfant }} {{ pre<b>non</b> }} {{ pre<b>nom</b>_enfant }}</p>'
            )
        );
    }

    /**
     * A value somebody typed on the public form never becomes a link
     * target: a variable placed in an `href` is substituted after the
     * sanitiser's scheme check, so the substituted body is checked again.
     */
    public function testAFirstNameCannotTurnALinkIntoScript(): void
    {
        $html = RequestEmailTemplateService::substitute(
            '<p><a href="{{ prenom_enfant }}">ici</a> et <a href="{{ lien_suivi }}">suivre</a></p>',
            ['prenom_enfant' => 'javascript:alert(1)', 'lien_suivi' => 'https://exemple.be/suivi/3/xyz']
        );

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<a href="https://exemple.be/suivi/3/xyz">suivre</a>', $html);
    }

    /**
     * A body stored by another path (the generic editable-content
     * endpoint, an old fixture) can lack the link: the page can still read
     * it, and the senders refuse it (RequestEmailServiceTest,
     * RegistrationServiceTest) rather than send it silently.
     */
    public function testAStoredBodyWithoutTheLinkIsReadAsItIsAndJudgedByHasTrackingLink(): void
    {
        $this->content->set(self::ACCEPTED, '<p>Bienvenue</p>', 'rich_text', 1);

        $this->assertSame('<p>Bienvenue</p>', $this->service->bodyFor(self::ACCEPTED));
        $this->assertFalse(RequestEmailTemplateService::hasTrackingLink($this->service->bodyFor(self::ACCEPTED)));
    }
}
