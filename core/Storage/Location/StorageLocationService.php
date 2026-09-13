<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Exception\UserFacingException;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\LocationConfig;

/**
 * What a screen and a consumer do WITH storage locations: create them,
 * check they are reachable, refuse to delete one something still stands
 * on, and hand back the one a consumer should default to.
 *
 * **Health is cached, and that is not an optimisation detail.** Checking a
 * location means writing to it, reading it back and removing the witness —
 * on a bucket, three round trips over the internet. Doing that on every
 * page that renders a photo would make a gallery unusable, and doing it
 * never would let a destination die silently. So the result lives in the
 * row, refreshed on an administrator's explicit « Tester », and lazily
 * once it has aged past {@see HEALTH_CHECK_TTL_SECONDS}.
 */
class StorageLocationService
{
    /**
     * How long a recorded health result is trusted before a caller that
     * needs one triggers a fresh, synchronous check. Long enough that
     * ordinary browsing never pays for it, short enough that a
     * destination which died this morning is not still reported healthy
     * this afternoon.
     */
    public const HEALTH_CHECK_TTL_SECONDS = 900;

    /**
     * The label and the folder a site gets before anybody configures
     * anything — so that a gallery works on a fresh install with no
     * storage configuration at all, which is the case this whole feature
     * must not make harder.
     *
     * **`gallery` and not something tidier, deliberately.** This names a
     * directory that ALREADY HOLDS FILES on every existing installation,
     * and two other places in the core still resolve it by that name:
     * `Core\Maintenance\BackupService::excludedArchivePrefixes()`, which
     * is what keeps the photos out of an archive that asked not to carry
     * them, and `Core\Storage\DiskBudget::measureNow()`, which is what
     * reports them as the gallery's share rather than as « divers ».
     * Renaming the constant alone would have left those two pointing at
     * an empty directory — a backup silently including gigabytes it was
     * told to exclude, and a breakdown reporting zero.
     *
     * The decision to redeclare configuration rather than convert it
     * (D16 of the chantier) is about the location ROWS in the database.
     * It says nothing about moving photographs on disk, and this constant
     * must not quietly do that. `Tests\Core\Storage\
     * DefaultStorageFolderTest` holds the three readings together.
     */
    public const DEFAULT_LABEL = 'Disque du serveur';
    public const DEFAULT_PATH = 'gallery';

    public function __construct(
        private StorageLocationRepository $repository,
        private StorageBackendFactory $backendFactory,
        private StorageLocationConsumerRegistry $consumers
    ) {
    }

    /**
     * @return list<StorageLocation>
     */
    public function all(): array
    {
        return $this->repository->findAll();
    }

    public function findById(int $id): ?StorageLocation
    {
        return $this->findByIdCached($id);
    }

    public function findDefault(): ?StorageLocation
    {
        return $this->repository->findDefault();
    }

    /**
     * @throws StorageLocationException when the label is already taken
     */
    public function create(StorageLocationType $type, string $label, LocationConfig $config, ?string $secret): int
    {
        $this->assertLabelFree($label, null);

        return $this->repository->create($type, $label, $config, $secret);
    }

    /**
     * @throws StorageLocationException when the label is already taken
     */
    public function update(int $id, string $label, LocationConfig $config, ?string $secret): void
    {
        $this->assertLabelFree($label, $id);
        $this->repository->update($id, $label, $config, $secret);
        unset($this->locationsById[$id]);
    }

    public function setDefault(int $id): void
    {
        $this->repository->setDefault($id);
        $this->locationsById = [];
    }

    /**
     * Removes a location, refusing while anything still stands on it.
     *
     * The refusal names the usage — « Galeries photo » — rather than
     * reporting a constraint violation, because the administrator's next
     * move is to go and re-point that usage, and a foreign-key error does
     * not tell them which one to re-point. Nothing is ever deleted from
     * the storage itself: a location is a declaration, and removing the
     * declaration must not remove somebody's photos.
     *
     * **A consumer that cannot be asked is a refusal, not a permission.**
     * « I could not determine whether anybody is using this » and
     * « nobody is using this » are opposite conclusions, and only one of
     * them may end in a deletion. The screens are allowed the lenient
     * reading ({@see usagesOf()}) because they only draw a list; this is
     * where the answer authorises destroying a declaration, so it fails
     * closed. Note that the two together are still safe when they
     * disagree: a page whose « Sert : … » line came out empty offers the
     * button, and the button lands here.
     *
     * @throws StorageLocationException while a consumer depends on $id, or
     *         when one of them could not be asked at all
     */
    public function delete(int $id): void
    {
        try {
            $usages = $this->consumers->usagesOf($id);
        } catch (\Throwable $e) {
            throw new StorageLocationException(
                'Impossible de vérifier si cet emplacement sert encore à quelque chose — il n\'a pas été '
                    . 'supprimé. Réessayez dans un instant.',
                0,
                $e
            );
        }

        if ($usages !== []) {
            throw new StorageLocationException(sprintf(
                'Cet emplacement sert encore à %s — choisissez-lui un autre emplacement avant de le supprimer.',
                implode(', ', $usages)
            ));
        }

        $this->repository->delete($id);
        unset($this->locationsById[$id]);
    }

