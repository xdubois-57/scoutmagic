<?php

declare(strict_types=1);

namespace Core\System;

/**
 * Finds an absolute path to a system binary for use in exec()/shell_exec()
 * calls. PHP-FPM/CGI workers often run with a much narrower $PATH than an
 * interactive SSH shell — sometimes none at all — so a binary that's
 * reachable by a logged-in admin (`which mysql` working over SSH) can
 * still be invisible to `exec('which mysql')` from PHP on the very same
 * host. Probing common install directories directly sidesteps that PATH
 * mismatch without needing to know in advance what any given host's layout
 * looks like. Only Core\Maintenance\BackupService::restoreDatabase() (the
 * `mysql` client) and the `timeout`/`gtimeout` wrapper around it still use
 * this — database dumping no longer shells out at all (Core\Database\
 * DatabaseDumper, backed by ifsnop/mysqldump-php).
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
     * binary genuinely isn't installed anywhere probed" or "no shell-
     * execution function can run at all", which look identical from the
     * outside but need completely different fixes (an operator can't
     * install a missing binary on shared hosting, but disable_functions is
     * at least something their host's support can act on). Checked here
     * rather than folded into find()'s own null, so callers can give a
     * specific, actionable message instead of a generic "not found".
     *
     * Delegates to ShellExecutor::isAvailable() rather than checking
     * exec() specifically: shared hosts commonly disable only a subset of
     * exec()/shell_exec()/system()/passthru(), and locate() below now runs
     * through whichever of them ShellExecutor picks — so this must ask the
     * same question, or it would report "unavailable" on a host where
     * locate() is actually working fine via system().
     */
    public static function isExecAvailable(): bool
    {
        return ShellExecutor::isAvailable();
    }

    private static function locate(string $name): string|false
    {
        $result = ShellExecutor::run('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
        $firstLine = strtok($result['output'], "\n");
        if ($result['returnCode'] === 0 && $firstLine !== false && $firstLine !== '') {
            return $firstLine;
        }

        foreach (self::COMMON_BIN_DIRS as $dir) {
            $candidate = $dir . '/' . $name;
            // Deliberately not is_file()/is_executable(): shared hosting
            // commonly restricts PHP's own filesystem functions to the
            // user's home directory via open_basedir, which makes both
            // return false for anything under /usr — even though that
            // restriction never applies to what a spawned subprocess can
            // run. Invoking the candidate directly is the only check that
            // reflects what actually running it will do.
            $candidateResult = ShellExecutor::run(escapeshellarg($candidate) . ' --version 2>/dev/null');
            if ($candidateResult['returnCode'] === 0) {
                return $candidate;
            }
        }

        return false;
    }
}
