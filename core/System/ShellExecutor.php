<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\System;

/**
 * Runs a shell command via whichever of exec()/shell_exec()/system()/
 * passthru() is actually callable on this host, in that preference order.
 *
 * Shared hosts commonly disable a *subset* of the four shell-execution
 * functions via disable_functions, not all of them uniformly — a mechanism
 * hardcoded to exec() alone (as ExecutableLocator/BackupService originally
 * were) can be completely blocked on a host where system() works fine.
 * Confirmed in practice: a script on another LWS-hosted account
 * successfully ran a shelled-out command via system() alone. Database
 * dumping no longer goes through here at all — only Core\Maintenance\
 * BackupService::restoreDatabase() (the `mysql` client) and
 * ExecutableLocator's own binary lookups still do.
 *
 * EVERY COMMAND HAS A TIME LIMIT (issue #791). Nothing else interrupts a
 * command that hangs — a security module that holds the process, an odd
 * account shell, a remote filesystem, a binary waiting on input — and in
 * the cron that is the worst place for it: the pass keeps CronPassLock until
 * the PHP process ends, every later pass exits silently, and the hosting
 * page keeps reading the cron as active. The limit is the coreutils
 * `timeout` utility wrapped around the command, because it works the same
 * through all four execution functions, whichever of them the host allows.
 * A host WITHOUT a working `timeout` keeps the old, unbounded behaviour:
 * nothing here can interrupt a command without it, and pretending otherwise
 * would be a promise the code cannot keep. proc_open() could bound it
 * exactly, but it is the function shared hosts disable most often.
 */
final class ShellExecutor
{
    /**
     * A marker only probe() writes, so a host that answers something
     * unexpected is not mistaken for a host that works.
     */
    private const PROBE_MARKER = 'scoutmagic_shell_probe_ok';

    /** What `timeout` returns when it had to stop the command. */
    public const TIMED_OUT_RETURN_CODE = 124;

    /**
     * Enough for a probe, a `command -v` or a `--version`; a caller with a
     * genuinely longer job passes its own.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    /** How long a command that ignores the polite stop gets before it is killed. */
    private const KILL_AFTER_SECONDS = 2;

    /** 128 + SIGKILL: what `timeout -k` returns once it had to kill the command. */
    private const KILLED_RETURN_CODE = 137;

    /** @var array<string>|null */
    private static ?array $disabledFunctions = null;

    /**
     * Where `timeout` was found by the first measurement: its name or an
     * absolute path, or '' when this host has none that works. Null until
     * measured.
     */
    private static ?string $timeoutBinary = null;

    /**
     * Tried in this order, the bare name last. The absolute paths come first
     * and are not decoration: on a host whose $PATH is empty or wrong — one
     * of the reasons probe() exists — the bare name is not found, and what
     * is kept after the first measurement must still work then, or the
     * bound would turn every command into "command not found".
     */
    private const TIMEOUT_CANDIDATES = ['/usr/bin/timeout', '/bin/timeout', '/usr/local/bin/timeout', 'timeout'];

    public static function isAvailable(): bool
    {
        return self::pickFunction() !== null;
    }

    /**
     * Whether shell execution DEMONSTRABLY works, by running a command
     * and checking what came back.
     *
     * isAvailable() answers a different, weaker question: whether one of
     * the four functions exists and is absent from `disable_functions`.
     * That is a declaration, and on a shared host it is regularly wrong in
     * the optimistic direction — the function is callable and the call
     * still gets nowhere, because a security module intercepts it, because
     * the account's shell is `/sbin/nologin`, because the filesystem is
     * mounted `noexec`, or because PATH is empty. Every one of those looks
     * exactly like "available" from the ini alone, and looks like a
     * missing binary from the outside.
     *
     * So this runs `echo`, a shell builtin needing no PATH and no
     * executable, and compares the output against a marker only this
     * method writes. Anything else — empty output, an error page, a
     * non-zero code, a throw — is reported as it came back rather than
     * summarised, because on a support question the exact refusal is the
     * whole answer.
     *
     * It is bounded like every other command: a shell that never answers is
     * reported as "interrupted after N s", not as "no output, code 124".
     *
     * @return array{declared: bool, works: bool, function: string|null, detail: string}
     */
    public static function probe(int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        $function = self::pickFunction();
        if ($function === null) {
            return [
                'declared' => false,
                'works' => false,
                'function' => null,
                'detail' => 'aucune des fonctions exec/shell_exec/system/passthru n\'est appelable',
            ];
        }

        try {
            $result = self::run('echo ' . escapeshellarg(self::PROBE_MARKER) . ' 2>&1', $timeoutSeconds);
        } catch (\Throwable $e) {
            return [
                'declared' => true,
                'works' => false,
                'function' => $function,
                'detail' => $e::class . ': ' . $e->getMessage(),
            ];
        }

        return self::probeVerdict($function, $result, $timeoutSeconds);
    }

