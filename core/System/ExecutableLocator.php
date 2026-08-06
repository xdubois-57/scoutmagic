<?php

declare(strict_types=1);

namespace Core\System;

/**
 * Finds an absolute path to a system binary for use in exec()/shell_exec()
 * calls. PHP-FPM/CGI workers often run with a much narrower $PATH than an
 * interactive SSH shell — sometimes none at all — so a binary that's
 * reachable by a logged-in admin (`which mysqldump` working over SSH) can
 * still be invisible to `exec('which mysqldump')` from PHP on the very same
 * host. Probing common install directories directly sidesteps that PATH
 * mismatch without needing to know in advance what any given host's layout
 * looks like.
 */
final class ExecutableLocator
{
    /** @var string[] */
    private const COMMON_BIN_DIRS = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/usr/local/sbin',
        '/usr/local/mysql/bin',
        '/opt/mysql/bin',
        '/usr/mysql/bin',
    ];

    /**
     * @var array<string, string|false>
     */
    private static array $cache = [];

    /**
     * @return string|null absolute path to the executable, or null if it
     *         couldn't be found via PATH or any of the common fallback
     *         directories
     */
    public static function find(string $name): ?string
    {
        if (!isset(self::$cache[$name])) {
            self::$cache[$name] = self::locate($name);
        }

        return self::$cache[$name] === false ? null : self::$cache[$name];
    }

    /**
     * find() returning null is ambiguous on its own: it means either "this
     * binary genuinely isn't installed anywhere probed" or "exec() itself
     * can't run at all", which look identical from the outside but need
     * completely different fixes (an operator can't install a missing
     * binary on shared hosting, but disable_functions is at least
     * something their host's support can act on). Checked here rather
     * than folded into find()'s own null, so callers can give a
     * specific, actionable message instead of a generic "not found".
     *
     * function_exists('exec') is not enough on its own: disable_functions
     * removes the ability to *call* a function without undefining it, so
     * function_exists() still reports true for a disabled function.
     */
    public static function isExecAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    private static function locate(string $name): string|false
    {
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && isset($output[0]) && $output[0] !== '') {
            return $output[0];
        }

        foreach (self::COMMON_BIN_DIRS as $dir) {
            $candidate = $dir . '/' . $name;
            // Deliberately not is_file()/is_executable(): shared hosting
            // commonly restricts PHP's own filesystem functions to the
            // user's home directory via open_basedir, which makes both
            // return false for anything under /usr — even though that
            // restriction never applies to what a spawned subprocess can
            // run. Invoking the candidate directly is the only check that
            // reflects what exec() will actually be able to do.
            $unused = [];
            @exec(escapeshellarg($candidate) . ' --version 2>/dev/null', $unused, $candidateReturnCode);
            if ($candidateReturnCode === 0) {
                return $candidate;
            }
        }

        return false;
    }
}
