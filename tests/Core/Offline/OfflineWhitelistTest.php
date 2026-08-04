<?php

declare(strict_types=1);

namespace Tests\Core\Offline;

use Core\Offline\OfflineWhitelist;
use PHPUnit\Framework\TestCase;

class OfflineWhitelistTest extends TestCase
{
    public function testEveryEntryHasAPathAndALabel(): void
    {
        foreach (OfflineWhitelist::getPaths() as $entry) {
            $this->assertArrayHasKey('path', $entry);
            $this->assertArrayHasKey('label', $entry);
            $this->assertNotSame('', $entry['path']);
            $this->assertNotSame('', $entry['label']);
        }
    }

    public function testIncludesExactlyTheSpecifiedPages(): void
    {
        $paths = array_column(OfflineWhitelist::getPaths(), 'path');

        $this->assertSame(
            ['/', '/contact', '/sections', '/rgpd', '/calendar', '/notifications', '/trombinoscope'],
            $paths
        );
    }

    public function testNeverIncludesFileServingOrPrivateRoutes(): void
    {
        $paths = array_column(OfflineWhitelist::getPaths(), 'path');

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('/files/', $path);
            $this->assertStringNotContainsString('/members/', $path);
            $this->assertStringNotContainsString('/finance', $path);
            $this->assertStringNotContainsString('/mass-mail', $path);
        }
    }

    public function testPathsHaveNoDuplicates(): void
    {
        $paths = array_column(OfflineWhitelist::getPaths(), 'path');

        $this->assertSame(array_unique($paths), $paths);
    }
}
