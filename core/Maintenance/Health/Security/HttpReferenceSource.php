<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

use Core\Http\StreamResponseHeaders;

/**
 * The reference sources over HTTPS — the same `file_get_contents()` and
 * stream context as {@see \Core\Maintenance\GitHubReleaseClient}, no
 * dependency.
 *
 * Fixed, public hosts and no user input: nothing here is a URL somebody
 * typed, so SECURITY.md §17's SSRF guard does not apply. What leaves the
 * server is the server's own address and the versions it asks about (they
 * are in the NVD query) — no personal data, as the RGPD page says (§1.7).
 *
 * Every request is bounded: {@see TIMEOUT_SECONDS} each, and a body cap
 * so an unexpected answer cannot fill the memory of a cron pass that holds
 * the scheduler's lock. A run makes at most seven requests (php.net, two
 * endoflife.date products, four NVD lookups), so a pass where every source
 * hangs still ends within about seventy seconds.
 */
final class HttpReferenceSource implements ReferenceSource
{
    public const TIMEOUT_SECONDS = 10;
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const USER_AGENT = 'ScoutMagic-HostSecurityCheck';

    public const PHP_BRANCHES_URL = 'https://www.php.net/releases/branches.php';
    public const ENDOFLIFE_URL = 'https://endoflife.date/api/v1/products/';
    public const NVD_URL = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    public function phpBranches(): array
    {
        return ReferenceParsers::phpBranches($this->get(self::PHP_BRANCHES_URL));
    }

    public function endOfLifeBranches(string $product): array
    {
        if (preg_match('/^[a-z0-9-]+$/', $product) !== 1) {
            throw new ReferenceSourceUnavailable('endoflife.date: invalid product slug');
        }

        return ReferenceParsers::endOfLifeBranches($this->get(self::ENDOFLIFE_URL . $product . '/'));
    }

    /**
     * `isVulnerable` makes the NVD keep only the CVEs whose configuration
     * marks this exact CPE vulnerable — the version-range matching is the
     * NVD's own, not re-implemented here.
     */
    public function advisories(string $cpeName): array
    {
        return ReferenceParsers::nvdAdvisories($this->get(
            self::NVD_URL . '?cpeName=' . rawurlencode($cpeName) . '&isVulnerable&noRejected&resultsPerPage=2000'
        ));
    }

    private function get(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: application/json\r\n",
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        StreamResponseHeaders::clear();
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        if ($body === false) {
            throw new ReferenceSourceUnavailable('unreachable reference source');
        }
        if (strlen($body) >= self::MAX_BYTES) {
            throw new ReferenceSourceUnavailable('reference document too large');
        }

        $status = 0;
        foreach (StreamResponseHeaders::last() as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new ReferenceSourceUnavailable('reference source answered HTTP ' . $status);
        }

        return $body;
    }
}
