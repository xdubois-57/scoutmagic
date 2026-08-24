<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpRegistry;
use PHPUnit\Framework\TestCase;

class HelpRegistryTest extends TestCase
{
    use HelpTopicFileFixtures;

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    public function testAggregatesCoreAndModuleTopics(): void
    {
        $coreDir = $this->makeTopicDir();
        $this->writeTopic($coreDir, 'sujet-core');
        $moduleDir = $this->makeTopicDir();
        $this->writeTopic($moduleDir, 'sujet-module');

        $registry = new HelpRegistry($coreDir);
        $registry->registerModuleTopics('calendar', $moduleDir);

        $topics = $registry->all();
        $this->assertArrayHasKey('sujet-core', $topics);
        $this->assertArrayHasKey('sujet-module', $topics);
        $this->assertNull($topics['sujet-core']->moduleId);
        $this->assertSame('calendar', $topics['sujet-module']->moduleId);
    }

    public function testAnUnregisteredModuleContributesNothing(): void
    {
        // A disabled module never calls registerModuleTopics() — its
        // topics must be absent, not filtered.
        $coreDir = $this->makeTopicDir();
        $this->writeTopic($coreDir, 'sujet-core');
        $moduleDir = $this->makeTopicDir();
        $this->writeTopic($moduleDir, 'sujet-module');

        $registry = new HelpRegistry($coreDir);

        $this->assertArrayNotHasKey('sujet-module', $registry->all());
    }

    public function testAnIdCollisionIsALoadErrorNeverASilentOverwrite(): void
    {
        $coreDir = $this->makeTopicDir();
        $coreFile = $this->writeTopic($coreDir, 'doublon');
        $moduleDir = $this->makeTopicDir();
        $this->writeTopic($moduleDir, 'doublon');

        $registry = new HelpRegistry($coreDir);
        $registry->registerModuleTopics('gallery', $moduleDir);

        // Reported, and deterministic: core is scanned first, so the core
        // file is the one that survives however the modules are ordered.
        $this->assertCount(1, $registry->loadErrors());
        $this->assertStringContainsString("Duplicate help topic id 'doublon'", $registry->loadErrors()[0]);
        $this->assertSame($coreFile, $registry->all()['doublon']->filePath);
    }

    /**
     * The site-down regression: Core\Http\FrontController builds the help
     * panel on every GET, so a topic the parser refuses used to return 500
     * on every page and every API endpoint at once. One bad file may cost
     * its own topic and nothing more.
     */
    public function testAnUnparseableTopicCostsOnlyItself(): void
    {
        $coreDir = $this->makeTopicDir();
        $this->writeTopic($coreDir, 'sujet-sain');
        $this->writeTopic($coreDir, 'sujet-casse', ['paths' => '/mes-locations/*/calendrier/']);

        $registry = new HelpRegistry($coreDir);

        $this->assertArrayHasKey('sujet-sain', $registry->all());
        $this->assertArrayNotHasKey('sujet-casse', $registry->all());
        $this->assertCount(1, $registry->loadErrors());
        $this->assertStringContainsString('sujet-casse', $registry->loadErrors()[0]);
    }

    public function testAHealthyCorpusReportsNoLoadError(): void
    {
        $coreDir = $this->makeTopicDir();
        $this->writeTopic($coreDir, 'sujet-core');

        $this->assertSame([], (new HelpRegistry($coreDir))->loadErrors());
    }

    public function testAMissingCoreDirectoryYieldsAnEmptyRegistry(): void
    {
        $registry = new HelpRegistry(sys_get_temp_dir() . '/does_not_exist_' . uniqid('', true));

        $this->assertSame([], $registry->all());
    }

    public function testTopicsRegisteredAfterAFirstReadAreStillPickedUp(): void
    {
        $coreDir = $this->makeTopicDir();
        $this->writeTopic($coreDir, 'sujet-core');
        $registry = new HelpRegistry($coreDir);
        $this->assertCount(1, $registry->all());

        $moduleDir = $this->makeTopicDir();
        $this->writeTopic($moduleDir, 'sujet-module');
        $registry->registerModuleTopics('news', $moduleDir);

        $this->assertCount(2, $registry->all());
    }
}
