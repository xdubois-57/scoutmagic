<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;

/**
 * Asset lifecycle: create, edit the "général" section, archive, restore.
 *
 * Never reads `$_POST`/`$_SESSION` (ARCHITECTURE.md §13) — the Controller
 * reads the request and passes plain values in.
 *
 * **Each configuration section saves independently** (roadmap §6.4), so
 * `updateGeneral()` touches only the general fields. It is not an
 * "update the asset" method and must not grow into one: a two-hundred-field
 * single form is precisely what the module's configuration screen is
 * designed against, and a partial post that blanked a field the operator
 * could not even see would be the bug that follows from it.
 */
class RentalAssetService
{
    /**
     * Asset types offered as a starting point in the UI. Deliberately a
     * suggestion list, not a closed domain: `rental_assets.asset_type` is a
     * free string precisely so a unit can rent out something nobody
     * anticipated without a schema migration. Anything the operator types
     * is accepted.
     */
    public const SUGGESTED_TYPES = ['Local', 'Terrain', 'Tente', 'Remorque', 'Matériel', 'Autre'];

    public function __construct(
        private RentalAssetRepository $assetRepository,
        private RentalSlugGenerator $slugGenerator,
        private JournalService $journal
    ) {
    }

    /**
     * @throws RentalException on invalid input, with a user-facing French message.
     */
    public function create(
        string $name,
        string $assetType,
        ?int $capacity,
        int $quantity,
        ?string $arrivalTime,
        ?string $departureTime,
        ?string $emergencyPhone,
        bool $isPublic,
        bool $showInMenu,
        ?int $userId = null
    ): int {
        $name = trim($name);
        $assetType = trim($assetType);

        $this->validate($name, $assetType, $capacity, $quantity, $arrivalTime, $departureTime);

        $id = $this->assetRepository->create(
            $assetType,
            $name,
            $this->slugGenerator->generate($name),
            $capacity,
            $quantity,
            self::normalizeTime($arrivalTime),
            self::normalizeTime($departureTime),
            $emergencyPhone,
            $isPublic,
            $showInMenu
        );

        // The asset's own name is a chief's label for a building, not
        // personal data, so it is safe in the journal — the emergency phone
        // deliberately is not, and never appears here.
        $this->journal->log(
            'rental',
            'rental_asset_created',
            'info',
            'Bien de location créé : ' . $name,
            ['asset_id' => $id],
            $userId
        );

        return $id;
    }

    /**
     * @throws RentalException
     */
    public function updateGeneral(
        int $assetId,
        string $name,
        string $assetType,
        ?int $capacity,
        int $quantity,
        ?string $arrivalTime,
        ?string $departureTime,
        ?string $emergencyPhone,
        bool $isPublic,
        bool $showInMenu,
        ?int $userId = null
    ): void {
        $asset = $this->requireAsset($assetId);

        $name = trim($name);
        $assetType = trim($assetType);
        $this->validate($name, $assetType, $capacity, $quantity, $arrivalTime, $departureTime);

        // The slug is minted once and kept: renaming an asset must not
        // break a link a renter already holds. It is only ever regenerated
        // when the stored slug is somehow empty (data predating this rule,
        // or an import), which is a repair, not a rename.
        $slug = $asset->slug !== '' ? $asset->slug : $this->slugGenerator->generate($name, $assetId);

        $this->assetRepository->updateGeneral(
            $assetId,
            $assetType,
            $name,
            $slug,
            $capacity,
            $quantity,
            self::normalizeTime($arrivalTime),
            self::normalizeTime($departureTime),
            $emergencyPhone,
            $isPublic,
            $showInMenu
        );

        $this->journal->log(
            'rental',
            'rental_asset_updated',
            'info',
            'Bien de location modifié : ' . $name,
            ['asset_id' => $assetId],
            $userId
        );
    }