    /**
     * What a probe's answer means.
     *
     * @param array{returnCode: int, output: string} $result
     * @return array{declared: bool, works: bool, function: string|null, detail: string}
     */
    private static function probeVerdict(string $function, array $result, int $timeoutSeconds): array
    {
        if ($result['returnCode'] === self::TIMED_OUT_RETURN_CODE) {
            return [
                'declared' => true,
                'works' => false,
                'function' => $function,
                'detail' => 'commande interrompue après ' . $timeoutSeconds . ' s sans réponse',
            ];
        }

        $output = trim($result['output']);
        if ($output === self::PROBE_MARKER) {
            return [
                'declared' => true,
                'works' => true,
                'function' => $function,
                'detail' => 'code ' . $result['returnCode'],
            ];
        }

        return [
            'declared' => true,
            'works' => false,
            'function' => $function,
            'detail' => $output === ''
                ? 'aucune sortie, code ' . $result['returnCode']
                : 'sortie inattendue (code ' . $result['returnCode'] . ') : ' . mb_substr($output, 0, 300),
        ];
    }

    /**
     * Runs $command, giving up on it after $timeoutSeconds where the host can.
     *
     * An interrupted command returns TIMED_OUT_RETURN_CODE with whatever
     * output it had written. On a host without a working `timeout` the
     * command runs to its end, however long that takes (see the class note).
     *
     * @return array{returnCode: int, output: string}
     */
    public static function run(string $command, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('A command timeout is at least one second.');
        }

        if (self::timeoutBinary() === '') {
            return self::runUnbounded($command);
        }

        $started = hrtime(true);
        $result = self::runUnbounded(self::bounded($command, $timeoutSeconds));

        // `timeout -k` answers 137 when it had to kill a command that
        // ignored the polite stop. Only a command that really reached its
        // limit is reported as interrupted: 137 on its own can be anyone
        // killing the process, and the limit has not then been reached.
        if (
            $result['returnCode'] === self::KILLED_RETURN_CODE
            && (hrtime(true) - $started) / 1e9 >= $timeoutSeconds
        ) {
            $result['returnCode'] = self::TIMED_OUT_RETURN_CODE;
        }

