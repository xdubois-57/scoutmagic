<?php

declare(strict_types=1);

/**
 * ScoutMagic — standalone bootstrap installer.
 *
 * Uploaded via FTP to an empty web folder. Downloads the latest published
 * GitHub release, installs it into whichever of the two supported layouts
 * fits the host, runs a full acceptance gate proving both functionality and
 * non-exposure of storage/, writes token.php only once every check passes,
 * deletes itself, and redirects to the (now token-gated) setup wizard.
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
function bootstrap_check_location(array $server): array
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
function bootstrap_check_php_version(string $version = PHP_VERSION): array
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
function bootstrap_check_zip_extension(): array
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
function bootstrap_check_outbound_https(callable $prober): array
{
    $ok = (bool) $prober();

    return [
        'ok' => $ok,
        'label' => 'Connexion sortante HTTPS',
        'detail' => $ok
            ? 'Connexion vers GitHub établie.'
            : "Impossible d'établir une connexion HTTPS sortante vers GitHub. Vérifiez que votre hébergeur autorise les connexions sortantes.",
    ];
}

/**
 * Purely informational — server software, memory_limit, max_execution_time,
 * open_basedir, posix availability never gate the install. The acceptance
 * gate settles what these were only standing in for.
 *
 * @return array<string, mixed>
 */
