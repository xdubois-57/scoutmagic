<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Volume;

use Core\Storage\ByteFormatter;

/**
 * One filesystem volume, and every declared directory that turned out to
 * sit on it.
 *
 * **Why a volume and not a directory.** Free space is a property of a
 * filesystem, never of a folder inside it. Three local locations under
 * `storage/` are three folders on ONE disk: asking each of them how much
 * room is left gets the same answer three times, and a screen printing it
 * three times says « 3,2 To libres » in three rows of a table whose reader
 * then believes the site has 9,6 To. That reading is not a rounding error,
 * it is a decision-changing one — it is the difference between « I can
 * move the galleries here » and « I cannot ».
 *
 * So the identity of this object is the **device number** `stat()` reports,
 * not a path. Two paths with the same `dev` are the same filesystem
 * whatever their spelling, symbolic links and bind mounts included, and
 * their occupations add up on it.
 *
 * **The declared quota belongs to a volume too, and only to one of them.**
 * `Core\Storage\DiskBudget::QUOTA_SETTING` asks the administrator for the
 * share their hosting contract grants — that contract covers the account's
 * own volume and says nothing whatsoever about a NAS someone mounted on
 * it. Applying that figure to a network mount would report a 900 Go disk
 * as a 4 Go one. So {@see $declaredQuotaBytes} is carried by the primary
 * volume alone, and every other volume answers from the system, which
 * tells the truth about a disk the site really owns.
 *
 * Which of the two produced the number is not an internal detail: « 62 % »
 * means something different under each, so {@see basis()} carries it and
 * the screen states it, exactly as {@see \Core\Storage\StorageUsage} does
 * for the installation as a whole.
 */
final class VolumeUsage
{
    /** The administrator declared a quota for this volume; the percentage is their share of it. */
    public const BASIS_QUOTA = 'quota';

    /** The system reported the volume's own size, and on this volume that figure is the truth. */
    public const BASIS_VOLUME = 'volume';

    /** Neither: the host would not report the volume and no quota covers it. */
    public const BASIS_UNKNOWN = 'unknown';

    /**
     * @param string|null $deviceId what `stat()` reported, or null when it
     *        would not say. A null device is never merged with another
     *        null one: two directories the system refuses to identify are
     *        two directories we cannot prove are the same disk, and
     *        pretending otherwise would under-report free space in the one
     *        direction that lets a write fail.
     * @param list<VolumeDirectory> $directories the declared directories on
     *        this volume, in declaration order
     * @param int|null $freeBytes  null when the host would not say — never 0, which reads as « full »
     * @param int|null $totalBytes null when the host would not say
     * @param int|null $declaredQuotaBytes the contract share, on the primary volume only
     * @param int|null $occupiedBytes what the declared directories on this
     *        volume measure, or null when none of them could be measured
     */
    public function __construct(
        public readonly ?string $deviceId,
        public readonly array $directories,
        public readonly bool $isPrimary,
        public readonly ?int $freeBytes,
        public readonly ?int $totalBytes,
        public readonly ?int $declaredQuotaBytes,
        public readonly ?int $occupiedBytes
    ) {
    }

    /**
     * The volume's French name on the screen: « Volume principal » for the
     * one carrying `storage/`, and otherwise the shortest directory
     * declared on it — which is the one an administrator typed and will
     * recognise, rather than a device number nobody has ever seen.
     */
    public function label(): string
    {
        if ($this->isPrimary) {
            return 'Volume principal';
        }

        $paths = array_map(static fn (VolumeDirectory $d): string => $d->path, $this->directories);
        if ($paths === []) {
            return 'Autre volume';
        }

        usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        return $paths[0];
    }

    /**
     * Which measurement the percentage was computed on. A declared quota
     * wins over the system's figure wherever there is one, because that is
     * precisely the case where the system's figure is somebody else's
     * disk.
     */
    public function basis(): string
    {
        if ($this->declaredQuotaBytes !== null && $this->declaredQuotaBytes > 0) {
            return self::BASIS_QUOTA;
        }
        if ($this->totalBytes !== null && $this->totalBytes > 0) {
            return self::BASIS_VOLUME;
        }

        return self::BASIS_UNKNOWN;
    }

    /** What the percentage is a percentage OF, or null when there is no such number. */
    public function basisTotalBytes(): ?int
    {
        return match ($this->basis()) {
            self::BASIS_QUOTA => $this->declaredQuotaBytes,
            self::BASIS_VOLUME => $this->totalBytes,
            default => null,
        };
    }

