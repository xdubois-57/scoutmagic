<?php

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * A single published (non-draft, non-prerelease) GitHub release, as
 * returned by GET /repos/{owner}/{repo}/releases/latest.
 */
final class ReleaseInfo
{
    public function __construct(
        public readonly string $tagName,
        public readonly string $body,
        public readonly string $htmlUrl,
        public readonly ?string $downloadUrl
    ) {
    }

    /**
     * The tag with any leading "v" stripped (release.sh tags as "v1.2.3",
     * VERSION and version_compare() everywhere else use the bare "1.2.3").
     */
    public function version(): string
    {
        return ltrim($this->tagName, 'vV');
    }
}
