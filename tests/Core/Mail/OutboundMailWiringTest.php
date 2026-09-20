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

    // ── the manual probe (roadmap IT-04) ──────────────────────────────

    /**
     * The controller takes the sender as a NULLABLE dependency, which is
     * the exact shape this whole file exists for: with null the sub-page
     * says « la sonde n'est pas disponible », which looks like a
     * deliberate state rather than a wiring that was never done.
     */
    public function testTheControllerActuallyReceivesTheProbeSender(): void
    {
        $source = self::source('public/index.php');

        $this->assertStringContainsString(
            'new \\Core\\Mail\\Probe\\MailProbeSender(',
            $source,
            'public/index.php must build the probe sender, or the sub-page is permanently unavailable.'
        );
        $this->assertStringContainsString(
            '$mailProbeRepository',
            $source,
            'And hand the page the history to display.'
        );
    }

    /**
     * **The transport underneath, and not one of its own.**
     *
     * The probe pins a relay, so it cannot go through the chain — which
     * means it needs the transport the chain itself delivers with. A
     * `new PhpMailerTransport()` spelled here instead would put real mail
     * on the wire from an installation configured to capture it, which is
     * exactly what `test_tools` exists to prevent. It also hung the
     * probe's own test against a real SMTP host, which is how this was
     * found.
     */
    public function testTheProbeSendsThroughTheTransportThisInstallationActuallyUses(): void
    {
        $source = self::source('public/index.php');

        $this->assertStringContainsString(
            "\$mailTransport['delivery']",
            $source,
            'The probe must deliver through the transport the factory resolved, never through a fresh one.'
        );

        $this->assertStringNotContainsString(
            'new \\Core\\Mail\\PhpMailerTransport(',
            self::source('core/Mail/Probe/MailProbeSender.php'),
            'MailProbeSender must take its transport, never reach for the real one itself.'
        );
    }

    /**
     * The archive reports what was tested; it must not be able to test.
     */
    public function testTheCollectorGetsTheProbeHistoryAndNotTheSender(): void
    {
        $source = self::source('core/Support/SupportPackageFactory.php');

        $this->assertStringContainsString(
            'new \\Core\\Mail\\Probe\\MailProbeRepository($pdo, $context->encryption)',
            $source
        );
        $this->assertStringNotContainsString(
            'new \\Core\\Mail\\Probe\\MailProbeSender(',
            $source,
            'A support package must never be able to send a probe while it is being assembled.'
        );
    }

    // ── the bounces are wired, both halves (roadmap IT-05) ────────────

    /**
     * **The half that records, and it takes TWO registrations — the same
     * two `testTheCoreConsumerIsRegisteredOnBothConsumerRegistries`
     * pins for the return-path consumer.** The web registry in
     * public/index.php is what the mailbox configuration screen reads,
     * so a consumer missing from it can never be granted a box. The one
     * built in public/scheduler-bootstrap.php is what the sync pass
     * calls `analyze()` against, so a consumer missing from THAT is
     * offered on screen, ticked, and then asked nothing — the whole
     * feature inert with no symptom but silence.
     */
    public function testTheBounceConsumerIsRegisteredOnBothConsumerRegistries(): void
    {
        $web = self::source('public/index.php');

        $this->assertStringContainsString(
            '\Core\Mail\Feedback\Bounce\BounceConsumer::CONSUMER_ID',
            $web,
            'Without this registration the superadmin can never grant a box to the bounce consumer.'
        );
        $this->assertStringContainsString('new \Core\Mail\Feedback\Bounce\BounceConsumer(', $web);

        $this->assertStringContainsString(
            'new \Core\Mail\Feedback\Bounce\BounceConsumer(',
            self::source('public/scheduler-bootstrap.php'),
            'The sync pass never asks the bounce consumer, so no bounce is ever recorded.'
        );
    }

    /**
     * The DMARC consumer, and the same two registrations for the same two
     * reasons (roadmap IT-06).
     *
     * Missing from the web registry, a super-admin can never grant it a
     * mailbox. Missing from the scheduler's, the box is granted, ticked,
     * and then asked nothing — the whole feature inert with no symptom
     * but an empty page that reads as « personne n'envoie en votre nom ».
     */
    public function testTheDmarcConsumerIsRegisteredOnBothConsumerRegistries(): void
    {
        $web = self::source('public/index.php');

        $this->assertStringContainsString(
            '\Core\Mail\Feedback\Dmarc\DmarcConsumer::CONSUMER_ID',
            $web,
            'Without this registration the superadmin can never grant a box to the DMARC consumer.'
        );
        $this->assertStringContainsString('new \Core\Mail\Feedback\Dmarc\DmarcConsumer(', $web);

        $this->assertStringContainsString(
            'new \Core\Mail\Feedback\Dmarc\DmarcConsumer(',
            self::source('public/scheduler-bootstrap.php'),
            'The sync pass never asks the DMARC consumer, so no report is ever recorded.'
        );
    }

    /**
     * **And the payload pass has to be wired too**, which is a separate
     * failure from the consumer being registered.
     *
     * A consumer can be on both registries, scoped to a box, handed every
     * message — and still never see a byte, because `analyzeAll()` asks
     * `analyze()` and a DMARC report says nothing in its body. The report
     * lives in the attachment, and only `analyzeAllPayloads()` reaches it.
     * Wired here rather than pinned by reading the consumer, because the
     * consumer looks identical either way.
     */
    public function testTheSyncRunsThePayloadPassAndNotOnlyTheArrivalOne(): void
    {
        $this->assertStringContainsString(
            'analyzeAllPayloads(',
            self::source('modules/inbound_mail/src/Service/MailboxSyncService.php'),
            'Without this the DMARC consumer is asked about a body that never carries a report.'
        );
    }

    /**
     * **The half that says « we wrote here », and it is the one every
     * message passes through.** A bounce counts only for an address the
     * site can show it wrote to, so the receipt is what makes any bounce
     * admissible at all. Stamped anywhere narrower than
     * `Core\Mail\MailService::send()` — in the mailing task alone, as it
     * first was — an installation without that module could never block
     * anything while its Rebonds page went on promising it would, and
     * the likeliest real bounce of the lot, a freshly mistyped address
     * refused on its very first confirmation mail, would be the one the
     * site threw away.
     *
     * Both entry points, because `public/cron.php` builds its own and a
     * receipt missing there means every scheduled mailing is invisible to
     * the bounce rule.
     */
    public function testEveryEntryPointHandsTheMailFactoryItsSendReceipts(): void
    {
        foreach (['public/index.php', 'public/cron.php'] as $entryPoint) {
            $source = self::source($entryPoint);

            $position = strpos($source, 'MailServiceFactory::create(');
            $this->assertNotFalse($position, $entryPoint . ' must build the mail service from the factory.');

            $this->assertStringContainsString(
                'new \Core\Mail\Feedback\Bounce\BounceStateRepository(',
                substr($source, $position, 900),
                $entryPoint . ' builds a MailService that notes no send, so no bounce is ever believed.'
            );
        }
    }

    /**
     * And the service actually stamps one. A dependency it accepts and
     * never calls is the same absence, one layer further in.
     */
    public function testTheMailServiceStampsAReceiptOnAMessageThatLeft(): void
    {
        $this->assertStringContainsString(
            '$this->sendReceipts?->recordSend(',
            self::source('core/Mail/MailService.php'),
            'MailService takes the receipts and never writes one.'
        );
    }

    /**
     * **A receipt takes two independent conditions, and forgetting
     * either fails safe.** The caller says the site chose this recipient,
     * defaulting to no; `recordSend()` separately refuses an address the
     * site does not hold.
     *
     * Neither alone survived review. A caller-side flag defaulting to
     * yes was missed by a public form, a registration twin, a claimed
     * secondary address and the deferred-mail queue. The address-side
     * check alone lets an attacker aim a send at an address that IS on
     * file — `member_emails` is unique per member, so a member can claim
     * another's confirmed address — and the receipt is minted for the
     * victim.
     */
    public function testAReceiptTakesBothTheCallersWordAndTheAddressesOwn(): void
    {
        $this->assertStringContainsString(
            'bool $vouchesForRecipient = false',
            self::source('core/Mail/MailService.php'),
            'the caller-side half must default to NO, or every caller that forgets it mints a receipt.'
        );

        $this->assertStringContainsString(
            'private function isOnFile(string $email): bool',
            self::source('core/Mail/Feedback/Bounce/BounceStateRepository.php'),
            'without this lookup every send vouches for its own recipient.'
        );
    }

    /**
     * And the consumer the SCHEDULER builds is given a notifier too.
     * Only that one ever runs `analyze()`, so a notifier present on the
     * web side alone would tell nobody anything: the block would land
     * and the member would simply find the unit gone quiet.
     */
    public function testTheSchedulerSideBounceConsumerCanAlsoTellSomebody(): void
    {
        $this->assertStringContainsString(
            'new \Core\Mail\Feedback\Bounce\MemberBounceNotifier(',
            self::source('public/scheduler-bootstrap.php')
        );
    }

    /**
     * The consumer is given a notifier. Without one the bounce is
     * recorded and the member is never told — the block still happens,
     * and to them the unit has simply gone quiet.
     */
    public function testTheBounceConsumerCanActuallyTellSomebody(): void
    {
        $this->assertStringContainsString(
            'new \Core\Mail\Feedback\Bounce\MemberBounceNotifier(',
            self::source('public/index.php')
        );
    }

    /**
     * **The half that reads, and it is deliberately NOT inside the
     * `inbound_mail` branch.** The bounce table is core, and « cette
     * adresse est-elle suspendue » has to answer correctly on every
     * installation. Built inside the module branch, a site without the
     * module would resolve blocked addresses for every mailing and go on
     * writing to them — which is the exact failure this whole chantier
     * exists to end.
     */
    public function testTheAddressResolverIsGivenTheBounceState(): void
    {
        $source = self::source('public/index.php');

        $position = strpos($source, 'new \Core\Member\MemberEmailService(');
        $this->assertNotFalse($position, 'MemberEmailService must be built in the composition root.');

        $construction = substr($source, $position, 1400);

        $this->assertStringContainsString(
            'new \Core\Mail\Feedback\Bounce\BounceService(',
            $construction,
            'Without this, a blocked address is resolved for every mailing exactly as before.'
        );
    }

    /**
     * **And the SECOND composition root of the same class**, which had
     * gone without it.
     *
     * `Modules\MassMail\Task\SendBatchHandler` builds its own
     * `MemberEmailService`. Nothing on that path resolves addresses today
     * — the freeze happens in the web request — so the omission cost
     * nothing yet, and that is exactly why it would have survived: a null
     * there answers « jamais rebondi » silently, and the day anything on
     * the scheduler path asks, it gets the wrong answer with no failure
     * anywhere.
     *
     * Two roots for one class is the shape this chantier kept tripping
     * over. One of them being right is not the same as the wiring being
     * right.
     */
    public function testTheSchedulersOwnAddressResolverIsGivenItToo(): void
    {
        $source = self::source('modules/mass_mail/src/Task/SendBatchHandler.php');

        $position = strpos($source, 'new \Core\Member\MemberEmailService(');
        $this->assertNotFalse($position, 'the scheduler builds its own, so it has to be found here.');

        $construction = substr($source, $position, 1400);

        $this->assertStringContainsString(
            'new \Core\Mail\Feedback\Bounce\BounceService(',
            $construction,
            'the two roots of one class must answer « suspendue ? » the same way.'
        );
    }

    // ── the seed mailboxes reach the module, on BOTH roots (IT-07) ────

    /**
     * **The quietest failure of IT-07, and it shipped.**
     *
     * `SeedMailboxes::addresses()` answers « no boxes » until something
     * hands it `inbound_mail`. `public/index.php` did;
     * `public/cron.php` built the object with a comment promising the
     * same « further down » and never did it — and `cron.php` is the only
     * entry point that ever runs a mailing
     * (`mass_mail`'s `SendBatchHandler` is a scheduled task, and
     * `index.php` never calls `processOverdue()`).
     *
     * So the feature was wired, green, configurable, switched on in the
     * interface, and emitted not one copy on a real installation. Nothing
     * failed anywhere: a unit that turned the copies on would simply
     * watch a results table stay empty for ever and conclude its
     * mailings were fine.
     *
     * Both roots now hand the object to the shared bootstrap, which is
     * the one place that knows how to resolve the module — §8.17's lesson
     * again, and the reason this is asserted on the CALL rather than on
     * some behaviour a test double could satisfy.
     */
    public function testBothEntryPointsHandTheSeedMailboxesToTheSharedBootstrap(): void
    {
        foreach (['public/index.php', 'public/cron.php'] as $root) {
            $source = self::source($root);

            $position = strpos($source, 'scoutmagicBootstrapScheduler(');
            $this->assertNotFalse($position, $root . ' no longer bootstraps the scheduler.');

            $call = substr($source, $position, 1600);
            $end = strpos($call, "\n);");
            $this->assertNotFalse($end, $root . ': the bootstrap call could not be delimited.');

            $this->assertStringContainsString(
                '$seedMailboxes',
                substr($call, 0, $end),
                $root . ' does not hand its seed mailboxes to the shared bootstrap, so on that entry point '
                . 'the copies are never emitted — silently, which is how this was missed the first time.'
            );
        }
    }

    /**
     * And the bootstrap does something with it rather than accepting it
     * politely.
     */
    public function testTheSharedBootstrapResolvesTheModuleForThem(): void
    {
        $source = self::source('public/scheduler-bootstrap.php');

        $this->assertStringContainsString(
            '$seedMailboxes?->resolveInboundMailWith(',
            $source,
            'the bootstrap takes the seed mailboxes and never tells them where to ask.'
        );
    }

    /**
     * **The archive's seed section says whether it could see what it
     * measures**, and only the factory can give it the answer.
     *
     * The collector's own test builds that argument itself, so it would
     * go on passing with the factory omitting it — the exact shape of
     * defect this iteration produced five times: a fixture supplying a
     * condition production does not. The consequence here is a third
     * party reading « perdus : 5 » as a sender being refused, when five
     * messages were delivered into a folder nobody was watching.
     */
    public function testTheArchiveIsToldWhetherTheSeedBoxesCanSeeTheirJunkFolder(): void
    {
        $source = self::source('core/Support/SupportPackageFactory.php');

        $this->assertStringContainsString(
            'new \\Core\\Mail\\Feedback\\Seed\\SeedMailboxes(',
            $source,
            'the collector is built without the boxes, so its blind-spot counter is always zero.'
        );
    }
}
