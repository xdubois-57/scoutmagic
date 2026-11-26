<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * One family of host components the security check knows how to compare,
 * and where its reference data comes from (issue #1358).
 *
 * **This table is the whole of what the check knows about a product.**
 * No CVE, no version number and no product name appears in a condition
 * anywhere else: a new advisory for a family listed here is picked up from
 * the NVD on the next weekly run, a new patch release from php.net or
 * endoflife.date, and adding a family is one line here plus, where its
 * version string needs it, an ignored token.
 *
 * The sources, decided by the maintainer on 2026-11-21:
 *
 * - **php.net** (`releases/branches.php`) for PHP: every branch with its
 *   latest release and its support state, published by the project itself;
 * - **endoflife.date** (API v1) for the end of support and the latest patch
 *   of MariaDB, MySQL and OpenSSL. It has no cURL entry: cURL publishes a
 *   single line of releases, so its « mise à jour » half stays unknown and
 *   only its advisories are compared;
 * - **NVD API 2.0** for the advisories, by CPE name with `isVulnerable`:
 *   the NVD itself matches the observed version against each CVE's affected
 *   ranges, so no range is re-implemented here.
 *
 * `backportsVisible` says whether a distribution that patches the product
 * in place says so in the version string. PHP (`PHP_VERSION` carries
 * « -1ubuntu1.2 ») and the database servers (« -0+deb12u1 ») do; the
 * OpenSSL and libcurl a distribution ships report the upstream number they
 * started from, however many fixes were applied since. For those, an
 * upstream comparison is never presented as proof — every « en retard » and
 * every advisory is qualified « à confirmer auprès de l'hébergeur »
 * (https://access.redhat.com/security/updates/backporting).
 */
final class ComponentFamily
{
    public const PHP = 'php';
    public const MARIADB = 'mariadb';
    public const MYSQL = 'mysql';
    public const OPENSSL = 'openssl';
    public const CURL = 'curl';

    /** Where the branches of PHP come from. */
    public const SOURCE_PHP_NET = 'php.net';
    /** Where the branches of the other families come from. */
    public const SOURCE_ENDOFLIFE = 'endoflife.date';

    /**
     * @param string|null $versionSource {@see SOURCE_PHP_NET}, {@see SOURCE_ENDOFLIFE}, or null when no
     *                                   source publishes this family's branches
     * @param string|null $endOfLifeProduct the endoflife.date product slug
     * @param string $cpeProduct `vendor:product` of the NVD CPE name
     * @param list<string> $ignoredTokens words a server appends to its version that are neither a
     *                                    pre-release nor a build (« -MariaDB », « -log »)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $versionSource,
        public readonly ?string $endOfLifeProduct,
        public readonly string $cpeProduct,
        public readonly bool $backportsVisible,
        public readonly array $ignoredTokens = [],
    ) {
    }

    /** @return array<string, self> */
    public static function all(): array
    {
        return [
            self::PHP => new self(self::PHP, 'PHP', self::SOURCE_PHP_NET, null, 'php:php', true),
            self::MARIADB => new self(
                self::MARIADB,
                'MariaDB',
                self::SOURCE_ENDOFLIFE,
                'mariadb',
                'mariadb:mariadb',
                true,
                ['mariadb', 'log', 'debug']
            ),
            self::MYSQL => new self(
                self::MYSQL,
                'MySQL',
                self::SOURCE_ENDOFLIFE,
                'mysql',
                'oracle:mysql',
                true,
                ['log', 'debug']
            ),
            self::OPENSSL => new self(self::OPENSSL, 'OpenSSL', self::SOURCE_ENDOFLIFE, 'openssl', 'openssl:openssl', false),
            self::CURL => new self(self::CURL, 'cURL (libcurl)', null, null, 'haxx:curl', false),
        ];
    }

    public static function get(string $id): ?self
    {
        return self::all()[$id] ?? null;
    }

    /** The key a family's branch list is cached and looked up under. */
    public function branchSourceKey(): ?string
    {
        return match ($this->versionSource) {
            self::SOURCE_PHP_NET => 'php.net',
            self::SOURCE_ENDOFLIFE => 'endoflife:' . $this->endOfLifeProduct,
            default => null,
        };
    }

    /** The page a reader can open to check the branch data by hand. */
    public function versionReferenceUrl(): ?string
    {
        return match ($this->versionSource) {
            self::SOURCE_PHP_NET => 'https://www.php.net/supported-versions.php',
            self::SOURCE_ENDOFLIFE => 'https://endoflife.date/' . $this->endOfLifeProduct,
            default => null,
        };
    }

    /** The NVD CPE 2.3 name of one upstream version of this family. */
    public function cpeName(VersionNumber $version): string
    {
        return 'cpe:2.3:a:' . $this->cpeProduct . ':' . $version->upstream() . ':*:*:*:*:*:*:*';
    }
}
