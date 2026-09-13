<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Security\SecretManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Where the off-site credentials end up, on a real settings table.
 *
 * **Against the database rather than a double**, because the claim being
 * tested is precisely about which STORE each value lands in — and a fake
 * settings service would be a fake of the very thing under suspicion. The
 * secrets side is real too: a `SecretManager` over a temporary directory,
 * so what the assertions read is the encrypted file the site would
 * actually write.
 *
 * @group database
 */
#[Group('database')]
final class RemoteBackupConnectionTest extends TestCase
{
    private string $base;
    private \PDO $pdo;
    private SettingService $settings;
    private SecretManager $secrets;
    private RemoteBackupConnection $connection;

    protected function setUp(): void
    {
        $this->pdo = $this->realDbConnection()->getPdo();
        $this->base = sys_get_temp_dir() . '/remote_conn_' . uniqid();
        @mkdir($this->base . '/keys', 0700, true);
        @mkdir($this->base . '/config', 0700, true);

        $this->secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $this->secrets->generateMasterKey();
        $this->secrets->writeSecrets([]);

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RemoteBackupConnection::register($this->settings);
        $this->connection = new RemoteBackupConnection($this->settings, $this->secrets);
        $this->connection->disconnect();
    }

    protected function tearDown(): void
    {
        // Best-effort: one case deliberately leaves the secrets file
        // unreadable, and this class refuses to write over a file it
        // cannot read — which is the behaviour that case exists to assert.
        try {
            $this->connection->disconnect();
        } catch (\Throwable) {
            // Nothing to clean up that survives the temporary directory.
        }
        foreach (['/keys/master.key', '/config/secrets.enc'] as $file) {
            @unlink($this->base . $file);
        }
        @rmdir($this->base . '/keys');
        @rmdir($this->base . '/config');
        @rmdir($this->base);
    }

    /**
     * **The client secret and the refresh token are nowhere in the
     * settings table**, and the client ID deliberately is.
     *
     * The ID travels in the authorisation URL the operator's own browser
     * follows, so hiding it would protect nothing and would cost them the
     * ability to check they pasted the right one. The other two are
     * credentials, and a `settings` row is a screen.
     */
    public function testTheCredentialsLandInTheEncryptedFileAndNotInTheSettingsTable(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $this->connection->saveConnection('refresh-42', 'unite@example.org', 'folder-42');

        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(\PDO::FETCH_ASSOC);
        $serialised = (string) json_encode($rows);

        $this->assertStringNotContainsString('client-secret-42', $serialised);
        $this->assertStringNotContainsString('refresh-42', $serialised);
        $this->assertStringContainsString('client-id-42', $serialised);

        // And they did land somewhere: a test that only proves absence
        // would pass on a feature that stores nothing at all.
        $this->assertSame('client-secret-42', $this->connection->clientSecret());
        $this->assertSame('refresh-42', $this->connection->refreshToken());
        $this->assertTrue($this->connection->isConnected());
    }

    /** Nor are they readable in the file itself without the master key. */
    public function testTheSecretsFileDoesNotHoldThemInClear(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $this->connection->saveConnection('refresh-42', 'unite@example.org', 'folder-42');

        $onDisk = (string) file_get_contents($this->base . '/config/secrets.enc');

        $this->assertStringNotContainsString('client-secret-42', $onDisk);
        $this->assertStringNotContainsString('refresh-42', $onDisk);
    }

    /**
     * Disconnecting forgets everything, the client credentials included.
     *
     * An operator who presses that button is handing the site on, or has
     * decided it should no longer be able to write into their Drive.
     * Keeping the secret « in case » would leave the site one click from
     * writing there again.
     */
    public function testDisconnectingForgetsTheCredentialsTooAndNotOnlyTheGrant(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $this->connection->saveConnection('refresh-42', 'unite@example.org', 'folder-42');

        $this->connection->disconnect();

        $this->assertFalse($this->connection->isConnected());
        $this->assertFalse($this->connection->hasCredentials());
        $this->assertSame('', $this->connection->clientId());
        $this->assertSame('', $this->connection->clientSecret());
        $this->assertSame('', $this->connection->refreshToken());
        $this->assertSame('', $this->connection->account());
        $this->assertSame(RemoteBackupConnection::STATE_DISCONNECTED, $this->connection->state());
    }

    /**
     * A withdrawn grant drops the dead token and keeps the account.
     *
     * « Reconnectez le compte » is an instruction the operator cannot
     * follow if the page no longer says which one; and a refresh token
     * Google will never honour again is a credential on disk protecting
     * nothing.
     */
    public function testAWithdrawnGrantKeepsTheAccountAndDropsTheToken(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $this->connection->saveConnection('refresh-42', 'unite@example.org', 'folder-42');

        $this->connection->markNeedsReauthorisation('Google n\'accepte plus l\'autorisation de ce site.');

        $this->assertSame(RemoteBackupConnection::STATE_NEEDS_REAUTH, $this->connection->state());
        $this->assertFalse($this->connection->isConnected());
        $this->assertSame('', $this->connection->refreshToken());
        $this->assertSame('unite@example.org', $this->connection->account());
        $this->assertSame('folder-42', $this->connection->folderId());
        // The client credentials survive: reconnecting needs them, and the
        // operator did not ask to forget the project.
        $this->assertTrue($this->connection->hasCredentials());
    }

