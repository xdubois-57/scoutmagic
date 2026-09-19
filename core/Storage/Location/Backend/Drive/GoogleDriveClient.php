<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend\Drive;

use Core\Storage\Location\StorageQuota;
use Core\Storage\Location\StoredObject;

/**
 * Google Drive over HTTP, by hand.
 *
 * **Why not `google/apiclient`.** What this feature needs from Google is a
 * form-encoded `POST` to refresh a token, a `PUT` carrying a
 * `Content-Range` header, and three `GET`s. The official library brings
 * Guzzle, PSR-7, PSR-18, a service-definition generator and some hundreds
 * of classes to reach that. `ARCHITECTURE.md` §1 asks a dependency to
 * justify itself, and this codebase already answers this exact question
 * three times over — `Core\Maintenance\GitHubReleaseClient`,
 * `Modules\SosStaff\Provider\Ovh\OvhApiClient` and the Anthropic provider
 * in `llm_connector` are all hand-written HTTP clients for third-party
 * APIs. This adds no Composer dependency.
 *
 * **The transport is injectable**, exactly as `OvhApiClient`'s is, and for
 * the same reason: none of this can be exercised against the real service
 * in a test suite — it would need a Google account, a project and a
 * published consent screen. Production leaves it null and gets
 * {@see defaultTransport()}.
 *
 * **What this class does NOT do**: decide anything. It has no idea where
 * the refresh token is kept, when it was last used, or what a backup is.
 * That is {@see \Core\Storage\Location\Backend\GoogleDriveBackend} and
 * {@see \Core\Http\Controller\GoogleDriveConnectionController}.
 *
 * The two transport shapes are named rather than spelled out at each use:
 * written inline they run past the line length this project holds itself
 * to, and the same closure signature appearing twice in slightly
 * different words is how the two drift apart.
 *
 * @phpstan-type DriveCall array{status: int, body: string}
 * @phpstan-type DriveResumableCall array{status: int, body: string, location?: string, range?: string}
 * @phpstan-type DriveTransport \Closure(string, string, array<string, string>, ?string): DriveCall
 * @phpstan-type DriveResumableTransport \Closure(string, string, array<string, string>, ?string): DriveResumableCall
 */
final class GoogleDriveClient
{
    /**
     * **`drive.file`, and never `drive`.**
     *
     * Two separate reasons, either of which would be enough.
     *
     * `drive.file` grants access only to files this application itself
     * created. A unit connecting its personal Google account is handing
     * over a key; this is the difference between a key to one drawer and a
     * key to the house, and the drawer is all a backup destination ever
     * needs.
     *
     * It is also a NON-SENSITIVE scope, which means Google does not
     * require the verification procedure — an annual security assessment
     * costing thousands of euros, which no scout unit will ever undergo.
     * Asking for `drive` would make this feature unusable in practice
     * while also making it far more dangerous, which is a rare combination.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /**
     * How long a refresh token survives while the project's consent screen
     * is still in "Testing".
     *
     * Google expires these after seven days, silently. It is the single
     * most common way an integration of this kind dies: the backups stop
     * after a week and nobody notices until the day they are needed. The
     * screen says so in as many words, and this constant is where that
     * number comes from so the two cannot drift.
     */
    public const TESTING_TOKEN_LIFETIME_DAYS = 7;

    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';
    /**
     * The floor under every request's total budget, in seconds.
     *
     * Enough for any of the small calls — a token, an `about`, a folder
     * lookup, a delete — and never the cap on an upload, which
     * {@see transferCeilingSeconds()} sizes on what is actually being
     * sent.
     */
    private const TIMEOUT = 30;

    /** How long to wait for the connection itself, in seconds. */
    private const CONNECT_TIMEOUT = 15;

    /**
     * The slowest upstream this client is willing to call working, in
     * bytes per second, and how long it tolerates worse before giving up.
     *
     * 16 KiB/s is about 128 kbps — below any link a site is actually
     * served over, and well below the domestic upstream this class was
     * written for. It is not a performance target: it is the line under
     * which a transfer is presumed dead rather than slow, and the number
     * the per-request ceiling is computed from.
     */
    private const MIN_UPLOAD_BYTES_PER_SECOND = 16 * 1024;
    private const STALL_SECONDS = 30;

