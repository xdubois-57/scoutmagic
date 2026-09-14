<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend\Drive;

use Core\Storage\Location\Backend\Drive\GoogleDriveClient;
use Core\Storage\Location\Backend\Drive\DriveAccessException;
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
        } catch (DriveAccessException $e) {
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
        } catch (DriveAccessException $e) {
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
     *
     * **The loop that walks a file moved out of this class in IT-05.** It
     * lives in {@see \Core\Storage\Location\Backend\GoogleDriveBackend}
     * now, behind the resumable-upload contract every backend keeps, which
     * is what lets a safety copy read a slice from a bucket and append it
     * here. So what this test pins is one piece and the answer to it.
     */
    public function testAPieceGoogleAcceptsWithMoreToComeIsProgressAndNotAFailure(): void
    {
        $chunk = str_repeat('x', 1024);
        $ranges = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers) use (&$ranges): array {
            $ranges[] = $headers['Content-Range'] ?? '';

            return ['status' => 308, 'body' => '', 'range' => 'bytes=0-2047'];
        });

        $upload = $client->sendChunk('https://upload.example/session-1', $chunk, 1024, 8192);

        $this->assertFalse($upload->isComplete());
        $this->assertSame(2048, $upload->offset);
        $this->assertSame(['bytes 1024-2047/8192'], $ranges);
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
    public function testAShortCommitIsReportedAsWhatGoogleKeptRatherThanAsWhatWasSent(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 308,
            'body' => '',
            // Sent 4096 from zero; Google kept 512 of them and says so.
            'range' => 'bytes=0-511',
        ]);

        $upload = $client->sendChunk('https://upload.example/session-1', str_repeat('x', 4096), 0, 8192);

        $this->assertSame(512, $upload->offset, 'the caller would have resumed past a hole');
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
        $client = $this->clientAnswering(fn (): array => ['status' => 308, 'body' => '']);

        try {
            $client->sendChunk('https://upload.example/session-1', str_repeat('x', 4096), 0, 8192);
            $this->fail('A 308 that reported no committed range was treated as progress.');
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('aucun octet', $e->getMessage());
        }
    }

    /**
     * A 308 that reports no progress at all would otherwise loop for
     * ever, re-sending the same chunk against a server that keeps none
     * of it.
     */
    public function testAContinueThatCommittedNothingIsRefusedRatherThanRetriedForEver(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 308, 'body' => '', 'range' => 'bytes=0-0']);

        try {
            $client->sendChunk('https://upload.example/session-1', str_repeat('x', 4096), 1024, 8192);
            $this->fail('An upload making no progress was accepted.');
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('aucun octet', $e->getMessage());
        }
    }

    /** And a piece that reaches the end reports the id, not an offset. */
    public function testThePieceThatFinishesCarriesTheIdThePurgeWillDeleteBy(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{"id":"drive-file-42"}']);

        $upload = $client->sendChunk('https://upload.example/s1', 'last bytes', 90, 100);

        $this->assertTrue($upload->isComplete());
        $this->assertSame('drive-file-42', $upload->fileId);
    }

    /**
     * Google accepting the last piece without naming the file is a
     * success this application cannot use: the retention deletes by name,
     * but the promotion that follows de-duplicates by identifier, and an
     * empty one would leave two files under one key.
     */
    public function testAPieceAcceptedWithoutAnIdentifierIsRefused(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{}']);

        try {
            $client->sendChunk('https://upload.example/s1', 'last bytes', 90, 100);
            $this->fail('An upload with no identifier was accepted.');
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('identifiant', $e->getMessage());
        }
    }

    public function testAPieceGoogleRefusesStopsTheUpload(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 500,
            'body' => '{"error":{"message":"Backend Error"}}',
        ]);

        try {
            $client->sendChunk('https://upload.example/s1', 'bytes', 0, 5);
            $this->fail('A refused chunk was accepted.');
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('envoi vers Google Drive', $e->getMessage());
        }
    }

    /**
     * A session Google opened without saying where to send the bytes.
     * There is nowhere to `PUT` to, and guessing would be writing to an
     * address the archive's author could otherwise choose.
     */
    public function testAnUploadSessionWithoutAnAddressIsRefused(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{}']);

        try {
            $client->beginUpload('token', 'folder', 'a.zip', 10, 'application/zip');
            $this->fail('An upload session with no location was accepted.');
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('où envoyer', $e->getMessage());
        }
    }

    public function testAnUploadGoogleWillNotEvenBeginIsRefusedBeforeAnyByteIsSent(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 403, 'body' => '{"error":{"message":"nope"}}']);

        try {
            $client->beginUpload('token', 'folder', 'a.zip', 10, 'application/zip');
            $this->fail('An upload Google refused to start was accepted.');
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('commencer l\'envoi', $e->getMessage());
        }
    }

    /**
     * **The session announces the media type the caller asked for**, and
     * not `application/zip` for everything.
     *
     * It used to be hard-coded, which was true of the one caller there
     * was. A Drive folder is a storage location now: what goes into it is
     * whatever a consumer stores, and an operator's own Drive listing
     * every one of their files as a zip archive is a small lie told on
     * every row.
     */
    public function testTheSessionAnnouncesTheMediaTypeItWasGiven(): void
    {
        $announced = '';
        $client = $this->clientAnswering(function (string $method, string $url, array $headers) use (&$announced): array {
            $announced = $headers['X-Upload-Content-Type'] ?? '';

            return ['status' => 200, 'body' => '{}', 'location' => 'https://upload.example/s1'];
        });

        $client->beginUpload('token', 'folder', 'photo.jpg', 4096, 'image/jpeg');

        $this->assertSame('image/jpeg', $announced);
    }

    /**
     * A small object goes in ONE request, metadata and bytes together.
     *
     * The witness file a connection test writes is a few hundred bytes,
     * and paying a resumable session for it — open, send, de-duplicate —
     * is three round trips to prove one.
     */
    public function testASmallObjectIsWrittenInASingleMultipartRequest(): void
    {
        $requests = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
            $requests[] = ['url' => $url, 'type' => $headers['Content-Type'] ?? '', 'body' => (string) $body];

            return ['status' => 200, 'body' => '{"id":"drive-small-1"}'];
        });

        $id = $client->uploadContents('token', 'folder-1', 'temoin.txt', 'bonjour', 'text/plain');

        $this->assertSame('drive-small-1', $id);
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('uploadType=multipart', $requests[0]['url']);
        $this->assertStringStartsWith('multipart/related; boundary=', $requests[0]['type']);
        $this->assertStringContainsString('"name":"temoin.txt"', $requests[0]['body']);
        $this->assertStringContainsString('bonjour', $requests[0]['body']);
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
            $client->listPage('token', 'folder', null, 100);
            $this->fail('A full-drive refusal was accepted.');
        } catch (DriveAccessException $e) {
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
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('Révoquez', $e->getMessage());
        }
    }

    /**
     * The listing is what the retention decides from, so every field it
     * decides on is read: the key it deletes by, the size it budgets
     * with, and the remote clock's own timestamp — never re-parsed into
     * this server's timezone, because a comparison between two clocks
     * that disagree deletes the wrong file.
     */
    public function testAListingCarriesTheFieldsARetentionWouldDecideFrom(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 200,
            'body' => (string) json_encode(['files' => [
                [
                    'id' => 'f-2',
                    'name' => 'sauvegarde-2.zip',
                    'size' => '2048',
                    'md5Checksum' => 'D41D8CD98F00B204E9800998ECF8427E',
                    'modifiedTime' => '2026-09-02T03:00:00.000Z',
                ],
                ['id' => 'f-1', 'name' => 'sauvegarde-1.zip', 'size' => '1024', 'modifiedTime' => '2026-09-01T03:00:00.000Z'],
                // Something the API answered that is not a file: skipped
                // rather than turned into an object with an empty key,
                // which a delete would then aim at nothing.
                ['id' => 'sans-nom'],
            ]]),
        ]);

        $page = $client->listPage('token', 'folder-1', null, 100);

        $this->assertCount(2, $page['objects']);
        $this->assertSame('sauvegarde-2.zip', $page['objects'][0]->key);
        $this->assertSame(2048, $page['objects'][0]->sizeBytes);
        $this->assertSame('2026-09-02T03:00:00.000Z', $page['objects'][0]->lastModifiedAt);
        $this->assertNull($page['cursor'], 'a single page reported more to come');
    }

    /**
     * **Drive's `md5Checksum` really is an MD5**, lower-cased so a
     * comparison with one computed while reading the source cannot fail
     * on the case of its letters alone.
     *
     * This is the difference from S3 worth pinning: an ETag equals an MD5
     * only for a single-part upload, so the object store announces
     * nothing. Drive computes it over the whole file whatever the upload
     * was cut into, which is what makes a verified copy possible here.
     */
    public function testTheAnnouncedChecksumIsAnMd5AndIsComparable(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 200,
            'body' => (string) json_encode(['files' => [
                ['id' => 'f-1', 'size' => '3', 'md5Checksum' => 'D41D8CD98F00B204E9800998ECF8427E'],
            ]]),
        ]);

        $file = $client->findFile('token', 'folder-1', 'a.zip');

        $this->assertNotNull($file);
        $this->assertSame('d41d8cd98f00b204e9800998ecf8427e', $file['checksum']);
    }

    /**
     * A Google-native document announces no checksum, and the honest
     * answer to « what is its digest » is nothing — never the empty
     * string, which a comparison would read as a value and call corrupt.
     */
    public function testAFileWithoutAChecksumAnnouncesNoneRatherThanAnEmptyOne(): void
    {
        $client = $this->clientAnswering(fn (): array => [
            'status' => 200,
            'body' => '{"files":[{"id":"f-1","size":"3"}]}',
        ]);

        $file = $client->findFile('token', 'folder-1', 'a.zip');

        $this->assertNotNull($file);
        $this->assertNull($file['checksum']);
    }

    /**
     * **A listing hands the cursor back rather than walking the folder
     * itself**, which is what every backend's `list()` contract requires.
     *
     * The loop that used to live here moved to the callers that run under
     * a time budget and must be able to stop between two pages — the
     * safety copy of IT-04, and the retention purge. What must not happen
     * is this answering « that is all there is » when Drive said
     * otherwise: the files a first-page-only reader never sees are the
     * OLDEST, which is precisely what a purge exists to delete, so the
     * account would fill up while the purge found nothing to do.
     */
    public function testAListingReportsThatThereIsAnotherPageRatherThanSwallowingIt(): void
    {
        $urls = [];
        $client = $this->clientAnswering(function (string $method, string $url) use (&$urls): array {
            $urls[] = $url;

            return str_contains($url, 'pageToken=suite')
                ? ['status' => 200, 'body' => '{"files":[{"id":"vieux-1","name":"a.zip","size":"1"}]}']
                : ['status' => 200, 'body' => (string) json_encode([
                    'nextPageToken' => 'suite',
                    'files' => [['id' => 'recent-1', 'name' => 'b.zip', 'size' => '2']],
                ])];
        });

        $first = $client->listPage('token', 'folder-1', null, 100);
        $this->assertSame('suite', $first['cursor']);
        $this->assertSame(['b.zip'], array_map(static fn ($o) => $o->key, $first['objects']));

        $second = $client->listPage('token', 'folder-1', $first['cursor'], 100);
        $this->assertStringContainsString('pageToken=suite', $urls[1]);
        $this->assertNull($second['cursor']);
        $this->assertSame(['a.zip'], array_map(static fn ($o) => $o->key, $second['objects']));
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

        $this->expectException(DriveAccessException::class);
        $client->ensureFolder('token', 'ScoutMagic');
    }

    public function testATokenResponseWithoutAnAccessTokenIsRefused(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => '{"expires_in":3599}']);

        try {
            $client->refreshAccessToken('c', 's', 'r');
            $this->fail('A token response with no token was accepted.');
        } catch (DriveAccessException $e) {
            $this->assertStringContainsString('jeton d\'accès', $e->getMessage());
        }
    }

    public function testAnUnreadableAnswerIsRefusedRatherThanGuessedAt(): void
    {
        $client = $this->clientAnswering(fn (): array => ['status' => 200, 'body' => 'not json at all']);

        try {
            $client->about('token');
            $this->fail('An unreadable answer was accepted.');
        } catch (DriveAccessException $e) {
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
        } catch (DriveAccessException $e) {
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
        } catch (DriveAccessException $e) {
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
     * the resumable upload's own docblock names as the case it exists
     * for: every chunk of every backup would have timed out
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
     * **Resuming sends from the offset it was given**, which is the whole
     * point of the destination being asked where it stands.
     */
    public function testAPieceIsSentAtTheOffsetItWasGivenRatherThanFromTheStart(): void
    {
        $ranges = [];
        $client = $this->clientAnswering(function (string $method, string $url, array $headers) use (&$ranges): array {
            $ranges[] = $headers['Content-Range'] ?? '';

            return ['status' => 200, 'body' => '{"id":"f"}'];
        });

        $chunk = GoogleDriveClient::UPLOAD_CHUNK_BYTES;
        $client->sendChunk('https://upload.example/s1', str_repeat('x', $chunk), $chunk * 2, $chunk * 3);

        $this->assertSame(
            [sprintf('bytes %d-%d/%d', $chunk * 2, ($chunk * 3) - 1, $chunk * 3)],
            $ranges,
            'a resumed upload started over from zero'
        );
    }

    /**
     * Cancelling a session that will never be finished **never throws**.
     *
     * Its one caller is already reporting a failure — a copy abandoned, a
     * transfer that disagreed with its source — and a failure to tidy up
     * must not replace the failure actually being reported.
     */
    public function testCancellingASessionSwallowsWhateverGoogleAnswers(): void
    {
        $methods = [];
        $client = $this->clientAnswering(function (string $method) use (&$methods): array {
            $methods[] = $method;

            throw new \RuntimeException('the network is gone');
        });

        $client->cancelUpload('https://upload.example/s1');

        $this->assertSame(['DELETE'], $methods);
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
