<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Volume\VolumeDirectory;
use Core\Storage\Volume\VolumeInventory;
use Core\Storage\Volume\VolumeUsage;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `storage-locations.txt` — where this installation writes, what each
 * destination can do, and which filesystem each of them really sits on
 * (ARCHITECTURE.md §8.48).
 *
 * The question it answers is the one a screenshot cannot: « les photos ne
 * s'affichent plus » is the same sentence whether a bucket's credentials
 * expired, a network mount went read-only, a disk filled up, or an
 * administrator moved the gallery onto a location nobody has tested since
 * March. The health results, the volume grouping and the capability
 * consequences are what tell those apart.
 *
 * **What it never carries**, and this is the part to read before adding a
 * line to it. The package leaves the installation and goes to a third
 * party:
 *
 * - **No credential of any kind.** Not an access key, not a secret, not a
 *   token. `StorageLocation` helps rather than hinders here — the secret
 *   is absent from it by construction, and only
 *   {@see StorageLocationRepository::getSecret()} can read one — so this
 *   collector cannot print one by accident. What it does say is whether a
 *   secret is configured, which is a yes/no and is exactly what
 *   distinguishes « never set up » from « set up and refused ».
 * - **No absolute path.** A storage path is a server directory, and
 *   `/mnt/nas/photos` says nothing about anybody — but
 *   `/home/marie.dupont/...` does, and a site is free to have one.
 *   {@see SupportCollectorContext::redact()} does NOT cover this: it
 *   replaces the credentials this run knows about and normalises
 *   whitespace, and a person's name in a directory is neither. So every
 *   path printed here goes through {@see maskPath()} instead — relative to
 *   the project root where it is under it (`storage/gallery`, whose
 *   segments are ours), and otherwise the directory's own name behind a
 *   fingerprint of the tree above it. The fingerprint is what keeps two
 *   entries on one mount readable as two entries on one mount, which is
 *   the whole diagnostic value the absolute path carried.
 *
 *   **The limit is stated rather than papered over**, the way SECURITY.md
 *   §11 states the one for the triage extract: the last segment survives,
 *   so a site that declared `/mnt/photos/marie.dupont` prints that word.
 *   Nothing can tell that segment from `photos` automatically, and
 *   dropping it too would leave a line that says nothing at all.
 * - **No endpoint host.** A bucket's endpoint is a provider's public
 *   address and would normally be safe, but combined with the bucket name
 *   it is half of a target; the provider NAME answers every diagnostic
 *   question the endpoint would, and answers it in one word.
 */
class StorageLocationsCollector implements SupportCollectorInterface
{
    public function __construct(
        private StorageLocationRepository $locations,
        private StorageLocationConsumerRegistry $consumers,
        private VolumeInventory $volumes
    ) {
    }

    public function name(): string
    {
        return 'storage_locations';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $lines = [];
        $lines[] = 'EMPLACEMENTS DE STOCKAGE';
        $lines[] = '';
        $lines[] = 'Aucun identifiant, aucune clé, aucun jeton ne figure dans ce fichier.';
        $lines[] = '';

        $lines[] = '── Emplacements déclarés ───────────────────────────────────';
        $locations = $this->locations->findAll();
        if ($locations === []) {
            $lines[] = 'Aucun emplacement déclaré.';
        }
        foreach ($locations as $location) {
            $lines = array_merge($lines, $this->describeLocation($location, $context));
            $lines[] = '';
        }

        $lines[] = '── Volumes ─────────────────────────────────────────────────';
        $lines[] = 'Regroupés par périphérique : deux dossiers de même volume partagent une place libre.';
        $lines[] = '';
        foreach ($this->volumes->measure() as $volume) {
            $lines = array_merge($lines, $this->describeVolume($volume, $context));
            $lines[] = '';
        }

        $context->addFileFromContent('storage-locations.txt', implode("\n", $lines) . "\n");
    }

