<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Storage\Location\StoredObject;

/**
 * The off-site copies as the destination lists them (#847, part 4) —
 * never deduced from the database, where they leave no row.
 *
 * Each copy says whether the phrase in force opens it, read from the
 * generation {@see RemoteRetention::nameFor()} writes into its name:
 * nothing is downloaded to tell. A name whose generation cannot be read
 * is « Ancienne phrase de passe », the answer that asks for a phrase
 * rather than one that fails after a long download.
 */
final class RemoteCopies
{
    public const STATE_NONE = 'none';
    public const STATE_UNREACHABLE = 'unreachable';
    public const STATE_LISTED = 'listed';

    public function __construct(
        private readonly RemoteBackupDestination $destination,
        private readonly RemoteRetention $retention,
        private readonly RemotePassphrase $passphrase
    ) {
    }

    /**
     * @return array{
     *     state: string,
     *     location: ?string,
     *     service: ?string,
     *     locationId: int,
     *     copies: list<array{name: string, size: int, writtenAt: ?string, generation: ?int, current: bool}>
     * }
     */
    public function read(): array
    {
        $location = $this->destination->location();
        if ($location === null) {
            return [
                'state' => self::STATE_NONE,
                'location' => null,
                'service' => null,
                'locationId' => 0,
                'copies' => [],
            ];
        }
        $service = $location->type->frenchLabel();
        $where = $service . ' — ' . $location->label;

        try {
            $backend = $this->destination->backend();
            $objects = $backend === null ? [] : $this->retention->listArchives($backend);
        } catch (\Throwable) {
            return [
                'state' => self::STATE_UNREACHABLE,
                'location' => $where,
                'service' => $service,
                'locationId' => $location->id,
                'copies' => [],
            ];
        }

        $current = max(1, $this->passphrase->generation());
        $copies = array_map(
            static function (StoredObject $object) use ($current): array {
                $generation = self::generationOf($object->key);

                return [
                    'name' => $object->key,
                    'size' => $object->sizeBytes,
                    'writtenAt' => self::writtenAtOf($object),
                    'generation' => $generation,
                    'current' => $generation === $current,
                ];
            },
            $objects
        );
        usort(
            $copies,
            static fn(array $a, array $b): int => strcmp((string) $b['writtenAt'], (string) $a['writtenAt'])
        );

        return [
            'state' => self::STATE_LISTED,
            'location' => $where,
            'service' => $service,
            'locationId' => $location->id,
            'copies' => $copies,
        ];
    }

    /**
     * The copy of that name in the listing, or null — the one way a name
     * coming from a form becomes a key read on the destination.
     *
     * @return array{name: string, size: int, writtenAt: ?string, generation: ?int, current: bool}|null
     */
    public function find(string $name): ?array
    {
        return self::pick($this->read(), $name);
    }

    /**
     * The copy of that name in a listing {@see read()} returned — for a
     * caller that needs the listing's location too, without listing twice.
     *
     * @param array{
     *     copies: list<array{name: string, size: int, writtenAt: ?string, generation: ?int, current: bool}>
     * } $listing
     * @return array{name: string, size: int, writtenAt: ?string, generation: ?int, current: bool}|null
     */
    public static function pick(array $listing, string $name): ?array
    {
        if (!RemoteRetention::isArchive($name)) {
            return null;
        }
        foreach ($listing['copies'] as $copy) {
            if ($copy['name'] === $name) {
                return $copy;
            }
        }

        return null;
    }

    /** The passphrase generation written in a copy's name, or null. */
    public static function generationOf(string $name): ?int
    {
        $pattern = '/-g(\d+)' . preg_quote(RemoteRetention::ARCHIVE_SUFFIX, '/') . '$/';

        return preg_match($pattern, $name, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * When it was written, `Y-m-d H:i:s` in the server's time zone: the
     * name first (UTC, as {@see RemoteRetention::nameFor()} writes it), the
     * destination's date otherwise.
     */
    private static function writtenAtOf(StoredObject $object): ?string
    {
        $named = '/^' . preg_quote(RemoteRetention::ARCHIVE_PREFIX, '/')
            . '(\d{4}-\d{2}-\d{2})-(\d{2})(\d{2})(\d{2})/';
        $instant = preg_match($named, $object->key, $m) === 1
            ? strtotime(sprintf('%sT%s:%s:%sZ', $m[1], $m[2], $m[3], $m[4]))
            : ($object->lastModifiedAt === null ? false : strtotime($object->lastModifiedAt));

        return $instant === false ? null : date('Y-m-d H:i:s', $instant);
    }
}
