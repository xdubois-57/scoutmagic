<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Config\SettingService;
use Core\Storage\ByteFormatter;

/**
 * How many archives stay in the operator's Drive, and how much room they
 * are allowed to take.
 *
 * **Two bounds, because a count alone is dangerous here.** On the server
 * the local retention counts backups and that is enough, since the
 * administrator knows their own disk. A Drive is not theirs to size: the
 * free tier is fifteen gibibytes, shared with their mail and their
 * photos, and one archive of a unit with a gallery can be several. Thirty
 * archives of two gibibytes each is sixty — an account full, a backup
 * that stops, and a mailbox that stops with it. So a volume ceiling sits
 * beside the count and **the more constraining of the two wins**.
 *
 * **A file the operator deleted by hand is not an error.** The
 * `drive.file` scope makes these files visible and deletable in their own
 * Drive, which is deliberate — it is their account. A purge that fell
 * over because something it meant to delete was already gone would break
 * on exactly the tidiness it should welcome, so a deletion that finds
 * nothing counts as done ({@see GoogleDriveClient::deleteFile()} reads
 * 404 as success), and one that fails for any other reason is recorded
 * and stepped over rather than allowed to strand every later file.
 */
final class RemoteRetention
{
    public const KEEP_SETTING = 'backup_remote_keep';
    public const MAX_BYTES_SETTING = 'backup_remote_max_bytes';

    public const DEFAULT_KEEP = 30;

    /** Ten gibibytes: two thirds of a free account, leaving room to live. */
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024 * 1024;

    public function __construct(private readonly SettingService $settings)
    {
    }

    public static function register(SettingService $settings): void
    {
        $settings->register(self::KEEP_SETTING, (string) self::DEFAULT_KEEP, 'number',
            'Archives distantes conservées',
            'Le nombre d\'archives gardées sur la destination hors site. La borne en volume s\'applique aussi.',
            null, null, null, true, 320);
        // Registered as « 10,0 Go », not as 10737418240. The description
        // below offers the human spelling and `maxBytes()` accepts both,
        // so a default of eleven raw digits would be the one value on the
        // page that contradicts its own help text.
        $settings->register(self::MAX_BYTES_SETTING, ByteFormatter::format(self::DEFAULT_MAX_BYTES), 'text',
            'Volume distant maximal',
            'L\'espace total que les archives hors site peuvent occuper, par exemple « 10 Go ».',
            null, null, null, true, 321);
    }

    /**
     * Which of the destination's files are beyond the bounds, newest
     * first.
     *
     * **Separate from deleting them**, so the decision can be asserted
     * without a network in the way — and so a caller can say what it is
     * about to remove before removing it.
     *
     * The newest archive is never in this list: keeping nothing is not a
     * retention policy, it is a site that uploads and immediately
     * deletes. A `keep` of zero is read as one for that reason.
     *
     * @param RemoteFile[] $files as the destination listed them
     * @return RemoteFile[]
     */
    public function beyondTheBounds(array $files): array
    {
        // Newest first, decided here rather than trusted from the
        // destination: everything below depends on this order, and a
        // provider that changed its default ordering would otherwise
        // silently start deleting the wrong end.
        usort($files, static fn(RemoteFile $a, RemoteFile $b): int => strcmp($b->createdAt, $a->createdAt));

        $keep = max(1, $this->keep());
        $maxBytes = $this->maxBytes();

        $kept = 0;
        $bytes = 0;
        $doomed = [];
        foreach ($files as $file) {
            $bytes += $file->sizeBytes;
            $kept++;

            // The first file to break EITHER bound is the first to go,
            // which is what "the more constraining of the two wins" means
            // in practice. The newest is exempt: $kept is already 1 here,
            // and $maxBytes is only consulted from the second onwards.
            if ($kept > $keep || ($kept > 1 && $bytes > $maxBytes)) {
                $doomed[] = $file;
            }
        }

        return $doomed;
    }

    /**
     * Deletes what is beyond the bounds and answers with what went.
     *
     * @param RemoteFile[] $files
     * @return array{deleted: int, failed: int, freedBytes: int}
     */
    public function purge(RemoteBackupTarget $target, array $files): array
    {
        $deleted = 0;
        $failed = 0;
        $freed = 0;

        foreach ($this->beyondTheBounds($files) as $file) {
            try {
                $target->delete($file->id);
                $deleted++;
                $freed += $file->sizeBytes;
            } catch (\Throwable) {
                // Stepped over, never fatal: one file this application
                // cannot remove must not strand every older one behind
                // it, which would turn a single stuck archive into an
                // account that fills up anyway.
                $failed++;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'freedBytes' => $freed];
    }

    public function keep(): int
    {
        $raw = $this->settings->get(self::KEEP_SETTING);

        return is_numeric($raw) ? max(1, (int) $raw) : self::DEFAULT_KEEP;
    }

    /**
     * The volume ceiling in bytes, read through {@see ByteFormatter} so
     * « 10 Go » is as valid as the byte count — nobody sizes a Drive in
     * bytes, and `DiskBudget::QUOTA_SETTING` set that precedent.
     */
    public function maxBytes(): int
    {
        $raw = $this->settings->get(self::MAX_BYTES_SETTING);
        if (!is_string($raw) || trim($raw) === '') {
            return self::DEFAULT_MAX_BYTES;
        }

        $parsed = ByteFormatter::parse($raw);

        return $parsed !== null && $parsed > 0 ? $parsed : self::DEFAULT_MAX_BYTES;
    }
}
