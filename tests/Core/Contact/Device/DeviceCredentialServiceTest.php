<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Device;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\Device\DeviceCredentialRepository;
use Core\Contact\Device\DeviceCredentialService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Creating, revoking, and the site-wide cut-out — plus what each of them
 * is allowed to write in the journal.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeviceCredentialServiceTest extends TestCase
{
    private \PDO $pdo;
    private DeviceCredentialService $service;
    private DeviceCredentialRepository $repository;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new DeviceCredentialRepository($this->pdo);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(
            DeviceCredentialService::SETTING_SYNC_ENABLED,
            '1',
            'boolean',
            'Synchronisation',
            'Le coupe-circuit.',
            null,
            null,
            null,
            false
        );

        $this->service = new DeviceCredentialService(
            $this->repository,
            $settings,
            new JournalService(new JournalRepository($this->pdo))
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['x', str_repeat('a', 64)]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    private function journalEntries(): array
    {
        return $this->pdo->query('SELECT * FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The secret is 32 random bytes, hex-encoded, and it exists in
     * exactly one place: the object this returns. Nothing writes it
     * anywhere it could be read back.
     */
    public function testTheSecretIsReturnedOnceAndStoredNowhereInClear(): void
    {
        $created = $this->service->create($this->accountId, 'Téléphone', $this->accountId);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $created->secret);

        $dump = '';
        foreach (['device_credentials', 'event_log', 'settings'] as $table) {
            foreach ($this->pdo->query("SELECT * FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $dump .= json_encode($row);
            }
        }
        $this->assertStringNotContainsString($created->secret, $dump);
    }

    public function testTwoCredentialsNeverShareASecret(): void
    {
        $first = $this->service->create($this->accountId, 'A', $this->accountId);
        $second = $this->service->create($this->accountId, 'B', $this->accountId);

        $this->assertNotSame($first->secret, $second->secret);
    }

    /**
     * A journal entry names identifiers and nothing else: no secret, no
     * label somebody typed, no address.
     */
    public function testCreatingAndRevokingAreJournaledWithIdentifiersOnly(): void
    {
        $created = $this->service->create($this->accountId, 'iPhone de Camille', $this->accountId);
        $this->service->revoke($created->credential, $this->accountId);

        $entries = $this->journalEntries();
        $this->assertSame(
            ['device_credential_created', 'device_credential_revoked'],
            array_column($entries, 'event_type')
        );

        foreach ($entries as $entry) {
            $this->assertSame('security', $entry['level']);
            $this->assertSame(
                ['credential_id' => $created->credential->id, 'user_account_id' => $this->accountId],
                json_decode((string) $entry['context'], true)
            );
            $row = (string) $entry['context'] . '|' . (string) $entry['description'];
            $this->assertStringNotContainsString('Camille', $row);
            $this->assertStringNotContainsString($created->secret, $row);
        }
    }

    public function testASuccessfulSynchronisationIsNeverJournaled(): void
    {
        $created = $this->service->create($this->accountId, 'Téléphone', $this->accountId);
        $before = count($this->journalEntries());

        $this->repository->touchSync($created->credential->id);

        $this->assertCount($before, $this->journalEntries());
    }

    public function testTheCutOutDefaultsToOnAndIsJournaledBothWays(): void
    {
        $this->assertTrue($this->service->isSyncEnabled());

        $this->service->setSyncEnabled(false, $this->accountId);
        $this->assertFalse($this->service->isSyncEnabled());

        $this->service->setSyncEnabled(true, $this->accountId);
        $this->assertTrue($this->service->isSyncEnabled());

        $this->assertSame(
            ['contact_sync_disabled', 'contact_sync_enabled'],
            array_column($this->journalEntries(), 'event_type')
        );
    }

    public function testTheNumberOfLiveCredentialsPerAccountIsBounded(): void
    {
        for ($i = 0; $i < DeviceCredentialService::MAX_PER_ACCOUNT; $i++) {
            $this->assertTrue($this->service->canCreateFor($this->accountId));
            $this->service->create($this->accountId, 'Appareil ' . $i, $this->accountId);
        }

        $this->assertFalse($this->service->canCreateFor($this->accountId));

        // Revoking one frees a slot; it does not delete the row.
        $this->service->revoke($this->service->listForAccount($this->accountId)[0], $this->accountId);
        $this->assertTrue($this->service->canCreateFor($this->accountId));
    }

    public function testALabelIsTrimmedBoundedAndNeverEmpty(): void
    {
        $blank = $this->service->create($this->accountId, "   \n ", $this->accountId);
        $this->assertSame('Appareil sans nom', $blank->credential->label);

        $long = $this->service->create($this->accountId, str_repeat('é', 250), $this->accountId);
        $this->assertSame(DeviceCredentialService::MAX_LABEL_LENGTH, mb_strlen($long->credential->label));
    }

    /**
     * A credential belonging to somebody else answers the same null as
     * one that does not exist, so nothing can be enumerated through it.
     */
    public function testOwnershipIsCheckedOnTheRowAndNotOnlyByRole(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['y', str_repeat('b', 64)]);
        $otherAccountId = (int) $this->pdo->lastInsertId();

        $mine = $this->service->create($this->accountId, 'À moi', $this->accountId);

        $this->assertNotNull($this->service->findOwned($mine->credential->id, $this->accountId));
        $this->assertNull($this->service->findOwned($mine->credential->id, $otherAccountId));
        $this->assertNull($this->service->findOwned(999999, $this->accountId));
        $this->assertNull($this->service->findOwned(0, $this->accountId));
    }
}
