<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * `@mass_mail/partials/_contact_group_dialog.html.twig`, rendered on its
 * own — the list's page includes it, and the script finds everything it
 * drives by the hooks asserted here, so a renamed hook is a dialog that
 * opens and does nothing.
 */
final class ContactGroupDialogRenderingTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context = ['list_id' => 7]): \DOMXPath
    {
        $html = TestTwig::create(['mass_mail'])->render(
            '@mass_mail/partials/_contact_group_dialog.html.twig',
            $context
        );

        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);

        return new \DOMXPath($document);
    }

    public function testTheDialogCarriesTheListItCreatesGroupsIn(): void
    {
        $xpath = $this->render();

        $dialog = $xpath->query('//*[@id="mm-contact-group-modal"]//*[@data-contact-group-dialog]');
        $this->assertNotFalse($dialog);
        $this->assertSame(1, $dialog->length);
        $element = $dialog->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element);
        $this->assertSame('7', $element->getAttribute('data-list-id'));
    }

    public function testEveryHookTheScriptDrivesIsThere(): void
    {
        $xpath = $this->render();

        foreach ([
            'error', 'name', 'new', 'file-new', 'file-name', 'template', 'existing', 'count', 'search',
            'unsubscribed-only', 'rows', 'empty', 'more', 'export', 'file-existing', 'preview', 'summary',
            'line-errors', 'save',
        ] as $hook) {
            $found = $xpath->query('//*[@data-cg-' . $hook . ']');
            $this->assertNotFalse($found);
            $this->assertSame(1, $found->length, "data-cg-{$hook} must appear exactly once");
        }
    }

    /**
     * Screens 7 and 8 of the mock-up: the labels a chief reads, and the
     * two buttons every dialog of the chantier ends with.
     */
    public function testTheDialogSaysWhatTheMockUpSays(): void
    {
        $html = TestTwig::create(['mass_mail'])->render(
            '@mass_mail/partials/_contact_group_dialog.html.twig',
            ['list_id' => 7]
        );

        foreach ([
            'Groupe de contacts', 'Nom du groupe', 'Fichier Excel', 'Choisir un fichier',
            'Télécharger un modèle vide', 'Rechercher un nom ou une adresse', 'Désinscrites seulement',
            'Afficher plus', 'Exporter en Excel', 'Choisir un fichier corrigé',
            'Le fichier remplacera tous les contacts de ce groupe.', 'Annuler', 'Enregistrer',
        ] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /** Nothing can be saved before the dialog knows what it is saving. */
    public function testEnregistrerStartsDisabledAndOnlyAnalysesAccept(): void
    {
        $xpath = $this->render();

        $save = $xpath->query('//*[@data-cg-save]')?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $save);
        $this->assertTrue($save->hasAttribute('disabled'));

        foreach (['file-new', 'file-existing'] as $hook) {
            $input = $xpath->query('//input[@data-cg-' . $hook . ']')?->item(0);
            $this->assertInstanceOf(\DOMElement::class, $input);
            $this->assertSame('file', $input->getAttribute('type'));
            $this->assertSame('.xlsx', $input->getAttribute('accept'));
            // Not inside a <form>: a file chosen is analysed by the script,
            // never submitted.
            $this->assertSame(0, $xpath->query('ancestor::form', $input)?->length);
        }
    }

    public function testTheTemplateLinkPointsAtTheEmptyTemplateRoute(): void
    {
        $link = $this->render()->query('//a[@data-cg-template]')?->item(0);

        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame('/admin/listes-de-diffusion/contact-groups/template', $link->getAttribute('href'));
    }

    public function testItRendersWithoutAList(): void
    {
        $element = $this->render([])->query('//*[@data-contact-group-dialog]')?->item(0);

        $this->assertInstanceOf(\DOMElement::class, $element);
        $this->assertSame('', $element->getAttribute('data-list-id'));
    }
}
