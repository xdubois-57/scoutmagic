<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Maintenance\Remote\GoogleDriveClient;
use Core\Maintenance\Remote\RemoteBackupException;
use PHPUnit\Framework\TestCase;

/**
 * The conversation with Google, without Google.
 *
 * **There is no honest way to test this against the real service.** It
 * would need a Google account, a cloud project, a published consent
 * screen and a human pressing « Autoriser » — which is to say it would
 * need a test nobody could run, and a test nobody runs is a test that
 * does not exist. The transport closure is the seam
 * `Modules\SosStaff\Provider\Ovh\OvhApiClient` established for exactly
 * this, and every case below drives it.
 *
 * What is asserted is what this class *decides*: which URL, which
 * parameters, which status means "keep going", and which refusal means
 * "the operator has to come back and reconnect".
 */
final class GoogleDriveClientTest extends TestCase
{
    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    /**
     * **The scope, asserted because it is a security decision and not a
     * detail.**
     *
     * `drive.file` reaches only files this application created.
     * Widening it to `drive` would hand a scout unit's whole Google Drive
     * to their website — and would also make the feature unusable, since
     * `drive` is a sensitive scope and Google then demands a paid annual
     * security assessment. A change to this line is a change that has to
     * be argued for, which is what a failing test is for.
     */
    public function testTheAuthorisationUrlAsksForTheNarrowScopeAndNothingWider(): void
    {
        $url = (new GoogleDriveClient())->authorizationUrl('client-123', 'https://unite.example/cb', 'st4te');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('https://www.googleapis.com/auth/drive.file', $query['scope'] ?? null);
        $this->assertStringStartsWith('https://accounts.google.com/', $url);
        $this->assertSame('client-123', $query['client_id'] ?? null);
        $this->assertSame('https://unite.example/cb', $query['redirect_uri'] ?? null);
        $this->assertSame('st4te', $query['state'] ?? null);
    }

    /**
     * `access_type=offline` and `prompt=consent`, together.
     *
     * Without the first, Google returns an access token that dies in an
     * hour — useless to a site that backs up at three in the morning.
     * Without the second, an operator reconnecting an account they had
     * already authorised gets no refresh token at all, and nothing in the
     * response says why.
     */
    public function testItAsksForAGrantThatOutlivesTheBrowserSession(): void
    {
        $url = (new GoogleDriveClient())->authorizationUrl('c', 'https://u.example/cb', 's');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame('consent', $query['prompt'] ?? null);
    }

