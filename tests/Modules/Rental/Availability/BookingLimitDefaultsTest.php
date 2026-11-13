<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Availability;

use Core\Database\SchemaComparator;
use Core\Database\SqlParser;
use Modules\Rental\Availability\BookingConstraints;
use PHPUnit\Framework\TestCase;

/**
 * An asset is no longer unlimited by default (issue #1114): the schema
 * declares the longest stay and the horizon the code names, and the assets
 * still on the old 0 — which nobody chose — follow at the next migration.
 */
class BookingLimitDefaultsTest extends TestCase
{
    /** @return array<string, ?string> column => declared default */
    private static function assetDefaults(): array
    {
        $file = dirname(__DIR__, 4) . '/modules/rental/schema.sql';
        foreach ((new SqlParser())->parseFile($file) as $table) {
            if ($table->name === 'rental_assets') {
                $defaults = [];
                foreach ($table->columns as $column) {
                    $defaults[$column->name] = $column->default;
                }

                return $defaults;
            }
        }

        self::fail('rental_assets is not declared in the rental schema');
    }

    public function testTheSchemaDeclaresTheDefaultsTheCodeNames(): void
    {
        $defaults = self::assetDefaults();

        $this->assertSame((string) BookingConstraints::DEFAULT_MAX_NIGHTS, $defaults['max_nights']);
        $this->assertSame((string) BookingConstraints::DEFAULT_MAX_HORIZON_DAYS, $defaults['max_horizon_days']);
    }

    public function testTheDefaultsAreAStayAMonthLongAndAboutEighteenMonthsAhead(): void
    {
        $this->assertSame(30, BookingConstraints::DEFAULT_MAX_NIGHTS);
        $this->assertGreaterThanOrEqual(545, BookingConstraints::DEFAULT_MAX_HORIZON_DAYS);
        $this->assertLessThanOrEqual(550, BookingConstraints::DEFAULT_MAX_HORIZON_DAYS);
    }

    /** Without this, every asset installed before the change kept a calendar one request could take whole. */
    public function testTheAssetsStillOnTheOldDefaultFollowTheNewOne(): void
    {
        $this->assertContains('rental_assets.max_nights', SchemaComparator::DEFAULT_FOLLOWING_COLUMNS);
        $this->assertContains('rental_assets.max_horizon_days', SchemaComparator::DEFAULT_FOLLOWING_COLUMNS);
    }

    /** The other two numeric rules keep 0: a minimum or a notice is not what lets a request take the calendar. */
    public function testTheMinimumAndTheNoticeStayWithoutDefault(): void
    {
        $defaults = self::assetDefaults();

        $this->assertSame('0', $defaults['min_nights']);
        $this->assertSame('0', $defaults['min_notice_days']);
        $this->assertNotContains('rental_assets.min_nights', SchemaComparator::DEFAULT_FOLLOWING_COLUMNS);
    }
}
