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
        ?string $pageLastKey = null,
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
            passPageLastKey: $pageLastKey,
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
        // **The position within the page, which is a key; NOT the
        // cursor, which belongs to the backend.** A single page of a
        // local listing answers no continuation token at all, so the
        // cursor here is null and the key is what says where to pick up.
        // Recording the key as the cursor — as this did — is invisible
        // on a folder, whose listing happens to resume from the last key
        // it returned, and fatal on a bucket, where it reads as an S3
        // continuation token, the listing throws, and the pass restarts
        // from zero every night without ever completing.
        $this->assertSame('12/a.jpg', $result->pageLastKey);
        $this->assertNull($result->cursor);
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

    /**
     * A copy interrupted for ever does not leave bytes nothing can reach.
     *
     * The partial object is hidden from `list()` on purpose — half a JPEG
     * is a JPEG to every screen — so nothing else in the system ever meets
     * it. If the source then loses that key while the copy is paused, the
     * only thing that could still name it is the inventory. So a paused
     * copy now writes an INCOMPLETE entry: never counted as protected,
     * met by the sweep like any other, and its deletion takes the partial
     * with it.
     */
    public function testAnInterruptedCopyWhoseSourceVanishesIsNotLeftBehindForEver(): void
    {
        $payload = random_bytes(ProtectedCopier::CHUNK_BYTES * 3);
        $this->source->put('12/film.mp4', $payload, 'video/mp4');

        $slices = 0;
        $this->pass(new \DateTimeImmutable('2026-01-01 02:00:00'))->run(
            $this->protection(startedAt: '2026-01-01 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            // Two: the listing spends one before handing the budget to
            // the copier, which then gets through exactly one slice.
            static function () use (&$slices): bool {
                return $slices++ < 2;
            }
        );

        $this->assertSame(
            ProtectedCopier::CHUNK_BYTES,
            $this->destination->partialSize('12/film.mp4'),
            'what arrived must still be there for the next run'
        );
        $entry = $this->inventory()->get('12/film.mp4');
        $this->assertNotNull($entry, 'a paused copy has to be written down to be reachable later');
        $this->assertFalse($entry->isComplete(), 'and never counted as a file that is protected');

        // The source loses the file while the copy is interrupted.
        $this->source->delete('12/film.mp4');

        // A pass that completes its listing: the entry is not seen, so
        // the countdown starts.
        $this->pass(new \DateTimeImmutable('2026-01-02 02:00:00'))->run(
            $this->protection(startedAt: '2026-01-02 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        // And after the grace period the sweep reclaims both.
        $this->pass(new \DateTimeImmutable('2026-03-01 02:00:00'))->run(
            $this->protection(startedAt: '2026-03-01 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(
            0,
            $this->destination->partialSize('12/film.mp4'),
            'the abandoned partial object must not outlive the entry that named it'
        );
        $this->assertNull($this->inventory()->get('12/film.mp4'));
    }

    /**
     * **Deleting is budgeted; deciding is not.**
     *
     * The marking loop is arithmetic over a document already in memory.
     * The deletion loop makes a request per key — one round trip each on
     * a bucket — so a night on which a large batch comes out of its grace
     * period together could run past `max_execution_time`, and a run
     * killed there loses the inventory it never saved. No cursor is
     * needed for the pause: the marks are IN the document and persist, so
     * what is left over is simply deleted by the next pass.
     */
    public function testTheSweepStopsDeletingOnItsBudgetAndFinishesOnTheNextPass(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->source->put('12/' . $name . '.jpg', 'photo-' . $name, 'image/jpeg');
        }
        $this->firstPassAt('2026-01-01 02:00:00');

        foreach (['a', 'b', 'c'] as $name) {
            $this->source->delete('12/' . $name . '.jpg');
        }
        $this->pass(new \DateTimeImmutable('2026-01-02 02:00:00'))->run(
            $this->protection(startedAt: '2026-01-02 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        // Time enough for exactly one deletion. Keyed on what has really
        // gone, because a call count would also be spent by the listing.
        $stopAfterOne = function (): bool {
            $left = 0;
            foreach (['a', 'b', 'c'] as $name) {
                if ($this->destination->exists('12/' . $name . '.jpg')) {
                    $left++;
                }
            }

            return $left === 3;
        };

        $first = $this->pass(new \DateTimeImmutable('2026-03-01 02:00:00'))->run(
            $this->protection(startedAt: '2026-03-01 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $stopAfterOne
        );

        $this->assertSame(1, $first->deletedCount, 'the sweep must stop when its time is up');
        $this->assertSame(2, $this->inventory()->count(), 'and leave the rest marked, for the next pass');

        $second = $this->pass(new \DateTimeImmutable('2026-03-02 02:00:00'))->run(
            $this->protection(startedAt: '2026-03-02 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertSame(2, $second->deletedCount);
        $this->assertSame(0, $this->inventory()->count());
        foreach (['a', 'b', 'c'] as $name) {
            $this->assertFalse($this->destination->exists('12/' . $name . '.jpg'));
        }
    }

    /**
     * A run that stops in the middle of a page must not hand the backend
     * a cursor the backend never issued.
     *
     * **This is invisible on a folder and fatal on a bucket.** The local
     * listing happens to resume from the last key it returned, so
     * recording an object key as the cursor worked there by accident.
     * `StorageBackendInterface::list()` says the cursor is opaque to the
     * caller, and S3 means its own `NextContinuationToken` by it: an
     * object key handed back as one is refused, the next run's listing
     * throws, `recordPassFailed()` clears the working state, and the pass
     * starts from zero again — every night, on exactly the sources big
     * enough to need more than one run. Phase 2 never runs either, since
     * it is reached only by a phase 1 that finished (D15), so the copy
     * also never lets go of anything.
     *
     * The fake below is the part of S3 that matters here: it issues
     * tokens of its own and refuses anything else.
     */
    public function testAMidPageStopNeverHandsTheBackendACursorItDidNotIssue(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->source->put('12/' . $name . '.jpg', 'photo-' . $name, 'image/jpeg');
        }

        $paging = new class ($this->root . '/source') extends LocalStorageBackend {
            public const PAGE = 2;

            public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
            {
                if ($cursor !== null && !str_starts_with($cursor, 'token-')) {
                    throw new \RuntimeException("Not a continuation token this backend issued: {$cursor}");
                }

                $all = parent::list($prefix, null, 10_000)->objects;
                $offset = $cursor === null ? 0 : (int) substr($cursor, 6);
                $page = array_slice($all, $offset, self::PAGE);
                $next = ($offset + self::PAGE) < count($all) ? 'token-' . ($offset + self::PAGE) : null;

                return new StorageListing($page, $next);
            }
        };

        // Stops as soon as the first object has arrived — mid-page, by
        // construction, since a page holds two.
        $destination = $this->destination;
        $first = $this->pass()->run(
            $this->protection(),
            $paging,
            $this->destination,
            'Galerie',
            static fn(): bool => !$destination->exists('12/a.jpg')
        );

        $this->assertFalse($first->finished);
        $this->assertNull($first->cursor, 'the first page was not finished, so there is no token yet');
        $this->assertSame('12/a.jpg', $first->pageLastKey);

        // The next run resumes — and the backend is never asked for a
        // page with a key as its cursor, which is what would throw.
        $result = $this->pass()->run(
            $this->protection(
                phase: StorageProtection::PHASE_INVENTORY,
                cursor: $first->cursor,
                pageLastKey: $first->pageLastKey,
                seen: $first->seenCount
            ),
            $paging,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertTrue($result->finished);
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->assertTrue(
                $this->destination->exists('12/' . $name . '.jpg'),
                "12/{$name}.jpg never arrived"
            );
        }
        $this->assertSame(
            4,
            $result->copiedCount,
            'and the one the first run already carried across is not sent again'
        );
    }

    /**
     * A resume key deleted at the source must cost one replayed page, not
     * the rest of the listing.
     *
     * **The failure is silent and ends in deletion.** The skip matches
     * nothing, the key stays set, it is carried into the next page, and
     * every remaining page is skipped too — while the walk still reaches
     * the end and reports « finished ». The sweep then runs on an
     * inventory in which this pass stamped nothing and reads the entire
     * copy as gone from the source. Above the mass-disappearance floor
     * the D15 guard catches it; below twenty entries nothing does, and
     * the copy is deleted once the grace period elapses — for files that
     * never moved.
     */
    public function testAResumeKeyDeletedAtTheSourceDoesNotSkipTheRestOfTheListing(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
            $this->source->put('12/' . $name . '.jpg', 'photo-' . $name, 'image/jpeg');
        }

        $paging = new class ($this->root . '/source') extends LocalStorageBackend {
            public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
            {
                $all = parent::list($prefix, null, 10_000)->objects;
                $offset = $cursor === null ? 0 : (int) substr((string) $cursor, 6);
                $page = array_slice($all, $offset, 2);
                $next = ($offset + 2) < count($all) ? 'token-' . ($offset + 2) : null;

                return new StorageListing($page, $next);
            }
        };

        // The previous run stopped on a key the source has since lost.
        $result = $this->pass(new \DateTimeImmutable('2026-01-01 02:00:00'))->run(
            $this->protection(
                phase: StorageProtection::PHASE_INVENTORY,
                startedAt: '2026-01-01 02:00:00',
                cursor: null,
                pageLastKey: '12/disparu.jpg',
                seen: 1
            ),
            $paging,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertTrue($result->finished);
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
            $this->assertTrue(
                $this->destination->exists('12/' . $name . '.jpg'),
                "12/{$name}.jpg was skipped because a resume key no longer in the source stayed set"
            );
        }
        $this->assertSame(6, $this->inventory()->count());
        // And nothing was read as disappeared, which is what the skipped
        // listing would have produced.
        $this->assertSame(0, $result->markedAbsentCount);
    }

    /**
     * A sweep that runs out of time pauses IN its own phase.
     *
     * Until it could, `finishedSweep()` was the only way out of phase 2,
     * so `pass_phase` was never persisted as `reconcile` and both the
     * resume branch of run() and the guard that protects it were
     * unreachable — code that read as live and could not run. The
     * consequence of the dead branch is the real cost: a sweep that
     * stopped had to list the entire source again next time to get back
     * to a decision it had already made.
     */
    public function testASweepOutOfTimeResumesInPhaseTwoRatherThanListingAgain(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->source->put('12/' . $name . '.jpg', 'photo-' . $name, 'image/jpeg');
        }
        $this->firstPassAt('2026-01-01 02:00:00');
        foreach (['a', 'b', 'c'] as $name) {
            $this->source->delete('12/' . $name . '.jpg');
        }
        $this->pass(new \DateTimeImmutable('2026-01-02 02:00:00'))->run(
            $this->protection(startedAt: '2026-01-02 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $stopAfterOne = function (): bool {
            $left = 0;
            foreach (['a', 'b', 'c'] as $name) {
                if ($this->destination->exists('12/' . $name . '.jpg')) {
                    $left++;
                }
            }

            return $left === 3;
        };

        $paused = $this->pass(new \DateTimeImmutable('2026-03-01 02:00:00'))->run(
            $this->protection(startedAt: '2026-03-01 02:00:00'),
            $this->source,
            $this->destination,
            'Galerie',
            $stopAfterOne
        );

        $this->assertFalse($paused->finished, 'a sweep with deletions left is not finished');
        $this->assertSame(StorageProtection::PHASE_RECONCILE, $paused->phase);

        // Resuming straight into the sweep: the source is never listed,
        // and the decisions are read back from the inventory itself.
        $resumed = $this->pass(new \DateTimeImmutable('2026-03-01 02:00:30'))->run(
            $this->protection(
                phase: StorageProtection::PHASE_RECONCILE,
                startedAt: '2026-03-01 02:00:00'
            ),
            $this->source,
            $this->destination,
            'Galerie',
            $this->always()
        );

        $this->assertTrue($resumed->finished);
        $this->assertSame(2, $resumed->deletedCount);
        $this->assertSame(0, $this->inventory()->count());
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
