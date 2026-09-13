<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportException;
use Core\Mail\Transport\TransportService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two refusals the configuration screen owes an explanation for
 * (ARCHITECTURE.md §8.106).
 *
 * Both of them are the same failure seen from the configuration side
 * rather than from a relay going down: a lane with nothing enabled in it
 * sends nothing, and on the authentication lane that means nobody can
 * sign in — including whoever emptied it.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TransportServiceTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private TransportService $service;
    private JournalRepository $journalRepository;
    private string $secretsDirectory = '';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $settings = new SettingService(new SettingRepository($this->pdo));
        // A real SecretManager over a scratch directory: deleting a
        // provider forgets its connection, and a null manager would make
        // that path untestable rather than merely unexercised.
        $secretsDirectory = sys_get_temp_dir() . '/scoutmagic-transport-' . bin2hex(random_bytes(6));
        mkdir($secretsDirectory, 0700, true);
        $this->secretsDirectory = $secretsDirectory;
        $secretManager = new \Core\Security\SecretManager(
            $secretsDirectory . '/master.key',
            $secretsDirectory . '/secrets.enc'
        );
        $secretManager->generateMasterKey();
        $secretManager->writeSecrets([]);
        $connections = new ProviderConnections([], $secretManager);
        $this->journalRepository = new JournalRepository($this->pdo);

        $this->service = new TransportService(
            $this->providers,
            $this->chains,
            new SendCounterRepository($this->pdo),
            $connections,
            new MailProviderDirectory($this->providers, $connections, $settings),
            new JournalService($this->journalRepository)
        );
    }

    protected function tearDown(): void
    {
        if ($this->secretsDirectory === '') {
            return;
        }

        foreach (glob($this->secretsDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->secretsDirectory);
    }

    // ── a lane is never emptied ───────────────────────────────────────

    public function testTheLastEnabledEntryOfALaneCannotBeDisabled(): void
    {
        $this->chains->append(MailLane::Authentication, MailProvider::LOCAL_ID, true);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/Authentification/');

        $this->service->setEntryEnabled(MailLane::Authentication, MailProvider::LOCAL_ID, false);
    }

    public function testAnEntryCanBeDisabledWhileAnotherStaysEnabled(): void
    {
        $relay = $this->providers->create('Relais', null, 50, 10);
        $this->chains->append(MailLane::Bulk, $relay, true);
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);

        $this->service->setEntryEnabled(MailLane::Bulk, MailProvider::LOCAL_ID, false);

        $this->assertSame(1, $this->chains->countEnabled(MailLane::Bulk));
    }

    /**
     * The refusal is per lane, and it has to be: a relay may be the only
     * one enabled on the mailing lane while three others carry the
     * authentication one.
     */
    public function testDisablingIsRefusedOnlyOnTheLaneThatWouldBeEmptied(): void
    {
        $relay = $this->providers->create('Relais', null, 50, 10);
        $this->chains->append(MailLane::Bulk, $relay, true);
        $this->chains->append(MailLane::Transactional, $relay, true);
        $this->chains->append(MailLane::Transactional, MailProvider::LOCAL_ID, true);

        $this->service->setEntryEnabled(MailLane::Transactional, $relay, false);
        $this->assertSame(1, $this->chains->countEnabled(MailLane::Transactional));

        $this->expectException(TransportException::class);
        $this->service->setEntryEnabled(MailLane::Bulk, $relay, false);
    }

    // ── the local send is permanent ───────────────────────────────────

    public function testTheLocalSendCannotBeDeleted(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/envoi local/i');

        $this->service->deleteProvider(MailProvider::LOCAL_ID);
    }

    public function testARelayThatIsALaneSoleActiveEntryCannotBeDeleted(): void
    {
        $relay = $this->providers->create('Relais', null, 50, 10);
        $this->chains->append(MailLane::Authentication, $relay, true);

        try {
            $this->service->deleteProvider($relay);
            $this->fail('A lane may never be left with no enabled entry.');
        } catch (TransportException) {
            // Asserted HERE rather than after the call: `expectException()`
            // ends the test at the throw, so the check below never ran and
            // an implementation that deleted the row and then complained
            // would have passed.
            $this->assertNotNull($this->providers->findById($relay));
        }
    }

    public function testDeletingARelayRemovesItsEntriesEverywhere(): void
    {
        $relay = $this->providers->create('Relais', null, 50, 10);
        foreach (MailLane::ordered() as $lane) {
            $this->chains->append($lane, MailProvider::LOCAL_ID, true);
            $this->chains->append($lane, $relay, true);
        }

        $this->service->deleteProvider($relay);

        $this->assertNull($this->providers->findById($relay));
        foreach (MailLane::ordered() as $lane) {
            $this->assertFalse($this->chains->exists($lane, $relay));
        }
    }

    /**
     * The credentials are erased BEFORE the row, so a failure to erase
     * them leaves something to retry.
     *
     * `ProviderConnections::forget()` is file I/O on `secrets.enc`. Done
     * after the row was deleted, a failure there stranded that relay's
     * host, user and password in the file for good: the retry finds no
     * row and returns having done nothing, and nothing else in the site
     * knows the prefix. A third party's password would have outlived the
     * fournisseur that justified keeping it, with the screen reporting
     * « Fournisseur supprimé. » every time.
     *
     * A service whose `ProviderConnections` has no `SecretManager` is
     * exactly the shape of that failure — `mutate()` refuses — and it
     * checks the second half too: the refusal arrives as a
     * `TransportException`, which the controller catches, rather than the
     * bare `RuntimeException` that would have reached a visitor as a 500.
     */
    public function testARelayWhoseCredentialsCannotBeErasedIsNotDeleted(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $unwritable = new ProviderConnections([]);
        $service = new TransportService(
            $this->providers,
            $this->chains,
            new SendCounterRepository($this->pdo),
            $unwritable,
            new MailProviderDirectory($this->providers, $unwritable, $settings),
            new JournalService($this->journalRepository)
        );

        $relay = $this->providers->create('Relais', null, 50, 10, 'mail_provider_9');
        foreach (MailLane::ordered() as $lane) {
            $this->chains->append($lane, MailProvider::LOCAL_ID, true);
            $this->chains->append($lane, $relay, true);
        }

        try {
            $service->deleteProvider($relay);
            $this->fail('Deleting a provider whose credentials cannot be erased must be refused.');
        } catch (TransportException $e) {
            $this->assertStringNotContainsString(
                $this->secretsDirectory,
                $e->getMessage(),
                'The message reaches a screen, so it never names a path on the server.'
            );
        }

        $this->assertNotNull(
            $this->providers->findById($relay),
            'The row is still there, so the administrator can try again.'
        );
        foreach (MailLane::ordered() as $lane) {
            $this->assertTrue($this->chains->exists($lane, $relay), 'And its lane entries are untouched.');
        }
    }

    // ── what the journal keeps ────────────────────────────────────────

    /**
     * Changing a chain is journaled at `security` deliberately: it
     * changes where the site's sign-in links leave from, which is a
     * security decision even when it is taken in good faith.
     */
    public function testEveryChainChangeIsJournalledAtSecurityLevel(): void
    {
        $relay = $this->providers->create('Relais', null, 50, 10);
        $this->chains->append(MailLane::Bulk, $relay, true);
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);

        $this->service->setEntryEnabled(MailLane::Bulk, MailProvider::LOCAL_ID, false);
        $this->service->reorderLane(MailLane::Bulk, [MailProvider::LOCAL_ID, $relay]);

        $types = array_column($this->journalRepository->search(limit: 50), 'event_type');
        $this->assertContains('mail_lane_entry_disabled', $types);
        $this->assertContains('mail_lane_reordered', $types);

        foreach ($this->journalRepository->search(limit: 50) as $entry) {
            if (str_starts_with((string) $entry['event_type'], 'mail_lane')) {
                $this->assertSame('security', $entry['level']);
            }
        }
    }

    // ── reordering ────────────────────────────────────────────────────

    public function testReorderingIgnoresAnIdThatIsNotInThatLane(): void
    {
        $relay = $this->providers->create('Relais', null, 50, 10);
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);
        $this->chains->append(MailLane::Bulk, $relay, true);

        // 4242 is in no lane at all: a reordering must never create an
        // entry out of an id a browser happened to send.
        $this->service->reorderLane(MailLane::Bulk, [$relay, 4242, MailProvider::LOCAL_ID]);

        $order = array_map(
            static fn($entry): int => $entry->providerId,
            $this->chains->forLane(MailLane::Bulk)
        );
        $this->assertSame([$relay, MailProvider::LOCAL_ID], $order);
    }
}
