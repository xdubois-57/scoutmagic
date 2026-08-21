<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Modules\SupportDashboard\Repository\SupportInstallationRepository;

/**
 * Everything the support dashboard shows: the filtered table, its counters,
 * the five indicator cards and the two current-state charts
 * (ARCHITECTURE.md §8.46).
 *
 * **Filtering, searching, sorting and paging all happen here, in PHP, over
 * the whole retained set** rather than in SQL. Three reasons, in order of
 * weight:
 *
 * 1. One of the required filters is "does this installation have module X
 *    enabled", and the module list lives inside the stored JSON payload.
 *    Expressing that in SQL means `JSON_CONTAINS`, which is MySQL-only and
 *    would leave the test suite unable to exercise the filter at all.
 * 2. The cards and both charts must be recomputed **on the filtered set**,
 *    not on the page. Doing the filter in SQL and the aggregates in PHP (or
 *    the reverse) is how a table and its own counters end up disagreeing.
 * 3. The population is scout units in one federation — dozens, plausibly a
 *    few hundred. Loading them all is a few hundred kilobytes.
 *
 * If that population ever grows by an order of magnitude, the fix is to
 * denormalise the module list into its own table and move the filter into
 * SQL — not to split the work between the two.
 *
 * One rule runs through every method here: **an absent value stays absent.**
 * It is never counted as zero, never rendered as "Non", never averaged in.
 */
class SupportDashboardService
{
    /**
     * Temporary until IT-10 introduces `support_active_threshold_days`.
     * Kept as a constant, not a literal, so the replacement is one edit.
     */
    public const ACTIVE_THRESHOLD_DAYS = 14;

    /** Technical keys for the auto-update filter — never displayed as-is. */
    public const AUTO_UPDATE_DISABLED = 'disabled';
    public const AUTO_UPDATE_ENABLED = 'enabled';

    /**
     * The one French string this service produces, and only ever as a chart
     * slice label: a chart cannot render an absent category as a gap, so
     * "unknown" has to become a named slice. Everywhere else an absent
     * value stays null and the template decides how to show it.
     */
    public const UNKNOWN_LABEL = 'Non renseigné';

    public function __construct(private SupportInstallationRepository $installations)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildView(SupportDashboardFilters $filters): array
    {
        $all = array_map([$this, 'hydrate'], $this->installations->findAll());

        $filtered = array_values(array_filter($all, fn(array $row): bool => $this->matches($row, $filters)));
        $filtered = $this->sort($filtered, $filters);

        $pageCount = max(1, (int) ceil(count($filtered) / SupportDashboardFilters::PER_PAGE));
        $page = min($filters->page, $pageCount);
        $pageRows = array_slice($filtered, ($page - 1) * SupportDashboardFilters::PER_PAGE, SupportDashboardFilters::PER_PAGE);

        return [
            'rows' => $pageRows,
            'total_count' => count($all),
            'filtered_count' => count($filtered),
            'page' => $page,
            'page_count' => $pageCount,
            'indicators' => $this->indicators($filtered),
            'charts' => $this->charts($filtered),
            'available' => $this->availableFilterValues($all),
            'active_threshold_days' => $this->activeThresholdDays(),
        ];
    }

    /**
     * One installation, fully expanded, for the detail dialog.
     *
     * @return array<string, mixed>|null
     */
    public function findDetail(int $id): ?array
    {
        $row = $this->installations->findById($id);
        if ($row === null) {
            return null;
        }

        $installation = $this->hydrate($row);
        $installation['raw_json'] = self::prettyJson((string) ($row['payload'] ?? ''));

        return $installation;
    }

