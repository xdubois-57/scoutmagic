<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * What a kind of storage means for somebody who has to choose one.
 *
 * **This is the whole of D3's second half.** Capabilities are declared by
 * the backends and are the right vocabulary for code: « lecture par plage
 * d'octets », « URL signée », « envoi repris ». They are the wrong
 * vocabulary for an administrator, who is not choosing a storage on byte
 * ranges — they are choosing one for the photographs of a camp, and what
 * they need to know is whether the album will be slow and whether the
 * videos will play.
 *
 * So the screen never prints a capability. It prints these, and each of
 * them is COMPUTED from the capabilities rather than written beside them:
 * a backend that gains an aptitude tomorrow changes one static list, and
 * these sentences follow it. A table maintained in parallel is a table
 * that lies the first time somebody forgets it — which is exactly what
 * `StorageLocationType` says about not restating what a backend can do.
 *
 * Four consequences, no more: they are the four questions the mockup found
 * an administrator actually asks. IT-07 lays the same four out as a
 * comparison across types; this is the per-location reading of them.
 */
final class StorageConsequence
{
    /** Showing photographs to visitors. */
    public const AREA_PHOTOS = 'photos';

    /** Playing a video, and being able to move inside it. */
    public const AREA_VIDEOS = 'videos';

    /** Sending large files, and surviving a connection that drops. */
    public const AREA_BACKUPS = 'backups';

    /** Whether the site can say how much room is left. */
    public const AREA_SPACE = 'space';

    private function __construct(
        public readonly string $area,
        public readonly string $label,
        public readonly string $verdict,
        public readonly string $detail
    ) {
    }

    /**
     * The four readings for one kind of storage, in the order an
     * administrator cares about them.
     *
     * @return list<StorageConsequence>
     */
    public static function forType(StorageLocationType $type): array
    {
        return [
            self::photos($type),
            self::videos($type),
            self::backups($type),
            self::space($type),
        ];
    }

    /** « Photos : oui, le plus rapide ». */
    private static function photos(StorageLocationType $type): self
    {
        // A signed URL is what lets the storage hand the bytes to the
        // visitor directly. Without it every photograph travels through
        // PHP — which works, and is what the local disk has always done,
        // but on an album opened by thirty parents the same evening the
        // difference is felt.
        return $type->supports(StorageCapability::SignedUrl)
            ? new self(
                self::AREA_PHOTOS,
                'Photos',
                'oui, le plus rapide',
                'Les photos vont directement du stockage au visiteur, sans passer par le site.'
            )
            : new self(
                self::AREA_PHOTOS,
                'Photos',
                'oui, un peu plus lent',
                'Chaque photo transite par le site avant d\'arriver au visiteur.'
            );
    }

    /** « Vidéos : non » is the one verdict here that is a real refusal. */
    private static function videos(StorageLocationType $type): self
    {
        // Without range reads a player can START a film and never move
        // inside it, because a `Range:` request cannot be answered. That
        // is not « slower », it is a feature that does not work, and it is
        // why the gallery blocks rather than warns on this one (D4).
        return $type->supports(StorageCapability::RangeRead)
            ? new self(
                self::AREA_VIDEOS,
                'Vidéos',
                'oui',
                'Une vidéo se lit, et on peut avancer dedans.'
            )
            : new self(
                self::AREA_VIDEOS,
                'Vidéos',
                'non',
                'Impossible d\'avancer dans une vidéo : la lecture y est inutilisable.'
            );
    }

    /**
     * « Sauvegardes : oui » — or « non », which is the second real refusal
     * here and used not to be one.
     *
     * **This said « oui, sans reprise » and the product said no.**
     * `RemoteBackupController::choose()` refuses a destination that cannot
     * resume, and `MaintenanceController` does not even offer it in the
     * picker — because a backup archive is never sent in one piece, so a
     * destination that restarts from zero never finishes a large one.
     * Meanwhile this sentence told the administrator backups would work
     * there, merely without resume. Two types are affected today, S3 and
     * WebDAV, and the comparison table this feeds would have published
     * the contradiction four types wide.
     *
     * A verdict that promises what the next screen refuses is worse than
     * a blunt one, so it now says the same thing the refusal does.
     */
    private static function backups(StorageLocationType $type): self
    {
        return $type->supports(StorageCapability::ResumableUpload)
            ? new self(
                self::AREA_BACKUPS,
                'Sauvegardes',
                'oui',
                'Un envoi volumineux interrompu reprend où il s\'était arrêté.'
            )
            : new self(
                self::AREA_BACKUPS,
                'Sauvegardes',
                'non',
                'Une archive de sauvegarde ne part jamais en une seule fois : '
                . 'ce stockage ne peut pas être choisi comme destination.'
            );
    }

    /**
     * « Place restante », and the local disk is the case that does not fit
     * the capability.
     *
     * `LocalStorageBackend` deliberately declares no quota: room left is a
     * property of a VOLUME, and a per-location answer would report the same
     * free space three times for three folders on one disk. So the site
     * does know the figure for a local location — `Core\Storage\Volume`
     * computes it — it simply does not get it from the backend, and saying
     * « non indiquée » about it would be false.
     */
    private static function space(StorageLocationType $type): self
    {
        if ($type === StorageLocationType::Local) {
            return new self(
                self::AREA_SPACE,
                'Place restante',
                'selon l\'hébergeur',
                'Mesurée par volume sur le tableau de bord, quand l\'hébergeur accepte de la rapporter.'
            );
        }

        return $type->supports(StorageCapability::Quota)
            ? new self(
                self::AREA_SPACE,
                'Place restante',
                'oui',
                'Ce stockage sait dire combien il reste d\'espace.'
            )
            : new self(
                self::AREA_SPACE,
                'Place restante',
                'non indiquée',
                'Ce stockage ne sait pas dire ce qu\'il contient sans le parcourir entièrement.'
            );
    }

    /**
     * The same four readings, laid out across every kind of storage.
     *
     * **One row per question, one column per type**, which is the shape
     * the decision actually has: an administrator is not reading four
     * separate verdicts about S3, they are asking « which of these should
     * hold the camp photographs » and comparing the same line across the
     * options. IT-01 built `capabilities()` to interrogate the backend
     * classes for precisely this moment — so this table cannot drift from
     * what the code does, and a backend that gains an aptitude moves its
     * own cell.
     *
     * The types come from the enum's own `cases()`, so a fifth kind of
     * storage appears here the day it exists, with no list to remember.
     *
     * @return list<array{
     *     area: string,
     *     label: string,
     *     cells: list<array{type: StorageLocationType, verdict: string, detail: string}>
     * }>
     */
    public static function comparison(): array
    {
        $byType = [];
        foreach (StorageLocationType::cases() as $type) {
            $byType[$type->value] = self::forType($type);
        }

        $rows = [];
        foreach (array_keys(self::forType(StorageLocationType::Local)) as $index) {
            $cells = [];
            foreach (StorageLocationType::cases() as $type) {
                $consequence = $byType[$type->value][$index];
                $cells[] = [
                    'type' => $type,
                    'verdict' => $consequence->verdict,
                    'detail' => $consequence->detail,
                ];
            }

            $first = $byType[StorageLocationType::Local->value][$index];
            $rows[] = [
                'area' => $first->area,
                'label' => $first->label,
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /** « Photos : oui, le plus rapide — Les photos vont directement… » */
    public function sentence(): string
    {
        return sprintf('%s : %s — %s', $this->label, $this->verdict, $this->detail);
    }
}
