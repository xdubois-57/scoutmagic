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
 */
final class ShellExecutor
{
    /**
     * A marker only probe() writes, so a host that answers something
     * unexpected is not mistaken for a host that works.
     */
    private const PROBE_MARKER = 'scoutmagic_shell_probe_ok';

    /** @var array<string>|null */
    private static ?array $disabledFunctions = null;

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
     * @return array{declared: bool, works: bool, function: string|null, detail: string}
     */
    public static function probe(): array
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
            $result = self::run('echo ' . escapeshellarg(self::PROBE_MARKER) . ' 2>&1');
        } catch (\Throwable $e) {
            return [
                'declared' => true,
                'works' => false,
                'function' => $function,
                'detail' => $e::class . ': ' . $e->getMessage(),
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
     * @return array{returnCode: int, output: string}
     */
    public static function run(string $command): array
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
