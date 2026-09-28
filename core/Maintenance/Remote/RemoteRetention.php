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
 *
 * **Thinned before it is counted (issue #619, IT-05).** Thirty daily
 * archives reached back one month and no further: the day somebody
 * noticed, in spring, that a section had been emptied at Christmas, every
 * copy that still held it was gone. So the archives are first thinned —
 * the latest, then one per week over the month just gone, then one per
 * month beyond — and only what survives the thinning is held to the two
 * bounds, which did not change. At thirty allowed, a year of daily
 * archives thins to about seventeen: the count stops biting and the reach goes from a
 * month to a year, for the same volume.
 *
 * Nothing new is recorded to do it. Each archive's name carries the
 * instant it was written ({@see nameFor()}), which is all the thinning
 * reads. **An archive of an earlier passphrase generation is not treated
 * apart**: age alone decides, and one that survives stays unreadable with
 * the current phrase — assumed, and said on the page.
 *
 * **Local retention does not thin.** It exists to go back a few days after
 * a wrong move on a site that is still standing, not to cross the year.
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

    /**
     * What the destination held after the last purge, as JSON — read by
     * the page, which never asks the destination anything itself.
     */
    public const STATE_SETTING = 'backup_remote_state';

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
        $settings->register(
            self::KEEP_SETTING,
            (string) self::DEFAULT_KEEP,
            'number',
            'Archives distantes conservées',
            'Le nombre d\'archives gardées sur la destination hors site. La borne en volume s\'applique aussi.',
            null,
            null,
            null,
            true,
            320
        );
        // Registered as « 10,0 Go », not as 10737418240. The description
        // below offers the human spelling and `maxBytes()` accepts both,
        // so a default of eleven raw digits would be the one value on the
        // page that contradicts its own help text.
        $settings->register(
            self::MAX_BYTES_SETTING,
            ByteFormatter::format(self::DEFAULT_MAX_BYTES),
            'text',
            'Volume distant maximal',
            'L\'espace total que les archives hors site peuvent occuper, par exemple « 10 Go ».',
            null,
            null,
            null,
            true,
            321
        );
        $settings->register(
            self::STATE_SETTING,
            '',
            'text',
            'État de la destination hors site',
            'Le nombre, le volume et la plus ancienne des archives relevés au dernier envoi.',
            null,
            null,
            null,
            false,
            322
        );
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
     * Which of the destination's files are thinned out or beyond the
     * bounds, newest first.
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
        $monthAgo = $files === [] ? 0 : self::aMonthBefore(self::writtenAt($files[0]));

        $kept = 0;
        $bytes = 0;
        $periods = [];
        $doomed = [];
        foreach ($files as $file) {
            // Thinning first: the newest of each period stays, and every
            // later one of the same period goes. The newest overall opens
            // its own week, so it is never thinned. Periods are calendar
            // weeks and months, never windows sliding with the newest
            // archive: a sliding week would drop yesterday's keeper the
            // day it changed windows, before its successor had aged into
            // the next one.
            $period = self::periodOf(self::writtenAt($file), $monthAgo);
            if (isset($periods[$period])) {
                $doomed[] = $file;
                continue;
            }
            $periods[$period] = true;

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
     * Deletes what is beyond the bounds and answers with what went — and
     * with what is left, which is the destination's real state.
     *
     * An archive whose deletion failed is still there, so it is counted
     * among what is left: the page states what the destination holds, not
     * what this class meant it to hold.
     *
     * @param list<StoredObject> $files
     * @return array{
     *     deleted: int,
     *     failed: int,
     *     freedBytes: int,
     *     remaining: array{count: int, bytes: int, oldest: ?string}
     * }
     */
    public function purge(StorageBackendInterface $backend, array $files): array
    {
        $deleted = 0;
        $failed = 0;
        $freed = 0;
        $gone = [];

        foreach ($this->beyondTheBounds($files) as $file) {
            try {
                $backend->delete($file->key);
                $deleted++;
                $freed += $file->sizeBytes;
                $gone[$file->key] = true;
            } catch (\Throwable) {
                // Stepped over, never fatal: one file this application
                // cannot remove must not strand every older one behind
                // it, which would turn a single stuck archive into an
                // account that fills up anyway.
                $failed++;
            }
        }

        $left = array_values(array_filter(
            $files,
            static fn(StoredObject $f): bool => self::isArchive($f->key) && !isset($gone[$f->key])
        ));

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'freedBytes' => $freed,
            'remaining' => self::stateOf($left),
        ];
    }

    /**
     * How many archives, how much room, and since when — the three things
     * that let an operator check the policy at a glance.
     *
     * @param list<StoredObject> $archives
     * @return array{count: int, bytes: int, oldest: ?string}
     */
    public static function stateOf(array $archives): array
    {
        $bytes = 0;
        $oldest = null;
        foreach ($archives as $archive) {
            $bytes += $archive->sizeBytes;
            $at = self::writtenAt($archive);
            if ($at > 0 && ($oldest === null || $at < $oldest)) {
                $oldest = $at;
            }
        }

        return [
            'count' => count($archives),
            'bytes' => $bytes,
            // Back to the wall-clock time the name was written in, which
            // writtenAt() read as if it were UTC — the same stored shape
            // as every other date this page shows.
            'oldest' => $oldest === null ? null : gmdate('Y-m-d H:i:s', $oldest),
        ];
    }

    /**
     * What the last purge left on the destination, or null before the
     * first one — or when what was recorded cannot be read back.
     *
     * @return array{count: int, bytes: int, oldest: ?string, observedAt: string}|null
     */
    public function lastKnownState(): ?array
    {
        $raw = $this->settings->get(self::STATE_SETTING);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (
            !is_array($state)
            || !is_int($state['count'] ?? null)
            || !is_int($state['bytes'] ?? null)
            || !is_string($state['observedAt'] ?? null)
        ) {
            return null;
        }
        $oldest = $state['oldest'] ?? null;

        return [
            'count' => $state['count'],
            'bytes' => $state['bytes'],
            'oldest' => is_string($oldest) ? $oldest : null,
            'observedAt' => $state['observedAt'],
        ];
    }

    /**
     * Records what a purge left, stamped with when it was seen.
     *
     * @param array{count: int, bytes: int, oldest: ?string} $state
     */
    public function recordState(array $state, \DateTimeImmutable $observedAt): void
    {
        $recorded = json_encode([
            'count' => $state['count'],
            'bytes' => $state['bytes'],
            'oldest' => $state['oldest'],
            'observedAt' => $observedAt->format('Y-m-d H:i:s'),
        ]);
        $this->settings->setInternal(self::STATE_SETTING, (string) $recorded);
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
        $named = '/^' . self::ARCHIVE_PREFIX . '(\d{4}-\d{2}-\d{2})-(\d{2})(\d{2})(\d{2})/';
        if (preg_match($named, $file->key, $m) === 1) {
            $parsed = strtotime(sprintf('%sT%s:%s:%sZ', $m[1], $m[2], $m[3], $m[4]));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        $announced = $file->lastModifiedAt === null ? false : strtotime($file->lastModifiedAt);

        return $announced === false ? 0 : $announced;
    }

    /**
     * The same moment one calendar month earlier: where « one per week »
     * ends and « one per month » begins.
     *
     * **Clamped to the last day of the shorter month**, not left to
     * `modify('-1 month')`, which overflows: from 31 March it lands on
     * 3 March, and from 1 April on 1 March — a boundary that moved BACK
     * two days while the newest archive moved forward one, flipping an
     * early-March archive from one period to another and back.
     */
    private static function aMonthBefore(int $instant): int
    {
        $year = (int) gmdate('Y', $instant);
        $month = (int) gmdate('n', $instant) - 1;
        if ($month === 0) {
            $month = 12;
            $year--;
        }
        $lastDay = (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
        $day = min((int) gmdate('j', $instant), $lastDay);

        return gmmktime(
            (int) gmdate('G', $instant),
            (int) gmdate('i', $instant),
            (int) gmdate('s', $instant),
            $month,
            $day,
            $year
        );
    }

    /**
     * The period an archive speaks for: its ISO week inside the month
     * just gone, its calendar month before that. UTC, like the names.
     */
    private static function periodOf(int $instant, int $monthAgo): string
    {
        return $instant >= $monthAgo ? 'week ' . gmdate('o-W', $instant) : 'month ' . gmdate('Y-m', $instant);
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
