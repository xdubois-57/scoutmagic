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
use Core\Mail\Template\MissingRequiredVariableException;
use Core\Module\ModuleException;
use Core\Module\ModuleManifest;
use Core\Security\HtmlSanitizer;
use Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A variable the e-mail cannot do without (issue #905) — the family's
 * tracking link is the case that motivated it: a customised receipt that
 * lost its link is a message telling a family to wait for news they have
 * no way to follow.
 *
 * Three effects, each proved here: the manifest declares it, the editor's
 * save refuses a body without it, and the renderer refuses to SEND a
 * stored body without it — whatever put that body in the table.
 *
 * And the substitution side of the same ticket: one engine, so a
 * placeholder written `{{x}}`, percent-encoded by the editor inside a link,
 * or cut in two by a bold word is still the placeholder.
 */
#[Group('database')]
class RequiredEmailVariableTest extends TestCase
{
    private const TEMPLATE = 'demo.receipt';

    private \PDO $pdo;
    private EmailTemplateRegistry $registry;
    private EmailTemplateOverrideRepository $overrides;
    private EmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->registry = new EmailTemplateRegistry();
        $this->registry->registerModuleManifest(ModuleManifest::fromArray([
            'id' => 'demo',
            'name' => 'Démo',
            'version' => '1.0.0',
            'emails' => [[
                'id' => self::TEMPLATE,
                'label' => 'Accusé de réception',
                'description' => 'Envoyé à la famille.',
                'default_subject' => 'Demande reçue',
                'template' => 'email/super_admin_revoked.html.twig',
                'variables' => [
                    ['name' => 'prenom', 'label' => 'Prénom', 'example' => 'Léa'],
                    ['name' => 'lien', 'label' => 'Lien de suivi', 'example' => 'https://u.be/s/1', 'required' => true],
                ],
            ]],
        ]));
        $this->overrides = new EmailTemplateOverrideRepository($this->pdo);
        $this->renderer = new EmailTemplateRenderer(
            TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates'),
            $this->registry,
            $this->overrides,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    // ── the declaration ───────────────────────────────────────────────

    public function testTheManifestCarriesRequiredIntoTheRegistryAndDefaultsToOptional(): void
    {
        $template = $this->registry->find(self::TEMPLATE);
        self::assertNotNull($template);

        self::assertSame(['prenom' => false, 'lien' => true], array_combine(
            array_map(static fn($v) => $v->name, $template->variables),
            array_map(static fn($v) => $v->required, $template->variables)
        ));
    }

    public function testANonBooleanRequiredIsRefused(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("'required' must be a boolean");

        ModuleManifest::fromArray(['id' => 'demo', 'name' => 'Démo', 'version' => '1.0.0', 'emails' => [[
            'id' => 'demo.x',
            'label' => 'X',
            'description' => 'X.',
            'default_subject' => 'X',
            'template' => '@demo/email/x.html.twig',
            'variables' => [['name' => 'lien', 'label' => 'Lien', 'example' => 'u', 'required' => 'yes']],
        ]]]);
    }

    // ── what counts as « contains » ───────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatCarryTheLink(): array
    {
        return [
            'spaced' => ['<p>Suivez {{ lien }}.</p>'],
            'unspaced' => ['<p>Suivez {{lien}}.</p>'],
            'percent-encoded in a link' => ['<p><a href="%7B%7B lien %7D%7D">Suivre</a></p>'],
            'cut in two by a bold word' => ['<p>Suivez {{ li<strong>e</strong>n }}.</p>'],
        ];
    }

    #[DataProvider('bodiesThatCarryTheLink')]
    public function testEveryFormThatWouldBeSubstitutedCountsAsPresent(string $body): void
    {
        $template = $this->registry->find(self::TEMPLATE);
        self::assertNotNull($template);

        self::assertSame([], $template->missingRequiredVariables($body));
    }

    public function testTheWordAloneOrAnOptionalVariableDoesNotCount(): void
    {
        $template = $this->registry->find(self::TEMPLATE);
        self::assertNotNull($template);

        self::assertSame(
            ['lien'],
            array_map(
                static fn($v) => $v->name,
                $template->missingRequiredVariables('<p>Bonjour {{ prenom }}, le lien suit.</p>')
            )
        );
    }

    // ── on save ───────────────────────────────────────────────────────

    public function testSavingABodyWithoutTheRequiredVariableIsRefusedByName(): void
    {
        try {
            $this->customisation()->customise(self::TEMPLATE, 'Sujet', '<p>Bonjour {{ prenom }}.</p>', null);
            self::fail('A body without its required link must not be stored.');
        } catch (EmailTemplateException $e) {
            self::assertStringContainsString('« Lien de suivi »', $e->getMessage());
            self::assertStringContainsString('{{ lien }}', $e->getMessage());
        }

        self::assertNull($this->overrides->find(self::TEMPLATE));
    }

    public function testALinkTheSanitizerStripsIsALinkMissing(): void
    {
        $this->expectException(EmailTemplateException::class);

        // `javascript:` is pruned by the sanitizer; what is left has no
        // placeholder, and the check reads what would be STORED.
        $this->customisation()->customise(
            self::TEMPLATE,
            'Sujet',
            '<p><script>"{{ lien }}"</script>Bonjour.</p>',
            null
        );
    }

    public function testSavingABodyThatCarriesItIsAccepted(): void
    {
        $this->customisation()->customise(self::TEMPLATE, 'Sujet', '<p>Suivez {{lien}}.</p>', null);

        self::assertNotNull($this->overrides->find(self::TEMPLATE));
    }

    // ── on send ───────────────────────────────────────────────────────

    public function testAStoredBodyWithoutTheRequiredVariableIsNotSentAndIsJournaled(): void
    {
        // Straight into the table, as an older version or a restored
        // backup would have put it: the editor's refusal never ran.
        $this->overrides->save(self::TEMPLATE, 'Sujet', '<p>Bonjour {{ prenom }}.</p>', null);

        try {
            $this->renderer->render(self::TEMPLATE, ['site_name' => 'U', 'prenom' => 'Léa', 'lien' => 'https://x']);
            self::fail('A stored body without its required link must not be rendered for sending.');
        } catch (MissingRequiredVariableException $e) {
            self::assertStringContainsString('« Lien de suivi »', $e->getMessage());
        }

        $statement = $this->pdo->prepare('SELECT level, context FROM event_log WHERE event_type = ?');
        $statement->execute(['email_template_required_variable_missing']);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('error', $rows[0]['level']);
        self::assertSame(
            ['template_id' => self::TEMPLATE, 'variables' => ['lien']],
            json_decode((string) $rows[0]['context'], true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testTheShippedTemplateIsNotSubjectToTheCheck(): void
    {
        // No customisation: the Twig file shipped with the application is
        // what goes out, and its wording is code reviewed like code.
        $email = $this->renderer->render(self::TEMPLATE, ['site_name' => 'U']);

        self::assertNotSame('', $email->bodyHtml);
    }

    // ── one engine ────────────────────────────────────────────────────

    #[DataProvider('bodiesThatCarryTheLink')]
    public function testEveryFormIsSubstitutedIntoTheSentBody(string $body): void
    {
        $this->overrides->save(self::TEMPLATE, 'Sujet', $body, null);

        $email = $this->renderer->render(self::TEMPLATE, ['site_name' => 'U', 'lien' => 'https://u.be/s/42']);

        self::assertStringContainsString('https://u.be/s/42', $email->bodyHtml);
        self::assertStringNotContainsString('lien', strtolower(strip_tags($email->bodyHtml)));
    }

    public function testTheSubjectAcceptsAnySpacingToo(): void
    {
        $this->overrides->save(self::TEMPLATE, 'Inscription de {{prenom}}', '<p>{{ lien }}</p>', null);

        $email = $this->renderer->render(self::TEMPLATE, ['site_name' => 'U', 'prenom' => 'Léa', 'lien' => 'x']);

        self::assertSame('Inscription de Léa', $email->subject);
    }

    private function customisation(): EmailTemplateCustomisationService
    {
        return new EmailTemplateCustomisationService($this->registry, $this->overrides, new HtmlSanitizer());
    }
}
