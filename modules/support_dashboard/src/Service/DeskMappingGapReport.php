<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Import\AgeBranchRepository;
use Core\Import\DeskMappingGapKind;
use Core\Service\TextNormalizerService;
use Modules\SupportDashboard\Repository\DeskMappingGapRepository;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;

/**
 * What every reporting installation is currently failing to match, read
 * off the reports this receiver already holds (issue #356).
 *
 * ## Nothing is catalogued (D5)
 *
 * The values this software knows are IN THE CODE, and the receiver runs
 * the same software: it recognises a branch by calling
 * {@see AgeBranchRepository::canonicalSortOrder()} itself, and asks the
 * cotisations module about a tariff through the capability that module
 * publishes. So a value a later version learns disappears from this page
 * the day the receiver is upgraded, with nothing to delete — and a
 * catalogue of « known values » is exactly the table that would have to be
 * kept in step with the code for ever.
 *
 * Two kinds are never filtered, for opposite reasons:
 *
 * - **A function has no table at all.** `MappingResolver::resolveFunction()`
 *   creates every unseen function at the lowest role; there is no needle
 *   list a release could extend, so no version can make a function
 *   « recognised ». Only the unit qualifying it resolves one, and the line
 *   here is a federation-wide signal — « seven units carry a function
 *   nobody has qualified » — rather than a table to complete.
 * - **A CSV header never travels.** The sender journals it (the import
 *   stopped, there is nothing left to observe afterwards) and leaves it out
 *   of the payload, so the kind exists in the enum and not in this list.
 *
 * ## The oldest version is not decoration
 *
 * A value corrected in the code keeps being reported by every installation
 * that has not upgraded. Without that column the same value is picked up
 * again next month by somebody who thinks it was forgotten.
 */
class DeskMappingGapReport
{
    public function __construct(
        private SupportInstallationRepository $installations,
        private DeskMappingGapRepository $gaps
    ) {
    }

    /**
     * @return list<DeskMappingGapRow>
     */
    public function rows(bool $includeIgnored = false): array
    {
        $stored = $this->gaps->findAllKeyed();
        $aggregated = [];

        foreach ($this->installations->findAll() as $installation) {
            $payload = json_decode((string) ($installation['payload'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }

            foreach (self::unresolvedIn($payload) as [$kind, $value]) {
                if ($this->thisVersionRecognises($kind, $value)) {
                    continue;
                }

                $key = $kind . DeskMappingGapRepository::KEY_SEPARATOR . TextNormalizerService::fold($value);
                $aggregated[$key] ??= [
                    'kind' => $kind,
                    'value_raw' => $value,
                    'instances' => [],
                    'count' => 0,
                    'last_seen' => null,
                    'oldest_version' => null,
                ];

                $host = self::hostOf($installation['instance_url'] ?? null);
                if ($host !== null && !in_array($host, $aggregated[$key]['instances'], true)) {
                    $aggregated[$key]['instances'][] = $host;
                }

                $aggregated[$key]['count']++;
                $aggregated[$key]['last_seen'] = self::later(
                    $aggregated[$key]['last_seen'],
                    isset($installation['last_received_at']) ? (string) $installation['last_received_at'] : null
                );
                $aggregated[$key]['oldest_version'] = self::older(
                    $aggregated[$key]['oldest_version'],
                    isset($installation['scoutmagic_version']) ? (string) $installation['scoutmagic_version'] : null
                );
            }
        }

        $rows = [];
        foreach ($aggregated as $key => $entry) {
            $row = $stored[$key] ?? null;
            $ignored = $row !== null && $row['ignored_at'] !== null;
            if ($ignored && !$includeIgnored) {
                continue;
            }

            // Sorted so the line reads the same on two consecutive loads:
            // `findAll()` orders installations by when they last reported,
            // which reshuffles under the page every time somebody sends a
            // report.
            sort($entry['instances']);

            $rows[] = new DeskMappingGapRow(
                id: $row['id'] ?? null,
                kind: $entry['kind'],
                // The FIRST spelling this receiver saw wins over the one in
                // today's report: two units spelling it differently must
                // not make the line change wording between two page loads.
                valueRaw: $row['value_raw'] ?? $entry['value_raw'],
                installations: $entry['count'],
                instances: $entry['instances'],
                firstSeenAt: $row['first_seen_at'] ?? null,
                lastSeenAt: $entry['last_seen'],
                oldestVersion: $entry['oldest_version'],
                ignored: $ignored
            );
        }

        usort($rows, static function (DeskMappingGapRow $a, DeskMappingGapRow $b): int {
            return [$b->installations, $a->valueRaw] <=> [$a->installations, $b->valueRaw];
        });

        return $rows;
    }

    /**
     * Every (kind, value) pair a payload declares unresolved, ignoring
     * anything malformed: this document was written by another
     * installation, and one bad entry must not cost the page every other
     * one.
     *
     * @param array<string, mixed> $payload
     * @return list<array{0: string, 1: string}>
     */
    public static function unresolvedIn(array $payload): array
    {
        $listed = $payload['desk_unresolved']['listed'] ?? null;
        if (!is_array($listed)) {
            return [];
        }

        $pairs = [];
        foreach ($listed as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $kind = $entry['kind'] ?? null;
            $value = $entry['value'] ?? null;
            if (!is_string($kind) || !is_string($value) || trim($value) === '') {
                continue;
            }
            if (DeskMappingGapKind::tryFrom($kind) === null) {
                continue;
            }

            $pairs[] = [$kind, mb_substr(trim($value), 0, 190)];
        }

        return $pairs;
    }

    /**
     * Whether THIS installation's code — the receiver's own, one or more
     * versions ahead of the sender's — already knows the value.
     */
    private function thisVersionRecognises(string $kind, string $value): bool
    {
        return self::recognisedByThisVersion($kind, $value);
    }

    /**
     * The same question, asked by the intake before it records a value at
     * all ({@see DeskMappingGapRecorder}). One implementation, because two
     * would eventually disagree — and the disagreement would show up as a
     * notification for a value the page does not list.
     */
    public static function recognisedByThisVersion(string $kind, string $value): bool
    {
        return match (DeskMappingGapKind::tryFrom($kind)) {
            DeskMappingGapKind::BRANCH =>
                AgeBranchRepository::canonicalSortOrder($value) !== AgeBranchRepository::UNKNOWN_SORT_ORDER,
            // See the class docblock: a function has no table to complete,
            // and a CSV header never reaches a payload.
            default => false,
        };
    }

    private static function hostOf(mixed $instanceUrl): ?string
    {
        if (!is_string($instanceUrl) || $instanceUrl === '') {
            return null;
        }

        $host = parse_url($instanceUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private static function later(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate > $current ? $candidate : $current;
    }

    private static function older(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null || $candidate === '') {
            return $current;
        }

        return $current === null || version_compare($candidate, $current, '<') ? $candidate : $current;
    }
}
