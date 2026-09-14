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
use Core\Storage\Location\Protection\StorageInventoryStore;
use Core\Storage\Location\Protection\StorageProtection;
use Core\Storage\Location\StorageListing;
use PHPUnit\Framework\TestCase;

/**
 * One run of one protection, end to end against two real folders.
 *
 * The tests the chantier asks for by name are all here: resumption after a
 * cut, an incomplete inventory marking nothing, a mass disappearance
 * stopping the sweep, a grace period that a database restore cannot move,
 * and `delete` on a key that is already gone.
 */
final class ProtectionPassTest extends TestCase
{
    private string $root;
    private LocalStorageBackend $source;
    private LocalStorageBackend $destination;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/protection_pass_' . uniqid();
        mkdir($this->root . '/source', 0755, true);
        mkdir($this->root . '/destination', 0755, true);
        $this->source = new LocalStorageBackend($this->root . '/source');
        $this->destination = new LocalStorageBackend($this->root . '/destination');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function pass(?\DateTimeImmutable $now = null): ProtectionPass
    {
        return new ProtectionPass(
            new StorageInventoryStore(),
            new ProtectedCopier(),
            $now !== null ? static fn(): \DateTimeImmutable => $now : null
        );
    }

    private function protection(
        int $gracePeriodDays = 30,
        ?string $phase = null,
        ?string $startedAt = null,
        ?string $cursor = null,
        int $seen = 0
    ): StorageProtection {
        return new StorageProtection(
            id: 1,
            sourceLocationId: 3,
            destinationLocationId: 5,
            enabled: true,
            gracePeriodDays: $gracePeriodDays,
            cadenceHours: 24,
            passPhase: $phase,
            passStartedAt: $startedAt,
            passCursor: $cursor,
            passSeenCount: $seen
        );
    }

    private function always(): callable
    {
        return static fn(): bool => true;
    }

    /**
     * A first, complete pass on a clock the test controls.
     *
     * **Every test that later uses a fixture date needs this.** The stamp
     * an entry carries is the pass's own start, compared for exact
     * equality — so a first pass left on the real clock stamps entries
     * with today, and a second pass dated last week matches none of them.
     */
    private function firstPassAt(string $when): void
    {
        $this->pass(new \DateTimeImmutable($when))->run(
            $this->protection(startedAt: $when),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );
    }

    private function inventory(): \Core\Storage\Location\Protection\StorageInventory
    {
        return (new StorageInventoryStore())->load($this->destination, 3, 'Galerie', 5);
    }

    // ————— Phase 1 —————

    public function testAFirstPassCopiesEverythingAndRecordsIt(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbbb', 'image/jpeg');

        $result = $this->pass()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertTrue($result->finished);
        $this->assertSame(2, $result->copiedCount);
        $this->assertSame('aaa', $this->destination->get('12/a.jpg'));
        $this->assertSame(2, $this->inventory()->count());
    }

    /** A second pass over an unchanged source copies nothing at all. */
    public function testASecondPassOverAnUnchangedSourceCopiesNothing(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->pass()->run($this->protection(), $this->source, $this->destination, 'Galerie', $this->always());

        $second = $this->pass()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(0, $second->copiedCount);
    }

    /** A file whose size changed is copied again. */
    public function testAFileThatChangedSizeIsCopiedAgain(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->pass()->run($this->protection(), $this->source, $this->destination, 'Galerie', $this->always());

        $this->source->put('12/a.jpg', 'aaaaaaaaaa', 'image/jpeg');
        $second = $this->pass()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(1, $second->copiedCount);
        $this->assertSame('aaaaaaaaaa', $this->destination->get('12/a.jpg'));
    }

    /**
     * The subsystem's own bookkeeping is not content, wherever it is met.
     * A source that is also somebody's destination carries an inventory of
     * its own, and copying it onward would describe the wrong pair of
     * locations.
     */
    public function testAnInventoryFileAtTheSourceIsNotCopiedOnward(): void
    {
        $this->source->put(
            StorageInventoryStore::RESERVED_PREFIX . 'protection-source-9.20260101-000000-000-abcd.json.gz',
            'whatever',
            'application/gzip'
        );
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');

        $result = $this->pass()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(1, $result->copiedCount);
        $this->assertFalse($this->inventory()->has(
            StorageInventoryStore::RESERVED_PREFIX . 'protection-source-9.20260101-000000-000-abcd.json.gz'
        ));
    }

    /** Out of budget mid-listing: the run pauses and says where it got to. */
    public function testARunOutOfBudgetPausesAndRecordsItsCursor(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbb', 'image/jpeg');
        $this->source->put('12/c.jpg', 'ccc', 'image/jpeg');

        // **Keyed on an observable side effect, not on a call count.**
        // The same budget is handed to the copier, which asks it between
        // slices — so « the third call » is not « the third object », and
        // a test written that way would be measuring the copier's
        // chunking rather than the pass's cursor.
        $destination = $this->destination;
        $result = $this->pass()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => !$destination->exists('12/a.jpg')
        );