    /**
     * A site whose secrets cannot be read at all answers « not connected »
     * rather than throwing on a configuration page an admin just opened.
     */
    public function testASiteWhoseSecretsAreUnreadableReportsItselfAsNotConnected(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $this->connection->saveConnection('refresh-42', 'unite@example.org', 'folder-42');

        file_put_contents($this->base . '/config/secrets.enc', 'des octets qui ne veulent rien dire');

        $this->assertSame('', $this->connection->refreshToken());
        $this->assertFalse($this->connection->isConnected());
        $this->assertFalse($this->connection->hasCredentials());
    }

    /**
     * **The account address is not a `settings` row either.**
     *
     * It is not a credential, but it is the e-mail address of a real
     * person — and a `settings` row renders in clear on Configuration >
     * Réglages and travels unredacted in the support diagnostic export.
     * The client ID stays visible because it is public by construction;
     * this one has no such excuse.
     */
    public function testTheConnectedAccountAddressIsNotStoredInTheClear(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $this->connection->saveConnection('refresh-42', 'tresorier@example.org', 'folder-42');

        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertStringNotContainsString('tresorier@example.org', (string) json_encode($rows));
        $this->assertStringNotContainsString(
            'tresorier@example.org',
            (string) file_get_contents($this->base . '/config/secrets.enc')
        );
        // And the screen can still show it, which is the point of keeping
        // it at all.
        $this->assertSame('tresorier@example.org', $this->connection->account());
    }

    /**
     * **« Rien n'a été modifié » has to be true when it is said.**
     *
     * `writeSecrets()` refuses to write a `secrets.enc` it could not read,
     * with that sentence. Seven `settings` rows committed before the
     * refusal would make it a lie told by the code that wrote it — and a
     * specific, unrecoverable one: the page would say « déraccordé » over
     * credentials that still work, with the button that would undo it now
     * hidden and the client id it needs already cleared.
     */
    public function testARefusedDisconnectLeavesTheSettingsExactlyAsTheyWere(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $this->connection->saveConnection('refresh-42', 'unite@example.org', 'folder-42');

        file_put_contents($this->base . '/config/secrets.enc', 'des octets qui ne veulent rien dire');

        try {
            $this->connection->disconnect();
            $this->fail('An unreadable secrets file was overwritten.');
        } catch (RemoteBackupException) {
            // Asserted below rather than here.
        }

        $this->assertSame(RemoteBackupConnection::STATE_CONNECTED, $this->connection->state());
        $this->assertSame('client-id-42', $this->connection->clientId());
        $this->assertSame('folder-42', $this->connection->folderId());
    }

    /** The same discipline when the credentials are being saved. */
    public function testARefusedCredentialSaveDoesNotLeaveTheClientIdBehind(): void
    {
        file_put_contents($this->base . '/config/secrets.enc', 'des octets qui ne veulent rien dire');

        try {
            $this->connection->saveCredentials('client-id-99', 'client-secret-99');
            $this->fail('An unreadable secrets file was overwritten.');
        } catch (RemoteBackupException) {
            // Asserted below.
        }

        $this->assertSame('', $this->connection->clientId());
    }

    /**
     * **And it refuses to write over what it cannot read.**
     *
     * `secrets.enc` is one document holding the SMTP password, the column
     * encryption key and the VAPID private key alongside these two.
     * Writing it means writing all of it — so a « Déraccorder » that
     * helpfully replaced an unreadable file with a fresh one containing
     * nothing but a cleared Drive token would turn a bad day into an
     * unrecoverable site. It says so instead.
     */
    public function testItRefusesToRewriteASecretsFileItCannotRead(): void
    {
        $this->connection->saveCredentials('client-id-42', 'client-secret-42');
        $before = (string) file_get_contents($this->base . '/config/secrets.enc');

        file_put_contents($this->base . '/config/secrets.enc', 'des octets qui ne veulent rien dire');

        try {
            $this->connection->disconnect();
            $this->fail('An unreadable secrets file was overwritten.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('Rien n\'a été modifié', $e->getMessage());
            $this->assertFalse($e->needsReauthorisation);
        }

        $this->assertSame(
            'des octets qui ne veulent rien dire',
            (string) file_get_contents($this->base . '/config/secrets.enc'),
            'the corrupted file was replaced, taking every other secret with it'
        );
        $this->assertNotSame('', $before);
    }

    private function realDbConnection(): Connection
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $name = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        $connection = new Connection($host, $port, $name, $user, $password);
        if ($connection->testConnection() !== true) {
            $this->markTestSkipped('No database available for this test.');
        }

        return $connection;
    }
}
