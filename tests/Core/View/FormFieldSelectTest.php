<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * The `<select>` of core/View/templates/partials/form_field.html.twig and
 * its `value` parameter (issue #1380).
 *
 * The partial documents `value:` as a general parameter and read it for
 * every control but this one, which only looked at each option's own
 * `selected`. A page that handed `value: x` to a select reopened on the
 * FIRST option without an error, and the next save overwrote the answer
 * saved earlier — which is what the health sheet did until #1346 worked
 * round it.
 *
 * The contract, pinned below:
 *  - `value` given, and some option's value equals it as a string: THAT
 *    option is selected, the first one only, and the options' own
 *    `selected` is ignored — never two selected options;
 *  - `value` absent or null: the options' own `selected`, as ever;
 *  - `value` given but matching no option: the options' own `selected`
 *    (the safe fallback), else nothing marked, which a browser submits as
 *    its first option;
 *  - `''`, `'0'` and `0` are values, not an absence.
 */
final class FormFieldSelectTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private const OPTIONS = [
        ['value' => '', 'label' => 'Non précisé'],
        ['value' => 'yes', 'label' => 'Oui'],
        ['value' => 'no', 'label' => 'Non'],
        ['value' => '0', 'label' => 'Zéro'],
    ];

    /**
     * @param array<string, mixed> $field
     */
    private function render(array $field): string
    {
        return TestTwig::create()->render('partials/form_field.html.twig', $field + [
            'field_id' => 'answer',
            'field_name' => 'answer',
            'label' => 'Réponse',
            'type' => 'select',
            'options' => self::OPTIONS,
        ]);
    }

    /**
     * What a browser would submit for the rendered select: the option marked
     * `selected`, else the first one.
     *
     * @return list<string> the values of the options marked selected
     */
    private function selectedValues(string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $found = [];
        foreach ((new \DOMXPath($dom))->query('//option[@selected]') ?: [] as $option) {
            $found[] = $option instanceof \DOMElement ? $option->getAttribute('value') : '';
        }

        return $found;
    }

    /** @return string what the form posts for this markup */
    private function submitted(string $html): string
    {
        $selected = $this->selectedValues($html);
        if ($selected !== []) {
            return $selected[0];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $first = (new \DOMXPath($dom))->query('//option')?->item(0);

        return $first instanceof \DOMElement ? $first->getAttribute('value') : '';
    }

    public function testTheSavedValueIsTheOptionSelected(): void
    {
        $this->assertSame(['no'], $this->selectedValues($this->render(['value' => 'no'])));
        $this->assertSame(['yes'], $this->selectedValues($this->render(['value' => 'yes'])));
    }

    /** An explicit empty answer is an answer: it selects the empty option, not « nothing ». */
    public function testAnExplicitEmptyStringSelectsTheEmptyOption(): void
    {
        $options = [
            ['value' => 'yes', 'label' => 'Oui'],
            ['value' => '', 'label' => 'Non précisé'],
            ['value' => 'no', 'label' => 'Non'],
        ];

        $this->assertSame(['' ], $this->selectedValues($this->render(['value' => '', 'options' => $options])));
    }

    public function testZeroAsAStringAndAsANumberSelectsTheZeroOptionNotTheEmptyOne(): void
    {
        $this->assertSame(['0'], $this->selectedValues($this->render(['value' => '0'])));
        $this->assertSame(['0'], $this->selectedValues($this->render(['value' => 0])));
    }

    public function testComparisonIsMadeOnStrings(): void
    {
        $options = [['value' => 5, 'label' => 'Cinq'], ['value' => 6, 'label' => 'Six']];

        $this->assertSame(['5'], $this->selectedValues($this->render(['value' => '5', 'options' => $options])));
        $this->assertSame(['6'], $this->selectedValues($this->render(['value' => 6, 'options' => $options])));
    }

    public function testABooleanIsItsStringForm(): void
    {
        $options = [['value' => '', 'label' => 'Non'], ['value' => '1', 'label' => 'Oui']];

        $this->assertSame(['1'], $this->selectedValues($this->render(['value' => true, 'options' => $options])));
        $this->assertSame([''], $this->selectedValues($this->render(['value' => false, 'options' => $options])));
    }

    /** Without `value`, the options' own `selected` still decides: the existing callers. */
    public function testWithoutAValueTheOptionsOwnSelectedDecides(): void
    {
        $options = self::OPTIONS;
        $options[2]['selected'] = true;

        $this->assertSame(['no'], $this->selectedValues($this->render(['options' => $options])));
    }

    public function testWithoutAValueNothingIsMarkedWhenNoOptionIs(): void
    {
        $this->assertSame([], $this->selectedValues($this->render([])));
    }

    /** A null is no answer: the same as no `value` at all, never a match for the empty option. */
    public function testANullValueIsAbsent(): void
    {
        $options = self::OPTIONS;
        $options[1]['selected'] = true;

        $this->assertSame(['yes'], $this->selectedValues($this->render(['value' => null, 'options' => $options])));
        $this->assertSame([], $this->selectedValues($this->render(['value' => null])));
    }

    /** The two mechanisms agreeing — the health sheet — give the same result as either alone. */
    public function testValueAndSelectedAgreeingSelectOneOption(): void
    {
        $options = self::OPTIONS;
        $options[1]['selected'] = true;

        $this->assertSame(['yes'], $this->selectedValues($this->render(['value' => 'yes', 'options' => $options])));
    }

    /** The two disagreeing: `value` wins, and there is never a second selected option. */
    public function testValueWinsOverAnOptionsOwnSelectedAndNeverMakesTwo(): void
    {
        $options = self::OPTIONS;
        $options[1]['selected'] = true;

        $selected = $this->selectedValues($this->render(['value' => 'no', 'options' => $options]));

        $this->assertSame(['no'], $selected);
    }

    public function testASelectedOptionThatValueDoesNotNameIsNotSelectedAtAll(): void
    {
        $options = self::OPTIONS;
        $options[0]['selected'] = true;
        $options[3]['selected'] = true;

        $this->assertSame(['yes'], $this->selectedValues($this->render(['value' => 'yes', 'options' => $options])));
    }

    /**
     * A value no option has — a choice retired since, a hand-edited form —
     * falls back on the options' own `selected` (the contract before this
     * partial read `value`) and marks nothing when there is none: it
     * never invents a match.
     */
    public function testAValueNoOptionHasFallsBackOnTheOptionsOwnSelected(): void
    {
        $options = self::OPTIONS;
        $options[2]['selected'] = true;

        $this->assertSame(['no'], $this->selectedValues($this->render(['value' => 'inconnue', 'options' => $options])));
        $this->assertSame([], $this->selectedValues($this->render(['value' => 'inconnue'])));
    }

    public function testTwoOptionsWithTheSameValueSelectOnlyTheFirst(): void
    {
        $options = [
            ['value' => 'a', 'label' => 'A'],
            ['value' => 'b', 'label' => 'B1'],
            ['value' => 'b', 'label' => 'B2'],
        ];

        $this->assertSame(['b'], $this->selectedValues($this->render(['value' => 'b', 'options' => $options])));
    }

    /** A disabled placeholder named by `value` is still the one shown: that is how it is meant to be used. */
    public function testADisabledOptionNamedByTheValueIsSelectedAndStaysDisabled(): void
    {
        $options = [
            ['value' => '', 'label' => 'Choisir…', 'disabled' => true],
            ['value' => 'a', 'label' => 'A'],
        ];

        $html = $this->render(['value' => '', 'options' => $options]);

        $this->assertSame([''], $this->selectedValues($html));
        $this->assertStringContainsString('disabled', $html);
    }

    /** The other attributes of an option are untouched. */
    public function testOptionDataAttributesAreKept(): void
    {
        $options = [['value' => 'a', 'label' => 'A', 'data' => ['first-import' => '1']]];

        $html = $this->render(['value' => 'a', 'options' => $options]);

        $this->assertStringContainsString('data-first-import="1"', $html);
        $this->assertSame(['a'], $this->selectedValues($html));
    }

    /**
     * Reopen, then save without touching anything: every answer an option
     * can carry comes back identical. This is the loss the issue is about.
     */
    public function testReopeningThenSavingUnchangedKeepsEveryAnswer(): void
    {
        foreach (self::OPTIONS as $option) {
            $saved = (string) $option['value'];

            $this->assertSame(
                $saved,
                $this->submitted($this->render(['value' => $saved])),
                'the answer « ' . $saved . ' » must survive a reopen and a save'
            );
        }
    }

    /** Both ways of marking it reopen on the same answer, so the health sheet keeps working either way. */
    public function testReopeningThenSavingKeepsTheAnswerWhetherItIsMarkedByValueOrByTheOption(): void
    {
        foreach (self::OPTIONS as $option) {
            $saved = (string) $option['value'];
            $marked = array_map(
                static fn(array $o): array => $o + ['selected' => (string) $o['value'] === $saved],
                self::OPTIONS
            );

            $this->assertSame($saved, $this->submitted($this->render(['options' => $marked])));
            $this->assertSame($saved, $this->submitted($this->render(['value' => $saved, 'options' => $marked])));
        }
    }

    /** `value` is read for a select only; a text field is as it was. */
    public function testATextFieldStillTakesItsValue(): void
    {
        $html = TestTwig::create()->render('partials/form_field.html.twig', [
            'field_id' => 'name',
            'label' => 'Nom',
            'value' => 'Baloo',
        ]);

        $this->assertStringContainsString('value="Baloo"', $html);
    }

    public function testThePartialDocumentsHowAValueIsMatched(): void
    {
        $partial = (string) file_get_contents(dirname(__DIR__, 3) . '/core/View/templates/partials/form_field.html.twig');
        $normalised = (string) preg_replace('/\s+/u', ' ', $partial);

        $this->assertStringContainsString('select', $normalised);
        $this->assertStringContainsString('compared as strings', $normalised);
        $this->assertStringContainsString('never two', $normalised);
    }
}
