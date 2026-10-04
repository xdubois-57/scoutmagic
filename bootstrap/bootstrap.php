<?php

declare(strict_types=1);

/**
 * ScoutMagic — standalone bootstrap installer.
 *
 * Uploaded via FTP to an empty web folder. Writes token.php on its first
 * load and asks for it before anything else (the operator reads it over
 * FTP), refuses to go on until the site answers over HTTPS, then installs
 * either the latest published GitHub release or — when the operator is
 * restoring a portable backup — the release that wrote that backup (#719),
 * into whichever of the two supported layouts fits the host. A full
 * acceptance gate proves both functionality and non-exposure of storage/;
 * a backup is then sent in resumable chunks to the address the setup
 * wizard reads. The file deletes itself and redirects to the wizard, which
 * accepts the proof that the token was typed here.
 *
 * This is the first-run twin of Core\Maintenance\Task\InstallUpdateHandler
 * and Core\Maintenance\GitHubReleaseClient: same VERSION format, same
 * archive-root handling (resolveBranchArchiveRoot's logic is mirrored, not
 * reused — this file cannot depend on vendor/autoload.php, since it runs
 * before any dependency exists on disk), same "no new Composer dependency"
 * constraint (raw ZipArchive + PHP streams only).
 *
 * The installed application must be exactly the tree the repository already
 * describes (ARCHITECTURE.md §12), unchanged. This file adapts to the host;
 * it never modifies BackupService, InstallUpdateHandler, RestoreBackupHandler,
 * or FileAccessGuard.
 *
 * Everything below step BOOTSTRAP_TEST is pure/testable functions. main() is
 * the only place that touches superglobals, emits output, or calls exit —
 * see tests/Bootstrap/BootstrapTest.php.
 */

const BOOTSTRAP_REPO_OWNER = 'xdubois-57';
const BOOTSTRAP_REPO_NAME = 'scoutmagic';
const BOOTSTRAP_USER_AGENT = 'ScoutMagic-Bootstrap';
const BOOTSTRAP_HTTP_TIMEOUT = 20;
const BOOTSTRAP_DOWNLOAD_TIMEOUT = 300;
const BOOTSTRAP_STATE_FILE = '.bootstrap-state.php';
const BOOTSTRAP_LOCK_FILE = '.bootstrap.lock';
const BOOTSTRAP_LOCK_STALE_SECONDS = 600;
const BOOTSTRAP_TOKEN_FILE = 'token.php';
const BOOTSTRAP_INDEX_STUB_FILE = 'index.php';
const BOOTSTRAP_TEMP_DIR_PREFIX = '.tmp-';
const BOOTSTRAP_MIN_PHP_VERSION = '8.4.0';
const BOOTSTRAP_STORAGE_SUBDIRS = ['keys', 'config', 'core', 'modules', 'temp'];
const BOOTSTRAP_REQUIRED_ARTIFACT_ENTRIES = ['vendor/autoload.php', 'public/index.php', 'schema/core.sql'];
const BOOTSTRAP_ACCESS_FILE = '.bootstrap-access.php';
const BOOTSTRAP_INCOMING_PART = 'portable-restore.part';
const BOOTSTRAP_ARCHIVE_MAX_BYTES = 2 * 1024 * 1024 * 1024;
/** Below the smallest post_max_size a shared host is seen to keep (8 MB). */
const BOOTSTRAP_CHUNK_BYTES = 2 * 1024 * 1024;

// What this file hands the setup wizard (#719, D) — frozen, and the same
// values as Core\Security\BootstrapHandoff, which this file cannot load.
// tests/Bootstrap/BootstrapHandoffContractTest pins the two together.
const BOOTSTRAP_PROOF_COOKIE = 'scoutmagic_setup_proof';
const BOOTSTRAP_PROOF_LIFETIME_SECONDS = 7200;
const BOOTSTRAP_PROOF_CONTEXT = 'scoutmagic-setup-proof-v1';
const BOOTSTRAP_ARCHIVE_PATH = 'storage/restore/portable-restore.zip';
const BOOTSTRAP_INCOMING_DIR = 'storage/restore/incoming';
const BOOTSTRAP_RESTORE_MODE_URL = '/setup?restauration=1';
// The archive comment's format, as Core\Maintenance\Portable\PortableManifest writes it.
const BOOTSTRAP_PORTABLE_FORMAT = 'scoutmagic-portable-backup';
const BOOTSTRAP_PORTABLE_FORMAT_VERSION = 2;

// =============================================================================
// Preflight / environment
// =============================================================================

/**
 * Blocks a subfolder install. DOCUMENT_ROOT mismatches vs __DIR__ are
 * deliberately NOT checked here — they're informational only (a real chroot
 * reports e.g. Apache seeing /var/www/example.be/htdocs while PHP sees
 * /htdocs, both correct). Location is judged solely by where the script
 * itself was requested from.
 *
 * @param array<string, mixed> $server
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrapCheckLocation(array $server): array
{
    $scriptName = (string) ($server['SCRIPT_NAME'] ?? '/bootstrap.php');
    $dir = str_replace('\\', '/', dirname($scriptName));
    $isRoot = $dir === '/' || $dir === '.' || $dir === '';

    return [
        'ok' => $isRoot,
        'label' => "Emplacement d'installation",
        'detail' => $isRoot
            ? 'bootstrap.php est bien à la racine du site.'
            : "bootstrap.php doit être placé à la racine du site (actuellement détecté dans « {$dir} »).",
    ];
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrapCheckPhpVersion(string $version = PHP_VERSION): array
{
    $ok = version_compare($version, BOOTSTRAP_MIN_PHP_VERSION, '>=');

    return [
        'ok' => $ok,
        'label' => 'Version de PHP',
        'detail' => $ok
            ? "PHP {$version} détecté."
            : 'PHP ' . BOOTSTRAP_MIN_PHP_VERSION . " ou supérieur est requis (PHP {$version} détecté).",
    ];
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrapCheckZipExtension(): array
{
    $ok = class_exists('ZipArchive');

    return [
        'ok' => $ok,
        'label' => 'Extension ZipArchive',
        'detail' => $ok ? 'Disponible.' : "L'extension PHP zip (ZipArchive) est requise et absente sur ce serveur.",
    ];
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrapCheckOutboundHttps(callable $prober): array
{
    $ok = (bool) $prober();

    return [
        'ok' => $ok,
        'label' => 'Connexion sortante HTTPS',
        'detail' => $ok
            ? 'Connexion vers GitHub établie.'
            : "Impossible d'établir une connexion HTTPS sortante vers GitHub. Vérifiez que votre hébergeur autorise "
                . "les connexions sortantes.",
    ];
}

/**
 * Purely informational — server software, memory_limit, max_execution_time,
 * open_basedir, posix availability never gate the install. The acceptance
 * gate settles what these were only standing in for.
 *
 * @return array<string, mixed>
 */
function bootstrapGatherEnvironmentInfo(): array
{
    return [
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'inconnu',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
        'memory_limit' => ini_get('memory_limit') ?: 'inconnu',
        'max_execution_time' => ini_get('max_execution_time') ?: 'inconnu',
        'open_basedir' => ini_get('open_basedir') ?: '',
        'posix_available' => function_exists('posix_getpwuid'),
        'php_version' => PHP_VERSION,
    ];
}

/**
 * Empirical write+read+delete of a real file, plus mkdir()/rmdir() of a
 * throwaway path — is_writable() "lies under ACLs and open_basedir", per
 * the spec, so nothing here trusts it.
 */
function bootstrapProbeWritable(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }

    $probe = rtrim($dir, '/') . '/.bootstrap-write-probe-' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'probe') === false) {
        return false;
    }
    $read = @file_get_contents($probe);
    @unlink($probe);
    if ($read !== 'probe') {
        return false;
    }

    $subdir = rtrim($dir, '/') . '/.bootstrap-mkdir-probe-' . bin2hex(random_bytes(4));
    if (!@mkdir($subdir, 0755)) {
        return false;
    }
    $mkdirOk = is_dir($subdir);
    @rmdir($subdir);

    return $mkdirOk;
}

/**
 * Rejects a parent directory that looks like a filesystem root (etc/, usr/,
 * bin/, var/ all present together) — layout A must never be selected there.
 */
function bootstrapLooksLikeSystemRoot(string $dir): bool
{
    $markers = ['etc', 'usr', 'bin', 'var'];
    $present = 0;
    foreach ($markers as $marker) {
        if (is_dir(rtrim($dir, '/') . '/' . $marker)) {
            $present++;
        }
    }

    return $present >= count($markers);
}

/**
 * Decides Layout A ("Natural" — install into the parent, merge public/'s
 * contents into the document root itself) vs Layout B ("Single-tree" —
 * everything goes into the document root, protected by one root .htaccess).
 *
 * $docRoot must already be a resolved, real path (realpath()'d by the
 * caller) — this function only reasons about the string, never touches the
 * filesystem itself except via $writableProbe, so it stays testable.
 *
 * @return array{layout: 'A'|'B', parent: string|null, reason: string}
 */
function bootstrapSelectLayout(string $docRoot, callable $writableProbe): array
{
    $parent = dirname($docRoot);

    $parentContainsDocRoot = $parent !== $docRoot
        && strncmp($docRoot, rtrim($parent, '/') . '/', strlen(rtrim($parent, '/') . '/')) === 0;

    if (!$parentContainsDocRoot) {
        return [
            'layout' => 'B',
            'parent' => null,
            'reason' => "Le dossier parent ne contient pas physiquement le document root (hébergement chrooté ou lien "
                . "symbolique) — installation en une seule arborescence.",
        ];
    }

    if (bootstrapLooksLikeSystemRoot($parent)) {
        return [
            'layout' => 'B',
            'parent' => null,
            'reason' => 'Le dossier parent ressemble à une racine système — installation en une seule arborescence par '
                . 'sécurité.',
        ];
    }

    if (!$writableProbe($parent)) {
        return [
            'layout' => 'B',
            'parent' => null,
            'reason' => "Le dossier parent du document root n'est pas accessible en écriture — installation en une "
                . "seule arborescence dans le document root.",
        ];
    }

    return [
        'layout' => 'A',
        'parent' => $parent,
        'reason' => 'Le dossier parent du document root est accessible en écriture et le contient physiquement — '
            . 'installation naturelle.',
    ];
}

// =============================================================================
// GitHub release resolution
// =============================================================================

/**
 * The zip artifact is selected by filename (.zip suffix), never by array
 * position — GitHub does not preserve `gh release create`'s file argument
 * order in the assets array (observed: it sorts assets alphabetically, so
 * "bootstrap.php" lands before "release-vX.Y.Z.zip" at assets[0]).
 * Picking assets[0] unconditionally downloaded bootstrap.php itself and
 * failed the ZIP-magic-byte check downstream.
 *
 * @param array<string, mixed> $release
 * @return array{url: string, size: int, source: 'asset'|'zipball'}
 */
function bootstrapResolveArchiveUrl(array $release): array
{
    $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
    foreach ($assets as $asset) {
        $name = strtolower((string) ($asset['name'] ?? ''));
        if (str_ends_with($name, '.zip') && isset($asset['browser_download_url'])) {
            return [
                'url' => (string) $asset['browser_download_url'],
                'size' => (int) ($asset['size'] ?? 0),
                'source' => 'asset',
            ];
        }
    }

    if (!empty($release['zipball_url'])) {
        return ['url' => (string) $release['zipball_url'], 'size' => 0, 'source' => 'zipball'];
    }

    throw new RuntimeException("Impossible de déterminer l'URL de l'archive de la dernière version publiée.");
}

/**
 * No repository, ref, version, or path is ever read from the request — the
 * repo is the hardcoded BOOTSTRAP_REPO_OWNER/BOOTSTRAP_REPO_NAME constants,
 * always the latest published release, full stop.
 *
 * @return array<string, mixed>
 */
function bootstrapFetchLatestRelease(callable $httpGet): array
{
    return bootstrapFetchRelease(
        $httpGet,
        'https://api.github.com/repos/' . BOOTSTRAP_REPO_OWNER . '/' . BOOTSTRAP_REPO_NAME . '/releases/latest',
        'Aucune version publiée n\'a été trouvée pour ce dépôt.'
    );
}

/**
 * The release that wrote an archive (#719): `releases/tags/vX.Y.Z`. The
 * version comes from the archive's clear comment, so it is validated as
 * a bare release number before it is ever put in a URL — a development
 * build was never published, and is refused by name.
 *
 * @return array<string, mixed>
 */
function bootstrapFetchReleaseByVersion(callable $httpGet, string $version): array
{
    if (!bootstrapIsReleaseVersion($version)) {
        throw new RuntimeException('La version ' . $version . ' n\'est pas une version publiée : installez sans '
            . 'sauvegarde, puis envoyez-la dans l\'assistant de configuration.');
    }

    return bootstrapFetchRelease(
        $httpGet,
        'https://api.github.com/repos/' . BOOTSTRAP_REPO_OWNER . '/' . BOOTSTRAP_REPO_NAME
            . '/releases/tags/v' . $version,
        'La version ' . $version . ' n\'a pas été trouvée parmi les versions publiées.'
    );
}

/**
 * @return array<string, mixed>
 */
function bootstrapFetchRelease(callable $httpGet, string $url, string $notFound): array
{
    $attempts = 0;
    $lastError = null;

    while ($attempts < 3) {
        $attempts++;
        $result = $httpGet($url, ['Accept: application/vnd.github+json']);

        if ($result['status'] === 403 && ($result['headers']['x-ratelimit-remaining'] ?? '1') === '0') {
            $reset = (int) ($result['headers']['x-ratelimit-reset'] ?? 0);
            throw new RuntimeException(
                'Limite de requêtes GitHub atteinte' . ($reset > 0 ? ' — réessayez après ' . date(
                    'H:i:s',
                    $reset
                ) . '.' : '.')
            );
        }

        if ($result['status'] === 404) {
            throw new RuntimeException($notFound);
        }

        if ($result['status'] >= 200 && $result['status'] < 300) {
            $data = json_decode((string) $result['body'], true);
            if (is_array($data)) {
                return $data;
            }
            $lastError = new RuntimeException('Réponse GitHub invalide.');
        } else {
            $lastError = new RuntimeException('Erreur GitHub (HTTP ' . $result['status'] . ').');
        }

        if ($attempts < 3) {
            usleep(500000 * $attempts);
        }
    }

    // Unreachable without a return/throw above unless the loop ran at
    // least once and fell through via the failure branch, which always
    // sets $lastError — kept as a throw rather than a bare "unreachable"
    // assumption so a future refactor here fails loudly instead of
    // silently returning null.
    throw $lastError;
}

function bootstrapDownloadWithRetry(string $url, string $destPath, callable $downloader): void
{
    $attempts = 0;
    $lastError = null;

    while ($attempts < 3) {
        $attempts++;
        try {
            $downloader($url, $destPath);
            if (is_file($destPath) && filesize($destPath) > 0) {
                return;
            }
            $lastError = new RuntimeException('Le fichier téléchargé est vide.');
        } catch (\Throwable $e) {
            $lastError = $e;
        }
        if ($attempts < 3) {
            usleep(500000 * $attempts);
        }
    }

    throw $lastError;
}

/**
 * @return array{ok: bool, degraded: bool, label: string, detail: string}
 */
function bootstrapCheckDiskSpace(string $dir, int $declaredArtifactSize, ?callable $freeSpaceFn = null): array
{
    $freeSpaceFn ??= 'disk_free_space';

    if (!function_exists('disk_free_space') && $freeSpaceFn === 'disk_free_space') {
        return ['ok' => true, 'degraded' => true, 'label' => 'Espace disque', 'detail' => 'Impossible de vérifier '
            . 'l\'espace disque disponible (fonction indisponible) — poursuite sans garantie.'];
    }

    $free = @$freeSpaceFn($dir);
    if ($free === false || $free === null) {
        return ['ok' => true, 'degraded' => true, 'label' => 'Espace disque', 'detail' => "Impossible de déterminer "
            . "l'espace disque disponible — poursuite sans garantie."];
    }

    if ($declaredArtifactSize <= 0) {
        return ['ok' => true, 'degraded' => true, 'label' => 'Espace disque', 'detail' => "Taille de l'archive "
            . "inconnue — vérification ignorée."];
    }

    $needed = $declaredArtifactSize * 3;
    $ok = $free >= $needed;

    return [
        'ok' => $ok,
        'degraded' => false,
        'label' => 'Espace disque',
        'detail' => $ok
            ? sprintf('%.1f Mo disponibles.', $free / 1048576)
            : sprintf(
                'Seulement %.1f Mo disponibles, %.1f Mo requis (3x la taille de l\'archive).',
                $free / 1048576,
                $needed / 1048576
            ),
    ];
}

// =============================================================================
// Archive extraction
// =============================================================================