    /**
     * Days of silence after which an installation counts as stale.
     * Overridden by a setting in a later iteration.
     */
    protected function activeThresholdDays(): int
    {
        return self::ACTIVE_THRESHOLD_DAYS;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        $lastReceivedAt = self::stringOrNull($row['last_received_at'] ?? null);
        $lastReceivedTimestamp = self::timestampOrNull($lastReceivedAt);

        $isActive = $lastReceivedTimestamp !== null
            && (time() - $lastReceivedTimestamp) < $this->activeThresholdDays() * 86400;

        return [
            'id' => (int) $row['id'],
            'installation_id' => (string) $row['installation_id'],
            'instance_url' => self::stringOrNull($row['instance_url'] ?? null),
            'scoutmagic_version' => self::stringOrNull($row['scoutmagic_version'] ?? null),
            'is_dev_build' => self::boolOrNull($row['is_dev_build'] ?? null),
            'active_members' => self::intOrNull($row['active_members'] ?? null),
            'active_sections' => self::intOrNull($row['active_sections'] ?? null),
            'installation_method' => self::stringOrNull($row['installation_method'] ?? null),
            'auto_update_enabled' => self::boolOrNull($row['auto_update_enabled'] ?? null),
            'auto_update_level' => self::stringOrNull($row['auto_update_level'] ?? null),
            'scout_year_label' => self::stringOrNull($row['scout_year_label'] ?? null),
            'installed_at' => self::stringOrNull($row['installed_at'] ?? null),
            'last_upgraded_at' => self::stringOrNull($row['last_upgraded_at'] ?? null),
            'first_seen_at' => self::stringOrNull($row['first_seen_at'] ?? null),
            'last_received_at' => $lastReceivedAt,
            'last_received_timestamp' => $lastReceivedTimestamp,
            'statistics_schema_version' => self::intOrNull($row['statistics_schema_version'] ?? null),
            'is_active' => $isActive,
            'auto_update_label' => self::autoUpdateLabelOf(self::autoUpdateKey([
                'auto_update_enabled' => self::boolOrNull($row['auto_update_enabled'] ?? null),
                'auto_update_level' => self::stringOrNull($row['auto_update_level'] ?? null),
            ])),
            'auto_update_key' => self::autoUpdateKey([
                'auto_update_enabled' => self::boolOrNull($row['auto_update_enabled'] ?? null),
                'auto_update_level' => self::stringOrNull($row['auto_update_level'] ?? null),
            ]),
            'modules' => self::modulesOf($payload),
            'payload' => $payload,
            // The version label used for grouping and display: a dev build
            // is its own bucket, since "1.0.33" and "dev-abc1234" are not
            // the same thing running.
            'version_label' => self::versionLabel($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matches(array $row, SupportDashboardFilters $filters): bool
    {
        if ($filters->status === SupportDashboardFilters::STATUS_ACTIVE && $row['is_active'] !== true) {
            return false;
        }
        if ($filters->status === SupportDashboardFilters::STATUS_STALE && $row['is_active'] !== false) {
            return false;
        }

        if ($filters->version !== null && $row['version_label'] !== $filters->version) {
            return false;
        }

        if ($filters->installationMethod !== null && $row['installation_method'] !== $filters->installationMethod) {
            return false;
        }

        if ($filters->autoUpdate !== null && $row['auto_update_key'] !== $filters->autoUpdate) {
            return false;
        }

        if ($filters->moduleId !== null) {
            $modules = $row['modules'];
            if (!array_key_exists($filters->moduleId, $modules)) {
                return false;
            }
            if ($filters->moduleEnabled !== null && $modules[$filters->moduleId]['enabled'] !== $filters->moduleEnabled) {
                return false;
            }
        }

        if ($filters->search !== '') {
            // Instance URL, installation id, version/build and scout-year
            // label. Deliberately NOT email: there is no email anywhere in
            // a report, so offering the search would promise a capability
            // that cannot exist.
            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['instance_url'],
                $row['installation_id'],
                $row['version_label'],
                $row['scout_year_label'],
            ], static fn(mixed $value): bool => is_string($value))));

            if (!str_contains($haystack, mb_strtolower($filters->search))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sort(array $rows, SupportDashboardFilters $filters): array
    {
        $key = match ($filters->sort) {
            SupportDashboardFilters::SORT_ACTIVE_MEMBERS => 'active_members',
            SupportDashboardFilters::SORT_VERSION => 'version_label',
            SupportDashboardFilters::SORT_INSTALLED_AT => 'installed_at',
            SupportDashboardFilters::SORT_INSTANCE_URL => 'instance_url',
            default => 'last_received_at',
        };

        usort($rows, static function (array $a, array $b) use ($key, $filters): int {
            $left = $a[$key];
            $right = $b[$key];

            // A missing value always sorts last, in both directions: it is
            // not "smallest", it is unknown, and burying it under a
            // descending sort would be the same lie as showing it as 0.
            if ($left === null && $right === null) {
                return strcmp((string) $a['installation_id'], (string) $b['installation_id']);
            }
            if ($left === null) {
                return 1;
            }
            if ($right === null) {
                return -1;
            }

            $comparison = is_int($left) && is_int($right)
                ? $left <=> $right
                : strcmp((string) $left, (string) $right);

            return $filters->descending ? -$comparison : $comparison;
        });

        return $rows;
    }

    /**
     * The five indicator cards, always over the filtered set.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function indicators(array $rows): array
    {
        $activeInstallations = 0;
        $totalMembers = null;
        $totalSections = null;
        $autoUpdateKnown = 0;
        $autoUpdateEnabled = 0;
        $versionCounts = [];

        foreach ($rows as $row) {
            if ($row['is_active'] === true) {
                $activeInstallations++;
            }
            if ($row['active_members'] !== null) {
                $totalMembers = ($totalMembers ?? 0) + $row['active_members'];
            }
            if ($row['active_sections'] !== null) {
                $totalSections = ($totalSections ?? 0) + $row['active_sections'];
            }
            if ($row['auto_update_enabled'] !== null) {
                $autoUpdateKnown++;
                if ($row['auto_update_enabled'] === true) {
                    $autoUpdateEnabled++;
                }
            }
            $version = $row['version_label'];
            if (is_string($version)) {
                $versionCounts[$version] = ($versionCounts[$version] ?? 0) + 1;
            }
        }

        arsort($versionCounts);
        $mostCommonVersion = $versionCounts === [] ? null : (string) array_key_first($versionCounts);

        return [
            'active_installations' => $activeInstallations,
            // null, not 0: "nobody told us" is not "there are none".
            'total_active_members' => $totalMembers,
            'total_active_sections' => $totalSections,
            'most_common_version' => $mostCommonVersion,
            'most_common_version_count' => $mostCommonVersion !== null ? $versionCounts[$mostCommonVersion] : null,
            'auto_update_percentage' => $autoUpdateKnown > 0
                ? (int) round($autoUpdateEnabled * 100 / $autoUpdateKnown)
                : null,
            'auto_update_known_count' => $autoUpdateKnown,
        ];
    }

    /**
     * Exactly two current-state charts: version/build distribution and
     * auto-update mode distribution. No third one is added here, however
     * available the data — every other breakdown the payload would allow is
     * explicitly out of scope.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array{label: string, count: int}>>
     */
    private function charts(array $rows): array
    {
        $versions = [];
        $autoUpdate = [];

        foreach ($rows as $row) {
            $version = is_string($row['version_label']) ? $row['version_label'] : self::UNKNOWN_LABEL;
            $versions[$version] = ($versions[$version] ?? 0) + 1;

            $label = self::autoUpdateLabelOf($row['auto_update_key']);
            $autoUpdate[$label] = ($autoUpdate[$label] ?? 0) + 1;
        }

        arsort($versions);
        arsort($autoUpdate);

        return [
            'versions' => self::asSeries($versions),
            'auto_update' => self::asSeries($autoUpdate),
        ];
    }

    /**
     * The distinct values actually present, so a filter never offers an
     * option that would return nothing.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, mixed>>
     */
    private function availableFilterValues(array $rows): array
    {
        $versions = [];
        $methods = [];
        $autoUpdate = [];
        $modules = [];

        foreach ($rows as $row) {
            if (is_string($row['version_label'])) {
                $versions[$row['version_label']] = true;
            }
            if (is_string($row['installation_method'])) {
                $methods[$row['installation_method']] = true;
            }
            $key = $row['auto_update_key'];
            if ($key !== null) {
                $autoUpdate[$key] = true;
            }
            foreach (array_keys($row['modules']) as $moduleId) {
                $modules[$moduleId] = true;
            }
        }

        $sorted = static function (array $keys): array {
            $values = array_keys($keys);
            sort($values);

            return array_map('strval', $values);
        };

        return [
            'versions' => $sorted($versions),
            'installation_methods' => $sorted($methods),
            'auto_update_levels' => array_map(
                static fn(string $key): array => ['key' => $key, 'label' => self::autoUpdateLabelOf($key)],
                $sorted($autoUpdate)
            ),
            'modules' => $sorted($modules),
        ];
    }

    /**
     * @param array<string, int> $counts
     * @return array<int, array{label: string, count: int}>
     */
    private static function asSeries(array $counts): array
    {
        $series = [];
        foreach ($counts as $label => $count) {
            $series[] = ['label' => (string) $label, 'count' => $count];
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array{enabled: bool, version: ?string}>
     */
    private static function modulesOf(array $payload): array
    {
        $modules = [];
        $declared = $payload['modules'] ?? null;
        if (!is_array($declared)) {
            return $modules;
        }

        foreach ($declared as $module) {
            if (!is_array($module) || !isset($module['id']) || !is_string($module['id'])) {
                continue;
            }
            $modules[$module['id']] = [
                'enabled' => ($module['enabled'] ?? null) === true,
                'version' => isset($module['version']) && is_string($module['version']) ? $module['version'] : null,
            ];
        }

        ksort($modules);

        return $modules;
    }

    /**
     * The auto-update bucket an installation falls into, as a **technical**
     * key — never the French label. The key is what travels in the query
     * string and what the filter compares against; the label is produced
     * once, at the edge, by autoUpdateLabelOf(). Filtering on the displayed
     * French text would make the URL a translation artefact.
     *
     * @param array<string, mixed> $row
     */
    private static function autoUpdateKey(array $row): ?string
    {
        if ($row['auto_update_enabled'] === null) {
            return null;
        }
        if ($row['auto_update_enabled'] === false) {
            return self::AUTO_UPDATE_DISABLED;
        }

        return $row['auto_update_level'] ?? self::AUTO_UPDATE_ENABLED;
    }

    /**
     * French label for an auto-update key. The three update levels are
     * ScoutMagic's own vocabulary (`auto_update_level`), already shown
     * untranslated on Configuration > Mise à jour, so they stay as they are.
     */
    public static function autoUpdateLabelOf(?string $key): string
    {
        return match ($key) {
            null => self::UNKNOWN_LABEL,
            self::AUTO_UPDATE_DISABLED => 'Désactivées',
            self::AUTO_UPDATE_ENABLED => 'Activées',
            default => $key,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function versionLabel(array $row): ?string
    {
        $version = self::stringOrNull($row['scoutmagic_version'] ?? null);

        return $version;
    }

    private static function prettyJson(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $raw;
        }

        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return $value === null || $value === '' ? null : (bool) $value;
    }

    private static function timestampOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
