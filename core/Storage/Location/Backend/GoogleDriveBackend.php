<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

use Core\Storage\Location\Backend\Drive\DriveAccessException;
use Core\Storage\Location\Backend\Drive\GoogleDriveClient;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageListing;
use Core\Storage\Location\StorageQuota;
use Core\Storage\Location\StoredObject;

/**
 * A folder in somebody's Google Drive, as a storage location.
 *
 * **This class is what IT-05 is.** `GoogleDriveTarget` used to sit behind
 * `RemoteBackupTarget`, an interface with one implementation whose own
 * docblock justified itself on two grounds: the precedent of
 * {@see StorageBackendInterface}, and testability against a service that
 * needs a Google account and a consent screen. Both arguments survive
 * intact — they simply turn out to have been arguments for being THIS
 * interface rather than a parallel one. A Drive folder was never a
 * different kind of thing from a bucket; it was the same kind of thing
 * with one consumer.
 *
 * **What that buys immediately.** The off-site backup stops being the only
 * thing that can write to Drive: a location's safety copy (IT-04) can, a
 * second Drive account is representable where `remote_backup_refresh_token`
 * made it unthinkable, and the screen that compares destinations can
 * include this one without knowing anything about OAuth.
 *
 * **The access token is never stored.** It lives for an hour, and a row
 * holding one would be a credential at rest for no benefit — the refresh
 * costs a single request and any real operation makes dozens. It is cached
 * in memory for the life of this object, which covers exactly the one
 * request or one scheduler run a caller performs.
 *
 * **A key is a file name, and the folder is flat.** Drive has no
 * directories in the filesystem sense — a folder is a parent, and a name
 * may contain slashes — so `"12/med_3.jpg"` is one file called
 * `12/med_3.jpg` sitting directly in the folder. That is invisible to
 * every caller, which addresses objects by key and never by path, and it
 * is why {@see deletePrefix()} filters names rather than removing a
 * subtree.
 *
 * **Drive lets two files share a name, and a storage key may not.** Every
 * write here therefore ends by removing the older namesakes it created,
 * and every read goes through one lookup that answers with the newest.
 * Getting that wrong does not corrupt anything; it makes a reader and a
 * deleter disagree about which file « the » key is, which is worse.
 *
 * **The Google consent screen is the failure this integration actually
 * dies of.** A project left in « Test » status hands out refresh tokens
 * that Google withdraws after
 * {@see GoogleDriveClient::TESTING_TOKEN_LIFETIME_DAYS} days, silently:
 * the backups simply stop, and nothing says so until somebody needs one.
 * The warning belongs on this location's own card, which is where the
 * screen puts it.
 */
final class GoogleDriveBackend implements ResumableUploadBackend, QuotaReportingBackend
{
    /**
     * Three, and what is absent is as informative as what is there.
     *
     * **Resumable upload** is the native protocol and the reason this
     * destination can hold an archive at all. **Quota** is real: Drive
     * simply says how full the account is, where a bucket would have to
     * be walked object by object — it is the first backend to declare it,
     * and {@see QuotaReportingBackend} exists because of that.
     * **Checksum** is real too, and is the difference from S3 worth
     * naming: Drive's `md5Checksum` is an MD5 of the whole file whatever
     * the upload was cut into, so it compares with a digest computed while
     * reading the source, where a multipart ETag never can.
     *
     * **No range read and no signed URL**, which is what keeps this
     * destination out of the gallery for now: without range reads a video
     * can be started and never seeked in, and without a signed URL every
     * photograph travels through PHP. Both are IT-07's work, and
     * declaring either here before its method exists is exactly the lie
     * this mechanism is for. **No server-side copy**: Drive can copy a
     * file within an account, but not from another storage, so the
     * capability a copier looks for — « move this object without the
     * bytes passing through me » — is one this cannot honour.
     *
     * @return list<StorageCapability>
     */
    public static function declaredCapabilities(): array
    {
        return [
            StorageCapability::ResumableUpload,
            StorageCapability::Quota,
            StorageCapability::Checksum,
        ];
    }

