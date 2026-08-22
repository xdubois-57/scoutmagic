<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Support;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Support\GroupLabel;
use PHPUnit\Framework\TestCase;

/**
 * How a group is named on screen — see Support\GroupLabel's own docblock
 * for why only the current year is ever appended.
 */
class GroupLabelTest extends TestCase
{
    public function testAGroupTiedToTheYearInEffectCarriesItAfterItsName(): void
    {
        $this->assertSame(
            'Louveteaux (2025-2026)',
            GroupLabel::withYear($this->group('Louveteaux', 4), 4, '2025-2026')
        );
    }

    /**
     * A past-year group is already marked as an archive wherever it
     * appears; repeating the year there would say twice what the badge
     * says once — and would say the WRONG year, since the label passed in
     * is the effective one, not the group's.
     */
    public function testAPastYearGroupIsNamedByItsNameAlone(): void
    {
        $this->assertSame('Louveteaux', GroupLabel::withYear($this->group('Louveteaux', 3), 4, '2025-2026'));
    }

    public function testAGroupTiedToNoYearAtAllIsNamedByItsNameAlone(): void
    {
        $this->assertSame(
            'Coordination camp',
            GroupLabel::withYear($this->group('Coordination camp', null), 4, '2025-2026')
        );
    }

    public function testAGroupAlreadyNamedAfterTheYearDoesNotSayItTwice(): void
    {
        $this->assertSame(
            'Louveteaux 2025-2026',
            GroupLabel::withYear($this->group('Louveteaux 2025-2026', 4), 4, '2025-2026')
        );
    }

    public function testAnEmptyYearLabelAddsNothing(): void
    {
        $this->assertSame('Louveteaux', GroupLabel::withYear($this->group('Louveteaux', 4), 4, '  '));
    }

    private function group(string $name, ?int $scoutYearId): DiscussionGroup
    {
        return new DiscussionGroup(1, $name, $scoutYearId, null, null, '2026-01-01 10:00:00', null, '2026-01-01 10:00:00');
    }
}
