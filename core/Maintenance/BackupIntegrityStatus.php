<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * What the last verification pass found when it re-read a stored backup.
 *
 * **Five states rather than "good / bad", and each distinction was paid
 * for.** An operator reading a list of dates needs to know not only that
 * something is wrong but *which* wrong thing: a file that vanished and a
 * file whose bytes changed have different causes and different answers.
 */
enum BackupIntegrityStatus: string
{
    /** Never looked at. A backup taken minutes ago is here, and that is fine. */
    case Unknown = 'unknown';

    /** Re-read from disk, digest matched. The only reassuring answer. */
    case Intact = 'intact';

    /**
     * The row is here and the file is not.
     *
     * Never a purge: {@see BackupRetention::forget()} removes the files
     * and the row together, so a row whose file is gone means something
     * outside the application took it — an FTP clean-up, a `storage/`
     * restored from elsewhere, a hosting migration that dropped a folder.
     */
    case Missing = 'missing';

    /**
     * The file is there and its bytes are not what they were.
     *
     * Which in practice means truncated: a write that ran out of quota
     * (the failure `Core\Storage\DiskBudget` now refuses up front, but
     * older archives predate it), a disk that filled during a copy, an
     * interrupted download over FTP.
     */
    case Corrupt = 'corrupt';

    /**
     * Nothing to compare against — the backup predates the digests.
     *
     * **Deliberately not `Corrupt`, and the distinction is the whole
     * reason this case exists.** An installation that upgrades with five
     * older backups on disk would otherwise light the integrity alert on
     * its first pass, on five backups that are very probably fine, and an
     * alert that cries the day it is installed is switched off before it
     * ever says anything true. Recording a digest on first sight was the
     * alternative and is worse: it would certify whatever state the file
     * is in today, including already broken.
     */
    case Unverifiable = 'unverifiable';

    /**
     * Whether this state means the backup cannot be counted on.
     *
     * `Unknown` and `Unverifiable` are absent on purpose: neither is a
     * finding. One has not been looked at yet, the other cannot be looked
     * at — and treating "I don't know" as "it's broken" is the same
     * mistake `Core\Alert\AlertReading::inconclusive()` exists to refuse.
     */
    public function isFailure(): bool
    {
        return $this === self::Missing || $this === self::Corrupt;
    }

    /** Shown on the row in « Sauvegardes récentes ». Null where there is nothing to say. */
    public function label(): ?string
    {
        return match ($this) {
            self::Unknown => null,
            self::Intact => 'Vérifiée',
            self::Missing => 'Fichier absent',
            self::Corrupt => 'Illisible',
            self::Unverifiable => 'Non vérifiable',
        };
    }

    /** The Bootstrap contextual class of that badge (design.md §7). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Missing, self::Corrupt => 'text-bg-danger',
            self::Intact => 'text-bg-success',
            default => 'text-bg-light',
        };
    }

    /**
     * The sentence the row's title carries, for the two states where
     * knowing what happened changes what an operator does next.
     */
    public function explanation(): ?string
    {
        return match ($this) {
            self::Missing => 'Le fichier de cette sauvegarde n\'est plus sur le serveur. Le site ne l\'a pas '
                . 'supprimé : une suppression retire la ligne en même temps que le fichier.',
            self::Corrupt => 'Le fichier est là mais son contenu a changé depuis sa création — le plus souvent '
                . 'une archive tronquée par un disque plein. Elle ne se restaurera pas.',
            self::Unverifiable => 'Cette sauvegarde est antérieure à la vérification d\'intégrité : le site n\'a '
                . 'pas d\'empreinte à laquelle la comparer. Les suivantes en ont une.',
            default => null,
        };
    }
}