    /**
     * Below this, an upload is buffered in memory and sent in one request
     * instead of opening a resumable session.
     *
     * **8 MiB, and the trade is explicit.** A session costs five round
     * trips for a small file — open, write the bookkeeping object below,
     * send, de-duplicate, remove the bookkeeping — where one request does
     * the same work. What it costs in exchange is resumption: a buffered
     * upload interrupted between two runs restarts from zero, because
     * nothing durable was written. Bounded by this constant, that is at
     * most 8 MiB of transfer thrown away, which is less than the five
     * requests would have cost to save it.
     *
     * The figure is `ProtectedCopier::CHUNK_BYTES`, deliberately: that
     * copier hands over one slice of exactly this size, so a file at or
     * under the threshold arrives in a single {@see appendToPartial()}
     * call and the buffer never holds more than one slice.
     */
    public const BUFFERED_UPLOAD_LIMIT_BYTES = 8 * 1024 * 1024;

    /**
     * What this application's own bookkeeping objects are called.
     *
     * A resumable session is a URI Google mints, and it cannot be
     * rediscovered: a run that opens one and dies has to find it again
     * tomorrow or start a multi-gibibyte archive over. **So it is kept in
     * the destination** — a small object beside the file it describes —
     * and not in this site's database, which is D12 one level down: a
     * transfer in flight is a fact about the destination, and restoring a
     * database must not be able to move it backwards.
     *
     * The prefix is what keeps these out of every listing, so no consumer
     * ever meets one as content; `ObjectStorageBackend`'s health-check
     * canary uses the same convention and for the same reason.
     */
    private const INTERNAL_PREFIX = '.scoutmagic-';

    private const PARTIAL_PREFIX = self::INTERNAL_PREFIX . 'part-';

    private const WITNESS_KEY = self::INTERNAL_PREFIX . 'healthcheck.txt';

    private string $accessToken = '';

    private string $resolvedFolderId = '';

    /**
     * Buffered uploads in flight, by key — see
     * {@see BUFFERED_UPLOAD_LIMIT_BYTES}.
     *
     * @var array<string, string>
     */
    private array $buffers = [];

    /**
     * Sessions opened by this instance, by key, so a copy that runs
     * through one request does not re-read its own bookkeeping object
     * between every chunk.
     *
     * `offset` is what Google last confirmed it holds, and `null` means
     * « this instance has not asked yet » — which is a different thing
     * from zero and is why the field is nullable. A fresh run picks a
     * session back up without knowing where it stopped; every send after
     * that is answered with the new offset, so the probe costs one request
     * per run rather than one per chunk.
     *
     * @var array<string, array{session: string, total: int, offset: ?int, fileId: string}>
     */
    private array $sessions = [];

    /**
     * Metadata already looked up, by key — `null` meaning « asked, and
     * there is no such file ».
     *
     * A copy verifies a freshly written object by asking its size and
     * then its checksum, which is two lookups of the same file across the
     * network. Invalidated by every write and every delete this class
     * performs, so it can only ever be as stale as something else changing
     * the folder behind us.
     *
     * @var array<string, array{id: string, size: int, checksum: ?string, modifiedAt: ?string}|null>
     */
    private array $metadata = [];

    public function __construct(
        private readonly GoogleDriveClient $client,
        private readonly GoogleDriveLocationConfig $config,
        private readonly GoogleDriveSecret $secret
    ) {
    }

    public function capabilities(): array
    {
        return self::declaredCapabilities();
    }

    public function supports(StorageCapability $capability): bool
    {
        return in_array($capability, self::declaredCapabilities(), true);
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        // **The memory cost is the caller's to know about.** `put()` is
        // the floor contract and takes a whole string, so the peak here is
        // the object plus the multipart envelope around it. A caller
        // moving something whose size depends on what a unit has stored
        // uses the resumable path instead, which is the entire reason
        // {@see ResumableUploadBackend} exists.
        $fileId = $this->client->uploadContents(
            $this->accessToken(),
            $this->folderId(),
            $key,
            $contents,
            $mimeType
        );
        $this->removeNamesakes($key, $fileId);
    }

    public function get(string $key): string
    {
        $file = $this->metadataFor($key);
        if ($file === null) {
            throw new \RuntimeException("Stored file not found: {$key}");
        }

        return $this->client->download($this->accessToken(), $file['id']);
    }

    public function size(string $key): ?int
    {
        try {
            $file = $this->metadataFor($key);
        } catch (\Throwable) {
            // Null is « cannot be determined », which is exactly what an
            // unreachable destination means here — and the contract a
            // caller serving a range or refusing an over-large operation
            // already handles.
            return null;
        }

        return $file['size'] ?? null;
    }

