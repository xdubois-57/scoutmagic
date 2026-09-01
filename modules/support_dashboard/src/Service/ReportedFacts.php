<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * The handful of reported values a maintainer reads while answering a
 * ticket, lifted out of a usage payload.
 *
 * **It exists because reading the payload by hand went wrong once.** The
 * ticket detail page used to pick `scoutmagic_version`, `php_version` and
 * `active_members` off the top level of the document — none of which is
 * where they live (`scoutmagic.version`, `runtime.php_version`,
 * `usage.active_members`) — so every one of them rendered « Non
 * renseigné » on a payload that carried them all. One reader, used by both
 * the installation row and the ticket's own frozen snapshot, is what keeps
 * the two columns of §8.49quinquies speaking the same vocabulary.
 *
 * Nothing here is trusted: the payload comes from another installation, so
 * a nested array where a string was expected becomes null rather than
 * something a template has to render.
 */
final class ReportedFacts
{
    /**
     * The compared fields, in the order they are read. Deliberately not
     * every field of the payload: a forty-row table nobody scans is a
     * table that hides the two lines that moved.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'scoutmagic_version' => 'Version du site',
        'php_version' => 'PHP',
        'database_version' => 'Base de données',
        'active_members' => 'Membres actifs',
        'active_sections' => 'Sections actives',
        'installation_method' => "Méthode d'installation",
        'auto_update_level' => 'Niveau de mise à jour',
    ];

    /**
     * @param array<string, mixed> $payload the raw usage report
     * @return array<string, string|int|null> keyed by LABELS' keys
     */
    public static function fromPayload(array $payload): array
    {
        $database = self::scalar($payload, ['database', 'engine']);
        $databaseVersion = self::scalar($payload, ['database', 'version']);

        return [
            'scoutmagic_version' => self::scalar($payload, ['scoutmagic', 'version']),
            'php_version' => self::scalar($payload, ['runtime', 'php_version']),
            // « MariaDB 10.11 » rather than two columns nobody reads apart.
            'database_version' => $database !== null && $databaseVersion !== null
                ? $database . ' ' . $databaseVersion
                : ($database ?? $databaseVersion),
            // Counts must be counts. A payload is another installation's
            // document, and « Membres actifs : beaucoup » on a support
            // page reads as a broken page rather than as a stranger's
            // bad value — which is what it would be.
            'active_members' => self::count($payload, ['usage', 'active_members']),
            'active_sections' => self::count($payload, ['usage', 'active_sections']),
            'installation_method' => self::scalar($payload, ['installation', 'method']),
            'auto_update_level' => self::scalar($payload, ['updates', 'auto_update_level']),
        ];
    }

    /**
     * One value, only when it really is a whole number.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $path
     */
    private static function count(array $payload, array $path): ?int
    {
        $value = self::at($payload, $path);

        return is_int($value) ? $value : null;
    }

    /**
     * One value, only when it is a scalar a page can print.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $path
     */
    private static function scalar(array $payload, array $path): string|int|null
    {
        $value = self::at($payload, $path);

        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Walks a dotted path, answering null the moment it leaves the map.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $path
     */
    private static function at(array $payload, array $path): mixed
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
