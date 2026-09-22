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
 * `category` is optional, and that is not the same as lenient.
 *
 * **Absent is a legitimate default.** A module that does not say where
 * it belongs is a tool, and « Technique » is the honest shelf for one —
 * refusing to load it over a missing shelf would make every third-party
 * module fail on a field it has never heard of.
 *
 * **Unknown is a mistake, and it fails loudly.** A manifest declaring
 * `"argnet"` has a typo. Filing it silently under « Technique » would
 * hide that typo for good: the module would work, sit on the wrong
 * shelf, and nothing would ever say why. Same treatment as an unknown
 * `menu` value, which this repository already refuses at load time.
 */
final class ModuleCategoryTest extends TestCase
{
    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private static function manifest(array $extra = []): array
    {
        return array_merge([
            'id' => 'demo',
            'name' => 'Démo',
            'version' => '1.0.0',
        ], $extra);
    }

    public function testAManifestThatNamesNoCategoryLandsUnderTechnique(): void
    {
        $manifest = ModuleManifest::fromArray(self::manifest());

        $this->assertSame('technique', $manifest->category);
        $this->assertArrayHasKey($manifest->category, ModuleManifest::CATEGORIES);
    }

    /** @return array<string, array{string}> */
    public static function knownCategories(): array
    {
        $cases = [];
        foreach (array_keys(ModuleManifest::CATEGORIES) as $id) {
            $cases[$id] = [$id];
        }

        return $cases;
    }

    /**
     * Every declared shelf is reachable. A vocabulary with an entry no
     * manifest could ever name would be a label nobody can use.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('knownCategories')]
    public function testEveryDeclaredCategoryIsAccepted(string $id): void
    {
        $this->assertSame($id, ModuleManifest::fromArray(self::manifest(['category' => $id]))->category);
    }

    public function testAnUnknownCategoryIsRefusedAtLoadTime(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("Module 'demo' invalid category value 'argnet'");

        ModuleManifest::fromArray(self::manifest(['category' => 'argnet']));
    }

    /**
     * The refusal names the known shelves. A message saying only « this
     * is invalid » leaves the author guessing at a vocabulary they
     * cannot see from their own module's directory.
     */
    public function testTheRefusalListsTheShelvesThatExist(): void
    {
        try {
            ModuleManifest::fromArray(self::manifest(['category' => 'argnet']));
            $this->fail('An unknown category must be refused.');
        } catch (ModuleException $e) {
            foreach (array_keys(ModuleManifest::CATEGORIES) as $known) {
                $this->assertStringContainsString($known, $e->getMessage());
            }
        }
    }

    /** A non-string is refused too, and says what it got rather than crashing. */
    public function testACategoryThatIsNotEvenAStringIsRefused(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("Module 'demo' invalid category value 'array'");

        ModuleManifest::fromArray(self::manifest(['category' => ['argent']]));
    }

    /**
     * The shelves are ordered, and the page draws them in that order, so
     * the order is part of the contract rather than an artefact of how
     * the array was typed.
     */
    public function testTheShelvesKeepTheOrderThePageDrawsThemIn(): void
    {
        $this->assertSame(
            ['communication', 'activites', 'membres', 'argent', 'services', 'site', 'technique'],
            array_keys(ModuleManifest::CATEGORIES)
        );
    }

    /** Every label is French, and no two shelves share one. */
    public function testEachShelfHasItsOwnFrenchLabel(): void
    {
        $labels = array_values(ModuleManifest::CATEGORIES);

        $this->assertSame($labels, array_unique($labels), 'Two shelves share a label.');
        foreach ($labels as $label) {
            $this->assertNotSame('', trim($label));
        }
    }
}
