<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * Reads the three reference documents into value objects — kept apart from
 * {@see HttpReferenceSource} so every shape, every missing field and every
 * edge of a score is testable on a synthetic document, without a network.
 *
 * A document whose overall shape is wrong throws
 * {@see ReferenceSourceUnavailable}: a changed format must read as « source
 * indisponible », never as « no advisory ». A single malformed entry inside
 * a well-formed document is skipped.
 */
final class ReferenceParsers
{
    /** php.net states in which a branch still receives security fixes. */
    private const PHP_SUPPORTED_STATES = ['stable', 'security'];

    /**
     * `https://www.php.net/releases/branches.php`: a list of
     * `{branch, latest, state, security_support_end}`.
     *
     * @return list<ReleaseBranch>
     */
    public static function phpBranches(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            throw new ReferenceSourceUnavailable('php.net: unexpected branches document');
        }

        $branches = [];
        foreach ($data as $entry) {
            if (!is_array($entry) || !is_string($entry['branch'] ?? null) || $entry['branch'] === '') {
                continue;
            }
            $state = is_string($entry['state'] ?? null) ? $entry['state'] : null;
            $branches[] = new ReleaseBranch(
                $entry['branch'],
                is_string($entry['latest'] ?? null) && $entry['latest'] !== '' ? $entry['latest'] : null,
                match (true) {
                    $state === 'eol' => false,
                    in_array($state, self::PHP_SUPPORTED_STATES, true) => true,
                    default => null,
                },
                self::date($entry['security_support_end'] ?? null),
                'https://www.php.net/supported-versions.php'
            );
        }

        if ($branches === []) {
            throw new ReferenceSourceUnavailable('php.net: no branch in the document');
        }

        return $branches;
    }

    /**
     * `https://endoflife.date/api/v1/products/{product}/`: `result.releases`,
     * each `{name, isEol, eolFrom, latest: {name, link}}`.
     *
     * @return list<ReleaseBranch>
     */
    public static function endOfLifeBranches(string $json): array
    {
        $data = json_decode($json, true);
        $releases = is_array($data) && is_array($data['result'] ?? null) ? ($data['result']['releases'] ?? null) : null;
        if (!is_array($releases) || !array_is_list($releases)) {
            throw new ReferenceSourceUnavailable('endoflife.date: unexpected product document');
        }

        $branches = [];
        foreach ($releases as $release) {
            if (!is_array($release) || !is_string($release['name'] ?? null) || $release['name'] === '') {
                continue;
            }
            $latest = is_array($release['latest'] ?? null) ? $release['latest'] : [];
            $branches[] = new ReleaseBranch(
                $release['name'],
                is_string($latest['name'] ?? null) && $latest['name'] !== '' ? $latest['name'] : null,
                is_bool($release['isEol'] ?? null) ? !$release['isEol'] : null,
                self::date($release['eolFrom'] ?? null),
                is_string($latest['link'] ?? null) && str_starts_with($latest['link'], 'https://') ? $latest['link'] : null
            );
        }

        if ($branches === []) {
            throw new ReferenceSourceUnavailable('endoflife.date: no release in the document');
        }

        return $branches;
    }

    /**
     * NVD CVE API 2.0: `vulnerabilities[].cve` with its `metrics`.
     * Rejected records are dropped; an empty list is a valid answer.
     *
     * @return list<Advisory>
     */
    public static function nvdAdvisories(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['vulnerabilities'] ?? null)) {
            throw new ReferenceSourceUnavailable('NVD: unexpected CVE document');
        }

        $advisories = [];
        foreach ($data['vulnerabilities'] as $entry) {
            $cve = is_array($entry) && is_array($entry['cve'] ?? null) ? $entry['cve'] : null;
            if ($cve === null || !is_string($cve['id'] ?? null) || preg_match('/^CVE-\d{4}-\d+$/', $cve['id']) !== 1) {
                continue;
            }
            if (($cve['vulnStatus'] ?? null) === 'Rejected') {
                continue;
            }

            $metric = self::preferredMetric(is_array($cve['metrics'] ?? null) ? $cve['metrics'] : []);
            $advisories[] = new Advisory(
                $cve['id'],
                $metric['score'] ?? null,
                $metric['version'] ?? null,
                $metric['source'] ?? null,
                'https://nvd.nist.gov/vuln/detail/' . $cve['id']
            );
        }

        return Advisory::deduplicated($advisories);
    }

    /**
     * The one score an advisory is judged on: the NVD's own analysis
     * (`type: Primary`) before a CNA's (`Secondary`), and within each the
     * newest CVSS version. The version and the scorer travel with it.
     *
     * @param array<string, mixed> $metrics
     * @return array{score: float, version: ?string, source: ?string}|null
     */
    private static function preferredMetric(array $metrics): ?array
    {
        $families = ['cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'];
        foreach (['Primary', 'Secondary'] as $type) {
            foreach ($families as $family) {
                foreach (is_array($metrics[$family] ?? null) ? $metrics[$family] : [] as $metric) {
                    if (!is_array($metric) || ($metric['type'] ?? null) !== $type) {
                        continue;
                    }
                    $cvss = is_array($metric['cvssData'] ?? null) ? $metric['cvssData'] : [];
                    $score = $cvss['baseScore'] ?? null;
                    if (!is_int($score) && !is_float($score)) {
                        continue;
                    }
                    if ($score < 0 || $score > 10) {
                        continue;
                    }

                    return [
                        'score' => (float) $score,
                        'version' => is_string($cvss['version'] ?? null) ? $cvss['version'] : null,
                        'source' => is_string($metric['source'] ?? null) ? $metric['source'] : null,
                    ];
                }
            }
        }

        return null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
