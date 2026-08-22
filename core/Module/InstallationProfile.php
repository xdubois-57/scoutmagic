<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\Statistics\DestinationMatcher;

/**
 * The set of named flags that are true for THIS installation
 * (ARCHITECTURE.md §8.49).
 *
 * A module's manifest declares `visible_when` as a list of these flag
 * names; ModuleManager asks this object whether any of them holds and
 * filters the module out of discoverModules() when none does. The object
 * is immutable and pure — no PDO, no SettingService — so the composition
 * root can build it before any service is wired, exactly like
 * Core\Statistics\DestinationMatcher, which it delegates every host
 * comparison to.
 *
 * Every flag is decided from the configured `base_url` setting, never from
 * $_SERVER['HTTP_HOST']: the Host header is attacker-supplied on every
 * request, so deriving a flag from it would let a crafted request talk an
 * ordinary installation into behaving like the reference installation.
 */
final class InstallationProfile
{
    /** This installation is the one usage statistics are reported to. */
    public const FLAG_STATISTICS_RECEIVER = 'statistics_receiver';

    /** This installation is the project's own reference installation. */
    public const FLAG_REFERENCE_INSTALLATION = 'reference_installation';

    /** This installation is a developer's local/test installation. */
    public const FLAG_LOCAL_INSTALLATION = 'local_installation';

    /**
     * The complete list of flag names, and the only one: ModuleManifest
     * validates `visible_when` against it, so a flag added here is
     * immediately a legal manifest value and a typo anywhere else is a
     * load-time error rather than a module that silently does not exist.
     *
     * @var array<int, string>
     */
    public const KNOWN_FLAGS = [
        self::FLAG_STATISTICS_RECEIVER,
        self::FLAG_REFERENCE_INSTALLATION,
        self::FLAG_LOCAL_INSTALLATION,
    ];

    /**
     * The project's reference installation, hardcoded on purpose.
     *
     * A setting an admin can edit would make "am I the reference
     * installation?" answerable by anyone who can reach Configuration >
     * Paramètres. There is precedent: bootstrap/bootstrap.php hardcodes the
     * GitHub repository the updater pulls from, for the same reason.
     */
    public const REFERENCE_HOST = 'scoutmagic.be';

    /**
     * Hosts that are a local installation whatever their shape.
     *
     * @var array<int, string>
     */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * Last DNS labels that mark a local installation ("unite.test",
     * "scoutmagic.local"). Compared as a whole label, never as a substring:
     * "mylocal.be" and "nonlocalhost.be" are real domains.
     *
     * @var array<int, string>
     */
    private const LOCAL_SUFFIX_LABELS = ['test', 'local', 'localhost'];

    /** @var array<int, string> */
    private array $flags;

    /**
     * @param array<int, string> $flags flag names that hold for this installation
     */
    public function __construct(array $flags = [])
    {
        $this->flags = array_values(array_unique(array_filter(
            $flags,
            static fn(string $flag): bool => in_array($flag, self::KNOWN_FLAGS, true)
        )));
    }

    /**
     * Build the profile from the two raw setting values.
     *
     * The single resolver every entry point calls (public/index.php,
     * public/cron.php, scripts/e2e-support.php), so the three can never
     * drift into answering the same question differently.
     */
    public static function resolve(?string $baseUrl, ?string $statisticsDestination): self
    {
        $flags = [];

        if (DestinationMatcher::isReceiver($baseUrl, $statisticsDestination)) {
            $flags[] = self::FLAG_STATISTICS_RECEIVER;
        }

        if ($baseUrl !== null && DestinationMatcher::isSameHost($baseUrl, self::REFERENCE_HOST)) {
            $flags[] = self::FLAG_REFERENCE_INSTALLATION;
        }

        if ($baseUrl !== null && self::isLocalHost($baseUrl)) {
            $flags[] = self::FLAG_LOCAL_INSTALLATION;
        }

        return new self($flags);
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Whether any of the given flags holds — the OR semantics of a
     * manifest's `visible_when`. An empty list means "no condition", which
     * every caller reads as "always visible".
     *
     * @param array<int, string> $flags
     */
    public function hasAny(array $flags): bool
    {
        foreach ($flags as $flag) {
            if ($this->has($flag)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function all(): array
    {
        return $this->flags;
    }

    /**
     * Whether a URL's host is a local development host.
     *
     * An empty or unparseable base_url has no host, so it is not local —
     * "unknown" must never read as "match", the same rule
     * DestinationMatcher::isSameHost() already applies.
     */
    private static function isLocalHost(string $baseUrl): bool
    {
        $host = self::normalizedHost($baseUrl);
        if ($host === null) {
            return false;
        }

        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return true;
        }

        $labels = explode('.', $host);
        $lastLabel = $labels[count($labels) - 1];

        return in_array($lastLabel, self::LOCAL_SUFFIX_LABELS, true);
    }

    /**
     * The comparable host of a URL, or null when there is none.
     *
     * DestinationMatcher's own normalization is private and stays that way —
     * it is a comparator, and it answers "same host?", not "which host?".
     * The reference-installation flag goes through it; only the local-host
     * test needs the host itself, and it applies the same rules (trim,
     * lowercase, trailing dot, bracketed IPv6, a missing scheme) so the two
     * can never disagree about what a base_url's host is.
     *
     * `www.` is deliberately NOT stripped here: it changes nothing for a
     * loopback address or a `.test` suffix, and keeping the rule in one
     * place (the comparator) is worth more than mirroring it.
     */
    private static function normalizedHost(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ((!is_string($host) || $host === '') && !str_contains($url, '://')) {
            // No scheme: parse_url() reports the whole thing as a path.
            $host = parse_url('https://' . $url, PHP_URL_HOST);
        }
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = trim(strtolower(rtrim($host, '.')), '[]');

        return $host !== '' ? $host : null;
    }
}
