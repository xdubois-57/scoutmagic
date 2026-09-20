<?php

declare(strict_types=1);

namespace Tests\Core\Support\Collector;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Mail\DnsCheckMemory;
use Core\Mail\Feedback\ReturnProbeRepository;
use Core\Mail\MailIdentity;
use Core\Mail\MailPurpose;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\ProviderHealth;
use Core\Mail\Transport\ProviderHealthRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Support\Collector\OutboundMailCollector;
use Modules\InboundMail\Api\InboundMailInterface;
use Core\Security\EncryptionService;
use Core\Support\SupportCollectorContext;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `outbound-mail.txt` — what a support archive may say about how this
 * installation's mail leaves, and above all what it may never say
 * (ARCHITECTURE.md §8.106, §8.48).
 *
 * The archive goes to a third party. So the assertion that matters most
 * here is the negative one: no credential, no recipient. It is asserted
 * against a fixture whose password and username are distinctive strings,
 * so a future edit that starts printing a provider's connection fails
 * this test rather than shipping quietly.
 *
 * @group database
 */
class OutboundMailCollectorTest extends TestCase
{
    private \PDO $pdo;
    private string $projectRoot;
    private string $storagePath;
    private Connection $connection;
    private SettingService $settings;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private SendCounterRepository $counters;
    private ProviderHealthRepository $health;
    private DeferredMailRepository $deferred;
    private ReturnProbeRepository $returnProbes;
    private \Core\Mail\Probe\MailProbeRepository $mailProbes;
    private \Core\Mail\Feedback\Bounce\BounceStateRepository $bounceStates;
    private \Core\Mail\Feedback\Dmarc\DmarcReportRepository $dmarcReports;
    private \Core\Mail\Feedback\Seed\SeedCopyRepository $seedCopies;
    private \Core\Mail\Transport\DomainPreferences $domainPreferences;
    private ?InboundMailInterface $inboundMail = null;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->counters = new SendCounterRepository($this->pdo);
        $this->health = new ProviderHealthRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->deferred = new DeferredMailRepository($this->pdo, $encryption);
        $this->returnProbes = new ReturnProbeRepository($this->pdo, $encryption);
        $this->mailProbes = new \Core\Mail\Probe\MailProbeRepository($this->pdo, $encryption);
        $this->bounceStates = new \Core\Mail\Feedback\Bounce\BounceStateRepository($this->pdo, $encryption);
        $this->dmarcReports = new \Core\Mail\Feedback\Dmarc\DmarcReportRepository($this->pdo);
        $this->seedCopies = new \Core\Mail\Feedback\Seed\SeedCopyRepository($this->pdo, $encryption);
        $this->settings->register(
            \Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC,
            '0',
            'boolean',
            'Routage automatique',
            '',
            null,
            null,
            null,
            false,
            59
        );
        $this->settings->register(
            \Core\Mail\Transport\DomainPreferences::SETTING_KEY,
            '',
            'text',
            'Routage par domaine',
            '',
            null,
            null,
            null,
            false,
            60
        );
        $this->domainPreferences = new \Core\Mail\Transport\DomainPreferences($this->settings);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-outbound-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/temp', 0700, true);

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    public function testItReportsEveryProviderTheChainsAndTheCounters(): void
    {
        $relay = $this->addRelay('Brevo', 'smtp-relay.brevo.test', dailyQuota: 300);
        $this->chains->append(MailLane::Bulk, $relay, true);
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, false);
        $this->counters->increment($relay, MailLane::Bulk);

        $report = $this->collect();

