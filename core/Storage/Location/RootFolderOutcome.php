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
 * la corbeille »); failed says that the deletion in ScoutMagic stands
 * anyway and what the operator may want to do by hand — a Drive that is
 * unreachable for an hour must not keep a location alive.
 *
 * **A rename is the other way round** (#832): a folder that could not be
 * renamed refuses the rename itself, and the location keeps its old
 * label ({@see StorageLocationService::update()}). So the rename never
 * reports a failure here; it reports done, nothing to do, or a folder
 * shared with another location that was deliberately left alone.
 *
 * `$reason` is a French sentence fit for the screen; `$detail` is what the
 * journal keeps beside it — the provider's own words, never displayed.
 */
final class RootFolderOutcome
{
    private const NOTHING = 'nothing';
    private const DONE = 'done';
    private const FAILED = 'failed';
    private const SHARED = 'shared';
    private const DISCONNECTED = 'disconnected';

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

    /**
     * The folder is also another location's, so it was left alone
     * (#474): a location connected before each got its own folder found
     * the old one by NAME, and two of them on one account share it still.
     * $reason carries the other location's label.
     */
    public static function sharedWith(string $otherLabel): self
    {
        return new self(self::SHARED, $otherLabel);
    }

    /**
     * The location was disconnected from Google Drive: its folder is
     * still there (« Déraccorder » keeps its id, so a reconnection finds
     * it), but the site has no grant left to rename or trash it. Not a
     * fault — an ordinary state the administrator put it in.
     */
    public static function disconnected(): self
    {
        return new self(self::DISCONNECTED);
    }

    public function isDisconnected(): bool
    {
        return $this->state === self::DISCONNECTED;
    }

    public function isShared(): bool
    {
        return $this->state === self::SHARED;
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
