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
            new RemoteFile('vieux', 'a.zip', 10, '2026-01-01T00:00:00.000Z'),
            new RemoteFile('recent', 'b.zip', 10, '2026-09-01T00:00:00.000Z'),
            new RemoteFile('moyen', 'c.zip', 10, '2026-05-01T00:00:00.000Z'),
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
     */
    private function archives(int $count, int $sizeBytes): array
    {
        $files = [];
        for ($index = 1; $index <= $count; $index++) {
            $files[] = new RemoteFile(
                'archive-' . $index,
                'sauvegarde-' . $index . '.zip',
                $sizeBytes,
                sprintf('2026-09-%02dT03:00:00.000Z', 30 - $index)
            );
        }

        return $files;
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
