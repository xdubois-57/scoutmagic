<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\LaneEntry;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\TransportSeeder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What an installation that has been sending mail for a year finds on
 * the first request after this lands (ARCHITECTURE.md §8.106).
 *
 * Two things have to be true, and neither is optional: the relay it
 * already had keeps working, and the local send is in all three chains
 * so an installation in `local` mode goes on sending exactly as before.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TransportSeederTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            TransportSeeder::SETTING_SEEDED,
            '0',
            'boolean',
            'Semé',
            'Test',
            null,
            null,
            null,
            false
        );
    }

    public function testAnInstallationWithARelayKeepsItAtTheHeadOfEveryLane(): void
    {
        $this->seed(['mail_mode' => 'smtp', 'smtp_host' => 'smtp-relay.brevo.com', 'smtp_user' => 'u', 'smtp_password' => 'p']);

        $providers = $this->providers->findAll();
        $this->assertCount(1, $providers);
        $this->assertSame('Brevo', $providers[0]['name'], 'A name a volunteer recognises, derived from the host.');
        $this->assertSame(
            ProviderConnections::LEGACY_PREFIX,
            $providers[0]['secret_prefix'],
            'The historic smtp_* keys ARE this provider — one storage, not a copy, so the setup wizard '
                . 'and the Fournisseurs page can never drift apart.'
        );

        foreach (MailLane::ordered() as $lane) {
            $entries = $this->chains->forLane($lane);
            $this->assertSame([$providers[0]['id'], MailProvider::LOCAL_ID], array_map(
                static fn($entry): int => $entry->providerId,
                $entries
            ), "The relay leads the {$lane->value} lane and the local send backs it up.");
            $this->assertTrue($entries[0]->enabled);
            $this->assertTrue($entries[1]->enabled);
        }
    }

    public function testAnInstallationWithNoRelayGetsTheLocalSendEverywhere(): void
    {
        $this->seed([]);

        $this->assertSame([], $this->providers->findAll());
        foreach (MailLane::ordered() as $lane) {
            $entries = $this->chains->forLane($lane);
            $this->assertCount(1, $entries);
            $this->assertTrue($entries[0]->isLocal());
            $this->assertTrue($entries[0]->enabled, 'An installation sending locally must keep sending.');
        }
    }

    public function testSeedingTwiceChangesNothing(): void
    {
        $secrets = ['mail_mode' => 'smtp', 'smtp_host' => 'ssl0.ovh.net'];
        $this->seed($secrets);
        $this->seed($secrets);

        $this->assertCount(1, $this->providers->findAll());
        $this->assertCount(2, $this->chains->forLane(MailLane::Bulk));
    }

    /**
     * A seed that died between creating the relay's row and placing it in
     * every lane must finish the job on the next boot.
     *
     * Laying the chains down is several writes. A guard reading « are
     * there any providers at all » would conclude, on the retry, that
     * there is nothing to create — and the lanes the first attempt never
     * reached would stay empty for the life of the installation, silently,
     * because mail keeps flowing through the local entry. Resuming on the
     * secret prefix is what closes that.
     */
    public function testASeedInterruptedPartWayFinishesOnTheNextBoot(): void
    {
        $secrets = ['mail_mode' => 'smtp', 'smtp_host' => 'smtp-relay.brevo.test'];

        // What a half-finished first attempt leaves behind: the row, and
        // the relay in one lane out of three.
        $relayId = $this->providers->create(
            'Brevo',
            null,
            TransportSeeder::DEFAULT_RELAY_BATCH_SIZE,
            TransportSeeder::DEFAULT_RELAY_BATCH_INTERVAL,
            ProviderConnections::LEGACY_PREFIX
        );
        $this->chains->append(MailLane::Authentication, $relayId, true);

        $this->seed($secrets);

        $this->assertCount(1, $this->providers->findAll(), 'No second row for the same relay.');
        foreach (MailLane::ordered() as $lane) {
            $this->assertTrue(
                $this->chains->exists($lane, $relayId),
                "The relay must have reached the {$lane->value} lane on the retry."
            );
            $this->assertTrue($this->chains->exists($lane, MailProvider::LOCAL_ID));
        }
    }

    /**
     * D14: the old `mass_mail` numbers are deliberately not carried over.
     */
    public function testItDoesNotCarryTheOldMassMailCadenceOver(): void
    {
        $this->settings->register('batch_size', '20', 'number', 'Ancien', 'Test', 'mass_mail');
        $this->settings->set('batch_size', '7', 'mass_mail');

        $this->seed(['mail_mode' => 'smtp', 'smtp_host' => 'smtp.exemple.test']);

        $this->assertSame(
            TransportSeeder::DEFAULT_RELAY_BATCH_SIZE,
            $this->providers->findAll()[0]['batch_size']
        );
    }

    /**
     * A first install whose schema does not exist yet must not fail the
     * request — and must not mark itself seeded either, or the chains
     * would never be laid down at all.
     */
    public function testADatabaseThatCannotAnswerYetIsNotAFailure(): void
    {
        $this->pdo->exec('DROP TABLE mail_lane_entries');

        $this->seed(['mail_mode' => 'smtp', 'smtp_host' => 'smtp.exemple.test']);

        $this->assertSame('0', (string) $this->settings->get(TransportSeeder::SETTING_SEEDED));
    }

    /**
     * The first of two findings a full review pass raised on this seam.
     *
     * `SetupController::handleConfigUpdate()` writes `smtp_host` back on
     * every save whatever the mode, so an installation that moved SMTP →
     * Local keeps a readable host it deliberately stopped using.
     * Importing on that host alone put the abandoned third party FIRST in
     * all three lanes — magic links included — on the first boot after
     * this change, and `TransportConfigurator::apply()` would have called
     * `isSMTP()` over the local mode `MailService` had chosen.
     */
    public function testARelayTheInstallationSwitchedAwayFromIsNotImported(): void
    {
        $this->seed(['mail_mode' => 'local', 'smtp_host' => 'smtp-relay.brevo.com', 'smtp_password' => 'p']);

        $this->assertSame([], $this->providers->findAll(), 'A host in local mode is not a provider.');
        foreach (MailLane::ordered() as $lane) {
            $entries = $this->chains->forLane($lane);
            $this->assertCount(1, $entries, 'The lane carries the local send and nothing else.');
            $this->assertSame(MailProvider::LOCAL_ID, $entries[0]->providerId);
        }
    }

    /**
     * An absent `mail_mode` is `local`, which is what
     * `MailServiceFactory::create()` already defaults it to — so the
     * chain agrees with the way the site is actually sending today.
     */
    public function testAnAbsentModeReadsAsLocal(): void
    {
        $this->seed(['smtp_host' => 'smtp-relay.brevo.com']);

        $this->assertSame([], $this->providers->findAll());
    }

    /**
     * **A seeded installation does not seed again** — which is what the
     * second flag's removal had to leave true (issue #336).
     *
     * That flag existed for one path: a site seeded while local-only picking
     * up a relay configured THROUGH THE WIZARD later. « Installation &
     * serveur » no longer configures one after initialisation, so the path
     * is gone — and this test is the other half of the bargain, because
     * without the flag the common boot must stop on the first read rather
     * than re-read the secrets and decide again.
     *
     * A relay appearing in `secrets.enc` afterwards is therefore NOT
     * imported. That is the intended loss, and it costs nothing real: the
     * only writer left is « Courrier sortant › Fournisseurs », which
     * creates the provider row itself.
     */
    public function testASeededInstallationDoesNotImportARelayThatAppearsLater(): void
    {
        $this->seed(['mail_mode' => 'local']);
        $this->assertSame([], $this->providers->findAll());
        $this->assertSame('1', (string) $this->settings->get(TransportSeeder::SETTING_SEEDED));

        // A relay written into the secrets after the seeding — which is what
        // the wizard used to be able to do.
        $this->seed(['mail_mode' => 'smtp', 'smtp_host' => 'ssl0.ovh.net']);

        $this->assertSame(
            [],
            $this->providers->findAll(),
            'a seeded installation read the secrets again and imported a relay on a later boot'
        );
        foreach (MailLane::ordered() as $lane) {
            $ids = array_map(static fn(LaneEntry $e): int => $e->providerId, $this->chains->forLane($lane));
            $this->assertSame([MailProvider::LOCAL_ID], $ids, 'the lanes were rewritten on a later boot');
        }
    }

    /**
     * And the case that mattered most, which the single flag now answers
     * structurally rather than carefully: once the relay is imported,
     * deleting it from the Fournisseurs page is a DECISION.
     *
     * `ProviderConnections::forget()` deliberately keeps the four legacy
     * keys in `secrets.enc`, so a seeder that re-read them would put the row
     * back on the next request — and the administrator would find the relay
     * they had just removed sitting at the head of every lane again. This
     * is why removing the second flag had to make the common boot return
     * EARLIER rather than later (issue #336).
     */
    public function testARelayDeletedFromTheScreenIsNotResurrected(): void
    {
        $secrets = ['mail_mode' => 'smtp', 'smtp_host' => 'ssl0.ovh.net'];
        $this->seed($secrets);
        $relayId = (int) $this->providers->findAll()[0]['id'];

        $this->chains->removeProvider($relayId);
        $this->providers->delete($relayId);

        $this->seed($secrets);

        $this->assertSame([], $this->providers->findAll(), 'The administrator removed it; it stays removed.');
    }

    /**
     * The first label a volunteer recognises, and not the country.
     *
     * The suffixes are named rather than measured on purpose: a rule that
     * dropped any trailing label of three letters or fewer reads as the
     * same idea and is not — `ssl0.ovh.net` would come out « Ssl0 »,
     * because `ovh` IS the provider. That case is in the list below.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('relayHosts')]
    public function testTheRelayIsNamedAfterItsProviderNotItsCountry(string $host, string $expected): void
    {
        $this->seed(['mail_mode' => 'smtp', 'smtp_host' => $host]);

        $this->assertSame($expected, $this->providers->findAll()[0]['name']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function relayHosts(): array
    {
        return [
            'a Swiss host' => ['mail.infomaniak.ch', 'Infomaniak'],
            'a German host' => ['smtp.strato.de', 'Strato'],
            'a Dutch host' => ['smtp.transip.nl', 'Transip'],
            'a three-letter provider' => ['ssl0.ovh.net', 'Ovh'],
            'the usual one' => ['smtp-relay.brevo.com', 'Brevo'],
            'nothing readable left' => ['localhost', 'Localhost'],
        ];
    }

    /**
     * @param array<string, string> $secrets
     */
    private function seed(array $secrets): void
    {
        (new TransportSeeder($this->providers, $this->chains, $this->settings))->seed($secrets);
    }
}
