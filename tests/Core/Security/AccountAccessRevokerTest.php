<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Contact\Device\DeviceCredentialRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AccountAccessRevoker;
use Core\Security\WebAuthnCredentialRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1115: what a password reset and « Déconnecter partout » take back
 * — every passkey and live device credential of the account, except the
 * ones explicitly kept, and never another account's.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AccountAccessRevokerTest extends TestCase
{
    private \PDO $pdo;
    private WebAuthnCredentialRepository $passkeys;
    private DeviceCredentialRepository $devices;
    private AccountAccessRevoker $revoker;
    private int $holder;
    private int $other;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->passkeys = new WebAuthnCredentialRepository($this->pdo);
        $this->devices = new DeviceCredentialRepository($this->pdo);
        $this->revoker = new AccountAccessRevoker(
            $this->passkeys,
            $this->devices,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->holder = $this->createAccount('a');
        $this->other = $this->createAccount('b');
    }

    public function testLiveAccessesListsPasskeysAndOnlyTheDevicesStillWorking(): void
    {
        $key = $this->passkeys->create($this->holder, random_bytes(16), 'pk', 'Mon téléphone');
        $live = $this->devices->create($this->holder, 'Ordinateur', 'secret-1')->id;
        $dead = $this->devices->create($this->holder, 'Ancien', 'secret-2')->id;
        $this->devices->revoke($dead);
        $this->passkeys->create($this->other, random_bytes(16), 'pk', 'Pas à moi');

        $this->assertSame(
            [
                'passkeys' => [['id' => $key, 'label' => 'Mon téléphone']],
                'devices' => [['id' => $live, 'label' => 'Ordinateur']],
            ],
            $this->revoker->liveAccesses($this->holder)
        );
    }

    public function testRevokeTakesBackEverythingWhenNothingIsKept(): void
    {
        $credentialId = random_bytes(16);
        $this->passkeys->create($this->holder, $credentialId, 'pk', 'Clé');
        $device = $this->devices->create($this->holder, 'Téléphone', 'secret')->id;

        $count = $this->revoker->revoke(
            $this->holder,
            [],
            [],
            AccountAccessRevoker::REASON_SIGN_OUT_EVERYWHERE,
            $this->holder
        );

        $this->assertSame(['passkeys' => 1, 'devices' => 1], $count);
        $this->assertNull($this->passkeys->findByCredentialId($credentialId));
        $this->assertTrue($this->devices->findById($device)?->isRevoked());
    }

    public function testRevokeLeavesExactlyTheKeptOnes(): void
    {
        $keptKey = $this->passkeys->create($this->holder, random_bytes(16), 'pk', 'Gardée');
        $goneKey = $this->passkeys->create($this->holder, random_bytes(16), 'pk', 'Inconnue');
        $keptDevice = $this->devices->create($this->holder, 'Gardé', 's1')->id;
        $goneDevice = $this->devices->create($this->holder, 'Inconnu', 's2')->id;

        $count = $this->revoker->revoke(
            $this->holder,
            [$keptKey],
            [$keptDevice],
            AccountAccessRevoker::REASON_PASSWORD_RESET,
            $this->holder
        );

        $this->assertSame(['passkeys' => 1, 'devices' => 1], $count);
        $this->assertSame(
            [$keptKey],
            array_map(
                static fn(array $row): int => (int) $row['id'],
                $this->passkeys->findByUserAccountId($this->holder)
            )
        );
        $this->assertFalse($this->devices->findById($keptDevice)?->isRevoked());
        $this->assertTrue($this->devices->findById($goneDevice)?->isRevoked());
        $this->assertNotContains($goneKey, array_column($this->passkeys->findByUserAccountId($this->holder), 'id'));
    }

    /**
     * A keep list naming someone else's identifiers neither keeps nor
     * revokes them: the revoker only ever looks at the holder's own.
     */
    public function testAnotherAccountsAccessesAreNeverTouched(): void
    {
        $theirKey = $this->passkeys->create($this->other, random_bytes(16), 'pk', 'Autre');
        $theirDevice = $this->devices->create($this->other, 'Autre', 's')->id;

        $count = $this->revoker->revoke(
            $this->holder,
            [$theirKey],
            [$theirDevice],
            AccountAccessRevoker::REASON_SIGN_OUT_EVERYWHERE,
            $this->holder
        );

        $this->assertSame(['passkeys' => 0, 'devices' => 0], $count);
        $this->assertCount(1, $this->passkeys->findByUserAccountId($this->other));
        $this->assertFalse($this->devices->findById($theirDevice)?->isRevoked());
    }

    /**
     * An already revoked device keeps its first revocation instant: it is
     * not « live », so it is neither counted nor stamped again.
     */
    public function testAnAlreadyRevokedDeviceIsNotCountedAgain(): void
    {
        $device = $this->devices->create($this->holder, 'Ancien', 's')->id;
        $this->devices->revoke($device);
        $this->pdo->prepare('UPDATE device_credentials SET revoked_at = ? WHERE id = ?')
            ->execute(['2020-01-01 00:00:00', $device]);

        $count = $this->revoker->revoke(
            $this->holder,
            [],
            [],
            AccountAccessRevoker::REASON_SIGN_OUT_EVERYWHERE,
            $this->holder
        );

        $this->assertSame(['passkeys' => 0, 'devices' => 0], $count);
        $this->assertSame('2020-01-01', $this->devices->findById($device)?->revokedAt?->format('Y-m-d'));
    }

    /**
     * One security entry, identifiers only — never the label its owner
     * typed (SECURITY.md §11).
     */
    public function testOneSecurityJournalEntryWithIdentifiersOnly(): void
    {
        $kept = $this->passkeys->create($this->holder, random_bytes(16), 'pk', 'Étiquette secrète');
        $gone = $this->passkeys->create($this->holder, random_bytes(16), 'pk', 'Autre étiquette');
        $device = $this->devices->create($this->holder, 'Nom tapé', 's')->id;

        $this->revoker->revoke($this->holder, [$kept], [], AccountAccessRevoker::REASON_PASSWORD_RESET, $this->holder);

        $rows = $this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('account_accesses_revoked', $rows[0]['event_type']);
        $this->assertSame('security', $rows[0]['level']);
        $this->assertSame($this->holder, (int) $rows[0]['user_account_id']);

        $context = json_decode((string) $rows[0]['context'], true);
        $this->assertSame('password_reset', $context['reason']);
        $this->assertSame([$gone], $context['passkey_ids']);
        $this->assertSame([$device], $context['device_credential_ids']);
        $this->assertSame(1, $context['kept_passkeys']);
        $this->assertSame(0, $context['kept_devices']);
        $this->assertStringNotContainsString('tiquette', (string) $rows[0]['context']);
        $this->assertStringNotContainsString('Nom tapé', (string) $rows[0]['context']);
    }

    public function testTheTwoReasonsReadDifferentlyInTheJournal(): void
    {
        $this->revoker->revoke($this->holder, [], [], AccountAccessRevoker::REASON_PASSWORD_RESET, $this->holder);
        $this->revoker->revoke($this->holder, [], [], AccountAccessRevoker::REASON_SIGN_OUT_EVERYWHERE, $this->holder);

        $descriptions = $this->pdo->query('SELECT description FROM event_log ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertStringContainsString('réinitialisation', (string) $descriptions[0]);
        $this->assertStringContainsString('Déconnexion partout', (string) $descriptions[1]);
    }

    private function createAccount(string $suffix): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['x' . $suffix, str_repeat($suffix, 64)]);

        return (int) $this->pdo->lastInsertId();
    }
}
