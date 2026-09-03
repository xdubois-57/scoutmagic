<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use PHPUnit\Framework\TestCase;

/**
 * What the composition roots build the Camps consumer with.
 *
 * Registration ORDER is no longer pinned here: `MessageConsumerRegistry`
 * asks every consumer and applies every answer (§8.58, « everybody
 * analyses; nobody wins »), and which module may look at which box is
 * the mailbox configuration's decision, not the order of a list. What
 * still matters is that the consumer the web path builds can do
 * everything the scheduled one can — the two tests below.
 */
final class ConsumerRegistrationOrderTest extends TestCase
{

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
     * And it can re-analyse, which is the same defect one screen further
     * along.
     *
     * « Relancer l'analyse » on /chefs/camps/courrier runs THIS consumer,
     * through `InboundMailService::reanalyzeUnlinked()`. It was built with
     * `null` for the gateway and nothing for the reading services, so the
     * button could not look a thread up, could not read an attachment and
     * could not recognise a stay by its period: it re-checked the sender's
     * address, the one signal that had already failed on arrival, and
     * reported « rien de neuf » with complete confidence.
     *
     * Nothing about that was visible from either call site — the button
     * worked, it simply could not find anything — which is why it is a
     * test.
     */
    public function testTheWebRegistrysCampsConsumerCanReanalyseAsWellAsTheHourlyTask(): void
    {
        $factory = self::webCampsFactory();

        $this->assertStringContainsString(
            'inboundMailForOthers',
            $factory,
            'the web registry builds a Camps consumer with no gateway: « Relancer l\'analyse » '
            . 'could not recognise a reply in a thread already attached to a stay'
        );
        $this->assertStringContainsString(
            'campsStayFromMail',
            $factory,
            'without it the re-run reads no attachment, so a booking that states its dates only '
            . 'in its contract stays unattached however often the button is pressed'
        );
        $this->assertStringContainsString(
            'ExistingStayMatcher',
            $factory,
            'without it the re-run cannot attach a message to a stay the unit already booked'
        );
    }

    /**
     * The camps factory in public/index.php and nothing else — anchored on
     * the consumer id it registers, so a wide scan cannot make another
     * module's dependencies look like this one's.
     */
    private static function webCampsFactory(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/public/index.php');

        self::assertSame(
            1,
            preg_match(
                '/registerFactory\(\s*\\\\Modules\\\\Camps\\\\Mail\\\\CampsMessageConsumer::CONSUMER_ID,'
                . '(.*?)\n        \);/s',
                $source,
                $factory
            ),
            'no registerFactory() for the Camps consumer in public/index.php'
        );

        return $factory[1];
    }

    /**
     * The hourly task gets it too, or the two passes disagree about what
     * they can recognise — and the way they disagree is silent.
     */
    public function testTheHourlyTasksCampsConsumerCanRecogniseAStayByItsPeriod(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 4) . '/public/scheduler-bootstrap.php');

        $this->assertStringContainsString(
            'new \\Modules\\Camps\\Mail\\ExistingStayMatcher(',
            $bootstrap
        );
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
