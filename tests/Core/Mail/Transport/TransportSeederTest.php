<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Transport\LaneChainRepository;
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
 * @group database
 */
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
        $this->seed(['smtp_host' => 'smtp-relay.brevo.com', 'smtp_user' => 'u', 'smtp_password' => 'p']);

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
        $secrets = ['smtp_host' => 'ssl0.ovh.net'];
        $this->seed($secrets);
        $this->seed($secrets);

        $this->assertCount(1, $this->providers->findAll());
        $this->assertCount(2, $this->chains->forLane(MailLane::Bulk));
    }

    /**
     * D14: the old `mass_mail` numbers are deliberately not carried over.
     */
    public function testItDoesNotCarryTheOldMassMailCadenceOver(): void
    {
        $this->settings->register('batch_size', '20', 'number', 'Ancien', 'Test', 'mass_mail');
        $this->settings->set('batch_size', '7', 'mass_mail');

        $this->seed(['smtp_host' => 'smtp.exemple.test']);

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

        $this->seed(['smtp_host' => 'smtp.exemple.test']);

        $this->assertSame('0', (string) $this->settings->get(TransportSeeder::SETTING_SEEDED));
    }

    /**
     * @param array<string, string> $secrets
     */
    private function seed(array $secrets): void
    {
        (new TransportSeeder($this->providers, $this->chains, $this->settings))->seed($secrets);
    }
}
