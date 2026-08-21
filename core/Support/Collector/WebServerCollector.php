<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `webserver/` — every `.htaccess` in the installation, plus whatever
 * server configuration happens to be readable.
 *
 * The `.htaccess` files are the point. They are the only web-server
 * configuration always present and always readable on the FTP/shared
 * hosting this project targets, and they are exactly what breaks in
 * Layout B (§9.1): a rewrite that routes PHP across a directory boundary,
 * a `DirectoryIndex` the host overrides, a deny rule swallowing the
 * `/config/...` admin routes. Reading them is usually the fastest way to
 * explain a site that answers 403 or 404 to everything.
 *
 * The server-config candidates are strictly best effort and there is
 * **no platform-aware Apache/Nginx discovery** (D-07): on chrooted shared
 * hosting every such path is unreadable in principle, so a discovery
 * mechanism would be elaborate machinery that reports "unavailable"
 * forever. A short candidate list costs nothing and occasionally works.
 */
class WebServerCollector implements SupportCollectorInterface
{
    private const MAX_HTACCESS_DEPTH = 6;

    /** @var array<int, string> */
    private const CANDIDATE_CONFIG_PATHS = [
        '/etc/apache2/apache2.conf',
        '/etc/apache2/httpd.conf',
        '/etc/httpd/conf/httpd.conf',
        '/etc/nginx/nginx.conf',
        '/usr/local/apache2/conf/httpd.conf',
    ];

    /** @var array<int, string> */
    private array $candidateConfigPaths;

    /**
     * @param array<int, string>|null $candidateConfigPaths Overrides
     *     self::CANDIDATE_CONFIG_PATHS — test-only. These are real,
     *     absolute host paths outside any project root, so a test
     *     asserting "nothing readable" would otherwise depend on
     *     whatever happens to exist on the machine running it (e.g.
     *     macOS ships a readable /etc/apache2/httpd.conf out of the box).
     */
    public function __construct(?array $candidateConfigPaths = null)
    {
        $this->candidateConfigPaths = $candidateConfigPaths ?? self::CANDIDATE_CONFIG_PATHS;
    }

    public function name(): string
    {
        return 'webserver';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $projectRoot = rtrim($context->projectRoot(), '/');

        $summary = [];
        $summary[] = '# Configuration du serveur web';
        $summary[] = '';
        $summary[] = 'Logiciel serveur (SERVER_SOFTWARE) : ' . $this->serverSoftware();
        $summary[] = 'Interface PHP (SAPI) : ' . PHP_SAPI;
        $summary[] = '';
        $summary[] = '## Fichiers .htaccess copiés';

        $copied = 0;
        foreach ($this->findHtaccessFiles($projectRoot) as $path) {
            $relative = substr($path, strlen($projectRoot) + 1);
            if ($context->addFileFromPath('webserver/htaccess/' . $relative, $path)) {
                $summary[] = '- ' . $relative;
                $copied++;
            } else {
                $summary[] = '- ' . $relative . ' (illisible)';
            }
        }
        if ($copied === 0) {
            $summary[] = '(aucun)';
        }

        $summary[] = '';
        $summary[] = '## Configuration serveur (meilleur effort)';

        $configCopied = 0;
        foreach ($this->candidateConfigPaths as $candidate) {
            if ($context->addFileFromPath('webserver/config/' . basename($candidate), $candidate)) {
                $summary[] = '- ' . $candidate . ' : copié';
                $configCopied++;
            } else {
                $summary[] = '- ' . $candidate . ' : indisponible';
            }
        }

        $context->addFileFromContent('webserver/summary.txt', implode("\n", $summary) . "\n");

        if ($copied === 0 && $configCopied === 0) {
            $context->markUnavailable('no_readable_webserver_configuration');
        } elseif ($configCopied === 0) {
            $context->addNote('Aucune configuration serveur lisible (attendu sur hébergement mutualisé)');
        }
    }

    /**
     * Every `.htaccess` in the install tree, bounded in depth and never
     * following symlinks — a link into `/` would otherwise turn this into
     * a whole-disk scan.
     *
     * @return array<int, string> absolute paths
     */
    private function findHtaccessFiles(string $projectRoot): array
    {
        $found = [];
        $queue = [[$projectRoot, 1]];

        while ($queue !== []) {
            [$directory, $depth] = array_shift($queue);

            $entries = @scandir($directory);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'vendor' || $entry === 'node_modules') {
                    continue;
                }

                $path = $directory . '/' . $entry;

                if ($entry === '.htaccess' && is_file($path)) {
                    $found[] = $path;
                    continue;
                }

                if ($depth < self::MAX_HTACCESS_DEPTH && is_dir($path) && !is_link($path)) {
                    $queue[] = [$path, $depth + 1];
                }
            }
        }

        sort($found);

        return $found;
    }

    private function serverSoftware(): string
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;

        return is_string($software) && $software !== '' ? $software : '(inconnu — exécution hors requête web)';
    }
}