    /** Each `PUT` of a resumable upload. Google requires a multiple of 256 KiB. */
    /**
     * How much goes in one `PUT`, a multiple of the 256 KiB the protocol
     * requires.
     *
     * **Sized against the time budget, not against throughput.** A chunk
     * is one HTTP request and cannot be interrupted politely, so
     * {@see sendChunks()} can only check its deadline BETWEEN chunks and
     * the overshoot is one chunk's duration. Two mebibytes is about
     * seventeen seconds on a one-megabit domestic upstream — the link
     * this whole mechanism exists for — which keeps the overshoot on the
     * order of the budget itself rather than several times it. Eight
     * mebibytes, the first value here, was four times worse on that same
     * link for no gain that mattered.
     *
     * Public because the tests derive their expected `Content-Range`
     * headers from it: a test that repeats the number is a test that
     * silently stops matching the code the day it changes.
     */
    public const UPLOAD_CHUNK_BYTES = 2 * 1024 * 1024;

    /** How many entries one page of a folder listing asks for. */
    private const LIST_PAGE_SIZE = 100;

    /**
     * Which of Google's two endpoints refused, for {@see errorFor()}.
     *
     * A 401 means something different on each, and telling them apart is
     * the difference between « vérifiez votre secret » and a refresh
     * token deleted for nothing.
     */
    private const ENDPOINT_API = 'api';
    private const ENDPOINT_TOKEN = 'token';

    /**
     * @param DriveTransport|null $transport
     */
    public function __construct(private ?\Closure $transport = null)
    {
    }