    public function exists(string $key): bool
    {
        return $this->metadataFor($key) !== null;
    }

    public function delete(string $key): void
    {
        $file = $this->metadataFor($key);
        if ($file === null) {
            // A key that is not there is a success: the caller asked for
            // it to be gone, and every mechanism that deletes derives its
            // list from something that can be older than the storage.
            return;
        }

        $this->client->deleteFile($this->accessToken(), $file['id']);
        unset($this->metadata[$key]);
    }

    public function deletePrefix(string $prefix): void
    {
        // An empty prefix is a no-op and never « everything »: the one
        // caller that wants a whole album gone passes its album prefix,
        // and a bug that produced an empty one must not erase the
        // operator's entire folder.
        if ($prefix === '') {
            return;
        }

        $cursor = null;
        do {
            $listing = $this->list($prefix, $cursor);
            foreach ($listing->objects as $object) {
                $this->delete($object->key);
            }
            $cursor = $listing->cursor;
        } while ($cursor !== null);
    }

    /**
     * One page of the folder, filtered to $prefix.
     *
     * **The filtering happens here rather than in the query**, and that
     * makes an empty page with a cursor an ordinary outcome rather than a
     * bug: Drive pages its answer before this class looks at the names, so
     * a page can legitimately hold nothing that matches. Every caller of
     * this contract already continues on the cursor rather than on the
     * page being non-empty, which is what makes that safe.
     *
     * The bookkeeping objects of {@see PARTIAL_PREFIX} are never listed.
     * A half-written file must not be visible as content — half a
     * photograph is a photograph as far as every screen is concerned —
     * and a resumable session leaves no object under the real key at all
     * until it completes, so the only thing to hide is this class's own
     * note to itself.
     */
    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        $page = $this->client->listPage($this->accessToken(), $this->folderId(), $cursor, $limit);

