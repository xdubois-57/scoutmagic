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

    /** « Sauvegardes : oui, un envoi coupé est repris ». */
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
                'oui, sans reprise',
                'Un envoi volumineux interrompu repart de zéro.'
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

    /** « Photos : oui, le plus rapide — Les photos vont directement… » */
    public function sentence(): string
    {
        return sprintf('%s : %s — %s', $this->label, $this->verdict, $this->detail);
    }
}
