<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection;

use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Protection\InventoryEntry;
use Core\Storage\Location\Protection\StorageInventory;
use Core\Storage\Location\Protection\StorageInventoryStore;
use PHPUnit\Framework\TestCase;

/**
 * The inventory lives in the destination, and this is what that buys.
 *
 * Against a real `LocalStorageBackend` rather than a mock: what is being
 * tested is a sequence of put/list/get/delete whose whole point is what
 * survives an interruption between two of them, and a mock would only
 * prove that the calls were made in the order the test expected them.
 */
final class StorageInventoryStoreTest extends TestCase
{
    private string $directory;
    private LocalStorageBackend $destination;
    private StorageInventoryStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/inventory_store_' . uniqid();
        mkdir($this->directory, 0755, true);
        $this->destination = new LocalStorageBackend($this->directory);
        $this->store = new StorageInventoryStore();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function load(): StorageInventory
    {
        return $this->store->load($this->destination, 3, 'Galerie', 5);
    }

    // ————— The round trip —————

    public function testAnEmptyDestinationAnswersAnEmptyInventory(): void
    {
        $inventory = $this->load();

        $this->assertSame(0, $inventory->count());
        $this->assertSame(3, $inventory->sourceLocationId);
    }

    public function testWhatIsWrittenComesBack(): void
    {
        $inventory = $this->load();
        $inventory->put('12/med_88.jpg', new InventoryEntry(
            sizeBytes: 4096,
            md5: str_repeat('a', 32),
            copiedAt: '2026-09-14T10:00:00+00:00'
        ));
        $this->store->save($this->destination, $inventory);

        $reloaded = $this->load();

        $this->assertSame(1, $reloaded->count());
        $entry = $reloaded->get('12/med_88.jpg');
        $this->assertNotNull($entry);
        $this->assertSame(4096, $entry->sizeBytes);
        $this->assertSame(str_repeat('a', 32), $entry->md5);
        $this->assertTrue($entry->isComplete());
    }

    /**
     * **A copy found on a disk in three years describes itself** (D12).
     * Without the source's own name in it, it is a folder of files whose
     * provenance nobody can establish.
     */
    public function testTheDocumentNamesTheInstallationItCameFrom(): void
    {
        $this->store->save($this->destination, $this->load());

        $reloaded = $this->load();

        $this->assertSame('Galerie', $reloaded->sourceLabel);
        $this->assertSame(3, $reloaded->sourceLocationId);
        $this->assertSame(5, $reloaded->destinationLocationId);
    }

    // ————— Never overwritten in place —————

    /**
     * A second save writes a NEW key and removes the old one, so the
     * destination never holds a half-written document under the name the
     * reader is about to open.
     */
    public function testASaveWritesANewKeyAndRemovesThePreviousOne(): void
    {
        $this->store->save($this->destination, $this->load());
        $first = $this->documentKeys();
        $this->assertCount(1, $first);

        $inventory = $this->load();
        $inventory->put('1/a.jpg', new InventoryEntry(sizeBytes: 1, copiedAt: 'now'));
        $this->store->save($this->destination, $inventory);

        $second = $this->documentKeys();
        $this->assertCount(1, $second, 'the previous document must be removed, not accumulated');
        $this->assertNotSame($first, $second, 'and the new one must be a different key');
    }

    /**
     * **Interrupted between the write and the delete: two documents, and
     * the reader keeps the most recent complete one.**
     *
     * That is the expected outcome of a crash, not a corruption — which is
     * why the reader sorts by the timestamp in the name rather than
     * trusting there to be exactly one.
     */
    public function testAnInterruptedReplacementLeavesTwoDocumentsAndTheNewestWins(): void
    {
        $old = $this->load();
        $old->put('1/old.jpg', new InventoryEntry(sizeBytes: 1, copiedAt: 'then'));
        $this->store->save($this->destination, $old);

        // The crash: a newer document is written and the old one is never
        // removed. Written by hand because the store, working correctly,
        // never leaves this state behind.
        $newer = StorageInventory::empty(3, 'Galerie', 5);
        $newer->put('1/new.jpg', new InventoryEntry(sizeBytes: 2, copiedAt: 'now'));
        $this->writeDocumentByHand('2099-01-01T00:00:00+00:00', $newer);

        $this->assertCount(2, $this->documentKeys(), 'the fixture must really leave two behind');

        $loaded = $this->load();

        $this->assertTrue($loaded->has('1/new.jpg'), 'the newest document must win');
        $this->assertFalse($loaded->has('1/old.jpg'));
    }

