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

    /**
     * The web registry's Camps consumer can file a document, and that is
     * not a detail.
     *
     * It used to be built read-only — `(camps, pdo, encryption)` — on the
     * reasoning that this registry only ever answers « may this person
     * download that file ». But `InboundMailService::attach()` calls
     * `onLinked()` through this same registry, and `onLinked()` is where
     * Camps turns a message's attachments into a stay's documents. A
     * consumer built without a document service answered that callback by
     * doing nothing, in silence: « Créer un camp depuis ce message » made
     * the stay and left the contract behind in the mailbox.
     *
     * Nothing about that was visible from either call site, which is why
     * it is a test.
     */
    public function testTheWebRegistrysCampsConsumerCanFileADocument(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/public/index.php');

        // The Camps factory and nothing else — anchored on the consumer id
        // it registers, so a wide scan cannot make another module's
        // dependencies look like this one's.
        $this->assertSame(
            1,
            preg_match(
                '/registerFactory\(\s*\\\\Modules\\\\Camps\\\\Mail\\\\CampsMessageConsumer::CONSUMER_ID,'
                . '(.*?)\n        \);/s',
                $source,
                $factory
            ),
            'no registerFactory() for the Camps consumer in public/index.php'
        );

        $this->assertStringContainsString(
            'campsDocumentService',
            $factory[1],
            'the web registry builds a Camps consumer with no document service: attach() would '
            . 'create the association and file nothing, which is how a stay ended up without '
            . 'the contract that made it'
        );
        $this->assertStringContainsString('campsFieldCompletion', $factory[1]);
    }

    /**
     * The old list of dedicated boxes is retired, and its description has
     * to say so.
     *
     * It used to carry the warning about pointing two modules at one
     * mailbox — a warning the « Portée des modules » screen now gives in
     * place. What matters here is the opposite: a superadmin must not spend
     * an afternoon filling in a field the module stopped reading, which is
     * exactly how the automatic stay creation came to be off on an
     * installation that had configured everything correctly.
     */
    public function testTheRetiredDedicatedMailboxSettingSaysWhereTheAnswerLivesNow(): void
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

        $this->assertStringContainsString('HISTORIQUE', $description);
        $this->assertStringContainsString('Courrier entrant', $description);
    }
}
