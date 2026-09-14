<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Config\SettingService;
use Core\Storage\Location\Backend\ResumableUploadBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;

/**
 * Which declared storage location the off-site backup sends to.
 *
 * **This class is what is LEFT of `RemoteBackupConnection` once a Drive
 * folder is a storage location.** That one held six settings rows, three
 * secrets and an OAuth state machine, because it was the only thing in the
 * application that knew what Google was. All of that moved: the
 * credentials to the location's own encrypted column (D7), the folder and
 * the client id to its configuration record, the consent flow to the
 * screen that declares locations. What does not move is the ASSIGNMENT —
 * « the off-site backup goes there » — which is D4: an assignment belongs
 * to the consumer that made it, and there is deliberately no join table
 * in the middle.
 *
 * So one setting remains, holding one identifier, and this class is the
 * one place that reads it.
 *
 * **Any location may be chosen, and the screen rather than this class is
 * where that is narrowed.** A location that cannot resume an interrupted
 * upload is refused ({@see backend()}), because an archive measured in
 * gibibytes over a domestic upstream link does not finish in one run on
 * any hosting this application exists for. Beyond that, whether a
 * destination is genuinely off-site is a judgement about the operator's
 * infrastructure — a local location pointing at a network mount in another
 * building is off-site; a Drive folder in the same account as their photos
 * is arguably less so — and this class is not in a position to make it.
 */
final class RemoteBackupDestination
{
    /**
     * The location the off-site backup writes to, by id, or '' for none.
     *
     * `editable: false` like every other assignment: it is written by the
     * screen that offers the list of declared locations, and a value
     * hand-typed on the generic settings page would point the backups at
     * a row that may not exist.
     */
    public const LOCATION_SETTING = 'backup_remote_location_id';

    public function __construct(
        private readonly SettingService $settings,
        private readonly StorageLocationRepository $locations,
        private readonly StorageBackendFactory $backends
    ) {
    }

    public static function register(SettingService $settings): void
    {
        $settings->register(
            self::LOCATION_SETTING,
            '',
            'text',
            'Destination des sauvegardes hors site',
            'L\'emplacement de stockage où les archives chiffrées sont envoyées.',
            null,
            null,
            null,
            false,
            300
        );
    }

    public function locationId(): int
    {
        return (int) ($this->settings->get(self::LOCATION_SETTING) ?: 0);
    }

    /**
     * The chosen location, or null when none is chosen **or when the one
     * that was chosen no longer exists**.
     *
     * The second case is not defensive programming: `StorageLocationService
     * ::delete()` refuses while a consumer stands on a location, and
     * {@see RemoteBackupConsumer} is what makes this one such a consumer —
     * but a row can still vanish under an installation restored from a
     * backup taken before the assignment was made. Answering null there is
     * what turns that into « rien ne part » on the screen instead of a
     * fatal in the scheduler.
     */
    public function location(): ?StorageLocation
    {
        $id = $this->locationId();

        return $id > 0 ? $this->locations->findById($id) : null;
    }

    public function isConfigured(): bool
    {
        return $this->location() !== null;
    }

    /**
     * The backend to write archives through, or **a refusal in French**.
     *
     * @throws RemoteBackupException when a destination is chosen that
     *         cannot resume an interrupted upload. Refused rather than
     *         attempted: `put()` takes the whole archive as a string, so a
     *         site with a gibibyte of photographs would meet a fatal at
     *         four in the morning instead of an explanation on a screen.
     */
    public function backend(): ?ResumableUploadBackend
    {
        $location = $this->location();
        if ($location === null) {
            return null;
        }

        $backend = $this->backends->create($location);
        if (!$backend instanceof ResumableUploadBackend) {
            throw RemoteBackupException::of(sprintf(
                'L\'emplacement « %s » ne sait pas %s. Une archive de sauvegarde ne part jamais en une seule '
                . 'fois : choisissez une destination qui sait reprendre un envoi interrompu.',
                $location->label,
                StorageCapability::ResumableUpload->frenchDescription()
            ));
        }

        return $backend;
    }

    /**
     * Since when this site has had somewhere to send to — the date the
     * age check measures from when nothing has ever arrived.
     *
     * A Drive location knows when its grant was obtained and says so; any
     * other location falls back to the day it was declared, which is the
     * same claim in weaker form. « Ten days with nothing sent » is ten
     * days whether the sends failed or never started, and the second is
     * the more alarming of the two.
     */
    public function activeSince(): string
    {
        $location = $this->location();
        if ($location === null) {
            return '';
        }

        $config = $location->config;
        if ($config instanceof \Core\Storage\Location\Config\GoogleDriveLocationConfig && $config->connectedAt !== '') {
            return $config->connectedAt;
        }

        return $location->createdAt;
    }

    /** Points the off-site backup at $locationId, or at nothing for 0. */
    public function choose(int $locationId): void
    {
        $this->settings->setInternal(self::LOCATION_SETTING, $locationId > 0 ? (string) $locationId : '');
    }
}