        $objects = [];
        foreach ($page['objects'] as $object) {
            if (str_starts_with($object->key, self::INTERNAL_PREFIX)) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($object->key, $prefix)) {
                continue;
            }
            $objects[] = $object;
        }

        return new StorageListing($objects, $page['cursor']);
    }

    /** Nothing here is a file this server can open. */
    public function localPath(string $key): ?string
    {
        return null;
    }

    /**
     * Null, and not because it is hard.
     *
     * Drive can mint a link, but only by making the file readable to
     * anyone who holds it, for ever, with nothing checking who is asking
     * — which is the one property {@see
     * \Core\Storage\Location\Config\LocationConfig::
     * servesPubliclyWithoutExpiry()} exists to keep out of this
     * application. So the bytes go out through the consumer's own
     * access-controlled route, exactly as they do for the local disk.
     */
    public function directUrl(string $key, string $ttl = '+1 hour'): ?string
    {
        return null;
    }

    public function stableDirectUrl(string $key): ?string
    {
        return null;
    }

    /**
     * Drive's `md5Checksum`, which really is one.
     *
     * The difference from S3 is worth restating where somebody will read
     * it: an ETag equals an MD5 only for a single-part upload, so an
     * object store cannot tell a digest from a digest-of-digests and says
     * nothing. Drive computes the MD5 of the whole file however the upload
     * was cut up, so this value compares with one computed while reading
     * the source — which is what makes a verified copy possible here.
     */
    public function announcedChecksum(string $key): ?string
    {
        return $this->metadataFor($key)['checksum'] ?? null;
    }

    public function quota(): ?StorageQuota
    {
        return $this->client->about($this->accessToken())['quota'];
    }

    /**
     * Writes a witness object and removes it again.
     *
     * **Writing, not reading.** A destination that answers `about` happily
     * may still refuse every write — a revoked grant, a full account, a
     * folder that no longer exists — and an operator who pressed
     * « Tester » and saw a green tick would learn that on the night their
     * server burned down. The round trip costs a few hundred bytes.
     *
     * **Never throws**, per the interface: a failed test is an answer, and
     * this method exists to give the administrator that answer rather than
     * an error page. The removal sits in a `finally` for the same reason
     * the write is guarded — a witness uploaded and then not removed,
     * because the token expired between the two calls, would stay in the
     * operator's Drive and every press of the button would leave another.
     */
    public function testConnection(): ?string
    {
        if (!$this->secret->hasGrant()) {
            return 'Aucun compte Google Drive n\'est raccordé à cet emplacement.';
        }

        $written = false;
        try {
            $this->put(
                self::WITNESS_KEY,
                'ScoutMagic — test de raccordement ' . date('c') . "\n",
                'text/plain'
            );
            $written = true;

            if (!$this->exists(self::WITNESS_KEY)) {
                return 'Le fichier témoin a été accepté par Google Drive mais ne s\'y retrouve pas.';
            }

            return null;
        } catch (DriveAccessException $e) {
            // Already a French sentence written for a person: this is a
            // UserFacingException, and its own `throw` site chose the
            // words. Google's English body travels as the cause.
            return $e->getMessage();
        } catch (\Throwable) {
            return 'Le test n\'a pas pu être mené à son terme sur ce serveur. Le raccordement n\'est pas en '
                . 'cause ; consultez le journal du site.';
        } finally {
            if ($written) {
                try {
                    $this->delete(self::WITNESS_KEY);
                } catch (\Throwable) {
                    // A witness left behind is a small untidiness; an
                    // exception out of a diagnostic button is not.
                }
            }
        }
    }

    public function beginPartial(string $key, int $totalBytes): void
    {
        unset($this->buffers[$key], $this->sessions[$key]);

        if ($totalBytes <= self::BUFFERED_UPLOAD_LIMIT_BYTES) {
            // Nothing leaves this server yet, and nothing durable is
            // written: see BUFFERED_UPLOAD_LIMIT_BYTES for what that costs
            // and what it saves.
            //
            // **And nothing is looked up either**, which is the point of
            // the branch being here rather than after the cancellation
            // below: a gallery copying ten thousand thumbnails would
            // otherwise pay one round trip apiece to ask about a
            // bookkeeping object that has never existed for a key this
            // size. A stale one from an earlier, larger attempt is
            // self-healing — {@see partialSize()} meets it, probes the
            // session it names, and throws it away when Google has
            // forgotten it.
            $this->buffers[$key] = '';

            return;
        }

        // **Whatever was in flight under this key is cancelled first.**
        // A caller only reaches here when nothing is stored, so the
        // session that WAS open holds no bytes worth keeping — and
        // overwriting the note that points at it would leave it on
        // Google's side until it expired, invisible to everything.
        $this->discardPartial($key);

        // `application/octet-stream` because the media type is stated at
        // promotion, not here — {@see GoogleDriveClient::setMimeType()}
        // explains why that is the right way round and what corrects it.
        $session = $this->client->beginUpload(
            $this->accessToken(),
            $this->folderId(),
            $key,
            $totalBytes,
            'application/octet-stream'
        );
        $this->sessions[$key] = [
            'session' => $session,
            'total' => $totalBytes,
            'offset' => 0,
            'fileId' => '',
        ];
        $this->rememberPartial($key, $session, $totalBytes);
    }

    /**
     * How much of $key Google says it holds — **asked, never remembered**.
     *
     * The bookkeeping object gives back the session; the offset comes from
     * Google itself, because the protocol allows it to have committed
     * fewer bytes than were sent and this application has no way to know
     * which. A run that died mid-chunk therefore resumes from the truth
     * rather than from a guess, and a database restored to last week
     * cannot move it.
     *
     * 0 for a buffered upload, which is honest: nothing durable exists,
     * and the transfer starts again.
     */
    public function partialSize(string $key): int
    {
        if (isset($this->buffers[$key])) {
            return strlen($this->buffers[$key]);
        }

        $session = $this->sessions[$key] ?? $this->readPartial($key);
        if ($session === null) {
            return 0;
        }
        $this->sessions[$key] = $session;

        // **A session this instance has already finished is not probed.**
        // Google closes a resumable session the moment its last byte
        // lands, so asking it again answers « no such session » — and
        // this method would read that as « nothing is stored » and have
        // the caller send a completed archive a second time. The offset
        // is known here without asking anybody.
        if ($session['fileId'] !== '') {
            return $session['total'];
        }

        try {
            $upload = $this->client->probeUpload($session['session'], $session['total']);
        } catch (DriveAccessException) {
            // A session Google no longer knows about — expired, cancelled
            // — is not an offset of zero on a live transfer: it is no
            // transfer at all. Throwing the note away is what lets the
            // next call open a fresh session instead of appending to a
            // URI that answers nothing.
            $this->discardPartial($key);

            return 0;
        }

        if ($upload->isComplete()) {
            // The last chunk landed and only the answer was lost. Report
            // the full size so the caller stops sending and promotes.
            $this->sessions[$key]['fileId'] = $upload->fileId;
            $this->sessions[$key]['offset'] = $session['total'];

            return $session['total'];
        }

        $this->sessions[$key]['offset'] = $upload->offset;

        return $upload->offset;
    }

    public function appendToPartial(string $key, string $chunk): void
    {
        if (isset($this->buffers[$key])) {
            $this->buffers[$key] .= $chunk;
            if (strlen($this->buffers[$key]) > self::BUFFERED_UPLOAD_LIMIT_BYTES) {
                // The caller announced one size and is sending another.
                // Refused rather than grown: the buffer is what bounds
                // this class's memory, and silently letting it past the
                // limit is how a 900 MB film becomes a fatal on shared
                // hosting.
                unset($this->buffers[$key]);

                throw new \RuntimeException(
                    "Buffered upload exceeded the announced size for: {$key}"
                );
            }

            return;
        }

        $session = $this->sessions[$key] ?? $this->readPartial($key);
        if ($session === null) {
            // No `beginPartial()` and no note in the destination. The
            // total is unknowable, and Google needs it, so there is
            // nothing honest to do but say so.
            throw new \RuntimeException("No upload was opened for: {$key}");
        }
        $this->sessions[$key] = $session;

        if ($chunk === '') {
            // A zero-byte append cannot be sent — a `Content-Range` needs
            // at least one byte — and it is not a failure either: the
            // caller materialising an empty object has nothing to give.
            // An empty file's session is finished by `promotePartial()`.
            return;
        }

        // **Asked once per run, not once per chunk.** Google's answer to
        // a send already says where it now stands, so re-probing between
        // every slice would double the requests to learn what the previous
        // one just said. The probe is for the case that answer was never
        // seen: a run resuming a session another run opened.
        $offset = $session['offset'] ?? $this->client->probeUpload($session['session'], $session['total'])->offset;
        $start = $offset;
        $end = $offset + strlen($chunk);

        // **This method's contract is that the WHOLE chunk is stored when
        // it returns, and Google's protocol does not promise that.** It is
        // allowed to commit fewer bytes than were sent and to say how many
        // in a `Range` header. A caller advancing by what it handed over
        // would then read its next slice from past the hole, and the
        // archive would upload, be accepted, and be unreadable on the day
        // it was needed. So the remainder is re-sent here until Google has
        // all of it — the caller never learns that anything happened,
        // which is what makes `appendToPartial()` a contract a filesystem
        // and a resumable session can both keep.
        while ($offset < $end) {
            $upload = $this->client->sendChunk(
                $session['session'],
                substr($chunk, $offset - $start),
                $offset,
                $session['total']
            );

            if ($upload->isComplete()) {
                $this->sessions[$key]['fileId'] = $upload->fileId;
                $this->sessions[$key]['offset'] = $session['total'];

                return;
            }

            // `sendChunk()` already refuses an answer that kept nothing,
            // so this cannot spin: every turn of this loop advances.
            $offset = $upload->offset;
            $this->sessions[$key]['offset'] = $offset;
        }
    }

    /**
     * Turns the finished upload into the object itself.
     *
     * **Nothing before this call is readable as $key**, which the
     * interface requires and which Drive gives for free on the session
     * path: a resumable upload produces no file at all until its last byte
     * lands. The buffered path keeps the same promise by holding the bytes
     * on this server until here.
     */
    public function promotePartial(string $key, string $mimeType): void
    {
        if (isset($this->buffers[$key])) {
            $contents = $this->buffers[$key];
            unset($this->buffers[$key]);
            $this->put($key, $contents, $mimeType);

            return;
        }

        $session = $this->sessions[$key] ?? $this->readPartial($key);
        if ($session === null) {
            throw new \RuntimeException("No partial upload to promote for: {$key}");
        }

        $fileId = $session['fileId'];
        if ($fileId === '') {
            $upload = $this->client->probeUpload($session['session'], $session['total']);
            if (!$upload->isComplete()) {
                throw new \RuntimeException("Partial upload is not finished for: {$key}");
            }
            $fileId = $upload->fileId;
        }

        try {
            $this->client->setMimeType($this->accessToken(), $fileId, $mimeType);
        } catch (\Throwable) {
            // Cosmetic: the file is there and complete. Failing the
            // promotion over its label would make the caller copy the
            // whole thing again tomorrow.
        }

        $this->removeNamesakes($key, $fileId);
        $this->forgetPartial($key);
    }

    public function discardPartial(string $key): void
    {
        unset($this->buffers[$key]);

        $session = $this->sessions[$key] ?? $this->readPartial($key);
        if ($session !== null) {
            $this->client->cancelUpload($session['session']);
        }
        $this->forgetPartial($key);
    }

    /**
     * Removes every other file sharing this key's name.
     *
     * Drive allows namesakes and a storage key does not, so the moment
     * after a write is the moment to collapse them. Guarded: a duplicate
     * left behind is untidy, while a failure here would report a write
     * that actually succeeded as a failure and have the caller send the
     * whole object again.
     */
    private function removeNamesakes(string $key, string $keepId): void
    {
        unset($this->metadata[$key]);

        try {
            foreach ($this->client->findDuplicates($this->accessToken(), $this->folderId(), $key, $keepId) as $id) {
                $this->client->deleteFile($this->accessToken(), $id);
            }
        } catch (\Throwable) {
            // See above.
        }
    }

    /**
     * @return array{id: string, size: int, checksum: ?string, modifiedAt: ?string}|null
     */
    private function metadataFor(string $key): ?array
    {
        if (!array_key_exists($key, $this->metadata)) {
            $this->metadata[$key] = $this->client->findFile($this->accessToken(), $this->folderId(), $key);
        }

        return $this->metadata[$key];
    }

    /** What this key's bookkeeping object is called. */
    private function partialKey(string $key): string
    {
        return self::PARTIAL_PREFIX . sha1($key) . '.json';
    }

    private function rememberPartial(string $key, string $session, int $total): void
    {
        $this->put(
            $this->partialKey($key),
            (string) json_encode(['session' => $session, 'total' => $total], JSON_UNESCAPED_SLASHES),
            'application/json'
        );
    }

    /**
     * @return array{session: string, total: int, offset: ?int, fileId: string}|null
     */
    private function readPartial(string $key): ?array
    {
        try {
            $file = $this->metadataFor($this->partialKey($key));
            if ($file === null) {
                return null;
            }
            $raw = json_decode($this->client->download($this->accessToken(), $file['id']), true);
        } catch (\Throwable) {
            return null;
        }

        $session = is_array($raw) && is_string($raw['session'] ?? null) ? $raw['session'] : '';
        $total = is_array($raw) ? (int) ($raw['total'] ?? 0) : 0;

        return $session !== '' && $total > 0
            ? ['session' => $session, 'total' => $total, 'offset' => null, 'fileId' => '']
            : null;
    }

    private function forgetPartial(string $key): void
    {
        unset($this->sessions[$key]);

        try {
            $this->delete($this->partialKey($key));
        } catch (\Throwable) {
            // The note is invisible to every listing and describes a
            // session Google forgets within a week. Failing over it would
            // undo a copy that arrived.
        }
    }

    /**
     * The folder this application writes into, created on first use.
     *
     * Looked up rather than assumed when the row carries none: an operator
     * may have emptied their trash, and under `drive.file` a folder this
     * application cannot see is a folder that no longer exists as far as
     * it is concerned.
     *
     * **Resolved in memory and not written back.** Persisting it is the
     * connection flow's business, which holds the repository; a backend
     * built to answer one page must not be a thing that writes
     * configuration rows as a side effect.
     *
     * @throws DriveAccessException
     */
    private function folderId(): string
    {
        if ($this->config->folderId !== '') {
            return $this->config->folderId;
        }
        if ($this->resolvedFolderId !== '') {
            return $this->resolvedFolderId;
        }

        return $this->resolvedFolderId = $this->client->ensureFolder(
            $this->accessToken(),
            GoogleDriveLocationConfig::FOLDER_NAME
        );
    }

    /**
     * @throws DriveAccessException
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== '') {
            return $this->accessToken;
        }

        if (!$this->secret->hasGrant()) {
            // `of()`, not `revoked()`: nothing was withdrawn. Marking this
            // as a revocation would have a caller record a state the site
            // was never in.
            throw DriveAccessException::of('Aucun compte Google Drive n\'est raccordé à cet emplacement.');
        }

        $fresh = $this->client->refreshAccessToken(
            $this->config->clientId,
            $this->secret->clientSecret,
            $this->secret->refreshToken
        );

        return $this->accessToken = $fresh['access_token'];
    }
}
