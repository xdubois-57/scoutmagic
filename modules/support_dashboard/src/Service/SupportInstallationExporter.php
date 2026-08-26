<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Export\TabularSpreadsheet;

/**
 * The dashboard's XLSX export (ARCHITECTURE.md §8.50).
 *
 * **XLSX, never CSV**, and one row per installation in the **currently
 * filtered set** — not the page on screen. Exporting the visible page would
 * turn a pagination accident into a silently truncated deliverable, which
 * is the failure mode nobody notices until the numbers are already in a
 * report.
 *
 * **One column per defined metric**, not per visible column: the responsive
 * table hides most metrics below `xxl`, and a spreadsheet has no such
 * constraint. Three things are deliberately absent:
 *
 * - the raw JSON payload — a spreadsheet cell is the wrong home for it, and
 *   it is exactly where an unknown field from a newer sender would smuggle
 *   in something nobody vetted for this format;
 * - any contact email — there is none in a report, and a column named for
 *   one would imply otherwise;
 * - a derived "days since last report" — the reception timestamp and the
 *   status are both here, and a stored derivation is stale the moment the
 *   file is saved.
 *
 * A missing value is written as the same "Non renseigné" the page shows,
 * consistently, in every column. Never `0`, never `Non`: a reader sorting
 * on a column must not be handed a zero that was really a silence.
 */
final class SupportInstallationExporter
{
    public const MISSING = 'Non renseigné';

    /**
     * @return array<int, string>
     */
    public static function headers(): array
    {
        return [
            "Identifiant d'installation",
            "URL de l'instance",
            'Statut',
            'Dernière réception',
            'Première réception',
            'Version / build ScoutMagic',
            'Build de développement',
            'Membres actifs',
            'Sections actives',
            'Année scoute',
            "Méthode d'installation",
            'Mises à jour automatiques',
            'Niveau de mise à jour automatique',
            "Date d'installation",
            'Dernière mise à jour ScoutMagic',
            'Version du schéma de statistiques',
            'Modules activés',
            'Modules désactivés',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows the filtered set, as
     *        hydrated by SupportDashboardService
     */
    public static function build(array $rows): string
    {
        $sheetRows = [];
        foreach ($rows as $row) {
            $sheetRows[] = [
                self::text($row['installation_id'] ?? null),
                self::text($row['instance_url'] ?? null),
                ($row['is_active'] ?? null) === true ? 'Active' : 'Obsolète',
                self::text($row['last_received_at'] ?? null),
                self::text($row['first_seen_at'] ?? null),
                self::text($row['version_label'] ?? null),
                self::yesNo($row['is_dev_build'] ?? null),
                self::text($row['active_members'] ?? null),
                self::text($row['active_sections'] ?? null),
                self::text($row['scout_year_label'] ?? null),
                self::text($row['installation_method'] ?? null),
                self::yesNo($row['auto_update_enabled'] ?? null),
                self::text($row['auto_update_level'] ?? null),
                self::text($row['installed_at'] ?? null),
                self::text($row['last_upgraded_at'] ?? null),
                self::text($row['statistics_schema_version'] ?? null),
                self::moduleList($row, true),
                self::moduleList($row, false),
            ];
        }

        return TabularSpreadsheet::build(self::headers(), $sheetRows, 'Installations');
    }

    private static function text(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::MISSING;
        }

        return (string) $value;
    }

    /**
     * A tri-state rendered as three distinct strings. Collapsing the unknown
     * case onto "Non" is the single most tempting mistake in this whole
     * export, and the one that would quietly misreport a fleet.
     */
    private static function yesNo(mixed $value): string
    {
        return match ($value) {
            true => 'Oui',
            false => 'Non',
            default => self::MISSING,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function moduleList(array $row, bool $enabled): string
    {
        $modules = $row['modules'] ?? [];
        if (!is_array($modules) || $modules === []) {
            return self::MISSING;
        }

        $matching = [];
        foreach ($modules as $id => $module) {
            if (is_array($module) && ($module['enabled'] ?? null) === $enabled) {
                $matching[] = (string) $id;
            }
        }

        return $matching === [] ? '' : implode(', ', $matching);
    }
}
