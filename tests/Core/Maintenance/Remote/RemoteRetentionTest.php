<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Maintenance\Remote\RemoteRetention;
use Core\Storage\Location\StorageListing;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StoredObject;
use PHPUnit\Framework\TestCase;
use Tests\Core\Storage\Location\Backend\RefusingBackend;

/**
 * Which archives stay in the operator's Drive.
 *
 * The decision is separated from the deleting so it can be asserted with
 * no network in the way, and every case below drives the decision
 * directly.
 */
// Through `InMemorySettingService`, whose constructor opens an in-memory
// database: this class builds none itself and inherits none.
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RemoteRetentionTest extends TestCase
{
    private InMemorySettingService $settings;
    private RemoteRetention $retention;

    protected function setUp(): void
    {
        $this->settings = new InMemorySettingService();
        $this->retention = new RemoteRetention($this->settings);
    }

    /** Nothing is deleted while both bounds are satisfied. */
    public function testAFolderInsideBothBoundsLosesNothing(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '30';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';

        $doomed = $this->retention->beyondTheBounds($this->archives(5, 1024));

        $this->assertSame([], $doomed);
    }

    /** Past the count, the oldest go — and only the oldest. */
    public function testPastTheCountTheOldestArchivesAreTheOnesThatGo(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '3';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';

        $doomed = $this->retention->beyondTheBounds($this->archives(5, 1024));

        $this->assertSame([$this->nameOf(4), $this->nameOf(5)], $this->keysOf($doomed));
    }

    /**
     * **The volume ceiling bites before the count does, and that is the
     * case the count alone would miss.**
     *
     * A free Drive is fifteen gibibytes shared with a mailbox. Thirty
     * archives of two gibibytes is sixty: an account full, a backup that
     * stops, and the mail that stops with it.
     */
    public function testTheVolumeCeilingBitesWhileTheCountStillHasRoom(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '30';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '5000';

        // Six archives of a thousand bytes: the sixth crosses five
        // thousand, so it and nothing newer goes.
        $doomed = $this->retention->beyondTheBounds($this->archives(6, 1000));

        $this->assertSame([$this->nameOf(6)], $this->keysOf($doomed));
    }

    /**
     * **The newest archive is kept even when the destination forgot to
     * date it.**
     *
     * A folder where some objects carry `lastModifiedAt` and some do not
     * is the case a single « date, or empty string » comparison gets
     * exactly backwards: the empty string sorts below every real date, so
     * every undated archive lands at the old end whatever its name says,
     * and the purge starts with the one written last night.
     *
     * Here the newest of five announces no date at all. Keeping three, it
     * must still be among them.
     */
    public function testAnUndatedArchiveIsPlacedByItsNameRatherThanTreatedAsAncient(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '3';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';

        $files = $this->archives(5, 1024);
        $files[0] = new StoredObject($files[0]->key, $files[0]->sizeBytes, null, null);

        $doomed = $this->retention->beyondTheBounds($files);

        $this->assertSame([$this->nameOf(4), $this->nameOf(5)], $this->keysOf($doomed));
    }

    /** And where both bite, the more constraining one decides. */
    public function testTheMoreConstrainingOfTheTwoBoundsWins(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '4';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '2500';

        // The count would keep four; the volume only keeps two.
        $doomed = $this->retention->beyondTheBounds($this->archives(5, 1000));

        $this->assertSame(
            [$this->nameOf(3), $this->nameOf(4), $this->nameOf(5)],
            $this->keysOf($doomed)
        );
    }

    /**
     * **The newest archive is never deleted, whatever the settings say.**
     *
     * Otherwise a single archive larger than the ceiling — a unit whose
     * gallery grew — would be uploaded and immediately removed, on every
     * run, for ever: a site that appears to back up and keeps nothing.
     */
    public function testTheNewestArchiveSurvivesEvenWhenItAloneExceedsTheCeiling(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '30';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '100';

        $doomed = $this->retention->beyondTheBounds($this->archives(1, 5_000_000));

        $this->assertSame([], $doomed, 'the only copy off-site was deleted for being too big');
    }

    /** A `keep` of zero is read as one, for the same reason. */
    public function testAKeepOfZeroStillKeepsTheLatest(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '0';

        $doomed = $this->retention->beyondTheBounds($this->archives(3, 10));

        $this->assertSame([$this->nameOf(2), $this->nameOf(3)], $this->keysOf($doomed));
    }

    /**
     * **The order is decided here, not trusted from the destination.**
     *
     * Everything above depends on newest-first. A provider that changed
     * its default ordering would otherwise start deleting the newest
     * archives while reporting a successful purge.
     */
    public function testTheOrderIsImposedRatherThanAssumed(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '1';

        $shuffled = [
            new StoredObject('scoutmagic-2026-01-01-030000-g1.zip', 10, null, '2026-01-01T00:00:00.000Z'),
            new StoredObject('scoutmagic-2026-09-01-030000-g1.zip', 10, null, '2026-09-01T00:00:00.000Z'),
            new StoredObject('scoutmagic-2026-05-01-030000-g1.zip', 10, null, '2026-05-01T00:00:00.000Z'),
        ];

        $doomed = $this->retention->beyondTheBounds($shuffled);

        $this->assertSame([
            'scoutmagic-2026-05-01-030000-g1.zip',
            'scoutmagic-2026-01-01-030000-g1.zip',
        ], $this->keysOf($doomed));
    }

    /**
     * **A destination that announces no date is still ordered**, by the
     * name — which carries `Y-m-d-His` for every archive this application
     * writes.
     *
     * Without it, a backend that volunteers no timestamp would be purged
     * in whatever order it happened to list, which is arbitrary: the
     * unit's newest off-site copy would go as readily as its oldest.
     */
    public function testWithoutATimestampTheNameDecidesRatherThanTheListingOrder(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '1';

        $doomed = $this->retention->beyondTheBounds([
            new StoredObject('scoutmagic-2026-01-01-030000-g1.zip', 10),
            new StoredObject('scoutmagic-2026-09-01-030000-g1.zip', 10),
            new StoredObject('scoutmagic-2026-05-01-030000-g1.zip', 10),
        ]);

        $this->assertSame([
            'scoutmagic-2026-05-01-030000-g1.zip',
            'scoutmagic-2026-01-01-030000-g1.zip',
        ], $this->keysOf($doomed));
    }

    /**
     * **The latest, then one per week over the month gone, then one per
     * month beyond** — a year of daily archives thins to about seventeen.
     */
    public function testAYearOfDailyArchivesThinsToOnePerWeekThenOnePerMonth(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '1000';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';

        $files = $this->daily(new \DateTimeImmutable('2026-09-30 03:00:00'), 365);
        $doomed = $this->keysOf($this->retention->beyondTheBounds($files));
        $kept = array_values(array_diff($this->keysOf($files), $doomed));

        $this->assertSame('scoutmagic-2026-09-30-030000-g1.zip', $kept[0], 'the latest archive was thinned');

        $weeks = [];
        $months = [];
        foreach ($kept as $key) {
            $at = new \DateTimeImmutable(substr($key, 11, 10));
            if ($at >= new \DateTimeImmutable('2026-08-30')) {
                $weeks[] = $at->format('o-W');
            } else {
                $months[] = $at->format('Y-m');
            }
        }

        $this->assertSame(array_values(array_unique($weeks)), $weeks, 'two archives kept for one week');
        $this->assertSame(array_values(array_unique($months)), $months, 'two archives kept for one month');
        // Sunday 30 August to Wednesday 30 September touches six ISO
        // weeks, 35 to 40, and each has its archive.
        $this->assertCount(6, $weeks, 'the month gone is not covered week by week');
        // Then August 2026 back to October 2025, the first month of the
        // year of archives: the reach is the whole year.
        $this->assertCount(11, $months);
        $this->assertSame('2025-10', $months[10]);
        $this->assertCount(17, $kept);
    }

    /**
     * **Kept archives stay kept from one day to the next.** A week or a
     * month measured back from the newest archive would slide every day,
     * and the archive kept yesterday for its week would be thinned today,
     * before the next had aged into the window. Calendar periods do not
     * slide: what was kept for a closed period is kept tomorrow too, until
     * it passes into a monthly period that already has a newer one.
     */
    public function testWhatWasKeptYesterdayForAClosedWeekIsStillKeptToday(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '1000';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';

        $yesterday = $this->daily(new \DateTimeImmutable('2026-09-29 03:00:00'), 20);
        $keptYesterday = array_diff(
            $this->keysOf($yesterday),
            $this->keysOf($this->retention->beyondTheBounds($yesterday))
        );

        $today = [...$this->daily(new \DateTimeImmutable('2026-09-30 03:00:00'), 1), ...$yesterday];
        $keptToday = array_diff($this->keysOf($today), $this->keysOf($this->retention->beyondTheBounds($today)));

        // Only yesterday's latest may go: it shares a week with today's.
        $this->assertSame(
            ['scoutmagic-2026-09-29-030000-g1.zip'],
            array_values(array_diff($keptYesterday, $keptToday))
        );
    }

    /**
     * **A month back from the 31st is the last day of the shorter month**
     * (IT-05 review). `modify('-1 month')` from 31 March lands on 3 March,
     * which would pull 28 February out of the weekly periods and thin it
     * against 27 February as if both spoke for February. Clamped, the
     * boundary is 28 February: the 28th opens its week, the 27th its
     * month, and both stay.
     */
    public function testAMonthBackFromTheEndOfAMonthDoesNotOverflow(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '1000';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';

        $doomed = $this->retention->beyondTheBounds([
            new StoredObject('scoutmagic-2026-03-31-030000-g1.zip', 10),
            new StoredObject('scoutmagic-2026-02-28-040000-g1.zip', 10),
            new StoredObject('scoutmagic-2026-02-27-030000-g1.zip', 10),
        ]);

        $this->assertSame([], $this->keysOf($doomed));
    }

    /**
     * And the boundary never moves back while the newest archive moves
     * forward — across the end of a month included.
     */
    public function testTheMonthBoundaryOnlyEverMovesForward(): void
    {
        $boundary = new \ReflectionMethod(RemoteRetention::class, 'aMonthBefore');
        $previous = PHP_INT_MIN;
        $day = new \DateTimeImmutable('2026-01-25 03:00:00', new \DateTimeZone('UTC'));
        for ($step = 0; $step < 400; $step++) {
            $at = $boundary->invoke(null, $day->modify("+{$step} days")->getTimestamp());
            $this->assertIsInt($at);
            $this->assertGreaterThanOrEqual($previous, $at, 'the boundary moved back on day ' . $step);
            $previous = $at;
        }
    }

    /**
     * **The bounds apply to what the thinning kept**, and still bite: a
     * kept archive past the count goes like any other.
     */
    public function testTheCountStillBitesOnWhatTheThinningKept(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '3';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';

        $files = $this->daily(new \DateTimeImmutable('2026-09-30 03:00:00'), 21);
        $doomed = $this->keysOf($this->retention->beyondTheBounds($files));

        $this->assertSame([
            'scoutmagic-2026-09-30-030000-g1.zip',
            'scoutmagic-2026-09-27-030000-g1.zip',
            'scoutmagic-2026-09-20-030000-g1.zip',
        ], array_values(array_diff($this->keysOf($files), $doomed)));
    }

    /**
     * **An archive of an earlier passphrase generation is not treated
     * apart**: age alone decides, and the generation in the name changes
     * nothing to which archive of the week is kept.
     */
    public function testAnEarlierGenerationIsThinnedByAgeAlone(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '30';

        $doomed = $this->retention->beyondTheBounds([
            new StoredObject('scoutmagic-2026-09-30-030000-g2.zip', 10),
            new StoredObject('scoutmagic-2026-09-23-030000-g2.zip', 10),
            new StoredObject('scoutmagic-2026-09-22-030000-g1.zip', 10),
            new StoredObject('scoutmagic-2026-09-16-030000-g1.zip', 10),
        ]);

        $this->assertSame(['scoutmagic-2026-09-22-030000-g1.zip'], $this->keysOf($doomed));
    }

    /**
     * **What a purge leaves is the destination's real state** — a deletion
     * that failed leaves its archive counted, and the witness file is not
     * an archive.
     */
    public function testWhatIsLeftCountsARefusedDeletionAndNeverTheWitness(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '1';

        $deleted = [];
        $files = [...$this->archives(3, 100), new StoredObject('scoutmagic-test.txt', 7, null, '2026-10-01T00:00:00Z')];
        $report = $this->retention->purge(new PurgeBackendDouble($deleted, $this->nameOf(3)), $files);

        $this->assertSame(['count' => 2, 'bytes' => 200, 'oldest' => '2026-07-15 03:00:00'], $report['remaining']);

        $this->retention->recordState($report['remaining'], new \DateTimeImmutable('2026-10-01 04:00:00'));
        $this->assertSame(
            ['count' => 2, 'bytes' => 200, 'oldest' => '2026-07-15 03:00:00', 'observedAt' => '2026-10-01 04:00:00'],
            $this->retention->lastKnownState()
        );
    }

    /** Nothing recorded, or something unreadable, is no state at all. */
    public function testNoRecordedStateIsNoState(): void
    {
        $this->assertNull($this->retention->lastKnownState());

        $this->settings->values[RemoteRetention::STATE_SETTING] = '{"count":"deux"}';
        $this->assertNull($this->retention->lastKnownState());
    }

    /**
     * **A file already gone, or one that refuses, never strands the rest.**
     *
     * `drive.file` makes these files deletable in the operator's own
     * Drive — deliberately, it is their account — so tidying up there
     * must not break the purge here. And one stuck file must not block
     * every older one behind it, which would fill the account anyway.
     */
    public function testOneRefusedDeletionDoesNotStrandTheOlderArchives(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '1';

        $deleted = [];
        $backend = new PurgeBackendDouble($deleted, $this->nameOf(2));

        $report = $this->retention->purge($backend, $this->archives(4, 100));

        $this->assertSame([$this->nameOf(3), $this->nameOf(4)], $deleted);
        $this->assertSame(2, $report['deleted']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(200, $report['freedBytes']);
    }

    /**
     * **Gathering the archives walks every page**, and a caller that read
     * only the first would have the worst possible belief: the listing is
     * newest-first, so the objects it never sees are the OLDEST — exactly
     * what the purge exists to delete. The account would fill up while
     * the purge reported nothing to do.
     */
    public function testGatheringTheArchivesFollowsEveryPage(): void
    {
        $backend = new PagedBackendDouble([
            [new StoredObject($this->nameOf(1), 10, null, '2026-09-29T03:00:00Z')],
            [
                new StoredObject($this->nameOf(2), 10, null, '2026-09-28T03:00:00Z'),
                // Not an archive: the witness a Tester button leaves
                // behind is newer than every real backup, and counting it
                // with `keep` at 1 kept the witness and deleted the
                // unit's only off-site copy.
                new StoredObject('scoutmagic-test.txt', 12, null, '2026-09-30T03:00:00Z'),
            ],
            [new StoredObject($this->nameOf(3), 10, null, '2026-09-27T03:00:00Z')],
        ]);

        $archives = $this->retention->listArchives($backend);

        $this->assertSame(
            [$this->nameOf(1), $this->nameOf(2), $this->nameOf(3)],
            $this->keysOf($archives)
        );
    }

    /** « 10 Go » is as valid as the byte count: nobody sizes a Drive in bytes. */
    public function testTheCeilingMayBeWrittenTheWayAHostingContractWritesIt(): void
    {
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '10 Go';
        $this->assertSame(10 * 1024 * 1024 * 1024, $this->retention->maxBytes());

        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '';
        $this->assertSame(RemoteRetention::DEFAULT_MAX_BYTES, $this->retention->maxBytes());

        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = 'pas un nombre';
        $this->assertSame(RemoteRetention::DEFAULT_MAX_BYTES, $this->retention->maxBytes());
    }

    /**
     * @return list<StoredObject> newest first, index 1 being the newest
     *
     * Named through {@see RemoteRetention::nameFor()} rather than by hand:
     * retention now looks at the name to tell an archive from the witness
     * file the Tester button leaves behind, so a fixture spelled its own
     * way would be filtered out — and every count below would pass on an
     * empty list.
     *
     * **One a month apart**, so the thinning keeps every one of them and
     * the cases built on this helper test the two bounds alone. The
     * thinning has cases of its own, with archives spaced on purpose.
     */
    private function archives(int $count, int $sizeBytes): array
    {
        $files = [];
        for ($index = 1; $index <= $count; $index++) {
            $files[] = new StoredObject(
                $this->nameOf($index),
                $sizeBytes,
                null,
                sprintf('2026-%02d-15T03:00:00.000Z', 10 - $index)
            );
        }

        return $files;
    }

    /** The name {@see archives()} gives the $index-th newest archive. */
    private function nameOf(int $index): string
    {
        return RemoteRetention::nameFor(
            new \DateTimeImmutable(sprintf('2026-%02d-15 03:00:00', 10 - $index)),
            1
        );
    }

    /**
     * One archive a day, the newest first, named the way the send names
     * them and announcing no date of their own.
     *
     * @return list<StoredObject>
     */
    private function daily(\DateTimeImmutable $newest, int $days): array
    {
        $files = [];
        for ($day = 0; $day < $days; $day++) {
            $files[] = new StoredObject(RemoteRetention::nameFor($newest->modify("-{$day} days"), 1), 10);
        }

        return $files;
    }

    /**
     * @param list<StoredObject> $objects
     * @return list<string>
     */
    private function keysOf(array $objects): array
    {
        return array_values(array_map(static fn (StoredObject $o): string => $o->key, $objects));
    }

    /**
     * **The default an operator actually sees.**
     *
     * The description offers « 10 Go » as the spelling, and the field is
     * free text. A default of eleven raw digits would be the one value on
     * the page that contradicts its own help — and the first thing
     * somebody would "correct", which is how a ten-gibibyte ceiling
     * becomes a ten-gigabyte one by accident.
     */
    public function testTheRegisteredCeilingIsReadableAndMeansWhatItSays(): void
    {
        $settings = new InMemorySettingService();
        RemoteRetention::register($settings);

        $registered = (string) $settings->get(RemoteRetention::MAX_BYTES_SETTING);
        $this->assertStringContainsString('Go', $registered, 'the default is a raw byte count on a screen');
        $this->assertSame(
            RemoteRetention::DEFAULT_MAX_BYTES,
            (new RemoteRetention($settings))->maxBytes(),
            'the value written into the settings row does not read back as the constant it came from'
        );
    }


    /**
     * **The witness file is not an archive, and counting it as one
     * deleted the unit's only off-site backup.**
     *
     * `GoogleDriveTarget::testConnection()` writes `scoutmagic-test.txt`
     * into this same folder on every press of the Tester button, and
     * ignores a failed clean-up on purpose — so one CAN survive. It is
     * then the newest thing in the folder, and a purge that counted it
     * kept it and deleted the real backup underneath.
     */
    public function testAWitnessFileIsNeitherCountedNorPurged(): void
    {
        $settings = new InMemorySettingService([RemoteRetention::KEEP_SETTING => '1']);
        $witness = new StoredObject('scoutmagic-test.txt', 12, null, '2026-03-04T00:00:00Z');
        $backup = new StoredObject('scoutmagic-2026-03-03-020400-g1.zip', 900, null, '2026-03-03T00:00:00Z');

        $doomed = (new RemoteRetention($settings))->beyondTheBounds([$witness, $backup]);

        $this->assertSame([], $doomed, 'the newest witness was kept and the only real backup deleted under it');
    }

    /**
     * The name the handler writes is the name retention recognises.
     *
     * The two live in one class for this reason: a format on one side and
     * a pattern on the other are two things to keep in step, and the
     * purge is where getting it wrong destroys a backup.
     */
    public function testTheNameItSpellsIsTheNameItRecognises(): void
    {
        $name = RemoteRetention::nameFor(new \DateTimeImmutable('2026-03-03 02:04:00'), 2);

        $this->assertSame('scoutmagic-2026-03-03-020400-g2.zip', $name);
        $this->assertTrue(RemoteRetention::isArchive($name));
        $this->assertFalse(RemoteRetention::isArchive('scoutmagic-test.txt'));
    }

}

/**
 * A destination that records what it was asked to delete, and refuses one
 * named object the way a provider refuses one this application cannot
 * remove.
 *
 * Everything else refuses ({@see RefusingBackend}) — a purge that reached
 * for any of it would be doing something this class has no business doing.
 */
final class PurgeBackendDouble extends RefusingBackend
{
    /** @param string[] $deleted */
    public function __construct(private array &$deleted, private readonly string $refuses = '')
    {
    }

    public function delete(string $key): void
    {
        if ($key === $this->refuses) {
            throw new StorageLocationException('Ce fichier n\'a pas pu être supprimé.');
        }

        $this->deleted[] = $key;
    }
}

/**
 * A destination that answers a listing in pages, so the walk that
 * gathers archives can be asserted without a network.
 */
final class PagedBackendDouble extends RefusingBackend
{
    /** @param list<list<StoredObject>> $pages */
    public function __construct(private readonly array $pages)
    {
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        $index = $cursor === null ? 0 : (int) $cursor;
        $objects = $this->pages[$index] ?? [];
        $next = isset($this->pages[$index + 1]) ? (string) ($index + 1) : null;

        return new StorageListing($objects, $next);
    }
}
