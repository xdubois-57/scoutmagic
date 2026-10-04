<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every module that owns delegated albums must also NAME them.
 *
 * A delegated album is gallery's row and another module's content
 * (ARCHITECTURE.md §7.5). Gallery lists it on Configuration › Galerie ›
 * Albums because it occupies real space on a real storage location and
 * moving it is gallery's job — and to list it, it has to call it
 * something. Gallery cannot: knowing that `camp_camp` means "a stay" is
 * exactly the coupling `Api\DelegatedAlbumDescriber` exists to prevent, so
 * the owning module answers and gallery falls back only when nobody does.
 *
 * Issue #749 is what happens when a module takes the first half and skips
 * the second: `camps` registered its `Api\DelegatedAlbumAccessChecker`,
 * created albums happily, and an administrator read `camp_camp #10` on a
 * real page for as long as it took somebody to report it. Nothing failed,
 * no test went red, and the fallback — which exists for a module switched
 * off and for an owner since deleted — quietly became the normal path for
 * a live owner type.
 *
 * That is the shape this test refuses. It is deliberately two assertions
 * on two different ways of getting it wrong: a describer nobody wrote, and
 * a describer nobody wired, which an administrator cannot tell apart.
 */
class DelegatedAlbumOwnerTypesAreDescribedTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Modules holding a class that implements gallery's access checker —
     * which is to say, every module that owns delegated albums. Keyed by
     * module directory name so the two halves can be compared per module.
     *
     * @return array<string, string> module id => the implementing file
     */
    private function modulesOwningDelegatedAlbums(): array
    {
        return $this->modulesImplementing('DelegatedAlbumAccessChecker');
    }

    /**
     * @return array<string, string> module id => the implementing file
     */
    private function modulesDescribingDelegatedAlbums(): array
    {
        return $this->modulesImplementing('DelegatedAlbumDescriber');
    }

    /**
     * @return array<string, string>
     */
    private function modulesImplementing(string $interface): array
    {
        $found = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT . '/modules', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($directory as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // `implements X` or `implements A, X` — the declaration, never
            // a mention in a docblock, which several of these files have.
            if (preg_match('/^\s*(?:final\s+)?class\s+\w+[^{]*\bimplements\b[^{]*\b' . $interface . '\b/m', $source) !== 1) {
                continue;
            }
            $relative = str_replace(self::ROOT . '/', '', $file->getPathname());
            $module = explode('/', $relative)[1];
            // Gallery declares the interfaces; it is not a consumer of
            // its own delegation, and has no albums of this kind to name.
            if ($module !== 'gallery') {
                $found[$module] = $relative;
            }
        }

        return $found;
    }

    public function testEveryModuleOwningDelegatedAlbumsAlsoProvidesADescriber(): void
    {
        $owners = $this->modulesOwningDelegatedAlbums();
        $this->assertNotEmpty(
            $owners,
            'no module implements Api\\DelegatedAlbumAccessChecker any more, so this test is reading '
            . 'the wrong interface name and has been silently passing.',
        );

        $describers = $this->modulesDescribingDelegatedAlbums();
        $missing = array_diff_key($owners, $describers);

        $this->assertSame(
            [],
            $missing,
            'these modules own delegated albums but provide no Api\\DelegatedAlbumDescriber, so '
            . 'gallery\'s album administration page names theirs `owner_type #id` — issue #749, '
            . 'which reached a real page exactly this way: ' . implode(', ', array_keys($missing)),
        );
    }

    /**
     * A describer that exists and is never registered is a describer that
     * does nothing: the registry is built from
     * `$galleryDelegatedAlbumDescribers` in the composition root, and an
     * administrator reading the page cannot tell an unwired describer from
     * a missing one.
     */
    public function testEveryDescriberIsRegisteredInTheCompositionRoot(): void
    {
        $index = (string) file_get_contents(self::ROOT . '/public/index.php');

        foreach ($this->modulesDescribingDelegatedAlbums() as $module => $file) {
            $class = basename($file, '.php');
            $this->assertMatchesRegularExpression(
                '/\$galleryDelegatedAlbumDescribers\[\] = new \\\\Modules\\\\\w+\\\\[\w\\\\]*' . $class . '\(/',
                $index,
                sprintf(
                    'module `%s` provides %s but public/index.php never pushes it onto '
                    . '$galleryDelegatedAlbumDescribers, so the registry never sees it and the page '
                    . 'falls back to the technical label anyway.',
                    $module,
                    $class
                ),
            );
        }
    }
}
