<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * Which directories the web server hands out by itself, file by file, with
 * no access check — the one place a local storage location must never be
 * (issue #1129, SECURITY.md §6).
 *
 * **It depends on the hosting layout** (ARCHITECTURE.md §9.1), and the old
 * check only knew one of the two:
 *
 * - **Layout A**: the project sits beside the document root, and only
 *   `public/`'s contents are inside it. Served: that directory.
 * - **Layout B**: the whole tree sits IN the document root, and its root
 *   `.htaccess` (`bootstrap.php`'s `bootstrapHtaccessContent()`) serves
 *   every existing file as-is — except under the directories it refuses by
 *   name ({@see PROTECTED_TOP_LEVEL}) and any dot-directory. Served:
 *   everything else under the project root. A location at `<root>/photos`
 *   is therefore every photo downloadable by URL, which the `public/`-only
 *   check let through.
 *
 * The layout is read the way the rest of the application reads it: the
 * install report `bootstrap.php` wrote, else the one unambiguous tell — the
 * `index.php` stub layout B puts at the project root.
 *
 * The document root the web server reports, when there is one, is a second
 * reason to refuse rather than the source of truth: it can be absent (the
 * scheduler) or wrong (some hosts rewrite it), but a folder under it is
 * served whatever the layout says.
 */
final class WebServedArea
{
    /**
     * The top-level directories layout B's root `.htaccess` refuses for
     * every existing file or directory under them. Kept equal to that rule
     * by a test that reads `bootstrap.php`.
     */
    public const PROTECTED_TOP_LEVEL = ['storage', 'core', 'modules', 'config', 'schema', 'vendor', 'tests', 'scripts'];

    /** The form's refusal, unchanged since before #1129. */
    public const DECLARATION_REFUSED = 'Ce dossier est dans la partie du site servie directement par le serveur web : '
        . 'tout ce qui y serait déposé deviendrait téléchargeable sans aucun contrôle d\'accès. Choisissez un '
        . 'dossier situé ailleurs.';

    /**
     * The refusal for a location saved before the rule knew layout B, or
     * arrived by a restore: it is no longer opened, its files stay where
     * they are.
     */
    public const SAVED_LOCATION_REFUSED = 'Le dossier de cet emplacement est servi directement par le serveur web : '
        . 'ses fichiers y sont téléchargeables sans aucun contrôle d\'accès. Le site ne l\'utilise plus. Déplacez '
        . 'son contenu dans un dossier hors de la racine web, puis indiquez ce nouveau chemin sur sa fiche.';

    /**
     * @param list<string> $servedRoots directories served whole (layout A's
     *        public directory, the reported document root)
     */
    private function __construct(
        private string $projectRoot,
        private bool $layoutB,
        private array $servedRoots
    ) {
    }

    /**
     * @param string      $projectRoot  the installation's root — `dirname()` of the
     *                                  public directory and of `storage/` alike
     * @param string|null $publicDir    the directory holding the front controller,
     *                                  when the caller knows it (`__DIR__` of
     *                                  `public/index.php`); otherwise looked for
     * @param string|null $documentRoot what the web server reports, if anything
     */
    public static function of(string $projectRoot, ?string $publicDir = null, ?string $documentRoot = null): self
    {
        $root = self::canonicalise($projectRoot);
        $layoutB = self::isLayoutB($root);

        $served = [];
        if ($publicDir !== null && $publicDir !== '') {
            $served[] = self::canonicalise($publicDir);
        }
        if (!$layoutB) {
            // Layout A merges `public/`'s contents into a document root of
            // any name beside the project files. A caller that does not
            // know it (the scheduler, a task) finds it by what it holds:
            // the front controller and the scheduler entry point together.
            foreach (glob(rtrim($root, '/') . '/*/cron.php') ?: [] as $cron) {
                if (is_file(dirname($cron) . '/index.php')) {
                    $served[] = self::canonicalise(dirname($cron));
                }
            }
        }
        if ($documentRoot !== null && trim($documentRoot) !== '') {
            $served[] = self::canonicalise($documentRoot);
        }

        return new self($root, $layoutB, array_values(array_unique(array_filter(
            $served,
            static fn (string $dir): bool => $dir !== '' && $dir !== '/'
        ))));
    }

    /** For a caller that knows only where `storage/` is. */
    public static function forStoragePath(string $storagePath): self
    {
        return self::of(dirname(rtrim($storagePath, '/')));
    }

    /**
     * Whether a file written under $path could be fetched straight from the
     * web server. Answers for a directory that does not exist yet, which is
     * the ordinary case when a location is being declared.
     */
    public function serves(string $path): bool
    {
        $candidate = self::canonicalise($path);
        if ($candidate === '') {
            return false;
        }

        if ($this->layoutB && self::isUnder($candidate, $this->projectRoot)) {
            return !$this->isProtectedInLayoutB($candidate);
        }

        foreach ($this->servedRoots as $root) {
            if (self::isUnder($candidate, $root)) {
                return true;
            }
        }

        return false;
    }

    private function isProtectedInLayoutB(string $candidate): bool
    {
        if ($candidate === $this->projectRoot) {
            return false;
        }
        $relative = substr($candidate, strlen(rtrim($this->projectRoot, '/')) + 1);
        $segments = explode('/', $relative);

        if (in_array($segments[0], self::PROTECTED_TOP_LEVEL, true)) {
            return true;
        }
        // The `.htaccess` refuses any path with a dot-segment, at any depth.
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    private static function isLayoutB(string $root): bool
    {
        $report = $root . '/storage/config/install-report.json';
        if (is_readable($report)) {
            $decoded = json_decode((string) file_get_contents($report), true);
            $layout = is_array($decoded) ? ($decoded['layout'] ?? null) : null;
            if ($layout === 'A' || $layout === 'B') {
                return $layout === 'B';
            }
        }

        return is_file($root . '/index.php') && is_file($root . '/public/index.php');
    }

    private static function isUnder(string $candidate, string $root): bool
    {
        return $candidate === $root || str_starts_with($candidate, rtrim($root, '/') . '/');
    }

    /**
     * A path in its canonical form, whether or not it exists yet.
     *
     * Walks up to the nearest ancestor the filesystem can resolve,
     * `realpath()`s that — which settles `.`, doubled slashes and symbolic
     * links in one step — then re-attaches the segments that do not exist
     * yet. A path with no resolvable ancestor at all comes back trimmed
     * rather than empty, so the caller still has something to compare.
     *
     * Used for COMPARING only, never stored: resolving symbolic links into
     * what gets written down would turn a deploy layout's
     * `/var/www/current/photos` into `/var/www/releases/41/photos` and break
     * it at the next release.
     */
    public static function canonicalise(string $path): string
    {
        $existing = rtrim($path, '/');
        if ($existing === '') {
            return '/';
        }

        /** @var list<string> $missing */
        $missing = [];
        while (!file_exists($existing)) {
            $segment = basename($existing);
            $parent = dirname($existing);
            if ($parent === $existing) {
                return rtrim($path, '/');
            }
            // A `.` segment names its own parent and must not come back as
            // a directory name when the tail is re-attached.
            if ($segment !== '' && $segment !== '.') {
                $missing[] = $segment;
            }
            $existing = $parent;
        }

        $resolved = realpath($existing);
        if ($resolved === false) {
            return rtrim($path, '/');
        }

        // A `..` in the part that does not exist yet climbs from what came
        // before it, so `<root>/storage/../photos` is `<root>/photos` here
        // as it is to the kernel — never a way around the comparison.
        $result = $resolved;
        foreach (array_reverse($missing) as $segment) {
            $result = $segment === '..' ? dirname($result) : rtrim($result, '/') . '/' . $segment;
        }

        return $result;
    }
}
