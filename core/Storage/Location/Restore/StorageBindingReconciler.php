<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Restore;

use Core\Service\TextNormalizerService;

/**
 * Puts today's storage bindings back after a restore (#832) — see
 * {@see StorageBindingSnapshot} for why they are state outside the backup.
 *
 * Run once the restored schema is current, against the snapshot taken
 * just before the database was replaced. Three cases, and in none of them
 * is anything created, moved, renamed or deleted in the storage itself:
 *
 * - **A location in both** keeps today's binding — its configuration, its
 *   credential and its label — over the backup's. The label goes with
 *   them because a Drive location's folder carries it (#474): restoring an
 *   old label would only make it disagree with the folder. When the
 *   credential cannot be read any more (a portable restore installs the
 *   archive's keys), today's configuration is kept with the backup's
 *   credential, and the location says to enter it again.
 * - **A location only in the snapshot** — created after the backup was
 *   taken — is put back, never as the default: data written there since
 *   must stay reachable.
 * - **A location only in the backup** — deleted since, or brought by a
 *   portable archive from another installation — comes back as the backup
 *   has it, and says that its target was not checked. It is not replaced,
 *   not rebound to a guess, and not removed.
 *
 * A row says which with `reconciliation_note`, shown on the locations page
 * until an administrator saves the location; the health recorded against
 * the replaced binding is cleared so the next check measures the real one.
 *
 * **Matched by id, and only within one installation.** Ids are this
 * installation's own counter: an ordinary restore and a portable archive
 * of this same site agree on them, and a portable archive from ANOTHER
 * installation does not — its location 2 is not this site's location 2.
 * There, nothing is matched: today's locations are all put back beside the
 * archive's, and the archive's are all flagged.
 *
 * Which album or backup uses which location is the backup's business, and
 * comes back with it. Nothing here re-points a usage; a location whose
 * binding changed is named in the report, which is the journal's, so that
 * an administrator can tell where a usage may now point at bytes that
 * moved after the backup was taken.
 */
final class StorageBindingReconciler
{
    public const NOTE_FROM_BACKUP = "Cet emplacement vient de la sauvegarde restaurée et n'existait pas sur le site "
        . 'juste avant la restauration : sa cible (dossier, partage ou bucket) n\'a pas été vérifiée. Assurez-vous '
        . 'qu\'elle contient bien les fichiers attendus avant de vous en servir.';

    public const NOTE_SECRET_UNREADABLE = "La liaison d'avant la restauration a été conservée, mais ses identifiants "
        . "n'ont pas pu être relus avec les clés restaurées : saisissez-les à nouveau, puis testez l'emplacement.";

    public const NOTE_KEPT_BESIDE = "Cet emplacement existait sur le site juste avant la restauration et la "
        . 'sauvegarde ne le connaissait pas : il a été conservé, avec les fichiers qu\'il contient.';

    private const RESTORED_SUFFIX = ' (avant restauration)';

    public function __construct(private StorageBindingRepository $repository)
    {
    }

    /**
     * @param bool $sameInstallation whether the restored backup was taken
     *        by this installation — see the class docblock
     * @return list<string> one French line per location that changed,
     *         for the journal
     */
    public function reconcile(StorageBindingSnapshot $before, bool $sameInstallation): array
    {
        // **All or nothing.** A failure halfway would otherwise leave the
        // table neither today's nor the backup's — and the journal entry
        // for a failed reconciliation says the backup's bindings stand.
        return $this->repository->transactional(fn (): array => $this->reconcileRows($before, $sameInstallation));
    }

    /**
     * @return list<string>
     */
    private function reconcileRows(StorageBindingSnapshot $before, bool $sameInstallation): array
    {
        $restored = [];
        foreach ($this->repository->rows() as $row) {
            $restored[$row['id']] = $row;
        }
        // A count per folded key, not a set: two restored labels distinct
        // to the database (« Drive-A », « Drive A ») can fold alike, and
        // freeing one must not free the other.
        $labels = [];
        foreach ($restored as $row) {
            self::takeLabel($labels, $row['label']);
        }

        $report = [];
        $matched = [];
        $keepTheirId = [];
        $needANewId = [];
        foreach ($before->rows as $current) {
            $twin = $sameInstallation ? ($restored[$current['id']] ?? null) : null;
            if ($twin !== null && $twin['type'] === $current['type']) {
                $matched[$twin['id']] = true;
                $line = $this->rebind($twin, $current, $labels);
                if ($line !== null) {
                    $report[] = $line;
                }
                continue;
            }

            if (isset($restored[$current['id']])) {
                $needANewId[] = $current;
            } else {
                $keepTheirId[] = $current;
            }
        }

        // **Every row that keeps its id goes in before any row that needs
        // a new one.** A new id is the table's next one, and it could be
        // exactly the id a later row still has to be put back under: two
        // rows, one primary key, and the reconciliation stopping halfway.
        foreach ($keepTheirId as $current) {
            $report[] = $this->putBack($current, $current['id'], $labels);
        }
        foreach ($needANewId as $current) {
            $report[] = $this->putBack($current, null, $labels);
        }

        // $restored was read before anything was put back, so the rows
        // inserted above are not in it: what is left unmatched here is
        // exactly what only the backup knew.
        foreach ($restored as $id => $row) {
            if (isset($matched[$id])) {
                continue;
            }
            $this->repository->flag($id, self::NOTE_FROM_BACKUP);
            $report[] = "« {$row['label']} » : venu de la sauvegarde, cible non vérifiée.";
        }

        return $report;
    }

