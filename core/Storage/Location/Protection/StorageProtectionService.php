<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Maintenance\BackupRepository;
use Core\Service\DateInput;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;

/**
 * Choosing a location's safety copy, and everything that has to be true
 * before the choice is saved.
 *
 * **Three refusals and two warnings, and the split is the design.** A
 * refusal is for a relation that cannot work or that would expose
 * somebody's files; a warning is for one that will work and that an
 * administrator should know the price of. Turning a warning into a refusal
 * would make the feature unusable for the units that need it most — a unit
 * whose photographs already live off-site has nowhere else to put them —
 * and turning a refusal into a warning would let a wrong click publish a
 * private album for ever.
 */
class StorageProtectionService
{
    /**
     * The shortest grace period this accepts, in days.
     *
     * One, not zero. Zero means « delete from the copy the moment it
     * leaves the source », which turns the safety copy into a mirror and
     * defeats the single case it exists for: somebody deleted an album by
     * mistake this morning.
     */
    public const MIN_GRACE_PERIOD_DAYS = 1;

    public const MIN_CADENCE_HOURS = 1;

    public function __construct(
        private readonly StorageProtectionRepository $protections,
        private readonly StorageLocationRepository $locations,
        private readonly ?BackupRepository $backups = null
    ) {
    }

    /**
     * @return list<StorageProtection>
     */
    public function all(): array
    {
        return $this->protections->findAll();
    }

    public function forSource(int $sourceLocationId): ?StorageProtection
    {
        return $this->protections->findBySourceId($sourceLocationId);
    }

    /**
     * Declares — or corrects — the protection of one source.
     *
     * @throws StorageLocationException when the relation cannot be allowed
     */
    public function save(
        int $sourceLocationId,
        int $destinationLocationId,
        int $gracePeriodDays,
        int $cadenceHours,
        bool $enabled
    ): int {
        $source = $this->requireLocation($sourceLocationId);
        $destination = $this->requireLocation($destinationLocationId);

        $this->refuseCycle($source, $destination);
        $this->refuseExposure($source, $destination);

        if ($gracePeriodDays < self::MIN_GRACE_PERIOD_DAYS) {
            throw new StorageLocationException(
                'Le délai de grâce doit être d\'au moins un jour : à zéro, la copie de secours suit chaque '
                . 'suppression et ne protège plus de la suppression faite par erreur.'
            );
        }
        if ($cadenceHours < self::MIN_CADENCE_HOURS) {
            throw new StorageLocationException('La cadence doit être d\'au moins une heure.');
        }

        return $this->protections->save(
            $sourceLocationId,
            $destinationLocationId,
            $gracePeriodDays,
            $cadenceHours,
            $enabled
        );
    }

    public function delete(int $id): void
    {
        $this->protections->delete($id);
    }

    /**
     * What is true about this relation and worth saying, without stopping
     * it — French sentences, ready to render.
     *
     * @return list<string>
     */
    public function warningsFor(
        StorageLocation $source,
        StorageLocation $destination,
        int $gracePeriodDays
    ): array {
        $warnings = [];

        // **Remote to remote: warn, do not forbid** (the chantier says so
        // in as many words). Neither end is a disk this server can reach,
        // so every byte is downloaded and re-uploaded through it — which
        // is slow, and on metered hosting is paid for twice. It is also
        // the only arrangement that survives losing the server, so a unit
        // that has chosen it has usually chosen it on purpose.
        if ($source->type !== StorageLocationType::Local && $destination->type !== StorageLocationType::Local) {
            $warnings[] = 'Les deux emplacements sont distants : chaque octet copié transite par ce serveur, '
                . 'donc il est téléchargé puis renvoyé. La copie fonctionne, mais elle est plus lente et '
                . 'consomme du trafic des deux côtés.';
        }

        $horizon = $this->restorableHorizonInDays();
        if ($horizon !== null && $gracePeriodDays < $horizon) {
            // **Arithmetic, not documentation.** Restoring a database
            // older than the grace period resurrects `gallery_media` rows
            // whose files the copy has already purged: the albums come
            // back holed, permanently, and nothing anywhere says why.
            $warnings[] = sprintf(
                'Votre plus ancienne sauvegarde restaurable date de %d jours, et le délai de grâce est de '
                . '%d jours. Restaurer cette sauvegarde ferait réapparaître des albums dont les fichiers '
                . 'auraient déjà été effacés de la copie : portez le délai à %d jours au moins.',
                $horizon,
                $gracePeriodDays,
                $horizon
            );
        }

        return $warnings;
    }

