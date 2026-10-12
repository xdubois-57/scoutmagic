<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Offline\OfflinePageProvider;
use Core\Offline\OfflineWhitelist;
use PHPUnit\Framework\TestCase;

/**
 * Every provider an offline entry names is a real provider, registered in
 * the composition root (#883).
 *
 * An entry names its provider by class in `module.json`; the instance is
 * registered from `public/index.php`. A provider declared and never
 * registered contributes nothing — Core\Offline\OfflineManifestService
 * does not fail the whole manifest over one module's wiring — so without
 * this test, a module's offline pages could silently never be downloaded.
 */
final class OfflinePageProvidersAreWiredTest extends TestCase
{
    public function testEveryDeclaredProviderIsAProviderAndIsRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        $index = (string) file_get_contents($root . '/public/index.php');

        $declared = [];
        foreach ((new OfflineWhitelist())->getAllEntries() as $entry) {
            if ($entry['provider'] !== null) {
                $declared[$entry['provider']] = 'core';
            }
        }
        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            foreach ($data['offline'] ?? [] as $entry) {
                if (isset($entry['provider'])) {
                    $declared[$entry['provider']] = basename(dirname($manifest));
                }
            }
        }

        $this->assertNotEmpty($declared, 'the core declares providers of its own, so this cannot be empty');
        foreach ($declared as $class => $owner) {
            $this->assertTrue(class_exists($class), "{$owner}: offline provider {$class} does not exist");
            $this->assertTrue(
                is_subclass_of($class, OfflinePageProvider::class),
                "{$owner}: {$class} does not implement OfflinePageProvider"
            );
            $this->assertStringContainsString(
                '$offlinePageProviders->register(new \\' . $class . '(',
                $index,
                "{$owner}: {$class} is declared in an offline entry but never registered in public/index.php"
            );
        }
    }
}
