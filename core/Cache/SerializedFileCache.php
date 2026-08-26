<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Cache;

/**
 * The one implementation of "persist a PHP value between requests as a
 * serialized file" — guarded unserialize (allowed classes only),
 * atomic tmp-file + rename write, and every failure mode a miss rather
 * than an error, because a cache that can take a request down is worse
 * than no cache. Grew two identical hand-rolled copies (the help index
 * and the module-manifest cache) the day it was needed twice; this is
 * the shared one.
 */
final class SerializedFileCache
{
    /**
     * @param class-string[] $allowedClasses the object classes the stored
     *        value may contain — anything else fails the read as a miss
     */
    public function __construct(
        private readonly string $filePath,
        private readonly array $allowedClasses,
    ) {
    }

    /**
     * The stored value, or null on ANY doubt: missing file, unserializable
     * content, a disallowed class. $validate, when given, gets the
     * decoded value and may veto it (return false) — the shape check that
     * turns "someone else's bytes" into a miss instead of a crash later.
     *
     * @param callable(mixed): bool|null $validate
     */
    public function read(?callable $validate = null): mixed
    {
        $raw = @file_get_contents($this->filePath);
        if ($raw === false) {
            return null;
        }

        $value = @unserialize($raw, ['allowed_classes' => $this->allowedClasses]);
        if ($value === false || ($validate !== null && !$validate($value))) {
            return null;
        }

        return $value;
    }

    /**
     * Atomically replaces the stored value. Failures are silent — the
     * next request simply rebuilds and tries again.
     */
    public function write(mixed $value): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        $tmp = $this->filePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize($value)) === false || !@rename($tmp, $this->filePath)) {
            @unlink($tmp);
        }
    }
}
