<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Core\Storage\Location\Backend\GoogleDriveBackend;
use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Backend\ObjectStorageBackend;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\Storage\Location\Backend\WebDav\WebDavClient;
use Core\Storage\Location\Backend\WebDavBackend;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\Config\WebDavLocationConfig;
use PHPUnit\Framework\TestCase;
use Tests\Core\Storage\Location\Backend\Drive\FakeDrive;
use Tests\Core\Storage\Location\Backend\WebDav\FakeWebDavServer;

/**
 * One scenario, every backend: `deletePrefix('5')` empties album 5 and
 * leaves albums 50, 51 and 512 alone.
 *
 * **Written because three backends agreed and the fourth did not** (#484).
 * `GoogleDriveBackend` read its prefix as a string that names begin with,
 * so deleting — or MIGRATING — album 5 destroyed the files of every album
 * whose id starts with 5. The rows survived, so the photographs became
 * 404s with nothing on screen and nothing in the journal; in the
 * migration path the cleanup even sits inside a `catch (\Throwable) {}`.
 *
 * **Why a shared scenario and not a fourth backend test.** Each backend
 * already had a `deletePrefix` test and all four passed: Drive's called
 * `deletePrefix('album/')`, **with** the trailing slash that no real
 * caller passes. A per-backend test cannot catch that, because the form
 * it is written in is the form under test. Holding all four to the same
 * ids, in the shape `AlbumService` actually uses, is what makes a fifth
 * backend unable to diverge in silence.
 *
 * **The ids are the point and are not arbitrary.** 5, 50, 51 and 512 are
 * the smallest set that distinguishes « the folder 5 » from « names
 * beginning with 5 », and they are ordinary album ids: any site with ten
 * albums has such a pair.
 */
final class DeletePrefixIsAFolderTest extends TestCase
{
    /** Album 5's own objects, and three neighbours that must survive. */
    private const OWN = ['5/med_9.jpg', '5/orig_9.jpg'];
    private const NEIGHBOURS = ['50/med_1.jpg', '51/med_2.jpg', '512/orig_7.jpg'];

    private string $localRoot = '';

    protected function tearDown(): void
    {
        if ($this->localRoot !== '' && is_dir($this->localRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->localRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
            }
            @rmdir($this->localRoot);
        }
    }

    public function testTheLocalDiskDeletesTheFolderAndNotTheNamesakes(): void
    {
        $this->localRoot = sys_get_temp_dir() . '/delete_prefix_' . uniqid();
        $backend = new LocalStorageBackend($this->localRoot);

        $this->assertOnlyTheFolderGoes($backend);
    }

    public function testTheWebDavShareDeletesTheCollectionAndNotTheNamesakes(): void
    {
        $share = new FakeWebDavServer();
        $backend = new WebDavBackend(
            new WebDavClient($share->transport()),
            new WebDavLocationConfig($share->baseUrl, 'unite'),
            'le-mot-de-passe'
        );

        $this->assertOnlyTheFolderGoes($backend);
    }

    /**
     * The one that was wrong.
     *
     * Drive's folder is flat — `"5/med_9.jpg"` is one file whose NAME
     * contains a slash — so this is the backend with nothing structural
     * to lean on, and the only one where the rule has to be written down.
     */
    public function testTheDriveFolderDeletesTheFolderAndNotTheNamesakes(): void
    {
        $drive = new FakeDrive();
        $backend = new GoogleDriveBackend(
            $drive->client(),
            new GoogleDriveLocationConfig('client-1', $drive->folderId, '2026-09-01T00:00:00+00:00'),
            new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org')
        );

        $this->assertOnlyTheFolderGoes($backend);
    }

