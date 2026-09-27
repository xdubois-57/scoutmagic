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
 * **A key is a path, and the folders are real** (#474). The location owns
 * one folder, `ScoutMagic/<label>/`, created by the connection flow and
 * found by its id ({@see GoogleDriveLocationConfig::$folderId}), never by
 * its name. Every segment of a key before the last `/` is a sub-folder,
 * created on first write — the same principle as WebDAV's `MKCOL`s — and
 * the last segment is the file name: `5/med_9.jpg` is the file
 * `med_9.jpg` in the folder `5`. The folders it has already resolved are
 * kept, path to id, for the life of this instance, so an album costs one
 * lookup and not one per rendition. It used to be the opposite — one flat
 * folder, `"5/med_9.jpg"` a file whose NAME held a slash — which mixed a
 * gallery's photographs, a safety copy, the backup archives and this
 * class's own bookkeeping in one list nobody could read in Drive, and
 * which made every Drive location of an account share one folder, since
 * that folder was looked up by name.
 *
 * **The application's own files live in `.scoutmagic/`.** A key under
 * {@see INTERNAL_PREFIX} (a resumable session's note, the connection
 * test's witness) is stored in that folder, and so is anything under the
 * storage subsystem's reserved prefix `.scoutmagic/`, which already is a
 * folder. {@see list()} still hides the first kind and still reports the
 * second, exactly as before: `StorageInventoryStore` lists its documents
 * through it, and every pass skips them by key.
 *
 * **Why a walk of the folders, and not a tag on every file.** Drive can
 * carry `appProperties` — the location and the key, stamped on each file —
 * and one query `appProperties has {…}` would then find any file wherever
 * it sits: `findFile()` and `list()` in one request each, and a file an
 * operator moved in Drive still found. It was weighed against the walk and
 * rejected, on three counts:
 *
 * - **Request count, where it matters, goes the other way.** A tag query
 *   cannot filter on a key PREFIX, so `list('5/')` and above all
 *   `deletePrefix('5')` would page through every file of the location to
 *   find album 5's — thousands of requests to delete one album, against
 *   two with folders (find `5`, `DELETE` it: Drive removes a folder with
 *   everything in it). A lookup of one file is a single request either
 *   way once the album folder is cached; only a full `list('')` is
 *   cheaper tagged, and that is a safety copy reading a Drive SOURCE,
 *   which is not how anyone uses Drive — it is a destination, and its
 *   inventory is one folder.
 * - **One truth instead of two.** Tagged, the folders would be decoration
 *   and could disagree with what the site reads: a photo dragged from
 *   `5/` to `12/` would still belong to album 5 for the site while sitting
 *   in album 12 for the person looking. With the walk, what the operator
 *   sees in Drive is what the site sees. « A moved file is still found »
 *   is the other side of that coin, and the help asks precisely that
 *   nothing be moved.
 * - **No new limit on keys.** A property is capped at 124 bytes for key
 *   and value together; a storage key has no such limit on the three
 *   other backends, and a contract that holds on three backends out of
 *   four is how #484 happened.
 *
 * The walk's own cost is paid in {@see list()} and stated there.
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
final class GoogleDriveBackend implements ResumableUploadBackend, QuotaReportingBackend, ManagedRootFolderBackend
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

    /**
     * The folder the application's own files are grouped in — the same
     * name as `StorageInventoryStore::RESERVED_PREFIX` without its slash,
     * so the safety copy's inventories and this class's notes share it.
     */
    private const TECHNICAL_FOLDER = '.scoutmagic';

    /**
     * How deep {@see list()} descends below the folder it starts from.
     * Keys are one level deep (`{albumId}/med_{mediaId}.jpg`) and the
     * inventory's are one level too; this is a guard against a tree
     * nobody here built, not a feature.
     */
    private const WALK_DEPTH_CEILING = 8;

    private string $accessToken = '';

    /**
     * Folders already resolved, by path relative to the location's folder
     * (`''` is that folder itself, `5` an album) — `null` meaning « asked,
     * and there is none ».
     *
     * One lookup per album, not one per rendition: a photograph writes
     * three files into the same folder, and the factory hands this
     * instance out for the whole request.
     *
     * @var array<string, string|null>
     */
    private array $folderIds = [];

    /**
     * The sub-folder names of a folder, sorted, by path — what the walk in
     * {@see list()} steps through.
     *
     * @var array<string, list<string>>
     */
    private array $subfolders = [];

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
        [$folders, $name] = self::locate($key);
        $parentId = $this->folderIdOf($folders, true);
        \assert($parentId !== null);

        $fileId = $this->client->uploadContents(
            $this->accessToken(),
            $parentId,
            $name,
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

    /**
     * **A folder, not a string that names start with** (#484).
     *
     * This read its prefix literally, through {@see list()}, whose filter
     * is a bare `str_starts_with()` — and every real caller passes an
     * album id with no trailing slash. So `deletePrefix('5')` deleted
     * `5/…` and also `50/…`, `51/…` and `512/…`: deleting or MIGRATING
     * album 5 silently destroyed the files of every album whose id it is a
     * prefix of. The rows survived, so the photographs became 404s with
     * nothing on screen and nothing in the journal — and in the migration
     * path the cleanup sits inside a `catch (\Throwable) {}`, so even a
     * failure said nothing.
     *
     * The other three backends never had it: local removes the directory
     * `5/`, WebDAV the collection `5/`, and S3 lists under
     * `rtrim($prefix, '/') . '/'`. This is the same normalisation, which
     * is why {@see StorageBackendInterface::deletePrefix()} now states the
     * rule rather than leaving each backend to infer it.
     *
     * {@see list()} keeps its literal filter: that is its own documented
     * contract, and other callers depend on it — `StorageInventoryStore`
     * asks for `RESERVED_PREFIX`, which is not a folder.
     *
     * Since #474 the rule is structural here as well: the prefix is
     * resolved to a real folder and that folder is deleted, so « names
     * that begin with 5 » is not even expressible any more.
     */
    public function deletePrefix(string $prefix): void
    {
        // An empty prefix is a no-op and never « everything »: the one
        // caller that wants a whole album gone passes its album prefix,
        // and a bug that produced an empty one must not erase the
        // operator's entire folder. Checked AFTER the normalisation, so
        // that '/' — which trims to nothing — is refused too rather than
        // becoming the folder every key is under.
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return;
        }

        // **One request, not one per file.** The prefix names a folder,
        // and Drive removes a folder with everything in it — so album 5
        // is the folder `5`, found and deleted, while `50/` is another
        // folder that this never looks at.
        $folderId = $this->folderIdOf(explode('/', $prefix), false);
        if ($folderId === null) {
            // Nothing was ever written under it, or it is already gone:
            // the end state the caller asked for.
            return;
        }

        $this->client->deleteFile($this->accessToken(), $folderId);
        $this->forgetFolder($prefix);
    }

    /**
     * One page of the objects under $prefix, with their FULL keys
     * (`5/med_9.jpg`), whatever folder they sit in.
     *
     * **The tree is walked in a fixed order**: a folder's own files first,
     * then its sub-folders, each in `strcmp` order of its name, depth
     * first. The cursor says where the walk stands — which folder, and
     * Drive's own page token inside it — so resuming costs nothing: no
     * page is re-read, unlike WebDAV's walk, which has no token to keep.
     * Stepping from one folder to the next asks for the sub-folders of
     * the folders on the way, once per instance. The cost of the whole
     * walk is therefore one request per page of files plus about one per
     * folder, which is what a location with one folder per album pays to
     * be listed; the reasoning against tagging every file instead is on
     * the class.
     *
     * **An empty page with a cursor is an ordinary outcome**, as it
     * already was: a page may stop at a folder boundary, or hold only
     * bookkeeping that is hidden. Every caller of this contract continues
     * on the cursor rather than on the page being non-empty.
     *
     * The prefix is literal, as the interface says: the walk starts from
     * the deepest folder the prefix names completely (`5` for `5/`, the
     * location's own folder for `album`) and keeps the keys that begin
     * with it.
     *
     * The bookkeeping objects of {@see INTERNAL_PREFIX} are never listed,
     * wherever they sit. A half-written file must not be visible as
     * content — half a photograph is a photograph as far as every screen
     * is concerned — and a resumable session leaves no object under the
     * real key at all until it completes, so the only thing to hide is
     * this class's own note to itself.
     */
    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        $slash = strrpos($prefix, '/');
        $start = $slash === false ? '' : substr($prefix, 0, $slash);
        [$folder, $pageToken] = $cursor === null ? [$start, null] : self::decodeCursor($cursor, $start);
        $limit = max(1, $limit);

        $objects = [];
        while (count($objects) < $limit) {
            $folderId = $this->folderIdOf(self::segmentsOf($folder), false);
            $page = $folderId === null
                // A folder removed while the walk was under way holds
                // nothing, which is an answer and not a failure: the walk
                // carries on with the next one rather than discarding
                // what its siblings gave.
                ? ['objects' => [], 'cursor' => null]
                : $this->client->listPage($this->accessToken(), $folderId, $pageToken, $limit - count($objects));

            foreach ($page['objects'] as $object) {
                if (str_starts_with($object->key, self::INTERNAL_PREFIX)) {
                    continue;
                }
                $key = $folder === '' ? $object->key : $folder . '/' . $object->key;
                if ($prefix !== '' && !str_starts_with($key, $prefix)) {
                    continue;
                }
                $objects[] = new StoredObject(
                    $key,
                    $object->sizeBytes,
                    $object->announcedChecksum,
                    $object->lastModifiedAt
                );
            }

            if ($page['cursor'] !== null) {
                $pageToken = $page['cursor'];
                continue;
            }

            $next = $this->folderAfter($folder, $start);
            if ($next === null) {
                return new StorageListing($objects, null);
            }
            $folder = $next;
            $pageToken = null;
        }

        return new StorageListing($objects, self::encodeCursor($folder, $pageToken));
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
            // **The folder first.** Under `drive.file` a folder the
            // operator put in the trash is still writable into, and the
            // witness would go in and come back as if nothing were wrong —
            // while everything written afterwards lands in a bin Google
            // empties on its own after thirty days.
            if ($this->config->folderId === '') {
                return 'Cet emplacement n\'a pas encore de dossier sur Google Drive : raccordez un compte depuis '
                    . 'sa fiche.';
            }
            $folder = $this->client->describeFile($this->accessToken(), $this->config->folderId);
            if ($folder === null || $folder['trashed']) {
                return 'Le dossier de cet emplacement n\'existe plus sur Google Drive, ou il est dans la corbeille. '
                    . 'Sortez-le de la corbeille, ou reconnectez le compte pour en créer un nouveau.';
            }

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
        [$folders, $name] = self::locate($key);
        $parentId = $this->folderIdOf($folders, true);
        \assert($parentId !== null);

        $session = $this->client->beginUpload(
            $this->accessToken(),
            $parentId,
            $name,
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

        // **Only « the session is gone » throws the note away, and every
        // other failure travels.** A session Google no longer knows about
        // is not an offset of zero on a live transfer — it is no transfer
        // at all — so the note has to go or the next call would append to
        // a URI that answers nothing. But a 5xx, a rate limit or a cURL
        // error on this one status query says nothing about the transfer:
        // it is still there and still resumable. Treating the two alike
        // let a single network hiccup destroy the resume state of a
        // multi-gibibyte upload and send the whole thing again from byte
        // zero, which is the one cost this class exists to avoid. The
        // client keeps them apart ({@see GoogleDriveClient::probeUpload()}
        // answers null for the first and throws for the second), so the
        // exception is deliberately NOT caught here: the run fails, the
        // scheduler comes back, and the transfer picks up where it was.
        $upload = $this->client->probeUpload($session['session'], $session['total']);
        if ($upload === null) {
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
        $offset = $session['offset'] ?? $this->probedOffset($session);
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
            if ($upload === null || !$upload->isComplete()) {
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
     * Where a session this instance has not asked about yet stands.
     *
     * A session Google has forgotten cannot be appended to, so it is a
     * refusal here rather than the null {@see partialSize()} turns into a
     * fresh start: this method is reached with bytes in hand and a caller
     * that believes a transfer is under way.
     *
     * @param array{session: string, total: int, offset: ?int, fileId: string} $session
     */
    private function probedOffset(array $session): int
    {
        $upload = $this->client->probeUpload($session['session'], $session['total']);
        if ($upload === null) {
            throw new \RuntimeException('The upload session no longer exists at the destination.');
        }

        return $upload->offset;
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
            [$folders, $name] = self::locate($key);
            $parentId = $this->folderIdOf($folders, false);
            if ($parentId === null) {
                return;
            }
            foreach ($this->client->findDuplicates($this->accessToken(), $parentId, $name, $keepId) as $id) {
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
            [$folders, $name] = self::locate($key);
            $parentId = $this->folderIdOf($folders, false);
            // No folder means no file: nothing was ever written there, and
            // a read must not create the album folder it is asking about.
            $this->metadata[$key] = $parentId === null
                ? null
                : $this->client->findFile($this->accessToken(), $parentId, $name);
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
        // **Nothing is caught here, and that is the fix rather than an
        // omission.** Null out of this method means « there is no
        // transfer under this key », which every caller turns into
        // starting a fresh one — so swallowing a 5xx or a cURL error on
        // the lookup would make a passing network failure indistinguishable
        // from an upload that was never opened, and send a multi-gibibyte
        // archive again from byte zero. A destination that cannot be
        // asked is a run that fails and comes back, not a transfer that
        // never existed. The one thing that IS tolerated is a note whose
        // CONTENT does not parse, below: that object is this class's own,
        // and an unreadable one describes no session anybody can resume.
        $file = $this->metadataFor($this->partialKey($key));
        if ($file === null) {
            return null;
        }

        $raw = json_decode($this->client->download($this->accessToken(), $file['id']), true);

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

    public function renameRootFolder(string $name): void
    {
        if ($this->config->folderId === '') {
            return;
        }

        $this->client->renameFile($this->accessToken(), $this->config->folderId, $name);
    }

    public function trashRootFolder(): void
    {
        if ($this->config->folderId === '') {
            return;
        }

        $this->client->trashFile($this->accessToken(), $this->config->folderId);
    }

    /**
     * The folders and the file name a key maps to.
     *
     * Every segment before the last `/` is a folder, the last is the file.
     * A key of this class's own ({@see INTERNAL_PREFIX}, at the top) goes
     * into {@see TECHNICAL_FOLDER} so that none of it sits among the
     * albums an operator browses. An empty segment is refused rather than
     * guessed at: `a//b` or a leading `/` names no folder Drive could
     * hold, and silently collapsing it would make two keys one file.
     *
     * @return array{0: list<string>, 1: string}
     */
    private static function locate(string $key): array
    {
        $segments = explode('/', $key);
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \RuntimeException("Invalid storage key: {$key}");
            }
        }

        $name = (string) array_pop($segments);
        if ($segments === [] && str_starts_with($name, self::INTERNAL_PREFIX)) {
            $segments = [self::TECHNICAL_FOLDER];
        }

        return [$segments, $name];
    }

    /** @return list<string> */
    private static function segmentsOf(string $path): array
    {
        return $path === '' ? [] : explode('/', $path);
    }

    /**
     * The id of the folder at $segments below the location's own, or null
     * when it does not exist and $create is false.
     *
     * @param list<string> $segments
     * @throws DriveAccessException
     */
    private function folderIdOf(array $segments, bool $create): ?string
    {
        $path = '';
        $id = $this->rootFolderId();

        foreach ($segments as $segment) {
            $child = $path === '' ? $segment : $path . '/' . $segment;
            if (!array_key_exists($child, $this->folderIds)) {
                $this->folderIds[$child] = $this->client->findFolder($this->accessToken(), $id, $segment);
            }

            $found = $this->folderIds[$child];
            if ($found === null) {
                if (!$create) {
                    return null;
                }
                $found = $this->createFolderUnder($id, $segment);
                $this->folderIds[$child] = $found;
                unset($this->subfolders[$path]);
            }

            $id = $found;
            $path = $child;
        }

        return $id;
    }

    /**
     * Creates a sub-folder, **and gives way to an older one**.
     *
     * Two runs writing the first photographs of an album at the same
     * moment both find no folder `5` and both create one. Every lookup
     * answers with the OLDEST of namesakes, so the younger one would hold
     * files nothing ever finds again. Asking once more after creating —
     * one request per album, not per file — lets the loser delete its own
     * empty folder and write into the winner's.
     *
     * @throws DriveAccessException
     */
    private function createFolderUnder(string $parentId, string $name): string
    {
        $created = $this->client->createFolder($this->accessToken(), $name, $parentId);
        $oldest = $this->client->findFolder($this->accessToken(), $parentId, $name);
        if ($oldest === null || $oldest === $created) {
            return $created;
        }

        try {
            $this->client->deleteFile($this->accessToken(), $created);
        } catch (\Throwable) {
            // An empty folder left behind is untidy; failing a write over
            // it would not be.
        }

        return $oldest;
    }

    /**
     * The sub-folder names of $path, sorted, asked once per instance.
     *
     * @return list<string>
     * @throws DriveAccessException
     */
    private function subfoldersOf(string $path): array
    {
        if (isset($this->subfolders[$path])) {
            return $this->subfolders[$path];
        }

        $id = $this->folderIdOf(self::segmentsOf($path), false);
        $names = [];
        if ($id !== null) {
            $pageToken = null;
            do {
                $page = $this->client->listFolders($this->accessToken(), $id, $pageToken);
                foreach ($page['folders'] as $folder) {
                    $child = $path === '' ? $folder['name'] : $path . '/' . $folder['name'];
                    // Oldest first, as findFolder() answers: the first of
                    // two namesakes is the one every lookup agrees on.
                    if (!array_key_exists($child, $this->folderIds) || $this->folderIds[$child] === null) {
                        $this->folderIds[$child] = $folder['id'];
                    }
                    if ($folder['name'] !== '' && !str_contains($folder['name'], '/')) {
                        $names[$folder['name']] = true;
                    }
                }
                $pageToken = $page['cursor'];
            } while ($pageToken !== null);
        }

        $names = array_map('strval', array_keys($names));
        usort($names, 'strcmp');

        return $this->subfolders[$path] = $names;
    }

    /**
     * The folder the walk visits after $path, in depth-first `strcmp`
     * order, without leaving $start — or null when the walk is over.
     *
     * Derived from the path alone, which is what keeps the cursor small:
     * a folder deleted since the cursor was written is stepped over from
     * its parent's list of what remains, rather than breaking the walk.
     *
     * @throws DriveAccessException
     */
    private function folderAfter(string $path, string $start): ?string
    {
        $depth = count(self::segmentsOf($path)) - count(self::segmentsOf($start));
        if ($depth < self::WALK_DEPTH_CEILING) {
            $children = $this->subfoldersOf($path);
            if ($children !== []) {
                return $path === '' ? $children[0] : $path . '/' . $children[0];
            }
        }

        $current = $path;
        while ($current !== $start && $current !== '') {
            $slash = strrpos($current, '/');
            $parent = $slash === false ? '' : substr($current, 0, $slash);
            $name = $slash === false ? $current : substr($current, $slash + 1);

            foreach ($this->subfoldersOf($parent) as $sibling) {
                if (strcmp($sibling, $name) > 0) {
                    return $parent === '' ? $sibling : $parent . '/' . $sibling;
                }
            }
            $current = $parent;
        }

        return null;
    }

    /** Drops everything remembered about $path and what was under it. */
    private function forgetFolder(string $path): void
    {
        // Cast: PHP turns a key such as '5' into the integer 5.
        foreach (array_keys($this->folderIds) as $known) {
            $known = (string) $known;
            if ($known === $path || str_starts_with($known, $path . '/')) {
                unset($this->folderIds[$known]);
            }
        }
        foreach (array_keys($this->subfolders) as $known) {
            $known = (string) $known;
            if ($known === $path || str_starts_with($known, $path . '/')) {
                unset($this->subfolders[$known]);
            }
        }
        $slash = strrpos($path, '/');
        unset($this->subfolders[$slash === false ? '' : substr($path, 0, $slash)]);
        $this->metadata = [];
    }

    private static function encodeCursor(string $folder, ?string $pageToken): string
    {
        return (string) json_encode(['folder' => $folder, 'page' => $pageToken], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function decodeCursor(string $cursor, string $start): array
    {
        $decoded = json_decode($cursor, true);
        $folder = is_array($decoded) ? ($decoded['folder'] ?? null) : null;
        $page = is_array($decoded) ? ($decoded['page'] ?? null) : null;

        // **A cursor from somewhere else is refused, not reinterpreted.**
        // The one realistic origin is a pass paused before #474, holding
        // a page token of the old flat folder; reading it as « start
        // over » would be a guess, and a pass that fails clears its state
        // and restarts from the top anyway, which is the same outcome
        // honestly reached.
        if (
            !is_string($folder)
            || ($page !== null && !is_string($page))
            || ($start !== '' && $folder !== $start && !str_starts_with($folder, $start . '/'))
        ) {
            throw new \RuntimeException('Unreadable listing cursor for a Google Drive location.');
        }

        return [$folder, $page];
    }

    /**
     * The location's own folder, `ScoutMagic/<label>/` — **by id, and
     * only by id.**
     *
     * It is written by the connection flow and by nothing else, and it is
     * never looked up by name: that is what made every Drive location of
     * an account share one folder, and what would make a location renamed
     * in Drive lose its files. A row without one is a location that was
     * never connected, and nothing can be written for it.
     *
     * @throws DriveAccessException
     */
    private function rootFolderId(): string
    {
        if ($this->config->folderId === '') {
            throw DriveAccessException::of(
                'Cet emplacement n\'a pas encore de dossier sur Google Drive : raccordez un compte depuis sa fiche.'
            );
        }

        return $this->config->folderId;
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
