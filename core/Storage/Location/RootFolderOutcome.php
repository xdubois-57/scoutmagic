<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * What happened to a location's own folder when the location was renamed
 * or deleted (#474, {@see Backend\ManagedRootFolderBackend}).
 *
 * **Three answers, because the screen says three different things.**
 * Nothing to do (a local folder, a bucket, a Drive location never
 * connected) says nothing; done says where the folder went (« placé dans
 * la corbeille »); failed says that the rename or the deletion in
 * ScoutMagic stands anyway and what the operator may want to do by hand.
 * A failure never undoes the decision it follows: the label is the
 * administrator's, and a Drive that is unreachable for an hour must not
 * refuse them a rename or keep a location alive.
 *
 * `$reason` is a French sentence fit for the screen; `$detail` is what the
 * journal keeps beside it — the provider's own words, never displayed.
 */
final class RootFolderOutcome
{
    private const NOTHING = 'nothing';
    private const DONE = 'done';
    private const FAILED = 'failed';

    private function __construct(
        private readonly string $state,
        public readonly ?string $reason = null,
        public readonly ?string $detail = null
    ) {
    }

    public static function nothingToDo(): self
    {
        return new self(self::NOTHING);
    }

    public static function done(): self
    {
        return new self(self::DONE);
    }

    public static function failed(string $reason, string $detail): self
    {
        return new self(self::FAILED, $reason, $detail);
    }

    public function isDone(): bool
    {
        return $this->state === self::DONE;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }
}
