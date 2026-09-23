<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The modules this repository ships must be shelved the way the mockup
 * draws them — name, category and description.
 *
 * See Tests\Core\Module\ModulesPageMockup for why the expected value is
 * read from a design document rather than from the manifests.
 */
final class ModulesPageMockupTest extends TestCase
{
    /**
     * The two the mockup deliberately leaves out, named rather than
     * filtered: a rule saying « skip whatever the mockup omits » would
     * absorb a module genuinely lost from the drawing without a word.
     *
     * @var array<string, string>
     */
    private const NOT_DRAWN = [
        'support_dashboard' => 'visible_when — the installation collecting support statistics',
        'test_tools' => 'visible_when — reference and local installations',
    ];

    /** @return array<string, ModuleManifest> */
    private static function shipped(): array
    {
        $manifests = [];
        foreach (glob(dirname(__DIR__, 3) . '/modules/*/module.json') ?: [] as $path) {
            $manifests[basename(dirname($path))] = ModuleManifest::fromFile($path);
        }

        self::assertNotSame([], $manifests, 'No module manifest was read — the glob broke.');

        return $manifests;
    }

    /**
     * The shape the reading must find. A mockup that stopped parsing
     * would yield no categories and no modules, and every comparison
     * below would pass on nothing — the failure this whole file exists
     * to avoid.
     */
    public function testTheMockupStillReadsAsSevenShelvesAndTwentyTwoModules(): void
    {
        $this->assertCount(7, ModulesPageMockup::categories());
        $this->assertCount(22, ModulesPageMockup::modules());
    }

    /**
     * The closed vocabulary is the mockup's, ids and French labels
     * alike, in the same order — the page draws the shelves in
     * declaration order, so the order is part of the design.
     */
    public function testTheCodeDeclaresTheShelvesTheMockupDraws(): void
    {
        $this->assertSame(ModulesPageMockup::categories(), ModuleManifest::CATEGORIES);
    }

    /** Every shipped module sits on the shelf the mockup puts it on. */
    public function testEveryModuleIsShelvedWhereTheMockupPutsIt(): void
    {
        $drawn = ModulesPageMockup::modules();

        $misplaced = [];
        foreach (self::shipped() as $id => $manifest) {
            if (isset(self::NOT_DRAWN[$id])) {
                continue;
            }

            $this->assertArrayHasKey(
                $manifest->name,
                $drawn,
                "Module '{$id}' ships as « {$manifest->name} », which the mockup does not draw."
            );

            if ($drawn[$manifest->name][0] !== $manifest->category) {
                $misplaced[] = sprintf(
                    '%s: on « %s », the mockup puts it on « %s »',
                    $manifest->name,
                    $manifest->category,
                    $drawn[$manifest->name][0]
                );
            }
        }

        $this->assertSame([], $misplaced, implode("\n", $misplaced));
    }

    /**
     * And it says what the mockup says it says. The descriptions are the
     * only text on this page that explains a module to somebody deciding
     * whether to switch it off, so they are part of the design rather
     * than incidental prose.
     */
    public function testEveryModuleDescribesItselfAsTheMockupDoes(): void
    {
        $drawn = ModulesPageMockup::modules();

        $drift = [];
        foreach (self::shipped() as $id => $manifest) {
            if (isset(self::NOT_DRAWN[$id]) || !isset($drawn[$manifest->name])) {
                continue;
            }

            if ($drawn[$manifest->name][1] !== $manifest->description) {
                $drift[] = sprintf(
                    "%s:\n  ships:  %s\n  mockup: %s",
                    $manifest->name,
                    $manifest->description,
                    $drawn[$manifest->name][1]
                );
            }
        }

        $this->assertSame([], $drift, implode("\n", $drift));
    }

    /**
     * Neither direction may drift: the mockup must not promise a module
     * the repository does not ship. A rename applied to the drawing and
     * forgotten in a manifest lands exactly here.
     */
    public function testTheMockupPromisesNoModuleTheRepositoryDoesNotShip(): void
    {
        $names = array_map(
            static fn(ModuleManifest $m): string => $m->name,
            self::shipped()
        );

        $promised = array_diff(array_keys(ModulesPageMockup::modules()), $names);

        $this->assertSame([], array_values($promised), 'The mockup draws modules that do not exist.');
    }

    /**
     * Everything on disk is either drawn or named with its reason. A
     * module added without a decision about where it belongs fails here
     * rather than landing quietly in « Technique ».
     */
    public function testEveryShippedModuleIsEitherDrawnOrNamedWithItsReason(): void
    {
        $drawn = ModulesPageMockup::modules();

        $unaccounted = [];
        foreach (self::shipped() as $id => $manifest) {
            if (!isset($drawn[$manifest->name]) && !isset(self::NOT_DRAWN[$id])) {
                $unaccounted[] = $id;
            }
        }

        $this->assertSame([], $unaccounted, 'Modules neither drawn by the mockup nor listed as not drawn.');
    }

    /** The two exceptions are real modules, and they really are hidden. */
    public function testTheTwoUndrawnModulesAreTheOnesTheirFlagsHide(): void
    {
        $shipped = self::shipped();

        foreach (self::NOT_DRAWN as $id => $reason) {
            $this->assertArrayHasKey($id, $shipped, "'{$id}' is listed as not drawn but does not exist.");
            $this->assertNotSame(
                [],
                $shipped[$id]->visibleWhen,
                "'{$id}' is listed as not drawn for a visible_when it does not declare."
            );
        }
    }
}
