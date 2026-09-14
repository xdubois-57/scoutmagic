<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Config\SettingService;
use Core\Storage\ByteFormatter;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\Storage\Location\StoredObject;

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
 * nothing counts as done — which is
 * {@see StorageBackendInterface::delete()}'s contract for every backend,
 * not a Drive quirk — and one that fails for any other reason is recorded
 * and stepped over rather than allowed to strand every later file.
 *
 * **Since IT-05 it counts objects on a storage location rather than files
 * on a Drive**, and nothing about the policy changed with it. That is the
 * point: « thirty archives or ten gibibytes, whichever bites first » was
 * never a statement about Google, and the class that enforces it has no
 * business knowing which destination it is enforcing it on.
 */
final class RemoteRetention
{
    /**
     * What an archive this application uploaded is called, and how to
     * recognise one again.
     *
     * **The name and its recogniser live together on purpose.** Nothing
     * about naming a backup belongs to retention; what belongs to
     * retention is being unable to get it wrong. The folder holds more
     * than archives — a connection test writes a witness object on every
     * press of the Tester button and ignores a failed clean-up,
     * deliberately, so a witness CAN survive.
     * Counting one as an archive is not cosmetic: it is newer than every
     * real backup, so with `keep` at 1 the purge kept the witness and
     * deleted the unit's only off-site copy.
     *
     * Deliberately tolerant — the prefix and the extension, not the whole
     * date-and-generation shape. A stricter pattern would make every
     * archive written under a future naming scheme immortal, and the
     * folder is visible to this application alone (the `drive.file`
     * scope), so there is nothing else in it to mistake for a backup.
     */
    public const ARCHIVE_PREFIX = 'scoutmagic-';
    public const ARCHIVE_SUFFIX = '.zip';

    public const KEEP_SETTING = 'backup_remote_keep';
    public const MAX_BYTES_SETTING = 'backup_remote_max_bytes';

    public const DEFAULT_KEEP = 30;

    /** Ten gibibytes: two thirds of a free account, leaving room to live. */
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024 * 1024;

    /** How many pages {@see listArchives()} will walk before stopping. */
    private const MAX_PAGES = 200;

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
     * Everything on the destination, page by page until there is no more.
     *
     * **Every page of it, and a caller that reads only the first has the
     * worst possible belief.** The destination answers a listing one page
     * at a time and says so with a cursor; the files a first-page-only
     * reader would never see are precisely the OLDEST — the ones this
     * class exists to delete — so an account past a page of archives
     * would fill up while the purge reported nothing to do.
     *
     * **Bounded, unlike the loop it replaces.** A folder that somehow
     * never stopped paging would otherwise hold this run for ever, on a
     * scheduler whose whole shape is « do a little and come back ».
     * `MAX_PAGES` at the default page size is far past any plausible
     * number of archives, so reaching it means something is wrong with the
     * destination rather than with the policy — and stopping there purges
     * the oldest of what was seen instead of nothing at all.
     *
     * @return list<StoredObject>
     */
    public function listArchives(StorageBackendInterface $backend): array
    {
        $objects = [];
        $cursor = null;
        $pages = 0;

        do {
            $listing = $backend->list('', $cursor);
            foreach ($listing->objects as $object) {
                if (self::isArchive($object->key)) {
                    $objects[] = $object;
                }
            }
            $cursor = $listing->cursor;
            $pages++;
        } while ($cursor !== null && $pages < self::MAX_PAGES);

        return $objects;
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
     * @param list<StoredObject> $files as the destination listed them
     * @return list<StoredObject>
     */
    public function beyondTheBounds(array $files): array
    {
        // Archives only, before anything is sorted or counted. See
        // ARCHIVE_PREFIX for what a surviving witness file did to a purge
        // that counted everything in the folder.
        $files = array_values(array_filter($files, static fn(StoredObject $f): bool => self::isArchive($f->key)));

        // Newest first, decided here rather than trusted from the
        // destination: everything below depends on this order, and a
        // provider that changed its default ordering would otherwise
        // silently start deleting the wrong end.
        //
        // **One instant per archive, then one comparison.** Reading the
        // date « when both sides have one, else the names » is the
        // obvious rule and is not an ordering at all: it can hold A after
        // B, B after C and C after A, and a sort given that is free to
        // return anything.
        //
        // So each file is reduced to a single instant first, and the key
        // only ever breaks ties. Preferring the NAME's timestamp is not a
        // fallback either: it is the moment this application wrote the
        // archive, where `lastModifiedAt` is whatever the destination last
        // did to the object — a re-upload, a metadata touch, a restore
        // from that provider's own trash all move it, and none of them
        // make the archive newer.
        usort($files, static function (StoredObject $a, StoredObject $b): int {
            return [self::writtenAt($b), $b->key] <=> [self::writtenAt($a), $a->key];
        });

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
     * @param list<StoredObject> $files
     * @return array{deleted: int, failed: int, freedBytes: int}
     */
    public function purge(StorageBackendInterface $backend, array $files): array
    {
        $deleted = 0;
        $failed = 0;
        $freed = 0;

        foreach ($this->beyondTheBounds($files) as $file) {
            try {
                $backend->delete($file->key);
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

    /**
     * The name an archive uploaded now would take.
     *
     * The generation is in it, and that is the point: regenerating the
     * passphrase leaves every archive already sent openable only by the
     * old one, and nothing re-encrypts them. Without the number, an
     * operator facing a folder of archives would try the current phrase,
     * fail, and conclude the backup was broken.
     */
    public static function nameFor(\DateTimeImmutable $at, int $generation): string
    {
        return sprintf(
            '%s%s-g%d%s',
            self::ARCHIVE_PREFIX,
            $at->format('Y-m-d-His'),
            max(1, $generation),
            self::ARCHIVE_SUFFIX
        );
    }

    /**
     * When an archive was written, as a sortable instant.
     *
     * The name first, because {@see nameFor()} puts `Y-m-d-His` in it at
     * the moment of writing and nothing afterwards edits it. Then the
     * destination's own date, for an archive named under some future
     * scheme this deliberately tolerant {@see isArchive()} still accepts.
     * Then zero — nothing is known, and an archive nothing is known about
     * has to sort somewhere; it sorts oldest, with its key still
     * separating it from its peers rather than leaving them in list
     * order.
     */
    private static function writtenAt(StoredObject $file): int
    {
        if (preg_match('/^' . self::ARCHIVE_PREFIX . '(\d{4}-\d{2}-\d{2})-(\d{2})(\d{2})(\d{2})/', $file->key, $m) === 1) {
            $parsed = strtotime(sprintf('%sT%s:%s:%sZ', $m[1], $m[2], $m[3], $m[4]));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        $announced = $file->lastModifiedAt === null ? false : strtotime($file->lastModifiedAt);

        return $announced === false ? 0 : $announced;
    }

    /** Whether a remote file is one of {@see nameFor()}'s. */
    public static function isArchive(string $name): bool
    {
        return str_starts_with($name, self::ARCHIVE_PREFIX) && str_ends_with($name, self::ARCHIVE_SUFFIX);
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