    /**
     * @return list<string>
     */
    private function describeLocation(StorageLocation $location, SupportCollectorContext $context): array
    {
        $lines = [];
        $lines[] = sprintf(
            '%s [%s]%s',
            $context->redact($location->label, 120),
            $location->type->value,
            $location->isDefault ? ' — par défaut' : ''
        );
        $lines[] = '  Cible          : ' . $context->redact($this->targetOf($location, $context), 200);
        $lines[] = '  Identifiants   : ' . ($location->secretConfigured ? 'configurés' : 'aucun');
        $lines[] = '  Dernier test   : ' . ($location->lastCheckedAt ?? 'jamais');
        $lines[] = '  Résultat       : ' . match ($location->lastCheckOk) {
            true => 'joignable',
            false => 'en erreur — ' . $context->redact((string) $location->lastCheckError, 300),
            default => 'jamais testé',
        };
        $lines[] = '  Sert à         : ' . $this->usagesOf($location->id);
        // The consequences rather than the capability names, for the same
        // reason the screen shows them: whoever reads this package is
        // diagnosing « les vidéos ne se lisent pas », not auditing an enum.
        foreach ($location->consequences() as $consequence) {
            $lines[] = '  ' . str_pad($consequence->label, 14) . ' : ' . $consequence->verdict;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function describeVolume(VolumeUsage $volume, SupportCollectorContext $context): array
    {
        $lines = [];
        // `label()` is either « Volume principal »/« Autre volume » or the
        // shortest declared path on the volume — so the gate asks the one
        // question that separates them, with the same definition of
        // « absolute » the masking itself uses.
        $label = $volume->label();
        $lines[] = sprintf(
            '%s%s',
            $context->redact(
                LocalLocationConfig::isAbsolutePath($label) ? $this->maskPath($label, $context) : $label,
                200
            ),
            $volume->isPrimary ? ' (volume principal)' : ''
        );
        $lines[] = '  Périphérique   : ' . ($volume->deviceId ?? 'non identifié par le système');
        $lines[] = '  Mesure         : ' . match ($volume->basis()) {
            VolumeUsage::BASIS_QUOTA => 'quota déclaré',
            VolumeUsage::BASIS_VOLUME => 'système',
            default => 'aucune',
        };
        $lines[] = '  Occupé         : ' . ($volume->occupiedLabel() ?: 'inconnu');
        $lines[] = '  Sur            : ' . ($volume->basisTotalLabel() ?: 'inconnu');
        $percent = $volume->usedPercent();
        $lines[] = '  Taux           : ' . ($percent !== null ? $percent . ' %' : 'inconnu');
        $lines[] = '  Hors storage/  : ' . ($volume->hasDirectoryOutsideStorage() ? 'oui' : 'non');

        foreach ($volume->directories as $directory) {
            $lines[] = sprintf(
                '  · %s — %s%s',
                $context->redact($this->maskPath($directory->path, $context), 200),
                $directory->exists ? ($directory->sizeLabel() ?: 'taille inconnue') : 'dossier absent',
                $directory->isUnderStoragePath ? '' : ' (hors storage/)'
            );
        }

        return $lines;
    }

    /**
     * **Never lets one module's failure empty the file.** This collector
     * draws a diagnostic and decides nothing, so the lenient reading is
     * the right one here — the opposite of
     * {@see \Core\Storage\Location\StorageLocationService::delete()},
     * where the same answer authorises destroying something.
     */
    private function usagesOf(int $locationId): string
    {
        // **« rien » is only true once somebody has been asked.** This
        // collector runs inside a scheduled task, where no module has
        // registered a consumer — so the question was never put, and
        // printing « rien » beside a location a gallery is standing on
        // would be a wrong answer rather than a missing one. The registry
        // answers that from memory, without a query.
        if ($this->consumers->isEmpty()) {
            return 'indéterminé (aucun module n\'a été interrogé — voir Configuration > Stockage)';
        }

        try {
            $usages = $this->consumers->usagesOf($locationId);
        } catch (\Throwable) {
            return 'indéterminé (un module n\'a pas pu répondre)';
        }

        return $usages === [] ? 'rien' : implode(', ', $usages);
    }

    /**
     * Where a location points, without the two things this file never
     * carries (see the class docblock): an absolute path, and a bucket's
     * endpoint host.
     *
     * Deliberately NOT {@see StorageLocation::describe()}, which is built
     * for a screen an administrator is looking at on their own server and
     * says both.
     */
    private function targetOf(StorageLocation $location, SupportCollectorContext $context): string
    {
        $config = $location->config;

        if ($config instanceof LocalLocationConfig) {
            return $this->maskPath($config->describe(), $context);
        }

        if ($config instanceof ObjectStorageLocationConfig) {
            return ($config->provider ?? 'point de terminaison personnalisé') . ' / ' . $config->bucket;
        }

        if ($config instanceof WebDavLocationConfig) {
            // **The host, and deliberately not the rest of the address.**
            // `describe()` stops at the hostname; the path below it names
            // the account on the share (`…/dav/files/marie.dupont/…`),
            // which is exactly the kind of thing §11 keeps out of a
            // package that goes to somebody else. Which cloud the unit
            // uses is what a diagnosis needs.
            return $config->describe();
        }

        return $location->type->value;
    }

    /**
     * A path this file may print. See the class docblock for the rule and
     * for the limit it does not pretend to cover.
     */
    private function maskPath(string $path, SupportCollectorContext $context): string
    {
        // **Backslashes first, and this is not cosmetic.** `dirname()` and
        // `basename()` are POSIX on this server: handed
        // `C:\Users\marie.dupont\photos` they answer `.` and the WHOLE
        // string, so the fingerprint branch below would print the entire
        // path as though it were a folder name and mask nothing at all.
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $context->projectRoot()), '/');

        // The codebase's own definition, not a second one. A POSIX-only
        // `str_starts_with($path, '/')` here called a declared
        // `C:\Users\…` location « relative » and returned it unchanged —
        // the OS account name straight into an archive that leaves the
        // installation.
        if ($path === '' || !LocalLocationConfig::isAbsolutePath($path)) {
            // Genuinely relative — `storage/gallery`, the shape
            // LocalLocationConfig::describe() gives a non-absolute
            // location. Every segment of it is ours.
            return $path;
        }

        if ($root !== '' && $path === $root) {
            return '.';
        }
        if ($root !== '' && str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return sprintf(
            '[hors racine #%s]/%s',
            substr(hash('sha256', dirname($path)), 0, 6),
            basename($path)
        );
    }
}
