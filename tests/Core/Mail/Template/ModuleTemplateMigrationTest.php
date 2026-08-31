<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Module\ModuleManifest;
use Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * Every module e-mail, rendered through the register, is the message the
 * module used to render itself.
 *
 * This is the non-regression the migration needs and the only one worth
 * having. Each of these senders used to call `$twig->render()` twice and
 * pass a literal subject; they now call the renderer. What must not have
 * changed is what leaves the building: same subject, same HTML, same
 * plain text, byte for byte, as long as nobody has customised anything.
 * A test that merely asserted "an e-mail comes out" would pass while a
 * unit's messages quietly turned into something else.
 *
 * The comparison is against Twig itself, rendered here from the same
 * template path the manifest declares — not against a stored copy of the
 * expected output, which would need updating every time somebody improved
 * a wording and would then be asserting its own staleness.
 *
 * @group database
 */
class ModuleTemplateMigrationTest extends TestCase
{
    /**
     * One case per declared module e-mail, with a context rich enough for
     * the shipped template to render: the objects the template walks are
     * NOT here, because a template that needs one is tested by its own
     * module's suite. What is here is every module e-mail whose template
     * renders from scalars alone, plus the subject of all of them.
     *
     * @return array<string, array{0: string}>
     */
    public static function modules(): array
    {
        return [
            'calendar' => ['calendar'],
            'news' => ['news'],
            'registration' => ['registration'],
            'rental' => ['rental'],
            'retro' => ['retro'],
            'sos_staff' => ['sos_staff'],
        ];
    }

    /**
     * With nothing customised, the renderer must produce exactly what
     * `$twig->render($declaredTemplate, $context)` produces — which is
     * what every one of these senders did before.
     */
    #[DataProvider('modules')]
    public function testEveryDeclaredEmailRendersAsTheShippedTemplateDoes(string $moduleId): void
    {
        $twig = self::twigFor($moduleId);
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleManifest(
            ModuleManifest::fromFile(dirname(__DIR__, 4) . "/modules/{$moduleId}/module.json")
        );

        $pdo = DatabaseTestHelper::createTestDatabase();
        $renderer = new EmailTemplateRenderer(
            $twig,
            $registry,
            new EmailTemplateOverrideRepository($pdo),
            new JournalService(new JournalRepository($pdo))
        );

        $templates = $registry->groupedByModule()[$moduleId] ?? [];
        $this->assertNotEmpty($templates, "Module {$moduleId} declares no e-mail — this test would prove nothing.");

        foreach ($templates as $template) {
            $context = $template->exampleValues() + ['site_name' => 'Unité Test'];

            $email = $renderer->render($template->id, $context);

            $this->assertSame(
                $twig->render($template->template, $context),
                $email->bodyHtml,
                "{$template->id}: the HTML body must be the shipped template's own output."
            );
            $this->assertSame(
                $twig->render(str_replace('.html.twig', '.text.twig', $template->template), $context),
                $email->bodyText,
                "{$template->id}: the plain-text body must be the shipped template's own output."
            );
        }
    }

    /**
     * The two rental e-mails whose subject the sender computes — seven
     * decisions, and a document label that says whether it is a resend —
     * declare that subject as a variable rather than freezing one of its
     * values as the default. This checks the declaration says so, because
     * a manifest that lost the variable would silently start sending
     * « Votre document » for every attachment.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function computedSubjects(): array
    {
        return [
            'a decision has seven possible subjects' => ['rental.decision', 'decision_subject'],
            'a document names itself and says if it is a resend' => ['rental.document', 'document_subject'],
        ];
    }

    #[DataProvider('computedSubjects')]
    public function testAComputedSubjectIsDeclaredAsAVariable(string $templateId, string $variable): void
    {
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleManifest(
            ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/rental/module.json')
        );

        $template = $registry->find($templateId);
        $this->assertNotNull($template);
        $this->assertSame('{{ ' . $variable . ' }}', $template->defaultSubject);
        $this->assertTrue(
            $template->hasVariable($variable),
            "{$templateId} builds its subject from {{ {$variable} }}, so it must declare it — "
                . 'otherwise the renderer leaves the placeholder in the subject line.'
        );
    }

    /**
     * A module's declared template path must exist, and so must its
     * plain-text twin. The renderer derives the text template by name
     * (`.html.twig` → `.text.twig`), so a module that shipped only the
     * HTML half would fail at send time — on the one e-mail nobody sends
     * in development.
     */
    #[DataProvider('modules')]
    public function testBothHalvesOfEveryDeclaredTemplateExist(string $moduleId): void
    {
        $twig = self::twigFor($moduleId);
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleManifest(
            ModuleManifest::fromFile(dirname(__DIR__, 4) . "/modules/{$moduleId}/module.json")
        );

        foreach ($registry->groupedByModule()[$moduleId] ?? [] as $template) {
            $this->assertTrue(
                $twig->getLoader()->exists($template->template),
                "{$template->id}: {$template->template} does not exist."
            );
            $this->assertTrue(
                $twig->getLoader()->exists(str_replace('.html.twig', '.text.twig', $template->template)),
                "{$template->id}: the plain-text half of {$template->template} does not exist."
            );
        }
    }

    private static function twigFor(string $moduleId): Environment
    {
        $root = dirname(__DIR__, 4);

        return TwigFactory::create(
            $root . '/core/View/templates',
            false,
            [$moduleId => $root . '/modules/' . $moduleId . '/views']
        );
    }
}
