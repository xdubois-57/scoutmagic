<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\GitHubReleaseClient;
use PHPUnit\Framework\TestCase;

/**
 * Only selectZipAssetUrl() is covered here — the rest of
 * GitHubReleaseClient makes a real network call with no injectable
 * transport, so it isn't unit-testable without hitting the network. This
 * is the piece that actually had the bug: scripts/release.sh publishes
 * bootstrap.php as a second release asset, and GitHub does not preserve
 * upload order in the assets array (observed: it sorts alphabetically,
 * landing bootstrap.php before the release zip at assets[0]) — picking
 * assets[0] unconditionally silently offered bootstrap.php itself as the
 * "update" download, which every live install's Configuration >
 * Maintenance update check and the auto-update webhook both go through.
 */
class GitHubReleaseClientTest extends TestCase
{
    public function testSelectsZipRegardlessOfPositionInAssetsArray(): void
    {
        $assets = [
            ['name' => 'bootstrap.php', 'browser_download_url' => 'https://example.com/bootstrap.php'],
            ['name' => 'release-v1.0.1.zip', 'browser_download_url' => 'https://example.com/release-v1.0.1.zip'],
        ];

        $this->assertSame(
            'https://example.com/release-v1.0.1.zip',
            GitHubReleaseClient::selectZipAssetUrl($assets)
        );
    }

    public function testFindsZipEvenWhenListedFirst(): void
    {
        $assets = [
            ['name' => 'release-v1.0.1.zip', 'browser_download_url' => 'https://example.com/release-v1.0.1.zip'],
            ['name' => 'bootstrap.php', 'browser_download_url' => 'https://example.com/bootstrap.php'],
        ];

        $this->assertSame(
            'https://example.com/release-v1.0.1.zip',
            GitHubReleaseClient::selectZipAssetUrl($assets)
        );
    }

    public function testReturnsNullWhenNoZipNamedAssetPresent(): void
    {
        $assets = [
            ['name' => 'bootstrap.php', 'browser_download_url' => 'https://example.com/bootstrap.php'],
        ];

        $this->assertNull(GitHubReleaseClient::selectZipAssetUrl($assets));
    }

    public function testReturnsNullForEmptyAssetsArray(): void
    {
        $this->assertNull(GitHubReleaseClient::selectZipAssetUrl([]));
    }

    public function testIgnoresMalformedAssetEntries(): void
    {
        $assets = [
            'not-an-array',
            ['name' => 'release-v1.0.1.zip', 'browser_download_url' => 'https://example.com/release-v1.0.1.zip'],
        ];

        $this->assertSame(
            'https://example.com/release-v1.0.1.zip',
            GitHubReleaseClient::selectZipAssetUrl($assets)
        );
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $assets = [
            ['name' => 'Release-V1.0.1.ZIP', 'browser_download_url' => 'https://example.com/release-v1.0.1.zip'],
        ];

        $this->assertSame(
            'https://example.com/release-v1.0.1.zip',
            GitHubReleaseClient::selectZipAssetUrl($assets)
        );
    }
}
