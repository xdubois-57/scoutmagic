<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Probe;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Mail\Probe\MailProbeException;
use Core\Mail\Probe\MailProbeRepository;
use Core\Mail\Probe\MailProbeSender;
use Core\Mail\Probe\MailProbeVerdict;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportConfigurator;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * The manual probe (roadmap IT-04): one message, by a chosen road, and a
 * verdict a person types in.
 *
 * What is pinned here is the handful of properties that make the answer
 * mean anything — the message is built like a real one, it leaves by the
 * relay that was asked for and by no other, a send that fails leaves no
 * row, and the history keeps naming a relay that has since been deleted.
 */
#[Group('database')]
class MailProbeSenderTest extends TestCase
{
    private \PDO $pdo;
    private MailProbeRepository $probes;
    private MailProviderRepository $providers;
    private string $storagePath = '';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->probes = new MailProbeRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->providers = new MailProviderRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-probe-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0o770, true);
    }

    protected function tearDown(): void
    {
        if ($this->storagePath !== '') {
            self::removeDirectory($this->storagePath);
        }
    }

    // ── the message ───────────────────────────────────────────────────

    /**
     * « Identique à un vrai envoi » is the whole premise: a stripped-down
     * message is weighed differently from a real one, so a probe built
     * out of a bare paragraph would measure its own shape rather than the
     * unit's mail.
     */
    public function testTheProbeIsBuiltLikeARealMessageAndNotLikeATestString(): void
    {
        $sent = $this->sendOne();

        $this->assertStringContainsString('<html', strtolower($sent->Body), 'It carries the real HTML frame.');
        $this->assertNotSame('', $sent->AltBody, 'And the plain-text half, because a real send is multipart.');
        $this->assertSame('Unité Exemple', $sent->FromName);
        $this->assertSame('info@unite.be', $sent->From);
    }

    /**
     * The code is what an operator types into a mailbox search box — spam
     * folder included, where searching is the only way through — so it
     * has to be in the subject and in the body they are looking at.
     */
    public function testTheCodeTravelsInTheSubjectAndInTheMessage(): void
    {
        $sent = $this->sendOne();

        $code = MailProbeSender::codeIn($sent->Subject);
        $this->assertNotNull($code, "The subject « {$sent->Subject} » carries no code.");
        $this->assertStringContainsString($code, $sent->Body);
        $this->assertStringContainsString($code, $sent->AltBody);
    }

    /**
     * Distinct from `ReturnPathVerifier`'s `RET-`, so that a message
     * coming back can be attributed to the right diagnostic instead of to
     * whichever one looked first.
     */
    public function testAProbeCodeIsNotMistakenForAReturnCheckKey(): void
    {
        $code = MailProbeSender::generateCode();

        $this->assertStringStartsWith('SM-', $code);
        $this->assertNull(\Core\Mail\Feedback\ReturnPathVerifier::keyIn(
            MailProbeSender::subjectFor($code)
        ));
    }

    /**
     * A real mailing carries `List-Unsubscribe`, and receivers weigh it.
     * A probe without one would be measurably lighter than the mail it
     * stands for, on precisely the signal being measured.
     */
    public function testABulkProbeCarriesAnUnsubscribeHeaderAndOtherLanesDoNot(): void
    {
        $this->assertStringContainsString(
            'List-Unsubscribe',
            $this->sendOne(MailLane::Bulk)->createHeader()
        );

        $this->assertStringNotContainsString(
            'List-Unsubscribe',
            $this->sendOne(MailLane::Transactional)->createHeader(),
            'A transactional message does not carry one, so a probe standing for one must not either.'
        );
    }

    /**
     * An installation with no expedition address configured — a real
     * one, the field is a setting and the page that fills it in is two
     * tabs away. The probe is refused rather than sent from nobody, and
     * the refusal is a `MailProbeException`, so the page says it in
     * French instead of showing whatever PHPMailer said.
     *
     * This is also why `headersFor()` has no empty-address branch: the
     * address it would guard against is the `From`, so nothing reaches
     * the header assembly without one.
     */
    public function testWithNoExpeditionAddressTheProbeIsRefusedRatherThanSentFromNobody(): void
    {
        $captured = null;
        // The relay first: the directory reads the table once, when it
        // is built.
        $providerId = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured), '');

        try {
            $sender->send('vous@exemple.be', $providerId, MailLane::Bulk);
            $this->fail('A probe with no From address must not be reported as sent.');
        } catch (MailProbeException $e) {
            $this->assertStringNotContainsString('vous@exemple.be', $e->getMessage());
        }

        $this->assertSame(0, $this->probes->count(), 'nothing may be recorded for a probe that never left.');
    }

    /**
     * A relay list that cannot be read is not a relay list that is
     * empty — but here the two behave alike on purpose: the form offers
     * nothing and the send is refused, rather than the page dying on a
     * database error at the moment somebody is diagnosing a delivery
     * problem. The same reading of the same table as the dashboard's.
     */
    public function testARelayListThatCannotBeReadOffersNothingRatherThanCrashing(): void
    {
        $captured = null;
        $sender = $this->senderWith($this->capturingTransport($captured));
        $this->pdo->exec('DROP TABLE mail_providers');

        $this->assertSame([], $sender->availableProviders());

        $this->expectException(MailProbeException::class);
        $sender->send('vous@exemple.be', 1, MailLane::Bulk);
    }
    /**
     * **Bookkeeping is never the probe's verdict.** Three things run
     * beside the send — the journal entry, the verdict's journal entry,
     * and the relay's send counter — and each is written inside a
     * `catch` that swallows its own failure on purpose.
     *
     * The reason is what the operator would otherwise be told. A message
     * that the relay accepted, reported as a failure because a counter
     * could not be incremented, has them record « jamais reçu » against
     * a relay that did its job — and the history, which is the whole
     * product of this page, then carries a verdict about the wrong
     * thing.
     *
     * Here neither store can be written. The probe still leaves, the row
     * is still written, and the verdict is still recorded.
     */
    public function testAProbeSurvivesAJournalAndACounterThatCannotBeWritten(): void
    {
        $captured = null;
        $providerId = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith(
            $this->capturingTransport($captured),
            counters: new SendCounterRepository($this->pdo)
        );

        // `send_counters` is not in this fixture at all, which is the
        // same thing from the repository's side: the statement fails.
        $this->pdo->exec('DROP TABLE event_log');

        $probe = $sender->send('vous@exemple.be', $providerId, MailLane::Bulk);

        $this->assertInstanceOf(PHPMailer::class, $captured, 'the message must still have left.');
        $this->assertSame(1, $this->probes->count());

        $this->assertTrue(
            $sender->recordVerdict($probe->id, MailProbeVerdict::Inbox),
            'a verdict is recorded even when its journal entry cannot be.'
        );
        $this->assertSame(MailProbeVerdict::Inbox, $this->probes->find($probe->id)?->verdict);
    }
    // ── the chosen road ───────────────────────────────────────────────

    /**
     * **No fallback, and that is the point.** An operator asking « est-ce
     * que mes messages arrivent quand ils partent par celui-ci » must not
     * be answered by a message that quietly went out through another: the
     * whole value of the history rests on each line naming the relay the
     * message actually left by.
     */
    public function testTheProbeLeavesByTheRelayThatWasAskedForAndByNoOther(): void
    {
        $chosen = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $this->addRelay('OVH', 'ssl0.ovh.net');

        $sent = $this->sendOne(MailLane::Bulk, $chosen);

        $this->assertSame('smtp-relay.brevo.com', $sent->Host);
    }

    /**
     * A relay that refuses the probe fails it, loudly. Falling through to
     * the next one would report « ça marche » about a road that does not.
     */
    public function testARelayThatRefusesTheProbeFailsItRatherThanFallingThrough(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->refusingTransport());

        $this->expectException(MailProbeException::class);
        $sender->send('vous@exemple.be', $id, MailLane::Bulk);
    }

    /**
     * And nothing is written down when nothing left. A row with no
     * verdict would sit in the history for ever waiting for an answer
     * nobody can give, and « jamais reçu » would then mean two different
     * things in one column.
     */
    public function testAProbeThatNeverLeftIsNotInTheHistory(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->refusingTransport());

        try {
            $sender->send('vous@exemple.be', $id, MailLane::Bulk);
        } catch (MailProbeException) {
            // The subject of the assertion below.
        }

        $this->assertSame(0, $this->probes->count());
    }

    /**
     * The one outcome that is neither a success nor a failure.
     *
     * `record()` runs after the send — it has to — so there is a narrow
     * window where the relay accepted the probe and the database refuses
     * the insert. Reporting that as « la sonde n'a pas pu partir » is
     * false twice over: the message is on its way, and the operator,
     * told it failed, presses the button again and sends a duplicate.
     */
    public function testAProbeThatLeftButCouldNotBeRecordedSaysSoAndHandsOverTheCode(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured));

        // The table goes away between the send and the insert.
        $this->pdo->exec('DROP TABLE mail_probes');

        try {
            $sender->send('vous@exemple.be', $id, MailLane::Bulk);
            $this->fail('A probe whose row could not be written must say so.');
        } catch (\Core\Mail\Probe\MailProbeNotRecordedException $e) {
            $this->assertInstanceOf(PHPMailer::class, $captured, 'The message did leave.');
            $this->assertSame(
                MailProbeSender::codeIn($captured->Subject),
                $e->probeCode,
                'The code it carries is the one the operator is told to search for.'
            );
            $this->assertStringContainsString($e->probeCode, $e->getMessage());
            $this->assertStringContainsString('ne relancez pas', $e->getMessage());
        }
    }

    /**
     * And it is a `MailProbeException`, so the controller's single catch
     * still covers it — one `catch`, two sentences.
     */
    public function testTheUnrecordedOutcomeIsStillAProbeExceptionAndIsUserFacing(): void
    {
        $exception = new \Core\Mail\Probe\MailProbeNotRecordedException('SM-ABC234');

        $this->assertInstanceOf(MailProbeException::class, $exception);
        $this->assertInstanceOf(\Core\Exception\UserFacingException::class, $exception);
    }

    public function testAnAddressThatIsNotAnAddressIsRefusedBeforeAnythingIsSent(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured));

        $this->expectException(MailProbeException::class);

        try {
            $sender->send('pas une adresse', $id, MailLane::Bulk);
        } finally {
            $this->assertNull($captured, 'Nothing was put on the wire.');
            $this->assertSame(0, $this->probes->count());
        }
    }

    public function testAProviderThatDoesNotExistIsRefused(): void
    {
        $sender = $this->senderWith($this->capturingTransport($captured));

        $this->expectException(MailProbeException::class);
        $sender->send('vous@exemple.be', 4242, MailLane::Bulk);
    }

    // ── the history ───────────────────────────────────────────────────

    /**
     * The two lines the roadmap asks this table for: same recipient, two
     * relays, two verdicts. That comparison is what settles an argument
     * no amount of explaining settles.
     */
    public function testTwoRoadsToOneAddressAreTwoComparableLines(): void
    {
        $brevo = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $ovh = $this->addRelay('OVH', 'ssl0.ovh.net');
        $sender = $this->senderWith($this->capturingTransport($captured));

        $first = $sender->send('parent@exemple.be', $brevo, MailLane::Bulk);
        $second = $sender->send('parent@exemple.be', $ovh, MailLane::Bulk);
        $sender->recordVerdict($first->id, MailProbeVerdict::Spam);
        $sender->recordVerdict($second->id, MailProbeVerdict::Inbox);

        $history = $this->probes->recent();

        $this->assertCount(2, $history);
        $this->assertSame('OVH', $history[0]->providerName);
        $this->assertSame(MailProbeVerdict::Inbox, $history[0]->verdict);
        $this->assertSame('Brevo', $history[1]->providerName);
        $this->assertSame(MailProbeVerdict::Spam, $history[1]->verdict);
        $this->assertSame('parent@exemple.be', $history[0]->destination);
    }

    /**
     * A relay deleted six months later must not take its own evidence
     * with it. The row keeps the NAME the provider had when the message
     * left, so the line goes on saying which road was tested instead of
     * showing a blank where the answer was.
     */
    public function testTheHistoryStillNamesARelayThatHasSinceBeenDeleted(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured));
        $probe = $sender->send('parent@exemple.be', $id, MailLane::Bulk);
        $sender->recordVerdict($probe->id, MailProbeVerdict::Spam);

        $this->pdo->prepare('DELETE FROM mail_providers WHERE id = ?')->execute([$id]);

        $history = $this->probes->recent();

        $this->assertCount(1, $history);
        $this->assertSame('Brevo', $history[0]->providerName);
        $this->assertSame(MailProbeVerdict::Spam, $history[0]->verdict);
    }

    /**
     * The first answer is the one the operator gave while looking at the
     * mailbox. A second press — a double click, a second tab — must not
     * overwrite it, and must say so rather than pretend it wrote.
     */
    public function testASecondVerdictDoesNotOverwriteTheFirst(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured));
        $probe = $sender->send('parent@exemple.be', $id, MailLane::Bulk);

        $this->assertTrue($sender->recordVerdict($probe->id, MailProbeVerdict::Spam));
        $this->assertFalse($sender->recordVerdict($probe->id, MailProbeVerdict::Inbox));
        $this->assertSame(MailProbeVerdict::Spam, $this->probes->find($probe->id)?->verdict);
    }

    public function testAProbeWaitingForAVerdictIsListedAsPendingAndThenStops(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured));
        $probe = $sender->send('parent@exemple.be', $id, MailLane::Bulk);

        $this->assertCount(1, $this->probes->pending());

        $sender->recordVerdict($probe->id, MailProbeVerdict::Never);

        $this->assertSame([], $this->probes->pending());
    }

    /**
     * The journal says which road was tested and what came of it, never
     * who was written to — the same rule `mail_identity_changed` follows.
     */
    public function testTheJournalNamesTheRoadAndTheVerdictButNeverTheDestination(): void
    {
        $id = $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured));
        $probe = $sender->send('parent@exemple.be', $id, MailLane::Bulk);
        $sender->recordVerdict($probe->id, MailProbeVerdict::Spam);

        $statement = $this->pdo->query(
            "SELECT event_type, context FROM event_log WHERE event_type LIKE 'mail_probe_%' ORDER BY id"
        );
        $this->assertNotFalse($statement);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame('mail_probe_sent', $rows[0]['event_type']);
        $this->assertSame('mail_probe_verdict', $rows[1]['event_type']);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('parent@exemple.be', (string) $row['context']);
            $this->assertStringContainsString('Brevo', (string) $row['context']);
        }
    }

    /**
     * The roadmap is explicit that this instrument must not run itself:
     * a test message that lands in the spam folder week after week
     * teaches the receiver that this sender belongs there, and the
     * instrument becomes the cause of what it measures. Nothing may
     * schedule one.
     */
    public function testNothingButARequestCanSendAProbe(): void
    {
        $root = dirname(__DIR__, 4);

        // The scheduler roots, which is where an unattended send would
        // have to be wired in from. `public/index.php` DOES name the
        // sender — that is the page the operator presses — so it is
        // deliberately not in this list.
        foreach (['public/cron.php', 'public/scheduler-bootstrap.php'] as $schedulerRoot) {
            $contents = file_get_contents($root . '/' . $schedulerRoot);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString(
                'MailProbeSender',
                $contents,
                "{$schedulerRoot} must not be able to send a probe: the probe is manual on purpose."
            );
        }

        // And no task handler anywhere, core's or a module's.
        // **Two levels as well as one**, and that is not thoroughness for
        // its own sake: `core/Mail/Transport/Task/` is two deep, and it is
        // the single most likely home for a future « envoyer une sonde
        // chaque semaine » handler. A one-level glob left the exact
        // directory this test exists to watch outside its own scan.
        $handlers = array_merge(
            glob($root . '/core/*/Task/*.php') ?: [],
            glob($root . '/core/*/*/Task/*.php') ?: [],
            glob($root . '/modules/*/src/Task/*.php') ?: []
        );
        $this->assertNotSame([], $handlers, 'The handlers moved; this test is looking at nothing.');
        $this->assertContains(
            $root . '/core/Mail/Transport/Task/DrainDeferredMailHandler.php',
            $handlers,
            'The mail lane\'s own task directory must be in the scan — it is where a cadence would go.'
        );

        foreach ($handlers as $handler) {
            $this->assertStringNotContainsString(
                'MailProbeSender',
                (string) file_get_contents($handler),
                basename($handler) . ' schedules a probe, which the roadmap forbids by default.'
            );
        }
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function sendOne(
        MailLane $lane = MailLane::Bulk,
        ?int $providerId = null
    ): PHPMailer {
        $providerId ??= $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $sender = $this->senderWith($this->capturingTransport($captured));
        $sender->send('vous@exemple.be', $providerId, $lane);

        $this->assertInstanceOf(PHPMailer::class, $captured, 'Nothing reached the transport.');

        return $captured;
    }

    private function senderWith(
        MailTransportInterface $transport,
        string $fromAddress = 'info@unite.be',
        ?SendCounterRepository $counters = null
    ): MailProbeSender {
        $connections = new ProviderConnections($this->secrets);

        return new MailProbeSender(
            new MailService(
                'local',
                $fromAddress,
                'Unité Exemple',
                'EX',
                new DkimManager($this->storagePath . '/keys'),
                's2026',
                transport: $transport
            ),
            new MailProviderDirectory($this->providers, $connections, $this->settings()),
            new TransportConfigurator($connections),
            // The SAME transport underneath, which is the point of the
            // dependency: the probe pins a relay and bypasses the chain,
            // so if it reached for a transport of its own this test would
            // dial a real SMTP server — and so would a site configured to
            // capture its mail rather than send it.
            $transport,
            $this->probes,
            $this->twig(),
            new JournalService(new JournalRepository($this->pdo)),
            $counters
        );
    }

    /**
     * A transport that keeps what it was handed instead of putting it on
     * the wire — the same seam `modules/test_tools` uses, spelled here so
     * that a core test names no module (ARCHITECTURE.md §7.5).
     */
    private function capturingTransport(?PHPMailer &$captured): MailTransportInterface
    {
        return new class ($captured) implements MailTransportInterface {
            public function __construct(private ?PHPMailer &$captured)
            {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                // preSend() assembles the headers and the multipart body
                // without opening a socket, which is what makes the
                // assertions above about a REAL message rather than about
                // the arguments it was built from.
                $mail->preSend();
                $this->captured = $mail;
            }
        };
    }

    private function refusingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                throw new \RuntimeException('SMTP connect() failed');
            }
        };
    }

    private function addRelay(string $name, string $host): int
    {
        $id = $this->providers->create($name, null, 50, 10);

        // The host and port live in secrets.enc in production, and the
        // directory reads them through ProviderConnections — which this
        // test feeds from the same place the real one does, rather than
        // writing a host into a column that does not hold one.
        $prefix = ProviderConnections::prefixFor($id);
        $this->secrets[$prefix . '_host'] = $host;
        $this->secrets[$prefix . '_port'] = '587';
        $this->secrets[$prefix . '_username'] = 'unite';
        $this->secrets[$prefix . '_password'] = 'secret';

        return $id;
    }

    /** @var array<string, string> */
    private array $secrets = [];

    private function settings(): \Core\Config\SettingService
    {
        return new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
    }

    private function twig(): Environment
    {
        return TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', false);
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? self::removeDirectory($entry) : unlink($entry);
        }

        rmdir($directory);
    }
}
