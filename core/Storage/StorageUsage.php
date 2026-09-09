<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage;

/**
 * One reading of what the site occupies, and of what it is allowed to
 * occupy — the two being different questions that shared hosting makes it
 * very easy to confuse.
 *
 * `disk_free_space()` reports the **volume** the directory sits on. On
 * shared hosting that volume is the host's, shared with every other
 * account, and routinely two orders of magnitude larger than the quota
 * this account actually gets. A site reading it is not measuring itself;
 * it is measuring somebody else's disk and calling the answer reassuring.
 *
 * So this object carries four separate facts and never folds them into
 * one: the real size of `storage/`, the size of the **whole installation**
 * that contains it, the volume's own free/total as reported (or the
 * absence of that report), and the quota the admin declared from their
 * hosting contract. {@see basis()} says which of them the percentage was
 * computed on, and the screen states it — because « 62 % » means something
 * entirely different depending on the answer.
 *
 * **Why `storage/` alone is not what a declared quota is measured
 * against.** The setting asks for the allowance « tel qu'il figure sur
 * votre contrat », which is the whole hosting account: the application's
 * own footprint — `core/`, `modules/`, `public/` and above all `vendor/`,
 * a couple of hundred megabytes of PHP dependencies — is charged to it
 * too. Subtracting only `storage/` from it would over-report the room left
 * by several times {@see DiskBudget::SAFETY_MARGIN_BYTES}, in the
 * direction that lets a write truncate, and would understate occupation on
 * the screen by the same amount. So the quota basis reads
 * {@see $installBytes}, and the breakdown carries the application's share
 * as a line of its own rather than hiding it inside a total.
 */
final class StorageUsage
{
    /** The admin declared their quota; the percentage is the site's share of it. */
    public const BASIS_QUOTA = 'quota';

    /** No declared quota: the percentage is the whole volume's, which is not this account's. */
    public const BASIS_VOLUME = 'volume';

    /** Neither: the host would not report its volume and nobody declared a quota. */
    public const BASIS_UNKNOWN = 'unknown';

    /**
     * The installation outside `storage/` — `vendor/` and the code. Not a
     * storage area anybody manages, but it is charged to the declared
     * quota, so leaving it out of the breakdown would make the total look
     * like a sum that does not add up.
     */
    public const AREA_APPLICATION = 'application';

    public const AREA_GALLERY = 'gallery';
    public const AREA_BACKUPS = 'backups';
    public const AREA_TEMP = 'temp';
    public const AREA_OTHER = 'other';

    /**
     * @param array<string, int> $breakdown bytes per AREA_* key; AREA_OTHER
     *                                      is deliberately computed as the
     *                                      remainder rather than as a sum
     *                                      of known folders, so a module
     *                                      that adds a storage directory
     *                                      shows up instead of vanishing
     * @param int $installBytes the whole installation, `storage/`
     *                          included — what a declared quota is really
     *                          measured against. Never smaller than
     *                          $storageBytes; equal to it when the
     *                          installation root could not be walked, in
     *                          which case the quota basis simply falls back
     *                          on `storage/` alone rather than inventing a
     *                          figure.
     * @param int|null $volumeFreeBytes  null when the host would not say
     * @param int|null $volumeTotalBytes null when the host would not say
     */
    public function __construct(
        public readonly int $storageBytes,
        public readonly array $breakdown,
        public readonly ?int $declaredQuotaBytes,
        public readonly ?int $volumeFreeBytes,
        public readonly ?int $volumeTotalBytes,
        public readonly string $measuredAt,
        public readonly int $installBytes = 0
    ) {
    }

    /**
     * What the declared quota is charged for: the whole installation, or
     * `storage/` alone when the installation root could not be walked.
     * Never less than `storage/`, which is inside it.
     */
    public function quotaChargedBytes(): int
    {
        return max($this->storageBytes, $this->installBytes);
    }

    public function basis(): string
    {
        if ($this->declaredQuotaBytes !== null && $this->declaredQuotaBytes > 0) {
            return self::BASIS_QUOTA;
        }
        if ($this->volumeTotalBytes !== null && $this->volumeTotalBytes > 0) {
            return self::BASIS_VOLUME;
        }

        return self::BASIS_UNKNOWN;
    }

    /** What the percentage is a percentage OF, or null when there is no such number. */
    public function totalBytes(): ?int
    {
        return match ($this->basis()) {
            self::BASIS_QUOTA => $this->declaredQuotaBytes,
            self::BASIS_VOLUME => $this->volumeTotalBytes,
            default => null,
        };
    }

