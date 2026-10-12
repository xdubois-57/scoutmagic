<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestEmailTemplateService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The four request e-mails of « Gabarits » (issue #843, D10): the
 * catalogue, the three states, saving and restoring a body, the
 * substitution every sender goes through — and the one property the page
 * exists to keep, that « À rédiger » on screen and the sender's guard are
 * the same judgement.
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
        // Only the two submission e-mails ship with a body.
        $this->assertSame([true, true, false, false], array_map(static fn($t) => $t->hasDefault(), $templates));
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

        foreach ([self::RECEIPT, self::ALERT] as $key) {
            $template = $this->service->findByKey($key);
            $this->assertSame([], $this->service->unknownVariablesIn($template, (string) $template->defaultBody), $key);
        }
    }

    public function testFreshInstallStates(): void
    {
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::RECEIPT));
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::ALERT));
        $this->assertSame(RequestEmailTemplateService::STATE_TO_WRITE, $this->service->stateOf(self::ACCEPTED));
        $this->assertSame(RequestEmailTemplateService::STATE_TO_WRITE, $this->service->stateOf(self::REFUSED));
        $this->assertTrue($this->service->isToWrite(self::ACCEPTED));
        $this->assertFalse($this->service->isToWrite(self::RECEIPT));
        $this->assertSame(
            $this->service->findByKey(self::RECEIPT)->defaultBody,
            $this->service->bodyFor(self::RECEIPT)
        );
        $this->assertNull($this->service->bodyFor(self::ACCEPTED));
    }

    public function testSavingABodyMakesItCustomAndStoresItSanitised(): void
    {
        $this->service->save(self::ACCEPTED, '<p>Bienvenue {{ prenom_enfant }} !</p><script>alert(1)</script>', 7);

        $this->assertSame(RequestEmailTemplateService::STATE_CUSTOM, $this->service->stateOf(self::ACCEPTED));
        $this->assertFalse($this->service->isToWrite(self::ACCEPTED));
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
        $this->assertTrue($this->service->isToWrite(self::REFUSED));
    }

    public function testABlankStoredBodyIsStillToWriteOrDefault(): void
    {
        // Written by another path (the generic editable-content endpoint):
        // what renders as nothing is not a body.
        $this->content->set(self::ACCEPTED, '<p>&nbsp;</p>', 'rich_text', 1);
        $this->content->set(self::RECEIPT, '<p>&nbsp;</p>', 'rich_text', 1);

        $this->assertTrue($this->service->isToWrite(self::ACCEPTED));
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::RECEIPT));
        $this->assertSame(
            $this->service->findByKey(self::RECEIPT)->defaultBody,
            $this->service->bodyFor(self::RECEIPT)
        );
    }

    public function testSavingTheDefaultAgainLeavesTheTemplateOnTheDefault(): void
    {
        $this->service->save(self::RECEIPT, '<p>Mon texte {{ prenom_enfant }}</p>', 7);
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

    public function testRestoringTheDefaultForgetsTheUnitsBody(): void
    {
        $this->service->save(self::ALERT, '<p>Nouvelle demande : {{ prenom_enfant }}</p>', 7);

        $this->service->restoreDefault(self::ALERT);

        $this->assertNull($this->content->get(self::ALERT));
        $this->assertSame(RequestEmailTemplateService::STATE_DEFAULT, $this->service->stateOf(self::ALERT));
    }

    public function testADecisionEmailHasNoDefaultToRestore(): void
    {
        $this->service->save(self::ACCEPTED, '<p>Bienvenue</p>', 7);

        $this->expectException(RegistrationException::class);
        try {
            $this->service->restoreDefault(self::ACCEPTED);
        } finally {
            $this->assertSame('<p>Bienvenue</p>', $this->content->get(self::ACCEPTED), 'nothing was deleted');
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
     * The point of IT-07's « une seule définition » : whatever is stored,
     * the page's « À rédiger » and the sender's refusal agree.
     *
     * @return array<string, array{string|null, bool}>
     */
    public static function storedBodies(): array
    {
        return [
            'nothing stored' => [null, true],
            'an emptied paragraph' => ['<p>&nbsp;</p>', true],
            'only tags' => ['<p><br></p>', true],
            'a real body' => ['<p>Bonjour {{ prenom_enfant }}</p>', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storedBodies')]
    public function testThePageAndTheSendersGuardAreOneJudgement(?string $stored, bool $toWrite): void
    {
        if ($stored !== null) {
            $this->content->set(self::ACCEPTED, $stored, 'rich_text', 1);
            $this->content->set(self::REFUSED, $stored, 'rich_text', 1);
        }
        $sender = new RequestEmailService(
            new RegistrationRequestRepository(
                $this->pdo,
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
            ),
            $this->createStub(MailService::class),
            $this->content,
            new JournalService(new JournalRepository($this->pdo)),
            'https://exemple.be',
            'Unité Test',
            new \Modules\Registration\Service\DecisionEmailService(
                new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo),
                new JournalService(new JournalRepository($this->pdo))
            )
        );

        $this->assertSame($toWrite, $this->service->isToWrite(self::ACCEPTED));
        $this->assertSame(
            $toWrite,
            $this->service->stateOf(self::ACCEPTED) === RequestEmailTemplateService::STATE_TO_WRITE
        );
        $this->assertSame(!$toWrite, $sender->isAcceptedBodyReady());
        $this->assertSame(!$toWrite, $sender->isRefusedBodyReady());
    }
}
