<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Template\EmailTemplateCustomisationService;
use Core\Mail\Template\EmailTemplateException;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Security\HtmlSanitizer;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two paths through the renderer, and the line between them.
 *
 * The one that has to be proved rather than described is the FIRST: with
 * nothing customised, a migrated sender must produce the message it
 * produced before, byte for byte. A rendering that is "equivalent" is a
 * rewrite wearing a refactor's clothes, and nobody would notice until a
 * unit's e-mails started looking subtly different.
 *
 * The second is a security boundary: stored content is substituted as a
 * string and never evaluated, because an administration page that could
 * define a Twig template would be an administration page that could run
 * code.
 *
 * @group database
 */
class EmailTemplateRendererTest extends TestCase
{
    private \PDO $pdo;
    private \Twig\Environment $twig;
    private EmailTemplateRegistry $registry;
    private EmailTemplateOverrideRepository $overrides;
    private EmailTemplateRenderer $renderer;
    private JournalService $journal;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates');
        $this->registry = new EmailTemplateRegistry();
        $this->overrides = new EmailTemplateOverrideRepository($this->pdo);
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->renderer = new EmailTemplateRenderer($this->twig, $this->registry, $this->overrides, $this->journal);
    }

    // ── nothing customised: the shipped template, unchanged ───────────

    /**
     * @dataProvider shippedTemplates
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shippedTemplates')]
    public function testWithNoCustomisationTheOutputIsTheShippedRenderingByteForByte(
        string $templateId,
        string $htmlTemplate,
        string $textTemplate,
        string $subject,
        array $context
    ): void {
        $email = $this->renderer->render($templateId, $context);

        self::assertSame($subject, $email->subject);
        self::assertSame($this->twig->render($htmlTemplate, $context), $email->bodyHtml);
        self::assertSame($this->twig->render($textTemplate, $context), $email->bodyText);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>}>
     */
    public static function shippedTemplates(): array
    {
        return [
            'magic link' => [
                'magic_link',
                'email/magic_link.html.twig',
                'email/magic_link.text.twig',
                'Votre lien de connexion',
                ['site_name' => 'Unité Test', 'magic_link_url' => 'https://u.example/auth/verify?token=abc', 'expiry_minutes' => 15],
            ],
            'super admin granted' => [
                'super_admin_granted',
                'email/super_admin_granted.html.twig',
                'email/super_admin_granted.text.twig',
                'Vous êtes administrateur du site',
                ['site_name' => 'Unité Test', 'granted_by' => 'akela@example.be', 'login_url' => 'https://u.example/login'],
            ],
        ];
    }

    // ── customised: the stored subject and body win ───────────────────

    public function testACustomisedEmailTakesItsSubjectAndBodyFromTheDatabase(): void
    {
        $this->overrides->save(
            'super_admin_granted',
            'Bienvenue parmi les administrateurs',
            '<p>Bonjour, {{ granted_by }} vous a donné accès. Voici votre lien : {{ login_url }}</p>',
            null
        );

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'Unité Test',
            'granted_by' => 'akela@example.be',
            'login_url' => 'https://u.example/login',
        ]);

        self::assertSame('Bienvenue parmi les administrateurs', $email->subject);
        self::assertStringContainsString('akela@example.be vous a donné accès', $email->bodyHtml);
        self::assertStringContainsString('https://u.example/login', $email->bodyHtml);
        self::assertStringNotContainsString('{{', $email->bodyHtml);

        // The frame is still code: the unit's name comes from
        // email/base.html.twig, which a customisation cannot reach.
        self::assertStringContainsString('Unité Test', $email->bodyHtml);
        self::assertStringContainsString('<!DOCTYPE html>', $email->bodyHtml);
    }

    public function testACustomisedSubjectIsSubstitutedToo(): void
    {
        $this->overrides->save('super_admin_granted', 'Accès accordé par {{ granted_by }}', '<p>Bonjour.</p>', null);

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'U', 'granted_by' => 'akela@example.be', 'login_url' => 'https://u.example/login',
        ]);

        self::assertSame('Accès accordé par akela@example.be', $email->subject);
    }

    /**
     * The security boundary. Stored content is a string that gets
     * substituted, never a template that gets evaluated — so Twig syntax
     * in it comes back out exactly as typed.
     */
    public function testTwigSyntaxInAStoredBodyComesBackLiterally(): void
    {
        $this->overrides->save(
            'super_admin_granted',
            'Sujet {{ 7 * 7 }}',
            '<p>{{ 7 * 7 }} et {% if true %}oui{% endif %} et {{ app.request }}</p>',
            null
        );

        $email = $this->renderer->render('super_admin_granted', ['site_name' => 'U']);

        self::assertStringContainsString('{{ 7 * 7 }}', $email->subject);
        self::assertStringNotContainsString('49', $email->subject);
        self::assertStringContainsString('{{ 7 * 7 }}', $email->bodyHtml);
        self::assertStringContainsString('{% if true %}oui{% endif %}', $email->bodyHtml);
        self::assertStringNotContainsString('49', $email->bodyHtml);
    }

    /**
     * With `member` and `member_name` both declared, replacing the short
     * one first would eat the start of the long one. Nothing core declares
     * such a pair today, which is exactly why this is pinned with a
     * purpose-built registry rather than left to be discovered.
     */
    public function testAVariableWhoseNameIsAPrefixOfAnotherIsNotEatenByIt(): void
    {
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleTemplates('demo', 'Démo', [[
            'id' => 'demo.overlap',
            'label' => 'Chevauchement',
            'description' => 'Deux variables dont l\'une préfixe l\'autre.',
            'default_subject' => 'Sujet',
            'template' => 'email/super_admin_revoked.html.twig',
            'editable' => true,
            'variables' => [
                ['name' => 'member', 'label' => 'Membre', 'example' => 'Akéla'],
                ['name' => 'member_name', 'label' => 'Nom complet', 'example' => 'Akéla Loup'],
            ],
        ]]);

        $overrides = new EmailTemplateOverrideRepository($this->pdo);
        $overrides->save('demo.overlap', 'Sujet', '<p>{{ member }} / {{ member_name }}</p>', null);

        $email = (new EmailTemplateRenderer($this->twig, $registry, $overrides))
            ->render('demo.overlap', ['site_name' => 'U', 'member' => 'Akéla', 'member_name' => 'Akéla Loup']);

        self::assertStringContainsString('Akéla / Akéla Loup', $email->bodyHtml);
    }

    public function testAnUndeclaredVariableStaysLiteralAndIsReportedOnce(): void
    {
        $this->overrides->save('super_admin_granted', 'Sujet', '<p>Bonjour {{ prenom }} {{ prenom }}.</p>', null);

        $this->renderer->render('super_admin_granted', ['site_name' => 'U']);
        $this->renderer->render('super_admin_granted', ['site_name' => 'U']);
        $this->renderer->render('super_admin_granted', ['site_name' => 'U']);

        $rows = $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'email_template_unknown_variable'"
        );
        self::assertSame(1, (int) ($rows === false ? 0 : $rows->fetchColumn()));

        $email = $this->renderer->render('super_admin_granted', ['site_name' => 'U']);
        self::assertStringContainsString('{{ prenom }}', $email->bodyHtml);
    }

    public function testTheTextHalfIsDerivedFromTheCustomisedHtml(): void
    {
        $this->overrides->save(
            'super_admin_granted',
            'Sujet',
            '<p>Première ligne.</p><p>Seconde ligne &amp; suite.</p>',
            null
        );

        $email = $this->renderer->render('super_admin_granted', ['site_name' => 'U']);

        self::assertStringNotContainsString('<p>', $email->bodyText);
        self::assertStringContainsString('Première ligne.', $email->bodyText);
        self::assertStringContainsString('Seconde ligne & suite.', $email->bodyText);
        self::assertStringNotContainsString('&amp;', $email->bodyText);
    }

    /**
     * A customised body is HTML, and a substituted value is not markup.
     *
     * The shipped path has always had this: Twig escapes what it prints.
     * The customised path had nothing — the stored text is deliberately
     * never given to Twig — so a renter called « <b>Léo</b> », a manager's
     * sentence, a family's answer to a form went into a DKIM-signed
     * message as live markup. Escaping here is the only place it can
     * happen, and it is what makes the two paths say the same thing.
     */
    public function testAValueSubstitutedIntoACustomisedBodyIsEscaped(): void
    {
        $this->overrides->save('super_admin_granted', 'Sujet', '<p>De la part de {{ granted_by }}.</p>', null);

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'U',
            'granted_by' => '<script>alert(1)</script>',
            'login_url' => 'https://u.example/login',
        ]);

        self::assertStringNotContainsString('<script>', $email->bodyHtml);
        self::assertStringContainsString('&lt;script&gt;', $email->bodyHtml);
        // And it reads correctly again in the text half, which is derived
        // from the HTML: an entity in a text/plain part is a defect.
        self::assertStringContainsString('<script>alert(1)</script>', $email->bodyText);
    }

    /**
     * The same escaping must not maul a URL: an `&` in a query string
     * belongs in an href as `&amp;`, and comes back out as `&` in the
     * text half.
     */
    public function testAUrlSubstitutedIntoAnHrefSurvivesIntact(): void
    {
        $this->overrides->save(
            'super_admin_granted',
            'Sujet',
            '<p><a href="{{ login_url }}">Se connecter</a></p>',
            null
        );

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'U',
            'granted_by' => 'akela@example.be',
            'login_url' => 'https://u.example/login?a=1&b=2',
        ]);

        self::assertStringContainsString('href="https://u.example/login?a=1&amp;b=2"', $email->bodyHtml);
        // And the text half keeps the address beside the label: a
        // plain-text reader told to « Se connecter » with no URL in sight
        // has been told nothing.
        self::assertStringContainsString('Se connecter : https://u.example/login?a=1&b=2', $email->bodyText);
    }

    /**
     * A declared variable carrying several lines — a list of members, the
     * contents of a retrospective — is written by its sender as plain
     * text with line breaks. The shipped half renders it through
     * `|nl2br`; the customised half has no filter to reach for, so the
     * substitution does the same thing and both paths agree.
     */
    public function testLineBreaksInAValueBecomeBreaksInACustomisedBody(): void
    {
        $this->overrides->save('super_admin_granted', 'Sujet', '<p>{{ granted_by }}</p>', null);

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'U',
            'granted_by' => "Camille Dupont\nLouis Dupont",
            'login_url' => 'https://u.example/login',
        ]);

        self::assertStringContainsString('Camille Dupont<br>' . "\n" . 'Louis Dupont', $email->bodyHtml);
    }

    /**
     * A subject line is not markup. The same value that is escaped into
     * the body is used as it is here — « Vous & moi » in an inbox, not
     * « Vous &amp; moi ».
     */
    public function testASubjectIsNotHtmlEscaped(): void
    {
        $this->overrides->save('super_admin_granted', 'Accès accordé par {{ granted_by }}', '<p>.</p>', null);

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'U',
            'granted_by' => 'Akéla & Baloo',
            'login_url' => 'https://u.example/login',
        ]);

        self::assertSame('Accès accordé par Akéla & Baloo', $email->subject);
    }

    /**
     * Both halves of a reworded e-mail are signed. The HTML one gets the
     * closing formula from `email/base.html.twig`, which a customisation
     * cannot reach; the plain-text one is derived from the stored body
     * alone, so without this it was the single version of the message
     * that arrived unsigned.
     */
    public function testACustomisedEmailIsSignedInBothHalves(): void
    {
        $this->overrides->save('super_admin_granted', 'Sujet', '<p>Notre propre texte.</p>', null);

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'Unité Test',
            'granted_by' => 'akela@example.be',
            'login_url' => 'https://u.example/login',
        ]);

        self::assertStringContainsString('Bien à vous', $email->bodyHtml);
        self::assertStringContainsString('Bien à vous', $email->bodyText);
        self::assertStringContainsString('Unité Test', $email->bodyText);
        self::assertStringNotContainsString('<', $email->bodyText);
    }

    /**
     * The call to action of half the e-mails this site sends is a link
     * whose address IS a variable. What the editor stores is not what the
     * administrator typed: the sanitizer serialises through DOM, and DOM
     * percent-encodes a URL attribute — `href="%7B%7B%20login_url%20%7D%7D"`.
     * Matched only in its literal spelling, the placeholder was never
     * substituted, and every customised e-mail went out with its button
     * pointing at the escaped placeholder itself.
     */
    public function testAPlaceholderInsideALinkSurvivesTheSanitisersUrlEncoding(): void
    {
        $stored = (new HtmlSanitizer())->sanitize('<p><a href="{{ login_url }}">Se connecter</a></p>');
        self::assertStringContainsString(
            '%7B%7B',
            $stored,
            'Precondition: this test is about what the sanitizer does to a placeholder in an href.'
        );

        $this->overrides->save('super_admin_granted', 'Sujet', $stored, null);

        $email = $this->renderer->render('super_admin_granted', [
            'site_name' => 'U',
            'granted_by' => 'akela@example.be',
            'login_url' => 'https://u.example/login',
        ]);

        self::assertStringContainsString('href="https://u.example/login"', $email->bodyHtml);
        self::assertStringNotContainsString('%7B%7B', $email->bodyHtml);
    }

    public function testAnUnknownTemplateIdIsARefusalRatherThanAnEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->renderer->render('nope');
    }

    // ── writing a customisation ───────────────────────────────────────

    public function testTheStoredBodyIsSanitisedBeforeItIsWritten(): void
    {
        $this->customisationService()->customise(
            'super_admin_granted',
            'Sujet',
            '<p>Bonjour</p><script>alert(1)</script>',
            null
        );

        $stored = $this->overrides->find('super_admin_granted');

        self::assertNotNull($stored);
        self::assertStringNotContainsString('<script', $stored['body_html']);
        self::assertStringNotContainsString('alert(1)', $stored['body_html']);
        self::assertStringContainsString('Bonjour', $stored['body_html']);
    }

    public function testANonEditableEmailIsRefusedOnTheServer(): void
    {
        $this->expectException(EmailTemplateException::class);

        $this->customisationService()->customise('magic_link', 'Sujet', '<p>Corps</p>', null);
    }

    public function testResettingRemovesTheRowAndPutsTheEmailBackOnTheShippedTemplate(): void
    {
        $service = $this->customisationService();
        $service->customise('super_admin_granted', 'Autre chose', '<p>Corps</p>', null);

        self::assertTrue($this->renderer->isCustomised('super_admin_granted'));

        self::assertTrue($service->reset('super_admin_granted'));

        self::assertFalse($this->renderer->isCustomised('super_admin_granted'));
        self::assertSame(
            'Vous êtes administrateur du site',
            $this->renderer->render('super_admin_granted', ['site_name' => 'U'])->subject
        );
    }

    public function testAnEmptySubjectIsRefused(): void
    {
        $this->expectException(EmailTemplateException::class);

        $this->customisationService()->customise('super_admin_granted', '   ', '<p>Corps</p>', null);
    }

    private function customisationService(): EmailTemplateCustomisationService
    {
        return new EmailTemplateCustomisationService($this->registry, $this->overrides, new HtmlSanitizer());
    }
}
