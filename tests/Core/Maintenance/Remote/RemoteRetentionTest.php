<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Maintenance\Remote\RemoteBackupException;
use Core\Maintenance\Remote\RemoteFile;
use Core\Maintenance\Remote\RemoteRetention;
use PHPUnit\Framework\TestCase;

/**
 * Which archives stay in the operator's Drive.
 *
 * The decision is separated from the deleting so it can be asserted with
 * no network in the way, and every case below drives the decision
 * directly.
 */
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

        $this->assertSame(['archive-4', 'archive-5'], array_map(static fn ($f) => $f->id, $doomed));
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

        $this->assertSame(['archive-6'], array_map(static fn ($f) => $f->id, $doomed));
    }

    /** And where both bite, the more constraining one decides. */
    public function testTheMoreConstrainingOfTheTwoBoundsWins(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '4';
        $this->settings->values[RemoteRetention::MAX_BYTES_SETTING] = '2500';

        // The count would keep four; the volume only keeps two.
        $doomed = $this->retention->beyondTheBounds($this->archives(5, 1000));

        $this->assertSame(
            ['archive-3', 'archive-4', 'archive-5'],
            array_map(static fn ($f) => $f->id, $doomed)
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

        $this->assertSame(['archive-2', 'archive-3'], array_map(static fn ($f) => $f->id, $doomed));
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
            new RemoteFile('vieux', 'scoutmagic-2026-01-01-030000-g1.zip', 10, '2026-01-01T00:00:00.000Z'),
            new RemoteFile('recent', 'scoutmagic-2026-09-01-030000-g1.zip', 10, '2026-09-01T00:00:00.000Z'),
            new RemoteFile('moyen', 'scoutmagic-2026-05-01-030000-g1.zip', 10, '2026-05-01T00:00:00.000Z'),
        ];

        $doomed = $this->retention->beyondTheBounds($shuffled);

        $this->assertSame(['moyen', 'vieux'], array_map(static fn ($f) => $f->id, $doomed));
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
        $target = new PurgeTargetDouble($deleted, 'archive-2');

        $report = $this->retention->purge($target, $this->archives(4, 100));

        $this->assertSame(['archive-3', 'archive-4'], $deleted);
        $this->assertSame(2, $report['deleted']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(200, $report['freedBytes']);
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
     * @return RemoteFile[] newest first, `archive-1` being the newest
     *
     * Named through {@see RemoteRetention::nameFor()} rather than by hand:
     * retention now looks at the name to tell an archive from the witness
     * file the Tester button leaves behind, so a fixture spelled its own
     * way would be filtered out — and every count below would pass on an
     * empty list.
     */
    private function archives(int $count, int $sizeBytes): array
    {
        $files = [];
        for ($index = 1; $index <= $count; $index++) {
            $files[] = new RemoteFile(
                'archive-' . $index,
                RemoteRetention::nameFor(new \DateTimeImmutable(sprintf('2026-09-%02d 03:00:00', 30 - $index)), 1),
                $sizeBytes,
                sprintf('2026-09-%02dT03:00:00.000Z', 30 - $index)
            );
        }

        return $files;
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
        $witness = new RemoteFile('w', 'scoutmagic-test.txt', 12, '2026-03-04T00:00:00Z');
        $backup = new RemoteFile('b', 'scoutmagic-2026-03-03-020400-g1.zip', 900, '2026-03-03T00:00:00Z');

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
 * named file the way Drive refuses one this application cannot remove.
 */
final class PurgeTargetDouble implements \Core\Maintenance\Remote\RemoteBackupTarget
{
    /** @param string[] $deleted */
    public function __construct(private array &$deleted, private readonly string $refuses = '')
    {
    }

    public function delete(string $remoteId): void
    {
        if ($remoteId === $this->refuses) {
            throw RemoteBackupException::of('Ce fichier n\'a pas pu être supprimé.');
        }

        $this->deleted[] = $remoteId;
    }

    public function upload(string $localPath, string $remoteName): string
    {
        throw new \LogicException('not used');
    }

    /** @return \Core\Maintenance\Remote\RemoteFile[] */
    public function list(): array
    {
        throw new \LogicException('not used');
    }

    public function quota(): ?\Core\Maintenance\Remote\RemoteQuota
    {
        throw new \LogicException('not used');
    }

    public function testConnection(): \Core\Maintenance\Remote\RemoteConnectionCheck
    {
        throw new \LogicException('not used');
    }

    public function beginUpload(string $remoteName, int $size): string
    {
        throw new \LogicException('not used');
    }

    public function sendChunks(
        string $sessionUrl,
        string $localPath,
        int $size,
        int $offset,
        \Closure $hasTimeLeft
    ): \Core\Maintenance\Remote\RemoteUpload {
        throw new \LogicException('not used');
    }

    public function probeUpload(string $sessionUrl, int $size): \Core\Maintenance\Remote\RemoteUpload
    {
        throw new \LogicException('not used');
    }
}