    public function testAnExpiredAccessTokenIsRefreshedFromTheStoredRefreshToken(): void
    {
        $seen = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers, ?string $body) use (&$seen): array {
            $seen[] = ['method' => $method, 'url' => $url, 'body' => $body];

            return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'ya29.fresh', 'expires_in' => 3599])];
        });

        $fresh = $client->refreshAccessToken('client-1', 'secret-1', 'refresh-1');

        $this->assertSame('ya29.fresh', $fresh['access_token']);
        $this->assertSame(3599, $fresh['expires_in']);
        $this->assertCount(1, $seen);
        $this->assertSame('POST', $seen[0]['method']);
        $this->assertSame('https://oauth2.googleapis.com/token', $seen[0]['url']);
        parse_str((string) $seen[0]['body'], $form);
        $this->assertSame('refresh_token', $form['grant_type'] ?? null);
        $this->assertSame('refresh-1', $form['refresh_token'] ?? null);
    }

    /**
     * **A withdrawn grant is a state, not a network error.**
     *
     * Google answers `invalid_grant` both when the operator revoked the
     * access from their Google account and when the seven-day clock of a
     * testing consent screen ran out. Either way the site cannot fix it by
     * retrying, and the operator has to reconnect — so the exception has
     * to carry that distinction rather than read like any other failure.
     */
    public function testARevokedRefreshTokenIsReportedAsSomethingToReconnectRatherThanRetry(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 400,
            'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}',
        ]);

        try {
            $client->refreshAccessToken('c', 's', 'dead-token');
            $this->fail('A revoked refresh token was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertTrue($e->needsReauthorisation);
            $this->assertStringContainsString('Reconnectez', $e->getMessage());
            // Google's own English, and the word "Token", stay out of the
            // sentence a human reads — they travel as the cause.
            $this->assertStringNotContainsString('invalid_grant', $e->getMessage());
            $this->assertStringContainsString('invalid_grant', (string) $e->getPrevious()?->getMessage());
        }
    }

    /** A 401 on the API says the same thing, and must read the same way. */
    public function testAnUnauthorisedApiCallIsAlsoSomethingToReconnect(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 401, 'body' => '{"error":{"message":"Invalid Credentials"}}']);

        try {
            $client->about('stale-access-token');
            $this->fail('A 401 was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertTrue($e->needsReauthorisation);
        }
    }

    /**
     * An account with no quota at all answers by leaving `limit` OUT,
     * and reading that absence as zero would tell somebody with unlimited
     * storage that their disk is full.
     */
    public function testAnAccountWithoutAQuotaIsReportedAsHavingNoneRatherThanNoRoom(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 200,
            'body' => (string) json_encode([
                'user' => ['emailAddress' => 'unite@example.org'],
                'storageQuota' => ['usage' => '12345'],
            ]),
        ]);

        $about = $client->about('token');

        $this->assertSame('unite@example.org', $about['account']);
        $this->assertNull($about['quota']);
    }

    public function testAQuotaIsReadInBytes(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 200,
            'body' => (string) json_encode([
                'user' => ['emailAddress' => 'unite@example.org'],
                'storageQuota' => ['usage' => '400', 'limit' => '1000'],
            ]),
        ]);

        $quota = $client->about('token')['quota'];

        $this->assertNotNull($quota);
        $this->assertSame(400, $quota->usedBytes);
        $this->assertSame(1000, $quota->limitBytes);
        $this->assertSame(600, $quota->freeBytes());
    }

    /**
     * **308 is Google saying « continuez », not an error.**
     *
     * It is the answer to every piece of a resumable upload but the last,
     * and it sits outside the 2xx range — so a client that treats
     * "not 2xx" as failure cannot upload anything larger than one chunk,
     * which is every backup this feature exists for.
     */
    public function testALargeUploadIsSentInPiecesAndTheContinueStatusIsNotAFailure(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sm_up_');
        $this->assertIsString($path);
        // Two chunks and a bit: the chunk size is eight mebibytes.
        file_put_contents($path, str_repeat('x', 20 * 1024 * 1024));

        $ranges = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers, ?string $body) use (&$ranges): array {
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/session-1'];
            }
            $ranges[] = $headers['Content-Range'] ?? '';

            return str_contains($headers['Content-Range'] ?? '', '/20971520')
                && str_starts_with($headers['Content-Range'] ?? '', 'bytes 16777216-')
                ? ['status' => 200, 'body' => '{"id":"drive-file-9"}']
                : ['status' => 308, 'body' => ''];
        });

        $id = $client->uploadFile('token', 'folder-1', $path, 'sauvegarde.zip');
        @unlink($path);

        $this->assertSame('drive-file-9', $id);
        $this->assertSame([
            'bytes 0-8388607/20971520',
            'bytes 8388608-16777215/20971520',
            'bytes 16777216-20971519/20971520',
        ], $ranges);
    }

    /**
     * Deleting something that is already gone is a success: the caller
     * asked for it not to be there.
     */
    public function testDeletingAFileThatIsAlreadyGoneIsNotAFailure(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 404, 'body' => '{"error":{"message":"File not found"}}']);

        $client->deleteFile('token', 'already-deleted');

        // Reached at all, so it did not throw.
        $this->assertTrue(true);
    }

    public function testAFullDriveIsSaidToBeFullRatherThanRefusedGenerically(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 403,
            'body' => '{"error":{"errors":[{"reason":"storageQuotaExceeded"}]}}',
        ]);

        try {
            $client->listFiles('token', 'folder');
            $this->fail('A full-drive refusal was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertFalse($e->needsReauthorisation);
            $this->assertStringContainsString('espace libre', $e->getMessage());
        }
    }

    public function testTheFolderIsReusedWhenThisApplicationAlreadyCreatedOne(): void
    {
        $calls = 0;
        $client = $this->clientAnswering(function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => '{"files":[{"id":"folder-existing"}]}'];
        });

        $this->assertSame('folder-existing', $client->ensureFolder('token', 'ScoutMagic'));
        $this->assertSame(1, $calls, 'a second request was made to create a folder that already existed');
    }

    /**
     * An exchange that comes back without a refresh token is refused with
     * the one instruction that actually resolves it.
     *
     * It happens when Google recognises a grant the operator gave before —
     * `prompt=consent` exists to prevent it, and storing the empty string
     * instead would produce a site that believes it is connected and
     * discovers otherwise a week later.
     */
    public function testAnExchangeWithoutARefreshTokenIsRefusedWithSomethingToDo(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 200,
            'body' => '{"access_token":"ya29.x","expires_in":3599}',
        ]);

        try {
            $client->exchangeCode('c', 's', 'https://u.example/cb', 'code-1');
            $this->fail('An exchange without a refresh token was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('Révoquez', $e->getMessage());
        }
    }

    /**
     * The listing is what IT-09's remote retention will decide from, so
     * every field it decides on is read: the id it deletes by, the size
     * it budgets with, and the remote clock's own timestamp — never
     * re-parsed into this server's timezone, because a comparison between
     * two clocks that disagree deletes the wrong file.
     */
    public function testAListingCarriesTheFieldsARetentionWouldDecideFrom(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 200,
            'body' => (string) json_encode(['files' => [
                ['id' => 'f-2', 'name' => 'sauvegarde-2.zip', 'size' => '2048', 'createdTime' => '2026-09-02T03:00:00.000Z'],
                ['id' => 'f-1', 'name' => 'sauvegarde-1.zip', 'size' => '1024', 'createdTime' => '2026-09-01T03:00:00.000Z'],
                // Something the API answered that is not a file: skipped
                // rather than turned into a RemoteFile with an empty id,
                // which a delete would then aim at nothing.
                ['name' => 'sans identifiant'],
            ]]),
        ]);

        $files = $client->listFiles('token', 'folder-1');

        $this->assertCount(2, $files);
        $this->assertSame('f-2', $files[0]->id);
        $this->assertSame('sauvegarde-2.zip', $files[0]->name);
        $this->assertSame(2048, $files[0]->sizeBytes);
        $this->assertSame('2026-09-02T03:00:00.000Z', $files[0]->createdAt);
    }

    public function testTheFolderIsCreatedWhenThisApplicationHasNoneYet(): void
    {
        $methods = [];
        $client = $this->clientAnswering(function (string $method) use (&$methods): array {
            $methods[] = $method;

            return $method === 'POST'
                ? ['status' => 200, 'body' => '{"id":"folder-new"}']
                : ['status' => 200, 'body' => '{"files":[]}'];
        });

        $this->assertSame('folder-new', $client->ensureFolder('token', 'ScoutMagic'));
        $this->assertSame(['GET', 'POST'], $methods);
    }

    public function testAFolderCreationThatAnswersWithoutAnIdentifierIsRefused(): void
    {
        $client = $this->clientAnswering(fn (string $method): array => $method === 'POST'
            ? ['status' => 200, 'body' => '{}']
            : ['status' => 200, 'body' => '{"files":[]}']);

        $this->expectException(RemoteBackupException::class);
        $client->ensureFolder('token', 'ScoutMagic');
    }

    public function testAnUploadGoogleWillNotEvenBeginIsRefusedBeforeAnyByteIsSent(): void
    {
        $path = $this->fileOf('x');
        $client = $this->clientAnswering(fn (): array => ['status' => 403, 'body' => '{"error":{"message":"nope"}}']);

        try {
            $client->uploadFile('token', 'folder', $path, 'a.zip');
            $this->fail('An upload Google refused to start was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('commencer l\'envoi', $e->getMessage());
        }
    }

    /**
     * A session Google opened without saying where to send the bytes.
     * There is nowhere to `PUT` to, and guessing would be writing to an
     * address the archive's author could otherwise choose.
     */
    public function testAnUploadSessionWithoutAnAddressIsRefused(): void
    {
        $path = $this->fileOf('x');
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{}']);

        try {
            $client->uploadFile('token', 'folder', $path, 'a.zip');
            $this->fail('An upload session with no location was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('où envoyer', $e->getMessage());
        }
    }

    public function testAFileThatIsNotThereIsRefusedWithoutContactingGoogle(): void
    {
        $calls = 0;
        $client = $this->clientAnswering(function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => '{}'];
        });

        try {
            $client->uploadFile('token', 'folder', sys_get_temp_dir() . '/absent_' . uniqid(), 'a.zip');
            $this->fail('A missing file was sent.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('introuvable', $e->getMessage());
        }
        $this->assertSame(0, $calls, 'Google was contacted about a file this server does not have');
    }

    /**
     * Google accepting the last piece without naming the file is a
     * success this application cannot use: IT-09's retention deletes by
     * identifier, and an empty one would delete nothing while reading as
     * a completed send.
     */
    public function testAnUploadAcceptedWithoutAnIdentifierIsRefused(): void
    {
        $path = $this->fileOf('some bytes');
        $client = $this->clientAnswering(fn (string $method): array => $method === 'POST'
            ? ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1']
            : ['status' => 200, 'body' => '{}']);

        try {
            $client->uploadFile('token', 'folder', $path, 'a.zip');
            $this->fail('An upload with no identifier was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('identifiant', $e->getMessage());
        }
    }

    public function testAChunkGoogleRefusesStopsTheUpload(): void
    {
        $path = $this->fileOf('some bytes');
        $client = $this->clientAnswering(fn (string $method): array => $method === 'POST'
            ? ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1']
            : ['status' => 500, 'body' => '{"error":{"message":"Backend Error"}}']);

        try {
            $client->uploadFile('token', 'folder', $path, 'a.zip');
            $this->fail('A refused chunk was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('envoi vers Google Drive', $e->getMessage());
        }
    }

    public function testATokenResponseWithoutAnAccessTokenIsRefused(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{"expires_in":3599}']);

        try {
            $client->refreshAccessToken('c', 's', 'r');
            $this->fail('A token response with no token was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('jeton d\'accès', $e->getMessage());
        }
    }

    public function testAnUnreadableAnswerIsRefusedRatherThanGuessedAt(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => 'not json at all']);

        try {
            $client->about('token');
            $this->fail('An unreadable answer was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('illisible', $e->getMessage());
        }
    }

    private function fileOf(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sm_gd_');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @param \Closure(string, string, array<string, string>, ?string): array{status: int, body: string, location?: string} $answer
     */
    private function clientAnswering(\Closure $answer): GoogleDriveClient
    {
        return new GoogleDriveClient($answer);
    }
}
