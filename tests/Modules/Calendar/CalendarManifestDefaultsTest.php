<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar;

use PHPUnit\Framework\TestCase;

/**
 * modules/calendar/module.json's event_default_end_time setting — the
 * value a fresh install (or a unit that never customized it) pre-fills in
 * the "Heure de fin" field of the event-creation dialog.
 */
class CalendarManifestDefaultsTest extends TestCase
{
    public function testEventDefaultEndTimeIs1745(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/calendar/module.json'),
            true
        );

        $setting = null;
        foreach ($manifest['settings'] as $candidate) {
            if ($candidate['key'] === 'event_default_end_time') {
                $setting = $candidate;
            }
        }

        $this->assertNotNull($setting);
        $this->assertSame('17:45', $setting['default_value']);
    }
}
