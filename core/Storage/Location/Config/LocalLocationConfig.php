<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Config;

use Core\Storage\Location\StorageLocationService;

/**
 * A directory on a filesystem this server can see.
 *
 * `$path` is relative to `storage/` — the ordinary case, `storage/modules/
 * gallery`, needing no configuration at all — or absolute, which is how a
 * network mount or a second volume is reached. Both are legitimate and the
 * distinction is resolved once, by
 * `Core\Storage\Location\Backend\StorageBackendFactory`, so that nothing
 * else in the codebase has to know the rule.
 *
 * An absolute path OUTSIDE `storage/` has a consequence an administrator
 * has to be told about at the moment they type it: a full reset of the
 * site empties `storage/` and cannot reach anything else, so such a
 * directory survives a reset — which is a protection for the photos and a
 * surprise for whoever expected the site to be blank afterwards.
 */
final class LocalLocationConfig implements LocationConfig
{
    public function __construct(
        public readonly string $path
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        // The same folder StorageLocationService::DEFAULT_PATH names, and
        // for its reasons: a record with no path is one this version could
        // not read, and answering with a directory that holds nothing
        // would be worse than answering with the one that does.
        $path = isset($raw['path']) && is_string($raw['path']) && $raw['path'] !== ''
            ? $raw['path']
            : StorageLocationService::DEFAULT_PATH;

        return new self($path);
    }

    public function toArray(): array
    {
        return ['path' => $this->path];
    }

    public function isAbsolute(): bool
    {
        return self::isAbsolutePath($this->path);
    }

    /**
     * The same question about a bare string — **one definition of
     * « absolute », reachable by everything that needs it.**
     *
     * It was a private judgement inside this class, and three other places
     * ended up re-deciding it: the browser warning on the location form,
     * which missed a UNC path; the support collector's path masking, which
     * missed a drive letter and therefore exported `C:\Users\…` verbatim
     * into an archive that goes to a third party; and this method. A rule
     * spelled out three times is a rule that will differ in three ways.
     */
    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    public function describe(): string
    {
        return $this->isAbsolute() ? $this->path : 'storage/' . ltrim($this->path, '/');
    }

    /**
     * Never: the local disk hands out no URL at all — the consumer serves
     * these bytes through its own access-controlled route.
     */
    public function servesPubliclyWithoutExpiry(): bool
    {
        return false;
    }
}
