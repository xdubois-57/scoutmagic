<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\DeskImportYearWindow;
use PHPUnit\Framework\TestCase;

/**
 * The days the import page shows, computed in PHP because a template must
 * never parse a date itself (TemplateDateReadingRatchetTest).
 */
class DeskImportYearWindowTest extends TestCase
{
    public function testTheThreeDaysOfThePageAreShownAsDayAndMonth(): void
    {
        $window = $this->window('2027-07-01', '2027-08-15');

        self::assertSame('01/07', $window->nextStartDayMonth());
        self::assertSame('15/08', $window->encodingDayMonth());
        self::assertSame('14/08', $window->eveOfEncodingDayMonth());
    }

    public function testTheEveOfAFirstOfTheMonthIsTheLastDayOfTheMonthBefore(): void
    {
        $window = $this->window('2027-02-01', '2027-03-01');

        self::assertSame('28/02', $window->eveOfEncodingDayMonth());
    }

    private function window(string $nextStart, string $encoding): DeskImportYearWindow
    {
        $public = ['id' => 1, 'label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'];

        return new DeskImportYearWindow([], 1, DeskImportYearWindow::PERIOD_BEFORE, false, $public, null, $nextStart, $encoding);
    }
}