        return $result;
    }

    /**
     * Tests only: pretend the host has, or lacks, a working `timeout`.
     * Null goes back to measuring it.
     *
     * @internal
     */
    public static function forceTimeoutUtility(?bool $available): void
    {
        self::$timeoutBinary = $available === null ? null : ($available ? 'timeout' : '');
    }

    /**
     * The command inside `sh -c` so that redirections and `;` in it stay
     * inside the bound. The shell is named by its absolute path for the same
     * reason as `timeout` (see TIMEOUT_CANDIDATES). The shell_exec() backend
     * appends its exit-status marker OUTSIDE this string, where `$?` is
     * `timeout`'s own status — the command's, or TIMED_OUT_RETURN_CODE.
     */
    private static function bounded(string $command, int $timeoutSeconds): string
    {
        return self::boundedWith(self::timeoutBinary() ?: 'timeout', $command, $timeoutSeconds);
    }

    private static function boundedWith(string $binary, string $command, int $timeoutSeconds): string
    {
        return $binary . ' -k ' . self::KILL_AFTER_SECONDS . ' ' . $timeoutSeconds
            . ' /bin/sh -c ' . escapeshellarg($command);
    }

    /**
     * The `timeout` that works on this host, or '' when there is none.
     * Asking for its path would not be enough: an older BusyBox applet does
     * not take `-k`, and wrapping every command in a refused option would
     * break all of them. So the exact form is run once, on a command whose
     * answer is known, and the result kept for the process.
     */
    private static function timeoutBinary(): string
    {
        if (self::$timeoutBinary !== null) {
            return self::$timeoutBinary;
        }

        if (self::pickFunction() === null) {
            return '';
        }

        foreach (self::TIMEOUT_CANDIDATES as $candidate) {
            $answer = self::runUnbounded(
                self::boundedWith($candidate, 'echo ' . escapeshellarg(self::PROBE_MARKER), 5) . ' 2>/dev/null'
            );
            if ($answer['returnCode'] === 0 && trim($answer['output']) === self::PROBE_MARKER) {
                return self::$timeoutBinary = $candidate;
            }
        }

        return self::$timeoutBinary = '';
    }

    /**
     * @return array{returnCode: int, output: string}
     */
    private static function runUnbounded(string $command): array
    {
        return match (self::pickFunction()) {
            'exec' => self::runViaExec($command),
            'shell_exec' => self::runViaShellExec($command),
            'system' => self::runViaSystem($command),
            'passthru' => self::runViaPassthru($command),
            default => ['returnCode' => -1, 'output' => ''],
        };
    }

    /**
     * @return array{returnCode: int, output: string}
     */
    private static function runViaExec(string $command): array
    {
        $output = [];
        $returnCode = -1;
        @exec($command, $output, $returnCode);

        return ['returnCode' => $returnCode, 'output' => implode("\n", $output)];
    }

    /**
     * shell_exec() has no return-code parameter, so the exit status is
     * smuggled out via a trailing marker appended to the same shell
     * invocation — `$?` expands to the exit status of the command just
     * before the `;`, i.e. $command itself, before the shell runs echo.
     *
     * @return array{returnCode: int, output: string}
     */
    private static function runViaShellExec(string $command): array
    {
        $marker = '___SHELL_EXECUTOR_RC___';
        $raw = @shell_exec($command . '; echo ' . $marker . '$?');

        if ($raw === null) {
            return ['returnCode' => -1, 'output' => ''];
        }

        $pos = strrpos($raw, $marker);
        if ($pos === false) {
            return ['returnCode' => -1, 'output' => $raw];
        }

        return [
            'returnCode' => (int) substr($raw, $pos + strlen($marker)),
            'output' => rtrim(substr($raw, 0, $pos), "\n"),
        ];
    }

    /**
     * system() prints each output line directly to the current output
     * buffer as the command runs, rather than returning it — left
     * unbuffered, that would leak mysqldump's stdout/stderr straight into
     * the HTTP response (the exact class of bug fixed in bootstrap.php's
     * stray-output issue). ob_start()/ob_get_clean() captures it instead,
     * same as exec()'s $output array would.
     *
     * @return array{returnCode: int, output: string}
     */
    private static function runViaSystem(string $command): array
    {
        $returnCode = -1;
        ob_start();
        @system($command, $returnCode);
        $output = (string) ob_get_clean();

        return ['returnCode' => $returnCode, 'output' => $output];
    }

    /**
     * Same output-buffering rationale as runViaSystem(); passthru() is
     * binary-safe but otherwise behaves the same way for this purpose.
     *
     * @return array{returnCode: int, output: string}
     */
    private static function runViaPassthru(string $command): array
    {
        $returnCode = -1;
        ob_start();
        @passthru($command, $returnCode);
        $output = (string) ob_get_clean();

        return ['returnCode' => $returnCode, 'output' => $output];
    }

    private static function pickFunction(): ?string
    {
        foreach (['exec', 'shell_exec', 'system', 'passthru'] as $candidate) {
            if (self::isFunctionEnabled($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isFunctionEnabled(string $function): bool
    {
        if (!function_exists($function)) {
            return false;
        }

        if (self::$disabledFunctions === null) {
            self::$disabledFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        }

        return !in_array($function, self::$disabledFunctions, true);
    }
}