    /**
     * And when the newest is the one that got truncated, the reader falls
     * back to the one before it rather than answering « nothing is here ».
     *
     * Answering « nothing » would make the next pass copy the whole source
     * again — and, once entries are gone, never delete anything that
     * should be.
     */
    public function testATruncatedNewestDocumentFallsBackToThePreviousOne(): void
    {
        $good = $this->load();
        $good->put('1/kept.jpg', new InventoryEntry(sizeBytes: 1, copiedAt: 'then'));
        $this->store->save($this->destination, $good);

        $this->destination->put(
            StorageInventoryStore::RESERVED_PREFIX . 'protection-source-3.20991231-235959-000.json.gz',
            'not gzip at all',
            'application/gzip'
        );

        $loaded = $this->load();

        $this->assertTrue($loaded->has('1/kept.jpg'), 'a truncated newest must not erase what is known');
    }

    // ————— Rewritten only if something changed —————

    public function testSavingAnUnchangedInventoryWritesNothing(): void
    {
        $inventory = $this->load();
        $inventory->put('1/a.jpg', new InventoryEntry(sizeBytes: 1, copiedAt: 'now'));
        $this->store->save($this->destination, $inventory);

        $reloaded = $this->load();
        $written = $this->store->save($this->destination, $reloaded, $reloaded->fingerprint());

        $this->assertFalse($written, 'an unchanged inventory must not be rewritten every night');
    }

    public function testSavingAChangedInventoryDoesWrite(): void
    {
        $inventory = $this->load();
        $this->store->save($this->destination, $inventory);

        $reloaded = $this->load();
        $fingerprint = $reloaded->fingerprint();
        $reloaded->put('1/b.jpg', new InventoryEntry(sizeBytes: 2, copiedAt: 'now'));

        $this->assertTrue($this->store->save($this->destination, $reloaded, $fingerprint));
    }

    /**
     * The fingerprint deliberately excludes the generation date, which
     * changes every pass by definition — comparing documents that carried
     * it would find a difference every night and rewrite megabytes for
     * nothing.
     */
    public function testTheFingerprintIgnoresTheGenerationDate(): void
    {
        $a = StorageInventory::empty(3, 'Galerie', 5);
        $b = StorageInventory::empty(3, 'Galerie', 5);
        $a->put('1/a.jpg', new InventoryEntry(sizeBytes: 1, copiedAt: 'now'));
        $b->put('1/a.jpg', new InventoryEntry(sizeBytes: 1, copiedAt: 'now'));

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame(
            $a->toJson(new \DateTimeImmutable('2026-01-01')),
            $b->toJson(new \DateTimeImmutable('2026-06-01')),
            'the documents themselves do differ — it is the fingerprint that must not'
        );
    }

    // ————— Invisible from the content —————

    /**
     * If this destination ever also serves a gallery, the inventory must
     * not be listed as a medium. A gallery key is always `{albumId}/…`,
     * and nothing under the reserved prefix can be mistaken for one.
     */
    public function testTheDocumentLivesUnderTheReservedPrefix(): void
    {
        $this->store->save($this->destination, $this->load());

        foreach ($this->documentKeys() as $key) {
            $this->assertTrue(
                StorageInventoryStore::isReservedKey($key),
                "{$key} would be listed as content by a consumer of this destination."
            );
        }
    }

    public function testAnOrdinaryContentKeyIsNotReserved(): void
    {
        $this->assertFalse(StorageInventoryStore::isReservedKey('12/med_88.jpg'));
    }

    // ————— Refusing what it cannot read —————

    public function testADocumentFromALaterFormatVersionIsRefusedRatherThanReadPartially(): void
    {
        $json = (string) json_encode([
            'format' => StorageInventory::FORMAT,
            'format_version' => StorageInventory::FORMAT_VERSION + 1,
            'entries' => ['1/a.jpg' => ['size' => 1]],
        ]);

        $this->assertNull(StorageInventory::fromJson($json));
    }

    public function testSomeOtherJsonDocumentIsNotAnInventory(): void
    {
        $this->assertNull(StorageInventory::fromJson('{"hello":"world"}'));
        $this->assertNull(StorageInventory::fromJson('not json'));
    }

    // ————— Helpers —————

    /**
     * @return list<string>
     */
    private function documentKeys(): array
    {
        $keys = [];
        foreach ($this->destination->list(StorageInventoryStore::RESERVED_PREFIX)->objects as $object) {
            $keys[] = $object->key;
        }
        sort($keys);

        return $keys;
    }

    private function writeDocumentByHand(string $isoDate, StorageInventory $inventory): void
    {
        $at = new \DateTimeImmutable($isoDate);
        $payload = gzencode($inventory->toJson($at), 6);
        $this->assertIsString($payload);
        $this->destination->put(
            StorageInventoryStore::RESERVED_PREFIX
                . 'protection-source-3.' . $at->format('Ymd-His-v') . '.json.gz',
            $payload,
            'application/gzip'
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