    /**
     * Where to send the operator's browser to ask for consent.
     *
     * `access_type=offline` with `prompt=consent` is what makes Google
     * return a REFRESH token rather than an hour-long access token: this
     * application has to work at three in the morning with nobody at the
     * keyboard, and `prompt=consent` is required because Google issues the
     * refresh token only on a first grant — without it, an operator
     * reconnecting an account they had already authorised would come back
     * with nothing to store and no error to explain it.
     */
    public function authorizationUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Trades the one-shot code the browser came back with for a refresh
     * token.
     *
     * @return array{refresh_token: string, access_token: string, expires_in: int}
     * @throws DriveAccessException
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $redirectUri, string $code): array
    {
        $decoded = $this->postToken([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        $refreshToken = (string) ($decoded['refresh_token'] ?? '');
        if ($refreshToken === '') {
            // Reached when Google recognised an existing grant and reissued
            // only an access token — which `prompt=consent` above exists to
            // prevent. Saying so plainly beats storing an empty string and
            // failing a week later.
            throw DriveAccessException::of(
                'Google n\'a pas fourni de jeton de rafraîchissement. Révoquez l\'accès de cette application dans '
                . 'votre compte Google, puis recommencez le raccordement.'
            );
        }

        return [
            'refresh_token' => $refreshToken,
            'access_token' => (string) ($decoded['access_token'] ?? ''),
            'expires_in' => (int) ($decoded['expires_in'] ?? 0),
        ];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     * @throws DriveAccessException
     */
    public function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $decoded = $this->postToken([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $accessToken = (string) ($decoded['access_token'] ?? '');
        if ($accessToken === '') {
            throw DriveAccessException::of('Google a répondu sans jeton d\'accès utilisable.');
        }

        return ['access_token' => $accessToken, 'expires_in' => (int) ($decoded['expires_in'] ?? 3600)];
    }

    /**
     * The account and its storage quota.
     *
     * @return array{account: string, quota: ?StorageQuota}
     * @throws DriveAccessException
     */
    public function about(string $accessToken): array
    {
        $decoded = $this->apiJson(
            'GET',
            self::API_BASE . '/about?fields=user(emailAddress),storageQuota(limit,usage)',
            $accessToken
        );

        $user = is_array($decoded['user'] ?? null) ? $decoded['user'] : [];
        $storage = is_array($decoded['storageQuota'] ?? null) ? $decoded['storageQuota'] : [];

        // `limit` is ABSENT, not zero, on an account with unlimited
        // storage — reading it as 0 would report a full disk to somebody
        // who has no disk to fill.
        $quota = isset($storage['limit'])
            ? new StorageQuota((int) ($storage['usage'] ?? 0), (int) $storage['limit'])
            : null;

        return ['account' => (string) ($user['emailAddress'] ?? ''), 'quota' => $quota];
    }

    /**
     * Creates a folder and answers with its id, or finds the one this
     * application created before.
     *
     * Under `drive.file` the search can only ever see this application's
     * own files, so "is there already one" is a question about this
     * application's history and not about the operator's Drive.
     *
     * @throws DriveAccessException
     */
    public function ensureFolder(string $accessToken, string $name): string
    {
        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and trashed=false and name='%s'",
            self::quoted($name)
        );
        $found = $this->apiJson(
            'GET',
            self::API_BASE . '/files?' . http_build_query(['q' => $query, 'fields' => 'files(id)', 'pageSize' => 1]),
            $accessToken
        );
        $files = is_array($found['files'] ?? null) ? $found['files'] : [];
        if (isset($files[0]['id'])) {
            return (string) $files[0]['id'];
        }

        $created = $this->apiJson(
            'POST',
            self::API_BASE . '/files?fields=id',
            $accessToken,
            (string) json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder']),
            'application/json'
        );
        $id = (string) ($created['id'] ?? '');
        if ($id === '') {
            throw DriveAccessException::of('Le dossier de destination n\'a pas pu être créé sur Google Drive.');
        }

        return $id;
    }

    /**
     * One page of the folder's contents, newest first.
     *
     * **Paged, where this used to fetch everything in a loop, and the
     * loop moved rather than disappeared.** It belonged here while the
     * only caller was the retention purge; now
     * {@see \Core\Storage\Location\Backend\StorageBackendInterface::
     * list()} is a paged contract that every backend keeps, because the
     * caller that walks a whole location — the safety copy of IT-04 —
     * runs under a time budget and has to stop between two pages.
     *
     * **A caller that reads only the first page and believes that is all
     * there is has the worst possible belief**, and that has not changed:
     * the files it would never see are the OLDEST — exactly the ones a
     * purge exists to delete — so an account past a hundred archives
     * would fill up while the purge reported nothing to do. Ordering
     * newest-first puts the blind spot where it does the most damage,
     * which is why the cursor below is not optional decoration.
     *
     * `size` is absent on a Google-native document (a Doc, a Sheet) and
     * read as 0; under the `drive.file` scope this folder holds only what
     * this application uploaded, so that case is theoretical here and
     * reported honestly rather than guessed at.
     *
     * @return array{objects: list<StoredObject>, cursor: ?string}
     * @throws DriveAccessException
     */
    public function listPage(string $accessToken, string $folderId, ?string $pageToken, int $pageSize): array
    {
        $parameters = [
            'q' => sprintf("'%s' in parents and trashed=false", self::quoted($folderId)),
            'fields' => 'nextPageToken,files(id,name,size,md5Checksum,modifiedTime)',
            'orderBy' => 'createdTime desc',
            'pageSize' => max(1, min(self::LIST_PAGE_SIZE, $pageSize)),
        ];
        if ($pageToken !== null && $pageToken !== '') {
            $parameters['pageToken'] = $pageToken;
        }

        $decoded = $this->apiJson('GET', self::API_BASE . '/files?' . http_build_query($parameters), $accessToken);

        $objects = [];
        foreach (is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $objects[] = new StoredObject(
                (string) $entry['name'],
                (int) ($entry['size'] ?? 0),
                self::comparableChecksum($entry),
                isset($entry['modifiedTime']) ? (string) $entry['modifiedTime'] : null
            );
        }

        $next = is_string($decoded['nextPageToken'] ?? null) ? $decoded['nextPageToken'] : '';

        return ['objects' => $objects, 'cursor' => $next !== '' ? $next : null];
    }

    /**
     * The one file in this folder called $name, or null.
     *
     * **Drive lets two files share a name**, which a filesystem does not,
     * and the whole of this client addresses files by NAME because that
     * is what a storage key is. So « the one file » is a claim this
     * method has to make good on: it asks for the newest and answers with
     * that, and every write path removes the duplicates it creates (see
     * {@see \Core\Storage\Location\Backend\GoogleDriveBackend}). What
     * must never happen is a reader and a deleter picking different
     * files — so both go through here.
     *
     * @return array{id: string, size: int, checksum: ?string, modifiedAt: ?string}|null
     * @throws DriveAccessException
     */
    public function findFile(string $accessToken, string $folderId, string $name): ?array
    {
        $query = sprintf(
            "'%s' in parents and trashed=false and name='%s'",
            self::quoted($folderId),
            self::quoted($name)
        );
        $url = self::API_BASE . '/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id,size,md5Checksum,modifiedTime)',
            'orderBy' => 'createdTime desc',
            'pageSize' => 1,
        ]);
        $decoded = $this->apiJson('GET', $url, $accessToken);

        $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        $entry = is_array($files[0] ?? null) ? $files[0] : null;
        if ($entry === null || !isset($entry['id'])) {
            return null;
        }

        return [
            'id' => (string) $entry['id'],
            'size' => (int) ($entry['size'] ?? 0),
            'checksum' => self::comparableChecksum($entry),
            'modifiedAt' => isset($entry['modifiedTime']) ? (string) $entry['modifiedTime'] : null,
        ];
    }

    /**
     * Every file in this folder called $name EXCEPT $keepId, newest
     * first — the duplicates a write has just created.
     *
     * @return list<string>
     * @throws DriveAccessException
     */
    public function findDuplicates(string $accessToken, string $folderId, string $name, string $keepId): array
    {
        $query = sprintf(
            "'%s' in parents and trashed=false and name='%s'",
            self::quoted($folderId),
            self::quoted($name)
        );
        $url = self::API_BASE . '/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id)',
            'pageSize' => self::LIST_PAGE_SIZE,
        ]);
        $decoded = $this->apiJson('GET', $url, $accessToken);

        $ids = [];
        foreach (is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $entry) {
            $id = is_array($entry) ? (string) ($entry['id'] ?? '') : '';
            if ($id !== '' && $id !== $keepId) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The bytes of a file, whole.
     *
     * Deliberately without a range variant, and that is a scope statement
     * rather than an omission: reading a film in slices is what
     * {@see \Core\Storage\Location\StorageCapability::RangeRead} is,
     * this backend does not declare it, and a method here that nothing
     * declares would be exactly the capability lie the enum exists to
     * prevent. IT-07 adds both together or neither.
     *
     * @throws DriveAccessException
     */
    public function download(string $accessToken, string $fileId): string
    {
        $response = $this->send(
            'GET',
            self::API_BASE . '/files/' . rawurlencode($fileId) . '?alt=media',
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->errorFor($response, 'Le fichier n\'a pas pu être relu sur Google Drive.');
        }

        return $response['body'];
    }

    /**
     * The checksum Drive announces, when it is one an MD5 can be compared
     * with.
     *
     * Drive's `md5Checksum` IS an MD5 of the whole file, whatever the
     * upload was cut into — which is the difference from S3's ETag and
     * the reason this backend declares
     * {@see \Core\Storage\Location\StorageCapability::Checksum} where
     * the object store does not. Absent on a Google-native document,
     * which is why the field is nullable rather than defaulted.
     *
     * @param array<string, mixed> $entry
     */
    private static function comparableChecksum(array $entry): ?string
    {
        $md5 = $entry['md5Checksum'] ?? null;

        return is_string($md5) && preg_match('/^[0-9a-f]{32}$/i', $md5) === 1 ? strtolower($md5) : null;
    }

    /**
     * Records what kind of file this actually is, after the fact.
     *
     * **Because a resumable session is opened before anybody says.**
     * Google wants `X-Upload-Content-Type` when the session is minted,
     * and the contract that opens one
     * ({@see \Core\Storage\Location\Backend\ResumableUploadBackend::
     * beginPartial()}) deliberately carries a size and nothing else — the
     * media type belongs to the promotion, which is where the caller
     * actually states it. So the session opens on
     * `application/octet-stream` and this corrects it once the bytes are
     * there. One extra request per large file, against a folder of
     * archives that would otherwise all read as « données binaires » in
     * the operator's own Drive.
     *
     * @throws DriveAccessException
     */
    public function setMimeType(string $accessToken, string $fileId, string $mimeType): void
    {
        $this->apiJson(
            'PATCH',
            self::API_BASE . '/files/' . rawurlencode($fileId) . '?fields=id',
            $accessToken,
            (string) json_encode(['mimeType' => $mimeType]),
            'application/json'
        );
    }

    /** @throws DriveAccessException */
    public function deleteFile(string $accessToken, string $fileId): void
    {
        $response = $this->send(
            'DELETE',
            self::API_BASE . '/files/' . rawurlencode($fileId),
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        // 404 is success here: the caller asked for the file to be gone.
        if ($response['status'] === 404 || ($response['status'] >= 200 && $response['status'] < 300)) {
            return;
        }

        throw $this->errorFor($response, 'La suppression du fichier distant a échoué.');
    }

    /**
     * Writes a small object in one request and answers with its Drive id.
     *
     * **For the small things only**, and the threshold is not a matter of
     * taste: the body is held in a PHP string, so this is for a witness
     * file or a few hundred bytes of bookkeeping. Anything whose size
     * depends on what a unit has stored goes through the resumable
     * session below — on the shared hosting this application exists for,
     * a multi-gibibyte string is not slow, it is a fatal.
     *
     * Multipart rather than the two-step upload: one round trip carries
     * the metadata and the bytes together, which for a 200-byte witness
     * is the difference between one request and three.
     *
     * @throws DriveAccessException
     */
    public function uploadContents(
        string $accessToken,
        string $folderId,
        string $remoteName,
        string $contents,
        string $mimeType
    ): string {
        $boundary = 'scoutmagic' . bin2hex(random_bytes(16));
        $metadata = (string) json_encode(['name' => $remoteName, 'parents' => [$folderId]]);

        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            . "--{$boundary}\r\nContent-Type: {$mimeType}\r\n\r\n{$contents}\r\n"
            . "--{$boundary}--\r\n";

        $response = $this->send(
            'POST',
            self::UPLOAD_BASE . '/files?uploadType=multipart&fields=id',
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'multipart/related; boundary=' . $boundary,
            ],
            $body
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->errorFor($response, 'Google Drive a refusé le fichier.');
        }

        return $this->acceptedFileId($response);
    }

    /**
     * Opens a resumable session and answers with the URI to send to.
     *
     * **The URI is the thing worth keeping.** It outlives this request,
     * this run and this day — Google holds a session for about a week —
     * and it is what lets a backup too large for one request be finished
     * across several. The caller stores it; losing it means starting the
     * whole archive again.
     *
     * @throws DriveAccessException
     */
    public function beginUpload(
        string $accessToken,
        string $folderId,
        string $remoteName,
        int $size,
        string $mimeType
    ): string {
        $session = $this->send(
            'POST',
            self::UPLOAD_BASE . '/files?uploadType=resumable&fields=id',
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => $mimeType,
                'X-Upload-Content-Length' => (string) $size,
            ],
            (string) json_encode(['name' => $remoteName, 'parents' => [$folderId]])
        );
        if ($session['status'] < 200 || $session['status'] >= 300) {
            throw $this->errorFor($session, 'Google Drive a refusé de commencer l\'envoi.');
        }
        $sessionUrl = $session['location'] ?? '';
        if ($sessionUrl === '') {
            throw DriveAccessException::of('Google Drive n\'a pas indiqué où envoyer le fichier.');
        }

        return $sessionUrl;
    }

    /**
     * Asks Google how much of this file it already holds — or answers
     * **null when the session no longer exists**.
     *
     * **The question after a failure, instead of sending it all again.**
     * A run that died mid-chunk leaves this application unsure how much
     * arrived, and guessing either resends what was received or skips
     * what was not. An empty `PUT` with `Content-Range: bytes * / total`
     * is the protocol's way of asking, and the answer is authoritative in
     * a way no local bookkeeping can be.
     *
     * A 2xx here means the file is in fact already complete — the last
     * chunk landed and only the answer was lost — which is a resume that
     * has nothing left to do rather than an error.
     *
     * **Null is an answer, and separating it from a refusal is the whole
     * point of this signature.** A session Google has forgotten (404, or
     * 410 once it has been cancelled) is *no transfer at all*: the caller
     * has to throw the note away and open a fresh one. Every other
     * failure — a 5xx, a rate limit, a cURL error on this one status
     * query — is a transfer that is still there and still resumable, and
     * it travels as an exception so the caller leaves it alone. Reading
     * the two alike is what would let a single network hiccup destroy the
     * resume state of a multi-gibibyte upload and send it again from byte
     * zero, which is precisely what this class exists to avoid.
     *
     * @throws DriveAccessException when the session may still be good and
     *         the question simply could not be answered
     */
    public function probeUpload(string $sessionUrl, int $size): ?DriveUpload
    {
        $response = $this->send('PUT', $sessionUrl, [
            'Content-Length' => '0',
            'Content-Range' => sprintf('bytes */%d', $size),
        ]);

        if ($response['status'] === 308) {
            return DriveUpload::inProgress($sessionUrl, $this->committedOffset($response, 0));
        }
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return DriveUpload::completed($sessionUrl, $this->acceptedFileId($response));
        }
        if ($response['status'] === 404 || $response['status'] === 410) {
            return null;
        }

        throw $this->errorFor($response, 'Google Drive n\'a pas dit où reprendre l\'envoi.');
    }

    /**
     * Sends ONE piece, from `$offset`, and says what Google made of it.
     *
     * **One chunk per call, where this used to loop over a whole local
     * file under a time budget.** The loop has not disappeared; it moved
     * to the caller, because the caller is now
     * {@see \Core\Storage\Location\Backend\ResumableUploadBackend::
     * appendToPartial()} — a contract that hands over bytes already in
     * memory and knows nothing about local paths. That is what lets a
     * safety copy read a slice from an S3 bucket and append it to a Drive
     * folder without either end knowing what the other is, which is the
     * whole point of IT-05.
     *
     * **Where to continue FROM is Google's to say, not ours.** The
     * protocol allows it to commit fewer bytes than were sent and to
     * report how many in a `Range` header; advancing by the length we
     * happened to write assumes an answer instead of reading it, and a
     * short commit would leave a hole in the middle of an archive that
     * every later chunk widens. The archive would upload, be accepted,
     * and be unreadable on the day it was needed. So the answer comes
     * back in {@see DriveUpload::$offset} and the caller continues from
     * there, never from its own arithmetic.
     *
     * A 308 is « keep going » — the ordinary answer to every piece but
     * the last, and emphatically not an error despite sitting outside the
     * 2xx range.
     *
     * @throws DriveAccessException
     */
    public function sendChunk(string $sessionUrl, string $chunk, int $offset, int $total): DriveUpload
    {
        $length = strlen($chunk);
        if ($length === 0) {
            throw DriveAccessException::of('Aucun octet à envoyer vers Google Drive.');
        }

        $response = $this->send('PUT', $sessionUrl, [
            'Content-Length' => (string) $length,
            'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $total),
        ], $chunk);

        if ($response['status'] === 308) {
            $committed = $this->committedOffset($response, $offset);
            if ($committed <= $offset) {
                throw DriveAccessException::of(
                    'Google Drive n\'a retenu aucun octet de la dernière tranche envoyée.'
                );
            }

            return DriveUpload::inProgress($sessionUrl, $committed);
        }
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return DriveUpload::completed($sessionUrl, $this->acceptedFileId($response));
        }

        throw $this->errorFor($response, 'L\'envoi vers Google Drive a échoué.');
    }

    /**
     * Tells Google to forget a session that will never be finished.
     *
     * **Never throws.** The one caller is
     * {@see \Core\Storage\Location\Backend\ResumableUploadBackend::
     * discardPartial()}, which is itself called when something has
     * already gone wrong — a copy abandoned, a transfer that disagreed
     * with its source. A failure to tidy up must not replace the failure
     * that is actually being reported, and an abandoned session expires
     * on Google's side within about a week in any case.
     */
    public function cancelUpload(string $sessionUrl): void
    {
        try {
            $this->send('DELETE', $sessionUrl, ['Content-Length' => '0']);
        } catch (\Throwable) {
            // Nothing useful to tell anybody about it.
        }
    }

    /**
     * The id Google gave the finished file, refusing an acceptance that
     * names nothing — the caller needs it to delete the file later, and a
     * remote file this application cannot address is one it can never
     * purge.
     *
     * @param array{status: int, body: string, location?: string, range?: string} $response
     * @throws DriveAccessException
     */
    private function acceptedFileId(array $response): string
    {
        $decoded = json_decode($response['body'], true);
        $id = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
        if ($id === '') {
            throw DriveAccessException::of('Google Drive a accepté le fichier sans en donner l\'identifiant.');
        }

        return $id;
    }

    /**
     * One value, safe to sit inside the single quotes of a Drive query.
     *
     * Drive's query language escapes with a backslash, so a backslash in
     * the value has to go first: escaping the quotes alone leaves `\\` to be
     * read as the escape for whatever follows it. A key ending in one then
     * escapes the CLOSING quote, and the literal runs on into the rest of
     * the query.
     *
     * That is not only a malformed request. `GoogleDriveBackend::
     * removeNamesakes()` hands this a key, then DELETES every id the
     * answer names — so a literal that stops meaning what the caller wrote
     * is a file of somebody else's deleted out of the same folder.
     */
    private static function quoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * How far Google says it has actually got, from the `Range` header it
     * answers a 308 with.

     * **A 308 with no `Range` means it kept NOTHING**, and that is the
     * whole reason `$whenSilent` exists rather than a convenient default.
     * The protocol lets Google commit fewer bytes than were sent and
     * report how many; a silent answer is the limit case of that, not
     * permission to assume the chunk landed. This used to fall back to
     * "everything we wrote", which is precisely the assumption the
     * comment at the call site argues against — and a wrong one leaves a
     * hole in the middle of an archive that every later chunk widens,
     * producing a backup that uploads, is accepted, and cannot be read on
     * the day it is needed.
     *
     * Callers therefore pass what a silence means for THEM: a probe
     * passes 0 (nothing is committed yet), and a chunk send passes the
     * offset it started from, so no progress is recorded and the guard
     * above turns it into a refusal the next run can recover from.
     *
     * @param array<string, mixed> $response
     */
    private function committedOffset(array $response, int $whenSilent): int
    {
        $range = $response['range'] ?? '';
        if ($range === '' || preg_match('/bytes=0-(\d+)/i', $range, $matches) !== 1) {
            return $whenSilent;
        }

        return (int) $matches[1] + 1;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     * @throws DriveAccessException
     */
    private function postToken(array $form): array
    {
        $response = $this->send(
            'POST',
            self::TOKEN_ENDPOINT,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form)
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->errorFor($response, 'Google a refusé la demande de jeton.', self::ENDPOINT_TOKEN);
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw DriveAccessException::of('La réponse de Google est illisible.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     * @throws DriveAccessException
     */
    private function apiJson(
        string $method,
        string $url,
        string $accessToken,
        ?string $body = null,
        ?string $contentType = null
    ): array {
        $headers = ['Authorization' => 'Bearer ' . $accessToken];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $response = $this->send($method, $url, $headers, $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw $this->errorFor($response, 'Google Drive a refusé la requête.');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw DriveAccessException::of('La réponse de Google Drive est illisible.');
        }

        return $decoded;
    }

    /**
     * Turns a refused response into the right kind of refusal.
     *
     * **The distinction that matters is "come back and reconnect" versus
     * "try again later"**, and Google spells the first one in two
     * different places: `invalid_grant` on the token endpoint when the
     * refresh token has been revoked or has expired after seven days of
     * a testing consent screen, and a bare 401 on the API when the access
     * token is no longer honoured. Both mean the same thing to the
     * operator.
     *
     * **Which endpoint answered therefore has to be known here**, and
     * that is what `$endpoint` carries. A 401 does NOT mean the same
     * thing in both places: on the token endpoint RFC 6749 §5.2 spends it
     * on `invalid_client` — a client secret that is wrong, or that was
     * rotated in the Google console — while the refresh token itself is
     * refused with 400 `invalid_grant`. Reading the first as a revocation
     * would be worse than a wrong sentence, and it once was: the class
     * this client answered to dropped the refresh token on a revocation,
     * so a mistyped secret destroyed a grant that was still perfectly
     * good — through the one button an operator presses to find out what
     * is wrong. Nothing drops a grant any more
     * ({@see \Core\Storage\Location\Config\GoogleDriveSecret}), which
     * removes the worst consequence; the distinction is still the one
     * that decides whether the screen says « reconnectez » or « réessayez ».
     *
     * The provider's own body never reaches the message — it is English,
     * it names internals, and `UserFacingException` forbids it. It travels
     * as the cause, to the journal.
     *
     * @param array{status: int, body: string, location?: string, range?: string} $response
     */
    private function errorFor(
        array $response,
        string $fallback,
        string $endpoint = self::ENDPOINT_API
    ): DriveAccessException {
        $detail = new \RuntimeException(
            'Google responded ' . $response['status'] . ': ' . substr($response['body'], 0, 500)
        );

        if ($endpoint === self::ENDPOINT_TOKEN && $response['status'] === 401) {
            return DriveAccessException::of(
                'Google a refusé les identifiants du client OAuth de ce site. Vérifiez l\'identifiant et le '
                . 'secret client enregistrés ci-dessus — le compte raccordé, lui, n\'est pas en cause.',
                $detail
            );
        }
        if ($response['status'] === 401 || str_contains($response['body'], 'invalid_grant')) {
            return DriveAccessException::revoked(
                'Google n\'accepte plus l\'autorisation de ce site. Reconnectez le compte Drive depuis cette page.',
                $detail
            );
        }
        if ($response['status'] === 403 && str_contains($response['body'], 'storageQuotaExceeded')) {
            return DriveAccessException::of(
                'Le compte Google Drive raccordé n\'a plus assez d\'espace libre.',
                $detail
            );
        }

        return DriveAccessException::of($fallback, $detail);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, location?: string, range?: string}
     * @throws DriveAccessException
     */
    private function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $transport = $this->transport ?? self::defaultTransport();

        return $transport($method, $url, $headers, $body);
    }

    /**
     * How long one request is allowed to take in total, in seconds.
     *
     * **Sized on the request, because a flat number is a bet on the
     * site's upstream.** A call carrying nothing gets the floor; a chunk
     * of a backup gets the time that chunk needs at
     * MIN_UPLOAD_BYTES_PER_SECOND, which is slower than any link this
     * runs on. What stops a genuinely dead transfer is not this ceiling
     * but cURL's low-speed guard, set alongside it.
     *
     * Public because it is the one part of {@see defaultTransport()} that
     * can be asserted at all: the closure around it talks to the network
     * and is exercised nowhere, which is precisely why the transport is
     * injectable.
     */
    public static function transferCeilingSeconds(?string $body): int
    {
        if ($body === null) {
            return self::TIMEOUT;
        }

        return max(self::TIMEOUT, (int) ceil(strlen($body) / self::MIN_UPLOAD_BYTES_PER_SECOND));
    }

    /**
     * The real network, used whenever no transport was injected.
     *
     * cURL rather than `file_get_contents()`, unlike
     * `GitHubReleaseClient`: this one needs the `Location` header of the
     * resumable-session response, and a 308 that the stream wrapper
     * reports as a failure rather than as a status.
     *
     * @return DriveResumableTransport
     */
    public static function defaultTransport(): \Closure
    {
        return static function (string $method, string $url, array $headers, ?string $body): array {
            $handle = curl_init($url);
            if ($handle === false) {
                throw DriveAccessException::of('Impossible d\'initialiser la requête vers Google.');
            }

            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            $location = '';
            // **`Range` as well as `Location`, and it is not decoration.**
            // A 308 carries how many bytes Google actually COMMITTED,
            // which it is entitled to make fewer than were sent. A client
            // that cannot read this header cannot know that, and resumes
            // from the wrong place — see putChunks().
            $range = '';
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                // **Not a fixed cap on the whole transfer.** `CURLOPT_TIMEOUT`
                // covers connect, TLS, request body and response together, so
                // a single flat number is a bet on how fast the site's
                // upstream is — and thirty seconds bets on 2 Mbps, which is
                // exactly the link this class's resumable upload was written
                // NOT to assume. Every chunk of every backup would have timed
                // out on an ordinary ADSL line, on the one code path built to
                // survive it.
                //
                // So the ceiling is sized on what is being sent, and the real
                // guard is the pair below: a transfer moving under
                // MIN_UPLOAD_BYTES_PER_SECOND for STALL_SECONDS is dead, and
                // cURL abandons it without waiting for the ceiling. Slow is
                // tolerated; stalled is not.
                CURLOPT_TIMEOUT => self::transferCeilingSeconds($body),
                CURLOPT_LOW_SPEED_LIMIT => self::MIN_UPLOAD_BYTES_PER_SECOND,
                CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
                CURLOPT_HEADERFUNCTION => static function ($_handle, string $header) use (&$location, &$range): int {
                    if (stripos($header, 'location:') === 0) {
                        $location = trim(substr($header, 9));
                    }
                    if (stripos($header, 'range:') === 0) {
                        $range = trim(substr($header, 6));
                    }

                    return strlen($header);
                },
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($handle, $options);

            $responseBody = curl_exec($handle);
            if ($responseBody === false) {
                $error = curl_error($handle);
                curl_close($handle);

                // cURL's text is English and names TLS internals — carried
                // as the cause, never shown.
                throw DriveAccessException::of(
                    'Google n\'a pas pu être contacté. Vérifiez que ce serveur peut sortir vers Internet.',
                    new \RuntimeException($error)
                );
            }
            \assert(is_string($responseBody));

            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            return ['status' => $status, 'body' => $responseBody, 'location' => $location, 'range' => $range];
        };
    }
}