    /**
     * @param array{id: int, type: string, label: string, config: string, secret: ?string} $restored
     * @param array{id: int, type: string, label: string, config: string, secret: ?string} $current
     * @param array<string, int> $labels
     */
    private function rebind(array $restored, array $current, array &$labels): ?string
    {
        $readable = $this->repository->isReadable($current['secret']);
        $secret = $readable ? $current['secret'] : $restored['secret'];
        $label = $restored['label'];
        // Its own label, differently cased, is no collision: it frees as
        // it is taken.
        $ownKey = self::labelKey($restored['label']);
        $currentKey = self::labelKey($current['label']);
        if ($current['label'] !== $restored['label']
            && ($currentKey === $ownKey ? ($labels[$ownKey] ?? 0) <= 1 : !isset($labels[$currentKey]))
        ) {
            self::releaseLabel($labels, $restored['label']);
            self::takeLabel($labels, $current['label']);
            $label = $current['label'];
        }

        if ($readable && $label === $restored['label'] && $current['config'] === $restored['config']
            && $secret === $restored['secret']
        ) {
            return null;
        }

        $this->repository->rebind(
            $restored['id'],
            $label,
            $current['config'],
            $secret,
            $readable ? null : self::NOTE_SECRET_UNREADABLE
        );

        return $readable
            ? "« {$label} » : liaison d'avant la restauration conservée à la place de celle de la sauvegarde."
            : "« {$label} » : liaison d'avant la restauration conservée, identifiants à saisir à nouveau.";
    }

    /**
     * @param array{id: int, type: string, label: string, config: string, secret: ?string} $current
     * @param ?int $id the id to put it back under, or null for the table's next one
     * @param array<string, int> $labels
     */
    private function putBack(array $current, ?int $id, array &$labels): string
    {
        $label = $current['label'];
        if (isset($labels[self::labelKey($label)])) {
            $label = mb_substr($current['label'], 0, 255 - mb_strlen(self::RESTORED_SUFFIX)) . self::RESTORED_SUFFIX;
            $n = 2;
            while (isset($labels[self::labelKey($label)])) {
                // The column holds 255 characters, the number included.
                $suffix = self::RESTORED_SUFFIX . ' ' . $n++;
                $label = mb_substr($current['label'], 0, 255 - mb_strlen($suffix)) . $suffix;
            }
        }
        self::takeLabel($labels, $label);

        $readable = $this->repository->isReadable($current['secret']);
        $this->repository->insert(
            $id,
            $current['type'],
            $label,
            $current['config'],
            $readable ? $current['secret'] : null,
            $readable ? self::NOTE_KEPT_BESIDE : self::NOTE_KEPT_BESIDE . ' ' . self::NOTE_SECRET_UNREADABLE
        );

        return "« {$label} » : absent de la sauvegarde, conservé tel qu'il était avant la restauration.";
    }

    /** @param array<string, int> $labels */
    private static function takeLabel(array &$labels, string $label): void
    {
        $key = self::labelKey($label);
        $labels[$key] = ($labels[$key] ?? 0) + 1;
    }

    /** @param array<string, int> $labels */
    private static function releaseLabel(array &$labels, string $label): void
    {
        $key = self::labelKey($label);
        if (($labels[$key] ?? 0) <= 1) {
            unset($labels[$key]);
        } else {
            $labels[$key]--;
        }
    }

    /**
     * A label as the unique index compares it: the column's collation
     * ignores case and accents, so « dropbox » and « Dropbox » are one
     * label there, and must be one here or the reconciliation stops on a
     * duplicate key halfway through. Folding further than the collation
     * does only ever adds a suffix — never a collision.
     */
    private static function labelKey(string $label): string
    {
        return TextNormalizerService::fold($label);
    }
}
