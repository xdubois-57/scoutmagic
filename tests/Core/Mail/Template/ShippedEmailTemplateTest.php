<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Mail\Template\EmailTemplate;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Module\ModuleManifest;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The templates the application ships, held to what Configuration >
 * E-mails promises about them.
 *
 * That page seeds its editor with the shipped `content` block rendered
 * with **each declared variable standing for itself** — `{{ reference }}`,
 * not « LOC-2027-0042 ». The shipped template is therefore not merely what
 * gets sent while nobody has customised the e-mail: it IS the default
 * wording an administrator is handed, and every property below is about
 * that text still working after they save it.
 *
 * What went wrong, and is pinned here so it cannot come back:
 *
 * - **A template rendering something nobody declared.** The rental
 *   e-mails walked `booking.renterName` and `asset.name` as objects while
 *   the manifest declared a handful of flat strings. Neither reaches the
 *   editor, so the default wording offered for six e-mails read « Bonjour
 *   , du  au  » — and a unit that saved it sent that to its renters.
 * - **A declared variable no template mentions.** An insertion button for
 *   a value the message never says.
 * - **A message that arrives unsigned.** The closing formula lives in
 *   `email/base.html.twig` so that a reworded e-mail keeps it; a template
 *   that signed off itself would say it twice.
 */
final class ShippedEmailTemplateTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Every declared e-mail, core's and every module's, exactly as a
     * running site holds them.
     *
     * @return array<string, array{EmailTemplate}>
     */
    public static function everyDeclaredTemplate(): array
    {
        $registry = new EmailTemplateRegistry();
        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $path) {
            $manifest = ModuleManifest::fromFile($path);
            if ($manifest->emails !== []) {
                $registry->registerModuleTemplates($manifest->id, $manifest->name, $manifest->emails);
            }
        }

        $cases = [];
        foreach ($registry->getAll() as $template) {
            $cases[$template->id] = [$template];
        }

        return $cases;
    }

    /**
     * **Strict variables**, which is the whole point of this test: an
     * undefined name is an exception rather than a silent blank, so a
     * template reaching for a `booking` object nobody declared fails here
     * instead of in a family's inbox.
     */
    private function twig(): Environment
    {
        $namespaces = [];
        foreach (glob(self::root() . '/modules/*/views') ?: [] as $views) {
            $namespaces[basename(dirname($views))] = $views;
        }

        $twig = TwigFactory::create(self::root() . '/core/View/templates', false, $namespaces);
        $twig->addGlobal('site_name', self::SITE_NAME);
        $twig->enableStrictVariables();

        return $twig;
    }

    private const SITE_NAME = 'Unité Test';

    /**
     * @return array<string, string> declared name => its own placeholder
     */
    private static function placeholders(EmailTemplate $template): array
    {
        $context = ['site_name' => self::SITE_NAME];
        foreach ($template->variables as $variable) {
            $context[$variable->name] = '{{ ' . $variable->name . ' }}';
        }

        return $context;
    }

    private static function textTemplateOf(EmailTemplate $template): string
    {
        return preg_replace('/\.html\.twig$/', '.text.twig', $template->template) ?? $template->template;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyDeclaredTemplate')]
    public function testBothHalvesRenderFromTheDeclaredVariablesAlone(EmailTemplate $template): void
    {
        $twig = $this->twig();
        $context = self::placeholders($template);

        foreach ([$template->template, self::textTemplateOf($template)] as $file) {
            $twig->render($file, $context);
        }

        // Reaching this line IS the assertion: strict_variables turns
        // anything undeclared into a RuntimeError above.
        $this->addToAssertionCount(1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyDeclaredTemplate')]
    public function testEveryDeclaredVariableAppearsInTheDefaultWording(EmailTemplate $template): void
    {
        if ($template->variables === []) {
            // An e-mail with nothing to substitute — the two super-admin
            // notices — has nothing to prove here.
            $this->addToAssertionCount(1);

            return;
        }

        $body = $this->twig()->load($template->template)->renderBlock('content', self::placeholders($template));

        foreach ($template->variables as $variable) {
            $this->assertStringContainsString(
                '{{ ' . $variable->name . ' }}',
                $body,
                "Le gabarit « {$template->id} » n'utilise pas la variable {$variable->name} : "
                    . "un bouton d'insertion pour une valeur que l'email ne dit jamais."
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyDeclaredTemplate')]
    public function testEveryEmailIsSignedOnceByTheFrame(EmailTemplate $template): void
    {
        $twig = $this->twig();
        $context = self::placeholders($template);

        $html = $twig->render($template->template, $context);
        $text = $twig->render(self::textTemplateOf($template), $context);

        foreach (['html' => $html, 'texte' => $text] as $half => $rendered) {
            $this->assertSame(
                1,
                substr_count($rendered, 'Bien à vous'),
                "La moitié {$half} de « {$template->id} » doit porter la formule finale une "
                    . 'seule fois : elle vient du gabarit de base, un template qui signe '
                    . 'lui-même la répète.'
            );
            $this->assertStringContainsString(
                self::SITE_NAME,
                $rendered,
                "La moitié {$half} de « {$template->id} » doit être signée du nom de l'unité."
            );
        }
    }
}
