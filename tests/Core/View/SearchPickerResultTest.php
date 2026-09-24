<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\SearchPickerResult;
use PHPUnit\Framework\TestCase;

/**
 * The server half of the search picker's contract: the one JSON shape
 * public/assets/js/search-picker.js reads.
 */
final class SearchPickerResultTest extends TestCase
{
    public function testARowCarriesOnlyTheFieldsItHas(): void
    {
        $this->assertSame(
            ['id' => 3, 'label' => 'Fête d\'unité'],
            (new SearchPickerResult(3, 'Fête d\'unité'))->toArray()
        );
        $this->assertSame(
            ['id' => 'a', 'label' => 'L', 'subtitle' => 'S', 'badge' => 'B', 'warning' => 'W'],
            (new SearchPickerResult('a', 'L', 'S', 'B', 'W'))->toArray()
        );
    }

    public function testAnEmptyOptionalFieldIsOmittedLikeANullOne(): void
    {
        $this->assertSame(
            ['id' => 1, 'label' => 'L'],
            (new SearchPickerResult(1, 'L', '', null, ''))->toArray()
        );
    }

    public function testThePayloadIsTheSuccessEnvelope(): void
    {
        $this->assertSame(
            ['success' => true, 'results' => [['id' => 1, 'label' => 'A'], ['id' => 2, 'label' => 'B', 'badge' => 'X']]],
            SearchPickerResult::payload([new SearchPickerResult(1, 'A'), new SearchPickerResult(2, 'B', null, 'X')])
        );
    }

    public function testNoResultIsStillASuccessfulAnswer(): void
    {
        // The picker says « nothing matches » itself; a failure here would
        // read as a broken field.
        $this->assertSame(['success' => true, 'results' => []], SearchPickerResult::payload([]));
    }
}
