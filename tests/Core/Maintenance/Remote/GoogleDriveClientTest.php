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
        // Two chunks and a bit, whatever the chunk size happens to be.
        // Through fileOf(), so tearDown() removes the bytes whatever
        // happens — an upload that throws half way would otherwise leave
        // them behind on every failing run.
        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $size = ($chunk * 2) + ($chunk / 2);
        $path = $this->fileOf(str_repeat('x', (int) $size));

        $ranges = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers, ?string $body) use (&$ranges, $chunk): array {
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/session-1'];
            }
            $ranges[] = $headers['Content-Range'] ?? '';

            return str_starts_with($headers['Content-Range'] ?? '', 'bytes ' . ($chunk * 2) . '-')
                ? ['status' => 200, 'body' => '{"id":"drive-file-9"}']
                : ['status' => 308, 'body' => '', 'range' => 'bytes=0-' . (($chunk * count($ranges)) - 1)];
        });

        $id = $client->uploadFile('token', 'folder-1', $path, 'sauvegarde.zip');

        $this->assertSame('drive-file-9', $id);
        $this->assertSame([
            sprintf('bytes 0-%d/%d', $chunk - 1, $size),
            sprintf('bytes %d-%d/%d', $chunk, ($chunk * 2) - 1, $size),
            sprintf('bytes %d-%d/%d', $chunk * 2, $size - 1, $size),
        ], $ranges);
    }

    /**
     * **Where to continue from is Google's to say.**
     *
     * A 308 is allowed to report fewer bytes committed than were sent,
     * in a `Range: bytes=0-N` header. Advancing by the length this client
     * happened to write assumes an answer instead of reading it — and a
     * short commit would then leave a hole that every later chunk widens.
     * The archive uploads, Google accepts it, and it is unreadable on the
     * day somebody needs it: the worst failure shape a backup has, which
     * is silence.
     */
    public function testAShortCommitIsResumedFromWhereGoogleSaysRatherThanFromWhatWasSent(): void
    {
        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $size = $chunk * 6;
        $path = $this->fileOf(str_repeat('x', $size));

        $ranges = [];
        $answered = 0;
        $client = $this->clientAnswering(function (string $method, string $url, array $headers) use (&$ranges, &$answered, $chunk): array {
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/session-1'];
            }
            $ranges[] = $headers['Content-Range'] ?? '';
            $answered++;

            // Google keeps only the first eighth of that chunk, and says so.
            return $answered === 1
                ? ['status' => 308, 'body' => '', 'range' => 'bytes=0-' . (((int) ($chunk / 8)) - 1)]
                : ['status' => 200, 'body' => '{"id":"drive-file-7"}'];
        });

        $id = $client->uploadFile('token', 'folder-1', $path, 'sauvegarde.zip');

        $this->assertSame('drive-file-7', $id);
        $kept = (int) ($chunk / 8);
        $this->assertSame([
            sprintf('bytes 0-%d/%d', $chunk - 1, $size),
            // Resumed at what Google KEPT, not at what this client sent.
            sprintf('bytes %d-%d/%d', $kept, $kept + $chunk - 1, $size),
        ], $ranges);
    }

    /**
     * **A 308 that names no `Range` kept nothing**, and assuming
     * otherwise corrupts the archive silently.
     *
     * The protocol lets Google commit fewer bytes than were sent and say
     * how many; a silent answer is the limit case of that, not permission
     * to believe the chunk landed. Advancing by what was written would
     * leave a hole in the middle of a backup that every later chunk
     * widens — an archive that uploads, is accepted, and is unreadable on
     * the one day it matters. A refusal instead costs a run, and the next
     * one probes and resumes from where the destination really is.
     */
    public function testAContinueWithoutARangeHeaderIsRefusedRatherThanAssumedComplete(): void
    {
        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $size = $chunk * 6;
        $path = $this->fileOf(str_repeat('x', $size));

        $ranges = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers) use (&$ranges): array {
            if ($method === 'POST') {
                return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/session-1'];
            }
            $ranges[] = $headers['Content-Range'] ?? '';

            return ['status' => 308, 'body' => ''];
        });

        try {
            $client->uploadFile('token', 'folder-1', $path, 'sauvegarde.zip');
            $this->fail('A 308 that reported no committed range was treated as progress.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('aucun octet', $e->getMessage());
        }

        $this->assertSame(
            [sprintf('bytes 0-%d/%d', $chunk - 1, $size)],
            $ranges,
            'the upload carried on past a chunk the destination never acknowledged'
        );
    }

    /**
     * A 308 that reports no progress at all would otherwise loop for
     * ever, re-sending the same chunk against a server that keeps none
     * of it.
     */
    public function testAContinueThatCommittedNothingIsRefusedRatherThanRetriedForEver(): void
    {
        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $size = $chunk * 6;
        $path = $this->fileOf(str_repeat('x', $size));

        $client = $this->clientAnswering(fn (string $method): array => $method === 'POST'
            ? ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/session-1']
            : ['status' => 308, 'body' => '', 'range' => 'bytes=0-0']);

        try {
            $client->uploadFile('token', 'folder-1', $path, 'sauvegarde.zip');
            $this->fail('An upload making no progress was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('aucun octet', $e->getMessage());
        }
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

    /**
     * **A listing is every page of it, and the reason is the purge.**
     *
     * Drive answers a folder one page at a time and says so with
     * `nextPageToken`. A caller reading only the first page sees the
     * hundred newest files and takes that for the whole folder — and
     * because the listing is ordered newest-first, the files it never
     * sees are exactly the oldest ones, which is precisely what IT-09's
     * retention exists to delete. The account would fill up while the
     * purge found nothing to remove.
     */
    public function testAListingFollowsEveryPageRatherThanStoppingAtTheFirst(): void
    {
        $urls = [];
        $client = $this->clientAnswering(function (string $method, string $url) use (&$urls): array {
            $urls[] = $url;

            return str_contains($url, 'pageToken=suite')
                ? ['status' => 200, 'body' => (string) json_encode(['files' => [
                    ['id' => 'vieux-1', 'name' => 'a.zip', 'size' => '1', 'createdTime' => '2025-01-01T00:00:00.000Z'],
                ]])]
                : ['status' => 200, 'body' => (string) json_encode([
                    'nextPageToken' => 'suite',
                    'files' => [
                        ['id' => 'recent-1', 'name' => 'b.zip', 'size' => '2', 'createdTime' => '2026-09-01T00:00:00.000Z'],
                    ],
                ])];
        });

        $files = $client->listFiles('token', 'folder-1');

        $this->assertCount(2, $urls, 'the second page was never asked for');
        $this->assertStringContainsString('pageToken=suite', $urls[1]);
        $this->assertSame(['recent-1', 'vieux-1'], array_map(static fn ($file) => $file->id, $files));
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

    /**
     * **A 401 from the TOKEN endpoint is not a revocation**, and reading
     * it as one destroys the grant.
     *
     * RFC 6749 §5.2 spends 401 on `invalid_client` — a client secret that
     * is wrong, or that was rotated in the Google console — while a
     * refresh token that is genuinely dead comes back as 400
     * `invalid_grant`. `GoogleDriveTarget` answers a revocation by calling
     * `markNeedsReauthorisation()`, which deletes the refresh token: so
     * conflating the two means a mistyped secret erases a grant that was
     * still good, through the very button pressed to diagnose it.
     *
     * The sentence has to say which of the two it is, too. « Reconnectez
     * le compte » sends the operator through a full Google consent screen
     * that will not help; the secret is what is wrong.
     */
    public function testAWrongClientSecretIsNotReadAsAWithdrawnAuthorisation(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 401,
            'body' => '{"error":"invalid_client","error_description":"The OAuth client was not found."}',
        ]);

        try {
            $client->refreshAccessToken('client-id', 'wrong-secret', 'refresh-token');
            $this->fail('A refused client secret was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertFalse(
                $e->needsReauthorisation,
                'a wrong client secret would have deleted the refresh token it never invalidated'
            );
            $this->assertStringContainsString('secret client', $e->getMessage());
        }
    }

    /**
     * And a 401 on the API still means exactly what it used to.
     *
     * The pair matters more than either case alone: the fix above must
     * not have bought its precision by making the API side blind.
     */
    public function testA401OnTheApiIsStillAWithdrawnAuthorisation(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 401, 'body' => '{"error":{"message":"Invalid Credentials"}}']);

        try {
            $client->about('token');
            $this->fail('A 401 was accepted.');
        } catch (RemoteBackupException $e) {
            $this->assertTrue($e->needsReauthorisation);
        }
    }

    /**
     * **A request's time budget is sized on the request.**
     *
     * A flat cap on the whole transfer is a bet on the site's upstream,
     * and the number it used to bet on — thirty seconds for a chunk of
     * eight mebibytes — needed 2 Mbps sustained. That is above an
     * ordinary domestic ADSL upstream, which is the link
     * `uploadFile()`'s own docblock names as the case the resumable
     * upload exists for: every chunk of every backup would have timed out
     * on the one code path built to survive that link.
     *
     * The closure around this cannot be exercised — it opens a socket —
     * which is exactly why the rule it applies lives in a method that
     * can.
     */
    public function testTheTimeAllowedForARequestGrowsWithWhatItCarries(): void
    {
        $this->assertSame(30, GoogleDriveClient::transferCeilingSeconds(null));
        $this->assertSame(30, GoogleDriveClient::transferCeilingSeconds('{"name":"sauvegarde.zip"}'));

        $chunk = str_repeat('x', 8 * 1024 * 1024);
        $this->assertGreaterThanOrEqual(
            512,
            GoogleDriveClient::transferCeilingSeconds($chunk),
            'a backup chunk was given less time than the slowest link this client claims to support needs'
        );
    }

    /**
     * **A run that stops on its budget has not failed.**
     *
     * A backup of a whole site does not fit in one request on shared
     * hosting, where `max_execution_time` is thirty to a hundred and
     * twenty seconds. So the ordinary outcome of a run is « some of it
     * went » — and the offset it stops at is what the next run needs.
     * Reporting that as an exception would turn the normal case into an
     * error and lose the progress with it.
     */
    public function testAnUploadThatRunsOutOfTimeReportsHowFarItGotInsteadOfFailing(): void
    {
        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $size = $chunk * 5;
        $path = $this->fileOf(str_repeat('x', $size));

        // Each 308 names what Google kept, which is what the real
        // service does — a silent 308 means it kept NOTHING and is
        // refused (testAContinueWithoutARangeHeaderIsRefusedRatherThan-
        // AssumedComplete).
        $sent = 0;
        $client = $this->clientAnswering(function () use (&$sent, $chunk): array {
            $sent++;

            return ['status' => 308, 'body' => '', 'range' => 'bytes=0-' . (($chunk * $sent) - 1)];
        });

        // Room for two chunks and no more.
        $upload = $client->sendChunks('https://upload.example/s1', $path, $size, 0, static function () use (&$sent): bool {
            return $sent < 2;
        });

        $this->assertFalse($upload->isComplete());
        $this->assertSame($chunk * 2, $upload->offset, 'the next run would resend what this one committed');
        $this->assertSame('https://upload.example/s1', $upload->sessionUrl);
        $this->assertSame(2, $sent, 'the budget did not bound how many chunks were started');
    }

    /** And a run that reaches the end reports the id, not an offset. */
    public function testAnUploadThatFinishesCarriesTheIdThePurgeWillDeleteBy(): void
    {
        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $path = $this->fileOf(str_repeat('x', $chunk));

        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{"id":"drive-file-42"}']);

        $upload = $client->sendChunks('https://upload.example/s1', $path, $chunk, 0, static fn(): bool => true);

        $this->assertTrue($upload->isComplete());
        $this->assertSame('drive-file-42', $upload->fileId);
    }

    /**
     * **Resuming starts from the offset it was given**, which is the
     * whole point of keeping one between runs.
     */
    public function testResumingSendsFromTheStoredOffsetRatherThanFromTheStart(): void
    {
        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $size = $chunk * 3;
        $path = $this->fileOf(str_repeat('x', $size));

        $ranges = [];
        $client = $this->clientAnswering(
            function (string $method, string $url, array $headers) use (&$ranges, $chunk): array {
                $ranges[] = $headers['Content-Range'] ?? '';

                return count($ranges) < 2
                    ? ['status' => 308, 'body' => '', 'range' => 'bytes=0-' . (($chunk * 2) - 1)]
                    : ['status' => 200, 'body' => '{"id":"f"}'];
            }
        );

        $client->sendChunks('https://upload.example/s1', $path, $size, $chunk, static fn(): bool => true);

        $this->assertSame([
            sprintf('bytes %d-%d/%d', $chunk, ($chunk * 2) - 1, $size),
            sprintf('bytes %d-%d/%d', $chunk * 2, $size - 1, $size),
        ], $ranges, 'a resumed upload started over from zero');
    }

    /**
     * **Asking Google where it got to, rather than guessing.**
     *
     * A run killed mid-chunk leaves this application unsure how much
     * arrived. Guessing high skips bytes; guessing low resends them. The
     * empty `PUT` with `Content-Range: bytes * / total` is the protocol's
     * own question, and its answer is authoritative in a way no local
     * bookkeeping can be.
     */
    public function testTheProbeAsksGoogleHowMuchItHoldsAndBelievesTheAnswer(): void
    {
        $asked = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers) use (&$asked): array {
            $asked[] = $headers['Content-Range'] ?? '';

            return ['status' => 308, 'body' => '', 'range' => 'bytes=0-4095'];
        });

        $upload = $client->probeUpload('https://upload.example/s1', 10_000);

        $this->assertSame(['bytes */10000'], $asked);
        $this->assertFalse($upload->isComplete());
        $this->assertSame(4096, $upload->offset);
    }

    /**
     * A probe answering 2xx means the last chunk DID land and only the
     * answer was lost — a resume with nothing left to do, not an error,
     * and certainly not a reason to send the archive a second time.
     */
    public function testAProbeThatFindsTheFileAlreadyCompleteSaysSo(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{"id":"drive-file-9"}']);

        $upload = $client->probeUpload('https://upload.example/s1', 10_000);

        $this->assertTrue($upload->isComplete());
        $this->assertSame('drive-file-9', $upload->fileId);
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
