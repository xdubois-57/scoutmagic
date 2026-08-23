<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\ModuleException;
use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * module.json's optional `help` section (Core\Help aggregation,
 * ARCHITECTURE.md §8.64) — its own file beside ModuleManifestTest so the
 * two can evolve without stepping on each other.
 */
class ModuleManifestHelpTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function manifestData(array $extra = []): array
    {
        return array_merge([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
        ], $extra);
    }

    public function testHelpIsOptionalAndDefaultsToNoOverride(): void
    {
        $manifest = ModuleManifest::fromArray($this->manifestData());

        // Null means "scan the default help/ directory if it exists" —
        // shipping topics never requires a manifest section at all.
        $this->assertNull($manifest->helpDirectory);
    }

    public function testAnEmptyHelpObjectSelectsTheDefaultDirectoryName(): void
    {
        // json_decode('{"help": {}}') yields an empty PHP array — valid,
        // and explicitly selects the default directory name.
        $manifest = ModuleManifest::fromArray($this->manifestData(['help' => []]));

        $this->assertSame('help', $manifest->helpDirectory);
    }

    public function testHelpDirOverridesTheDirectoryName(): void
    {
        $manifest = ModuleManifest::fromArray($this->manifestData(['help' => ['dir' => 'aide']]));

        $this->assertSame('aide', $manifest->helpDirectory);
    }

    public function testHelpMustBeAnObjectNotAList(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('help must be an object');

        ModuleManifest::fromArray($this->manifestData(['help' => ['help']]));
    }

    public function testHelpRejectsAnUnknownKey(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("unknown key 'directory'");

        ModuleManifest::fromArray($this->manifestData(['help' => ['directory' => 'help']]));
    }

    public function testHelpDirMustBeAPlainDirectoryName(): void
    {
        // Never a path: the topics always live inside the module's own tree.
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('help.dir must be a plain directory name');

        ModuleManifest::fromArray($this->manifestData(['help' => ['dir' => '../outside']]));
    }
}
