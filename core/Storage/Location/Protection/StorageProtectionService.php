<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Maintenance\BackupRepository;
use Core\Maintenance\Remote\RemoteBackupDestination;
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
        private readonly ?BackupRepository $backups = null,
        /**
         * Where the off-site backup writes, so that a protection touching
         * that location can say so — see {@see warningsFor()} for the
         * decision this exists to carry out. Null on an installation with
         * no off-site backup configured at all, which warns about nothing
         * and is correct.
         */
        private readonly ?RemoteBackupDestination $remoteBackup = null
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

        $doubleRetention = $this->doubleRetentionWarning($source, $destination, $gracePeriodDays);
        if ($doubleRetention !== null) {
            $warnings[] = $doubleRetention;
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
     * Two retention mechanisms on the same files — **a warning, and
     * deliberately not a refusal**.
     *
     * This is the question IT-05 left to be decided and written down, so
     * here is the decision and here is why.
     *
     * **The arrangement.** The off-site backup keeps a bounded number of
     * archives on its destination and deletes the rest
     * ({@see \Core\Maintenance\Remote\RemoteRetention}: thirty of them,
     * or ten gibibytes, whichever bites first). A protection copies a
     * location's objects elsewhere and, once a file has been gone from the
     * source for the grace period, removes it from the copy too (D13).
     * Point either mechanism at the other's files and both act on the same
     * objects.
     *
     * **It is refused in no case, because the combination is coherent
     * once it is named.** The retention decides which archives stay on the
     * destination; the protection copies whatever is there and, after the
     * grace period, drops from the copy what has left the source. The copy
     * TRACKS the retention rather than fighting it, and nothing is ever
     * deleted that the operator did not, transitively, ask to have
     * deleted. What they get is a second copy of their off-site archives
     * that lags the first by the grace period — which is a thing a unit
     * with one cloud account and one spare disk genuinely wants, and which
     * a prohibition would forbid outright.
     *
     * **And a prohibition would fail in the wrong direction anyway.** A
     * location becomes the backup destination AFTER a protection is
     * declared just as easily as before, and no declaration-time refusal
     * covers that order — IT-04 learned exactly this lesson about the
     * public-URL refusal, which had to be repeated at reconfiguration
     * time to mean anything. A rule that is side-stepped by doing two
     * legitimate steps in the other order is not a protection; it is an
     * obstacle that tells whoever meets it that the site does not
     * understand the arrangement.
     *
     * **So it is said, once, where it can be acted on, and it names the
     * number that makes it matter.** The grace period is what decides how
     * far the copy lags, and it is the one figure an operator can change
     * in response to reading this.
     *
     * Null when neither end is the off-site destination, which is the
     * ordinary case.
     */
    private function doubleRetentionWarning(
        StorageLocation $source,
        StorageLocation $destination,
        int $gracePeriodDays
    ): ?string {
        $backupLocationId = $this->remoteBackup?->locationId() ?? 0;
        if ($backupLocationId === 0) {
            return null;
        }

        if ($source->id === $backupLocationId) {
            return sprintf(
                'Cet emplacement reçoit aussi les sauvegardes hors site, qui y suppriment d\'elles-mêmes les '
                . 'archives les plus anciennes. La copie de secours suivra : une archive purgée ici disparaîtra '
                . 'de « %s » %d jours plus tard. C\'est cohérent — la copie retarde simplement la purge — mais '
                . 'ce n\'est pas une conservation illimitée ; allongez le délai de grâce si vous vouliez en '
                . 'garder davantage.',
                $destination->label,
                $gracePeriodDays
            );
        }

        if ($destination->id === $backupLocationId) {
            return sprintf(
                'La copie de secours écrit dans « %s », qui reçoit aussi les sauvegardes hors site. Les deux '
                . 'mécanismes y déposent des fichiers, et la conservation des archives distantes ne compte que '
                . 'les archives : les fichiers copiés ici ne sont jamais purgés par elle, et occupent donc de '
                . 'la place en plus de celle que les sauvegardes réservent.',
                $destination->label
            );
        }

        return null;
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