    /**
     * The French names of everything standing on this location, **as far
     * as can be told** — a consumer that cannot answer is left out.
     *
     * For the screens, and only for them. A configuration page that 500s
     * because one module's table is missing is a page nobody can use to
     * repair that module; one « Sert : … » line short is a smaller harm,
     * and the deletion behind the button does not trust this answer
     * ({@see delete()}).
     *
     * @return list<string>
     */
    public function usagesOf(int $id): array
    {
        try {
            return $this->consumers->usagesOf($id);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Runs a real connectivity check and records the result. Called from
     * an explicit « Tester », from {@see checkFresh()}, and from nowhere
     * that renders a page for a visitor.
     */
    public function checkNow(StorageLocation $location): void
    {
        try {
            $error = $this->backendFactory->create($location)->testConnection();
        } catch (\Throwable $e) {
            // A backend that could not even be BUILT is a location in
            // error like any other — an unreadable secret, a type this
            // version does not know. The administrator needs the row to
            // say so; letting this escape would 500 the page they came to
            // fix it on.
            $error = $e instanceof UserFacingException
                ? $e->getMessage()
                : "L'emplacement n'a pas pu être ouvert avec la configuration enregistrée.";
        }

        $this->repository->recordCheckResult($location->id, $error === null, $error);
        unset($this->locationsById[$location->id]);
    }

    /**
     * Refreshes the recorded result only when it is missing or stale, and
     * hands back the location as it now stands.
     */
    public function checkFresh(StorageLocation $location): StorageLocation
    {
        $stale = $location->lastCheckedAt === null
            || (time() - (int) strtotime($location->lastCheckedAt)) > self::HEALTH_CHECK_TTL_SECONDS;

        if (!$stale) {
            return $location;
        }

        $this->checkNow($location);

        return $this->repository->findById($location->id) ?? $location;
    }

    /**
     * Makes sure this installation has at least one location, so that a
     * consumer on a fresh site has somewhere to write without anybody
     * having configured anything.
     *
     * Idempotent and cheap: a no-op the moment any row exists. Two
     * concurrent first-ever requests both see an empty table and both
     * insert; the UNIQUE index on the label rejects the loser, which then
     * adopts what the winner created rather than failing the request.
     */
    public function ensureDefaultExists(): ?StorageLocation
    {
        $existing = $this->repository->findDefault();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $this->repository->create(
                StorageLocationType::Local,
                self::DEFAULT_LABEL,
                new LocalLocationConfig(self::DEFAULT_PATH),
                null
            );
        } catch (\PDOException) {
            // Lost the race — see above.
        }

        $this->locationsById = [];

        return $this->repository->findDefault();
    }

    /**
     * Locations already read, for the lifetime of this instance.
     *
     * An album view resolves the same location once per media per size,
     * which was one query each for a row that cannot change mid-request:
     * the configuration page saves and redirects.
     *
     * @var array<int, StorageLocation|null>
     */
    private array $locationsById = [];

    private function findByIdCached(int $id): ?StorageLocation
    {
        if (!array_key_exists($id, $this->locationsById)) {
            $this->locationsById[$id] = $this->repository->findById($id);
        }

        return $this->locationsById[$id];
    }

    /**
     * @throws StorageLocationException
     */
    private function assertLabelFree(string $label, ?int $exceptId): void
    {
        $existing = $this->repository->findByLabel($label);
        if ($existing !== null && $existing->id !== $exceptId) {
            throw new StorageLocationException(
                'Un autre emplacement porte déjà ce nom — donnez-lui un nom différent pour les distinguer.'
            );
        }
    }
}