    /**
     * On the quota basis this is the whole installation's footprint, since
     * the quota pays for `vendor/` too; on the volume basis it is
     * everything on the volume, this site included, because that is the
     * only thing the host reported.
     */
    public function usedBytes(): ?int
    {
        return match ($this->basis()) {
            self::BASIS_QUOTA => $this->quotaChargedBytes(),
            self::BASIS_VOLUME => max(0, ($this->volumeTotalBytes ?? 0) - ($this->volumeFreeBytes ?? 0)),
            default => null,
        };
    }

    /**
     * 0-100, or **null** when nothing can be computed. Never 0: a host that
     * will not report its volume has not told us the disk is empty — the
     * same "unavailable is null, never 0" rule §8.47 already applies to
     * every other reported metric.
     */
    public function usedPercent(): ?int
    {
        $total = $this->totalBytes();
        $used = $this->usedBytes();
        if ($total === null || $used === null || $total <= 0) {
            return null;
        }

        return (int) min(100, round($used / $total * 100));
    }

    /**
     * How much more the site may write before something refuses — the
     * smaller of "what is left of the declared quota" and "what is left on
     * the volume", since either can be the binding one. Null when neither
     * is known, which means *unknown*, never *unlimited*: the caller
     * (`DiskBudget::ensureRoom()`) is what decides that an unknown budget
     * does not block a write, and it says so in one place.
     */
    public function availableBytes(): ?int
    {
        $candidates = [];

        if ($this->declaredQuotaBytes !== null && $this->declaredQuotaBytes > 0) {
            $candidates[] = max(0, $this->declaredQuotaBytes - $this->quotaChargedBytes());
        }
        if ($this->volumeFreeBytes !== null) {
            $candidates[] = max(0, $this->volumeFreeBytes);
        }

        return $candidates === [] ? null : min($candidates);
    }

    /** @return array<string, int> the AREA_* buckets, largest first, zeroes dropped */
    public function orderedBreakdown(): array
    {
        $breakdown = array_filter($this->breakdown, static fn (int $bytes): bool => $bytes > 0);
        arsort($breakdown);

        return $breakdown;
    }

    /**
     * The breakdown as the screen prints it: « Galerie 1,9 Go », largest
     * first.
     *
     * Built here rather than in the template, for the reason
     * `Modules\Gallery\Service\DiskSpace` already gives: choosing between
     * « 512 Mo » and « 1,2 Go », and naming a folder in French, are
     * decisions, and a decision in a Twig file is a decision nobody can
     * test.
     *
     * @return array<int, string>
     */
    public function breakdownLabels(): array
    {
        $names = [
            self::AREA_APPLICATION => 'application et bibliothèques',
            self::AREA_GALLERY => 'galerie',
            self::AREA_BACKUPS => 'sauvegardes',
            self::AREA_TEMP => 'fichiers temporaires',
            self::AREA_OTHER => 'pièces jointes et divers',
        ];

        $labels = [];
        foreach ($this->orderedBreakdown() as $area => $bytes) {
            $labels[] = ($names[$area] ?? $area) . ' ' . ByteFormatter::format($bytes);
        }

        return $labels;
    }

    public function storageLabel(): string
    {
        return ByteFormatter::format($this->storageBytes);
    }

    /** Empty when there is no total to state — never « 0 o ». */
    public function totalLabel(): string
    {
        $total = $this->totalBytes();

        return $total !== null ? ByteFormatter::format($total) : '';
    }

    /** Empty when there is no figure — never « 0 o », which would read as "full". */
    public function usedLabel(): string
    {
        $used = $this->usedBytes();

        return $used !== null ? ByteFormatter::format($used) : '';
    }

    /**
     * The one sentence that keeps « 62 % » from meaning two different
     * things — the whole reason this object carries three facts instead of
     * one number. On the volume basis the figure is not this account's, and
     * saying so is more useful than the figure.
     */
    public function basisSentence(): string
    {
        return match ($this->basis()) {
            self::BASIS_QUOTA => 'Occupation calculée sur le quota que vous avez déclaré dans les réglages.',
            self::BASIS_VOLUME => 'Occupation calculée sur le volume de l\'hébergeur, faute de quota déclaré — '
                . 'ce volume est partagé avec d\'autres comptes et il est bien plus grand que votre part. '
                . 'Renseignez le réglage « Quota disque déclaré » pour obtenir un chiffre qui vous concerne.',
            default => 'Votre hébergeur ne rapporte pas la taille de son volume et aucun quota n\'est déclaré : '
                . 'seule la taille occupée ci-dessus est connue. Renseignez le réglage « Quota disque déclaré » '
                . 'pour que le site puisse dire s\'il approche de la limite.',
        };
    }
}
