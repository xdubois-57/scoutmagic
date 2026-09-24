<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use PHPUnit\Framework\TestCase;

/**
 * The eight types, declared in module.json (a module's types live there,
 * not in Core\Notification\NotificationRegistry): `in_app` locked on
 * everywhere — the one channel certain to arrive — push and e-mail on by
 * default, except the e-mail of the reminder and of a withdrawal.
 */
final class NotificationTypesTest extends TestCase
{
    public function testTheEightTypesAndTheirChannels(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/covoiturage/module.json'),
            true
        );
        $channels = [];
        foreach ($manifest['notifications'] as $type) {
            $channels[$type['id']] = $type['channels'];
        }

        $this->assertSame([
            'covoiturage.request_received',
            'covoiturage.request_pending',
            'covoiturage.request_withdrawn',
            'covoiturage.request_accepted',
            'covoiturage.request_refused',
            'covoiturage.seat_revoked',
            'covoiturage.offer_changed',
            'covoiturage.offer_cancelled',
        ], array_keys($channels));

        foreach ($channels as $id => $channel) {
            $this->assertSame('on', $channel['in_app'], $id);
            $this->assertSame('default_on', $channel['push'], $id);
            $quiet = in_array($id, ['covoiturage.request_pending', 'covoiturage.request_withdrawn'], true);
            $this->assertSame($quiet ? 'off' : 'default_on', $channel['email'], $id);
        }
    }
}
