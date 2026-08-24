<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * A setting nothing reads is a promise the configuration page makes and
 * the application does not keep.
 *
 * That is a worse failure than a missing feature, because there is no way
 * for a unit to notice: the field is there, it has a label, it has a
 * helpful description, saving it works, and nothing changes. It happens
 * the ordinary way — a setting declared while a feature is being designed,
 * the feature landing differently, and the manifest keeping the row.
 *
 * The rule this pins: every key a module declares in `settings` is named
 * somewhere in that module OUTSIDE its own configuration surface (the
 * config controller that saves it, and the config template that renders
 * the form). Being read by a service, a task, another controller or a
 * template is what makes it a setting rather than a stored string.
 *
 * Source-level on purpose. A runtime check would need every module booted
 * and every code path exercised, which is exactly the coverage that does
 * not exist; a grep for the key catches the case that actually occurs.
 */
class DeclaredSettingsAreReadTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function moduleProvider(): array
    {
        return [
            'camps' => ['camps'],
            'rental' => ['rental'],
            'leadership' => ['leadership'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moduleProvider')]
    public function testEveryDeclaredSettingIsReadOutsideItsConfigurationSurface(string $moduleId): void
    {
        $root = dirname(__DIR__, 2) . '/modules/' . $moduleId;
        $unread = [];

        foreach (self::declaredKeys($root . '/module.json') as $key) {
            if (self::filesNaming($root, $key) === []) {
                $unread[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unread,
            sprintf(
                'Module "%s" declares settings nothing outside its config page reads: %s',
                $moduleId,
                implode(', ', $unread)
            )
        );
    }

    /**
     * @return string[]
     */
    private static function declaredKeys(string $manifestPath): array
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        self::assertIsArray($manifest);

        $settings = $manifest['settings'] ?? [];
        if ($settings === []) {
            return [];
        }

        // Both shapes exist in the tree: a map keyed by setting name, and a
        // list of objects carrying `key`.
        if (array_is_list($settings)) {
            return array_map(static fn (array $s): string => (string) $s['key'], $settings);
        }

        return array_map('strval', array_keys($settings));
    }

    /**
     * @return string[] Files naming $key, excluding the module's own
     *         configuration surface.
     */
    private static function filesNaming(string $root, string $key): array
    {
        $found = [];
        foreach (['/src', '/views'] as $subdirectory) {
            if (!is_dir($root . $subdirectory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . $subdirectory, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                if (!in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                if (self::isConfigurationSurface($file->getPathname())) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), $key)) {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }

    private static function isConfigurationSurface(string $path): bool
    {
        $name = basename($path);

        return str_ends_with($name, 'ConfigController.php')
            || str_starts_with($name, 'config.')
            || str_contains($path, '/views/config/');
    }
}
