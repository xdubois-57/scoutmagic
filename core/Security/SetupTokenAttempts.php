<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * The setup wizard's token gate, counted on the server (issue #1270).
 *
 * Before the site is initialized there is no database and no account: the
 * only thing standing between a visitor and the wizard is the token in
 * `token.php`. The count of wrong guesses used to live in the visitor's
 * session, so throwing the cookie away started it again. It now lives in
 * the very file `bootstrap/bootstrap.php` keeps it in (`.bootstrap-access.php`,
 * beside `token.php`), under the same exclusive lock and the same rules:
 *
 * - the count is **global** — not per session, not per address;
 * - the attempt is **recorded before** the comparison, and an attempt that
 *   cannot be recorded (a folder refusing the file, a lock that cannot be
 *   taken) is **not compared at all** — otherwise a folder that refuses the
 *   file would reset the count on every request and the ladder would never
 *   engage;
 * - the read-count-write runs under the lock, so parallel guesses each count;
 * - the token is exactly 64 hexadecimal characters, on both sides of the
 *   comparison, and is never echoed back.
 *
 * The bootstrap cannot load this class (it runs before `vendor/` exists),
 * so it carries its own copy of the file names, the keys and the ladder;
 * `tests/Bootstrap/BootstrapHandoffContractTest` pins the two together.
 * The file also holds other things the bootstrap decides (HTTPS verified,
 * release asked for): they are read and written back untouched.
 *
 * Nothing changes after initialization: the wizard deletes `token.php`
 * (and, here, this file) and `/setup` moves to the `superadmin` role.
 */
final class SetupTokenAttempts
{
    /** Same name as bootstrap/bootstrap.php's BOOTSTRAP_ACCESS_FILE. */
    public const ACCESS_FILE = '.bootstrap-access.php';
    /** Same name as bootstrap/bootstrap.php's BOOTSTRAP_ACCESS_LOCK_FILE. */
    public const LOCK_FILE = '.bootstrap-access.lock';
    public const KEY_ATTEMPTS = 'token_attempts';
    public const KEY_LOCKED_UNTIL = 'token_locked_until';

    private const TOKEN_PATTERN = '[0-9a-f]{64}';

    /** @param string $dir the folder holding `token.php` */
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * The token in `token.php`'s content, lower-cased — or '' when there is
     * none of exactly 64 hexadecimal characters (a short or long one in a
     * hand-written file is no token).
     */
    public static function tokenFrom(string $fileContent): string
    {
        // The lookahead refuses a longer run: the first 64 characters of 65 are no token.
        return preg_match('/TOKEN:\s*(' . self::TOKEN_PATTERN . ')(?![0-9a-f])/i', $fileContent, $matches) === 1
            ? strtolower($matches[1])
            : '';
    }

    /** When the gate reopens, or 0 when it is not locked at `$now`. */
    public function lockedUntil(int $now): int
    {
        $lockedUntil = (int) ($this->read()[self::KEY_LOCKED_UNTIL] ?? 0);

        return $lockedUntil > $now ? $lockedUntil : 0;
    }

    /**
     * One typed token: accepted, refused with the attempt counted, or
     * refused unread while locked.
     *
     * @param string $submitted what the visitor typed
     * @param string $expected  {@see tokenFrom()} of `token.php`, '' when absent
     * @return array{ok: bool, error?: string, locked_until?: int, attempts?: int}
     */
    public function verify(string $submitted, string $expected, int $now): array
    {
        $lock = @fopen($this->dir . '/' . self::LOCK_FILE, 'c');
        if ($lock === false) {
            return ['ok' => false, 'error' => self::notWritableError()];
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return ['ok' => false, 'error' => self::notWritableError()];
        }

        try {
            return $this->verifyLocked($submitted, $expected, $now);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Forgets the count and its lock — once `token.php` itself is gone. */
    public function clear(): void
    {
        @unlink($this->dir . '/' . self::ACCESS_FILE);
        @unlink($this->dir . '/' . self::LOCK_FILE);
    }

    /**
     * @return array{ok: bool, error?: string, locked_until?: int, attempts?: int}
     */
    private function verifyLocked(string $submitted, string $expected, int $now): array
    {
        $access = $this->read();
        $lockedUntil = (int) ($access[self::KEY_LOCKED_UNTIL] ?? 0);
        if ($lockedUntil > $now) {
            return [
                'ok' => false,
                'locked_until' => $lockedUntil,
                'error' => 'Trop de tentatives — nouvel essai possible dans '
                    . self::delayLabel($lockedUntil - $now) . '.',
            ];
        }

        $attempts = (int) ($access[self::KEY_ATTEMPTS] ?? 0) + 1;
        $access[self::KEY_ATTEMPTS] = $attempts;
        if (!$this->write($access)) {
            return ['ok' => false, 'error' => self::notWritableError()];
        }

        $submitted = strtolower(trim($submitted));
        if (
            preg_match('/^' . self::TOKEN_PATTERN . '$/', $expected) === 1
            && preg_match('/^' . self::TOKEN_PATTERN . '$/', $submitted) === 1
            && hash_equals($expected, $submitted)
        ) {
            unset($access[self::KEY_ATTEMPTS], $access[self::KEY_LOCKED_UNTIL]);
            $this->write($access);

            return ['ok' => true];
        }

        $seconds = self::lockSeconds($attempts);
        if ($seconds > 0) {
            $access[self::KEY_LOCKED_UNTIL] = $now + $seconds;
        }
        $this->write($access);

        return $seconds > 0
            ? [
                'ok' => false,
                'attempts' => $attempts,
                'locked_until' => $now + $seconds,
                'error' => 'Jeton invalide. Trop de tentatives — nouvel essai possible dans '
                    . self::delayLabel($seconds) . '.',
            ]
            : ['ok' => false, 'attempts' => $attempts, 'error' => 'Jeton invalide.'];
    }

    /** 4, 6, 8 and 10 failures lock for one minute, five, thirty, then a day. */
    private static function lockSeconds(int $attempts): int
    {
        return match (true) {
            $attempts >= 10 => 86400,
            $attempts >= 8 => 1800,
            $attempts >= 6 => 300,
            $attempts >= 4 => 60,
            default => 0,
        };
    }

    private static function delayLabel(int $seconds): string
    {
        return match (true) {
            $seconds >= 3600 => (int) ceil($seconds / 3600) . ' h',
            $seconds >= 60 => (int) ceil($seconds / 60) . ' min',
            default => max(1, $seconds) . ' s',
        };
    }

    private static function notWritableError(): string
    {
        return "Ce dossier n'est pas accessible en écriture pour PHP : les tentatives ne peuvent pas être comptées, "
            . "le jeton n'est donc pas vérifié. Donnez à PHP le droit d'écrire dans ce dossier, "
            . 'puis rechargez la page.';
    }

    /**
     * The file's JSON, in the comment the bootstrap writes it in.
     *
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->dir . '/' . self::ACCESS_FILE;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || preg_match('/\/\*(.*)\*\//s', $raw, $matches) !== 1) {
            return [];
        }
        $data = json_decode(trim($matches[1]), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $access */
    private function write(array $access): bool
    {
        $json = json_encode($access, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return @file_put_contents($this->dir . '/' . self::ACCESS_FILE, "<?php\n/*\n{$json}\n*/\n") !== false;
    }
}