function bootstrap_gather_environment_info(): array
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
function bootstrap_probe_writable(string $dir): bool
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
function bootstrap_looks_like_system_root(string $dir): bool
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
function bootstrap_select_layout(string $docRoot, callable $writableProbe): array
{
    $parent = dirname($docRoot);

    $parentContainsDocRoot = $parent !== $docRoot
        && strncmp($docRoot, rtrim($parent, '/') . '/', strlen(rtrim($parent, '/') . '/')) === 0;

    if (!$parentContainsDocRoot) {
        return [
            'layout' => 'B',
            'parent' => null,
            'reason' => "Le dossier parent ne contient pas physiquement le document root (hébergement chrooté ou lien symbolique) — installation en une seule arborescence.",
        ];
    }

    if (bootstrap_looks_like_system_root($parent)) {
        return [
            'layout' => 'B',
            'parent' => null,
            'reason' => 'Le dossier parent ressemble à une racine système — installation en une seule arborescence par sécurité.',
        ];
    }

    if (!$writableProbe($parent)) {
        return [
            'layout' => 'B',
            'parent' => null,
            'reason' => "Le dossier parent du document root n'est pas accessible en écriture — installation en une seule arborescence dans le document root.",
        ];
    }

    return [
        'layout' => 'A',
        'parent' => $parent,
        'reason' => 'Le dossier parent du document root est accessible en écriture et le contient physiquement — installation naturelle.',
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
function bootstrap_resolve_archive_url(array $release): array
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
function bootstrap_fetch_latest_release(callable $httpGet): array
{
    $url = 'https://api.github.com/repos/' . BOOTSTRAP_REPO_OWNER . '/' . BOOTSTRAP_REPO_NAME . '/releases/latest';
    $attempts = 0;
    $lastError = null;

    while ($attempts < 3) {
        $attempts++;
        $result = $httpGet($url, ['Accept: application/vnd.github+json']);

        if ($result['status'] === 403 && ($result['headers']['x-ratelimit-remaining'] ?? '1') === '0') {
            $reset = (int) ($result['headers']['x-ratelimit-reset'] ?? 0);
            throw new RuntimeException(
                'Limite de requêtes GitHub atteinte' . ($reset > 0 ? ' — réessayez après ' . date('H:i:s', $reset) . '.' : '.')
            );
        }

        if ($result['status'] === 404) {
            throw new RuntimeException('Aucune version publiée n\'a été trouvée pour ce dépôt.');
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

function bootstrap_download_with_retry(string $url, string $destPath, callable $downloader): void
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
function bootstrap_check_disk_space(string $dir, int $declaredArtifactSize, ?callable $freeSpaceFn = null): array
{
    $freeSpaceFn ??= 'disk_free_space';

    if (!function_exists('disk_free_space') && $freeSpaceFn === 'disk_free_space') {
        return ['ok' => true, 'degraded' => true, 'label' => 'Espace disque', 'detail' => 'Impossible de vérifier l\'espace disque disponible (fonction indisponible) — poursuite sans garantie.'];
    }

    $free = @$freeSpaceFn($dir);
    if ($free === false || $free === null) {
        return ['ok' => true, 'degraded' => true, 'label' => 'Espace disque', 'detail' => "Impossible de déterminer l'espace disque disponible — poursuite sans garantie."];
    }

    if ($declaredArtifactSize <= 0) {
        return ['ok' => true, 'degraded' => true, 'label' => 'Espace disque', 'detail' => "Taille de l'archive inconnue — vérification ignorée."];
    }

    $needed = $declaredArtifactSize * 3;
    $ok = $free >= $needed;

    return [
        'ok' => $ok,
        'degraded' => false,
        'label' => 'Espace disque',
        'detail' => $ok
            ? sprintf('%.1f Mo disponibles.', $free / 1048576)
            : sprintf('Seulement %.1f Mo disponibles, %.1f Mo requis (3x la taille de l\'archive).', $free / 1048576, $needed / 1048576),
    ];
}

// =============================================================================
// Archive extraction
// =============================================================================

function bootstrap_is_zip_slip(string $entryName): bool
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

function bootstrap_extract_zip_safely(string $zipPath, string $destDir): void
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
        if (bootstrap_is_zip_slip($name)) {
            $zip->close();
            throw new RuntimeException("L'archive contient une entrée dangereuse (chemin hors de la zone d'extraction) : {$name}");
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
function bootstrap_resolve_archive_root(string $extractedDir, string $sourceType): string
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
function bootstrap_verify_artifact(string $sourceRoot): array
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
function bootstrap_copy_tree(string $source, string $dest, array $excludeTopLevel = []): array
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
        bootstrap_copy_entry($source . '/' . $name, $dest . '/' . $name);
        $copied[] = $name;
    }

    return $copied;
}

function bootstrap_copy_entry(string $source, string $dest): void
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

function bootstrap_remove_path(string $path): void
{
    if (is_link($path)) {
        @unlink($path);
    } elseif (is_dir($path)) {
        bootstrap_remove_directory($path);
    } elseif (file_exists($path)) {
        @unlink($path);
    }
}

function bootstrap_remove_directory(string $dir): void
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
 * directory (bootstrap_index_stub_content()) — never rewritten across
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
function bootstrap_htaccess_content(): string
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

# Deny dotfiles anywhere in the tree.
RewriteRule (^|/)\. - [F,L]

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
HTACCESS;
}

/**
 * The one file bootstrap.php places outside the repository's own tree
 * (ARCHITECTURE.md §12) — see bootstrap_htaccess_content()'s own comment
 * for why a same-directory PHP stub is required at all. __DIR__ inside the
 * required file always reflects its own real location regardless of how
 * it's included, so public/index.php's own path resolution (AppConfig,
 * Twig template dir, SecretManager, etc. — all built from its own
 * __DIR__) needs no changes whatsoever to be required from here.
 */
function bootstrap_index_stub_content(): string
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
function bootstrap_create_storage_dirs(string $basePath): array
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
function bootstrap_write_version(string $basePath, string $version): void
{
    file_put_contents($basePath . '/VERSION', $version . "\n");
}

function bootstrap_generate_token(): string
{
    return bin2hex(random_bytes(32));
}

function bootstrap_token_file_content(string $token): string
{
    return "<?php /* TOKEN: {$token} */\n";
}

// =============================================================================
// Acceptance gate — Part 1: server-side checks (S1-S8)
// =============================================================================

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_gate_result(string $id, bool $ok, string $label, string $detail): array
{
    return ['id' => $id, 'ok' => $ok, 'label' => $label, 'detail' => $detail];
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s1(string $basePath): array
{
    $path = $basePath . '/VERSION';
    $ok = is_file($path) && trim((string) @file_get_contents($path)) !== '';

    return bootstrap_gate_result('S1', $ok, 'Fichier VERSION', $ok ? 'Présent et lisible.' : 'Manquant ou vide.');
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s2(string $basePath): array
{
    $ok = is_file($basePath . '/vendor/autoload.php');

    return bootstrap_gate_result('S2', $ok, 'Dépendances installées', $ok ? 'vendor/autoload.php présent.' : 'vendor/autoload.php manquant.');
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s3(string $publicDir): array
{
    $ok = is_file($publicDir . '/index.php');

    return bootstrap_gate_result('S3', $ok, "Point d'entrée applicatif", $ok ? 'index.php présent.' : 'index.php manquant.');
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s4(string $basePath): array
{
    $ok = is_file($basePath . '/schema/core.sql');

    return bootstrap_gate_result('S4', $ok, 'Schéma de base de données', $ok ? 'schema/core.sql présent.' : 'schema/core.sql manquant.');
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s5(string $basePath): array
{
    $missing = [];
    foreach (BOOTSTRAP_STORAGE_SUBDIRS as $sub) {
        if (!is_dir($basePath . '/storage/' . $sub)) {
            $missing[] = $sub;
        }
    }
    $ok = empty($missing);

    return bootstrap_gate_result('S5', $ok, 'Dossiers de stockage', $ok ? 'Tous les sous-dossiers storage/ sont créés.' : 'Sous-dossiers manquants : ' . implode(', ', $missing) . '.');
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s6(string $basePath): array
{
    $path = $basePath . '/storage/keys';
    $ok = is_dir($path) && is_writable($path);
    $mode = is_dir($path) ? substr(sprintf('%o', fileperms($path)), -4) : 'n/a';

    return bootstrap_gate_result('S6', $ok, 'Permissions storage/keys', $ok ? "Accessible en écriture (mode {$mode})." : "Non accessible en écriture (mode {$mode}).");
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s7(string $extractedRoot): array
{
    $ok = !is_file($extractedRoot . '/.htaccess');

    return bootstrap_gate_result('S7', $ok, "Absence de .htaccess dans l'artefact", $ok ? "Aucun .htaccess à la racine de l'archive." : "L'archive contenait un .htaccess à sa racine — elle ne devrait jamais en fournir un.");
}

/**
 * @return array{id: string, ok: bool, label: string, detail: string}
 */
function bootstrap_check_s8(string $tempDir): array
{
    $ok = !is_dir($tempDir);

    return bootstrap_gate_result('S8', $ok, 'Nettoyage du dossier temporaire', $ok ? 'Dossier temporaire supprimé.' : 'Le dossier temporaire existe encore.');
}

// =============================================================================
// Acceptance gate — Part 2: browser-fetch checks (B1-B8), evaluated from
// results the client reports back after fetching each probe URL itself.
// =============================================================================

/**
 * B1 — positive control. Must be exactly what was written.
 */
function bootstrap_evaluate_control_probe(int $httpStatus, string $fetchedBody, string $probeContent): bool
{
    return $httpStatus === 200 && $fetchedBody === $probeContent;
}

/**
 * B2 — token.php must return 200 with an EMPTY body, proving PHP executes
 * it rather than serving it as source. Catastrophic if this fails.
 */
function bootstrap_evaluate_php_execution_probe(int $httpStatus, string $fetchedBody): bool
{
    return $httpStatus === 200 && trim($fetchedBody) === '';
}

/**
 * B3-B7 — "not readable" means 403, 404, or a 200 whose body is an error
 * page rather than the probe content. Never read status alone.
 */
function bootstrap_evaluate_protection_probe(int $httpStatus, string $fetchedBody, string $probeContent): bool
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
function bootstrap_evaluate_no_directory_listing(int $httpStatus, string $fetchedBody): bool
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
function bootstrap_evaluate_functional_probe(int $httpStatus, string $fetchedBody, string $marker): bool
{
    return $httpStatus === 200 && strpos($fetchedBody, $marker) !== false;
}

// =============================================================================
// State / lock persistence
// =============================================================================

/**
 * @return array<string, mixed>
 */
function bootstrap_read_state(string $path): array
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
function bootstrap_write_state(string $path, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, "<?php\n/*\n{$json}\n*/\n");
}

function bootstrap_acquire_lock(string $path): bool
{
    if (is_file($path) && (time() - (int) @filemtime($path)) < BOOTSTRAP_LOCK_STALE_SECONDS) {
        return false;
    }

    return @file_put_contents($path, (string) getmypid()) !== false;
}

function bootstrap_release_lock(string $path): void
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
function bootstrap_step_preflight(string $docRoot, array $state): array
{
    $checks = [
        bootstrap_check_location($_SERVER),
        bootstrap_check_php_version(),
        bootstrap_check_zip_extension(),
        bootstrap_check_outbound_https('bootstrap_default_https_probe'),
    ];
    foreach ($checks as $check) {
        if (!$check['ok']) {
            throw new RuntimeException($check['detail']);
        }
    }

    if (!bootstrap_probe_writable($docRoot)) {
        throw new RuntimeException("Le dossier d'installation n'est pas accessible en écriture.");
    }

    $layoutInfo = bootstrap_select_layout($docRoot, 'bootstrap_probe_writable');

    if (bootstrap_already_installed($docRoot)) {
        throw new RuntimeException('Ce dossier contient déjà une installation ScoutMagic.');
    }

    $state['doc_root'] = $docRoot;
    $state['layout'] = $layoutInfo['layout'];
    $state['layout_parent'] = $layoutInfo['parent'];
    $state['layout_reason'] = $layoutInfo['reason'];
    $state['environment'] = bootstrap_gather_environment_info();
    $state['label'] = 'Préflight';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrap_step_resolve(string $docRoot, array $state): array
{
    $release = bootstrap_fetch_latest_release('bootstrap_default_http_get');
    $archive = bootstrap_resolve_archive_url($release);

    $installTarget = $state['layout'] === 'A' ? $state['layout_parent'] : $docRoot;
    $diskCheck = bootstrap_check_disk_space($installTarget, $archive['size']);
    if (!$diskCheck['ok']) {
        throw new RuntimeException($diskCheck['detail']);
    }

    $state['version'] = ltrim((string) ($release['tag_name'] ?? '0.0.0'), 'v');
    $state['archive_url'] = $archive['url'];
    $state['archive_size'] = $archive['size'];
    $state['source_type'] = $archive['source'];
    $state['disk_check'] = $diskCheck;
    $state['label'] = 'Résolution de la dernière version';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrap_step_download(string $docRoot, array $state): array
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
    bootstrap_download_with_retry($state['archive_url'], $artifactPath, 'bootstrap_default_downloader');

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
function bootstrap_step_extract(string $docRoot, array $state): array
{
    $extractedDir = $state['temp_dir'] . '/extracted';
    mkdir($extractedDir, 0755, true);
    bootstrap_extract_zip_safely($state['artifact_path'], $extractedDir);

    $state['extracted_dir'] = $extractedDir;
    $state['source_root'] = bootstrap_resolve_archive_root($extractedDir, $state['source_type']);
    $state['label'] = 'Extraction';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrap_step_verify_artifact(string $docRoot, array $state): array
{
    $result = bootstrap_verify_artifact($state['source_root']);
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
function bootstrap_step_install(string $docRoot, array $state): array
{
    $installTarget = $state['layout'] === 'A' ? $state['layout_parent'] : $docRoot;
    $copied = bootstrap_copy_tree($state['source_root'], $installTarget, ['storage', 'VERSION']);

    if ($state['layout'] === 'B') {
        file_put_contents($docRoot . '/.htaccess', bootstrap_htaccess_content());
        file_put_contents($docRoot . '/' . BOOTSTRAP_INDEX_STUB_FILE, bootstrap_index_stub_content());
    }

    bootstrap_seed_app_config($installTarget);

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
function bootstrap_seed_app_config(string $installTarget): void
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
function bootstrap_step_storage(string $docRoot, array $state): array
{
    bootstrap_create_storage_dirs($state['install_target']);
    $state['label'] = 'Création du stockage';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrap_step_finalize(string $docRoot, array $state): array
{
    bootstrap_write_version($state['install_target'], $state['version']);
    bootstrap_remove_directory($state['temp_dir']);

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
function bootstrap_step_gate_prepare(string $docRoot, array $state): array
{
    $basePath = $state['install_target'];
    $publicDir = $state['layout'] === 'A' ? $docRoot : $docRoot . '/public';

    $sChecks = [
        bootstrap_check_s1($basePath),
        bootstrap_check_s2($basePath),
        bootstrap_check_s3($publicDir),
        bootstrap_check_s4($basePath),
        bootstrap_check_s5($basePath),
        bootstrap_check_s6($basePath),
        bootstrap_check_s7($state['source_root']),
        bootstrap_check_s8($state['temp_dir']),
    ];
    $state['s_checks'] = $sChecks;

    $sFailed = array_values(array_filter($sChecks, static fn (array $c): bool => !$c['ok']));
    if (!empty($sFailed)) {
        bootstrap_rollback_install($docRoot, $state);
        $state = bootstrap_finish_gate($state, false);
        $state['gate_aborted_at'] = 'S';
        $state['label'] = 'Contrôles (échec)';
        $state['percent'] = 100;

        return $state;
    }

    $state['probes'] = bootstrap_write_gate_probes($docRoot, $state);
    $state['awaiting_gate_report'] = true;
    $state['label'] = 'Contrôles';
    $state['percent'] = 50;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<int, array<string, mixed>>
 */
function bootstrap_write_gate_probes(string $docRoot, array $state): array
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
    $probes[] = ['id' => 'B1', 'kind' => 'control', 'url' => '/control-' . $rand . '.txt', 'expected' => $probeContent, 'file' => $controlFile];

    // B2 — token.php must execute as PHP (empty body), never be served as
    // source. Written temporarily here; overwritten with the real token
    // only after the whole gate passes (step 10).
    $tokenFile = $docRoot . '/' . BOOTSTRAP_TOKEN_FILE;
    file_put_contents($tokenFile, "<?php /* gate probe */\n");
    $probes[] = ['id' => 'B2', 'kind' => 'php_exec', 'url' => '/' . BOOTSTRAP_TOKEN_FILE, 'expected' => '', 'file' => null];

    // B7 — dotfile in the docroot, always tested regardless of layout.
    $dotFile = $docRoot . '/.probe-' . $rand;
    file_put_contents($dotFile, $probeContent);
    $probes[] = ['id' => 'B7', 'kind' => 'protection', 'url' => '/.probe-' . $rand, 'expected' => $probeContent, 'file' => $dotFile];

    if ($layout === 'B') {
        // B3 — storage/keys/
        @mkdir($basePath . '/storage/keys', 0755, true);
        $b3File = $basePath . '/storage/keys/canary-' . $rand . '.txt';
        file_put_contents($b3File, $probeContent);
        $probes[] = ['id' => 'B3', 'kind' => 'protection', 'url' => '/storage/keys/canary-' . $rand . '.txt', 'expected' => $probeContent, 'file' => $b3File];

        // B4 — a storage/<new-dir> created moments earlier, proving a
        // prefix rule rather than a per-directory deny file. Failing while
        // B3 passes still aborts the whole install.
        $newDirName = 'gatecheck_' . $rand;
        @mkdir($basePath . '/storage/' . $newDirName, 0755, true);
        $b4File = $basePath . '/storage/' . $newDirName . '/canary-' . $rand . '.txt';
        file_put_contents($b4File, $probeContent);
        $probes[] = ['id' => 'B4', 'kind' => 'protection', 'url' => '/storage/' . $newDirName . '/canary-' . $rand . '.txt', 'expected' => $probeContent, 'file' => $b4File, 'dir' => $basePath . '/storage/' . $newDirName];

        // B5 — a real, already-installed file. config/app.php itself is
        // gitignored and excluded from the release artifact (per-unit
        // local config, never shipped) — config/app.php.dist is the real
        // file guaranteed present in a fresh install.
        if (is_file($basePath . '/config/app.php.dist')) {
            $probes[] = ['id' => 'B5', 'kind' => 'protection', 'url' => '/config/app.php.dist', 'expected' => (string) file_get_contents($basePath . '/config/app.php.dist'), 'file' => null];
        }

        // B6 — real file, vendor/autoload.php.
        $probes[] = ['id' => 'B6', 'kind' => 'protection', 'url' => '/vendor/autoload.php', 'expected' => (string) file_get_contents($basePath . '/vendor/autoload.php'), 'file' => null];

        // B8 — storage/ directory listing.
        $probes[] = ['id' => 'B8', 'kind' => 'listing', 'url' => '/storage/', 'expected' => null, 'file' => null];
    }

    // F1 — the wizard itself, reachable at the site root. The marker is a
    // stable id on the setup wizard's own <body>/root element.
    $probes[] = ['id' => 'F1', 'kind' => 'functional', 'url' => '/', 'expected' => 'id="scoutmagic-setup-wizard"', 'file' => null];

    return $probes;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>|null
 */
function bootstrap_find_probe(array $state, string $id): ?array
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
function bootstrap_probe_expected(array $state, string $id): string
{
    $probe = bootstrap_find_probe($state, $id);

    return $probe !== null && $probe['expected'] !== null ? (string) $probe['expected'] : '';
}

/**
 * @param array<string, mixed> $state
 */
function bootstrap_cleanup_gate_probes(array $state): void
{
    foreach ((array) ($state['probes'] ?? []) as $probe) {
        if (($probe['id'] ?? '') === 'B2') {
            continue; // token.php — handled by whichever caller (rollback or step 10) separately.
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
function bootstrap_rollback_install(string $docRoot, array $state): void
{
    $target = $state['install_target'] ?? null;
    if ($target !== null) {
        foreach ((array) ($state['installed_entries'] ?? []) as $entry) {
            bootstrap_remove_path($target . '/' . $entry);
        }
        bootstrap_remove_path($target . '/storage');
        bootstrap_remove_path($target . '/VERSION');
    }
    if (($state['layout'] ?? null) === 'B') {
        bootstrap_remove_path($docRoot . '/.htaccess');
        bootstrap_remove_path($docRoot . '/' . BOOTSTRAP_INDEX_STUB_FILE);
    }
    bootstrap_remove_path($docRoot . '/' . BOOTSTRAP_TOKEN_FILE);
    bootstrap_cleanup_gate_probes($state);
    if (!empty($state['temp_dir'])) {
        bootstrap_remove_path($state['temp_dir']);
    }
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrap_finish_gate(array $state, bool $passed): array
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
function bootstrap_evaluate_gate_report(string $docRoot, array $state, array $results): array
{
    $byId = [];
    foreach ($results as $r) {
        if (isset($r['id']) && is_string($r['id'])) {
            $byId[$r['id']] = $r;
        }
    }

    $bChecks = [];
    $b1 = $byId['B1'] ?? null;
    $b1Pass = $b1 !== null && bootstrap_evaluate_control_probe((int) $b1['status'], (string) $b1['body'], bootstrap_probe_expected($state, 'B1'));
    $bChecks[] = bootstrap_gate_result('B1', $b1Pass, 'Témoin positif (fichier accessible)', $b1Pass ? 'Le fichier témoin a été correctement servi.' : "Le fichier témoin n'a pas pu être vérifié — impossible de faire confiance aux contrôles suivants.");

    if (!$b1Pass) {
        foreach (['B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8'] as $id) {
            if (bootstrap_find_probe($state, $id) !== null) {
                $bChecks[] = bootstrap_gate_result($id, false, $id, 'Non vérifié — le témoin positif (B1) a échoué.');
            }
        }
        $state['b_checks'] = $bChecks;
        $state['f_checks'] = [bootstrap_gate_result('F1', false, 'Assistant de configuration accessible', 'Non vérifié — le témoin positif (B1) a échoué.')];
        bootstrap_rollback_install($docRoot, $state);
        $state = bootstrap_finish_gate($state, false);
        $state['gate_aborted_at'] = 'B1';

        return $state;
    }

    $b2 = $byId['B2'] ?? null;
    $b2Pass = $b2 !== null && bootstrap_evaluate_php_execution_probe((int) $b2['status'], (string) $b2['body']);
    $bChecks[] = bootstrap_gate_result('B2', $b2Pass, 'Exécution PHP (token.php)', $b2Pass ? "token.php s'exécute correctement en PHP." : 'token.php a été servi en clair — le serveur n\'exécute pas PHP à cet endroit. Installation dangereuse, abandon immédiat.');

    $allProtectionsPass = true;
    foreach (['B3', 'B4', 'B5', 'B6', 'B7'] as $id) {
        $probeDef = bootstrap_find_probe($state, $id);
        if ($probeDef === null) {
            continue; // not applicable in this layout — skipped by construction.
        }
        $r = $byId[$id] ?? null;
        $pass = $r !== null && bootstrap_evaluate_protection_probe((int) $r['status'], (string) $r['body'], (string) $probeDef['expected']);
        $allProtectionsPass = $allProtectionsPass && $pass;
        $bChecks[] = bootstrap_gate_result($id, $pass, 'Protection : ' . $id, $pass ? "Non accessible depuis le web." : 'ACCESSIBLE depuis le web — donnée sensible exposée.');
    }

    $b8Def = bootstrap_find_probe($state, 'B8');
    $b8Pass = true;
    if ($b8Def !== null) {
        $r = $byId['B8'] ?? null;
        $b8Pass = $r !== null && bootstrap_evaluate_no_directory_listing((int) $r['status'], (string) $r['body']);
        $bChecks[] = bootstrap_gate_result('B8', $b8Pass, 'Pas de listage de répertoire', $b8Pass ? 'Aucun contenu de répertoire exposé.' : 'Le contenu de storage/ est listé publiquement.');
    }

    $fDef = bootstrap_find_probe($state, 'F1');
    $f = $byId['F1'] ?? null;
    $fPass = $f !== null && $fDef !== null && bootstrap_evaluate_functional_probe((int) $f['status'], (string) $f['body'], (string) $fDef['expected']);
    $fChecks = [bootstrap_gate_result('F1', $fPass, "Assistant de configuration accessible", $fPass ? "La page d'accueil affiche l'assistant de configuration." : "La page d'accueil n'affiche pas l'assistant de configuration attendu.")];

    $state['b_checks'] = $bChecks;
    $state['f_checks'] = $fChecks;

    if (!$b2Pass) {
        // Catastrophic — abort immediately regardless of everything else.
        bootstrap_rollback_install($docRoot, $state);
        $state = bootstrap_finish_gate($state, false);
        $state['gate_aborted_at'] = 'B2';

        return $state;
    }

    $gatePassed = $allProtectionsPass && $b8Pass && $fPass;

    if (!$gatePassed) {
        bootstrap_rollback_install($docRoot, $state);
        $state = bootstrap_finish_gate($state, false);

        return $state;
    }

    bootstrap_cleanup_gate_probes($state);
    $state = bootstrap_finish_gate($state, true);

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
function bootstrap_step_token(string $docRoot, array $state): array
{
    if (empty($state['gate_passed'])) {
        throw new RuntimeException('Le jeton ne peut être généré qu\'après la réussite des contrôles.');
    }

    $token = bootstrap_generate_token();
    $written = @file_put_contents($docRoot . '/' . BOOTSTRAP_TOKEN_FILE, bootstrap_token_file_content($token)) !== false;

    $state['token_written'] = $written;
    if (!$written) {
        // Graceful degradation: a failure to write token.php doesn't lock
        // the operator out — the wizard's own refusal screen already tells
        // them the exact content to create over FTP, it just never
        // generates the token itself.
        $state['token_write_warning'] = "Impossible d'écrire token.php automatiquement — créez-le manuellement via FTP avec le contenu ci-dessous.";
        $state['token_manual_content'] = bootstrap_token_file_content($token);
    }

    $state['label'] = 'Génération du jeton';
    $state['percent'] = 100;

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function bootstrap_step_cleanup(string $docRoot, array $state, ?callable $selfDelete = null): array
{
    $selfDelete ??= static fn (): bool => @unlink(__FILE__);

    @unlink($docRoot . '/' . BOOTSTRAP_STATE_FILE);
    $selfDeleted = (bool) $selfDelete();

    $state['self_deleted'] = $selfDeleted;
    if (!$selfDeleted) {
        // Never auto-redirect if self-deletion failed — name the file to
        // delete via FTP, then let the operator continue manually.
        $state['cleanup_warning'] = "Impossible de supprimer bootstrap.php automatiquement — supprimez-le manuellement via FTP (il ne réinstallera rien tant que le fichier VERSION existe, mais le laisser en place est un risque inutile).";
    }
    $state['label'] = 'Nettoyage';
    $state['percent'] = 100;
    $state['done'] = true;

    return $state;
}

function bootstrap_already_installed(string $docRoot): bool
{
    return is_file($docRoot . '/VERSION') || is_dir($docRoot . '/core');
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
function bootstrap_default_http_get(string $url, array $headers = []): array
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

function bootstrap_default_downloader(string $url, string $destPath): void
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

function bootstrap_default_https_probe(): bool
{
    $context = stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 8, 'header' => 'User-Agent: ' . BOOTSTRAP_USER_AGENT . "\r\n", 'ignore_errors' => true],
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
function bootstrap_public_state(array $state): array
{
    $publicKeys = [
        'label', 'percent', 'layout', 'layout_reason', 'version', 'done', 'done_gate',
        'gate_passed', 'gate_aborted_at', 'error', 'failed_step', 's_checks', 'b_checks',
        'f_checks', 'probes', 'awaiting_gate_report', 'token_written', 'token_write_warning',
        'token_manual_content', 'self_deleted', 'cleanup_warning', 'disk_check', 'environment',
        'gate_report',
    ];

    $out = [];
    foreach ($publicKeys as $key) {
        if (array_key_exists($key, $state)) {
            $out[$key] = $state[$key];
        }
    }

    if (isset($out['probes'])) {
        $out['probes'] = array_map(
            static fn (array $p): array => ['id' => $p['id'], 'kind' => $p['kind'], 'url' => $p['url'], 'expected' => $p['expected']],
            $out['probes']
        );
    }

    return $out;
}

function bootstrap_sanitize_error_for_client(string $message, string $docRoot): string
{
    $sanitized = str_replace($docRoot, '', $message);
    $parent = dirname($docRoot);
    if ($parent !== $docRoot) {
        $sanitized = str_replace($parent, '', $sanitized);
    }

    return $sanitized;
}

/**
 * @return array{checks: array<int, array{ok: bool, label: string, detail: string}>, ok: bool, layout: array{layout: string, parent: string|null, reason: string}|null, environment: array<string, mixed>}
 */
function bootstrap_preview_preflight(string $docRoot): array
{
    $checks = [
        bootstrap_check_location($_SERVER),
        bootstrap_check_php_version(),
        bootstrap_check_zip_extension(),
        bootstrap_check_outbound_https('bootstrap_default_https_probe'),
    ];
    $writable = bootstrap_probe_writable($docRoot);
    $checks[] = [
        'ok' => $writable,
        'label' => "Permissions du dossier d'installation",
        'detail' => $writable ? 'Accessible en écriture (écriture/lecture/suppression testées).' : "Le dossier n'est pas accessible en écriture.",
    ];

    $allOk = !in_array(false, array_column($checks, 'ok'), true);
    $layout = $allOk ? bootstrap_select_layout($docRoot, 'bootstrap_probe_writable') : null;

    return ['checks' => $checks, 'ok' => $allOk, 'layout' => $layout, 'environment' => bootstrap_gather_environment_info()];
}

function bootstrap_handle_step_request(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    // See bootstrap_send_json() — every exit point below goes through it
    // rather than a bare echo json_encode(), so a stray PHP warning never
    // corrupts the JSON response.
    ob_start();
    $input = json_decode((string) file_get_contents('php://input'), true);
    $step = (int) (is_array($input) ? ($input['step'] ?? 0) : 0);
    $lockFile = $docRoot . '/' . BOOTSTRAP_LOCK_FILE;

    if ($step === 1) {
        // Deliberately NOT checking bootstrap_already_installed() here —
        // bootstrap_step_preflight() already makes the exact same check
        // moments later, INSIDE the try/catch below, where a failure
        // correctly triggers rollback (an abandoned attempt whose files
        // were already copied by an earlier request's step 6 must be torn
        // down, not just refused). A short-circuit here would return the
        // identical error message without ever reaching that rollback,
        // permanently stranding those files with no recovery path.
        if (!bootstrap_acquire_lock($lockFile)) {
            bootstrap_send_json(['error' => "Une installation est déjà en cours (ou une tentative précédente n'a pas été nettoyée). Réessayez dans 10 minutes, ou supprimez immédiatement le fichier " . BOOTSTRAP_LOCK_FILE . " via FTP à la racine du site pour débloquer tout de suite."]);
            return;
        }
    } elseif (!is_file($lockFile)) {
        bootstrap_send_json(['error' => 'Aucune installation en cours. Rechargez la page.']);
        return;
    }

    $state = bootstrap_read_state($stateFile);

    try {
        switch ($step) {
            case 1: $state = bootstrap_step_preflight($docRoot, $state); break;
            case 2: $state = bootstrap_step_resolve($docRoot, $state); break;
            case 3: $state = bootstrap_step_download($docRoot, $state); break;
            case 4: $state = bootstrap_step_extract($docRoot, $state); break;
            case 5: $state = bootstrap_step_verify_artifact($docRoot, $state); break;
            case 6: $state = bootstrap_step_install($docRoot, $state); break;
            case 7: $state = bootstrap_step_storage($docRoot, $state); break;
            case 8: $state = bootstrap_step_finalize($docRoot, $state); break;
            case 9: $state = bootstrap_step_gate_prepare($docRoot, $state); break;
            case 10: $state = bootstrap_step_token($docRoot, $state); break;
            case 11:
                $state = bootstrap_step_cleanup($docRoot, $state);
                bootstrap_release_lock($lockFile);
                bootstrap_send_json(bootstrap_public_state($state));
                return;
            default:
                bootstrap_send_json(['error' => 'Étape inconnue.']);
                return;
        }

        if (($state['done_gate'] ?? false) === true && ($state['gate_passed'] ?? false) === false) {
            bootstrap_release_lock($lockFile);
        }

        bootstrap_write_state($stateFile, $state);
        bootstrap_send_json(bootstrap_public_state($state));
    } catch (\Throwable $e) {
        $state['error'] = bootstrap_sanitize_error_for_client($e->getMessage(), $docRoot);
        $state['failed_step'] = $step;
        if (!empty($state['install_target']) && !empty($state['installed_entries'])) {
            // Something was actually copied onto disk — always roll it
            // back on any failure, regardless of which step number the
            // CURRENT request happens to be. Deliberately NOT keyed to
            // "$step is 6-8": a retry can fail at step 1 with "already
            // installed" (bootstrap_step_preflight()'s own guard) against
            // state left behind by an EARLIER request's step 6 that
            // succeeded server-side but whose response the browser never
            // got to process (a network error, or the exact bug
            // bootstrap_send_json() now guards against) — that install_
            // target/installed_entries pair is exactly as real and exactly
            // as much in need of rollback as one from the current request.
            bootstrap_rollback_install($docRoot, $state);
        } elseif (!empty($state['temp_dir'])) {
            // Nothing installed yet, but the temp dir (steps 2-5: download/
            // extract/verify) must still never be left behind — same "no
            // sys_get_temp_dir(), always removed" guarantee regardless of
            // which step actually failed.
            bootstrap_remove_directory($state['temp_dir']);
        }
        bootstrap_write_state($stateFile, $state);
        bootstrap_release_lock($lockFile);
        bootstrap_send_json(['done' => true, 'error' => $state['error'], 'step' => $step]);
    }
}

/**
 * The browser can never be sure a step actually failed server-side just
 * because it couldn't parse the response (a network error, or the exact
 * class of bug bootstrap_send_json() now guards against) — the step may
 * well have completed and left real files on disk with the lock still
 * held. Without an explicit way to force a clean rollback, that leaves
 * the install permanently stuck: bootstrap_step_preflight()'s own
 * "already installed" check then refuses every subsequent retry, and
 * there is no FTP-free way out. This performs the same rollback a
 * caught failure would have, from whatever was last durably written to
 * .bootstrap-state.php (state is written on every successful step
 * server-side regardless of whether the client ever saw the response),
 * then clears the lock and state file so a fresh attempt starts clean.
 */
function bootstrap_handle_abort_request(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    try {
        $state = bootstrap_read_state($stateFile);
        bootstrap_rollback_install($docRoot, $state);
        @unlink($stateFile);
        bootstrap_release_lock($docRoot . '/' . BOOTSTRAP_LOCK_FILE);

        bootstrap_send_json([
            'ok' => true,
            'message' => "Installation abandonnée : les fichiers déjà copiés ont été retirés. Rechargez la page pour recommencer.",
        ]);
    } catch (\Throwable $e) {
        // Even the recovery path itself must degrade to a parseable
        // response rather than a raw fatal error the browser can't read
        // — the JS fallback text already tells the operator to finish
        // the cleanup manually via FTP if this happens.
        bootstrap_send_json([
            'ok' => false,
            'message' => bootstrap_sanitize_error_for_client($e->getMessage(), $docRoot),
        ]);
    }
}

function bootstrap_handle_gate_report(string $docRoot, string $stateFile): void
{
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    $lockFile = $docRoot . '/' . BOOTSTRAP_LOCK_FILE;
    $input = json_decode((string) file_get_contents('php://input'), true);
    $results = is_array($input) && is_array($input['results'] ?? null) ? $input['results'] : [];

    $state = bootstrap_read_state($stateFile);
    if (empty($state['awaiting_gate_report'])) {
        bootstrap_send_json(['error' => 'Aucun contrôle en attente.']);
        return;
    }

    try {
        $state = bootstrap_evaluate_gate_report($docRoot, $state, $results);

        if (($state['gate_passed'] ?? false) === false) {
            bootstrap_release_lock($lockFile);
        }

        bootstrap_write_state($stateFile, $state);
        bootstrap_send_json(bootstrap_public_state($state));
    } catch (\Throwable $e) {
        // Same guarantee as bootstrap_handle_step_request's catch block —
        // an unexpected failure here must never leave the lock file
        // stranded, or every subsequent retry wrongly reports "an
        // install is already in progress" for up to
        // BOOTSTRAP_LOCK_STALE_SECONDS.
        $state['error'] = bootstrap_sanitize_error_for_client($e->getMessage(), $docRoot);
        bootstrap_rollback_install($docRoot, $state);
        bootstrap_write_state($stateFile, $state);
        bootstrap_release_lock($lockFile);
        bootstrap_send_json(['done' => true, 'error' => $state['error']]);
    }
}

/**
 * Discards any output already buffered — a stray PHP warning/notice
 * (an unsuppressed mkdir()/file_put_contents() hitting an edge case, or
 * simply a host with display_errors on) printed ahead of the intended
 * JSON would otherwise land in the same response body and break
 * response.json() client-side with an opaque "did not match the
 * expected pattern" parse error. bootstrap_handle_step_request() and
 * bootstrap_handle_gate_report() both call ob_start() first, and every
 * exit point goes through this instead of a bare echo json_encode(),
 * so the response is always exactly one clean JSON document regardless
 * of what else the host's PHP tried to print along the way.
 *
 * @param array<string, mixed> $payload
 */
function bootstrap_send_json(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload);
}

function bootstrap_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bootstrap_render_error_page(string $message): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>ScoutMagic — Installation</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;line-height:1.5}.alert{background:#fdecea;color:#611a15;border:1px solid #f5c2c0;border-radius:.5rem;padding:1rem}</style>'
        . '</head><body><h1>ScoutMagic — Installation</h1><div class="alert">' . bootstrap_html_escape($message) . '</div></body></html>';
}

function bootstrap_render_ui(string $docRoot, string $stateFile): void
{
    header('Content-Type: text/html; charset=utf-8');
    $preview = bootstrap_preview_preflight($docRoot);
    $stateFileNameJs = json_encode(BOOTSTRAP_STATE_FILE);
    $lockFileNameJs = json_encode(BOOTSTRAP_LOCK_FILE);

    $layoutBlock = '';
    if ($preview['ok'] && $preview['layout'] !== null) {
        $layout = $preview['layout'];
        $optionLabel = $layout['layout'] === 'A' ? 'Option A — Installation naturelle' : 'Option B — Arborescence unique';
        $paths = $layout['layout'] === 'A'
            ? sprintf('Dossier parent : <code>%s</code><br>Document root (public/) : <code>%s</code>', bootstrap_html_escape((string) $layout['parent']), bootstrap_html_escape($docRoot))
            : sprintf('Dossier d\'installation : <code>%s</code>', bootstrap_html_escape($docRoot));
        $securityNote = $layout['layout'] === 'A'
            ? "Le document root reste exactement ce qu'il est aujourd'hui — les fichiers sensibles (storage/, core/, etc.) sont installés à côté, hors de portée du web."
            : "Tout est installé dans le document root ; un fichier .htaccess unique à la racine protège storage/, core/, modules/, config/, schema/, vendor/, tests/ et scripts/, y compris les dossiers créés après l'installation.";

        $layoutBlock = '<div class="option-box"><h3>' . $optionLabel . '</h3><p class="paths">' . $paths . '</p>'
            . '<p><strong>Pourquoi ce choix :</strong> ' . bootstrap_html_escape($layout['reason']) . '</p>'
            . '<p><strong>Ce que cela signifie pour la sécurité :</strong> ' . bootstrap_html_escape($securityNote) . '</p></div>';
    }

    $checksHtml = '';
    foreach ($preview['checks'] as $check) {
        $checksHtml .= '<div class="report-row ' . ($check['ok'] ? 'report-ok' : 'report-fail') . '">'
            . ($check['ok'] ? '✓ ' : '✗ ') . bootstrap_html_escape($check['label']) . ' — ' . bootstrap_html_escape($check['detail']) . '</div>';
    }

    $installDisabled = $preview['ok'] ? '' : 'disabled';

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ScoutMagic — Installation</title>
<style>
  :root { color-scheme: light; }
  body { font-family: system-ui, -apple-system, sans-serif; max-width: 640px; margin: 0 auto; padding: 1.25rem; line-height: 1.5; }
  h1 { font-size: 1.4rem; }
  h3 { font-size: 1.05rem; margin-top: 0; }
  .option-box { border: 1px solid #ccc; border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
  .paths code { word-break: break-all; }
  button { min-height: 44px; min-width: 44px; font-size: 1rem; padding: .6rem 1.2rem; border-radius: .5rem; border: none; background: #0d6efd; color: #fff; cursor: pointer; }
  button:disabled { background: #999; cursor: not-allowed; }
  .report-row { padding: .4rem .2rem; border-bottom: 1px solid #eee; }
  .report-ok { color: #146c2e; }
  .report-fail { color: #b02a37; font-weight: 600; }
  .alert { border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
  .alert-ok { background: #e6f4ea; color: #146c2e; }
  .alert-error { background: #fdecea; color: #611a15; }
  #progress-bar-outer { background: #eee; border-radius: .5rem; height: 1rem; overflow: hidden; margin: 1rem 0; }
  #progress-bar { background: #0d6efd; height: 100%; width: 0; transition: width .3s; }
  #progress-log { font-size: .85rem; max-height: 220px; overflow-y: auto; background: #f8f9fa; border-radius: .5rem; padding: .75rem; }
  #progress-log p { margin: .2rem 0; }
  .log-error { color: #b02a37; font-weight: 600; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<h1>ScoutMagic — Installation</h1>

<section id="screen-confirm">
  <div id="report-table">{$checksHtml}</div>
  {$layoutBlock}
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
  var screens = {
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

  function runInstall() {
    showScreen('progress');
    runStep(1);
  }

  function runStep(step) {
    setProgress(step, 0);
    postJson('?action=step', { step: step }).then(function (data) {
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
      logLine('Contrôles envoyés — pause de quelques secondes avant de continuer (si la console du navigateur affiche des erreurs ici, c\\'est normal : elles viennent des vérifications ci-dessus).');
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
  // and bootstrap_step_preflight()'s own "already installed" check would
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
          summary.textContent = (data.message || 'Le nettoyage automatique a échoué.') + ' Supprimez manuellement via FTP les fichiers déjà copiés, ainsi que ' + STATE_FILE_NAME + ' et ' + LOCK_FILE_NAME + ', puis rechargez cette page.';
          summary.className = 'alert alert-error';
          abortBtn.disabled = false;
          return;
        }
        summary.textContent = (data.message || 'Nettoyage effectué.') + ' Rechargement…';
        summary.className = 'alert alert-ok';
        setTimeout(function () { window.location.reload(); }, 2500);
      }).catch(function () {
        summary.textContent = 'Le nettoyage automatique a également échoué. Supprimez manuellement via FTP les fichiers déjà copiés, ainsi que ' + STATE_FILE_NAME + ' et ' + LOCK_FILE_NAME + ', puis rechargez cette page.';
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
    summary.textContent = "La réponse du serveur n'a pas pu être interprétée (problème réseau, ou le serveur a renvoyé une réponse inattendue). Des fichiers ont peut-être déjà été copiés. Cliquez ci-dessous pour annuler proprement cette tentative avant de réessayer.";
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
      summary.textContent = (data.cleanup_warning || 'Installation terminée avec succès, mais bootstrap.php n\\'a pas pu se supprimer automatiquement.') + ' Une fois supprimé (ou si vous préférez continuer sans le faire), cliquez ci-dessous.';
      summary.className = 'alert alert-warning';
      if (data.token_write_warning) { logLine(data.token_write_warning, true); }
      var continueBtn = document.createElement('button');
      continueBtn.textContent = "Continuer vers l'assistant de configuration";
      // Straight to /setup rather than "/" + relying on the app's own
      // not-initialized redirect: the whole point of the .htaccess
      // hardening earlier in this file is that some hosts intercept the
      // bare root path before a PHP request is ever made, so bouncing
      // through "/" first is exactly the ambiguity to avoid here.
      continueBtn.addEventListener('click', function () { window.location.href = '/setup'; });
      summary.insertAdjacentElement('afterend', continueBtn);
    } else if (effectivePassed) {
      summary.textContent = 'Installation terminée avec succès. token.php vous attend dans le même dossier FTP — la page suivante vous le demandera. Redirection automatique dans quelques secondes.';
      summary.className = 'alert alert-ok';
      if (data.token_write_warning) { logLine(data.token_write_warning, true); }
      setTimeout(function () { window.location.href = '/setup'; }, 5000);
    } else {
      // For a plain step failure (steps 1-8/10), there are no s_checks/
      // b_checks/f_checks rows at all — data.error is the ONLY place the
      // actual reason lives, and the progress screen's log line for it
      // just got hidden by the switch to this screen. Show it directly.
      var reasonSuffix = data.error ? (' Raison : ' + data.error) : '';
      summary.textContent = 'L\\'installation a été annulée et les fichiers déjà copiés ont été retirés.' + reasonSuffix + ' Corrigez le point signalé ci-dessus puis rechargez cette page pour réessayer.';
      summary.className = 'alert alert-error';
    }
  }

  if (installBtn) { installBtn.addEventListener('click', runInstall); }
})();
</script>
</body>
</html>
HTML;
}

function bootstrap_main(): void
{
    $docRoot = __DIR__;
    $stateFile = $docRoot . '/' . BOOTSTRAP_STATE_FILE;

    if (bootstrap_already_installed($docRoot) && !is_file($stateFile)) {
        bootstrap_render_error_page(
            'Ce dossier contient déjà une installation ScoutMagic (fichier VERSION ou dossier core/ présent). '
            . 'Utilisez Configuration > Maintenance pour mettre à jour, ou retirez manuellement les fichiers existants avant de relancer bootstrap.php.'
        );
        return;
    }

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'step' && $method === 'POST') {
        bootstrap_handle_step_request($docRoot, $stateFile);
        return;
    }

    if ($action === 'gate-report' && $method === 'POST') {
        bootstrap_handle_gate_report($docRoot, $stateFile);
        return;
    }

    if ($action === 'abort' && $method === 'POST') {
        bootstrap_handle_abort_request($docRoot, $stateFile);
        return;
    }

    bootstrap_render_ui($docRoot, $stateFile);
}

if (!defined('BOOTSTRAP_TEST')) {
    bootstrap_main();
}
