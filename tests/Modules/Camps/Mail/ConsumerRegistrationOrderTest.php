<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use PHPUnit\Framework\TestCase;

/**
 * The camps consumer must be registered LAST.
 *
 * `MessageConsumerRegistry` is first-claim-wins in registration order,
 * and a DEDICATED camps mailbox claims everything it is offered. Register
 * it before another module's consumer and it silently swallows the mail
 * that module was waiting for — rental's own mailbox setting defaults to
 * "all mailboxes", so on an installation running both, every message
 * would go to whichever registered first.
 *
 * The failure is completely silent: no error, no log, just a rental
 * booking whose correspondence stops arriving. The whole registry now
 * lives in the sync handler's lazy factory in
 * public/scheduler-bootstrap.php — one file, one ordering, both entry
 * points — so that is where the order is pinned.
 */
final class ConsumerRegistrationOrderTest extends TestCase
{
    private string $bootstrap;

    protected function setUp(): void
    {
        $this->bootstrap = (string) file_get_contents(dirname(__DIR__, 4) . '/public/scheduler-bootstrap.php');
    }

    public function testTheCampsConsumerIsRegisteredAfterEveryOtherOne(): void
    {
        $campsOffset = strpos($this->bootstrap, 'new \\Modules\\Camps\\Mail\\CampsMessageConsumer(');
        $this->assertNotFalse($campsOffset, 'the camps consumer is no longer registered at all');

        preg_match_all('/\$registry->register\(/', $this->bootstrap, $m, PREG_OFFSET_CAPTURE);
        $offsets = array_map(static fn(array $hit): int => $hit[1], $m[0]);

        $this->assertNotEmpty($offsets);
        $this->assertGreaterThanOrEqual(
            max($offsets),
            $campsOffset,
            'A consumer is registered AFTER the camps one. The registry is first-claim-wins, '
            . 'and a dedicated camps mailbox claims everything — so camps must always come last.'
        );
    }

    public function testTheDedicatedMailboxSettingWarnsAboutOtherModules(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/camps/module.json'),
            true
        );
        $this->assertIsArray($manifest);

        $description = '';
        foreach ($manifest['settings'] ?? [] as $setting) {
            if (($setting['key'] ?? '') === 'camps_dedicated_mailbox_ids') {
                $description = (string) ($setting['description'] ?? '');
            }
        }

        // Registration order protects the code path; only this sentence
        // protects the administrator who points two modules at one
        // mailbox.
        $this->assertStringContainsString('exclue', $description);
        $this->assertStringContainsString('location', $description);
    }
}
