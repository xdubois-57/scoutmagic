<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection;

use Core\Storage\Location\Protection\StorageProtection;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Every method of the protection repository, against a real database.
 *
 * AGENTS.md asks for that of every repository, and this one has a
 * particular reason to be read closely: its whole table is disposable
 * working state (D12), so the thing that can go wrong is not a lost row
 * but a row that says something slightly untrue about a pass — a phase
 * that outlives a failure, a cadence that remembers a destination nobody
 * copies to any more, a start moment one time budget away from the one
 * the inventory entries carry.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class StorageProtectionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private StorageProtectionRepository $protections;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->protections = new StorageProtectionRepository($this->pdo);
    }

    public function testAProtectionIsSavedAndReadBackByEveryLookup(): void
    {
        $id = $this->protections->save(3, 5, 14, 6, true);

        $byId = $this->protections->findById($id);
        $this->assertNotNull($byId);
        $this->assertSame($id, $byId->id);
        $this->assertSame(3, $byId->sourceLocationId);
        $this->assertSame(5, $byId->destinationLocationId);
        $this->assertSame(14, $byId->gracePeriodDays);
        $this->assertSame(6, $byId->cadenceHours);
        $this->assertTrue($byId->enabled);
        $this->assertFalse($byId->isPassInProgress());

        $bySource = $this->protections->findBySourceId(3);
        $this->assertNotNull($bySource);
        $this->assertSame($id, $bySource->id);

        $this->assertCount(1, $this->protections->findAll());
    }

    public function testAnAbsentProtectionIsNullRatherThanAnError(): void
    {
        $this->assertNull($this->protections->findById(4242));
        $this->assertNull($this->protections->findBySourceId(4242));
        $this->assertSame([], $this->protections->findAll());
        $this->assertSame([], $this->protections->destinationLocationIds());
    }

    /**
     * **« This source is protected, to here » is one fact.** Saving again
     * for the same source corrects the relation rather than creating a
     * second one — the UNIQUE on the source says so.
     */
    public function testSavingTwiceForOneSourceCorrectsTheRelation(): void
    {
        $first = $this->protections->save(3, 5, 30, 24, true);
        $second = $this->protections->save(3, 7, 60, 12, false);

        $this->assertSame($first, $second, 'the same relation, corrected');
        $this->assertCount(1, $this->protections->findAll());

        $saved = $this->protections->findById($first);
        $this->assertNotNull($saved);
        $this->assertSame(7, $saved->destinationLocationId);
        $this->assertSame(60, $saved->gracePeriodDays);
        $this->assertSame(12, $saved->cadenceHours);
        $this->assertFalse($saved->enabled);
    }

    /**
     * Correcting a relation throws away the working state, which belongs
     * to the destination it was walking.
     */
    public function testCorrectingARelationDiscardsTheWorkingStateAndTheLastError(): void
    {
        $id = $this->protections->save(3, 5, 30, 24, true);
        $this->protections->recordPassProgress(
            $id,
            StorageProtection::PHASE_INVENTORY,
            'token-42',
            '12/m.jpg',
            900,
            '2026-01-01 02:00:00'
        );
        $this->protections->recordPassFailed($id, 'La copie de secours n\'a pas pu être poursuivie.');

        $this->protections->save(3, 7, 30, 24, true);

        $corrected = $this->protections->findById($id);
        $this->assertNotNull($corrected);
        $this->assertNull($corrected->passPhase);
        $this->assertNull($corrected->passCursor);
        $this->assertNull($corrected->passPageLastKey);
        $this->assertSame(0, $corrected->passSeenCount);
        $this->assertNull($corrected->lastError);
    }

    public function testDestinationLocationIdsAnswersEachDestinationOnce(): void
    {
        $this->protections->save(1, 9, 30, 24, true);
        $this->protections->save(2, 9, 30, 24, true);
        $this->protections->save(3, 8, 30, 24, false);

        $destinations = $this->protections->destinationLocationIds();
        sort($destinations);

        $this->assertSame([8, 9], $destinations, 'a destination protecting two sources is still one location');
    }

    /**
     * **The row remembers the position, not the decision.**
     *
     * Both halves of the position matter and they are different things:
     * the cursor is the BACKEND's own next-page token, opaque by
     * contract, and the page key is where inside that page the run
     * stopped. The start moment is the run's own, never one computed
     * here — the inventory entries it stamped carry that exact string and
     * are compared to it for equality.
     */
    public function testAPassInProgressRecordsBothHalvesOfItsPositionAndItsOwnStamp(): void
    {
        $id = $this->protections->save(3, 5, 30, 24, true);

        $this->protections->recordPassProgress(
            $id,
            StorageProtection::PHASE_INVENTORY,
            'token-42',
            '12/m.jpg',
            1700,
            '2026-01-01 02:00:00'
        );

        $inProgress = $this->protections->findById($id);
        $this->assertNotNull($inProgress);
        $this->assertTrue($inProgress->isPassInProgress());
        $this->assertSame(StorageProtection::PHASE_INVENTORY, $inProgress->passPhase);
        $this->assertSame('token-42', $inProgress->passCursor);
        $this->assertSame('12/m.jpg', $inProgress->passPageLastKey);
        $this->assertSame(1700, $inProgress->passSeenCount);
        $this->assertSame('2026-01-01 02:00:00', $inProgress->passStartedAt);
    }

    public function testACompletedPassClearsTheWorkingStateAndTheError(): void
    {
        $id = $this->protections->save(3, 5, 30, 24, true);
        $this->protections->recordPassFailed($id, 'Hier, ça n\'a pas marché.');
        $this->protections->recordPassProgress(
            $id,
            StorageProtection::PHASE_RECONCILE,
            'token-9',
            '12/z.jpg',
            12,
            '2026-01-02 02:00:00'
        );

        $this->protections->recordPassCompleted($id);

        $done = $this->protections->findById($id);
        $this->assertNotNull($done);
        $this->assertFalse($done->isPassInProgress());
        $this->assertNull($done->passCursor);
        $this->assertNull($done->passPageLastKey);
        $this->assertNull($done->passStartedAt);
        $this->assertSame(0, $done->passSeenCount);
        $this->assertNull($done->lastError, 'a pass that worked ends the story of the one that did not');
        $this->assertNotNull($done->lastCompletedPassAt);
    }

    /**
     * **A failure clears the working state too, and that is D15.**
     *
     * A pass that died mid-listing holds a cursor into a listing it can
     * no longer trust — expired credentials, a source that answered
     * « empty » — and resuming from it would carry that blindness into
     * the next run, where it decides what to delete.
     */
    public function testAFailedPassClearsTheCursorSoNothingResumesIntoABlindListing(): void
    {
        $id = $this->protections->save(3, 5, 30, 24, true);
        $this->protections->recordPassProgress(
            $id,
            StorageProtection::PHASE_INVENTORY,
            'token-42',
            '12/m.jpg',
            900,
            '2026-01-01 02:00:00'
        );

        $this->protections->recordPassFailed($id, 'La source a cessé de répondre.');

        $failed = $this->protections->findById($id);
        $this->assertNotNull($failed);
        $this->assertFalse($failed->isPassInProgress());
        $this->assertNull($failed->passCursor);
        $this->assertNull($failed->passPageLastKey);
        $this->assertNull($failed->passStartedAt);
        $this->assertSame(0, $failed->passSeenCount);
        $this->assertSame('La source a cessé de répondre.', $failed->lastError);
    }

    /** Long driver output is truncated rather than refused by the column. */
    public function testAVeryLongErrorIsTruncatedRatherThanLost(): void
    {
        $id = $this->protections->save(3, 5, 30, 24, true);

        $this->protections->recordPassFailed($id, str_repeat('é', 5000));

        $failed = $this->protections->findById($id);
        $this->assertNotNull($failed);
        $this->assertNotNull($failed->lastError);
        $this->assertSame(2000, mb_strlen($failed->lastError));
    }

    public function testDeletingARelationRemovesItAndLeavesTheOthersAlone(): void
    {
        $kept = $this->protections->save(1, 9, 30, 24, true);
        $removed = $this->protections->save(2, 9, 30, 24, true);

        $this->protections->delete($removed);

        $this->assertNull($this->protections->findById($removed));
        $this->assertNotNull($this->protections->findById($kept));
        $this->assertCount(1, $this->protections->findAll());
    }
}
