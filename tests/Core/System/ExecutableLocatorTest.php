<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Core\System\ExecutableLocator;
use PHPUnit\Framework\TestCase;

class ExecutableLocatorTest extends TestCase
{
    public function testFindReturnsAnAbsolutePathForACommonlyAvailableBinary(): void
    {
        // `ls` is available in every environment this test suite runs in
        // (macOS dev machines and Linux CI/hosting alike) and reliably on
        // PATH, unlike mysqldump — a good stand-in that doesn't depend on
        // any of the shared-hosting quirks this class exists to work around.
        $path = ExecutableLocator::find('ls');

        $this->assertNotNull($path);
        $this->assertTrue(is_executable($path));
    }

    public function testFindReturnsNullForANameThatDoesNotExistAnywhere(): void
    {
        $this->assertNull(ExecutableLocator::find('this-binary-does-not-exist-anywhere-12345'));
    }
}
