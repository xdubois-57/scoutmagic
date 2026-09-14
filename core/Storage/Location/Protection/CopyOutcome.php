<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

/**
 * What happened to one file a pass tried to copy.
 *
 * **Four answers and not two**, because a pass has to tell apart things
 * that look alike from the outside. « Paused » is not a failure — the
 * night's budget ran out mid-file and the next run picks it up where the
 * partial object ends. « Refused » is not a failure either: the file
 * cannot be carried to this destination at all, and saying so once is
 * worth more than retrying it every night for ever. Only « failed » is a
 * failure, and D14 says what to do with one: note it, move to the next
 * file, try again tomorrow.
 */
final class CopyOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly int $bytesTransferred = 0,
        public readonly ?string $md5 = null,
        public readonly ?string $reason = null
    ) {
    }

    public const COMPLETED = 'completed';
    public const PAUSED = 'paused';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    public static function completed(int $bytesTransferred, ?string $md5): self
    {
        return new self(self::COMPLETED, $bytesTransferred, $md5);
    }

    public static function paused(int $bytesTransferred): self
    {
        return new self(self::PAUSED, $bytesTransferred);
    }

    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, 0, null, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, 0, null, $reason);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isPaused(): bool
    {
        return $this->status === self::PAUSED;
    }
}
