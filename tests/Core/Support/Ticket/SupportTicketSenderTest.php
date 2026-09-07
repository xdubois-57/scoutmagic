<?php

declare(strict_types=1);

namespace Tests\Core\Support\Ticket;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsTransportInterface;
use Core\Statistics\StatisticsTransportResponse;
use Core\Support\Ticket\SupportTicketSender;
use Core\Support\Ticket\TicketCategories;
use Core\Support\Ticket\TicketIdentityService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What leaves the installation when somebody opens a ticket, and what is
 * kept when they do (roadmap IT-25).
 *
 * The properties worth pinning are all about restraint: the secret travels
 * in a header and never in the body, the description never reaches the
 * journal, and nothing is recorded as « Envoyé » unless the receiver said
 * so.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SupportTicketSenderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SecretManager $secretManager;
    private string $projectRoot;

    private const SECRET_LENGTH = 64;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-ticket-sender-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        foreach ([
            InstallationIdentityService::INSTALLATION_ID_SETTING,
            SupportTicketSender::LAST_REFERENCE_SETTING,
            SupportTicketSender::LAST_SENT_AT_SETTING,
            SupportTicketSender::CATEGORIES_SETTING,
            SupportTicketSender::RECENT_SETTING,
        ] as $key) {
            $this->settings->register($key, '', 'text', 'L', 'D', null, null, null, false);
        }
        $this->settings->register('statistics_enabled', '0', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');
    }

    protected function tearDown(): void
    {
        foreach (['/storage/config/secrets.enc', '/storage/keys/master.key'] as $file) {
            @unlink($this->projectRoot . $file);
        }
        foreach (['/storage/config', '/storage/keys', '/storage', ''] as $dir) {
            @rmdir($this->projectRoot . $dir);
        }
    }

    /**
     * The closed list of what a ticket body carries.
     *
     * `statistics` joined it deliberately: a report sent as its own call
     * arrived as its own event, and the receiver could not tie it to the
     * ticket it explained. Anything else appearing here is a change to
     * what leaves a unit's server, which is exactly the kind of change
     * that should not pass unnoticed — hence the exact-match assertion.
     *
     * The sender under test is built WITHOUT a payload builder, so the
     * key is present and null; `Tests\Core\Support\Ticket\
     * TicketCarriesStatisticsTest` covers the report itself.
     */
    public function testWhatLeavesIsTheTicketAndTheIdentityAndNothingElse(): void
    {
        $transport = $this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']);

        $result = $this->sender($transport)->send('desk_import', 'Mon import ne passe plus.', 'chef@unite.be');

        $this->assertTrue($result->sent);
        $this->assertSame('SUP-7KQ4F2', $result->reference);

        $call = $transport->calls[0];
        $this->assertSame('https://www.scoutmagic.be/api/support/tickets', $call['url']);

        $body = json_decode($call['body'], true);
        $this->assertSame(
            [
                'installation_id',
                'category',
                'description',
                'contact_email',
                'site_version',
                'php_version',
                'archive_consent',
                'statistics',
            ],
            array_keys($body)
        );
        $this->assertSame('desk_import', $body['category']);
        $this->assertSame('1.0.33', $body['site_version']);
        // The scope of the archive box's sentence on this version: what
        // the receiver compares against before serving a triage extract
        // (ARCHITECTURE.md §8.49sexies).
        $this->assertSame(SupportTicketSender::ARCHIVE_CONSENT_SCOPE, $body['archive_consent']);
        $this->assertSame('triage-extract-v1', $body['archive_consent']);

        // The secret authenticates the call and never travels in the body.
        $this->assertNotSame('', $call['token']);
        $this->assertStringNotContainsString($call['token'], $call['body']);
    }

    public function testTheReferenceAndTheDateAreKeptForTheOnlyLocalStatusThereIs(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertSame('SUP-7KQ4F2', (string) $this->settings->get(SupportTicketSender::LAST_REFERENCE_SETTING));
        $this->assertNotSame('', (string) $this->settings->get(SupportTicketSender::LAST_SENT_AT_SETTING));

        $sender = $this->sender($this->transport(200, []));
        $last = $sender->lastSent();
        $this->assertNotNull($last);
        $this->assertSame('SUP-7KQ4F2', $last['reference']);
    }

    // ── The last five references ────────────────────────────────────────

    /**
     * A reference exists to be copied into a GitHub issue, and nobody
     * reports within the minute: they send the evidence, look at the
     * problem some more, and write the issue that evening. Keeping only
     * the latest made the reference of two days ago unrecoverable —
     * exactly the one somebody comes back to the page for.
     */
    public function testTheLastFewReferencesAreKeptNewestFirst(): void
    {
        foreach (['SUP-AAAAAA', 'SUP-BBBBBB', 'SUP-CCCCCC'] as $reference) {
            $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => $reference]))
                ->send('other', 'Bonjour', 'chef@unite.be');
        }

        $recent = $this->sender($this->transport(200, []))->recentlySent();

        $this->assertSame(
            ['SUP-CCCCCC', 'SUP-BBBBBB', 'SUP-AAAAAA'],
            array_column($recent, 'reference')
        );
        $this->assertNotSame('', $recent[0]['sent_at']);
    }

    public function testTheListNeverGrowsPastWhatThePageShows(): void
    {
        for ($i = 0; $i < SupportTicketSender::RECENT_KEPT + 3; $i++) {
            $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-' . $i]))
                ->send('other', 'Bonjour', 'chef@unite.be');
        }

        $this->assertCount(SupportTicketSender::RECENT_KEPT, $this->sender($this->transport(200, []))->recentlySent());
    }

    /**
     * The category is stored as its VALUE and turned into a label only at
     * display time: a label is the receiver's wording of the moment, and a
     * frozen copy would print last year's vocabulary beside a reference
     * for as long as the row survives.
     */
    public function testTheCategoryIsShownAsALabelAndStoredAsAValue(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('other', 'Bonjour', 'chef@unite.be');

        $stored = (string) $this->settings->get(SupportTicketSender::RECENT_SETTING);
        $this->assertStringContainsString('"category":"other"', $stored);

        $recent = $this->sender($this->transport(200, []))->recentlySent();
        $this->assertNotNull($recent[0]['category_label']);
        $this->assertNotSame('other', $recent[0]['category_label']);
    }

    /**
     * An installation that has been sending for a year must not read as
     * one that never sent anything, just because this list is newer than
     * its own history.
     */
    public function testAnInstallationOlderThanTheListStillShowsItsLastReference(): void
    {
        $this->settings->setInternal(SupportTicketSender::LAST_REFERENCE_SETTING, 'SUP-OLD123');
        $this->settings->setInternal(SupportTicketSender::LAST_SENT_AT_SETTING, '2026-01-02 03:04:05');

        $recent = $this->sender($this->transport(200, []))->recentlySent();

        $this->assertSame(['SUP-OLD123'], array_column($recent, 'reference'));
        $this->assertSame('2026-01-02 03:04:05', $recent[0]['sent_at']);
    }

    public function testAnInstallationThatNeverSentAnythingShowsNothing(): void
    {
        $this->assertSame([], $this->sender($this->transport(200, []))->recentlySent());
    }

    /**
     * Bookkeeping is never allowed to make a ticket that WAS accepted read
     * as one that was not — the same posture every other write on this
     * path takes.
     */
    public function testAStoredListThatIsNotOneIsNotFatal(): void
    {
        $this->settings->setInternal(SupportTicketSender::RECENT_SETTING, 'pas du json');

        $result = $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertTrue($result->sent);
        $this->assertSame(['SUP-7KQ4F2'], array_column($this->sender($this->transport(200, []))->recentlySent(), 'reference'));
    }

    /**
     * Valid JSON of the wrong SHAPE is the case a plain `is_array()` walks
     * straight past: a JSON object decodes to an array, the loop finds no
     * entry in it, and an installation with a year of history reads as one
     * that never sent anything.
     */
    public function testAStoredObjectIsTreatedAsNoHistoryAtAll(): void
    {
        $this->settings->setInternal(SupportTicketSender::RECENT_SETTING, '{"reference":"SUP-BADSHP"}');
        $this->settings->setInternal(SupportTicketSender::LAST_REFERENCE_SETTING, 'SUP-OLD123');
        $this->settings->setInternal(SupportTicketSender::LAST_SENT_AT_SETTING, '2026-01-02 03:04:05');

        $recent = $this->sender($this->transport(200, []))->recentlySent();

        $this->assertSame(['SUP-OLD123'], array_column($recent, 'reference'));
    }

    /** And the next accepted send replaces it rather than prepending onto it. */
    public function testASendAfterAStoredObjectWritesAListAgain(): void
    {
        $this->settings->setInternal(SupportTicketSender::RECENT_SETTING, '{"reference":"SUP-BADSHP"}');

        $result = $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertTrue($result->sent);
        $this->assertSame(['SUP-7KQ4F2'], array_column($this->sender($this->transport(200, []))->recentlySent(), 'reference'));
    }

    /**
     * @return array<string, array{int, array<string, mixed>|string, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'the receiver never answered' => [503, 'oops', SupportTicketSender::FAILURE_UNREACHABLE],
            'it answered something unreadable' => [200, 'pas du json', SupportTicketSender::FAILURE_MALFORMED_ANSWER],
            'it refused' => [200, ['status' => 'refused', 'reason' => 'unknown_category'], SupportTicketSender::FAILURE_REFUSED],
            'it accepted without saying which ticket' => [200, ['status' => 'accepted'], SupportTicketSender::FAILURE_MALFORMED_ANSWER],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
    public function testNothingIsRecordedAsSentUnlessTheReceiverSaidSo(
        int $status,
        array|string $body,
        string $expectedReason
    ): void {
        $transport = is_string($body)
            ? new RecordingTicketTransport(StatisticsTransportResponse::response($status, $body))
            : $this->transport($status, $body);

        $result = $this->sender($transport)->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertFalse($result->sent);
        $this->assertSame($expectedReason, $result->failureReason);
        $this->assertSame('', (string) $this->settings->get(SupportTicketSender::LAST_REFERENCE_SETTING));
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'support_ticket_sent'")->fetchColumn()
        );
    }

    /**
     * A refusal still teaches the instance the vocabulary it should have
     * used — which is the whole point of publishing the list on every
     * answer.
     */
    public function testARefusalStillTeachesTheCategoryList(): void
    {
        $this->sender($this->transport(200, [
            'status' => 'refused',
            'reason' => 'unknown_category',
            'categories' => [['value' => 'nouvelle', 'label' => 'Nouvelle']],
        ]))->send('inexistante', 'Bonjour', 'chef@unite.be');

        $this->assertSame(
            [['value' => 'nouvelle', 'label' => 'Nouvelle']],
            $this->sender($this->transport(200, []))->categories()
        );
    }

    public function testTheShippedListIsWhatAnInstallationOffersBeforeItHasHeardOne(): void
    {
        $this->assertSame(TicketCategories::shipped(), $this->sender($this->transport(200, []))->categories());
    }

    /**
     * One malformed entry discredits the stored list: a picker half-built
     * from a corrupted setting is worse than the shipped one.
     */
    public function testAStoredListThatIsNotOneFallsBackToWhatWasShipped(): void
    {
        $this->settings->setInternal(SupportTicketSender::CATEGORIES_SETTING, '[{"value":"x"},{"nope":1}]');

        $this->assertSame(TicketCategories::shipped(), $this->sender($this->transport(200, []))->categories());
    }

    /**
     * « De quel module s'agit-il » is a question the receiver cannot ask
     * for every unit at once: two units run different sets, and
     * publishing the receiver's own modules would offer a unit
     * « Locations » for a feature it does not have while losing « Camps »
     * for one it does. So the module half is minted here, from what THIS
     * installation has enabled, and named the way each module's own menu
     * entry names it.
     */
    public function testOneCategoryPerEnabledModuleNamedTheWayItsMenuNamesIt(): void
    {
        $categories = $this->sender($this->transport(200, []), [
            'inbound_mail' => 'Courrier entrant',
            'camps' => 'Camps',
        ])->categories();

        $values = array_column($categories, 'value');
        $this->assertContains('module_camps', $values);
        $this->assertContains('module_inbound_mail', $values);
        $this->assertSame(
            'Courrier entrant',
            $categories[array_search('module_inbound_mail', $values, true)]['label']
        );
    }

    /**
     * « Autre » is the escape hatch, and an escape hatch that is not last
     * is the only answer anybody picks.
     */
    public function testTheEscapeHatchStaysLastWhateverElseIsOffered(): void
    {
        $categories = $this->sender($this->transport(200, []), ['camps' => 'Camps'])->categories();

        $this->assertSame('other', $categories[array_key_last($categories)]['value']);
    }

    /**
     * The modules are added to whatever fixed list is in force, the
     * receiver's own included — the two halves have different owners and
     * neither replaces the other.
     */
    public function testTheModulesAreAddedToTheListTheReceiverPublished(): void
    {
        $this->settings->setInternal(
            SupportTicketSender::CATEGORIES_SETTING,
            '[{"value":"nouvelle","label":"Nouvelle"},{"value":"other","label":"Autre"}]'
        );

        $values = array_column($this->sender($this->transport(200, []), ['camps' => 'Camps'])->categories(), 'value');

        $this->assertSame(['nouvelle', 'module_camps', 'other'], $values);
    }

    /**
     * An installation problem is one somebody has before they have a site
     * to report it from, so the tickets filed under « Installation » were
     * about everything else. « Vie privée » took its place, which is a
     * question units really do ask.
     */
    public function testTheShippedListRetiresInstallationAndOffersPrivacy(): void
    {
        $values = array_column(TicketCategories::shipped(), 'value');

        $this->assertNotContains('installation', $values);
        $this->assertContains('privacy', $values);
    }

    public function testTheJournalEntryCarriesTheReferenceAndNotAWordOfTheDescription(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('desk_import', 'Une phrase que personne ne doit relire ici.', 'chef@unite.be');

        $row = $this->pdo->query(
            "SELECT level, description, context FROM event_log WHERE event_type = 'support_ticket_sent'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('info', $row['level']);
        $this->assertStringContainsString('SUP-7KQ4F2', (string) $row['context']);
        $this->assertStringNotContainsString('personne ne doit relire', (string) $row['context']);
        $this->assertStringNotContainsString('personne ne doit relire', (string) $row['description']);
        $this->assertStringNotContainsString('chef@unite.be', (string) $row['context']);
    }

    /**
     * A ticket that did NOT leave is written down too.
     *
     * Only the success was journaled, and that is the worse half to have:
     * an administrator presses « Envoyer le ticket », nothing gets
     * through, and the event journal — which is also what the diagnostic
     * archive carries — says nothing at all. That is how a real « je crois
     * avoir envoyé un ticket » became unanswerable from either end: no
     * entry on the sender, no entry on the receiver, and no way to tell
     * whether it had ever been attempted.
     */
    public function testATicketThatDoesNotLeaveIsJournaledWithItsReason(): void
    {
        $result = $this->sender($this->transport(502, []))
            ->send('desk_import', 'Une phrase que personne ne doit relire ici.', 'chef@unite.be');

        $this->assertFalse($result->sent);

        $row = $this->pdo->query(
            "SELECT level, description, context FROM event_log WHERE event_type = 'support_ticket_not_sent'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'a failed send must leave a trace');
        // A failed attempt is not a broken site.
        $this->assertSame('warning', $row['level']);
        $this->assertStringContainsString(SupportTicketSender::FAILURE_UNREACHABLE, (string) $row['context']);
        $this->assertStringContainsString('desk_import', (string) $row['context']);
        // Same rule as the success entry beside it.
        $this->assertStringNotContainsString('personne ne doit relire', (string) $row['context']);
        $this->assertStringNotContainsString('chef@unite.be', (string) $row['context']);
    }

    /**
     * Every refusal, including the ones that never open a socket.
     */
    public function testAGuardRefusalIsJournaledJustTheSame(): void
    {
        $this->settings->setInternal('statistics_destination', 'http://scoutmagic.be');

        $this->sender($this->transport(200, []))->send('other', 'Bonjour', 'chef@unite.be');

        $context = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'support_ticket_not_sent'"
        )->fetchColumn();

        $this->assertIsString($context);
        $this->assertStringContainsString(TicketIdentityService::GUARD_INSECURE_DESTINATION, $context);
    }

    /**
     * The guards belong to the report and are called, not copied: a
     * destination that is not HTTPS stops the send before a socket is
     * opened.
     */
    public function testAGuardStopsTheSendBeforeAnythingLeaves(): void
    {
        $this->settings->setInternal('statistics_destination', 'http://scoutmagic.be');
        $transport = $this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-1']);

        $result = $this->sender($transport)->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertFalse($result->sent);
        $this->assertSame(TicketIdentityService::GUARD_INSECURE_DESTINATION, $result->failureReason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * Sending a ticket provisions the identity and leaves the daily report
     * exactly as it found it (roadmap IT-24).
     */
    public function testSendingATicketNeverEnablesTheDailyReport(): void
    {
        $this->sender($this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-7KQ4F2']))
            ->send('other', 'Bonjour', 'chef@unite.be');

        $this->assertSame('0', (string) $this->settings->get('statistics_enabled'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            (string) $this->settings->get(InstallationIdentityService::INSTALLATION_ID_SETTING)
        );
    }

    /**
     * @param array<string, mixed> $answer
     */
    /**
     * Two accepted sends interleaved: the second lands entirely between
     * the first's read and its write (issue #199).
     *
     * That is the race, reproduced without threads. Before the
     * compare-and-swap, the first sender wrote back the list it had read
     * — a list that no longer existed — and the second's reference went
     * with it. The database now refuses that write, and the loser re-reads
     * and prepends onto the winner's list instead.
     */
    public function testAnInterleavedSendDoesNotEraseTheOneThatLandedMeanwhile(): void
    {
        $landedMeanwhile = false;

        // A settings instance of its own, like a second PHP process: two
        // requests do not share a cache, and sharing one here would test
        // something that cannot happen.
        $interleaving = new InterleavingSettingService(
            new SettingRepository($this->pdo),
            SupportTicketSender::RECENT_SETTING,
            function () use (&$landedMeanwhile): void {
                $landedMeanwhile = true;
                $other = $this->senderWith(
                    new SettingService(new SettingRepository($this->pdo)),
                    $this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-SECOND'])
                );
                $this->assertTrue($other->send('other', 'Le deuxième envoi.', 'chef@unite.be')->sent);
            }
        );

        $first = $this->senderWith(
            $interleaving,
            $this->transport(200, ['status' => 'accepted', 'ticket_reference' => 'SUP-FIRST0'])
        );

        $this->assertTrue($first->send('other', 'Le premier envoi.', 'chef@unite.be')->sent);
        $this->assertTrue($landedMeanwhile, 'the interleaving hook never fired — the test proves nothing');

        // Read through a settings instance of its own, the way the page
        // that displays this list does on a later request. `$this->settings`
        // loaded its cache in setUp and nothing since has gone through it,
        // so it would answer with the state before either send.
        $reader = $this->senderWith(
            new SettingService(new SettingRepository($this->pdo)),
            $this->transport(200, [])
        );

        $recent = array_column($reader->recentlySent(), 'reference');

        $this->assertContains('SUP-FIRST0', $recent);
        $this->assertContains('SUP-SECOND', $recent, 'the interleaved send was erased by the one that read before it');
    }

    private function transport(int $status, array $answer): RecordingTicketTransport
    {
        return new RecordingTicketTransport(
            StatisticsTransportResponse::response($status, (string) json_encode($answer))
        );
    }

    /**
     * @param array<string, string> $moduleNames the modules this
     *        installation has enabled, as the composition root passes
     *        them; empty for the fixed list alone
     */
    private function sender(StatisticsTransportInterface $transport, array $moduleNames = []): SupportTicketSender
    {
        return $this->senderWith($this->settings, $transport, $moduleNames);
    }

    /**
     * The same sender on a settings instance the caller chooses — one per
     * contender, so a test can have two of them the way two requests do.
     *
     * @param array<string, string> $moduleNames
     */
    private function senderWith(
        SettingService $settings,
        StatisticsTransportInterface $transport,
        array $moduleNames = []
    ): SupportTicketSender {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new SupportTicketSender(
            $settings,
            new TicketIdentityService(
                $settings,
                new InstallationIdentityService($settings, $this->secretManager),
                $journal
            ),
            $transport,
            $journal,
            '1.0.33',
            null,
            $moduleNames
        );
    }
}

/**
 * A settings instance that lets something else happen between one read and
 * the write that follows it.
 *
 * The only way to reproduce a lost update in a single process: the hook
 * fires AFTER the value is read, so the reader is holding a value that is
 * already stale by the time it tries to write it.
 */
final class InterleavingSettingService extends SettingService
{
    /** @var (callable(): void)|null */
    private $interleave;

    public function __construct(SettingRepository $repository, private string $watchedKey, callable $interleave)
    {
        parent::__construct($repository);
        $this->interleave = $interleave;
    }

    public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
    {
        $value = parent::get($key, $moduleId, $default);

        // Once, and only for the key under test: every other read on this
        // instance has to behave exactly as it always does.
        if ($key === $this->watchedKey && $this->interleave !== null) {
            $interleave = $this->interleave;
            $this->interleave = null;
            $interleave();
        }

        return $value;
    }
}

/**
 * Records what was sent and answers what the test told it to.
 */
final class RecordingTicketTransport implements StatisticsTransportInterface
{
    /** @var array<int, array{url: string, body: string, token: string}> */
    public array $calls = [];

    public function __construct(private StatisticsTransportResponse $response)
    {
    }

    public function post(string $url, string $jsonBody, string $bearerToken, string $userAgent): StatisticsTransportResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $jsonBody, 'token' => $bearerToken];

        return $this->response;
    }
}
