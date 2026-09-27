<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use PHPUnit\Framework\TestCase;

/**
 * **« Section concernée » is gone from the organiser form, and the section
 * is said instead of asked (issue #650).**
 *
 * The field was the defect: it stayed editable while being ignored as soon
 * as an event was picked, and became required with none — a rule nobody
 * could guess before meeting the error message. It is replaced by one
 * read-only sentence, because the section still decides who sees the
 * passengers and a reader who cannot see it cannot check it.
 *
 * Asserted against the template source, the way
 * {@see OrganizerMapStylesheetTest} does: the question is what the markup
 * contains, and rendering the page would need a session, a calendar and a
 * database to answer something a file already answers.
 */
final class OrganizerSectionFieldIsGoneTest extends TestCase
{
    private const FORM = '/modules/covoiturage/views/organize/form.html.twig';
    private const SHOW = '/modules/covoiturage/views/show.html.twig';

    private function template(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . $path);
    }

    /**
     * The template WITHOUT its Twig comments. The questions below are about
     * what the page carries, and a `{# … #}` explaining why the field was
     * removed necessarily names it — so asserting on the raw source would
     * make the comment fail the test it documents.
     */
    private function markupOf(string $path): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $this->template($path));
    }

    /**
     * No input of any kind named `section_id`. A `select` left behind would
     * keep posting a section that CarpoolService now ignores — the worst of
     * both worlds, a control that looks live and does nothing, which is
     * precisely what this issue was reported about.
     */
    public function testTheFormPostsNoSectionAtAll(): void
    {
        $form = $this->markupOf(self::FORM);

        $this->assertStringNotContainsString(
            'section_id',
            $form,
            'the organiser form still carries a section_id field, which the service no longer reads'
        );
        $this->assertStringNotContainsString(
            'Section concernée',
            $form,
            'the label of the removed field is still on the page'
        );
    }

    /**
     * And it says which section will manage the carpool — both branches,
     * because « no section at all » is a real outcome (a creator linked to
     * no member) and the page must explain it rather than stay blank.
     */
    public function testTheFormNamesTheManagingSectionReadOnly(): void
    {
        $form = $this->markupOf(self::FORM);

        $this->assertStringContainsString('managing_section', $form);
        $this->assertStringContainsString('Géré aussi par', $form);
        $this->assertMatchesRegularExpression(
            '/\{%\s*if\s+managing_section\s*%\}.*\{%\s*else\s*%\}/s',
            $form,
            'the form has no branch for a creator with no section: it would show nothing at all'
        );
    }

    /**
     * **Three branches, not two** (raised in review of #650). « No section »
     * has two causes that must not share a sentence: creating with an
     * account linked to no member in a section, and EDITING a carpool that
     * simply has none — which every carpool created before #650 with events
     * does. Explaining the editor's own account on an edit states something
     * false about a reader who may well have a section.
     */
    public function testTheAbsenceOfASectionIsExplainedDifferentlyWhenEditing(): void
    {
        $form = $this->markupOf(self::FORM);

        $this->assertMatchesRegularExpression(
            '/\{%\s*elseif\s+carpool\s+is\s+null\s*%\}/',
            $form,
            'creating and editing share the « no section » wording, so one of the two is wrong'
        );

        $branches = preg_split('/\{%\s*(?:elseif[^%]*|else)\s*%\}/', $form);
        self::assertIsArray($branches);
        $this->assertStringNotContainsString(
            'votre compte',
            (string) end($branches),
            'the edit branch explains the editor\'s own account, which has nothing to do with '
            . "this carpool's stored section"
        );
    }

    /** The carpool's own page says it too — same reason, other reader. */
    public function testTheCarpoolPageNamesTheManagingSection(): void
    {
        $show = $this->markupOf(self::SHOW);

        $this->assertStringContainsString('carpool.creator_section', $show);
        $this->assertStringContainsString('Géré aussi par', $show);
    }
}
