<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Camps\Task;

use PHPUnit\Framework\TestCase;

/**
 * `geocode_places` is seeded from the composition root, and that is where
 * it went wrong — so, like `RefreshPlaceSummariesWiringTest` next door,
 * this reads the real source rather than a stand-in.
 *
 * The shape matters. `GeocodePlacesHandler` geocodes ONE place per run and
 * re-arms itself only while more are pending: an empty queue is meant to
 * end the chain. Seeding it the way the module's three daily tasks are
 * seeded — `rearm(..., '+1 minute')` on every page load — restarted that
 * chain a minute after it ended, for ever. The live site ran it 277 times
 * in ten hours, each run finding nothing to do in two milliseconds, and
 * those runs were a third of everything in the event journal.
 *
 * The invariant is therefore not "it is seeded" but "it is seeded only
 * when there is something to geocode".
 */
class GeocodeSeedWiringTest extends TestCase
{
    private static function indexSource(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents, 'public/index.php must be readable');

        return $contents;
    }

    public function testTheSeedIsGuardedOnThereBeingSomethingToGeocode(): void
    {
        $source = self::indexSource();

        $guard = strpos($source, 'countPendingGeocoding() > 0');
        $this->assertNotFalse(
            $guard,
            'geocode_places must only be seeded when a place is actually waiting for coordinates.'
        );

        $seed = strpos($source, 'Task\GeocodePlacesHandler::TASK_KEY');
        $this->assertNotFalse($seed, 'the geocoding task must still be seeded from the composition root');
        $this->assertGreaterThan($guard, $seed, 'the seed must sit inside the pending-work guard, not before it');
        $this->assertLessThan(
            400,
            $seed - $guard,
            'the seed must be the body of the guard, not merely somewhere after it'
        );
    }

    /**
     * The three daily tasks re-arm to a fixed hour and are correctly
     * seeded unconditionally. Geocoding must not be back among them: that
     * list is exactly where it was, and where it spun.
     */
    public function testTheGeocodingTaskIsNotBackInTheUnconditionalDailyLoop(): void
    {
        $source = self::indexSource();

        $start = strpos($source, 'Task\ReviewReminderHandler::TASK_KEY');
        $this->assertNotFalse($start, 'the daily camps seeding loop must still exist');
        $end = strpos($source, '$schedulerService->rearm(\'camps\', $campsTaskKey', $start);
        $this->assertNotFalse($end, 'the daily camps seeding loop must still end in a rearm() call');

        $this->assertStringNotContainsString(
            'GeocodePlacesHandler',
            substr($source, $start, $end - $start),
            'geocode_places is not periodic and must not be seeded unconditionally with the daily tasks.'
        );
    }
}