        $this->assertFalse($result->finished);
        $this->assertSame(StorageProtection::PHASE_INVENTORY, $result->phase);
        $this->assertSame('12/a.jpg', $result->cursor);
    }

    /** And the next run picks up from that cursor rather than from zero. */
    public function testTheNextRunResumesFromTheRecordedCursor(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbb', 'image/jpeg');

        $destination = $this->destination;
        $first = $this->pass()->run(
            $this->protection(),
            $this->source,
            $this->destination,
            'Galerie',
            static fn(): bool => !$destination->exists('12/a.jpg')
        );

        $second = $this->pass()->run(
            $this->protection(
                phase: StorageProtection::PHASE_INVENTORY,
                startedAt: '2026-09-14 02:00:00',
                cursor: $first->cursor,
                seen: $first->seenCount
            ),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertTrue($second->finished);
        $this->assertSame(1, $second->copiedCount, 'only the key after the cursor was left to copy');
        $this->assertSame('bbb', $this->destination->get('12/b.jpg'));
    }

    // ————— Phase 2, and D15 —————

    /**
     * **An incomplete source inventory marks nothing** (D15). This is the
     * catastrophic failure mode: credentials expire, the source answers
     * « empty », every file is marked gone, and the grace period then
     * erases the whole copy.
     */
    public function testAPassThatRanOutOfBudgetMarksNoDisappearance(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbb', 'image/jpeg');
        $this->source->put('12/c.jpg', 'ccc', 'image/jpeg');
        $this->firstPassAt('2026-09-13 02:00:00');

        // One really is gone — and the run will stop before its listing
        // ever reaches the end, so it cannot know that.
        $this->source->delete('12/c.jpg');

        $seen = 0;
        $result = $this->pass(new \DateTimeImmutable('2026-09-14 02:00:00'))->run(
            $this->protection(
                phase: StorageProtection::PHASE_INVENTORY,
                startedAt: '2026-09-14 02:00:00'
            ),
            $this->source,
            $this->destination,
            'Galerie',
            static function () use (&$seen): bool {
                return $seen++ < 1;
            }
        );

        $this->assertFalse($result->finished, 'the listing must not have reached its end');
        $this->assertSame(0, $result->markedAbsentCount);
        $this->assertNull(
            $this->inventory()->get('12/c.jpg')?->absentFromSourceSince,
            'a listing that did not finish must not conclude anything about what is missing'
        );
    }

    /**
     * And the opposite: a source that really did empty is believed, once
     * its listing has genuinely finished.
     *
     * Small enough to be under the mass-disappearance floor, because that
     * guard is a different rule with its own test — this one is only
     * about « the listing finished, so its conclusion is usable ».
     */
    public function testASourceThatReallyEmptiedHasItsFilesMarked(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbb', 'image/jpeg');
        $this->firstPassAt('2026-09-13 02:00:00');

        $this->source->delete('12/a.jpg');
        $this->source->delete('12/b.jpg');

        $result = $this->pass(new \DateTimeImmutable('2026-09-14 02:00:00'))->run(
            $this->protection(startedAt: '2026-09-14 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertTrue($result->finished);
        $this->assertSame(2, $result->markedAbsentCount);
        $this->assertSame(0, $result->deletedCount, 'marking is not deleting');
        $this->assertTrue($this->destination->exists('12/a.jpg'));
    }

    /**
     * A source that really lost one file has it marked — and the file is
     * NOT deleted, because the grace period has not run out.
     */
    public function testAFileTheSourceLostIsMarkedButNotDeleted(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->source->put('12/b.jpg', 'bbb', 'image/jpeg');
        $this->firstPassAt('2026-09-13 02:00:00');

        $this->source->delete('12/a.jpg');
        $result = $this->pass(new \DateTimeImmutable('2026-09-14 02:00:00'))->run(
            $this->protection(startedAt: '2026-09-14 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(1, $result->markedAbsentCount);
        $this->assertSame(0, $result->deletedCount);
        $this->assertTrue($this->destination->exists('12/a.jpg'), 'the copy is what protects a deletion by mistake');
        $this->assertNotNull($this->inventory()->get('12/a.jpg')?->absentFromSourceSince);
    }

    /**
     * **A file that came back loses its countdown**, in one move rather
     * than two: otherwise it would be purged from the copy while sitting
     * in plain view at the source.
     */
    public function testAFileThatCameBackStopsBeingMarkedAbsent(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->firstPassAt('2026-09-13 02:00:00');

        $this->source->delete('12/a.jpg');
        $this->pass(new \DateTimeImmutable('2026-09-14 02:00:00'))->run(
            $this->protection(startedAt: '2026-09-14 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );
        $this->assertNotNull($this->inventory()->get('12/a.jpg')?->absentFromSourceSince);

        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->pass(new \DateTimeImmutable('2026-09-15 02:00:00'))->run(
            $this->protection(startedAt: '2026-09-15 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertNull($this->inventory()->get('12/a.jpg')?->absentFromSourceSince);
    }

    /** Once the grace period has run out, the copy does let go. */
    public function testAFileAbsentPastTheGracePeriodIsDeletedFromTheCopy(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->firstPassAt('2026-08-25 02:00:00');

        $this->source->delete('12/a.jpg');
        $this->pass(new \DateTimeImmutable('2026-09-01 02:00:00'))->run(
            $this->protection(gracePeriodDays: 7, startedAt: '2026-09-01 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $result = $this->pass(new \DateTimeImmutable('2026-09-20 02:00:00'))->run(
            $this->protection(gracePeriodDays: 7, startedAt: '2026-09-20 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(1, $result->deletedCount);
        $this->assertFalse($this->destination->exists('12/a.jpg'));
        $this->assertFalse($this->inventory()->has('12/a.jpg'));
    }

    /**
     * **The countdown is insensitive to a restore of this site's
     * database** (D13), because it never touched the database: it is in
     * the destination's own inventory, beside the file it concerns.
     */
    public function testTheCountdownSurvivesAProtectionRowThatLostItsWorkingState(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->firstPassAt('2026-08-25 02:00:00');

        $this->source->delete('12/a.jpg');
        $this->pass(new \DateTimeImmutable('2026-09-01 02:00:00'))->run(
            $this->protection(gracePeriodDays: 7, startedAt: '2026-09-01 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );
        $markedAt = $this->inventory()->get('12/a.jpg')?->absentFromSourceSince;

        // The restore: every column of working state is back to nothing.
        $result = $this->pass(new \DateTimeImmutable('2026-09-20 02:00:00'))->run(
            $this->protection(gracePeriodDays: 7, startedAt: '2026-09-20 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertNotNull($markedAt);
        $this->assertSame(
            1,
            $result->deletedCount,
            'the countdown restarted, so a file lost in September would have been kept for ever'
        );
    }

    /**
     * **Beyond a share of disappearances in one pass, the sweep stops and
     * says so** (D15). The failure mode of this guard is a copy that keeps
     * too much, which is the direction to fail in.
     */
    public function testAMassDisappearanceStopsTheSweepRatherThanMarkingEverything(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->source->put("12/{$i}.jpg", "content-{$i}", 'image/jpeg');
        }
        $this->firstPassAt('2026-09-13 02:00:00');

        // Half the source goes missing at once — the shape of expired
        // credentials or a truncated listing, not of somebody tidying up.
        for ($i = 0; $i < 20; $i++) {
            $this->source->delete("12/{$i}.jpg");
        }

        $result = $this->pass(new \DateTimeImmutable('2026-09-14 02:00:00'))->run(
            $this->protection(startedAt: '2026-09-14 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertTrue($result->refusedMassDisappearance);
        $this->assertSame(0, $result->deletedCount);
        foreach ($this->inventory()->entries() as $key => $entry) {
            $this->assertNull($entry->absentFromSourceSince, "{$key} was marked despite the refusal");
        }
    }

    /** A handful going at once, on a small copy, is not a mass disappearance. */
    public function testASmallCopyLosingAFewFilesIsNotTreatedAsAMassDisappearance(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->source->put("12/{$i}.jpg", "content-{$i}", 'image/jpeg');
        }
        $this->firstPassAt('2026-09-13 02:00:00');

        $this->source->delete('12/0.jpg');
        $this->source->delete('12/1.jpg');

        $result = $this->pass(new \DateTimeImmutable('2026-09-14 02:00:00'))->run(
            $this->protection(startedAt: '2026-09-14 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertFalse($result->refusedMassDisappearance);
        $this->assertSame(2, $result->markedAbsentCount);
    }

    /**
     * **`delete` on a key that is already gone is a success, not an
     * error** — otherwise every pass that follows a restore fails on
     * ghosts.
     */
    public function testDeletingAKeyTheDestinationNoLongerHasIsASuccess(): void
    {
        $this->source->put('12/a.jpg', 'aaa', 'image/jpeg');
        $this->firstPassAt('2026-08-25 02:00:00');

        $this->source->delete('12/a.jpg');
        $this->pass(new \DateTimeImmutable('2026-09-01 02:00:00'))->run(
            $this->protection(gracePeriodDays: 7, startedAt: '2026-09-01 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        // The ghost: somebody removed it from the destination by hand.
        $this->destination->delete('12/a.jpg');

        $result = $this->pass(new \DateTimeImmutable('2026-09-20 02:00:00'))->run(
            $this->protection(gracePeriodDays: 7, startedAt: '2026-09-20 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(1, $result->deletedCount);
        $this->assertFalse($this->inventory()->has('12/a.jpg'));
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
