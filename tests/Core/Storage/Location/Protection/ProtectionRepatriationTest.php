<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection;

use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Protection\ProtectedCopier;
use Core\Storage\Location\Protection\ProtectionPass;
use Core\Storage\Location\Protection\ProtectionRepatriation;
use Core\Storage\Location\Protection\StorageInventoryStore;
use Core\Storage\Location\Protection\StorageProtection;
use PHPUnit\Framework\TestCase;

/**
 * Bringing back, from the copy, what the source no longer has — the repair
 * a restore needs, and the reason the whole mechanism is worth having.
 */
final class ProtectionRepatriationTest extends TestCase
{
    private string $root;
    private LocalStorageBackend $source;
    private LocalStorageBackend $destination;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/repatriation_' . uniqid();
        mkdir($this->root . '/source', 0755, true);
        mkdir($this->root . '/destination', 0755, true);
        $this->source = new LocalStorageBackend($this->root . '/source');
        $this->destination = new LocalStorageBackend($this->root . '/destination');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function protection(int $gracePeriodDays = 30): StorageProtection
    {
        return new StorageProtection(
            id: 1,
            sourceLocationId: 3,
            destinationLocationId: 5,
            enabled: true,
            gracePeriodDays: $gracePeriodDays,
            cadenceHours: 24,
            passStartedAt: '2026-09-13 02:00:00'
        );
    }

    private function repatriation(): ProtectionRepatriation
    {
        return new ProtectionRepatriation(new StorageInventoryStore(), new ProtectedCopier());
    }

    private function firstPass(): void
    {
        (new ProtectionPass(
            new StorageInventoryStore(),
            new ProtectedCopier(),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-09-13 02:00:00')
        ))->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => true
        );
    }

    private function inventory(): \Core\Storage\Location\Protection\StorageInventory
    {
        return (new StorageInventoryStore())->load($this->destination, 3, 'Galerie', 5);
    }

    /**
     * The case this exists for: a database restored to last month knows
     * about albums whose files were deleted since. The rows come back, the
     * files do not, and every one of those albums is holed.
     */
    public function testFilesTheSourceLostAreBroughtBackFromTheCopy(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbbb', 'image/jpeg');
        $this->firstPass();

        $this->source->delete('12/a.jpg');
        $this->source->delete('12/b.jpg');

        $result = $this->repatriation()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => true
        );

        $this->assertTrue($result->finished);
        $this->assertSame(2, $result->restoredCount);
        $this->assertSame('aaa', $this->source->get('12/a.jpg'));
        $this->assertSame('bbbb', $this->source->get('12/b.jpg'));
    }

    /** A file the source still has is left exactly as it is. */
    public function testAFileTheSourceStillHasIsNotTouched(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->firstPass();

        // Somebody replaced it at the source since the copy was taken.
        $this->source->put('12/a.jpg', 'newer content', 'image/jpeg');

        $result = $this->repatriation()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => true
        );

        $this->assertSame(0, $result->restoredCount);
        $this->assertSame(
            'newer content',
            $this->source->get('12/a.jpg'),
            'repatriation must never overwrite what the source has'
        );
    }

    /**
     * **A file that is back stops being marked absent**, in the same move:
     * the countdown that would have purged it from the copy is exactly
     * what this operation has just made wrong.
     */
    public function testARepatriatedFileStopsBeingMarkedAbsent(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->firstPass();
        $this->source->delete('12/a.jpg');

        // A nightly pass notices and starts the countdown.
        (new ProtectionPass(
            new StorageInventoryStore(),
            new ProtectedCopier(),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-09-14 02:00:00')
        ))->run(
            new StorageProtection(
                id: 1,
                sourceLocationId: 3,
                destinationLocationId: 5,
                enabled: true,
                gracePeriodDays: 30,
                cadenceHours: 24,
                passStartedAt: '2026-09-14 02:00:00'
            ),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => true
        );
        $this->assertNotNull($this->inventory()->get('12/a.jpg')?->absentFromSourceSince);

        $this->repatriation()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => true
        );

        $this->assertNull(
            $this->inventory()->get('12/a.jpg')?->absentFromSourceSince,
            'a file that is back must not keep a countdown towards being purged from the copy'
        );
    }

    /**
     * An entry describing a file that is nowhere is removed rather than
     * kept: the next pass would read it as « already protected ».
     */
    public function testAnEntryWhoseFileIsGoneFromBothEndsIsForgotten(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->firstPass();

        $this->source->delete('12/a.jpg');
        $this->destination->delete('12/a.jpg');

        $result = $this->repatriation()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => true
        );

        $this->assertSame(0, $result->restoredCount);
        $this->assertArrayHasKey('12/a.jpg', $result->failures);
        $this->assertFalse($this->inventory()->has('12/a.jpg'));
    }

    /** Out of budget: it pauses and says where to pick up. */
    public function testARunOutOfBudgetPausesAndRecordsItsCursor(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbb', 'image/jpeg');
        $this->firstPass();
        $this->source->delete('12/a.jpg');
        $this->source->delete('12/b.jpg');

        $source = $this->source;
        $result = $this->repatriation()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => !$source->exists('12/a.jpg')
        );

        $this->assertFalse($result->finished);
        $this->assertSame('12/a.jpg', $result->cursor);
        $this->assertTrue($this->source->exists('12/a.jpg'));
        $this->assertFalse($this->source->exists('12/b.jpg'));
    }

    /** And the next run continues from that cursor. */
    public function testTheNextRunContinuesFromTheCursor(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbb', 'image/jpeg');
        $this->firstPass();
        $this->source->delete('12/a.jpg');
        $this->source->delete('12/b.jpg');

        $source = $this->source;
        $first = $this->repatriation()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => !$source->exists('12/a.jpg')
        );

        $second = $this->repatriation()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => true,
            $first->cursor
        );

        $this->assertTrue($second->finished);
        $this->assertSame(1, $second->restoredCount);
        $this->assertSame('bbb', $this->source->get('12/b.jpg'));
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
