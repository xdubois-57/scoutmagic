<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use Core\Mail\MailIdentity;
use Core\Mail\MailPurpose;
use Core\Mail\MailServiceFactory;
use Core\Mail\MailTransportInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * What the composition roots actually build for the outbound mail
 * (roadmap IT-03).
 *
 * **This file exists because the same mistake has now been made three
 * times in this chantier.** A dependency added to a class is a dependency
 * ABSENT from the composition root until a test says otherwise: IT-02
 * shipped a deferral queue and a circuit breaker that were entirely
 * tested and entirely dead in production, because every test built the
 * object itself. Everything IT-03 adds has the same shape — a nullable
 * gateway, a settings key merged in by hand, two new collector arguments
 * — and every one of them fails silently rather than loudly.
 *
 * The route table and the wiring live in procedural bootstraps no unit
 * test loads, so they get source-level assertions — the same technique as
 * `Tests\Modules\Rental\Mail\RentalInboundMailWiringTest`.
 */
class OutboundMailWiringTest extends TestCase
{
    private static function source(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($contents, $relativePath . ' is unreadable.');

        return $contents;
    }

    // ── the return round trip reaches the real gateway ────────────────

    /**
     * The failure this guards against is the quietest one in the whole
     * iteration: with a null gateway every state reads « vérification
     * impossible », which is ALSO the honest answer on an installation
     * without `inbound_mail` — so the screen would look correct on every
     * site, and be wrong on the ones that have the module.
     */
    public function testTheVerifierIsBuiltWithTheInboundGatewayAndNotWithNull(): void
    {
        $source = self::source('public/index.php');

        $this->assertMatchesRegularExpression(
            '/new \\\\Core\\\\Mail\\\\Feedback\\\\ReturnPathVerifier\('
                . '\s*\n\s*new \\\\Core\\\\Mail\\\\Feedback\\\\ReturnProbeRepository\(\$pdo, \$encryptionService\),'
                . '\s*\n\s*\$mailService,'
                . '\s*\n\s*\$journalService,'
                . '\s*\n\s*\$inboundMailForOthers\s*\n\s*\);/',
            $source,
            'public/index.php must hand the verifier the resolved inbound-mail gateway.'
        );
    }

    /**
     * And the ordering fact that makes the line above possible: the
     * gateway does not exist until the module's own block has run, so the
     * controller is registered after it.
     */
    public function testTheControllerIsRegisteredAfterTheInboundMailBlockThatResolvesTheGateway(): void
    {
        $source = self::source('public/index.php');

        $gateway = strpos($source, '$inboundMailForOthers = new \\Modules\\InboundMail\\Service\\InboundMailService(');
        $controller = strpos($source, 'new \\Core\\Http\\Controller\\OutboundMailController(');

        $this->assertIsInt($gateway);
        $this->assertIsInt($controller);
        $this->assertGreaterThan(
            $gateway,
            $controller,
            'The controller is built after the gateway, or $returnPathVerifier gets a variable that does not exist yet.'
        );
    }

    public function testTheControllerActuallyReceivesTheVerifier(): void
    {
        $this->assertStringContainsString(
            '$returnPathVerifier,',
            self::source('public/index.php'),
            'The controller must be handed the verifier, not build one of its own.'
        );
    }

    /**
     * Two registries, and both are load-bearing. The scheduler's is the
     * one that calls `analyze()`; the web one is what the mailbox
     * configuration screen reads to know which consumers a box may be
     * opened to. Registered on the first only, the verification would be
     * impossible to ENABLE — and the screen would show no checkbox saying
     * why.
     */
    public function testTheCoreConsumerIsRegisteredOnBothConsumerRegistries(): void
    {
        $this->assertStringContainsString(
            'new \\Core\\Mail\\Feedback\\ReturnPathConsumer(',
            self::source('public/scheduler-bootstrap.php'),
            'The sync pass never asks the core consumer, so no round trip is ever recorded.'
        );

        $this->assertStringContainsString(
            '$inboundReadConsumers->registerFactory(' . "\n"
                . '        \\Core\\Mail\\Feedback\\ReturnPathVerifier::CONSUMER_ID,',
            self::source('public/index.php'),
            'The mailbox configuration screen cannot offer a scope for a consumer it does not list.'
        );
    }

    // ── the reply address reaches MailService ─────────────────────────

    /**
     * `mail_from_address` and its siblings live in the settings table and
     * are merged back into `$secrets` by hand at each entry point — a
     * list a new key has to join, and the failure mode is a configured
     * reply address that simply never appears on a message.
     */
    public function testTheReplyAddressJoinsTheSettingsMergedBackIntoTheSecretsAtEveryEntryPoint(): void
    {
        foreach (['public/index.php', 'public/cron.php'] as $entryPoint) {
            $this->assertStringContainsString(
                "'" . MailIdentity::SETTING_REPLY_ADDRESS . "'",
                self::source($entryPoint),
                $entryPoint . ' never merges the reply address back in, so it is permanently empty there.'
            );
        }
    }

    public function testTheFactoryTurnsThatKeyIntoAReplyToOnAMessageThatNamesNone(): void
    {
        $tempDir = sys_get_temp_dir() . '/outbound_wiring_' . uniqid();
        mkdir($tempDir, 0700, true);

        try {
            $transport = new class implements MailTransportInterface {
                public ?PHPMailer $received = null;

                public function deliver(PHPMailer $mail, MailPurpose $purpose): void
                {
                    $this->received = $mail;
                }
            };

            MailServiceFactory::create(
                [
                    'mail_mode' => 'local',
                    'mail_from_address' => 'info@unite.be',
                    'mail_from_name' => 'Unité',
                    'short_name' => '25SV',
                    MailIdentity::SETTING_REPLY_ADDRESS => 'secretariat@unite.be',
                ],
                new DkimManager($tempDir),
                $transport
            )->send('parent@exemple.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');

            $mail = $transport->received;
            $this->assertInstanceOf(PHPMailer::class, $mail);
            $this->assertSame(
                [['secretariat@unite.be', '']],
                array_values($mail->getReplyToAddresses())
            );
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempDir);
        }
    }

    // ── the support package gets its two new sections ─────────────────

    public function testTheCollectorIsBuiltWithTheSettingsAndTheProbeRows(): void
    {
        $source = self::source('core/Support/SupportPackageFactory.php');

        $this->assertStringContainsString(
            'new \\Core\\Mail\\Feedback\\ReturnProbeRepository($pdo, $context->encryption)',
            $source,
            'Without the probe rows the archive loses the section that says whether the returns arrive.'
        );
        $this->assertStringNotContainsString(
            'new \\Core\\Mail\\Feedback\\ReturnPathVerifier(',
            $source,
            'A support package must never be able to SEND a probe while it is being assembled.'
        );
    }
}
