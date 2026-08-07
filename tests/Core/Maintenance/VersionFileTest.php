<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\VersionFile;
use PHPUnit\Framework\TestCase;

class VersionFileTest extends TestCase
{
    public function testIsNewerThanComparesStableVersionsWithVersionCompare(): void
    {
        $this->assertTrue(VersionFile::isNewerThan('1.0.22', '1.0.21'));
        $this->assertTrue(VersionFile::isNewerThan('2.0.0', '1.9.9'));
        $this->assertFalse(VersionFile::isNewerThan('1.0.20', '1.0.21'));
        $this->assertFalse(VersionFile::isNewerThan('1.0.21', '1.0.21'));
    }

    public function testIsNewerThanNeverReportsAStableReleaseAsNewerThanAnInstalledDevBuild(): void
    {
        $this->assertFalse(VersionFile::isNewerThan('1.0.22', 'dev-a1b2c3d'));
        $this->assertFalse(VersionFile::isNewerThan('99.0.0', 'dev-8f3a2c1'));
    }

    public function testIsDevBuildRecognizesTheDevFormat(): void
    {
        $this->assertTrue(VersionFile::isDevBuild('dev-a1b2c3d'));
        $this->assertTrue(VersionFile::isDevBuild('dev-0123456789abcdef0123456789abcdef01234567'));
        $this->assertFalse(VersionFile::isDevBuild('1.0.22'));
        $this->assertFalse(VersionFile::isDevBuild(''));
        $this->assertFalse(VersionFile::isDevBuild('dev-'));
        $this->assertFalse(VersionFile::isDevBuild('dev-nothex'));
    }
}