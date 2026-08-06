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

    private static function locate(string $name): string|false
    {
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && isset($output[0]) && $output[0] !== '') {
            return $output[0];
        }

        foreach (self::COMMON_BIN_DIRS as $dir) {
            $candidate = $dir . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return false;
    }
}
