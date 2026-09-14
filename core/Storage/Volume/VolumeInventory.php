<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Volume;

use Core\Config\SettingService;
use Core\Storage\DirectorySize;
use Core\Storage\DiskBudget;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationService;

/**
 * Which filesystems this installation actually writes to, and how full
 * each of them is.
 *
 * **The grouping is the whole point.** Before this existed, free space was
 * one number read from `storage/`, and a local location pointing anywhere
 * else — a network mount, a second disk — was measured against the wrong
 * volume: a backup written to a NAS was approved because the system disk
 * was empty, or refused because the system disk was full while the NAS had
 * terabytes. Both answers are wrong in the way that costs a backup.
 *
 * So every declared directory is resolved to a real path, `stat()` is
 * asked which device it is on, and the ones sharing a device are one
 * volume. `dev` rather than the path prefix, because the two disagree
 * exactly where it matters: a symbolic link, a bind mount or a
 * `/mnt/nas/photos` that is really the system disk all look like separate
 * places by name and are one filesystem underneath.
 *
 * **A null device is never merged with another null device.** When
 * `stat()` will not answer — `open_basedir`, a directory that is not there
 * — the honest reading is « we cannot prove these are the same disk », and
 * the one thing that must not happen is treating two unknowns as one
 * volume and adding their occupations onto a single free-space figure.
 * They stay separate, which over-reports the number of volumes and never
 * over-reports the room left.
 */
class VolumeInventory
{
    public function __construct(
        private readonly string $storagePath,
        private readonly SettingService $settings,
        private readonly StorageLocationService $locations,
        private readonly StorageBackendFactory $backends,
        /**
         * How a path is placed on a filesystem. Defaulted rather than
         * required, so every production caller gets the kernel's answer
         * without naming it, and a test can hand the inventory two devices
         * on a machine that only has one.
         */
        private readonly DeviceResolver $devices = new StatDeviceResolver()
    ) {
    }

    /**
     * Every volume the site writes to, primary first, then by label.
     *
     * Walks each declared directory — which is the expensive part, and why
     * this is called from a configuration screen an administrator opens on
     * purpose rather than from anything that renders for a visitor.
     *
     * @return list<VolumeUsage>
     */
    public function measure(): array
    {
        $storageRoot = rtrim($this->storagePath, '/');
        $primaryDevice = $this->devices->deviceIdOf($storageRoot);

        /** @var array<string, list<VolumeDirectory>> $byDevice */
        $byDevice = [];
        /** @var array<string, string|null> $deviceOf */
        $deviceOf = [];
        $unknown = 0;

        foreach ($this->declaredDirectories() as $path => $label) {
            $device = $this->devices->deviceIdOf($path);
            // A directory whose device nobody will name gets a key of its
            // own, so two of them never land on one volume. See the class
            // docblock: an unknown is not a match.
            $key = $device ?? 'unknown:' . $unknown++;
            $byDevice[$key][] = new VolumeDirectory(
                path: $path,
                label: $label,
                sizeBytes: self::measuredSize($path),
                isUnderStoragePath: self::isUnder($path, $storageRoot),
                exists: is_dir($path)
            );
            $deviceOf[$key] = $device;
        }

        $quota = (new DiskBudget($this->storagePath, $this->settings))->declaredQuotaBytes();

        $volumes = [];
        foreach ($byDevice as $key => $directories) {
            $device = $deviceOf[$key];
            // The primary volume is the one `storage/` is on, matched by
            // device — so a location whose absolute path happens to point
            // back at the system disk is correctly the same volume, and a
            // `storage/` the system would not identify is still primary
            // because it is the directory the site was started from.
            $isPrimary = $device !== null && $primaryDevice !== null
                ? $device === $primaryDevice
                : self::containsPath($directories, $storageRoot);
            $reference = self::referencePath($directories, $storageRoot, $isPrimary);

            $volumes[] = new VolumeUsage(
                deviceId: $device,
                directories: $directories,
                isPrimary: $isPrimary,
                freeBytes: self::freeBytes($reference),
                totalBytes: self::totalBytes($reference),
                // Only the primary volume is covered by the declared
                // quota: the hosting contract that figure comes from says
                // nothing about a disk somebody mounted onto the account.
                declaredQuotaBytes: $isPrimary ? $quota : null,
                occupiedBytes: self::occupiedBytes($directories)
            );
        }

        usort($volumes, static function (VolumeUsage $a, VolumeUsage $b): int {
            return [$b->isPrimary, $a->label()] <=> [$a->isPrimary, $b->label()];
        });

        return $volumes;
    }

    /**
     * The volume a given path sits on, or null when no declared volume
     * matches it — which is what {@see DiskBudget::ensureRoomOn()} needs to
     * charge a write to the disk it is really going to land on.
     */
    public function volumeFor(string $path): ?VolumeUsage
    {
        $device = $this->devices->deviceIdOf($path);

        foreach ($this->measure() as $volume) {
            if ($device !== null && $volume->deviceId === $device) {
                return $volume;
            }
            foreach ($volume->directories as $directory) {
                if ($directory->path === rtrim($path, '/')) {
                    return $volume;
                }
            }
        }

        return null;
    }

    /**
     * Every directory whose occupation this site is responsible for:
     * `storage/` itself, then each local storage location.
     *
     * A location whose configured path this application refuses is left
     * out rather than reported as a volume — the refusal is a fault of the
     * location, and its own health row is where an administrator is told
     * about it. Silently inventing a volume for a path nothing can open
     * would put a row on the screen that no other row agrees with.
     *
     * @return array<string, string> resolved absolute path => French label
     */
    private function declaredDirectories(): array
    {
        $directories = [rtrim($this->storagePath, '/') => 'Dossier de stockage du site'];

        foreach ($this->locations->all() as $location) {
            $path = $this->localDirectoryOf($location);
            if ($path === null) {
                continue;
            }
            // Keyed by path, so two locations declaring the same folder are
            // one directory and not two — the same double-counting this
            // whole class exists to stop, one level down.
            $directories[$path] ??= $location->label;
        }

        return $directories;
    }