    /**
     * How many days back the oldest restorable backup reaches, or null
     * when this installation holds none.
     */
    public function restorableHorizonInDays(?\DateTimeImmutable $now = null): ?int
    {
        $oldest = $this->backups?->oldestRestorableCompletedAt();
        if ($oldest === null) {
            return null;
        }

        $completedAt = DateInput::fromStorage($oldest);
        if ($completedAt === null) {
            return null;
        }

        return $completedAt->diff($now ?? new \DateTimeImmutable())->days;
    }

    /**
     * **No cycle — neither A to itself, nor A to B to A.**
     *
     * A cycle is not merely useless: it is a mechanism that eats itself.
     * Each pass would copy the other's copy back, so a file deleted at the
     * source would be restored from the destination on the next pass and
     * deleted again on the one after, for ever — and the grace period,
     * which counts from « absent from the source », would never elapse for
     * anything.
     *
     * The chain is walked rather than the pair compared, because two
     * relations declared minutes apart are each individually innocent.
     *
     * @throws StorageLocationException
     */
    private function refuseCycle(StorageLocation $source, StorageLocation $destination): void
    {
        if ($source->id === $destination->id) {
            throw new StorageLocationException(
                'Un emplacement ne peut pas être sa propre copie de secours : la copie doit survivre à la '
                . 'perte de ce qu\'elle protège.'
            );
        }

        $seen = [$source->id => true];
        $currentId = $destination->id;

        // Bounded by the number of relations: each step moves to the
        // destination of a DIFFERENT source, and a source appears once.
        while (true) {
            if (isset($seen[$currentId])) {
                throw new StorageLocationException(sprintf(
                    'Cette destination finit par revenir sur « %s », qu\'elle est censée protéger. Une '
                    . 'chaîne de copies qui boucle se recopie indéfiniment et n\'efface jamais rien.',
                    $source->label
                ));
            }
            $seen[$currentId] = true;

            $next = $this->protections->findBySourceId($currentId);
            if ($next === null) {
                return;
            }
            $currentId = $next->destinationLocationId;
        }
    }

    /**
     * **A destination that serves publicly may not protect a source that
     * does not**, and this is the refusal that is about somebody's photos
     * rather than about the mechanism.
     *
     * The hole is created by this iteration and is worth naming. A
     * consumer with its own access control — a delegated album, a
     * discussion group's photographs — is already refused a location that
     * hands out permanent public URLs
     * ({@see \Core\Storage\Location\Config\LocationConfig::servesPubliclyWithoutExpiry()}).
     * That guard reads the location the consumer STANDS on. It knows
     * nothing about a second location the bytes are about to be copied to,
     * because the consumer does not know a protection exists — D4 keeps
     * the assignment with the consumer, and a protection is not an
     * assignment. So a private album on a private location, protected to a
     * public bucket, would have every one of its files readable by anybody
     * with the URL, with no screen anywhere saying so.
     *
     * Stated as a property of the two locations rather than asked of the
     * consumers, deliberately: it needs no new question on
     * {@see \Core\Storage\Location\StorageLocationConsumer}, it holds for a
     * consumer nobody has written yet, and it errs towards refusing a
     * pairing that might have been harmless — which is the direction to err
     * in when the failure is « published for ever, silently ».
     *
     * @throws StorageLocationException
     */
    private function refuseExposure(StorageLocation $source, StorageLocation $destination): void
    {
        if (!$destination->config->servesPubliclyWithoutExpiry()) {
            return;
        }
        if ($source->config->servesPubliclyWithoutExpiry()) {
            return;
        }

        throw new StorageLocationException(sprintf(
            '« %s » distribue des adresses publiques et permanentes, alors que « %s » n\'en distribue pas. '
            . 'Y copier le contenu rendrait accessible à quiconque en a l\'adresse ce qui ne l\'est pas '
            . 'aujourd\'hui. Choisissez une destination qui ne publie pas.',
            $destination->label,
            $source->label
        ));
    }

    private function requireLocation(int $id): StorageLocation
    {
        $location = $this->locations->findById($id);
        if ($location === null) {
            throw new StorageLocationException('Cet emplacement de stockage n\'existe plus.');
        }

        return $location;
    }
}
