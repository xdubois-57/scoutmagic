<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Config\SettingService;
use Core\Maintenance\Remote\GoogleDriveClient;
use Core\Maintenance\Remote\GoogleDriveTarget;
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;

/**
 * What the operator gets back when they press « Tester ».
 *
 * The class under test sits between a client that knows HTTP and a
 * connection that knows where the credentials are, so both are stood up
 * for real here: the client with a faked transport, the connection with a
 * real `SecretManager` over a temporary directory and a settings service
 * that keeps its rows in memory. Mocking the connection would leave the
 * one thing that matters untested — that a refused grant CHANGES the
 * site's recorded state, rather than merely being reported once and
 * forgotten by the next page view.
 */
final class GoogleDriveTargetTest extends TestCase
{
    private string $base;
    private RemoteBackupConnection $connection;
    private InMemorySettingService $settings;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/remote_target_' . uniqid();
        @mkdir($this->base . '/keys', 0700, true);
        @mkdir($this->base . '/config', 0700, true);

        $secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $secrets->generateMasterKey();
        $secrets->writeSecrets([
            'remote_backup_client_secret' => 'client-secret-1',
            'remote_backup_refresh_token' => 'refresh-1',
            'remote_backup_account' => 'unite@example.org',
        ]);

        $this->settings = new InMemorySettingService([
            RemoteBackupConnection::CLIENT_ID_SETTING => 'client-1',
            RemoteBackupConnection::FOLDER_SETTING => 'folder-1',
            RemoteBackupConnection::STATE_SETTING => RemoteBackupConnection::STATE_CONNECTED,
        ]);
        $this->connection = new RemoteBackupConnection($this->settings, $secrets);
    }

    protected function tearDown(): void
    {
        foreach (['/keys/master.key', '/config/secrets.enc'] as $file) {
            @unlink($this->base . $file);
        }
        @rmdir($this->base . '/keys');
        @rmdir($this->base . '/config');
        @rmdir($this->base);
    }

    /**
     * **The test writes and deletes, and that is the point.**
     *
     * A destination that answers `about` happily may still refuse every
     * write — a full account, a folder that is gone, a grant narrowed
     * behind the operator's back. An operator who pressed « Tester », saw
     * a green tick and learned otherwise the night their server burned
     * down would have been told nothing at all.
     */
    public function testTheTestActuallyWritesAndThenRemovesAWitnessFile(): void
    {
        $calls = [];
        $target = $this->targetAnswering(function (string $method, string $url) use (&$calls): array {
            $calls[] = $method . ' ' . preg_replace('#\?.*#', '', $url);

            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => '{"access_token":"ya29.ok","expires_in":3599}'];
            }
            if (str_contains($url, '/about')) {
                return ['status' => 200, 'body' => '{"user":{"emailAddress":"unite@example.org"},"storageQuota":{"usage":"10","limit":"100"}}'];
            }
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1'];
            }
            if ($method === 'PUT') {
                return ['status' => 200, 'body' => '{"id":"witness-1"}'];
            }

            return ['status' => 204, 'body' => ''];
        });

        $check = $target->testConnection();

        $this->assertTrue($check->ok, $check->message);
        $this->assertSame('unite@example.org', $check->account);
        $this->assertNotNull($check->quota);
        $this->assertSame(90, $check->quota->freeBytes());
        $this->assertContains('PUT https://upload.example/s1', $calls, 'nothing was written');
        $this->assertContains('DELETE https://www.googleapis.com/drive/v3/files/witness-1', $calls, 'the witness was left behind');
    }

    /**
     * **The witness is removed even when the reporting fails after it.**
     *
     * The docblock promised a `finally` and only the local temporary file
     * was in one; the remote deletion sat in the `try`, so a token that
     * expired between the upload and the delete, or a 503, would leave the
     * witness on the operator's Drive — one more on every press of a
     * button whose whole purpose is troubleshooting.
     */
    public function testTheRemoteWitnessIsRemovedEvenWhenSomethingFailsAfterTheUpload(): void
    {
        $deleted = [];
        $target = $this->targetAnswering(function (string $method, string $url) use (&$deleted): array {
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => '{"access_token":"ya29.ok","expires_in":3599}'];
            }
            if (str_contains($url, '/about')) {
                return ['status' => 200, 'body' => '{"user":{"emailAddress":"u@e.org"},"storageQuota":{}}'];
            }
            if (str_contains($url, '/upload/')) {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1'];
            }
            if ($method === 'PUT') {
                return ['status' => 200, 'body' => '{"id":"witness-1"}'];
            }
            if ($method === 'DELETE') {
                $deleted[] = $url;

                return ['status' => 204, 'body' => ''];
            }

            return ['status' => 200, 'body' => '{"files":[]}'];
        });

        $target->testConnection();

        $this->assertCount(1, $deleted, 'the witness was left on the Drive');
        $this->assertStringEndsWith('/files/witness-1', $deleted[0]);
    }

    /**
     * And a deletion that itself fails does not turn a diagnostic button
     * into an error page: the operator still gets the answer they asked
     * for.
     */
    public function testAWitnessThatCannotBeRemovedStillLetsTheTestReport(): void
    {
        $target = $this->targetAnswering(function (string $method, string $url): array {
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => '{"access_token":"ya29.ok","expires_in":3599}'];
            }
            if (str_contains($url, '/about')) {
                return ['status' => 200, 'body' => '{"user":{"emailAddress":"u@e.org"},"storageQuota":{}}'];
            }
            if (str_contains($url, '/upload/')) {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1'];
            }
            if ($method === 'PUT') {
                return ['status' => 200, 'body' => '{"id":"witness-1"}'];
            }
            if ($method === 'DELETE') {
                return ['status' => 503, 'body' => '{"error":{"message":"Backend Error"}}'];
            }

            return ['status' => 200, 'body' => '{"files":[]}'];
        });

        $check = $target->testConnection();

        $this->assertTrue($check->ok, $check->message);
    }

    /**
     * **A revoked grant leaves the site saying so.**
     *
     * Reporting it once in a flash message and leaving the status block
     * on « Raccordé » would give the operator two answers and no way to
     * choose. The state is written, the token is dropped, and the account
     * is kept — because « reconnectez le compte » is useless if the page
     * no longer says which compte.
     */
    public function testARevokedGrantIsRecordedAsAStateAndNotJustReported(): void
    {
        $target = $this->targetAnswering(fn (): array => [
            'status' => 400,
            'body' => '{"error":"invalid_grant"}',
        ]);

        $check = $target->testConnection();

        $this->assertFalse($check->ok);
        $this->assertTrue($check->needsReauthorisation);
        $this->assertSame(RemoteBackupConnection::STATE_NEEDS_REAUTH, $this->connection->state());
        $this->assertFalse($this->connection->isConnected());
        $this->assertSame('', $this->connection->refreshToken(), 'a dead credential was kept on disk');
        $this->assertSame('unite@example.org', $this->connection->account(), 'the operator is no longer told which account to reconnect');
        $this->assertNotSame('', $this->connection->lastError());
    }

    /**
     * An ordinary failure — the network, a refusal — is reported without
     * declaring the site disconnected: a grant that still works must not
     * be thrown away because Google had a bad minute.
     */
    public function testANetworkFailureDoesNotDeclareTheSiteDisconnected(): void
    {
        $target = $this->targetAnswering(function (string $method, string $url): array {
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => '{"access_token":"ya29.ok","expires_in":3599}'];
            }

            return ['status' => 500, 'body' => '{"error":{"message":"Backend Error"}}'];
        });

        $check = $target->testConnection();

        $this->assertFalse($check->ok);
        $this->assertFalse($check->needsReauthorisation);
        $this->assertSame(RemoteBackupConnection::STATE_CONNECTED, $this->connection->state());
        $this->assertSame('refresh-1', $this->connection->refreshToken());
    }

    /**
     * A site nobody has connected answers « nothing is connected », not
     * an uncaught exception on a page an administrator opened.
     */
    public function testASiteWithNoConnectionAnswersRatherThanThrows(): void
    {
        $this->settings->values[RemoteBackupConnection::STATE_SETTING] = RemoteBackupConnection::STATE_DISCONNECTED;
        $secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $secrets->writeSecrets(['remote_backup_client_secret' => 'client-secret-1']);

        $check = $this->targetAnswering(fn (): array => ['status' => 200, 'body' => '{}'])->testConnection();

        $this->assertFalse($check->ok);
        $this->assertTrue($check->needsReauthorisation);
        $this->assertStringContainsString('Aucun compte', $check->message);
    }

    /**
     * The four methods IT-09 will actually call, through the same stored
     * grant.
     *
     * Nothing in this iteration sends a backup — but the interface is
     * what the scheduled send will depend on, and a method that has never
     * once been exercised is a method whose first run is in production.
     */
    public function testTheDestinationCanBeListedWrittenToAndCleanedUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sm_tgt_');
        $this->assertIsString($path);
        file_put_contents($path, 'des octets');

        $deleted = [];
        $target = $this->targetAnswering(function (string $method, string $url) use (&$deleted): array {
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => '{"access_token":"ya29.ok","expires_in":3599}'];
            }
            if (str_contains($url, '/about')) {
                return ['status' => 200, 'body' => '{"user":{"emailAddress":"u@e.org"},"storageQuota":{"usage":"5","limit":"50"}}'];
            }
            if (str_contains($url, '/upload/')) {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1'];
            }
            if ($method === 'PUT') {
                return ['status' => 200, 'body' => '{"id":"remote-7"}'];
            }
            if ($method === 'DELETE') {
                $deleted[] = $url;

                return ['status' => 204, 'body' => ''];
            }

            return ['status' => 200, 'body' => '{"files":[{"id":"remote-7","name":"s.zip","size":"10","createdTime":"2026-09-01T00:00:00Z"}]}'];
        });

        $this->assertSame('remote-7', $target->upload($path, 'sauvegarde.zip'));

        $listed = $target->list();
        $this->assertCount(1, $listed);
        $this->assertSame('remote-7', $listed[0]->id);

        $quota = $target->quota();
        $this->assertNotNull($quota);
        $this->assertSame(45, $quota->freeBytes());

        $target->delete('remote-7');
        $this->assertCount(1, $deleted);
        $this->assertStringEndsWith('/files/remote-7', $deleted[0]);

        @unlink($path);
    }

    /**
     * A site that has never had a folder gets one made, and the access
     * token is refreshed exactly once for the whole operation rather than
     * per request.
     */
    public function testTheFolderIsCreatedOnFirstUseAndTheTokenIsRefreshedOnlyOnce(): void
    {
        $this->settings->values[RemoteBackupConnection::FOLDER_SETTING] = '';

        $refreshes = 0;
        $target = $this->targetAnswering(function (string $method, string $url) use (&$refreshes): array {
            if (str_contains($url, '/token')) {
                $refreshes++;

                return ['status' => 200, 'body' => '{"access_token":"ya29.ok","expires_in":3599}'];
            }
            if (str_contains($url, '/about')) {
                return ['status' => 200, 'body' => '{"user":{"emailAddress":"u@e.org"},"storageQuota":{}}'];
            }
            if (str_contains($url, '/upload/')) {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1'];
            }
            if ($method === 'PUT') {
                return ['status' => 200, 'body' => '{"id":"witness-1"}'];
            }
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{"id":"folder-created"}'];
            }
            if ($method === 'DELETE') {
                return ['status' => 204, 'body' => ''];
            }

            return ['status' => 200, 'body' => '{"files":[]}'];
        });

        $check = $target->testConnection();

        $this->assertTrue($check->ok, $check->message);
        $this->assertNull($check->quota, 'an account with no declared limit was given one');
        $this->assertSame(1, $refreshes, 'the access token was refreshed more than once for one operation');
    }

    /**
     * @param \Closure(string, string, array<string, string>, ?string): array{status: int, body: string, location?: string} $answer
     */
    private function targetAnswering(\Closure $answer): GoogleDriveTarget
    {
        return new GoogleDriveTarget($this->connection, new GoogleDriveClient($answer));
    }
}

/**
 * The settings table, in an array.
 *
 * `SettingService` is final-ish in spirit but not in fact, and what these
 * tests need from it is two methods over a map. A real one would drag in
 * a repository and a database for rows whose whole content is under this
 * test's control anyway.
 */
final class InMemorySettingService extends SettingService
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
        parent::__construct(new \Core\Config\SettingRepository(new \PDO('sqlite::memory:')));
    }

    public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function setInternal(string $key, string $value, ?string $moduleId = null): void
    {
        $this->values[$key] = $value;
    }
}
