<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Manifest;

use Core\Archive\ArchiveRejectedException;
use Core\Archive\BoundedZipReader;
use Core\Archive\OpenedArchive;

/**
 * Reads and checks the manifest every migration archive starts with.
 *
 * Three kinds of refusal, all of the whole archive and all before a single
 * object is written:
 *
 * - **a structure this site cannot interpret**: a missing or non-string
 *   field, a version that is not `MAJOR.MINOR.PATCH`, a **major version this
 *   import does not know** — read « au hasard » it would be worse than
 *   refused;
 * - **a manifest that contradicts itself or its archive**: another format
 *   than the import's, a member listed twice, a listed member missing, a
 *   member present but not listed;
 * - **a wrong fingerprint** — checked by the module member by member after
 *   this, with {@see verifyMember()}.
 *
 * Identifiers are strings, never numbers (`007` is not `7`). Fields this
 * site does not know are ignored, so a newer exporter adding one in a minor
 * version is still read.
 */
final class ManifestReader
{
    private const MAX_MANIFEST_BYTES = 1048576;
    private const MAX_ID_BYTES = 190;
    private const SEMVER = '/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})$/';

    /**
     * @throws ManifestException
     * @throws ArchiveRejectedException
     */
    public function read(OpenedArchive $archive, string $expectedFormat, int $supportedMajor): Manifest
    {
        if (!$archive->has(Manifest::FILE)) {
            throw new ManifestException('L\'archive ne contient pas de fichier manifest.json à sa racine.');
        }

        $data = json_decode($archive->read(Manifest::FILE, self::MAX_MANIFEST_BYTES), true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ManifestException('Le fichier manifest.json n\'est pas un objet JSON valide.');
        }

        $format = self::requiredString($data, 'format', 100);
        if ($format !== $expectedFormat) {
            throw new ManifestException(sprintf(
                'Ce fichier est au format « %s », alors que cet import attend le format « %s ».',
                BoundedZipReader::shown($format),
                $expectedFormat
            ));
        }

        $version = self::requiredString($data, 'format_version', 20);
        if (preg_match(self::SEMVER, $version, $parts) !== 1) {
            throw new ManifestException(
                'Le champ format_version doit être une version complète, comme « 1.0.0 ».'
            );
        }
        $major = (int) $parts[1];
        if ($major !== $supportedMajor) {
            throw new ManifestException(sprintf(
                'Ce fichier est en version %s du format, que ce site ne sait pas lire : il lit la version %d.x.x. '
                . 'Mettez ScoutMagic à jour, ou exportez dans une version %d.x.x.',
                $version,
                $supportedMajor,
                $supportedMajor
            ));
        }

        $source = $data['source'] ?? null;
        if (!is_array($source)) {
            throw new ManifestException('Le champ source du manifeste est absent ou n\'est pas un objet.');
        }
        $installationId = self::requiredString($source, 'installation_id', self::MAX_ID_BYTES, 'source.installation_id');
        $exportId = self::requiredString($data, 'export_id', self::MAX_ID_BYTES);

        $exportedAt = self::requiredString($data, 'exported_at', 40);
        if (!self::isIsoDateTime($exportedAt)) {
            throw new ManifestException(
                'Le champ exported_at doit être une date et une heure ISO 8601 avec fuseau, comme « 2026-11-08T14:30:00+01:00 ».'
            );
        }

        return new Manifest(
            $format,
            $version,
            $major,
            $installationId,
            $exportId,
            $exportedAt,
            self::inventory($data, $archive)
        );
    }

    /**
     * Hashes one listed member and compares it with the inventory.
     *
     * @return int the member's measured size
     *
     * @throws ManifestException
     * @throws ArchiveRejectedException
     */
    public function verifyMember(OpenedArchive $archive, Manifest $manifest, string $path): int
    {
        $expected = $manifest->files[$path] ?? null;
        if ($expected === null) {
            throw new ManifestException(sprintf('L\'élément « %s » n\'est pas inventorié.', BoundedZipReader::shown($path)));
        }

        $digest = $archive->digest($path);
        if (!hash_equals($expected['sha256'], $digest['sha256'])) {
            throw new ManifestException(sprintf(
                'L\'empreinte SHA-256 de « %s » ne correspond pas au manifeste : le fichier est incomplet ou a été modifié.',
                BoundedZipReader::shown($path)
            ));
        }
        if ($expected['size'] !== null && $expected['size'] !== $digest['bytes']) {
            throw new ManifestException(sprintf(
                'La taille de « %s » ne correspond pas au manifeste.',
                BoundedZipReader::shown($path)
            ));
        }

        return $digest['bytes'];
    }

    /**
     * @param array<mixed> $data
     * @return array<string, array{sha256: string, size: ?int}>
     *
     * @throws ManifestException
     */
    private static function inventory(array $data, OpenedArchive $archive): array
    {
        $lines = $data['files'] ?? null;
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new ManifestException('Le champ files du manifeste, l\'inventaire des fichiers, est absent ou n\'est pas une liste.');
        }

        $files = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new ManifestException('Une entrée de l\'inventaire files n\'est pas un objet.');
            }
            $path = $line['path'] ?? null;
            $sha = $line['sha256'] ?? null;
            if (!is_string($path) || $path === '' || !is_string($sha) || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
                throw new ManifestException(
                    'Chaque entrée de l\'inventaire files porte un path et un sha256 de 64 caractères hexadécimaux en minuscules.'
                );
            }
            $size = $line['size'] ?? null;
            if ($size !== null && (!is_int($size) || $size < 0)) {
                throw new ManifestException(sprintf('La taille inventoriée de « %s » n\'est pas un entier positif.', BoundedZipReader::shown($path)));
            }
            if ($path === Manifest::FILE) {
                throw new ManifestException('Le manifeste ne s\'inventorie pas lui-même.');
            }
            if (isset($files[$path])) {
                throw new ManifestException(sprintf('L\'inventaire cite deux fois « %s ».', BoundedZipReader::shown($path)));
            }
            if (!$archive->has($path)) {
                throw new ManifestException(sprintf(
                    'L\'inventaire cite « %s », absent de l\'archive.',
                    BoundedZipReader::shown($path)
                ));
            }
            $files[$path] = ['sha256' => $sha, 'size' => $size];
        }

        foreach ($archive->names() as $name) {
            if ($name !== Manifest::FILE && !isset($files[$name])) {
                throw new ManifestException(sprintf(
                    'L\'archive contient « %s », que l\'inventaire du manifeste ne cite pas.',
                    BoundedZipReader::shown($name)
                ));
            }
        }

        return $files;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ManifestException
     */
    private static function requiredString(array $data, string $key, int $maxBytes, ?string $shownAs = null): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maxBytes) {
            throw new ManifestException(sprintf(
                'Le champ %s du manifeste doit être une chaîne de caractères non vide d\'au plus %d octets.',
                $shownAs ?? $key,
                $maxBytes
            ));
        }

        return $value;
    }

    private static function isIsoDateTime(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return false;
        }

        return \Core\Service\DateInput::fromStorage($value) !== null;
    }
}
