<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Mail\Template\EmailTemplate;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Module\ModuleException;
use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The inventory of every automatic e-mail the site can send.
 *
 * What this pins is the inventory being COMPLETE and CONSISTENT, not the
 * wording of any one declaration: a template pointing at a Twig file that
 * does not exist, two modules claiming the same id, or an authentication
 * e-mail quietly declared editable are each invisible until somebody
 * opens the page and finds it broken.
 */
class EmailTemplateRegistryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    public function testEveryDeclaredTemplatePointsAtATwigFileThatExists(): void
    {
        foreach ($this->allDeclared() as $template) {
            self::assertFileExists(
                $this->resolveTemplatePath($template),
                "Le gabarit « {$template->id} » désigne un fichier Twig absent : {$template->template}"
            );
        }
    }

    /**
     * Every text an administrator reads on the page is required, because a
     * blank description is a row nobody can act on.
     */
    public function testEveryDeclaredTemplateCarriesItsFrenchWording(): void
    {
        foreach ($this->allDeclared() as $template) {
            self::assertNotSame('', trim($template->label), "Le gabarit « {$template->id} » n'a pas de libellé.");
            self::assertNotSame('', trim($template->description), "Le gabarit « {$template->id} » n'a pas de description.");
            self::assertNotSame('', trim($template->defaultSubject), "Le gabarit « {$template->id} » n'a pas de sujet.");
        }
    }

    public function testNoTemplateIdIsDeclaredTwiceAcrossCoreAndModules(): void
    {
        $ids = array_map(static fn (EmailTemplate $t): string => $t->id, $this->allDeclared());

        self::assertSame(
            array_values(array_unique($ids)),
            $ids,
            'Deux gabarits partagent le même identifiant.'
        );
    }

    /**
     * The four authentication e-mails are declared — they belong in the
     * inventory — and refused an editor. An administrator who broke the
     * magic link or a password reset would lock themselves out with no way
     * back in.
     */
    public function testTheAuthenticationEmailsAreDeclaredAndNotEditable(): void
    {
        $registry = $this->registryWithEveryModule();

        foreach ([
            'magic_link',
            'password_reset',
            'member_email_confirmation',
            'registration.secondary_email_confirmation',
        ] as $id) {
            $template = $registry->find($id);

            self::assertNotNull($template, "Le gabarit d'authentification « {$id} » n'est pas déclaré.");
            self::assertFalse($template->editable, "Le gabarit d'authentification « {$id} » est modifiable.");
        }
    }

    /**
     * `site_name` belongs to email/base.html.twig, which stays code: the
     * header, the footer and the unit's name are never customisable, so
     * offering an insertion button for them would be a promise the
     * renderer does not keep.
     */
    public function testNoTemplateOffersSiteNameAsAVariable(): void
    {
        foreach ($this->allDeclared() as $template) {
            self::assertFalse(
                $template->hasVariable('site_name'),
                "Le gabarit « {$template->id} » propose site_name, qui appartient au gabarit de base."
            );
        }
    }

    public function testACoreTemplateHasNoModulePrefixAndAModuleOneDoes(): void
    {
        $registry = $this->registryWithEveryModule();

        self::assertNull($registry->find('magic_link')?->moduleId());
        self::assertSame('rental', $registry->find('rental.acknowledgement')?->moduleId());
    }

    public function testTheGroupingSeparatesCoreFromEachModule(): void
    {
        $grouped = $this->registryWithEveryModule()->groupedByModule();

        self::assertArrayHasKey('', $grouped, 'Les gabarits core doivent former un groupe.');
        self::assertArrayHasKey('rental', $grouped);
        self::assertCount(6, $grouped['rental']);
    }

    // ── manifest validation ───────────────────────────────────────────

    public function testAModuleEmailIdMustCarryItsModulePrefix(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("must be prefixed 'demo.'");

        ModuleManifest::fromArray($this->manifestWith([[
            'id' => 'acknowledgement',
            'label' => 'Accusé',
            'description' => 'Envoyé au demandeur.',
            'default_subject' => 'Votre demande',
            'template' => '@demo/email/ack.html.twig',
        ]]));
    }

    public function testAMissingDescriptionIsRefusedAtLoadTime(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'description'");

        ModuleManifest::fromArray($this->manifestWith([[
            'id' => 'demo.ack',
            'label' => 'Accusé',
            'default_subject' => 'Votre demande',
            'template' => '@demo/email/ack.html.twig',
        ]]));
    }

    public function testANonListEmailsSectionIsRefused(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('emails must be an array');

        ModuleManifest::fromArray($this->manifestWith('nope'));
    }

    public function testAVariableNameMustBeAnIdentifier(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('must be a lowercase identifier');

        ModuleManifest::fromArray($this->manifestWith([[
            'id' => 'demo.ack',
            'label' => 'Accusé',
            'description' => 'Envoyé au demandeur.',
            'default_subject' => 'Votre demande',
            'template' => '@demo/email/ack.html.twig',
            'variables' => [['name' => 'Asset Name', 'label' => 'Bien', 'example' => 'Ferme']],
        ]]));
    }

    public function testAVariableDeclaredTwiceIsRefused(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("declares variable 'reference' twice");

        ModuleManifest::fromArray($this->manifestWith([[
            'id' => 'demo.ack',
            'label' => 'Accusé',
            'description' => 'Envoyé au demandeur.',
            'default_subject' => 'Votre demande',
            'template' => '@demo/email/ack.html.twig',
            'variables' => [
                ['name' => 'reference', 'label' => 'Référence', 'example' => 'LOC-1'],
                ['name' => 'reference', 'label' => 'Référence', 'example' => 'LOC-2'],
            ],
        ]]));
    }

    public function testEditableDefaultsToTrueAndIsOptional(): void
    {
        $manifest = ModuleManifest::fromArray($this->manifestWith([[
            'id' => 'demo.ack',
            'label' => 'Accusé',
            'description' => 'Envoyé au demandeur.',
            'default_subject' => 'Votre demande',
            'template' => '@demo/email/ack.html.twig',
        ]]));

        self::assertTrue($manifest->emails[0]['editable']);
    }

    public function testAManifestWithNoEmailsSectionDeclaresNone(): void
    {
        $manifest = ModuleManifest::fromArray(['id' => 'demo', 'name' => 'Demo', 'version' => '1.0.0']);

        self::assertSame([], $manifest->emails);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function manifestWith(mixed $emails): array
    {
        return ['id' => 'demo', 'name' => 'Demo', 'version' => '1.0.0', 'emails' => $emails];
    }

    /**
     * The registry as a running site holds it: core, plus every module on
     * disk. Reading the real manifests rather than a fixture is what makes
     * a module declaring a broken template fail here.
     */
    private function registryWithEveryModule(): EmailTemplateRegistry
    {
        $registry = new EmailTemplateRegistry();

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $path) {
            $manifest = ModuleManifest::fromFile($path);
            if ($manifest->emails !== []) {
                $registry->registerModuleTemplates($manifest->id, $manifest->name, $manifest->emails);
            }
        }

        return $registry;
    }

    /**
     * @return list<EmailTemplate>
     */
    private function allDeclared(): array
    {
        return $this->registryWithEveryModule()->getAll();
    }

    /**
     * `@module/…` is the Twig namespace ModuleManager registers for a
     * module's views/ directory; core templates are plain paths under
     * core/View/templates/.
     */
    private function resolveTemplatePath(EmailTemplate $template): string
    {
        if (str_starts_with($template->template, '@')) {
            [$namespace, $relative] = explode('/', substr($template->template, 1), 2);

            return self::root() . '/modules/' . $namespace . '/views/' . $relative;
        }

        return self::root() . '/core/View/templates/' . $template->template;
    }
}