    /**
     * Archiving is the only way an asset ever leaves circulation once it has
     * any history: it disappears from public view and from new bookings
     * while staying fully readable to its managers, who still have past
     * bookings to settle. Deleting it instead would destroy the history the
     * accounting exercise and the retention aggregate both depend on
     * (roadmap §6.1, §6.35).
     */
    public function archive(int $assetId, ?int $userId = null): void
    {
        $asset = $this->requireAsset($assetId);
        $this->assetRepository->setArchived($assetId, true);

        $this->journal->log(
            'rental',
            'rental_asset_archived',
            'info',
            'Bien de location archivé : ' . $asset->name,
            ['asset_id' => $assetId],
            $userId
        );
    }

    public function restore(int $assetId, ?int $userId = null): void
    {
        $asset = $this->requireAsset($assetId);
        $this->assetRepository->setArchived($assetId, false);

        $this->journal->log(
            'rental',
            'rental_asset_restored',
            'info',
            'Bien de location désarchivé : ' . $asset->name,
            ['asset_id' => $assetId],
            $userId
        );
    }

    /**
     * Hard-deletes an asset that has no history whatsoever — the "I just
     * created this by mistake" case, and nothing else.
     *
     * Managers are not history: a grant is a configuration choice, and its
     * `ON DELETE CASCADE` cleans it up. Anything that records what actually
     * happened (a booking, and later its documents and ledger lines) makes
     * the asset undeletable, and archive() is the answer instead. Later
     * iterations extend hasHistory() as they add those tables; the method
     * exists now so there is exactly one place to extend.
     *
     * @throws RentalException when the asset has history.
     */
    public function delete(int $assetId, ?int $userId = null): void
    {
        $asset = $this->requireAsset($assetId);

        if ($this->hasHistory($assetId)) {
            throw new RentalException(
                'Ce bien a un historique de réservations : il peut être archivé, mais pas supprimé.'
            );
        }

        $this->assetRepository->delete($assetId);

        $this->journal->log(
            'rental',
            'rental_asset_deleted',
            'info',
            'Bien de location supprimé : ' . $asset->name,
            ['asset_id' => $assetId],
            $userId
        );
    }

    /**
     * Whether anything records what actually happened to this asset, making
     * it archivable but not deletable.
     *
     * Currently nothing does — bookings arrive in a later iteration — so
     * this is honestly `false` rather than a stub pretending to check.
     * Every iteration that adds a history-bearing table adds its check
     * here, which is why deletion goes through this one gate.
     */
    public function hasHistory(int $assetId): bool
    {
        return false;
    }

    public function requireAsset(int $assetId): RentalAsset
    {
        $asset = $this->assetRepository->findById($assetId);
        if ($asset === null) {
            throw new RentalException("Ce bien n'existe pas ou plus.");
        }

        return $asset;
    }

    /**
     * @throws RentalException
     */
    private function validate(
        string $name,
        string $assetType,
        ?int $capacity,
        int $quantity,
        ?string $arrivalTime,
        ?string $departureTime
    ): void {
        if ($name === '') {
            throw new RentalException('Le nom du bien est obligatoire.');
        }

        if ($assetType === '') {
            throw new RentalException('Le type du bien est obligatoire.');
        }

        if ($quantity < 1) {
            throw new RentalException('La quantité doit valoir au moins 1.');
        }

        if ($capacity !== null && $capacity < 1) {
            throw new RentalException('La capacité doit être vide ou valoir au moins 1.');
        }

        foreach ([$arrivalTime, $departureTime] as $time) {
            if ($time !== null && trim($time) !== '' && self::normalizeTime($time) === null) {
                throw new RentalException("L'heure doit être au format HH:MM.");
            }
        }
    }

    /**
     * `HH:MM`, or null for "not set". A browser `<input type="time">` can
     * submit `HH:MM:SS`, so seconds are accepted and dropped rather than
     * rejected — the stored value is always the same shape whatever the
     * browser sent.
     */
    public static function normalizeTime(?string $time): ?string
    {
        $time = $time !== null ? trim($time) : '';
        if ($time === '') {
            return null;
        }

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $matches) !== 1) {
            return null;
        }

        return $matches[1] . ':' . $matches[2];
    }
}