function bootstrapIsZipSlip(string $entryName): bool
{
    $normalized = str_replace('\\', '/', $entryName);
    if (strpos($normalized, '../') !== false || substr($normalized, -3) === '/..') {
        return true;
    }
    if (str_starts_with($normalized, '/')) {
        return true;
    }
    if (preg_match('#^[A-Za-z]:#', $normalized) === 1) {
        return true;
    }

    return false;
}

function bootstrapExtractZipSafely(string $zipPath, string $destDir): void
{
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("L'archive téléchargée est invalide.");
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        if (bootstrapIsZipSlip($name)) {
            $zip->close();
            throw new RuntimeException("L'archive contient une entrée dangereuse (chemin hors de la zone "
                . "d'extraction) : {$name}");
        }

        $stat = $zip->statIndex($i);
        $externalAttr = (int) ($stat['external_attr'] ?? 0);
        $unixMode = ($externalAttr >> 16) & 0xFFFF;
        if (($unixMode & 0xA000) === 0xA000) {
            $zip->close();
            throw new RuntimeException("L'archive contient un lien symbolique, ce qui n'est pas autorisé : {$name}");
        }
    }

    $extracted = $zip->extractTo($destDir);
    $zip->close();

    if (!$extracted) {
        throw new RuntimeException("L'extraction de l'archive a échoué.");
    }
}

/**
 * Mirrors Core\Maintenance\Task\InstallUpdateHandler::resolveBranchArchiveRoot()
 * exactly, but the decision of which strategy to apply comes from the
 * source type ('asset' vs 'zipball'), never from entry count — a release
 * asset built by scripts/release.sh is zipped at the top level and must
 * never have this stripping applied even if it coincidentally has a single
 * top-level entry.
 */
function bootstrapResolveArchiveRoot(string $extractedDir, string $sourceType): string
{
    if ($sourceType !== 'zipball') {
        return $extractedDir;
    }

    $entries = array_values(array_diff(scandir($extractedDir) ?: [], ['.', '..']));
    if (count($entries) === 1 && is_dir($extractedDir . '/' . $entries[0])) {
        return $extractedDir . '/' . $entries[0];
    }

    return $extractedDir;
}

/**
 * @return array{ok: bool, label: string, detail: string}
 */
function bootstrapVerifyArtifact(string $sourceRoot): array
{
    $missing = [];
    foreach (BOOTSTRAP_REQUIRED_ARTIFACT_ENTRIES as $entry) {
        if (!file_exists($sourceRoot . '/' . $entry)) {
            $missing[] = $entry;
        }
    }

    $ok = empty($missing);

    return [
        'ok' => $ok,
        'label' => "Vérification de l'artefact",
        'detail' => $ok
            ? 'Tous les fichiers requis sont présents.'
            // vendor/ is the one that bites: no Composer on a shared host
            // means a vendor-less artifact installs cleanly and yields a
            // dead site with no way to recover without FTP surgery.
            : 'Fichiers manquants dans l\'archive : ' . implode(', ', $missing) . '.',
    ];
}

// =============================================================================
// Filesystem: dotfile-preserving copy, removal, .htaccess
// =============================================================================

/**
 * Copies every top-level entry of $source into $dest, except entries in
 * $excludeTopLevel. Dotfile-preserving throughout — never glob(), which
 * silently skips dotfiles (public/.htaccess and public/.user.ini are both
 * load-bearing).
 *
 * @param string[] $excludeTopLevel
 * @return string[] the top-level entry names actually copied (for rollback)
 */
function bootstrapCopyTree(string $source, string $dest, array $excludeTopLevel = []): array
{
    if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
        throw new RuntimeException("Impossible de créer le dossier de destination.");
    }

    $copied = [];
    foreach (new \DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $name = $entry->getFilename();
        if (in_array($name, $excludeTopLevel, true)) {
            continue;
        }
        bootstrapCopyEntry($source . '/' . $name, $dest . '/' . $name);
        $copied[] = $name;
    }

    return $copied;
}

function bootstrapCopyEntry(string $source, string $dest): void
{
    if (is_dir($source)) {
        if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
            throw new RuntimeException('Impossible de créer un dossier pendant la copie.');
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $relative = substr((string) $item, strlen($source) + 1);
            $target = $dest . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } elseif (!@copy((string) $item, $target)) {
                throw new RuntimeException('Échec de la copie d\'un fichier pendant l\'installation.');
            }
        }
    } elseif (!@copy($source, $dest)) {
        throw new RuntimeException('Échec de la copie d\'un fichier pendant l\'installation.');
    }
}

function bootstrapRemovePath(string $path): void
{
    if (is_link($path)) {
        @unlink($path);
    } elseif (is_dir($path)) {
        bootstrapRemoveDirectory($path);
    } elseif (file_exists($path)) {
        @unlink($path);
    }
}

function bootstrapRemoveDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isLink() || !$item->isDir()) {
            @unlink((string) $item);
        } else {
            @rmdir((string) $item);
        }
    }
    @rmdir($dir);
}

/**
 * Layout B protection: exactly one root .htaccess, never per-directory deny
 * files (which miss runtime-created directories like module storage/<name>/
 * folders that appear long after install, and which get overwritten by
 * every update since they'd live inside core/modules/vendor). Placed before
 * every other rule.
 *
 * PHP execution is routed to the index.php stub sitting in THIS SAME
 * directory (bootstrapIndexStubContent()) — never rewritten across
 * directories to public/index.php. An earlier version rewrote straight to
 * public/index.php via a two-hop chain (root .htaccess → public/'s own
 * .htaccess re-triggering a second rewrite for the same request) — that
 * cross-directory PHP-execution rewrite is a well-documented trap on some
 * Apache + PHP-FPM/FastCGI configurations, where SCRIPT_FILENAME is
 * computed from the request's original path rather than the rewritten
 * target and PHP-FPM reports a raw "File not found." even though the
 * target file genuinely exists. A same-directory rewrite to a real,
 * directly-executable PHP file (the stub) is the one universally-supported
 * case; only static assets are ever routed across directories here, which
 * never touches PHP execution or SCRIPT_FILENAME at all.
 */
function bootstrapHtaccessContent(): string
{
    return <<<'HTACCESS'
RewriteEngine On

# Some hosts (observed: OVH-style mutualized hosting) ship a placeholder
# page (e.g. default_index.html) and configure their own vhost-level
# DirectoryIndex to list it ahead of index.php. mod_dir's directory-index
# resolution for the bare "/" request can win that race against this
# .htaccess's own rewrite rules depending on the host's Apache module
# hook ordering, serving the host's placeholder instead of ever reaching
# the catch-all rule below. Overriding DirectoryIndex here — a directive
# virtually every host's AllowOverride permits — removes the ambiguity
# outright rather than relying on rewrite-vs-mod_dir ordering.
DirectoryIndex index.php

# Deny internal directories at any depth — a prefix rule catches
# runtime-created subdirectories (e.g. module storage/<name>/ folders) that
# don't exist yet at install time, unlike a per-directory deny file. Gated
# on the request actually resolving to a real file or directory: "config"
# collides with the app's own admin route namespace (/config/maintenance,
# /config/notifications, etc.) — an ungated string-prefix match would deny
# every one of those legitimate routes outright, since they never
# correspond to a real path on disk (only config/app.php itself does).
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^(storage|core|modules|config|schema|vendor|tests|scripts)(/|$) - [F,L]

# Deny dotfiles anywhere in the tree — except /.well-known/, which is a
# standardised public namespace (RFC 8615) and only looks like a dotfile.
# CardDAV autodiscovery asks for /.well-known/carddav before it will
# accept any other address (ARCHITECTURE.md 8.118), and ACME puts its
# challenge there too, so an unconditional deny here breaks both with a
# 403 that no application code can see, let alone explain.
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule (^|/)\. - [F,L]

# HTTP Basic credentials survive to PHP.
#
# Under mod_php Apache hands them over as PHP_AUTH_USER/PHP_AUTH_PW and
# there is nothing to do. Under CGI and FastCGI — which is most shared
# hosting, the deployment this project targets — the Authorization header
# is stripped before PHP sees it, because it is how the web server's own
# authentication would be carried. A CardDAV client speaks Basic and
# nothing else, so without this line every synchronisation answers 401
# forever, with correct credentials, and nothing anywhere says why.
#
# Copied into an environment variable rather than into a header: mod_php
# and FastCGI disagree about header rewriting, and both honour E=. What
# mod_rewrite sets this way reaches PHP as REDIRECT_HTTP_AUTHORIZATION
# once the request is rewritten, which is why the controller reads both
# spellings.
RewriteCond %{HTTP:Authorization} .
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

# Real, non-PHP files that exist under public/ (assets, etc.) are served
# directly as static files — safe to route across directories since no PHP
# execution is involved. .php is explicitly excluded: it must never be
# routed this way, only through the same-directory index.php stub below.
RewriteCond %{REQUEST_URI} !\.php$
RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -f
RewriteRule ^(.*)$ public/$1 [L]

# A real FILE sitting directly in the document root — the index.php stub
# itself, token.php once written, the acceptance gate's own control-*.txt
# canary — is served/executed as-is, same path, no rewrite: never swept
# into the front controller. Deliberately -f only, never -d: the document
# root itself is always a directory, and the root path must fall through
# to the explicit catch-all below rather than being served "as-is" here,
# which would silently depend on the host's DirectoryIndex configuration
# including index.php (usually true, but never assumed).
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

# Everything else runs through the index.php stub in this same directory.
RewriteRule ^ index.php [L]

<FilesMatch "\.(key|enc|sql|log|sqlite)$">
    Require all denied
</FilesMatch>

# The CLI scheduler entry point must never be reachable over HTTP. It
# already refuses a non-CLI SAPI itself; this is the .htaccess belt to it.
<Files "cron.php">
    Require all denied
</Files>

# ---------------------------------------------------------------------
# Baseline security headers for the files Apache serves ITSELF.
#
# Everything routed through index.php gets its headers from
# Core\Http\Response. A request for /assets/css/app.css never enters PHP
# at all — the rewrite above only forwards what does NOT exist on disk —
# so nothing sets them there, and a dynamic scan reports the gap on every
# static file (which is how this was found).
#
# Scoped to static extensions on purpose. A blanket rule here would also
# apply to PHP responses and overwrite the headers the application builds
# per request — the Content-Security-Policy carries a per-request nonce,
# and a fixed copy of it in this file would go stale the moment the
# policy changes.
#
# mod_headers is not universal on shared hosting, so this degrades
# silently when it is absent, like the rest of this file.
# ---------------------------------------------------------------------
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|webmanifest)$">
        Header always set X-Content-Type-Options "nosniff"

        # HSTS is an origin-wide policy: one HTML response already covers
        # the assets too. This closes the single remaining gap — a
        # visitor whose first-ever request to the origin is an asset.
        #
        # env=HTTPS so it is never announced on a connection Apache
        # cannot see is encrypted. Behind a separate TLS terminator
        # mod_ssl sets nothing — there the PHP side still emits it,
        # because Core\Http\RequestScheme's https_required policy (on by
        # default, SECURITY.md § 9) does not depend on what PHP sees, and
        # this file correctly stays quiet.
        #
        # The value is kept identical to Core\Http\Response's, and
        # Tests\Security\StaticAssetHeadersTest pins the two to each
        # other so they cannot drift apart.
        Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
    </FilesMatch>
</IfModule>

# ---------------------------------------------------------------------
# Compression, for the static files Apache serves itself AND the HTML
# the application renders (AddOutputFilterByType matches the response's
# content type, whatever produced it). Nothing in this tree was
# compressed at all before this block — 348 KB of render-blocking CSS
# shrinks to ~55 KB gzipped.
#
# Scoped by content type on purpose: text formats and SVG only, never
# the already-compressed image/font/archive types, and never the
# /files/… streams the application serves with Content-Length/Range
# semantics — their types (image/webp, application/pdf, video/*) are
# deliberately absent from this list.
#
# The type list is pinned to public/.htaccess's copy by
# Tests\Bootstrap\HtaccessCompressionTest so the two cannot drift.
# mod_deflate is not universal on shared hosting, so this degrades
# silently when it is absent, like the rest of this file.
# ---------------------------------------------------------------------
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css
    AddOutputFilterByType DEFLATE text/calendar text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/json
    AddOutputFilterByType DEFLATE application/manifest+json image/svg+xml
</IfModule>

HTACCESS;
}

/**
 * The one file bootstrap.php places outside the repository's own tree
 * (ARCHITECTURE.md §12) — see bootstrapHtaccessContent()'s own comment
 * for why a same-directory PHP stub is required at all. __DIR__ inside the
 * required file always reflects its own real location regardless of how
 * it's included, so public/index.php's own path resolution (AppConfig,
 * Twig template dir, SecretManager, etc. — all built from its own
 * __DIR__) needs no changes whatsoever to be required from here.
 */
function bootstrapIndexStubContent(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

// Single-tree layout stub (bootstrap.php, Layout B) — see this install's
// root .htaccess for why PHP execution is routed here rather than
// straight to public/index.php.
require __DIR__ . '/public/index.php';
PHP;
}

// =============================================================================
// storage/, VERSION, token
// =============================================================================

/**
 * @return string[] subdirectory names created
 */
function bootstrapCreateStorageDirs(string $basePath): array
{
    $created = [];
    foreach (BOOTSTRAP_STORAGE_SUBDIRS as $sub) {
        $path = $basePath . '/storage/' . $sub;
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException("Impossible de créer storage/{$sub}.");
        }
        $created[] = $sub;
    }

    return $created;
}

/**
 * Exact same format as Core\Maintenance\VersionFile::write() — the
 * installed site's VersionFile::read() must see byte-identical content.
 */
function bootstrapWriteVersion(string $basePath, string $version): void
{
    file_put_contents($basePath . '/VERSION', $version . "\n");
}

function bootstrapGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

function bootstrapTokenFileContent(string $token): string
{
    return "<?php /* TOKEN: {$token} */\n";
}