    /**
     * What is counted as used, under whichever basis applies.
     *
     * On a declared quota that is what the site's own directories occupy —
     * the quota is the site's allowance and the rest of the volume is not
     * charged to it. On the system basis it is everything on the volume,
     * this site included, because the host reported the volume and not our
     * share of it.
     *
     * **The system basis needs BOTH figures, and a missing free space is
     * `null` rather than zero.** {@see VolumeInventory} guards
     * `disk_free_space()` and `disk_total_space()` separately, so a host
     * that answers one and refuses the other is a real shape rather than a
     * theoretical one — and subtracting a missing free space from a known
     * total reads as « ce volume est plein à 100 % », which is the one
     * reading that makes an administrator go and delete photographs. The
     * rule this file already states for the percentage (« unavailable is
     * null, never 0 ») is the same rule, one figure earlier.
     */
    public function basisUsedBytes(): ?int
    {
        return match ($this->basis()) {
            self::BASIS_QUOTA => $this->occupiedBytes,
            self::BASIS_VOLUME => $this->totalBytes !== null && $this->freeBytes !== null
                ? max(0, $this->totalBytes - $this->freeBytes)
                : null,
            default => null,
        };
    }

    /**
     * 0-100, or **null** when nothing can be computed. Never 0: a host
     * that will not report its volume has not told us the disk is empty
     * (`specifications.md` §8.47, « unavailable is null, never 0 »).
     */
    public function usedPercent(): ?int
    {
        $total = $this->basisTotalBytes();
        $used = $this->basisUsedBytes();
        if ($total === null || $used === null || $total <= 0) {
            return null;
        }

        return (int) min(100, round($used / $total * 100));
    }

    /**
     * How much more may be written on this volume before something
     * refuses — the smaller of what is left of a declared quota and what
     * the system reports free, since either can be the binding one. Null
     * means *unknown*, never *unlimited*; the caller decides what an
     * unknown budget does, in one place
     * ({@see \Core\Storage\DiskBudget::ensureRoomOn()}).
     */
    public function availableBytes(): ?int
    {
        $candidates = [];

        if ($this->declaredQuotaBytes !== null && $this->declaredQuotaBytes > 0) {
            $candidates[] = max(0, $this->declaredQuotaBytes - ($this->occupiedBytes ?? 0));
        }
        if ($this->freeBytes !== null) {
            $candidates[] = max(0, $this->freeBytes);
        }

        return $candidates === [] ? null : min($candidates);
    }

    /** Empty when there is no figure — never « 0 o », which would read as « full ». */
    public function occupiedLabel(): string
    {
        return $this->occupiedBytes !== null ? ByteFormatter::format($this->occupiedBytes) : '';
    }

    /** Empty when there is no total to state. */
    public function basisTotalLabel(): string
    {
        $total = $this->basisTotalBytes();

        return $total !== null ? ByteFormatter::format($total) : '';
    }

    /** Empty when there is no figure. */
    public function basisUsedLabel(): string
    {
        $used = $this->basisUsedBytes();

        return $used !== null ? ByteFormatter::format($used) : '';
    }

    /**
     * The same fact as {@see basisSentence()}, in two or three words —
     * what the dashboard prints beside the directories.
     *
     * **Here rather than in the template**, and that is a correction: the
     * screen derived it with a two-way ternary on {@see basis()}, which
     * has THREE answers. A volume reporting nothing came out as
     * « mesure système » while `basisSentence()`, on the same card a few
     * lines below, said the volume reports neither its size nor its free
     * space. One source of truth cannot contradict itself; two can, and
     * did.
     */
    public function basisLabel(): string
    {
        return match ($this->basis()) {
            self::BASIS_QUOTA => 'quota déclaré',
            self::BASIS_VOLUME => 'mesure système',
            default => 'aucune mesure',
        };
    }

    /**
     * The one sentence that keeps « 62 % » from meaning two different
     * things on two rows of the same screen.
     */
    public function basisSentence(): string
    {
        return match ($this->basis()) {
            self::BASIS_QUOTA => 'Mesure basée sur le quota que vous avez déclaré : sur un hébergement partagé, '
                . 'le système rapporte la taille du volume entier, bien plus grande que votre part.',
            self::BASIS_VOLUME => $this->isPrimary
                ? 'Mesure du système, faute de quota déclaré — sur un hébergement partagé ce volume est celui '
                    . 'de l\'hébergeur, partagé avec d\'autres comptes et bien plus grand que votre part. '
                    . 'Renseignez le réglage « Quota disque déclaré » pour obtenir un chiffre qui vous concerne.'
                : 'Mesure du système. Aucun quota déclaré n\'est nécessaire ici : ce volume n\'est pas celui de '
                    . 'votre hébergement, le système en dit la vraie taille.',
            default => 'Ce volume ne rapporte ni sa taille ni sa place libre, et aucun quota ne le couvre : '
                . 'seule l\'occupation mesurée ci-dessus est connue.',
        };
    }

    /**
     * Whether any directory on this volume sits outside `storage/` — the
     * fact the screen turns into « ce dossier n'est pas effacé par une
     * réinitialisation complète ». A property of the directories rather
     * than of the volume, but this is where the screen asks it.
     */
    public function hasDirectoryOutsideStorage(): bool
    {
        foreach ($this->directories as $directory) {
            if (!$directory->isUnderStoragePath) {
                return true;
            }
        }

        return false;
    }
}