    private function localDirectoryOf(StorageLocation $location): ?string
    {
        try {
            $path = $this->backends->localDirectoryFor($location);
        } catch (StorageLocationException) {
            return null;
        }

        return $path !== null ? rtrim($path, '/') : null;
    }

    /**
     * What the declared directories occupy on one volume, **without
     * counting a directory twice**.
     *
     * The default local location is `storage/gallery`, which is inside
     * `storage/` — both are declared, both are on the primary volume, and
     * adding them would charge the gallery to the volume twice. So a
     * directory nested inside another one already counted contributes
     * nothing more: it is already inside that reading. It keeps its own
     * size for the screen, which answers the other question.
     *
     * Null when nothing could be measured at all — not zero, which would
     * claim an empty disk.
     *
     * @param list<VolumeDirectory> $directories
     */
    private static function occupiedBytes(array $directories): ?int
    {
        // **Shortest path first, and it is the directories themselves that
        // are sorted.** The skip below only fires for a directory nested
        // inside one ALREADY counted, and a parent is never `isUnder()` its
        // own child — so without this ordering the rule depends on a parent
        // happening to be declared before its children. `storage/` is
        // seeded first, which hides it; two locations on a mounted disk,
        // `/mnt/nas/photos` declared before `/mnt/nas`, do not have that
        // luck and their bytes were counted twice. An inflated occupation
        // feeds `VolumeUsage::availableBytes()` and thence a refusal, which
        // is this subsystem's own defect pointing the other way: refusing
        // room that exists.
        usort(
            $directories,
            static fn (VolumeDirectory $a, VolumeDirectory $b): int => strlen($a->path) <=> strlen($b->path)
        );

        $total = null;
        /** @var list<string> $counted */
        $counted = [];
        foreach ($directories as $directory) {
            if ($directory->sizeBytes === null) {
                continue;
            }
            foreach ($counted as $already) {
                if (self::isUnder($directory->path, $already)) {
                    continue 2;
                }
            }
            $counted[] = $directory->path;
            $total = ($total ?? 0) + $directory->sizeBytes;
        }

        return $total;
    }

    /**
     * The path to ask the system about for this volume: `storage/` when it
     * is on it — the directory that certainly exists — and otherwise the
     * first declared directory that is actually there. A path that is not
     * there answers nothing, and asking it would report a live volume as
     * unmeasurable.
     *
     * @param list<VolumeDirectory> $directories
     */
    private static function referencePath(array $directories, string $storageRoot, bool $isPrimary): ?string
    {
        if ($isPrimary && is_dir($storageRoot)) {
            return $storageRoot;
        }
        foreach ($directories as $directory) {
            if ($directory->exists) {
                return $directory->path;
            }
        }

        return null;
    }

    /** @param list<VolumeDirectory> $directories */
    private static function containsPath(array $directories, string $path): bool
    {
        foreach ($directories as $directory) {
            if ($directory->path === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * What walking $path measured, or **null** when it could not be
     * walked — which {@see VolumeDirectory::$sizeBytes} promises and
     * `DirectorySize::measure()` cannot deliver on its own.
     *
     * Under {@see \Core\Storage\DirectoryWalk::Measurement} an unreadable
     * directory is swallowed and comes back as `0`. That leniency is right
     * where it lives — one folder with wrong permissions must not turn a
     * configuration page into a 500 — and wrong as an occupation figure:
     * `0` says « this holds nothing », so the volume's occupation comes out
     * short by exactly what nobody could see, and under a declared quota
     * {@see VolumeUsage::availableBytes()} turns that straight into
     * OVERSTATED room left. Over-reporting the room left is the one
     * direction this namespace exists never to be wrong in — it is what
     * lets a write be approved onto a volume that cannot take it.
     *
     * A network mount whose permissions are wrong is the case, and it is
     * the case this whole iteration is built around.
     *
     * **The limit this does not close, stated rather than implied**: an
     * unreadable directory DEEPER in the tree is still skipped in silence,
     * because `CATCH_GET_CHILD` is what keeps the page open on a site with
     * one bad folder. Closing it means changing what a measurement does
     * for every `DiskBudget` caller, which is not this namespace's to
     * change from here.
     */
    private static function measuredSize(string $path): ?int
    {
        // Absent and unreadable are two answers, and `exists` on the
        // VolumeDirectory is what tells them apart on the screen. Both are
        // null here, because neither is an occupation of zero.
        if (!is_dir($path) || !is_readable($path)) {
            return null;
        }

        return DirectorySize::measure($path);
    }

    /** Null when the host would not say — never 0, which would mean « full ». */
    private static function freeBytes(?string $path): ?int
    {
        if ($path === null) {
            return null;
        }
        $free = @disk_free_space($path);

        return is_float($free) && $free >= 0 ? (int) $free : null;
    }

    /** Null when the host would not say — never 0, which would mean « no disk ». */
    private static function totalBytes(?string $path): ?int
    {
        if ($path === null) {
            return null;
        }
        $total = @disk_total_space($path);

        return is_float($total) && $total > 0 ? (int) $total : null;
    }

    /** Whether $path is $root itself or sits inside it, textually. */
    private static function isUnder(string $path, string $root): bool
    {
        $path = rtrim($path, '/');
        $root = rtrim($root, '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