// =============================================================================
// Acceptance gate — Part 1: server-side checks (S1-S8)
// =============================================================================

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapGateResult(string $id, bool $ok, string $label, string $detail): array
{
    return ['id' => $id, 'ok' => $ok, 'label' => $label, 'detail' => $detail];
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS1(string $basePath): array
{
    $path = $basePath . '/VERSION';
    $ok = is_file($path) && trim((string) @file_get_contents($path)) !== '';

    return bootstrapGateResult('S1', $ok, 'Fichier VERSION', $ok ? 'Présent et lisible.' : 'Manquant ou vide.');
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS2(string $basePath): array
{
    $ok = is_file($basePath . '/vendor/autoload.php');

    return bootstrapGateResult(
        'S2',
        $ok,
        'Dépendances installées',
        $ok ? 'vendor/autoload.php présent.' : 'vendor/autoload.php manquant.'
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS3(string $publicDir): array
{
    $ok = is_file($publicDir . '/index.php');

    return bootstrapGateResult(
        'S3',
        $ok,
        "Point d'entrée applicatif",
        $ok ? 'index.php présent.' : 'index.php manquant.'
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS4(string $basePath): array
{
    $ok = is_file($basePath . '/schema/core.sql');

    return bootstrapGateResult(
        'S4',
        $ok,
        'Schéma de base de données',
        $ok ? 'schema/core.sql présent.' : 'schema/core.sql manquant.'
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS5(string $basePath): array
{
    $missing = [];
    foreach (BOOTSTRAP_STORAGE_SUBDIRS as $sub) {
        if (!is_dir($basePath . '/storage/' . $sub)) {
            $missing[] = $sub;
        }
    }
    $ok = empty($missing);

    return bootstrapGateResult(
        'S5',
        $ok,
        'Dossiers de stockage',
        $ok
            ? 'Tous les sous-dossiers storage/ sont créés.'
            : 'Sous-dossiers manquants : ' . implode(', ', $missing) . '.'
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS6(string $basePath): array
{
    $path = $basePath . '/storage/keys';
    $ok = is_dir($path) && is_writable($path);
    $mode = is_dir($path) ? substr(sprintf('%o', fileperms($path)), -4) : 'n/a';

    return bootstrapGateResult(
        'S6',
        $ok,
        'Permissions storage/keys',
        $ok ? "Accessible en écriture (mode {$mode})." : "Non accessible en écriture (mode {$mode})."
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS7(string $extractedRoot): array
{
    $ok = !is_file($extractedRoot . '/.htaccess');

    return bootstrapGateResult(
        'S7',
        $ok,
        "Absence de .htaccess dans l'artefact",
        $ok ? "Aucun .htaccess à la racine de l'archive." : "L'archive contenait un .htaccess à sa racine — elle ne "
            . "devrait jamais en fournir un."
    );
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrapCheckS8(string $tempDir): array
{
    $ok = !is_dir($tempDir);

    return bootstrapGateResult(
        'S8',
        $ok,
        'Nettoyage du dossier temporaire',
        $ok ? 'Dossier temporaire supprimé.' : 'Le dossier temporaire existe encore.'
    );
}

// =============================================================================
// Acceptance gate — Part 2: browser-fetch checks (B1-B8), evaluated from
// results the client reports back after fetching each probe URL itself.
// =============================================================================

/**
 * B1 — positive control. Must be exactly what was written.
 */
function bootstrapEvaluateControlProbe(int $httpStatus, string $fetchedBody, string $probeContent): bool
{
    return $httpStatus === 200 && $fetchedBody === $probeContent;
}

/**
 * B2 — token.php must return 200 with an EMPTY body, proving PHP executes
 * it rather than serving it as source. Catastrophic if this fails.
 */
function bootstrapEvaluatePhpExecutionProbe(int $httpStatus, string $fetchedBody): bool
{
    return $httpStatus === 200 && trim($fetchedBody) === '';
}

/**
 * B3-B7 — "not readable" means 403, 404, or a 200 whose body is an error
 * page rather than the probe content. Never read status alone.
 */
function bootstrapEvaluateProtectionProbe(int $httpStatus, string $fetchedBody, string $probeContent): bool
{
    if ($httpStatus === 403 || $httpStatus === 404) {
        return true;
    }
    if ($httpStatus === 200) {
        return $fetchedBody !== $probeContent;
    }

    return false;
}

/**
 * B8 — storage/ directory URL shows no index listing.
 */
function bootstrapEvaluateNoDirectoryListing(int $httpStatus, string $fetchedBody): bool
{
    if ($httpStatus === 403 || $httpStatus === 404) {
        return true;
    }
    if ($httpStatus === 200 && stripos($fetchedBody, 'Index of') === false) {
        return true;
    }

    return false;
}

/**
 * F1 — the setup wizard must actually be reachable at the site root.
 */
function bootstrapEvaluateFunctionalProbe(int $httpStatus, string $fetchedBody, string $marker): bool
{
    return $httpStatus === 200 && strpos($fetchedBody, $marker) !== false;
}

// =============================================================================
// State / lock persistence
// =============================================================================

/**
 * @return array<string, mixed>
 */
function bootstrapReadState(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || !preg_match('/\/\*(.*)\*\//s', $raw, $matches)) {
        return [];
    }
    $data = json_decode(trim($matches[1]), true);

    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $state
 */
function bootstrapWriteState(string $path, array $state): bool
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return @file_put_contents($path, "<?php\n/*\n{$json}\n*/\n") !== false;
}

function bootstrapAcquireLock(string $path): bool
{
    if (is_file($path) && (time() - (int) @filemtime($path)) < BOOTSTRAP_LOCK_STALE_SECONDS) {
        return false;
    }

    return @file_put_contents($path, (string) getmypid()) !== false;
}

function bootstrapReleaseLock(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
    }
}

// =============================================================================
// The 11-step install flow — each step a short POST, state persisted
// between requests since a shared host can time out mid-extraction.
// =============================================================================

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepPreflight(string $docRoot, array $state): array
{
    // Blocking (#719, B3), and first: nothing is installed — nor even
    // probed — before the site has been seen answering over HTTPS from
    // this folder. Injected from the access file by
    // bootstrapHandleStepRequest().
    bootstrapRequireVerifiedHttps($state);

    $checks = [
        bootstrapCheckLocation($_SERVER),
        bootstrapCheckPhpVersion(),
        bootstrapCheckZipExtension(),
        bootstrapCheckOutboundHttps('bootstrapDefaultHttpsProbe'),
    ];
    foreach ($checks as $check) {
        if (!$check['ok']) {
            throw new RuntimeException($check['detail']);
        }
    }

    if (!bootstrapProbeWritable($docRoot)) {
        throw new RuntimeException("Le dossier d'installation n'est pas accessible en écriture.");
    }

    $layoutInfo = bootstrapSelectLayout($docRoot, 'bootstrapProbeWritable');

    if (bootstrapAlreadyInstalled($docRoot)) {
        throw new RuntimeException('Ce dossier contient déjà une installation ScoutMagic.');
    }

    $state['doc_root'] = $docRoot;
    $state['layout'] = $layoutInfo['layout'];
    $state['layout_parent'] = $layoutInfo['parent'];
    $state['layout_reason'] = $layoutInfo['reason'];
    $state['environment'] = bootstrapGatherEnvironmentInfo();
    $state['label'] = 'Préflight';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 */
function bootstrapRequireVerifiedHttps(array $state): void
{
    if (empty($state['site_https_verified'])) {
        throw new RuntimeException("Vérifiez d'abord que le site répond en HTTPS, puis relancez l'installation.");
    }
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepResolve(string $docRoot, array $state, ?callable $httpGet = null): array
{
    $httpGet ??= 'bootstrapDefaultHttpGet';
    // An archive to restore installs the release that wrote it (#719): the
    // restore then runs on its own version, and the site is updated
    // afterwards along the usual path, one major version at a time.
    $wanted = (string) ($state['release_version'] ?? '');
    $release = $wanted !== ''
        ? bootstrapFetchReleaseByVersion($httpGet, $wanted)
        : bootstrapFetchLatestRelease($httpGet);
    $archive = bootstrapResolveArchiveUrl($release);

    $installTarget = $state['layout'] === 'A' ? $state['layout_parent'] : $docRoot;
    $diskCheck = bootstrapCheckDiskSpace($installTarget, $archive['size']);
    if (!$diskCheck['ok']) {
        throw new RuntimeException($diskCheck['detail']);
    }

    $state['version'] = ltrim((string) ($release['tag_name'] ?? '0.0.0'), 'v');
    $state['archive_url'] = $archive['url'];
    $state['archive_size'] = $archive['size'];
    $state['source_type'] = $archive['source'];
    $state['disk_check'] = $diskCheck;
    $state['label'] = $wanted !== '' ? 'Résolution de la version ' . $wanted : 'Résolution de la dernière version';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepDownload(string $docRoot, array $state): array
{
    $tempDir = $docRoot . '/' . BOOTSTRAP_TEMP_DIR_PREFIX . bin2hex(random_bytes(6));
    if (!mkdir($tempDir, 0755, true)) {
        throw new RuntimeException('Impossible de créer le dossier temporaire.');
    }
    // Temp dir lives inside the install target, never sys_get_temp_dir()
    // (cross-device rename() risk, size-cap risk) — its own deny file in
    // case a request lands on it before it's removed in the finally step.
    file_put_contents($tempDir . '/.htaccess', "Require all denied\n");

    $artifactPath = $tempDir . '/artifact.zip';
    bootstrapDownloadWithRetry($state['archive_url'], $artifactPath, 'bootstrapDefaultDownloader');

    $header = (string) @file_get_contents($artifactPath, false, null, 0, 2);
    if (filesize($artifactPath) < 4 || $header !== 'PK') {
        throw new RuntimeException("Le fichier téléchargé n'est pas une archive ZIP valide.");
    }

    $state['temp_dir'] = $tempDir;
    $state['artifact_path'] = $artifactPath;
    $state['label'] = 'Téléchargement';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepExtract(string $docRoot, array $state): array
{
    $extractedDir = $state['temp_dir'] . '/extracted';
    mkdir($extractedDir, 0755, true);
    bootstrapExtractZipSafely($state['artifact_path'], $extractedDir);

    $state['extracted_dir'] = $extractedDir;
    $state['source_root'] = bootstrapResolveArchiveRoot($extractedDir, $state['source_type']);
    $state['label'] = 'Extraction';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepVerifyArtifact(string $docRoot, array $state): array
{
    $result = bootstrapVerifyArtifact($state['source_root']);
    if (!$result['ok']) {
        throw new RuntimeException($result['detail']);
    }
    if (is_file($state['source_root'] . '/.htaccess')) {
        throw new RuntimeException("L'archive contient un .htaccess à sa racine — installation refusée.");
    }

    $state['label'] = "Vérification de l'artefact";
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepInstall(string $docRoot, array $state): array
{
    $installTarget = $state['layout'] === 'A' ? $state['layout_parent'] : $docRoot;
    $copied = bootstrapCopyTree($state['source_root'], $installTarget, ['storage', 'VERSION']);

    if ($state['layout'] === 'B') {
        file_put_contents($docRoot . '/.htaccess', bootstrapHtaccessContent());
        file_put_contents($docRoot . '/' . BOOTSTRAP_INDEX_STUB_FILE, bootstrapIndexStubContent());
    }

    bootstrapSeedAppConfig($installTarget);

    $state['install_target'] = $installTarget;
    $state['installed_entries'] = $copied;
    $state['label'] = 'Installation des fichiers';
    $state['percent'] = 100;

    return $state;
}

/**
 * config/app.php is deliberately excluded from every release artifact
 * (scripts/release.sh) and never touched by InstallUpdateHandler's
 * copy-over-live-install step — it holds per-site values (currently just
 * debug mode) that must never be silently clobbered by an update. But a
 * genuinely first install has no existing config/app.php to protect, and
 * without one Core\Config\AppConfig throws immediately on every single
 * request (it's constructed before anything else in public/index.php,
 * ahead of even the setup-wizard routing) — bootstrap.php is the only
 * place in the whole install path positioned to seed it from the shipped
 * config/app.php.dist template, and only if the real file doesn't
 * already exist (never overwrites — matches the "protect existing
 * config" intent everywhere else this file is handled).
 */
function bootstrapSeedAppConfig(string $installTarget): void
{
    $configPath = $installTarget . '/config/app.php';
    $distPath = $installTarget . '/config/app.php.dist';
    if (!is_file($configPath) && is_file($distPath)) {
        @copy($distPath, $configPath);
    }
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepStorage(string $docRoot, array $state): array
{
    bootstrapCreateStorageDirs($state['install_target']);
    $state['label'] = 'Création du stockage';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepFinalize(string $docRoot, array $state): array
{
    bootstrapWriteVersion($state['install_target'], $state['version']);
    bootstrapRemoveDirectory($state['temp_dir']);

    $state['label'] = 'Finalisation';
    $state['percent'] = 100;

    return $state;
}

/**
 * Runs the full S1-S8 server-side gate. If any fails, rolls back
 * immediately (no point asking the browser to probe a tree we're about to
 * delete). Otherwise writes the browser-fetch canaries and hands their
 * URLs back for the client to test itself.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepGatePrepare(string $docRoot, array $state): array
{
    $basePath = $state['install_target'];
    $publicDir = $state['layout'] === 'A' ? $docRoot : $docRoot . '/public';

    $sChecks = [
        bootstrapCheckS1($basePath),
        bootstrapCheckS2($basePath),
        bootstrapCheckS3($publicDir),
        bootstrapCheckS4($basePath),
        bootstrapCheckS5($basePath),
        bootstrapCheckS6($basePath),
        bootstrapCheckS7($state['source_root']),
        bootstrapCheckS8($state['temp_dir']),
    ];
    $state['s_checks'] = $sChecks;

    $sFailed = array_values(array_filter($sChecks, static fn (array $c): bool => !$c['ok']));
    if (!empty($sFailed)) {
        bootstrapRollbackInstall($docRoot, $state);
        $state = bootstrapFinishGate($state, false);
        $state['gate_aborted_at'] = 'S';
        $state['label'] = 'Contrôles (échec)';
        $state['percent'] = 100;

        return $state;
    }

    $state['probes'] = bootstrapWriteGateProbes($docRoot, $state);
    $state['awaiting_gate_report'] = true;
    $state['label'] = 'Contrôles';
    $state['percent'] = 50;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<int, array<string, mixed>>
 */
function bootstrapWriteGateProbes(string $docRoot, array $state): array
{
    $layout = $state['layout'];
    $basePath = $state['install_target'];
    $rand = bin2hex(random_bytes(6));
    $probeContent = 'scoutmagic-probe-' . $rand;

    $probes = [];

    // B1 — positive control, in the docroot itself, whichever layout.
    // "A failed B1 invalidates B2-B8; never read it as protected."
    $controlFile = $docRoot . '/control-' . $rand . '.txt';
    file_put_contents($controlFile, $probeContent);
    $probes[] = [
        'id' => 'B1',
        'kind' => 'control',
        'url' => '/control-' . $rand . '.txt',
        'expected' => $probeContent,
        'file' => $controlFile
    ];

    // B2 — token.php must execute as PHP (empty body), never be served as
    // source. Probed as it is: since #719 it holds the real token from the
    // first load, and the operator's proof cookie is checked against it on
    // every request — a stand-in written here would lock them out at the
    // gate report. Served as source, its token shows and the gate fails,
    // and the rollback removes the file.
    bootstrapEnsureTokenFile($docRoot);
    $probes[] = [
        'id' => 'B2',
        'kind' => 'php_exec',
        'url' => '/' . BOOTSTRAP_TOKEN_FILE,
        'expected' => '',
        'file' => null
    ];

    // B7 — dotfile in the docroot, always tested regardless of layout.
    $dotFile = $docRoot . '/.probe-' . $rand;
    file_put_contents($dotFile, $probeContent);
    $probes[] = [
        'id' => 'B7',
        'kind' => 'protection',
        'url' => '/.probe-' . $rand,
        'expected' => $probeContent,
        'file' => $dotFile
    ];

    if ($layout === 'B') {
        // B3 — storage/keys/
        @mkdir($basePath . '/storage/keys', 0755, true);
        $b3File = $basePath . '/storage/keys/canary-' . $rand . '.txt';
        file_put_contents($b3File, $probeContent);
        $probes[] = [
            'id' => 'B3',
            'kind' => 'protection',
            'url' => '/storage/keys/canary-' . $rand . '.txt',
            'expected' => $probeContent,
            'file' => $b3File
        ];

        // B4 — a storage/<new-dir> created moments earlier, proving a
        // prefix rule rather than a per-directory deny file. Failing while
        // B3 passes still aborts the whole install.
        $newDirName = 'gatecheck_' . $rand;
        @mkdir($basePath . '/storage/' . $newDirName, 0755, true);
        $b4File = $basePath . '/storage/' . $newDirName . '/canary-' . $rand . '.txt';
        file_put_contents($b4File, $probeContent);
        $probes[] = [
            'id' => 'B4',
            'kind' => 'protection',
            'url' => '/storage/' . $newDirName . '/canary-' . $rand . '.txt',
            'expected' => $probeContent,
            'file' => $b4File,
            'dir' => $basePath . '/storage/' . $newDirName
        ];

        // B5 — a real, already-installed file. config/app.php itself is
        // gitignored and excluded from the release artifact (per-unit
        // local config, never shipped) — config/app.php.dist is the real
        // file guaranteed present in a fresh install.
        if (is_file($basePath . '/config/app.php.dist')) {
            $probes[] = [
                'id' => 'B5',
                'kind' => 'protection',
                'url' => '/config/app.php.dist',
                'expected' => (string) file_get_contents($basePath . '/config/app.php.dist'),
                'file' => null
            ];
        }

        // B6 — real file, vendor/autoload.php.
        $probes[] = [
            'id' => 'B6',
            'kind' => 'protection',
            'url' => '/vendor/autoload.php',
            'expected' => (string) file_get_contents($basePath . '/vendor/autoload.php'),
            'file' => null
        ];

        // B8 — storage/ directory listing.
        $probes[] = ['id' => 'B8', 'kind' => 'listing', 'url' => '/storage/', 'expected' => null, 'file' => null];
    }

    // F1 — the wizard itself, reachable at the site root. The marker is a
    // stable id on the setup wizard's own <body>/root element.
    $probes[] = [
        'id' => 'F1',
        'kind' => 'functional',
        'url' => '/',
        'expected' => 'id="scoutmagic-setup-wizard"',
        'file' => null
    ];

    return $probes;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>|null
 */
function bootstrapFindProbe(array $state, string $id): ?array
{
    foreach ((array) ($state['probes'] ?? []) as $probe) {
        if (($probe['id'] ?? null) === $id) {
            return $probe;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $state
 */
function bootstrapProbeExpected(array $state, string $id): string
{
    $probe = bootstrapFindProbe($state, $id);

    return $probe !== null && $probe['expected'] !== null ? (string) $probe['expected'] : '';
}

/**
 * @param array<string, mixed> $state
 */
function bootstrapCleanupGateProbes(array $state): void
{
    foreach ((array) ($state['probes'] ?? []) as $probe) {
        if (($probe['id'] ?? '') === 'B2') {
            continue; // the real token.php — kept, or removed by a rollback.
        }
        if (!empty($probe['file']) && is_file($probe['file'])) {
            @unlink($probe['file']);
        }
        if (!empty($probe['dir']) && is_dir($probe['dir'])) {
            @rmdir($probe['dir']);
        }
    }
}

/**
 * A failed gate must roll back the ENTIRE tree, not just stop — a
 * half-installed site a failed check just declared unsafe must not be
 * configurable, and if the failure mode is B2 (PHP served as source),
 * leaving a reachable public/index.php behind hands the site (and the
 * plaintext token.php) to whoever fetches it first. Removing the copied
 * entries closes that hole along with everything else.
 *
 * @param array<string, mixed> $state
 */
function bootstrapRollbackInstall(string $docRoot, array $state): void
{
    $target = $state['install_target'] ?? null;
    if ($target !== null) {
        foreach ((array) ($state['installed_entries'] ?? []) as $entry) {
            bootstrapRemovePath($target . '/' . $entry);
        }
        bootstrapRemovePath($target . '/storage');
        bootstrapRemovePath($target . '/VERSION');
    }
    if (($state['layout'] ?? null) === 'B') {
        bootstrapRemovePath($docRoot . '/.htaccess');
        bootstrapRemovePath($docRoot . '/' . BOOTSTRAP_INDEX_STUB_FILE);
    }
    bootstrapRemovePath($docRoot . '/' . BOOTSTRAP_TOKEN_FILE);
    bootstrapCleanupGateProbes($state);
    if (!empty($state['temp_dir'])) {
        bootstrapRemovePath($state['temp_dir']);
    }
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapFinishGate(array $state, bool $passed): array
{
    $state['gate_report'] = [
        's_checks' => $state['s_checks'] ?? [],
        'b_checks' => $state['b_checks'] ?? [],
        'f_checks' => $state['f_checks'] ?? [],
        'passed' => $passed,
        'layout' => $state['layout'] ?? null,
        'generated_at' => date('c'),
    ];
    $state['gate_passed'] = $passed;
    $state['awaiting_gate_report'] = false;
    $state['done_gate'] = true;

    return $state;
}

/**
 * Handles the browser's reported fetch results for every B/F probe and
 * renders the final verdict. This is the one deliberate place this file
 * trusts a client-reported value — MaintenanceController::installUpdate()
 * establishes the same precedent elsewhere in this codebase (client-side
 * DNS/connectivity checks feed server decisions there too). It's safe here
 * because only the person actually running the install can forge the
 * verdict, forging one grants no privilege (the wizard token still gates
 * admin access afterward), and only their own site is ever harmed by a
 * forged "pass".
 *
 * @param array<string, mixed> $state
 * @param array<int, array<string, mixed>> $results untrusted client-reported
 *        fetch results — {id, status, body} expected per entry, but never
 *        assumed present since this is decoded JSON from the browser
 * @return array<string, mixed>
 */
function bootstrapEvaluateGateReport(string $docRoot, array $state, array $results): array
{
    $byId = [];
    foreach ($results as $r) {
        if (isset($r['id']) && is_string($r['id'])) {
            $byId[$r['id']] = $r;
        }
    }

    $bChecks = [];
    $b1 = $byId['B1'] ?? null;
    $b1Pass = $b1 !== null && bootstrapEvaluateControlProbe(
        (int) $b1['status'],
        (string) $b1['body'],
        bootstrapProbeExpected($state, 'B1')
    );
    $bChecks[] = bootstrapGateResult(
        'B1',
        $b1Pass,
        'Témoin positif (fichier accessible)',
        $b1Pass ? 'Le fichier témoin a été correctement servi.' : "Le fichier témoin n'a pas pu être vérifié — "
            . "impossible de faire confiance aux contrôles suivants."
    );

    if (!$b1Pass) {
        foreach (['B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8'] as $id) {
            if (bootstrapFindProbe($state, $id) !== null) {
                $bChecks[] = bootstrapGateResult($id, false, $id, 'Non vérifié — le témoin positif (B1) a échoué.');
            }
        }
        $state['b_checks'] = $bChecks;
        $state['f_checks'] = [bootstrapGateResult(
            'F1',
            false,
            'Assistant de configuration accessible',
            'Non vérifié — le témoin positif (B1) a échoué.'
        )];
        bootstrapRollbackInstall($docRoot, $state);
        $state = bootstrapFinishGate($state, false);
        $state['gate_aborted_at'] = 'B1';

        return $state;
    }

    $b2 = $byId['B2'] ?? null;
    $b2Pass = $b2 !== null && bootstrapEvaluatePhpExecutionProbe((int) $b2['status'], (string) $b2['body']);
    $bChecks[] = bootstrapGateResult(
        'B2',
        $b2Pass,
        'Exécution PHP (token.php)',
        $b2Pass ? "token.php s'exécute correctement en PHP." : 'token.php a été servi en clair — le serveur n\'exécute '
            . 'pas PHP à cet endroit. Installation dangereuse, abandon immédiat.'
    );

    $allProtectionsPass = true;
    foreach (['B3', 'B4', 'B5', 'B6', 'B7'] as $id) {
        $probeDef = bootstrapFindProbe($state, $id);
        if ($probeDef === null) {
            continue; // not applicable in this layout — skipped by construction.
        }
        $r = $byId[$id] ?? null;
        $pass = $r !== null && bootstrapEvaluateProtectionProbe(
            (int) $r['status'],
            (string) $r['body'],
            (string) $probeDef['expected']
        );
        $allProtectionsPass = $allProtectionsPass && $pass;
        $bChecks[] = bootstrapGateResult(
            $id,
            $pass,
            'Protection : ' . $id,
            $pass ? "Non accessible depuis le web." : 'ACCESSIBLE depuis le web — donnée sensible exposée.'
        );
    }

    $b8Def = bootstrapFindProbe($state, 'B8');
    $b8Pass = true;
    if ($b8Def !== null) {
        $r = $byId['B8'] ?? null;
        $b8Pass = $r !== null && bootstrapEvaluateNoDirectoryListing((int) $r['status'], (string) $r['body']);
        $bChecks[] = bootstrapGateResult(
            'B8',
            $b8Pass,
            'Pas de listage de répertoire',
            $b8Pass ? 'Aucun contenu de répertoire exposé.' : 'Le contenu de storage/ est listé publiquement.'
        );
    }

    $fDef = bootstrapFindProbe($state, 'F1');
    $f = $byId['F1'] ?? null;
    $fPass = $f !== null && $fDef !== null && bootstrapEvaluateFunctionalProbe(
        (int) $f['status'],
        (string) $f['body'],
        (string) $fDef['expected']
    );
    $fChecks = [bootstrapGateResult(
        'F1',
        $fPass,
        "Assistant de configuration accessible",
        $fPass ? "La page d'accueil affiche l'assistant de configuration." : "La page d'accueil n'affiche pas "
            . "l'assistant de configuration attendu."
    )];

    $state['b_checks'] = $bChecks;
    $state['f_checks'] = $fChecks;

    if (!$b2Pass) {
        // Catastrophic — abort immediately regardless of everything else.
        bootstrapRollbackInstall($docRoot, $state);
        $state = bootstrapFinishGate($state, false);
        $state['gate_aborted_at'] = 'B2';

        return $state;
    }

    $gatePassed = $allProtectionsPass && $b8Pass && $fPass;

    if (!$gatePassed) {
        bootstrapRollbackInstall($docRoot, $state);
        $state = bootstrapFinishGate($state, false);

        return $state;
    }

    bootstrapCleanupGateProbes($state);
    $state = bootstrapFinishGate($state, true);

    if (is_dir($state['install_target'] . '/storage/config')) {
        @file_put_contents(
            $state['install_target'] . '/storage/config/install-report.json',
            json_encode($state['gate_report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepToken(string $docRoot, array $state): array
{
    if (empty($state['gate_passed'])) {
        throw new RuntimeException('Le jeton ne peut être confirmé qu\'après la réussite des contrôles.');
    }

    // Written on the first load and typed before anything ran (#719, B1);
    // a rollback since — a failed gate removes it — would have sent the
    // operator back to the token screen, so its absence here is a fault.
    if (bootstrapReadTokenValue($docRoot) === '') {
        throw new RuntimeException('token.php a disparu pendant l\'installation : rechargez la page pour recommencer.');
    }

    $state['token_written'] = true;
    $state['label'] = 'Jeton';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapStepCleanup(string $docRoot, array $state, ?callable $selfDelete = null): array
{
    $selfDelete ??= static fn (): bool => @unlink(__FILE__);

    @unlink($docRoot . '/' . BOOTSTRAP_STATE_FILE);
    @unlink($docRoot . '/' . BOOTSTRAP_ACCESS_FILE);
    $selfDeleted = (bool) $selfDelete();

    // Where the operator goes next — the wizard's restore mode when an
    // archive now waits for it (#719, D), the ordinary wizard otherwise.
    $target = (string) ($state['install_target'] ?? '');
    $state['redirect'] = $target !== '' && is_file($target . '/' . BOOTSTRAP_ARCHIVE_PATH)
        ? BOOTSTRAP_RESTORE_MODE_URL
        : '/setup';

    $state['self_deleted'] = $selfDeleted;
    if (!$selfDeleted) {
        // Never auto-redirect if self-deletion failed — name the file to
        // delete via FTP, then let the operator continue manually.
        $state['cleanup_warning'] = "Impossible de supprimer bootstrap.php automatiquement — supprimez-le manuellement "
            . "via FTP (il ne réinstallera rien tant que le fichier VERSION existe, mais le laisser en place est un "
            . "risque inutile).";
    }
    $state['label'] = 'Nettoyage';
    $state['percent'] = 100;
    $state['done'] = true;

    return $state;
}

function bootstrapAlreadyInstalled(string $docRoot): bool
{
    return is_file($docRoot . '/VERSION') || is_dir($docRoot . '/core');
}

// =============================================================================
// Access: the token first, HTTPS before anything else, then the archive
// (#719, B). Everything the bootstrap hands the setup wizard is frozen in
// Core\Security\BootstrapHandoff — copied here, since this file runs before
// vendor/ exists, and pinned to it by tests/Bootstrap.
// =============================================================================

/**
 * The token's existence, its attempts and lockout, the HTTPS verification
 * and the release an archive asks for — everything decided before step 1,
 * kept apart from the step state so an aborted install keeps none of it
 * and a fresh one inherits nothing.
 *
 * @return array<string, mixed>
 */
function bootstrapReadAccess(string $docRoot): array
{
    return bootstrapReadState($docRoot . '/' . BOOTSTRAP_ACCESS_FILE);
}

/**
 * @param array<string, mixed> $access
 */
function bootstrapWriteAccess(string $docRoot, array $access): bool
{
    return bootstrapWriteState($docRoot . '/' . BOOTSTRAP_ACCESS_FILE, $access);
}

/** The token in token.php, or '' when there is none. */
function bootstrapReadTokenValue(string $docRoot): string
{
    $content = (string) @file_get_contents($docRoot . '/' . BOOTSTRAP_TOKEN_FILE);

    return preg_match('/TOKEN:\s*([0-9a-f]{64})/i', $content, $m) === 1 ? strtolower($m[1]) : '';
}

/**
 * token.php FIRST (#719, B1): written on the very first load, before any
 * other question, and read by the operator over FTP. Returns whether a
 * token exists afterwards — false only when the folder refused the write,
 * in which case the operator creates the file themselves.
 */
function bootstrapEnsureTokenFile(string $docRoot): bool
{
    if (bootstrapReadTokenValue($docRoot) !== '') {
        return true;
    }

    return @file_put_contents(
        $docRoot . '/' . BOOTSTRAP_TOKEN_FILE,
        bootstrapTokenFileContent(bootstrapGenerateToken())
    ) !== false;
}

/** Same computation as Core\Security\BootstrapHandoff::proofValue(). */
function bootstrapProofValue(string $token, int $expiresAt): string
{
    return $expiresAt . '.' . hash_hmac('sha256', BOOTSTRAP_PROOF_CONTEXT . '|' . $expiresAt, $token);
}

/** Same rule as Core\Security\BootstrapHandoff::proofIsValid(). */
function bootstrapProofIsValid(string $cookie, string $token, int $now): bool
{
    if ($token === '' || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $cookie, $parts) !== 1) {
        return false;
    }
    $expiresAt = (int) $parts[1];
    if ($expiresAt <= $now || $expiresAt > $now + BOOTSTRAP_PROOF_LIFETIME_SECONDS) {
        return false;
    }

    return hash_equals(bootstrapProofValue($token, $expiresAt), $cookie);
}

/**
 * Whether this request comes from the operator who typed the token: the
 * proof cookie, checked against the token on disk.
 *
 * @param array<string, mixed> $cookies
 */
function bootstrapIsAuthorized(string $docRoot, array $cookies, int $now): bool
{
    $cookie = $cookies[BOOTSTRAP_PROOF_COOKIE] ?? null;

    return is_string($cookie) && bootstrapProofIsValid($cookie, bootstrapReadTokenValue($docRoot), $now);
}

/**
 * The setup wizard's ladder, unchanged (SetupController::verifyToken()):
 * 4, 6, 8 and 10 failures lock for one minute, five, thirty, then a day.
 */
function bootstrapTokenLockSeconds(int $attempts): int
{
    return match (true) {
        $attempts >= 10 => 86400,
        $attempts >= 8 => 1800,
        $attempts >= 6 => 300,
        $attempts >= 4 => 60,
        default => 0,
    };
}

/**
 * One typed token: accepted with a proof cookie value, refused with the
 * attempt counted, or refused unread while locked. The token is never
 * echoed back, right or wrong.
 *
 * The attempt is recorded **before** the token is compared, and an
 * attempt that cannot be recorded is not compared at all: a folder that
 * refuses `.bootstrap-access.php` would otherwise reset the counter on
 * every request, and the lockout ladder would never engage.
 *
 * @return array{ok: bool, cookie?: string, error?: string, locked_until?: int}
 */
function bootstrapVerifyToken(string $docRoot, string $submitted, int $now): array
{
    $access = bootstrapReadAccess($docRoot);
    $lockedUntil = (int) ($access['token_locked_until'] ?? 0);
    if ($lockedUntil > $now) {
        return [
            'ok' => false,
            'locked_until' => $lockedUntil,
            'error' => 'Trop de tentatives — nouvel essai possible dans '
                . bootstrapDelayLabel($lockedUntil - $now) . '.',
        ];
    }

    $attempts = (int) ($access['token_attempts'] ?? 0) + 1;
    $access['token_attempts'] = $attempts;
    if (!bootstrapWriteAccess($docRoot, $access)) {
        return ['ok' => false, 'error' => "Ce dossier n'est pas accessible en écriture pour PHP : les tentatives ne "
            . "peuvent pas être comptées, le jeton n'est donc pas vérifié. Donnez à PHP le droit d'écrire dans ce "
            . 'dossier, puis rechargez la page.'];
    }

    $token = bootstrapReadTokenValue($docRoot);
    $submitted = strtolower(trim($submitted));
    if ($token !== '' && $submitted !== '' && hash_equals($token, $submitted)) {
        unset($access['token_attempts'], $access['token_locked_until']);
        bootstrapWriteAccess($docRoot, $access);

        return ['ok' => true, 'cookie' => bootstrapProofValue($token, $now + BOOTSTRAP_PROOF_LIFETIME_SECONDS)];
    }

    $lock = bootstrapTokenLockSeconds($attempts);
    if ($lock > 0) {
        $access['token_locked_until'] = $now + $lock;
    }
    bootstrapWriteAccess($docRoot, $access);

    return $lock > 0
        ? [
            'ok' => false,
            'locked_until' => $now + $lock,
            'error' => 'Jeton invalide. Trop de tentatives — nouvel essai possible dans '
                . bootstrapDelayLabel($lock) . '.',
        ]
        : ['ok' => false, 'error' => 'Jeton invalide.'];
}

function bootstrapDelayLabel(int $seconds): string
{
    return match (true) {
        $seconds >= 3600 => (int) ceil($seconds / 3600) . ' h',
        $seconds >= 60 => (int) ceil($seconds / 60) . ' min',
        default => max(1, $seconds) . ' s',
    };
}

/**
 * The host as the browser named it — only a host name and an optional
 * port, never anything a URL could be built from otherwise.
 *
 * @param array<string, mixed> $server
 */
function bootstrapRequestHost(array $server): ?string
{
    $host = $server['HTTP_HOST'] ?? null;

    if (!is_string($host) || preg_match('/^[A-Za-z0-9.-]{1,253}(:\d{1,5})?$/', $host) !== 1) {
        return null;
    }

    return strtolower($host);
}

/**
 * **Blocking, before anything is installed or uploaded (#719, B3):** the
 * site must answer over HTTPS, with a certificate this PHP accepts, from
 * this very folder. A probe file is written here and fetched back at
 * `https://<host>/`; anything else is a message saying what to fix.
 *
 * @return array{ok: bool, detail: string}
 */
function bootstrapCheckSiteHttps(string $docRoot, ?string $host, callable $httpGet): array
{
    if ($host === null) {
        return ['ok' => false, 'detail' => "L'adresse du site n'a pas pu être lue dans la requête. Ouvrez cette page "
            . "par l'adresse publique du site, puis relancez la vérification."];
    }

    // No leading dot: a host denying dotfiles — the posture the gate's
    // own B7 probe wants — would answer 403 and block a sound site.
    $name = 'bootstrap-https-' . bin2hex(random_bytes(8)) . '.txt';
    $content = 'scoutmagic-https-' . bin2hex(random_bytes(16));
    if (@file_put_contents($docRoot . '/' . $name, $content) === false) {
        return ['ok' => false, 'detail' => "Le fichier de vérification n'a pas pu être écrit dans ce dossier."];
    }

    try {
        $result = $httpGet('https://' . $host . '/' . $name);
    } finally {
        @unlink($docRoot . '/' . $name);
    }

    $status = (int) ($result['status'] ?? 0);
    if ($status === 0) {
        return ['ok' => false, 'detail' => "https://{$host}/ ne répond pas en HTTPS : le certificat est absent, "
            . "expiré "
            . "ou ne correspond pas à cette adresse, ou le port 443 est fermé. Activez le certificat HTTPS dans le "
            . "panneau de votre hébergeur (souvent « Let's Encrypt »), attendez qu'il soit émis, puis relancez la "
            . "vérification."];
    }
    if ($status !== 200) {
        return ['ok' => false, 'detail' => "https://{$host}/ répond, mais avec le code HTTP {$status} au lieu de 200. "
            . "Vérifiez que l'adresse HTTPS du site pointe vers ce même dossier (et pas vers une page par défaut de "
            . "l'hébergeur), puis relancez la vérification."];
    }
    if (trim((string) ($result['body'] ?? '')) !== $content) {
        return ['ok' => false, 'detail' => "https://{$host}/ sert un autre dossier que celui-ci : l'adresse HTTPS et "
            . "l'adresse HTTP ne mènent pas au même endroit chez votre hébergeur. "
            . "Faites-les pointer vers ce dossier, "
            . "puis relancez la vérification."];
    }

    return ['ok' => true, 'detail' => "Le site répond en HTTPS à l'adresse https://{$host}/."];
}

/** A published release number — `1.2.3`, never a development build. */
function bootstrapIsReleaseVersion(string $version): bool
{
    return preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $version) === 1;
}

/**
 * What an archive's clear comment says, the way the setup wizard reads it
 * (Core\Maintenance\Portable\PortableArchiveHints): a hint for choosing a
 * release, never a fact — the wizard checks it against the encrypted
 * manifest. Null when the comment is not one of ours.
 *
 * @return array{version: string, site_url: string, created_at: string, kind: string}|null
 */
function bootstrapParseArchiveComment(string $comment): ?array
{
    $document = json_decode($comment, true);
    if (
        !is_array($document)
        || ($document['format'] ?? null) !== BOOTSTRAP_PORTABLE_FORMAT
        || ($document['format_version'] ?? null) !== BOOTSTRAP_PORTABLE_FORMAT_VERSION
        || !is_string($document['scoutmagic_version'] ?? null)
    ) {
        return null;
    }

    $text = static fn (mixed $v): string => is_string($v)
        ? mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $v)), 0, 200)
        : '';

    return [
        'version' => $text($document['scoutmagic_version']),
        'site_url' => $text($document['site_url'] ?? ''),
        'created_at' => $text($document['created_at'] ?? ''),
        'kind' => $text($document['kind'] ?? ''),
    ];
}

/**
 * Where the archive is assembled, and checked unreachable from the web
 * before a byte is accepted (#719, B6). In layout A storage/ sits beside
 * the document root and no URL reaches it; in layout B it is under the
 * document root, protected by the root .htaccess — proved here by
 * fetching a canary through the site. Refused rather than guessed: the
 * caller falls back to sending the archive in the setup wizard.
 *
 * **The verdict is the server's, not the browser's.** It is returned in
 * `state` for the caller to persist: `archive_upload` exists only after a
 * passed check, and {@see bootstrapArchiveAppend()} accepts nothing
 * without it — a direct chunk request after a refusal is refused too.
 *
 * `archive_upload` also names the file being sent — its size, name and
 * modification time, as the browser reports them. A leftover `.part`
 * resumes only for that same file, and only while it is shorter than it:
 * another file starts over, never spliced onto the first one's bytes.
 *
 * @param array<string, mixed> $state
 * @param array<string, mixed> $file  `{size, name, modified}` from the browser
 * @return array{ok: bool, received?: int, detail?: string, state: array<string, mixed>}
 */
function bootstrapArchiveBegin(string $docRoot, array $state, ?string $host, callable $httpGet, array $file): array
{
    $previous = $state['archive_upload'] ?? null;
    unset($state['archive_upload']);
    $refuse = static fn (string $detail): array => ['ok' => false, 'detail' => $detail, 'state' => $state];

    if (empty($state['gate_passed']) || empty($state['install_target'])) {
        return $refuse("L'installation n'est pas terminée : l'archive ne peut pas encore être envoyée.");
    }

    $size = $file['size'] ?? null;
    if (!is_int($size) || $size < 1 || $size > BOOTSTRAP_ARCHIVE_MAX_BYTES) {
        return $refuse("La taille de l'archive choisie n'est pas valable (2 Go au plus).");
    }
    $id = hash('sha256', (string) json_encode([
        is_string($file['name'] ?? null) ? $file['name'] : '',
        $size,
        is_int($file['modified'] ?? null) ? $file['modified'] : 0,
    ]));

    $incoming = $state['install_target'] . '/' . BOOTSTRAP_INCOMING_DIR;
    if (!is_dir($incoming) && !@mkdir($incoming, 0700, true) && !is_dir($incoming)) {
        return $refuse("Le dossier qui reçoit l'archive n'a pas pu être créé.");
    }
    @file_put_contents(dirname($incoming) . '/.htaccess', "Require all denied\n");
    @file_put_contents($incoming . '/.htaccess', "Require all denied\n");

    if (($state['layout'] ?? null) === 'B') {
        if ($host === null) {
            return $refuse("La protection du dossier de réception n'a pas pu être vérifiée.");
        }
        $canary = 'canary-' . bin2hex(random_bytes(8)) . '.txt';
        $content = 'scoutmagic-restore-canary-' . bin2hex(random_bytes(16));
        // Fail closed: a canary that was never written answers 404, which
        // would read as « protected » without anything having been tested.
        if (@file_put_contents($incoming . '/' . $canary, $content) === false) {
            return $refuse("Le témoin de protection n'a pas pu être écrit dans le dossier de "
                . "réception : l'archive ne sera pas envoyée ici. Vous l'enverrez dans l'assistant de configuration.");
        }
        try {
            $probe = $httpGet('https://' . $host . '/' . BOOTSTRAP_INCOMING_DIR . '/' . $canary);
        } finally {
            @unlink($incoming . '/' . $canary);
        }
        $status = (int) ($probe['status'] ?? 0);
        if (!bootstrapEvaluateProtectionProbe($status, (string) ($probe['body'] ?? ''), $content)) {
            return $refuse("Le dossier de réception de l'archive n'est pas prouvé inaccessible depuis "
                . "le web : l'archive ne sera pas envoyée ici. Vous l'enverrez dans l'assistant de configuration.");
        }
    }

    $part = $incoming . '/' . BOOTSTRAP_INCOMING_PART;
    $received = is_file($part) ? (int) filesize($part) : 0;
    $sameFile = is_array($previous) && ($previous['id'] ?? null) === $id;
    if (!$sameFile || $received >= $size) {
        @unlink($part);
        $received = 0;
    }
    $state['archive_upload'] = ['id' => $id, 'size' => $size];

    return ['ok' => true, 'received' => $received, 'state' => $state];
}

/**
 * One chunk, appended strictly in sequence: a chunk for any other offset
 * is refused with the size actually held, so the browser resumes from
 * there. Only after {@see bootstrapArchiveBegin()} passed, and never past
 * the size it announced. The last chunk must complete exactly that size;
 * it then checks the whole file — a zip whose comment names the release
 * just installed — and moves it to the address the wizard reads
 * ({@see BOOTSTRAP_ARCHIVE_PATH}).
 *
 * @param array<string, mixed> $state
 * @return array{status: int, received: int, done?: bool, error?: string}
 */
function bootstrapArchiveAppend(array $state, int $offset, string $data, bool $last): array
{
    $target = (string) ($state['install_target'] ?? '');
    if (empty($state['gate_passed']) || $target === '') {
        return ['status' => 409, 'received' => 0, 'error' => "L'installation n'est pas terminée."];
    }

    $upload = $state['archive_upload'] ?? null;
    $part = $target . '/' . BOOTSTRAP_INCOMING_DIR . '/' . BOOTSTRAP_INCOMING_PART;
    if (!is_array($upload) || !is_int($upload['size'] ?? null) || !is_dir(dirname($part))) {
        return ['status' => 409, 'received' => 0, 'error' => "L'envoi n'a pas été préparé."];
    }
    $expected = $upload['size'];

    $handle = fopen($part, 'c+b');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        return ['status' => 500, 'received' => 0, 'error' => "Le fichier de réception n'a pas pu être ouvert."];
    }

    try {
        $size = (int) fstat($handle)['size'];
        if ($offset !== $size) {
            return ['status' => 409, 'received' => $size, 'error' => 'Fragment hors séquence.'];
        }
        if ($size + strlen($data) > $expected) {
            return ['status' => 413, 'received' => $size, 'error' => "Le fragment dépasse la taille de l'archive "
                . 'choisie.'];
        }
        if ($last && $size + strlen($data) !== $expected) {
            return ['status' => 409, 'received' => $size, 'error' => "Le dernier fragment ne complète pas l'archive "
                . 'choisie.'];
        }
        fseek($handle, $size);
        if ($data !== '' && fwrite($handle, $data) !== strlen($data)) {
            return ['status' => 500, 'received' => $size, 'error' => "Le fragment n'a pas pu être écrit."];
        }
        fflush($handle);
        $received = $size + strlen($data);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    if (!$last) {
        return ['status' => 200, 'received' => $received];
    }

    $zip = new ZipArchive();
    $hints = $zip->open($part, ZipArchive::RDONLY) === true
        ? bootstrapParseArchiveComment((string) $zip->getArchiveComment())
        : null;
    if ($hints !== null || $zip->filename !== '') {
        @$zip->close();
    }
    if ($hints === null) {
        @unlink($part);

        return ['status' => 422, 'received' => 0, 'error' => "Le fichier reçu n'est pas une sauvegarde portable "
            . 'ScoutMagic lisible.'];
    }
    if ($hints['version'] !== (string) ($state['version'] ?? '')) {
        @unlink($part);

        return ['status' => 422, 'received' => 0, 'error' => 'Cette sauvegarde a été écrite par la version '
            . $hints['version'] . ', pas par celle qui vient d\'être installée.'];
    }

    $archive = $target . '/' . BOOTSTRAP_ARCHIVE_PATH;
    if (!@rename($part, $archive)) {
        return ['status' => 500, 'received' => $received, 'error' => "L'archive n'a pas pu être rangée."];
    }

    return ['status' => 200, 'received' => $received, 'done' => true];
}

// =============================================================================
// Default I/O adapters — the only functions that actually touch the
// network. Passed as callables everywhere above so tests can substitute
// their own.
// =============================================================================

/**
 * @param string[] $headers
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function bootstrapDefaultHttpGet(string $url, array $headers = []): array
{
    $headerStr = 'User-Agent: ' . BOOTSTRAP_USER_AGENT . "\r\n";
    foreach ($headers as $h) {
        $headerStr .= $h . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerStr,
            'timeout' => BOOTSTRAP_HTTP_TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 1,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = [];
    // $http_response_header is a magic local PHP populates in this same
    // scope after the http(s):// stream operation above — but only when a
    // response was actually received; a hard connection failure (DNS,
    // refused, etc.) never sets it at all, so this isset() guard is a real
    // runtime necessity despite PHPStan's stream stubs assuming otherwise.
    // @phpstan-ignore-next-line (genuine runtime necessity, see above)
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
        }
    }

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body === false ? '' : $body];
}

function bootstrapDefaultDownloader(string $url, string $destPath): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ' . BOOTSTRAP_USER_AGENT . "\r\n",
            'timeout' => BOOTSTRAP_DOWNLOAD_TIMEOUT,
            'follow_location' => 1,
            'ignore_errors' => true,
        ],
        // Never copy deploy.sh's ssl:verify-certificate no — TLS
        // certificates are always verified.
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    if (!@copy($url, $destPath, $context)) {
        throw new RuntimeException('Le téléchargement a échoué.');
    }
}

function bootstrapDefaultHttpsProbe(): bool
{
    $context = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 8,
            'header' => 'User-Agent: ' . BOOTSTRAP_USER_AGENT . "\r\n",
            'ignore_errors' => true
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    return @file_get_contents('https://api.github.com/', false, $context) !== false;
}

// =============================================================================
// HTTP entry point — the only section that touches superglobals/output.
// =============================================================================

/**
 * Strips absolute filesystem paths and internal-only bookkeeping before a
 * state array is sent to the browser — error output must stay generic (no
 * absolute paths, no open_basedir values, no stack traces). Full detail
 * stays in .bootstrap-state.php for the operator to inspect over FTP.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrapPublicState(array $state): array
{
    $publicKeys = [
        'label', 'percent', 'layout', 'layout_reason', 'version', 'done', 'done_gate',
        'gate_passed', 'gate_aborted_at', 'error', 'failed_step', 's_checks', 'b_checks',
        'f_checks', 'probes', 'awaiting_gate_report', 'token_written',
        'self_deleted', 'cleanup_warning', 'disk_check', 'environment',
        'gate_report', 'redirect', 'release_version',
    ];

    $out = [];
    foreach ($publicKeys as $key) {
        if (array_key_exists($key, $state)) {
            $out[$key] = $state[$key];
        }
    }

    if (isset($out['probes'])) {
        $out['probes'] = array_map(
            static fn (array $p): array => [
                'id' => $p['id'],
                'kind' => $p['kind'],
                'url' => $p['url'],
                'expected' => $p['expected']
            ],
            $out['probes']
        );
    }

    return $out;
}

function bootstrapSanitizeErrorForClient(string $message, string $docRoot): string
{
    $sanitized = str_replace($docRoot, '', $message);
    $parent = dirname($docRoot);
    if ($parent !== $docRoot) {
        $sanitized = str_replace($parent, '', $sanitized);
    }

    return $sanitized;
}

/**
 * @return array{
 *     checks: array<int, array{ok: bool, label: string, detail: string}>,
 *     ok: bool,
 *     layout: array{layout: string, parent: string|null, reason: string}|null,
 *     environment: array<string, mixed>
 * }
 */
function bootstrapPreviewPreflight(string $docRoot): array
{
    $checks = [
        bootstrapCheckLocation($_SERVER),
        bootstrapCheckPhpVersion(),
        bootstrapCheckZipExtension(),
        bootstrapCheckOutboundHttps('bootstrapDefaultHttpsProbe'),
    ];
    $writable = bootstrapProbeWritable($docRoot);
    $checks[] = [
        'ok' => $writable,
        'label' => "Permissions du dossier d'installation",
        'detail' => $writable
            ? 'Accessible en écriture (écriture/lecture/suppression testées).'
            : "Le dossier n'est pas accessible en écriture.",
    ];

    $allOk = !in_array(false, array_column($checks, 'ok'), true);
    $layout = $allOk ? bootstrapSelectLayout($docRoot, 'bootstrapProbeWritable') : null;

    return [
        'checks' => $checks,
        'ok' => $allOk,
        'layout' => $layout,
        'environment' => bootstrapGatherEnvironmentInfo()
    ];
}

function bootstrapHandleStepRequest(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    // See bootstrapSendJson() — every exit point below goes through it
    // rather than a bare echo json_encode(), so a stray PHP warning never
    // corrupts the JSON response. The level found here is the floor it
    // unwinds to: zero when serving a request, and whatever a harness
    // holds when one is running us.
    $buffering = ob_get_level();
    ob_start();
    $input = json_decode((string) file_get_contents('php://input'), true);
    $step = (int) (is_array($input) ? ($input['step'] ?? 0) : 0);
    $lockFile = $docRoot . '/' . BOOTSTRAP_LOCK_FILE;

    if ($step === 1) {
        // Deliberately NOT checking bootstrapAlreadyInstalled() here —
        // bootstrapStepPreflight() already makes the exact same check
        // moments later, INSIDE the try/catch below, where a failure
        // correctly triggers rollback (an abandoned attempt whose files
        // were already copied by an earlier request's step 6 must be torn
        // down, not just refused). A short-circuit here would return the
        // identical error message without ever reaching that rollback,
        // permanently stranding those files with no recovery path.
        if (!bootstrapAcquireLock($lockFile)) {
            bootstrapSendJson(['error' => "Une installation est déjà en cours (ou une tentative précédente n'a pas "
                . "été nettoyée). Réessayez dans 10 minutes, ou supprimez immédiatement le fichier "
                . BOOTSTRAP_LOCK_FILE
                . " via FTP à la racine du site pour débloquer tout de suite."], $buffering);
            return;
        }
    } elseif (!is_file($lockFile)) {
        bootstrapSendJson(['error' => 'Aucune installation en cours. Rechargez la page.'], $buffering);
        return;
    }

    $state = bootstrapReadState($stateFile);
    if ($step === 1) {
        // Decided before the install started (#719): the HTTPS check that
        // gates it, and the release an archive asks for.
        $access = bootstrapReadAccess($docRoot);
        $state['site_https_verified'] = !empty($access['https_verified_at']);
        $state['release_version'] = (string) ($access['release_version'] ?? '');
    }

    try {
        switch ($step) {
            case 1:
                $state = bootstrapStepPreflight($docRoot, $state);
                break;
            case 2:
                $state = bootstrapStepResolve($docRoot, $state);
                break;
            case 3:
                $state = bootstrapStepDownload($docRoot, $state);
                break;
            case 4:
                $state = bootstrapStepExtract($docRoot, $state);
                break;
            case 5:
                $state = bootstrapStepVerifyArtifact($docRoot, $state);
                break;
            case 6:
                $state = bootstrapStepInstall($docRoot, $state);
                break;
            case 7:
                $state = bootstrapStepStorage($docRoot, $state);
                break;
            case 8:
                $state = bootstrapStepFinalize($docRoot, $state);
                break;
            case 9:
                $state = bootstrapStepGatePrepare($docRoot, $state);
                break;
            case 10:
                $state = bootstrapStepToken($docRoot, $state);
                break;
            case 11:
                $state = bootstrapStepCleanup($docRoot, $state);
                bootstrapReleaseLock($lockFile);
                bootstrapSendJson(bootstrapPublicState($state), $buffering);
                return;
            default:
                bootstrapSendJson(['error' => 'Étape inconnue.'], $buffering);
                return;
        }

        if (($state['done_gate'] ?? false) === true && ($state['gate_passed'] ?? false) === false) {
            bootstrapReleaseLock($lockFile);
        }

        bootstrapWriteState($stateFile, $state);
        bootstrapSendJson(bootstrapPublicState($state), $buffering);
    } catch (\Throwable $e) {
        $state['error'] = bootstrapSanitizeErrorForClient($e->getMessage(), $docRoot);
        $state['failed_step'] = $step;
        if (!empty($state['install_target']) && !empty($state['installed_entries'])) {
            // Something was actually copied onto disk — always roll it
            // back on any failure, regardless of which step number the
            // CURRENT request happens to be. Deliberately NOT keyed to
            // "$step is 6-8": a retry can fail at step 1 with "already
            // installed" (bootstrapStepPreflight()'s own guard) against
            // state left behind by an EARLIER request's step 6 that
            // succeeded server-side but whose response the browser never
            // got to process (a network error, or the exact bug
            // bootstrapSendJson() now guards against) — that install_
            // target/installed_entries pair is exactly as real and exactly
            // as much in need of rollback as one from the current request.
            bootstrapRollbackInstall($docRoot, $state);
        } elseif (!empty($state['temp_dir'])) {
            // Nothing installed yet, but the temp dir (steps 2-5: download/
            // extract/verify) must still never be left behind — same "no
            // sys_get_temp_dir(), always removed" guarantee regardless of
            // which step actually failed.
            bootstrapRemoveDirectory($state['temp_dir']);
        }
        bootstrapWriteState($stateFile, $state);
        bootstrapReleaseLock($lockFile);
        bootstrapSendJson(['done' => true, 'error' => $state['error'], 'step' => $step], $buffering);
    }
}

/**
 * The browser can never be sure a step actually failed server-side just
 * because it couldn't parse the response (a network error, or the exact
 * class of bug bootstrapSendJson() now guards against) — the step may
 * well have completed and left real files on disk with the lock still
 * held. Without an explicit way to force a clean rollback, that leaves
 * the install permanently stuck: bootstrapStepPreflight()'s own
 * "already installed" check then refuses every subsequent retry, and
 * there is no FTP-free way out. This performs the same rollback a
 * caught failure would have, from whatever was last durably written to
 * .bootstrap-state.php (state is written on every successful step
 * server-side regardless of whether the client ever saw the response),
 * then clears the lock and state file so a fresh attempt starts clean.
 */
function bootstrapHandleAbortRequest(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();

    try {
        $state = bootstrapReadState($stateFile);
        bootstrapRollbackInstall($docRoot, $state);
        @unlink($stateFile);
        bootstrapReleaseLock($docRoot . '/' . BOOTSTRAP_LOCK_FILE);

        bootstrapSendJson([
            'ok' => true,
            'message' => "Installation abandonnée : les fichiers déjà copiés ont été retirés. Rechargez la page pour "
                . "recommencer.",
        ], $buffering);
    } catch (\Throwable $e) {
        // Even the recovery path itself must degrade to a parseable
        // response rather than a raw fatal error the browser can't read
        // — the JS fallback text already tells the operator to finish
        // the cleanup manually via FTP if this happens.
        bootstrapSendJson([
            'ok' => false,
            'message' => bootstrapSanitizeErrorForClient($e->getMessage(), $docRoot),
        ], $buffering);
    }
}

function bootstrapHandleGateReport(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();
    $lockFile = $docRoot . '/' . BOOTSTRAP_LOCK_FILE;
    $input = json_decode((string) file_get_contents('php://input'), true);
    $results = is_array($input) && is_array($input['results'] ?? null) ? $input['results'] : [];

    $state = bootstrapReadState($stateFile);
    if (empty($state['awaiting_gate_report'])) {
        bootstrapSendJson(['error' => 'Aucun contrôle en attente.'], $buffering);
        return;
    }

    try {
        $state = bootstrapEvaluateGateReport($docRoot, $state, $results);

        if (($state['gate_passed'] ?? false) === false) {
            bootstrapReleaseLock($lockFile);
        }

        bootstrapWriteState($stateFile, $state);
        bootstrapSendJson(bootstrapPublicState($state), $buffering);
    } catch (\Throwable $e) {
        // Same guarantee as bootstrapHandleStepRequest's catch block —
        // an unexpected failure here must never leave the lock file
        // stranded, or every subsequent retry wrongly reports "an
        // install is already in progress" for up to
        // BOOTSTRAP_LOCK_STALE_SECONDS.
        $state['error'] = bootstrapSanitizeErrorForClient($e->getMessage(), $docRoot);
        bootstrapRollbackInstall($docRoot, $state);
        bootstrapWriteState($stateFile, $state);
        bootstrapReleaseLock($lockFile);
        bootstrapSendJson(['done' => true, 'error' => $state['error']], $buffering);
    }
}

/**
 * Reads a JSON request body, `php://input` being the only place it lives.
 *
 * @return array<string, mixed>
 */
function bootstrapJsonInput(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);

    return is_array($input) ? $input : [];
}

/**
 * Whether the browser spoke HTTPS. `X-Forwarded-Proto` is believed here,
 * unlike in the application: a host terminating TLS in front of PHP sets
 * nothing else, and a client forging it only sends its own token in clear.
 *
 * @param array<string, mixed> $server
 */
function bootstrapRequestIsHttps(array $server): bool
{
    $https = strtolower((string) ($server['HTTPS'] ?? ''));
    $forwarded = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

    return ($https !== '' && $https !== 'off')
        || (string) ($server['SERVER_PORT'] ?? '') === '443'
        || $forwarded === 'https';
}

/**
 * The installer over plain http:// (#719, B3): nothing is asked, and the
 * token is never typed — it would cross the network in clear, and the
 * proof cookie with it. The operator is sent to the https:// address,
 * with what to do when it does not open.
 *
 * @param array<string, mixed> $server
 */
function bootstrapRenderHttpsRequired(array $server): void
{
    header('Content-Type: text/html; charset=utf-8');
    $host = bootstrapRequestHost($server);
    $path = (string) ($server['SCRIPT_NAME'] ?? '');
    if (preg_match('#^/[A-Za-z0-9._/-]{0,200}$#', $path) !== 1) {
        $path = '/bootstrap.php';
    }
    $link = $host === null
        ? '<p>Ouvrez cette page par l\'adresse publique du site, en commençant par <code>https://</code>.</p>'
        : '<p><a href="' . bootstrapHtmlEscape('https://' . $host . $path) . '">'
            . bootstrapHtmlEscape('https://' . $host . $path) . '</a></p>';

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ScoutMagic — Installation</title>
<style>
  body {
    font-family: system-ui, -apple-system, sans-serif;
    max-width: 640px; margin: 0 auto; padding: 1.25rem; line-height: 1.5;
  }
  h1 { font-size: 1.4rem; }
  .alert { border-radius: .5rem; padding: 1rem; margin: 1rem 0; background: #fdecea; color: #611a15; }
</style>
</head>
<body>
<h1>ScoutMagic — Installation</h1>
<section id="screen-https-required">
<div class="alert">Cette page est ouverte sans chiffrement (<code>http://</code>). Le jeton d'installation, les mots
de passe et la sauvegarde ne doivent jamais circuler en clair : l'installation se fait uniquement en HTTPS.</div>
<p>Ouvrez plutôt :</p>
{$link}
<p>Si cette adresse ne s'ouvre pas ou affiche un avertissement de sécurité, le certificat HTTPS n'est pas encore
actif. Activez-le dans le panneau de votre hébergeur (souvent « Let's Encrypt »), attendez qu'il soit émis, puis
réessayez.</p>
</section>
</body>
</html>
HTML;
}

/**
 * Sets the proof cookie: HttpOnly, SameSite=Strict, Secure on HTTPS, for
 * {@see BOOTSTRAP_PROOF_LIFETIME_SECONDS} from now.
 */
function bootstrapSetProofCookie(string $token, int $now): void
{
    setcookie(BOOTSTRAP_PROOF_COOKIE, bootstrapProofValue($token, $now + BOOTSTRAP_PROOF_LIFETIME_SECONDS), [
        'expires' => $now + BOOTSTRAP_PROOF_LIFETIME_SECONDS,
        'path' => '/',
        'secure' => bootstrapRequestIsHttps($_SERVER),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * POST ?action=verify-token — `{"token": "…"}`. On success the proof
 * cookie is set ({@see bootstrapSetProofCookie()}), and the setup wizard
 * accepts it in place of a typed token (#719, B2).
 */
function bootstrapHandleVerifyToken(string $docRoot, int $now): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();

    $result = bootstrapVerifyToken($docRoot, (string) (bootstrapJsonInput()['token'] ?? ''), $now);
    if ($result['ok']) {
        bootstrapSetProofCookie(bootstrapReadTokenValue($docRoot), $now);
        bootstrapSendJson(['ok' => true], $buffering);
        return;
    }

    bootstrapSendJson([
        'ok' => false,
        'error' => $result['error'] ?? 'Jeton invalide.',
        'locked_until' => $result['locked_until'] ?? null,
    ], $buffering);
}

/**
 * POST ?action=token-exposed — `{"token": "…"}`: the page read token.php
 * as text, and sends back the token it found there. Open without the
 * proof cookie, so nothing is taken on the browser's word and no request
 * leaves the server: only a caller who actually read the token — which
 * is the exposure — gets the file deleted (#719, B1). A stranger can
 * neither delete it nor make the server fetch anything.
 */
function bootstrapHandleTokenExposed(string $docRoot): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();

    $token = bootstrapReadTokenValue($docRoot);
    $read = strtolower(trim((string) (bootstrapJsonInput()['token'] ?? '')));
    $exposed = $token !== '' && $read !== '' && hash_equals($token, $read);
    if ($exposed) {
        @unlink($docRoot . '/' . BOOTSTRAP_TOKEN_FILE);
    }

    bootstrapSendJson(['exposed' => $exposed], $buffering);
}

/** POST ?action=https-check — the blocking HTTPS verification (#719, B3). */
function bootstrapHandleHttpsCheck(string $docRoot, callable $httpGet, int $now): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();

    $check = bootstrapCheckSiteHttps($docRoot, bootstrapRequestHost($_SERVER), $httpGet);
    $access = bootstrapReadAccess($docRoot);
    if ($check['ok']) {
        $access['https_verified_at'] = $now;
    } else {
        unset($access['https_verified_at']);
    }
    bootstrapWriteAccess($docRoot, $access);

    bootstrapSendJson($check, $buffering);
}

/**
 * POST ?action=choose-archive — `{"version": "1.2.3"}` to install the
 * release an archive names, `{"version": null}` for the latest one.
 */
function bootstrapHandleChooseArchive(string $docRoot): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();

    $version = bootstrapJsonInput()['version'] ?? null;
    $access = bootstrapReadAccess($docRoot);
    if ($version === null) {
        unset($access['release_version']);
    } elseif (!is_string($version) || !bootstrapIsReleaseVersion($version)) {
        bootstrapSendJson(['ok' => false, 'error' => 'Cette sauvegarde vient d\'une version de développement, qui '
            . 'n\'est pas publiée : installez sans sauvegarde, puis envoyez-la dans l\'assistant de '
            . 'configuration.'], $buffering);
        return;
    } else {
        $access['release_version'] = $version;
    }
    bootstrapWriteAccess($docRoot, $access);

    bootstrapSendJson(['ok' => true, 'version' => $access['release_version'] ?? null], $buffering);
}

/** POST ?action=archive-begin — prepares and proves the reception folder (#719, B6). */
function bootstrapHandleArchiveBegin(string $docRoot, string $stateFile, callable $httpGet): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();

    $result = bootstrapArchiveBegin(
        $docRoot,
        bootstrapReadState($stateFile),
        bootstrapRequestHost($_SERVER),
        $httpGet,
        bootstrapJsonInput()
    );
    // The verdict lives in the state, where the chunk requests read it.
    if (!bootstrapWriteState($stateFile, $result['state'])) {
        $result = ['ok' => false, 'detail' => "L'état de l'installation n'a pas pu être enregistré."];
    }
    unset($result['state']);

    bootstrapSendJson($result + ['chunk_bytes' => BOOTSTRAP_CHUNK_BYTES], $buffering);
}

/**
 * POST ?action=archive-chunk&offset=N&last=0|1 — one raw chunk, at most
 * {@see BOOTSTRAP_CHUNK_BYTES}.
 */
function bootstrapHandleArchiveChunk(string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    $buffering = ob_get_level();
    ob_start();

    $data = (string) file_get_contents('php://input', false, null, 0, BOOTSTRAP_CHUNK_BYTES + 1);
    if (strlen($data) > BOOTSTRAP_CHUNK_BYTES) {
        http_response_code(413);
        bootstrapSendJson(['error' => 'Fragment trop grand.'], $buffering);
        return;
    }

    $result = bootstrapArchiveAppend(
        bootstrapReadState($stateFile),
        max(0, (int) ($_GET['offset'] ?? 0)),
        $data,
        (string) ($_GET['last'] ?? '0') === '1'
    );
    http_response_code($result['status']);
    bootstrapSendJson($result, $buffering);
}

/**
 * Discards any output already buffered — a stray PHP warning/notice
 * (an unsuppressed mkdir()/file_put_contents() hitting an edge case, or
 * simply a host with display_errors on) printed ahead of the intended
 * JSON would otherwise land in the same response body and break
 * response.json() client-side with an opaque "did not match the
 * expected pattern" parse error. bootstrapHandleStepRequest() and
 * bootstrapHandleGateReport() both call ob_start() first, and every
 * exit point goes through this instead of a bare echo json_encode(),
 * so the response is always exactly one clean JSON document regardless
 * of what else the host's PHP tried to print along the way.
 *
 * **It CLOSES down to `$floor` and EMPTIES what is left there.** Those
 * are two different operations and the function needs both, because the
 * floor is not always zero.
 *
 * Closing the whole stack is what it used to do, and it destroys buffers
 * this function did not open. Under PHPUnit that is PHPUnit's own:
 * fifteen tests in Tests\Bootstrap\BootstrapRequestHandlersTest reported
 * « Test code or tested code closed output buffers other than its own »
 * on every run since they were written — *risky*, never *failure*, and
 * `phpunit.xml` declared neither failOnRisky nor failOnWarning, so the
 * command exited 0 and CI said nothing. A permanent signal the output
 * does not surface, which is docs/quality-pipeline.md § Reading a green
 * result in its most literal form.
 *
 * But stopping at the floor is not enough on its own, and assuming it
 * was is how this function nearly shipped with the very defect it
 * exists to prevent. **`output_buffering` is on by default** — 4096 in
 * both `php.ini-production` and `php.ini-development`, and the norm on
 * the shared hosting this installer is written for. PHP then opens an
 * implicit buffer before any user code runs, so a handler's floor is 1,
 * not 0, and a warning printed before the handler's own `ob_start()`
 * sits in that implicit buffer. Closing down to the floor leaves it
 * there, `echo json_encode()` appends the JSON behind it, and the
 * response is `Warning: … {"done":true}` — the opaque
 * `response.json()` failure, on the install screen, where the operator
 * has no other channel. Measured, on this file against its previous
 * version:
 *
 *     php -d output_buffering=4096  old:  {"done":true}
 *     php -d output_buffering=4096  new:  LEAKED {"done":true}
 *
 * `ob_clean()` is what closes that gap without re-opening the first
 * one: the floor buffer is emptied, never destroyed, so its owner —
 * PHP's implicit one, or PHPUnit's — still has it afterwards. Emptying
 * it is the function's contract rather than a side effect: the response
 * body is this JSON document and nothing else.
 *
 * Each handler passes the level it found on entry, so "as far down as I
 * opened" and "as far down as there is" stop being the same sentence.
 *
 * @param array<string, mixed> $payload
 * @param int $floor the buffering level to unwind to — the level the
 *        caller found before opening its own
 */
function bootstrapSendJson(array $payload, int $floor = 0): void
{
    while (ob_get_level() > max(0, $floor)) {
        ob_end_clean();
    }

    // What is left at the floor belongs to somebody else, so it is
    // emptied rather than closed — see the docblock above for the host
    // configuration that makes the difference visible.
    if (ob_get_level() > 0) {
        ob_clean();
    }

    echo json_encode($payload);
}

function bootstrapHtmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bootstrapRenderErrorPage(string $message): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" '
        . 'content="width=device-width, initial-scale=1">'
        . '<title>ScoutMagic — Installation</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 '
        . '1rem;line-height:1.5}.alert{background:#fdecea;color:#611a15;border:1px solid '
        . '#f5c2c0;border-radius:.5rem;padding:1rem}</style>'
        . '</head><body><h1>ScoutMagic — Installation</h1><div class="alert">'
        . bootstrapHtmlEscape($message)
        . '</div></body></html>';
}

/**
 * The first screen, before any other question (#719, B1): the token the
 * operator reads in token.php over FTP. Never the token itself — when the
 * file could not be written, the folder is not writable and nothing else
 * can be installed there either: the operator is told to fix that, never
 * to invent a token of their own.
 */
function bootstrapRenderTokenScreen(string $docRoot): void
{
    header('Content-Type: text/html; charset=utf-8');
    $tokenFile = BOOTSTRAP_TOKEN_FILE;
    $hasToken = bootstrapReadTokenValue($docRoot) !== '';
    $access = bootstrapReadAccess($docRoot);
    $lockedUntil = (int) ($access['token_locked_until'] ?? 0);
    $lockedNote = $lockedUntil > time()
        ? '<div class="alert alert-error">Trop de tentatives — nouvel essai possible dans '
            . bootstrapHtmlEscape(bootstrapDelayLabel($lockedUntil - time())) . '.</div>'
        : '';

    $body = $hasToken
        ? <<<HTML
<p>Un fichier <code>{$tokenFile}</code> vient d'être créé dans ce dossier. Ouvrez-le avec votre logiciel FTP
et recopiez ici la suite de 64 caractères qui suit <code>TOKEN:</code>. C'est la preuve que vous avez accès
aux fichiers de ce serveur : rien d'autre ne se fait avant.</p>
{$lockedNote}
<form id="token-form">
  <label for="token-input">Jeton d'installation</label><br>
  <input type="text" id="token-input" autocomplete="off" spellcheck="false"
    style="width:100%;font-family:monospace;padding:.5rem">
  <p><button type="submit" id="token-submit">Continuer</button></p>
</form>
<div id="token-result" class="alert" hidden></div>
HTML
        : <<<HTML
<div class="alert alert-error">Le fichier <code>{$tokenFile}</code> n'a pas pu être créé : ce dossier n'est pas
accessible en écriture pour PHP, et l'installation ne peut rien y faire. Donnez à PHP le droit d'écrire dans ce
dossier (dans le panneau de votre hébergeur, ou par FTP), puis rechargez cette page.</div>
HTML;

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ScoutMagic — Installation</title>
<style>
  body {
    font-family: system-ui, -apple-system, sans-serif;
    max-width: 640px; margin: 0 auto; padding: 1.25rem; line-height: 1.5;
  }
  h1 { font-size: 1.4rem; }
  button {
    min-height: 44px; font-size: 1rem; padding: .6rem 1.2rem; border-radius: .5rem;
    border: none; background: #0d6efd; color: #fff; cursor: pointer;
  }
  .alert { border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
  .alert-error { background: #fdecea; color: #611a15; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<h1>ScoutMagic — Installation</h1>
<section id="screen-token">
{$body}
</section>
<script>
(function () {
  var result = document.getElementById('token-result');
  function show(text) { result.textContent = text; result.className = 'alert alert-error'; result.hidden = false; }

  // token.php must run as PHP and print nothing. A host serving it as text
  // hands the token to anybody: the token read here goes back to the
  // server, which deletes the file once it matches (#719, B1).
  fetch('{$tokenFile}', { cache: 'no-store' }).then(function (res) { return res.text(); }).then(function (text) {
    var found = /TOKEN:\s*([0-9a-f]{64})/i.exec(text);
    if (!found) { return; }
    return fetch('?action=token-exposed', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: found[1] })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.exposed) {
        show("Ce serveur affiche token.php comme du texte au lieu de l'exécuter : le jeton a été supprimé et "
          + "l'installation est impossible ici. Demandez à votre hébergeur d'activer PHP pour ce dossier.");
        var form = document.getElementById('token-form');
        if (form) { form.hidden = true; }
      }
    });
  }).catch(function () { /* unreachable is not exposed */ });

  var form = document.getElementById('token-form');
  if (!form) { return; }
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    fetch('?action=verify-token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: document.getElementById('token-input').value })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.ok) { window.location.reload(); return; }
      show(data.error || 'Jeton invalide.');
    }).catch(function () { show('Erreur réseau — réessayez.'); });
  });
})();
</script>
</body>
</html>
HTML;
}

function bootstrapRenderUi(string $docRoot, string $stateFile): void
{
    header('Content-Type: text/html; charset=utf-8');
    $preview = bootstrapPreviewPreflight($docRoot);
    $stateFileNameJs = json_encode(BOOTSTRAP_STATE_FILE);
    $lockFileNameJs = json_encode(BOOTSTRAP_LOCK_FILE);

    $layoutBlock = '';
    if ($preview['ok'] && $preview['layout'] !== null) {
        $layout = $preview['layout'];
        $optionLabel = $layout['layout'] === 'A'
            ? 'Option A — Installation naturelle'
            : 'Option B — Arborescence unique';
        $paths = $layout['layout'] === 'A'
            ? sprintf(
                'Dossier parent : <code>%s</code><br>Document root (public/) : <code>%s</code>',
                bootstrapHtmlEscape((string) $layout['parent']),
                bootstrapHtmlEscape($docRoot)
            )
            : sprintf('Dossier d\'installation : <code>%s</code>', bootstrapHtmlEscape($docRoot));
        $securityNote = $layout['layout'] === 'A'
            ? "Le document root reste exactement ce qu'il est aujourd'hui — les fichiers sensibles (storage/, core/, "
                . "etc.) sont installés à côté, hors de portée du web."
            : "Tout est installé dans le document root ; un fichier .htaccess unique à la racine protège storage/, "
                . "core/, modules/, config/, schema/, vendor/, tests/ et scripts/, y compris les dossiers créés après "
                . "l'installation.";

        $layoutBlock = '<div class="option-box"><h3>' . $optionLabel . '</h3><p class="paths">' . $paths . '</p>'
            . '<p><strong>Pourquoi ce choix :</strong> ' . bootstrapHtmlEscape($layout['reason']) . '</p>'
            . '<p><strong>Ce que cela signifie pour la sécurité :</strong> '
            . bootstrapHtmlEscape($securityNote)
            . '</p></div>';
    }

    $checksHtml = '';
    foreach ($preview['checks'] as $check) {
        $checksHtml .= '<div class="report-row ' . ($check['ok'] ? 'report-ok' : 'report-fail') . '">'
            . ($check['ok'] ? '✓ ' : '✗ ')
            . bootstrapHtmlEscape($check['label'])
            . ' — '
            . bootstrapHtmlEscape($check['detail'])
            . '</div>';
    }

    $installDisabled = $preview['ok'] ? '' : 'disabled';
    $httpsVerifiedJs = json_encode(!empty(bootstrapReadAccess($docRoot)['https_verified_at']));
    $portableFormatJs = json_encode(BOOTSTRAP_PORTABLE_FORMAT);
    $portableFormatVersionJs = json_encode(BOOTSTRAP_PORTABLE_FORMAT_VERSION);

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ScoutMagic — Installation</title>
<style>
  :root { color-scheme: light; }
  body {
    font-family: system-ui, -apple-system, sans-serif;
    max-width: 640px; margin: 0 auto; padding: 1.25rem; line-height: 1.5;
  }
  h1 { font-size: 1.4rem; }
  h3 { font-size: 1.05rem; margin-top: 0; }
  .option-box { border: 1px solid #ccc; border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
  .paths code { word-break: break-all; }
  button {
    min-height: 44px; min-width: 44px; font-size: 1rem;
    padding: .6rem 1.2rem; border-radius: .5rem; border: none;
    background: #0d6efd; color: #fff; cursor: pointer;
  }
  button:disabled { background: #999; cursor: not-allowed; }
  .report-row { padding: .4rem .2rem; border-bottom: 1px solid #eee; }
  .report-ok { color: #146c2e; }
  .report-fail { color: #b02a37; font-weight: 600; }
  .alert { border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
  .alert-ok { background: #e6f4ea; color: #146c2e; }
  .alert-error { background: #fdecea; color: #611a15; }
  #progress-bar-outer { background: #eee; border-radius: .5rem; height: 1rem; overflow: hidden; margin: 1rem 0; }
  #progress-bar { background: #0d6efd; height: 100%; width: 0; transition: width .3s; }
  #progress-log {
    font-size: .85rem; max-height: 220px; overflow-y: auto;
    background: #f8f9fa; border-radius: .5rem; padding: .75rem;
  }
  #progress-log p { margin: .2rem 0; }
  .log-error { color: #b02a37; font-weight: 600; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<h1>ScoutMagic — Installation</h1>

<section id="screen-https" hidden>
  <h2>Accès en HTTPS</h2>
  <p>Avant d'installer quoi que ce soit, le site doit répondre en HTTPS : les mots de passe, le jeton et la
  sauvegarde que vous enverrez ensuite ne doivent jamais circuler en clair.</p>
  <button id="https-btn">Vérifier l'accès HTTPS</button>
  <div id="https-result" class="alert" hidden></div>
</section>

<section id="screen-confirm" hidden>
  <div id="report-table">{$checksHtml}</div>
  {$layoutBlock}
  <div class="option-box" id="archive-box">
    <h3>Restaurer depuis une sauvegarde ?</h3>
    <p>Si vous remontez le site à partir d'une sauvegarde portable, choisissez-la ici : la version de ScoutMagic
    qui l'a écrite sera installée, puis l'archive envoyée au serveur. À ce stade, seul son en-tête est lu, par
    votre navigateur — rien n'est envoyé ni déchiffré.</p>
    <input type="file" id="archive-file" accept=".zip">
    <dl id="archive-hints" hidden>
      <dt>Site d'origine</dt><dd id="archive-site"></dd>
      <dt>Créée le</dt><dd id="archive-date"></dd>
      <dt>Version de ScoutMagic</dt><dd id="archive-version"></dd>
      <dt>Type</dt><dd id="archive-kind"></dd>
    </dl>
    <p id="archive-error" class="report-fail" hidden></p>
    <p><button id="archive-clear" type="button" hidden>Installer sans sauvegarde</button></p>
  </div>
  <button id="install-btn" {$installDisabled}>Installer</button>
</section>

<section id="screen-progress" hidden>
  <div id="progress-bar-outer"><div id="progress-bar"></div></div>
  <p id="progress-label">Démarrage…</p>
  <div id="progress-log"></div>
</section>

<section id="screen-report" hidden>
  <h2>Contrôles</h2>
  <div id="report-summary" class="alert"></div>
  <div id="report-table"></div>
</section>

<script nonce="">
(function () {
  var STATE_FILE_NAME = {$stateFileNameJs};
  var LOCK_FILE_NAME = {$lockFileNameJs};
  var HTTPS_VERIFIED = {$httpsVerifiedJs};
  var PORTABLE_FORMAT = {$portableFormatJs};
  var PORTABLE_FORMAT_VERSION = {$portableFormatVersionJs};
  /** The archive chosen for a restore, with what its header says — or null. */
  var chosenArchive = null;
  var screens = {
    https: document.getElementById('screen-https'),
    confirm: document.getElementById('screen-confirm'),
    progress: document.getElementById('screen-progress'),
    report: document.getElementById('screen-report')
  };
  var progressBar = document.getElementById('progress-bar');
  var progressLabel = document.getElementById('progress-label');
  var progressLog = document.getElementById('progress-log');
  var installBtn = document.getElementById('install-btn');

  var STEP_LABELS = {
    1: 'Préflight', 2: 'Résolution', 3: 'Téléchargement', 4: 'Extraction',
    5: "Vérification de l'artefact", 6: 'Installation', 7: 'Stockage',
    8: 'Finalisation', 9: 'Contrôles', 10: 'Jeton', 11: 'Nettoyage'
  };

  function showScreen(name) {
    Object.keys(screens).forEach(function (k) { screens[k].hidden = (k !== name); });
  }

  function logLine(text, isError) {
    var p = document.createElement('p');
    p.textContent = text;
    if (isError) { p.className = 'log-error'; }
    progressLog.appendChild(p);
    progressLog.scrollTop = progressLog.scrollHeight;
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (res) { return res.json(); });
  }

  function runProbe(probe) {
    return fetch(probe.url, { cache: 'no-store' }).then(function (res) {
      return res.text().then(function (body) { return { id: probe.id, status: res.status, body: body }; });
    }).catch(function () {
      return { id: probe.id, status: 0, body: '' };
    });
  }

  function setProgress(step, percentWithinStep) {
    var overall = ((step - 1) + (percentWithinStep / 100)) / 11 * 100;
    progressBar.style.width = overall + '%';
    progressLabel.textContent = 'Étape ' + step + '/11 — ' + STEP_LABELS[step];
  }

  // ————— HTTPS first (#719, B3) —————
  // Checked by the server, which fetches a probe file back through
  // https://<this host>/. A page opened over http:// moves to https://
  // once that works, so everything after it travels encrypted.
  function afterHttps() {
    if (window.location.protocol !== 'https:') {
      window.location.href = 'https://' + window.location.host + window.location.pathname;
      return;
    }
    showScreen('confirm');
  }

  var httpsBtn = document.getElementById('https-btn');
  var httpsResult = document.getElementById('https-result');
  httpsBtn.addEventListener('click', function () {
    httpsBtn.disabled = true;
    httpsResult.hidden = true;
    postJson('?action=https-check', {}).then(function (data) {
      httpsBtn.disabled = false;
      httpsResult.textContent = data.detail || '';
      httpsResult.className = 'alert ' + (data.ok ? 'alert-ok' : 'alert-error');
      httpsResult.hidden = false;
      if (data.ok) { setTimeout(afterHttps, 1200); }
    }).catch(function () {
      httpsBtn.disabled = false;
      httpsResult.textContent = 'Erreur réseau — relancez la vérification.';
      httpsResult.className = 'alert alert-error';
      httpsResult.hidden = false;
    });
  });

  // ————— The archive's header, read here (#719, B4) —————
  // The last 65 557 bytes hold the end-of-central-directory record and
  // the zip comment, which is all that is read: nothing is uploaded or
  // decrypted before the operator confirms. The comment is a hint, shown
  // as text and never as markup; the wizard checks it against the
  // encrypted manifest.
  function readArchiveHeader(file) {
    var tailSize = Math.min(file.size, 65557);
    return file.slice(file.size - tailSize).arrayBuffer().then(function (buffer) {
      var bytes = new Uint8Array(buffer);
      for (var i = bytes.length - 22; i >= 0; i--) {
        if (bytes[i] !== 0x50 || bytes[i + 1] !== 0x4b || bytes[i + 2] !== 0x05 || bytes[i + 3] !== 0x06) {
          continue;
        }
        var length = bytes[i + 20] | (bytes[i + 21] << 8);
        if (i + 22 + length > bytes.length) { continue; }
        var doc;
        try {
          doc = JSON.parse(new TextDecoder('utf-8').decode(bytes.subarray(i + 22, i + 22 + length)));
        } catch (e) {
          return null;
        }
        if (!doc || doc.format !== PORTABLE_FORMAT || doc.format_version !== PORTABLE_FORMAT_VERSION
          || typeof doc.scoutmagic_version !== 'string') {
          return null;
        }
        return doc;
      }
      return null;
    });
  }

  var archiveFile = document.getElementById('archive-file');
  var archiveHints = document.getElementById('archive-hints');
  var archiveError = document.getElementById('archive-error');
  var archiveClear = document.getElementById('archive-clear');

  function setArchive(choice) {
    chosenArchive = choice;
    archiveHints.hidden = choice === null;
    archiveClear.hidden = choice === null;
    installBtn.textContent = choice === null
      ? 'Installer'
      : 'Installer la version ' + choice.version + ' et déposer cette sauvegarde';
  }

  archiveFile.addEventListener('change', function () {
    archiveError.hidden = true;
    var file = archiveFile.files && archiveFile.files[0];
    if (!file) { setArchive(null); return; }
    readArchiveHeader(file).then(function (doc) {
      if (doc === null) {
        setArchive(null);
        archiveError.textContent = "Ce fichier n'est pas une sauvegarde portable ScoutMagic de ce format.";
        archiveError.hidden = false;
        return;
      }
      if (!/^\d{1,4}\.\d{1,4}\.\d{1,4}$/.test(doc.scoutmagic_version)) {
        setArchive(null);
        archiveError.textContent = 'Cette sauvegarde vient de la version de développement '
          + doc.scoutmagic_version + ", qui n'est pas publiée : installez sans sauvegarde, puis "
          + "envoyez-la dans l'assistant de configuration.";
        archiveError.hidden = false;
        return;
      }
      var created = new Date(String(doc.created_at || ''));
      document.getElementById('archive-site').textContent = String(doc.site_url || 'Non indiqué');
      document.getElementById('archive-date').textContent = isNaN(created.getTime())
        ? 'Non indiquée' : created.toLocaleString('fr-BE');
      document.getElementById('archive-version').textContent = doc.scoutmagic_version;
      document.getElementById('archive-kind').textContent = doc.kind === 'remote'
        ? 'Automatique, hors site' : 'Manuelle';
      setArchive({ file: file, version: doc.scoutmagic_version });
    }).catch(function () {
      setArchive(null);
      archiveError.textContent = "L'en-tête de ce fichier n'a pas pu être lu.";
      archiveError.hidden = false;
    });
  });

  archiveClear.addEventListener('click', function () {
    archiveFile.value = '';
    setArchive(null);
  });

  function runInstall() {
    installBtn.disabled = true;
    postJson('?action=choose-archive', { version: chosenArchive ? chosenArchive.version : null }).then(function (data) {
      if (!data.ok) {
        installBtn.disabled = false;
        archiveError.textContent = data.error || 'Choix impossible.';
        archiveError.hidden = false;
        return;
      }
      showScreen('progress');
      runStep(1);
    }).catch(function () {
      installBtn.disabled = false;
      archiveError.textContent = 'Erreur réseau — réessayez.';
      archiveError.hidden = false;
    });
  }

  // ————— Sending the archive, resumable (#719, B6) —————
  // After the gate, so the folder it lands in is the installed site's own;
  // in chunks small enough for any post_max_size, each one appended only
  // at the offset the server holds, so a dropped request resumes. When
  // the server cannot prove the folder unreachable from the web, nothing
  // is sent and the wizard takes the upload instead.
  function uploadArchive() {
    var file = chosenArchive.file;
    logLine("Préparation de l'envoi de la sauvegarde…");
    var identity = { size: file.size, name: file.name, modified: file.lastModified };
    return postJson('?action=archive-begin', identity).then(function (begin) {
      if (!begin.ok) {
        logLine(begin.detail || "L'archive ne peut pas être envoyée ici.", true);
        return;
      }
      var chunk = begin.chunk_bytes || 2097152;
      var lastReported = -1;
      var retries = 0;

      function next(offset) {
        // The server resumes only a shorter copy of this same file: an
        // offset at or past its end is an error, never a silent success.
        if (offset >= file.size) { return Promise.reject(new Error('reprise incohérente')); }
        var end = Math.min(offset + chunk, file.size);
        var last = end >= file.size ? 1 : 0;
        return fetch('?action=archive-chunk&offset=' + offset + '&last=' + last, {
          method: 'POST',
          headers: { 'Content-Type': 'application/octet-stream' },
          body: file.slice(offset, end)
        }).then(function (res) {
          return res.json().then(function (data) { return { status: res.status, data: data }; });
        })
          .then(function (reply) {
            if (reply.status === 409 && retries < 5) {
              retries++;
              return next(reply.data.received || 0);
            }
            if (reply.status !== 200) {
              throw new Error(reply.data.error || ('Erreur HTTP ' + reply.status));
            }
            retries = 0;
            var percent = Math.floor((reply.data.received / file.size) * 100);
            if (percent >= lastReported + 10 || reply.data.done) {
              lastReported = percent;
              progressLabel.textContent = 'Envoi de la sauvegarde — ' + percent + ' %';
            }
            if (reply.data.done) {
              logLine('Sauvegarde déposée sur le serveur.');
              return;
            }
            return next(reply.data.received);
          });
      }

      return next(begin.received || 0);
    }).catch(function (err) {
      logLine("L'envoi de la sauvegarde a échoué (" + (err && err.message ? err.message : err)
        + ") : vous l'enverrez dans l'assistant de configuration.", true);
    });
  }

  function runStep(step) {
    setProgress(step, 0);
    postJson('?action=step', { step: step }).then(function (data) {
      // The proof lapsed: nothing ran, and nothing was rolled back.
      if (data.auth_required) {
        logLine(data.error + " L'installation n'a pas été annulée.", true);
        return;
      }
      if (data.error && !data.done) {
        logLine('Erreur : ' + data.error, true);
        return;
      }
      if (data.done && data.error) {
        logLine('Échec à l\\'étape ' + step + ' : ' + data.error, true);
        showReport(data, false);
        return;
      }
      setProgress(step, 100);
      logLine((STEP_LABELS[step] || ('Étape ' + step)) + ' — terminé.');

      if (step === 9) { handleGate(data); return; }
      if (step === 11) { showReport(data, true); return; }
      if (step === 10 && chosenArchive) {
        uploadArchive().then(function () { runStep(11); });
        return;
      }
      runStep(step + 1);
    }).catch(function (err) {
      logLine('Erreur réseau : ' + err, true);
      showAbortRecovery();
    });
  }

  function handleGate(data) {
    if (data.done_gate) {
      showReport(data, false);
      return;
    }
    logLine('Vérification des protections depuis le navigateur…');
    var probes = data.probes || [];
    Promise.all(probes.map(runProbe)).then(function (results) {
      // These probes deliberately fetch paths that must be denied
      // (storage/, dotfiles, etc.) to confirm .htaccess actually blocks
      // them — the browser logs each failed fetch to the console as an
      // "error" on its own, outside this code's control, even though
      // runProbe() itself handles the failure normally. Expected, not a
      // bug — this pause just holds the screen here for a moment so
      // there's time to see/copy them before moving on, if needed.
      logLine('Contrôles envoyés — pause de quelques secondes avant de continuer '
        + '(si la console du navigateur affiche des erreurs ici, c\\'est normal : '
        + 'elles viennent des vérifications ci-dessus).');
      return new Promise(function (resolve) { setTimeout(resolve, 8000); }).then(function () {
        return postJson('?action=gate-report', { results: results });
      });
    }).then(function (gateData) {
      if (gateData.gate_passed) {
        logLine('Contrôles réussis.');
        runStep(10);
      } else {
        logLine('Échec des contrôles de sécurité — annulation de l\\'installation.', true);
        showReport(gateData, false);
      }
    }).catch(function (err) {
      logLine('Erreur réseau : ' + err, true);
      showAbortRecovery();
    });
  }

  // The browser can never be sure a step genuinely failed server-side
  // just because the request errored or its response couldn't be parsed
  // — files may already be on disk with the install lock still held,
  // and bootstrapStepPreflight()'s own "already installed" check would
  // then refuse every retry with no way out. ?action=abort forces the
  // same rollback a caught failure would have, from whatever was last
  // durably written to .bootstrap-state.php.
  function attachAbortButton(summary) {
    var abortBtn = document.createElement('button');
    abortBtn.textContent = "Annuler l'installation et nettoyer";
    abortBtn.addEventListener('click', function () {
      abortBtn.disabled = true;
      postJson('?action=abort', {}).then(function (data) {
        if (data.ok === false) {
          summary.textContent = (data.message || 'Le nettoyage automatique a échoué.')
            + ' Supprimez manuellement via FTP les fichiers déjà copiés, ainsi que '
            + STATE_FILE_NAME + ' et ' + LOCK_FILE_NAME + ', puis rechargez cette page.';
          summary.className = 'alert alert-error';
          abortBtn.disabled = false;
          return;
        }
        summary.textContent = (data.message || 'Nettoyage effectué.') + ' Rechargement…';
        summary.className = 'alert alert-ok';
        setTimeout(function () { window.location.reload(); }, 2500);
      }).catch(function () {
        summary.textContent = 'Le nettoyage automatique a également échoué. '
          + 'Supprimez manuellement via FTP les fichiers déjà copiés, ainsi que '
          + STATE_FILE_NAME + ' et ' + LOCK_FILE_NAME + ', puis rechargez cette page.';
        summary.className = 'alert alert-error';
        abortBtn.disabled = false;
      });
    });
    summary.insertAdjacentElement('afterend', abortBtn);
  }

  function showAbortRecovery() {
    showScreen('report');
    document.getElementById('report-table').innerHTML = '';
    var summary = document.getElementById('report-summary');
    summary.textContent = "La réponse du serveur n'a pas pu être interprétée "
      + "(problème réseau, ou le serveur a renvoyé une réponse inattendue). "
      + "Des fichiers ont peut-être déjà été copiés. Cliquez ci-dessous pour "
      + "annuler proprement cette tentative avant de réessayer.";
    summary.className = 'alert alert-error';
    attachAbortButton(summary);
  }

  function showReport(data, passed) {
    showScreen('report');
    var container = document.getElementById('report-table');
    container.innerHTML = '';
    var groups = [
      ['Contrôles serveur', data.s_checks || (data.gate_report && data.gate_report.s_checks) || []],
      ['Contrôles navigateur', data.b_checks || (data.gate_report && data.gate_report.b_checks) || []],
      ['Contrôle fonctionnel', data.f_checks || (data.gate_report && data.gate_report.f_checks) || []]
    ];
    groups.forEach(function (group) {
      var name = group[0], items = group[1];
      if (!items.length) { return; }
      var h = document.createElement('h3');
      h.textContent = name;
      container.appendChild(h);
      items.forEach(function (item) {
        var row = document.createElement('div');
        row.className = 'report-row ' + (item.ok ? 'report-ok' : 'report-fail');
        row.textContent = (item.ok ? '✓ ' : '✗ ') + item.label + ' — ' + item.detail;
        container.appendChild(row);
      });
    });

    var summary = document.getElementById('report-summary');
    var effectivePassed = passed && data.gate_passed !== false;
    if (effectivePassed && data.self_deleted === false) {
      // Never auto-redirect when bootstrap.php couldn't delete itself —
      // name the file to remove via FTP, then let the operator continue
      // manually rather than silently leaving it reachable.
      summary.textContent = (data.cleanup_warning
          || 'Installation terminée avec succès, mais bootstrap.php n\\'a pas pu '
            + 'se supprimer automatiquement.')
        + ' Une fois supprimé (ou si vous préférez continuer sans le faire), '
        + 'cliquez ci-dessous.';
      summary.className = 'alert alert-warning';
      var continueBtn = document.createElement('button');
      continueBtn.textContent = "Continuer vers l'assistant de configuration";
      // Straight to /setup rather than "/" + relying on the app's own
      // not-initialized redirect: the whole point of the .htaccess
      // hardening earlier in this file is that some hosts intercept the
      // bare root path before a PHP request is ever made, so bouncing
      // through "/" first is exactly the ambiguity to avoid here.
      continueBtn.addEventListener('click', function () { window.location.href = data.redirect || '/setup'; });
      summary.insertAdjacentElement('afterend', continueBtn);
    } else if (effectivePassed) {
      summary.textContent = 'Installation terminée avec succès. La page suivante reconnaît le jeton que '
        + 'vous venez de saisir. Redirection automatique dans quelques secondes.';
      summary.className = 'alert alert-ok';
      setTimeout(function () { window.location.href = data.redirect || '/setup'; }, 5000);
    } else {
      // For a plain step failure (steps 1-8/10), there are no s_checks/
      // b_checks/f_checks rows at all — data.error is the ONLY place the
      // actual reason lives, and the progress screen's log line for it
      // just got hidden by the switch to this screen. Show it directly.
      var reasonSuffix = data.error ? (' Raison : ' + data.error) : '';
      summary.textContent = 'L\\'installation a été annulée et les fichiers déjà '
        + 'copiés ont été retirés.' + reasonSuffix
        + ' Corrigez le point signalé ci-dessus puis rechargez cette page pour réessayer.';
      summary.className = 'alert alert-error';
    }
  }

  if (installBtn) { installBtn.addEventListener('click', runInstall); }
  if (HTTPS_VERIFIED && window.location.protocol === 'https:') {
    showScreen('confirm');
  } else {
    showScreen('https');
  }
})();
</script>
</body>
</html>
HTML;
}

/**
 * @param string|null $docRoot where the installer runs — its own folder;
 *                             a test passes a temporary one
 */
function bootstrapMain(?string $docRoot = null): void
{
    $docRoot ??= __DIR__;
    $stateFile = $docRoot . '/' . BOOTSTRAP_STATE_FILE;

    if (bootstrapAlreadyInstalled($docRoot) && !is_file($stateFile)) {
        bootstrapRenderErrorPage(
            'Ce dossier contient déjà une installation ScoutMagic (fichier VERSION ou dossier core/ présent). '
            . 'Utilisez Configuration > Maintenance pour mettre à jour, ou retirez '
            . 'manuellement les fichiers existants '
            . 'avant de relancer bootstrap.php.'
        );
        return;
    }

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $now = time();

    // HTTPS before the token (#719, B3): over http:// the token would be
    // typed in clear, and its proof cookie set without the Secure flag.
    if (!bootstrapRequestIsHttps($_SERVER)) {
        if ($action !== '') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'done' => true,
                'error' => 'Cette page doit être ouverte en HTTPS (https://).',
            ]);
            return;
        }
        bootstrapEnsureTokenFile($docRoot);
        bootstrapRenderHttpsRequired($_SERVER);
        return;
    }

    // The token before anything else (#719, B1). These two are the only
    // actions open without it: typing it, and reporting it exposed.
    if ($action === 'verify-token' && $method === 'POST') {
        bootstrapHandleVerifyToken($docRoot, $now);
        return;
    }
    if ($action === 'token-exposed' && $method === 'POST') {
        bootstrapHandleTokenExposed($docRoot);
        return;
    }

    if (!bootstrapIsAuthorized($docRoot, $_COOKIE, $now)) {
        if ($action !== '') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'done' => true,
                'auth_required' => true,
                'error' => "Jeton d'installation requis — rechargez la page.",
            ]);
            return;
        }
        bootstrapEnsureTokenFile($docRoot);
        bootstrapRenderTokenScreen($docRoot);
        return;
    }

    // A sliding proof: two hours since the operator's last request, not
    // since the token was typed — a 2 GB upload on a slow line outlasts
    // the latter. The expiry is inside the signed value, so it is re-signed.
    bootstrapSetProofCookie(bootstrapReadTokenValue($docRoot), $now);

    if ($action === 'https-check' && $method === 'POST') {
        bootstrapHandleHttpsCheck($docRoot, 'bootstrapDefaultHttpGet', $now);
        return;
    }

    if ($action === 'choose-archive' && $method === 'POST') {
        bootstrapHandleChooseArchive($docRoot);
        return;
    }

    if ($action === 'archive-begin' && $method === 'POST') {
        bootstrapHandleArchiveBegin($docRoot, $stateFile, 'bootstrapDefaultHttpGet');
        return;
    }

    if ($action === 'archive-chunk' && $method === 'POST') {
        bootstrapHandleArchiveChunk($stateFile);
        return;
    }

    if ($action === 'step' && $method === 'POST') {
        bootstrapHandleStepRequest($docRoot, $stateFile);
        return;
    }

    if ($action === 'gate-report' && $method === 'POST') {
        bootstrapHandleGateReport($docRoot, $stateFile);
        return;
    }

    if ($action === 'abort' && $method === 'POST') {
        bootstrapHandleAbortRequest($docRoot, $stateFile);
        return;
    }

    bootstrapRenderUi($docRoot, $stateFile);
}

if (!defined('BOOTSTRAP_TEST')) {
    bootstrapMain();
}
