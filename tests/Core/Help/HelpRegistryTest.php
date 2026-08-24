<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpException;
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
        $this->writeTopic($coreDir, 'doublon');
        $moduleDir = $this->makeTopicDir();
        $this->writeTopic($moduleDir, 'doublon');

        $registry = new HelpRegistry($coreDir);
        $registry->registerModuleTopics('gallery', $moduleDir);

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage("Duplicate help topic id 'doublon'");
        $registry->all();
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
