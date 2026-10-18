<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Exception\UserFacingException;
use Core\Exception\UserFacingMessage;
use Core\Storage\Location\Backend\ManagedRootFolderBackend;
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

    /**
     * How far up a Drive folder's parents are followed when checking that
     * two locations do not overlap (#832). `ScoutMagic/<label>/` is two
     * levels; this leaves room for a folder an operator nested by hand.
     */
    private const MAX_FOLDER_DEPTH = 10;

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
     * @param bool $existingContentConfirmed the administrator has seen that
     *        the target already holds files, and attaches to it anyway
     * @throws StorageLocationException when the label is already taken
     * @throws StorageLocationTargetNotEmptyException when the target holds
     *         files and that was not confirmed
     */
    public function create(
        StorageLocationType $type,
        string $label,
        LocationConfig $config,
        ?string $secret,
        bool $existingContentConfirmed = false
    ): int {
        $this->assertLabelFree($label, null);
        if (!$existingContentConfirmed) {
            $this->assertTargetEmpty(
                new StorageLocation(0, $type, $label, false, $config, $secret !== null, null, null, null, ''),
                $secret
            );
        }

        return $this->repository->create($type, $label, $config, $secret);
    }

    /**
     * Saves a location — and, when its label changed and it owns its
     * folder (a Google Drive location, #474), renames that folder to
     * match.
     *
     * **The folder is renamed first, and the label saved only once it
     * has been** (#832). A Drive location's folder is ScoutMagic's own:
     * accepting a name here that Google refused would leave the two
     * disagreeing, and an administrator who then looks for the folder in
     * their Drive under the new name — or who renames a location to
     * « repair » it after a restore, which a rename never does — finds
     * nothing. So Google being unreachable, the folder gone or in the
     * trash, the location disconnected, or a grant that cannot be read
     * all refuse the whole save, and the location keeps its old label.
     *
     * Renaming never chooses another folder. The folder is found by its
     * id, which a rename never changes; attaching a location to a
     * different folder is a separate, explicit act
     * ({@see attachDriveFolder()}).
     *
     * **Changing where a location points attaches it to another storage**
     * (#832) — a local folder, a share, a bucket. When that target
     * already holds files, the save waits for the administrator to have
     * seen it: the site would read and write beside them from then on.
     *
     * Two cases save without asking Google, because there is no folder of
     * this location's own to rename: a Drive location never connected,
     * and one that shares its folder with another (pre-#474), whose
     * folder would be renamed along with it — the outcome says so.
     *
     * @throws StorageLocationException when the label is already taken,
     *         or when the folder could not be renamed (its cause attached
     *         as the previous exception, for the journal)
     * @throws StorageLocationTargetNotEmptyException when the new target
     *         holds files and that was not confirmed
     */
    public function update(
        int $id,
        string $label,
        LocationConfig $config,
        ?string $secret,
        bool $existingContentConfirmed = false
    ): RootFolderOutcome {
        $this->assertLabelFree($label, $id);
        $before = $this->repository->findById($id);

        if ($before !== null && !$existingContentConfirmed && $before->config->target() !== $config->target()) {
            $this->assertTargetEmpty(
                new StorageLocation(
                    $before->id,
                    $before->type,
                    $label,
                    $before->isDefault,
                    $config,
                    $before->secretConfigured,
                    null,
                    null,
                    null,
                    $before->createdAt
                ),
                $secret
            );
        }

        $outcome = RootFolderOutcome::nothingToDo();
        $renamed = null;
        if ($before !== null && $before->label !== $label) {
            // Reached with what is being saved, not with the row as it
            // was: a client secret corrected in the same submission is the
            // one that can reach Google, and the stale one would refuse
            // the rename — and with it the correction.
            $backend = $this->managedBackendOf(
                new StorageLocation(
                    $before->id,
                    $before->type,
                    $before->label,
                    $before->isDefault,
                    $config,
                    $before->secretConfigured,
                    $before->lastCheckedAt,
                    $before->lastCheckOk,
                    $before->lastCheckError,
                    $before->createdAt
                ),
                $secret
            );
            if ($backend instanceof ManagedRootFolderBackend) {
                try {
                    $backend->renameRootFolder($label);
                } catch (\Throwable $e) {
                    throw self::renameRefused(self::failure($e), $e);
                }
                $renamed = $backend;
                $outcome = RootFolderOutcome::done();
            } elseif ($backend->isDisconnected()) {
                throw new StorageLocationException(
                    "Le nom n'a pas été changé : cet emplacement n'est plus raccordé à Google Drive, et son "
                        . 'dossier ne peut pas être renommé sans lui. Raccordez-le d\'abord, puis changez son nom.'
                );
            } elseif ($backend->isFailed()) {
                throw self::renameRefused($backend, null);
            } else {
                $outcome = $backend;
            }
        }

        try {
            $this->repository->update($id, $label, $config, $secret);
        } catch (\Throwable $e) {
            // The folder already carries the new name: put the old one
            // back, so that a save which did not happen leaves nothing
            // changed anywhere. Best effort — the save's own failure is
            // what the administrator needs to read.
            if ($renamed !== null) {
                try {
                    $renamed->renameRootFolder($before->label);
                } catch (\Throwable) {
                    // Found by id: a folder left with the new name breaks nothing.
                }
            }
            throw $e;
        }
        unset($this->locationsById[$id]);

        return $outcome;
    }

    /**
     * The refusal a failed folder rename becomes: the label stays, and
     * the reason is said in French. The provider's own words travel as
     * the previous exception, for the journal, never for the screen.
     */
    private static function renameRefused(
        RootFolderOutcome $failure,
        ?\Throwable $cause
    ): StorageLocationRenameRefusedException {
        return new StorageLocationRenameRefusedException(
            "Le nom n'a pas été changé : le dossier de cet emplacement sur Google Drive n'a pas pu être renommé. "
                . $failure->reason
                . ' Renommer l\'emplacement ne choisit pas un autre dossier : si le sien a été supprimé ou '
                . 'mis à la corbeille, récupérez-le dans Google Drive avant de le renommer.',
            0,
            // The detail rather than the cause itself: it carries the
            // provider's answer underneath ScoutMagic's own words.
            new \RuntimeException((string) $failure->detail, 0, $cause)
        );
    }

    /**
     * Refuses to attach $proposed to a target that already holds files,
     * until the administrator confirms (#832). Reads one entry, writes
     * nothing — a local folder that does not exist yet is not created.
     *
     * A target that cannot be read is let through: whether it is
     * reachable is the health check's question, asked right after the
     * save, and refusing here would only make an unreachable target
     * impossible to correct. A Drive location never gets here: its
     * folder changes only through {@see attachDriveFolder()} and
     * {@see createDriveFolder()}.
     *
     * @throws StorageLocationTargetNotEmptyException
     */
    private function assertTargetEmpty(StorageLocation $proposed, ?string $secret): void
    {
        if ($proposed->type === StorageLocationType::GoogleDrive) {
            return;
        }

        try {
            $backend = $this->backendFactory->probe($proposed, $secret);
            $holdsFiles = $backend instanceof Backend\EmptinessProbingBackend
                ? $backend->holdsAnything()
                : $backend->list('', null, 1)->objects !== [];
        } catch (\Throwable) {
            return;
        }

        if ($holdsFiles) {
            throw new StorageLocationTargetNotEmptyException(
                '« ' . $proposed->config->describe() . ' » contient déjà des fichiers. Si vous confirmez, cet '
                    . 'emplacement s\'y rattache tel quel : rien n\'y est copié, déplacé ni effacé, et le site lira '
                    . 'et écrira à côté de ce qui s\'y trouve.'
            );
        }
    }

    /**
     * Attaches a Drive location to a folder that already exists — the
     * repair after a restore brought back a folder id that no longer
     * leads anywhere, or after the folder was recreated by hand (#832).
     *
     * **By id, never by name**: $reference is the folder's address as
     * Google Drive shows it, or its bare id. Before anything is saved the
     * folder is looked at with the location's own account: it must exist,
     * be a folder, be out of the trash, and belong to no other location.
     * One that already holds files is attached only once the administrator
     * has confirmed it. Nothing in it is moved, renamed or deleted, and
     * the location's label is not touched.
     *
     * @return string the folder's name, for the confirmation
     * @throws StorageLocationException
     * @throws StorageLocationTargetNotEmptyException
     */
    public function attachDriveFolder(int $id, string $reference, bool $existingContentConfirmed): string
    {
        [$location, $config] = $this->connectedDriveLocation($id);

        $folderId = self::driveFolderIdFrom($reference);
        if ($folderId === '') {
            throw new StorageLocationException(
                'Collez l\'adresse du dossier telle que Google Drive l\'affiche dans votre navigateur, ou son '
                    . 'identifiant.'
            );
        }
        if ($folderId === $config->folderId) {
            throw new StorageLocationException('Cet emplacement est déjà rattaché à ce dossier.');
        }
        $othersByFolder = [];
        foreach ($this->repository->findAll() as $other) {
            if ($other->id !== $id
                && $other->config instanceof Config\GoogleDriveLocationConfig
                && $other->config->folderId !== ''
            ) {
                $othersByFolder[$other->config->folderId] = $other->label;
            }
        }
        if (isset($othersByFolder[$folderId])) {
            throw new StorageLocationException(
                "Ce dossier est déjà celui de l'emplacement « {$othersByFolder[$folderId]} » : deux emplacements "
                    . 'ne partagent jamais un dossier.'
            );
        }

        $attached = new Config\GoogleDriveLocationConfig($config->clientId, $folderId, $config->connectedAt);
        $proposed = new StorageLocation(
            $location->id,
            $location->type,
            $location->label,
            $location->isDefault,
            $attached,
            $location->secretConfigured,
            null,
            null,
            null,
            $location->createdAt
        );

        try {
            $backend = $this->backendFactory->probe($proposed, null);
            if (!$backend instanceof Backend\GoogleDriveBackend) {
                throw new \LogicException('A Google Drive location built something else than a Drive backend.');
            }
            $folder = $backend->describeRootFolder();
            $usable = $folder !== null && $folder['folder'] && !$folder['trashed'];
            $holdsFiles = $usable && $backend->list('', null, 1)->objects !== [];
            $overlap = $usable ? $this->overlappingLocation($backend, $folderId, $folder, $othersByFolder) : null;
            $sharedRefusal = $usable
                ? self::sharedFolderRefusal($backend, $folderId, $folder, $config->folderId)
                : null;
        } catch (StorageLocationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StorageLocationException(
                'Google Drive n\'a pas pu montrer ce dossier : rien n\'a été changé. '
                    . UserFacingMessage::from($e, 'Réessayez dans un instant.'),
                0,
                $e
            );
        }

        if ($folder === null) {
            throw new StorageLocationException(
                'Le compte raccordé ne voit aucun dossier à cette adresse. Ce site ne voit que les dossiers qu\'il '
                    . 'a créés lui-même : choisissez un dossier rangé sous « '
                    . Config\GoogleDriveLocationConfig::PARENT_FOLDER_NAME . ' » dans le Drive de ce compte.'
            );
        }
        if (!$folder['folder']) {
            throw new StorageLocationException('Cette adresse désigne un fichier, pas un dossier.');
        }
        if ($folder['trashed']) {
            throw new StorageLocationException(
                "Le dossier « {$folder['name']} » est dans la corbeille de Google Drive : restaurez-le d'abord."
            );
        }
        if ($sharedRefusal !== null) {
            throw new StorageLocationException($sharedRefusal);
        }
        if ($overlap !== null) {
            throw new StorageLocationException($overlap);
        }
        if ($holdsFiles && !$existingContentConfirmed) {
            throw new StorageLocationTargetNotEmptyException(
                "Le dossier « {$folder['name']} » contient déjà des fichiers. Si vous confirmez, cet emplacement "
                    . 's\'y rattache tel quel : rien n\'y est copié, déplacé ni effacé, et le site lira et écrira '
                    . 'à côté de ce qui s\'y trouve.'
            );
        }

        $this->repository->update($id, $location->label, $attached, null);
        unset($this->locationsById[$id]);

        return $folder['name'];
    }

    /**
     * Why $folderId must not be attached because it may be the shared
     * `ScoutMagic` folder, or null when nothing points that way (#924).
     *
     * The shared folder is the only one this site creates at the top of
     * « Mon Drive »; a location's own is always inside it. Attaching a
     * location to it would put every location's files in reach of one
     * deletion, so a folder with little above it is refused. But refusing
     * is not the same as saying what it is: the refusal used to call every
     * such folder « le dossier commun », including an ordinary one in « Mon
     * Drive » or one whose parent the site cannot see. Three findings,
     * each worded for what was established and no more:
     *
     * - the folder holds THIS location's own folder: it is the shared one,
     *   by id, so a rename or a move changes nothing;
     * - it bears the shared folder's name: refused as a precaution (a
     *   shared folder moved deeper would escape the next test), and said
     *   to rest on the name;
     * - fewer than two folders above it: either it sits directly in « Mon
     *   Drive » (seen) or the site cannot tell where it sits (unknown).
     *   Neither proves it is the shared folder, and neither is allowed.
     *
     * Every refusal ends on what the administrator can do, and that a new
     * folder is not a recovery of the old files.
     *
     * @param array{name: string, parents: list<string>} $folder
     */
    private static function sharedFolderRefusal(
        Backend\GoogleDriveBackend $backend,
        string $folderId,
        array $folder,
        string $ownFolderId
    ): ?string {
        $shared = Config\GoogleDriveLocationConfig::PARENT_FOLDER_NAME;

        if (in_array($folderId, self::sharedParentsOf($backend, [$ownFolderId]), true)) {
            return 'C\'est le dossier commun « ' . $shared . ' » : il range le dossier de cet emplacement, comme '
                . 'celui de tous les emplacements. Choisissez celui de cet emplacement, à l\'intérieur.';
        }

        if ($folder['name'] === $shared) {
            return 'Ce dossier s\'appelle « ' . $shared . ' », comme le dossier commun qui range les dossiers de '
                . 'tous les emplacements. Il est refusé par prudence : le rattacher mettrait les fichiers de tous '
                . 'les emplacements à portée d\'une seule suppression. Si ce n\'est pas lui, renommez-le dans '
                . 'Google Drive puis recommencez ; sinon, choisissez le dossier de cet emplacement, à l\'intérieur.';
        }

        $ancestors = self::ancestorsOf($backend, $folder['parents']);
        if (count($ancestors) >= 2) {
            return null;
        }

        $notRecovery = 'Créer un nouveau dossier ne reprendrait pas ses fichiers.';
        if (self::reachesTopOfDrive($backend, $ancestors)) {
            return 'Le dossier « ' . $folder['name'] . ' » est rangé directement dans « Mon Drive », pas dans le '
                . 'dossier commun « ' . $shared . ' » où ce site range les dossiers de ses emplacements : il n\'a '
                . 'donc pas été rattaché. Si c\'est bien le dossier à reprendre, déplacez-le dans « ' . $shared
                . ' » depuis Google Drive, puis recommencez. ' . $notRecovery;
        }

        return 'Ce site ne voit pas dans quel dossier est rangé « ' . $folder['name'] . ' » : il ne peut pas '
            . 'vérifier qu\'il se trouve dans le dossier commun « ' . $shared . ' », et rien n\'a été changé. Il '
            . 'ne voit que les dossiers qu\'il a créés lui-même. Si c\'est bien le dossier à reprendre, rangez-le '
            . 'dans « ' . $shared . ' » depuis Google Drive, puis recommencez. ' . $notRecovery;
    }

    /**
     * Whether the last folder of a walk upwards is the top of « Mon Drive »
     * itself, as opposed to a folder the site was not allowed to look at.
     * The top has no parent, and is told apart from an invisible folder by
     * that, which is the difference between « seen » and « unknown ».
     *
     * @param list<string> $ancestors nearest first
     */
    private static function reachesTopOfDrive(Backend\GoogleDriveBackend $backend, array $ancestors): bool
    {
        if ($ancestors === []) {
            return false;
        }
        $last = $ancestors[count($ancestors) - 1];
        if ($last === 'root') {
            return true;
        }
        $described = $backend->describeFolder($last);

        return $described !== null && $described['parents'] === [];
    }

    /**
     * Why $folderId cannot be this location's because of another one, or
     * null: it lies inside another location's folder, or another
     * location's folder lies inside it. Either way the two would share
     * files — and deleting the outer one would put the inner one in the
     * trash with it. Looked up through the parents Google reports, a few
     * levels at most; a folder this account cannot see ends the walk.
     *
     * @param array{parents: list<string>} $folder
     * @param array<string, string> $othersByFolder folder id → label
     */
    private function overlappingLocation(
        Backend\GoogleDriveBackend $backend,
        string $folderId,
        array $folder,
        array $othersByFolder
    ): ?string {
        foreach (self::ancestorsOf($backend, $folder['parents']) as $ancestor) {
            if (isset($othersByFolder[$ancestor])) {
                return "Ce dossier est rangé dans celui de l'emplacement « {$othersByFolder[$ancestor]} » : les "
                    . 'deux partageraient des fichiers. Choisissez un dossier à part.';
            }
        }
        foreach ($othersByFolder as $otherFolderId => $label) {
            $other = $backend->describeFolder($otherFolderId);
            if ($other !== null && in_array($folderId, self::ancestorsOf($backend, $other['parents']), true)) {
                return "Ce dossier contient celui de l'emplacement « {$label} » : supprimer l'un mettrait l'autre "
                    . 'à la corbeille. Choisissez un dossier à part.';
            }
        }

        return null;
    }

    /**
     * The shared `ScoutMagic` folder by id, not by name — an operator may
     * have renamed it in Drive: whatever folder holds a location's own
     * folder, in the trash or not. Read only; a folder this account cannot
     * see contributes nothing.
     *
     * @param list<string> $locationFolderIds
     * @return list<string>
     */
    private static function sharedParentsOf(Backend\GoogleDriveBackend $backend, array $locationFolderIds): array
    {
        $parents = [];
        foreach (array_filter($locationFolderIds, static fn (string $id): bool => $id !== '') as $id) {
            array_push($parents, ...($backend->describeFolder($id)['parents'] ?? []));
        }

        return array_values(array_unique($parents));
    }

    /**
     * @param list<string> $parents
     * @return list<string> the folders above, nearest first
     */
    private static function ancestorsOf(Backend\GoogleDriveBackend $backend, array $parents): array
    {
        $ancestors = [];
        $next = $parents[0] ?? null;
        for ($depth = 0; $next !== null && $depth < self::MAX_FOLDER_DEPTH; $depth++) {
            if (in_array($next, $ancestors, true)) {
                break;
            }
            $ancestors[] = $next;
            $next = $backend->describeFolder($next)['parents'][0] ?? null;
        }

        return $ancestors;
    }

    /**
     * Gives a Drive location a new, empty folder under `ScoutMagic/`, named
     * after it (#832) — the explicit act that a restore, a reconnection
     * or a rename never performs on their own.
     *
     * Refused while the location's folder is still there: what is in it
     * would drop out of the site's sight without anything saying so. A
     * second destination is a new location, not this one emptied.
     *
     * @throws StorageLocationException
     */
    public function createDriveFolder(int $id): void
    {
        [$location, $config] = $this->connectedDriveLocation($id);

        try {
            $backend = $this->backendFactory->probe($location, null);
            if (!$backend instanceof Backend\GoogleDriveBackend) {
                throw new \LogicException('A Google Drive location built something else than a Drive backend.');
            }
            $current = $config->folderId !== '' ? $backend->describeRootFolder() : null;
            if ($current !== null && !$current['trashed']) {
                throw new StorageLocationException(
                    "Le dossier de cet emplacement existe toujours dans Google Drive (« {$current['name']} ») : "
                        . 'aucun nouveau dossier n\'a été créé. Pour une seconde destination, créez un nouvel '
                        . 'emplacement.'
                );
            }
            $folderId = $backend->createRootFolder($location->label);
        } catch (StorageLocationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StorageLocationException(
                'Google Drive n\'a pas pu créer le dossier : rien n\'a été changé. '
                    . UserFacingMessage::from($e, 'Réessayez dans un instant.'),
                0,
                $e
            );
        }

        $this->repository->update(
            $id,
            $location->label,
            new Config\GoogleDriveLocationConfig($config->clientId, $folderId, $config->connectedAt),
            null
        );
        unset($this->locationsById[$id]);
    }

    /**
     * A Drive location with an account behind it — the only kind whose
     * folder can be looked at, and so the only kind that can be attached
     * to one or given one.
     *
     * @return array{StorageLocation, Config\GoogleDriveLocationConfig}
     * @throws StorageLocationException
     */
    private function connectedDriveLocation(int $id): array
    {
        $location = $this->repository->findById($id);
        if ($location === null || !$location->config instanceof Config\GoogleDriveLocationConfig) {
            throw new StorageLocationException('Cet emplacement n\'est pas un emplacement Google Drive.');
        }
        if (!$location->config->isConnected()) {
            throw new StorageLocationException(
                'Raccordez d\'abord un compte Google à cet emplacement : son dossier ne peut pas être vérifié '
                    . 'sans lui.'
            );
        }

        return [$location, $location->config];
    }

    /**
     * The folder id in what an administrator pasted: the address Drive
     * shows (`…/drive/folders/<id>`, `…?id=<id>`) or the bare id. Empty
     * when it is neither.
     */
    public static function driveFolderIdFrom(string $reference): string
    {
        $reference = trim($reference);
        if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $reference, $m) === 1
            || preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $reference, $m) === 1
            || preg_match('~^([A-Za-z0-9_-]{10,})$~', $reference, $m) === 1
        ) {
            return $m[1];
        }

        return '';
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
     * **A folder the application created goes to the trash with it**
     * (#474) — a Google Drive location's `ScoutMagic/<label>/`, which
     * nobody typed and which would otherwise outlive the location for
     * ever. The trash, not a deletion: Google keeps it about thirty days,
     * which is the operator's way back from a wrong click. A folder the
     * administrator named — a local path, a WebDAV share, a bucket — is
     * theirs and is never touched. A failure to reach Drive does not keep
     * the location alive: it comes back for the caller to journal and to
     * show, and the folder simply stays where it is.
     *
     * @throws StorageLocationException while a consumer depends on $id, or
     *         when one of them could not be asked at all
     */
    public function delete(int $id): RootFolderOutcome
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

        // Built BEFORE the row goes: the backend needs the row's secret,
        // and the trash comes after the deletion so that a location that
        // could not be deleted never loses its folder.
        $location = $this->repository->findById($id);
        $backend = $location !== null ? $this->managedBackendOf($location) : RootFolderOutcome::nothingToDo();

        $this->repository->delete($id);
        unset($this->locationsById[$id]);

        if ($backend instanceof RootFolderOutcome) {
            return $backend;
        }

        try {
            $backend->trashRootFolder();
        } catch (\Throwable $e) {
            return self::failure($e);
        }

        return RootFolderOutcome::done();
    }

    /**
     * The folder a Drive location writes into once connected:
     * `ScoutMagic/<label>/` (#474). Called by the connection callback with
     * the access token Google just granted.
     *
     * **The one it already has, when the account just authorised can
     * still see it** — a reconnection after the seven-day expiry, or after
     * « Déraccorder », must land on the folder holding the files and not
     * beside it. A label changed while disconnected is caught up with
     * here, as the rename itself could not reach Google — unless another
     * location shares the folder (pre-#474 rows), whose folder would be
     * renamed along with it. A failed rename changes nothing: the folder
     * is found by id.
     *
     * **A new one only on a first connection**, when the location has
     * never had a folder: the shared `ScoutMagic` parent is found or
     * created, and the location's own folder is always CREATED under it,
     * never looked up by name — two locations must never share a folder
     * again, which is exactly what a lookup by name produced.
     *
     * **Null when the folder it had is gone** — deleted, in the trash, or
     * out of sight of the account just authorised (#832). Creating one
     * then would point the location at an empty folder while its files
     * wait elsewhere: a restore that brought back an old folder id, or
     * another account, is exactly when that happens. The caller keeps the
     * grant and the old id, and says so; attaching an existing folder or
     * creating a new one is then the administrator's explicit choice
     * ({@see attachDriveFolder()}, {@see createDriveFolder()}).
     *
     * @throws Backend\Drive\DriveAccessException
     */
    public function resolveDriveFolder(
        Backend\Drive\GoogleDriveClient $client,
        string $accessToken,
        StorageLocation $location
    ): ?string {
        $config = $location->config instanceof Config\GoogleDriveLocationConfig
            ? $location->config
            : new Config\GoogleDriveLocationConfig();

        if ($config->folderId !== '') {
            $existing = $client->describeFile($accessToken, $config->folderId);
            if ($existing !== null && !$existing['trashed']) {
                if ($existing['name'] !== $location->label && $this->otherLocationOnTheSameFolder($location) === null) {
                    try {
                        $client->renameFile($accessToken, $config->folderId, $location->label);
                    } catch (Backend\Drive\DriveAccessException) {
                        // Found by id: a stale name breaks nothing.
                    }
                }

                return $config->folderId;
            }

            return null;
        }

        $parentId = $client->ensureFolder($accessToken, Config\GoogleDriveLocationConfig::PARENT_FOLDER_NAME);

        return $client->createFolder($accessToken, $location->label, $parentId);
    }

    /**
     * Another Drive location whose folder is this one's, or null.
     *
     * **Only possible for locations connected before #474.** The old
     * connection looked the folder up by its NAME, so two locations on
     * the same account were given the same folder id — and nothing
     * migrates them. Since #474 each connection creates its own folder,
     * so a location connected today never matches.
     */
    private function otherLocationOnTheSameFolder(StorageLocation $location): ?StorageLocation
    {
        if (!$location->config instanceof Config\GoogleDriveLocationConfig) {
            return null;
        }
        foreach ($this->repository->findAll() as $other) {
            if ($other->id !== $location->id
                && $other->type === StorageLocationType::GoogleDrive
                && $other->config instanceof Config\GoogleDriveLocationConfig
                && $other->config->folderId === $location->config->folderId
            ) {
                return $other;
            }
        }

        return null;
    }

    /**
     * The backend of $location when it owns its folder — or the outcome
     * to report instead: nothing to do for every other kind, a failure
     * when the backend cannot even be built (an unreadable secret).
     */
    private function managedBackendOf(
        StorageLocation $location,
        ?string $secret = null
    ): ManagedRootFolderBackend|RootFolderOutcome {
        if ($location->type !== StorageLocationType::GoogleDrive) {
            // Asked of the type first: building a local backend creates
            // its directory, and an S3 one a client, for nothing.
            return RootFolderOutcome::nothingToDo();
        }
        if ($location->config instanceof Config\GoogleDriveLocationConfig && $location->config->folderId === '') {
            // Never connected: there is no folder, and saying one was put
            // in the trash would be untrue.
            return RootFolderOutcome::nothingToDo();
        }
        if ($location->config instanceof Config\GoogleDriveLocationConfig && !$location->config->isConnected()) {
            // Disconnected: « Déraccorder » keeps the folder id so that a
            // reconnection finds the folder again, but there is no grant
            // left to reach Google with. Asking anyway would fail, and
            // report an ordinary state as a Drive fault.
            return RootFolderOutcome::disconnected();
        }
        $sharer = $this->otherLocationOnTheSameFolder($location);
        if ($sharer !== null) {
            // Renaming it would rename the other location's folder, and
            // trashing it would hide — then, after thirty days, destroy —
            // the other location's live files.
            return RootFolderOutcome::sharedWith($sharer->label);
        }

        try {
            // Built afresh, never from the factory's per-location cache: on
            // an update, $location carries what is being saved.
            $backend = $this->backendFactory->probe($location, $secret);
        } catch (\Throwable $e) {
            return self::failure($e);
        }

        return $backend instanceof ManagedRootFolderBackend ? $backend : RootFolderOutcome::nothingToDo();
    }

    private static function failure(\Throwable $e): RootFolderOutcome
    {
        $reason = UserFacingMessage::from($e, 'Google Drive n\'a pas répondu comme prévu.');
        $detail = $e->getMessage();
        if ($e->getPrevious() !== null) {
            $detail .= ' — ' . $e->getPrevious()->getMessage();
        }

        return RootFolderOutcome::failed($reason, $detail);
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
     *
     * **Only the race is swallowed.** Catching every `PDOException` here
     * would turn a missing table, a dead connection or a refused write
     * into « cette installation n'a pas d'emplacement par défaut » — a
     * sentence that sends an administrator looking at the storage
     * configuration for a fault that is in the database. So the race is
     * recognised by its error codes and everything else is left to travel.
     *
     * **The race has three shapes, not one**, because
     * {@see StorageLocationRepository::create()} locks. The gap lock it
     * takes does not make the loser wait — gap locks do not conflict with
     * each other — it makes the two INSERTs collide, and InnoDB resolves
     * that with a deadlock or a lock-wait timeout. So the loser arrives
     * here as 1213 or 1205 as readily as as the UNIQUE index's 1062, and
     * treating only the last as « lost the race » would 500 the very
     * request this method exists to keep working: on a fresh install the
     * table is empty and a browser fetching several renditions of one
     * photo calls this concurrently.
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
        } catch (\PDOException $e) {
            if (!self::isLostRace($e)) {
                throw $e;
            }
            // Lost the race — see above. The winner's row is read below.
        }

        $this->locationsById = [];

        return $this->repository->findDefault();
    }

    /**
     * Whether this failure is another request having got there first, as
     * opposed to any other reason an INSERT can fail.
     *
     * Three driver codes, because the loser can arrive by three routes:
     *
     * - **1062**, the UNIQUE index on the label refusing a duplicate —
     *   the shape the race takes when both callers reach the INSERT.
     * - **1213**, a deadlock: the two insert-intention locks each
     *   conflicting with the other's gap lock, which is what
     *   {@see StorageLocationRepository::create()}'s `FOR UPDATE`
     *   actually produces.
     * - **1205**, the same collision resolved by a lock-wait timeout
     *   instead of by the deadlock detector.
     *
     * The driver code decides rather than the SQLSTATE: `23000` covers
     * every integrity constraint including the foreign keys, and `HY000`
     * covers almost everything else there is.
     *
     * All three roll the loser's transaction back, so re-reading
     * {@see StorageLocationRepository::findDefault()} afterwards returns
     * the winner's row and nothing half-written.
     */
    private static function isLostRace(\PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1213, 1205], true);
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