    /**
     * S3 is asserted on the REQUEST it issues rather than on what a store
     * holds afterwards, because its double is a response queue with no
     * state of its own. The fact is the same one — the listing that feeds
     * the deletes is scoped to `5/` — and saying so here is better than a
     * fake bucket written only for this file.
     */
    public function testTheBucketListsUnderTheFolderAndNotTheBareId(): void
    {
        $prefixes = [];
        $mock = new MockHandler();
        $mock->append(function ($cmd) use (&$prefixes) {
            $prefixes[] = $cmd['Prefix'];

            return new Result([
                'Contents' => array_map(static fn(string $k): array => ['Key' => $k], self::OWN),
                'IsTruncated' => false,
            ]);
        });
        $deleted = [];
        $mock->append(function ($cmd) use (&$deleted) {
            foreach ($cmd['Delete']['Objects'] as $object) {
                $deleted[] = $object['Key'];
            }

            return new Result([]);
        });

        $this->bucket($mock)->deletePrefix('5');

        $this->assertSame(['5/'], $prefixes, 'the bucket was asked for every key beginning with 5');
        $this->assertSame(self::OWN, $deleted);
    }

    /**
     * And nothing may read an empty prefix — or one that trims to nothing
     * — as « the folder every key is under ».
     *
     * A bug upstream that produced `''` or `'/'` must not empty the
     * operator's own space, which on WebDAV and on the local disk is a
     * folder holding things this application never put there.
     */
    public function testNoBackendTreatsAnEmptyOrSlashPrefixAsEverything(): void
    {
        $this->localRoot = sys_get_temp_dir() . '/delete_prefix_none_' . uniqid();
        $share = new FakeWebDavServer();
        $drive = new FakeDrive();

        $backends = [
            'local' => new LocalStorageBackend($this->localRoot),
            'webdav' => new WebDavBackend(
                new WebDavClient($share->transport()),
                new WebDavLocationConfig($share->baseUrl, 'unite'),
                'le-mot-de-passe'
            ),
            'drive' => new GoogleDriveBackend(
                $drive->client(),
                new GoogleDriveLocationConfig('client-1', $drive->folderId, '2026-09-01T00:00:00+00:00'),
                new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org')
            ),
        ];

        foreach ($backends as $name => $backend) {
            $this->seed($backend);

            $backend->deletePrefix('');
            $backend->deletePrefix('/');

            $this->assertSame(
                self::sorted(array_merge(self::OWN, self::NEIGHBOURS)),
                $this->keysOf($backend),
                $name . ' emptied the whole location for a prefix that names nothing'
            );
        }
    }

    private function assertOnlyTheFolderGoes(StorageBackendInterface $backend): void
    {
        $this->seed($backend);

        // The form every real caller passes: an album id, no trailing
        // slash (Modules\Gallery\Service\AlbumService, DelegatedAlbumService,
        // Task\MigrateAlbumStorageHandler).
        $backend->deletePrefix('5');

        $this->assertSame(
            self::sorted(self::NEIGHBOURS),
            $this->keysOf($backend),
            'deleting album 5 took the files of albums whose id merely starts with 5'
        );
    }

    private function seed(StorageBackendInterface $backend): void
    {
        foreach (array_merge(self::OWN, self::NEIGHBOURS) as $key) {
            $backend->put($key, 'x', 'image/jpeg');
        }
    }

    /** @return list<string> */
    private function keysOf(StorageBackendInterface $backend): array
    {
        $keys = [];
        $cursor = null;
        do {
            $listing = $backend->list('', $cursor);
            foreach ($listing->objects as $object) {
                $keys[] = $object->key;
            }
            $cursor = $listing->cursor;
        } while ($cursor !== null);

        return self::sorted($keys);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return array_values($keys);
    }

    private function bucket(MockHandler $mock): ObjectStorageBackend
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'access', 'secret' => 'secret'],
            'handler' => $mock,
        ]);

        return new ObjectStorageBackend(
            'https://s3.example.org',
            'us-east-1',
            'scoutmagic',
            'access',
            'secret',
            null,
            $client
        );
    }
}