        $this->assertStringContainsString('Brevo', $report);
        $this->assertStringContainsString('smtp-relay.brevo.test', $report, 'A host is a server, not a person.');
        $this->assertStringContainsString('300', $report, 'The declared quota.');
        $this->assertStringContainsString(MailProvider::LOCAL_NAME, $report);
        $this->assertStringContainsString('[actif]', $report);
        $this->assertStringContainsString('[désactivé]', $report);
        $this->assertStringContainsString('bulk', $report, 'The counter row names its lane.');
    }

    /**
     * The one assertion this file exists for.
     */
    public function testItNeverCarriesACredential(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test');
        $this->chains->append(MailLane::Authentication, $relay, true);

        $report = $this->collect();

        $this->assertStringNotContainsString('MOT-DE-PASSE-SECRET', $report);
        $this->assertStringNotContainsString('identifiant-secret@relais.test', $report);
        $this->assertStringContainsString('identifiant : configuré', $report, 'Whether one is set is diagnostic.');
    }

    public function testAnInstallationWithNothingConfiguredStillProducesTheFile(): void
    {
        $report = $this->collect();

        $this->assertStringContainsString(MailProvider::LOCAL_NAME, $report);
        $this->assertStringContainsString('(vide — aucun message ne peut partir sur cette voie)', $report);
        $this->assertStringContainsString('(aucun envoi enregistré sur la période)', $report);
    }

    /**
     * A lane row pointing at a provider that no longer exists must read as
     * such rather than crash the collector: the archive's contract is that
     * it is always produced (§8.48).
     */
    public function testALaneEntryWhoseProviderIsGoneIsNamedRatherThanFatal(): void
    {
        $this->chains->append(MailLane::Transactional, 4242, true);

        $report = $this->collect();

        $this->assertStringContainsString('fournisseur #4242 (introuvable)', $report);
    }

    // ── ce que le coupe-circuit, la réserve et la file racontent ──────

    /**
     * The column worth having is `ouvertures`: a provider closed right
     * now that has opened eleven times this month is a relay on its way
     * out, and no screenshot taken between two outages shows that.
     */
    public function testItReportsWhatTheBreakerHasBeenDoing(): void
    {
        $relay = $this->addRelay('Relais fatigué', 'smtp.fatigue.test');
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $this->health->recordFailure($relay, 'SMTP connect() failed.');
        }

        $report = $this->collect();

        $this->assertStringContainsString('── Coupe-circuit', $report);
        $this->assertMatchesRegularExpression('/Relais fatigué\s+ouvert\s+3\s+1/u', $report);
        $this->assertStringContainsString('SMTP connect() failed.', $report);
    }

    /**
     * The reserve is the one figure on this page that looks arbitrary, so
     * it travels with the sentence saying where it came from — a sentence
     * the reader can check against the counters printed further down.
     */
    public function testTheReserveTravelsWithItsProvenance(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test', dailyQuota: 1000);
        $this->chains->append(MailLane::Authentication, $relay, true);
        $this->chains->append(MailLane::Bulk, $relay, true);
        for ($i = 0; $i < 54; $i++) {
            $this->counters->increment($relay, MailLane::Transactional, date('Y-m-d'));
        }

        $report = $this->collect();

        $this->assertStringContainsString('── Réserve pour les liens de connexion', $report);
        $this->assertStringContainsString('votre pointe hors publipostage', $report);
        $this->assertStringContainsString('(54)', $report);
    }

    /** « Un report n'est pas un silence » — so the depth is in the archive. */
    public function testItReportsTheQueueDepthByLane(): void
    {
        $this->queueOne(MailLane::Transactional);
        $this->queueOne(MailLane::Bulk);
        $abandoned = $this->queueOne(MailLane::Bulk);
        $this->deferred->abandon($abandoned, 3, 'délai de vie dépassé');

        $report = $this->collect();

        $this->assertStringContainsString('── Messages différés', $report);
        $this->assertMatchesRegularExpression('/Transactionnel\S*\s+1 en attente/u', $report);
        $this->assertStringContainsString('abandonnés     : 1', $report);
        $this->assertStringContainsString('de 6 à 24 h', $report, 'The bands are disjoint and say so.');
    }

    /**
     * **The assertion this whole file exists for, extended to the three
     * new sections.** A deferred message holds a subject and a recipient;
     * the archive goes to a third party, and neither may leave with it.
     */
    public function testTheQueueSectionCarriesNoMessage(): void
    {
        $this->queueOne(MailLane::Transactional);

        $report = $this->collect();

        $this->assertStringNotContainsString('parent@exemple.test', $report);
        $this->assertStringNotContainsString('Reçu de paiement', $report);
    }

    /**
     * A table that is not there yet — a support package collected
     * mid-migration — costs the section, not the file. The chains and the
     * counters are still the thing somebody asked for.
     */
    public function testAMissingTableCostsOnlyItsOwnSection(): void
    {
        $this->pdo->exec('DROP TABLE mail_deferred_messages');
        $this->pdo->exec('DROP TABLE mail_provider_health');

        $report = $this->collect();

        $this->assertStringNotContainsString('── Messages différés', $report);
        $this->assertStringNotContainsString('── Coupe-circuit', $report);
        $this->assertStringContainsString('── Chaînes, dans leur ordre', $report);
    }

    // ── the domain and the returns (roadmap IT-03) ────────────────────

    public function testItReportsTheDomainsTheAuthenticationRestsOn(): void
    {
        $this->registerIdentity('info@unite.be', '');

        $report = $this->collect();

        $this->assertStringContainsString('── Authentification du domaine', $report);
        $this->assertStringContainsString('unite.be', $report, 'A domain is a server, not a person.');
        $this->assertStringContainsString('vérification DNS : jamais lancée', $report);
    }

    public function testTheRememberedDnsVerdictsTravelWithTheirDate(): void
    {
        $this->registerIdentity('info@unite.be', '');
        DnsCheckMemory::remember(
            $this->settings,
            'unite.be',
            'unite.be',
            's2026',
            '',
            [
                DnsCheckMemory::SPF => ['exists' => true, 'expected' => 'v=spf1 a mx ~all'],
                DnsCheckMemory::DKIM => ['exists' => false, 'expected' => 'v=DKIM1; k=rsa; p=AAAA'],
                DnsCheckMemory::DMARC => ['not_requested' => true],
            ],
            new \DateTimeImmutable('2026-09-01 08:00:00')
        );

        $report = $this->collect();

        $this->assertStringContainsString('vérification DNS du 2026-09-01 08:00', $report);
        $this->assertStringContainsString('SPF   : publié', $report);
        $this->assertStringContainsString('DKIM  : absent', $report);
        // Null is « nobody asked for reports », not « the record is
        // missing » — and the two would send a reader to different places.
        $this->assertStringContainsString('DMARC : non vérifié', $report);
        $this->assertStringNotContainsString('PÉRIMÉE', $report);
    }

    /**
     * A reading taken on the previous domain still lists three verdicts,
     * and they answer a question nobody is asking any more. The archive
     * does print both domains, fifteen lines apart — but a support
     * package is read by somebody looking for what is wrong, not by
     * somebody cross-checking two lines, so it says so in as many words.
     */
    public function testAReadingTakenBeforeTheAddressesMovedIsMarkedAsSuch(): void
    {
        $this->registerIdentity('info@nouveau.be', '');
        DnsCheckMemory::remember(
            $this->settings,
            'ancien.be',
            'ancien.be',
            's2026',
            '',
            [DnsCheckMemory::SPF => ['exists' => true, 'expected' => 'v=spf1 a mx ~all']],
            new \DateTimeImmutable('2026-09-01 08:00:00')
        );

        $report = $this->collect();

        $this->assertStringContainsString('PÉRIMÉE : les adresses ont changé depuis', $report);
    }

    /**
     * The archive answers « par quels chemins cette unité a-t-elle
     * testé, et qu'est-ce que ça a donné » — which needs the road and
     * the verdict, and not who was written to. The screen shows the
     * destination because the person reading it typed it a minute ago;
     * this file goes to a third party and is kept far longer.
     */
    public function testTheProbesAreReportedByRoadAndVerdictAndNeverByDestination(): void
    {
        $this->registerIdentity('info@unite.be', '');
        $this->mailProbes->record(
            'SM-ABC234',
            'parent@exemple.be',
            7,
            'Brevo',
            MailLane::Bulk,
            new \DateTimeImmutable('2026-09-01 08:00:00')
        );
        $id = $this->mailProbes->record(
            'SM-XYZ789',
            'parent@exemple.be',
            8,
            'OVH',
            MailLane::Bulk,
            new \DateTimeImmutable('2026-09-01 09:00:00')
        );
        $this->mailProbes->recordVerdict(
            $id,
            \Core\Mail\Probe\MailProbeVerdict::Inbox,
            new \DateTimeImmutable('2026-09-01 10:00:00')
        );

        $report = $this->collect();

        $this->assertStringContainsString('── Sondes de délivrabilité', $report);
        $this->assertStringContainsString('Brevo', $report);
        $this->assertStringContainsString('OVH', $report);
        $this->assertStringContainsString('Réception', $report);
        // Sent, and nobody has said where it landed — a third state, not
        // a missing value.
        $this->assertStringContainsString('en attente', $report);
        $this->assertStringNotContainsString('parent@exemple.be', $report);
        // Nor the code, which is a handle on a row and says nothing an
        // archive reader can use.
        $this->assertStringNotContainsString('SM-ABC234', $report);
    }

    /**
     * **The domain and the count, never the address** (roadmap IT-05).
     * This file goes to a third party and is kept far longer than the
     * screen it mirrors, while « onze adresses suspendues chez le même
     * fournisseur » is the shape somebody helping needs — and says
     * nothing about any one family.
     */
    public function testTheArchiveCountsSuspendedAddressesByProviderAndNamesNone(): void
    {
        $now = new \DateTimeImmutable('2026-09-19 10:00:00');
        foreach (['un@gmail.com', 'deux@gmail.com', 'trois@exemple.be'] as $email) {
            // Vouched for: these fixtures stand in for addresses the unit
            // writes to, and `recordSend()` stamps a receipt only for one
            // the site holds on file.
            $this->bounceStates->recordSend($email, $now->modify('-1 hour'), true);
            $state = $this->bounceStates->record(
                $email,
                \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress,
                \Core\Mail\Feedback\Bounce\BounceSeverity::Permanent,
                '5.1.1',
                $now
            );
            self::assertNotNull($state);
            $this->bounceStates->block($state->id, $now);
        }

        $report = $this->collect();

        $this->assertStringContainsString('── Adresses suspendues sur rebond', $report);
        $this->assertStringContainsString('gmail.com', $report);
        $this->assertStringNotContainsString('un@gmail.com', $report);
        $this->assertStringNotContainsString('trois@exemple.be', $report);
    }

    public function testTheArchiveSaysSoWhenNothingIsSuspended(): void
    {
        $this->assertStringContainsString('aucune adresse suspendue', $this->collect());
    }

    /**
     * **Counters, never a source address** (roadmap IT-06).
     *
     * The reasoning is the bounce section's exactly, and it is worth
     * repeating because the instinct pulls the other way: a source list
     * is precisely what somebody diagnosing a DMARC problem wants, and
     * precisely what this file must not carry. The archive goes to a
     * third party and outlives the screen it mirrors, whereas « quatre
     * sources, dont 12 % non authentifiées » keeps every diagnostic shape
     * while pointing at nobody. The addresses stay on the screen, which
     * `superadmin` opens and nobody else keeps.
     *
     * The fixture's addresses are distinctive on purpose: an edit that
     * starts printing them fails here rather than shipping quietly.
     */
    public function testTheArchiveCountsDmarcTrafficAndNamesNoSource(): void
    {
        $now = new \DateTimeImmutable();
        $this->recordDmarcReport(
            'google.com',
            'rapport-1',
            $now,
            [
                ['198.51.100.7', 120, true],
                ['203.0.113.42', 30, false],
            ]
        );
        $this->recordDmarcReport('Yahoo', 'rapport-2', $now, [['198.51.100.7', 50, true]]);

        $report = $this->collect();

        $this->assertStringContainsString('── Rapports DMARC, 30 derniers jours', $report);
        $this->assertStringContainsString('rapports           2', $report);
        $this->assertStringContainsString('fournisseurs       2', $report);
        $this->assertStringContainsString('sources distinctes 2', $report);
        $this->assertStringContainsString('messages           200', $report);
        // 170 of 200 — the percentage is the figure somebody reads first,
        // so it is computed here rather than left to the reader.
        $this->assertStringContainsString('authentifiés       170 (85%)', $report);
        $this->assertStringContainsString('quarantine', $report, 'The policy the reporters saw.');

        $this->assertStringNotContainsString('198.51.100.7', $report);
        $this->assertStringNotContainsString('203.0.113.42', $report);
    }

    /**
     * **The seed section counts and never names a box.**
     *
     * The fixture's addresses are distinctive on purpose, like the DMARC
     * ones above: a seed box is a mailbox of the unit's, so an edit that
     * starts printing one fails here rather than shipping an archive that
     * carries mailbox addresses to a third party (SECURITY.md §11).
     */
    public function testTheArchiveCountsTheSeedResultsByProviderAndNamesNoBox(): void
    {
        $sent = new \DateTimeImmutable('-1 day');
        foreach (['un', 'deux', 'trois'] as $run) {
            $this->seedCopies->claim($run, 'temoin-tres-distinctif@gmail.com', $sent);
            $this->seedCopies->recordLanding($run, 'temoin-tres-distinctif@gmail.com', 'Junk', $sent);
        }

        $report = $this->collect();

        $this->assertStringContainsString('── Boîtes témoins, 30 derniers jours', $report);
        $this->assertStringContainsString('routage automatique : non', $report);
        $this->assertStringContainsString('gmail.com', $report, 'An aggregated provider is a company.');
        $this->assertStringNotContainsString('temoin-tres-distinctif', $report);
    }

    /**
     * A domain whose mail was routed months ago and is no longer measured
     * still steers every mailing it names, so it stays in the archive: a
     * reader who cannot see it is reading the figures of a configuration
     * that is not the one in force.
     */
    public function testADecidedDomainAppearsEvenWithNoRecentMeasurement(): void
    {
        $this->domainPreferences->prefer('orange.fr', 3);

        $report = $this->collect();

        $this->assertStringContainsString('orange.fr', $report);
        $this->assertStringContainsString('routé', $report);
    }

    public function testTheArchiveSaysSoWhenNothingHasBeenMeasured(): void
    {
        $report = $this->collect();

        $this->assertStringContainsString('── Boîtes témoins, 30 derniers jours', $report);
        $this->assertStringContainsString('aucun envoi mesuré', $report);
    }

    public function testTheArchiveSaysSoWhenNoDmarcReportHasArrived(): void
    {
        $report = $this->collect();

        $this->assertStringContainsString('── Rapports DMARC, 30 derniers jours', $report);
        $this->assertStringContainsString('aucun rapport reçu', $report);
    }

    /**
     * An installation whose composition root built no probe repository:
     * the archive simply has no probe section, rather than a section
     * announcing itself and then saying nothing, and certainly rather
     * than a support package that cannot be produced at all on the day
     * somebody needs it.
     */
    public function testAnInstallationWithoutTheProbeGetsAnArchiveWithoutThatSection(): void
    {
        $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $this->mailProbes->record(
            'SM-ABC234',
            'vous@exemple.be',
            7,
            'Brevo',
            MailLane::Bulk,
            new \DateTimeImmutable('2026-09-01 08:00:00')
        );

        $report = $this->collect(withProbes: false);

        $this->assertStringNotContainsString('Sondes de délivrabilité', $report);
        // The rest of the archive is unaffected — the probe is one
        // section among several, not a precondition of the others.
        $this->assertStringContainsString('Brevo', $report);
    }

    /**
     * And a probe table that cannot be read is the same answer as no
     * probe at all. A support archive is asked for precisely when
     * something is broken; a collector that threw on a damaged table
     * would withhold the twelve other sections that still read fine.
     */
    public function testAProbeTableThatCannotBeReadCostsItsSectionAndNothingElse(): void
    {
        $this->addRelay('Brevo', 'smtp-relay.brevo.com');
        $this->pdo->exec('DROP TABLE mail_probes');

        $report = $this->collect();

        $this->assertStringNotContainsString('Sondes de délivrabilité', $report);
        $this->assertStringContainsString('Brevo', $report);
    }

    public function testAnInstallationThatHasNeverProbedSaysSo(): void
    {
        $this->registerIdentity('info@unite.be', '');

        $this->assertStringContainsString('aucune sonde envoyée', $this->collect());
    }

    public function testTheReturnVerificationIsReportedByRoleAndNeverByAddress(): void
    {
        $this->registerIdentity('info@unite.be', 'secretariat@unite.be');
        $this->inboundMail = $this->collectingGateway();
        $this->returnProbes->issue(
            'info@unite.be',
            'RET-ABCDEFGHJK',
            new \DateTimeImmutable('2026-09-01 08:00:00'),
            new \DateTimeImmutable('2026-09-01 14:00:00')
        );

        $report = $this->collect();

        $this->assertStringContainsString('── Vérification des retours', $report);
        $this->assertStringContainsString('Expédition (rebonds)', $report);
        $this->assertStringContainsString('Réponses', $report);
        $this->assertStringContainsString('envoyé le 2026-09-01 08:00', $report);
        // The assertion this section exists for: the archive goes to a
        // third party, and a domain is a server where an address is a
        // person.
        $this->assertStringNotContainsString('info@unite.be', $report);
        $this->assertStringNotContainsString('secretariat@unite.be', $report);
    }

    public function testAnAddressNobodyHasCheckedIsReportedAsSuchRatherThanOmitted(): void
    {
        $this->registerIdentity('info@unite.be', '');
        $this->inboundMail = $this->collectingGateway();

        $report = $this->collect();

        $this->assertStringContainsString('Jamais vérifié', $report);
    }

    /**
     * « Jamais vérifié » and « vérification impossible » send a reader to
     * opposite places — a button nobody pressed against a module that is
     * off — so the archive has to tell them apart exactly as the screen
     * does.
     */
    public function testWithoutTheInboundModuleTheArchiveSaysImpossibleAndNotNeverChecked(): void
    {
        $this->registerIdentity('info@unite.be', '');
        $this->inboundMail = null;

        $report = $this->collect();

        $this->assertStringContainsString('Vérification impossible', $report);
        $this->assertStringNotContainsString('Jamais vérifié', $report);
    }

    public function testAnEnabledBoxOpenToNobodyIsReportedImpossibleToo(): void
    {
        $this->registerIdentity('info@unite.be', '');
        $gateway = $this->createStub(InboundMailInterface::class);
        $gateway->method('isCollecting')->willReturn(true);
        $gateway->method('listMailboxSummaries')->willReturn([
            7 => ['name' => 'Boîte de l’unité', 'state' => 'ok', 'is_enabled' => true],
        ]);
        $gateway->method('probeAddressesFor')->willReturn([]);
        $this->inboundMail = $gateway;

        $this->assertStringContainsString('Vérification impossible', $this->collect());
    }

    private function collectingGateway(): InboundMailInterface
    {
        $gateway = $this->createStub(InboundMailInterface::class);
        $gateway->method('isCollecting')->willReturn(true);
        $gateway->method('listMailboxSummaries')->willReturn([
            7 => ['name' => 'Boîte de l’unité', 'state' => 'ok', 'is_enabled' => true],
        ]);
        // The scope-aware answer, which is what decides whether the round
        // trip can work at all — an enabled box open to nobody offers this
        // consumer nothing.
        $gateway->method('probeAddressesFor')->willReturn(['boite@unite.be']);

        return $gateway;
    }

    private function registerIdentity(string $fromAddress, string $replyAddress): void
    {
        $this->settings->register(
            MailIdentity::SETTING_FROM_ADDRESS,
            '',
            'email',
            'Email d\'expédition',
            '',
            null,
            null,
            null,
            true,
            40
        );
        $this->settings->register(
            MailIdentity::SETTING_REPLY_ADDRESS,
            '',
            'email',
            'Adresse de réponse',
            '',
            null,
            null,
            null,
            true,
            55
        );
        $this->settings->register(
            DnsCheckMemory::SETTING_KEY,
            '',
            'text',
            'Dernière vérification DNS',
            '',
            null,
            null,
            null,
            false,
            56
        );
        // Registered like the boot does it, default included: the
        // selector is half of what a DNS reading is about, so a fixture
        // without one describes an installation that cannot exist.
        $this->settings->register(
            'dkim_selector',
            's2026',
            'text',
            'Sélecteur DKIM',
            '',
            null,
            '^[a-z0-9]+$',
            null,
            true,
            60
        );
        $this->settings->set(MailIdentity::SETTING_FROM_ADDRESS, $fromAddress);
        $this->settings->set(MailIdentity::SETTING_REPLY_ADDRESS, $replyAddress);
    }

    private function queueOne(MailLane $lane): int
    {
        return $this->deferred->add(
            $lane,
            MailPurpose::Ordinary,
            [
                'to' => 'parent@exemple.test',
                'subject' => 'Reçu de paiement',
                'bodyHtml' => '<p>Bonjour</p>',
                'bodyText' => 'Bonjour',
                'replyTo' => null,
                'fromAddressOverride' => null,
                'fromNameOverride' => null,
                'extraHeaders' => [],
                'attachments' => [],
            ],
            'quota épuisé',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600)
        );
    }

    private function addRelay(string $name, string $host, ?int $dailyQuota = null): int
    {
        $id = $this->providers->create($name, $dailyQuota, 50, 10);
        $prefix = ProviderConnections::prefixFor($id);
        $this->secrets[$prefix . '_host'] = $host;
        $this->secrets[$prefix . '_port'] = '587';
        $this->secrets[$prefix . '_user'] = 'identifiant-secret@relais.test';
        $this->secrets[$prefix . '_password'] = 'MOT-DE-PASSE-SECRET';

        return $id;
    }

    /**
     * @param list<array{0: string, 1: int, 2: bool}> $sources address, messages, authenticated
     */
    private function recordDmarcReport(
        string $organisation,
        string $reportId,
        \DateTimeImmutable $now,
        array $sources
    ): void {
        $records = [];
        foreach ($sources as [$ip, $count, $passed]) {
            $records[] = new \Core\Mail\Feedback\Dmarc\DmarcRecord(
                $ip,
                $count,
                'none',
                $passed,
                false,
                'exemple.be'
            );
        }

        $written = $this->dmarcReports->record(
            new \Core\Mail\Feedback\Dmarc\DmarcReport(
                $organisation,
                $reportId,
                'exemple.be',
                $now->modify('-2 days'),
                $now->modify('-1 day'),
                'quarantine',
                $records
            ),
            $now
        );
        self::assertTrue($written, 'The fixture must actually reach the tables it is read from.');
    }

    private function collect(bool $withProbes = true): string
    {
        $connections = new ProviderConnections($this->secrets);
        $collector = new OutboundMailCollector(
            new MailProviderDirectory($this->providers, $connections, $this->settings),
            $this->chains,
            $this->health,
            new MailReserve($this->counters, $this->chains),
            $this->deferred,
            new DeferredMailQueue($this->deferred, $this->settings),
            $this->settings,
            $this->returnProbes,
            $this->inboundMail,
            $withProbes ? $this->mailProbes : null,
            $this->bounceStates,
            $this->dmarcReports,
            new \Core\Mail\Feedback\Seed\DomainRouting(
                $this->seedCopies,
                $this->settings,
                $this->domainPreferences,
                $this->chains,
                new MailProviderDirectory($this->providers, $connections, $this->settings)
            ),
            $this->domainPreferences
        );

        $archivePath = $this->storagePath . '/temp/outbound-' . bin2hex(random_bytes(6)) . '.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        $collector->collect(new SupportCollectorContext(
            $archive,
            $this->connection,
            $this->settings,
            $this->projectRoot,
            $this->storagePath
        ));
        $archive->close();

        $read = new \ZipArchive();
        $this->assertTrue($read->open($archivePath) === true);
        $content = $read->getFromName('outbound-mail.txt');
        $read->close();

        $this->assertIsString($content, 'The collector must always produce its file.');

        return $content;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
