<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * partials/search_picker.html.twig — the half of the generic search picker
 * that has to work with no JavaScript at all.
 *
 * The script's behaviour is tests/js/search-picker.test.js. What only the
 * markup can promise is pinned here: a form rendered from this partial and
 * submitted by a browser whose script never ran posts the right field, with
 * the retained values, from one control and one only.
 */
final class SearchPickerRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function render(array $overrides = []): \DOMXPath
    {
        $html = $this->twig->render('partials/search_picker.html.twig', array_merge([
            'picker_id' => 'events',
            'search_url' => '/recherche',
            'field_name' => 'event_ids',
            'label' => 'Évènements concernés',
            'options' => [
                ['value' => 1, 'label' => 'Fête d\'unité — Baladins'],
                ['value' => 2, 'label' => 'Fête d\'unité — Louveteaux'],
            ],
        ], $overrides));

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8"><form>' . $html . '</form>');

        return new \DOMXPath($dom);
    }

    /**
     * What a browser would submit without JavaScript: every named, enabled
     * control, and for a select only its selected options.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function submittedWithoutScript(\DOMXPath $xpath): array
    {
        $pairs = [];
        foreach ($xpath->query('//*[@name]') ?: [] as $node) {
            \assert($node instanceof \DOMElement);
            if ($node->hasAttribute('disabled')) {
                continue;
            }
            if ($node->tagName === 'select') {
                foreach ($xpath->query('.//option[@selected]', $node) ?: [] as $option) {
                    \assert($option instanceof \DOMElement);
                    $pairs[] = [$node->getAttribute('name'), $option->getAttribute('value')];
                }
                continue;
            }
            $pairs[] = [$node->getAttribute('name'), $node->getAttribute('value')];
        }

        return $pairs;
    }

    public function testSingleModeSubmitsTheChosenValueWithoutJavaScript(): void
    {
        $xpath = $this->render([
            'field_name' => 'event_id',
            'selected' => [['id' => 2, 'label' => 'Fête d\'unité — Louveteaux']],
        ]);

        $this->assertSame([['event_id', '2']], $this->submittedWithoutScript($xpath));
        $select = $xpath->query('//select')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $select);
        $this->assertFalse($select->hasAttribute('multiple'));
        // The empty choice exists, so a single picker can be left blank.
        $this->assertSame('', $xpath->query('//select/option')->item(0)?->getAttribute('value'));
    }

    public function testMultipleModeSubmitsEveryRetainedValueUnderAnArrayName(): void
    {
        $xpath = $this->render([
            'mode' => 'multiple',
            'selected' => [
                ['id' => 1, 'label' => 'Fête d\'unité — Baladins'],
                ['id' => 2, 'label' => 'Fête d\'unité — Louveteaux'],
            ],
        ]);

        $this->assertSame(
            [['event_ids[]', '1'], ['event_ids[]', '2']],
            $this->submittedWithoutScript($xpath)
        );
        $select = $xpath->query('//select')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $select);
        $this->assertTrue($select->hasAttribute('multiple'));
    }

    public function testARetainedRowMissingFromTheOptionsIsStillInTheFallbackList(): void
    {
        // Otherwise saving the form once without JavaScript would silently
        // drop a value retained earlier.
        $xpath = $this->render([
            'mode' => 'multiple',
            'selected' => [['id' => 9, 'label' => 'Camp de sélection']],
        ]);

        $this->assertSame([['event_ids[]', '9']], $this->submittedWithoutScript($xpath));
        $this->assertSame(3, $xpath->query('//select/option')->length);
    }

    public function testNothingButTheFallbackListIsSubmittedBeforeTheScriptRuns(): void
    {
        $xpath = $this->render(['mode' => 'multiple']);

        // The search box has no name, and the values container starts
        // empty: the script is what fills it, after removing the list.
        $this->assertSame([], $this->submittedWithoutScript($xpath));
        $this->assertSame(0, $xpath->query('//*[@data-search-picker-values]/*')->length);
        $search = $xpath->query('//*[@data-search-picker-search]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $search);
        $this->assertStringContainsString('d-none', $search->getAttribute('class'));
    }

    public function testTheScriptIsHandedEverythingItNeeds(): void
    {
        $xpath = $this->render([
            'mode' => 'multiple',
            'search_url' => '/covoiturage/evenements?x=1',
            'empty_label' => 'Aucun évènement ne correspond.',
            'selected' => [['id' => 1, 'label' => 'Fête d\'unité — Baladins', 'badge' => 'Baladins']],
        ]);

        $picker = $xpath->query('//*[@data-search-picker]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $picker);
        $this->assertSame('multiple', $picker->getAttribute('data-mode'));
        $this->assertSame('/covoiturage/evenements?x=1', $picker->getAttribute('data-search-url'));
        $this->assertSame('event_ids[]', $picker->getAttribute('data-field-name'));
        $this->assertSame('Aucun évènement ne correspond.', $picker->getAttribute('data-empty-label'));
        $this->assertSame(
            [['id' => 1, 'label' => 'Fête d\'unité — Baladins', 'badge' => 'Baladins']],
            json_decode($picker->getAttribute('data-selected'), true)
        );
    }

    public function testRetainedRowsReachTheScriptAsAListWhateverTheirKeys(): void
    {
        // A caller's array_filter() or id-keyed array would otherwise encode
        // as a JSON object, which the script discards: the upgraded picker
        // would then post nothing for rows the fallback list still holds.
        $picker = $this->render([
            'mode' => 'multiple',
            'selected' => [7 => ['id' => 7, 'label' => 'Camp'], 9 => ['id' => 9, 'label' => 'Hike']],
        ])->query('//*[@data-search-picker]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $picker);
        $this->assertSame(
            '[{"id":7,"label":"Camp"},{"id":9,"label":"Hike"}]',
            $picker->getAttribute('data-selected')
        );
    }

    public function testARequiredSinglePickerHandsTheRequirementToTheScript(): void
    {
        // The select that carries `required` is removed on upgrade; the
        // script reads this to keep the rule on the search box.
        $picker = $this->render(['field_name' => 'event_id', 'required' => true])
            ->query('//*[@data-search-picker]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $picker);
        $this->assertSame('1', $picker->getAttribute('data-required'));

        $multiple = $this->render(['mode' => 'multiple', 'required' => true])
            ->query('//*[@data-search-picker]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $multiple);
        $this->assertSame('0', $multiple->getAttribute('data-required'));
    }

    public function testEveryControlIsLabelled(): void
    {
        $xpath = $this->render(['help' => 'Cherchez par titre.']);

        foreach (['events-select', 'events-search'] as $id) {
            $this->assertSame(1, $xpath->query('//label[@for="' . $id . '"]')->length, $id);
        }
        $search = $xpath->query('//*[@id="events-search"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $search);
        $this->assertSame('events-help', $search->getAttribute('aria-describedby'));
    }
}
