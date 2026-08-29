<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The net under the whole « API et dépendances entre modules » chantier:
 * outside a module's own code, the ONLY part of it anything may name is
 * its `Api\` namespace (ARCHITECTURE.md §7.5). Zero exceptions — the
 * twelve historical leak edges the dependency review catalogued were
 * emptied one by one, and this test is what keeps the next one from
 * landing.
 *
 * Scope: every PHP file under `core/` and `modules/<m>/src/`. The
 * composition roots (`public/index.php`, `public/cron.php`,
 * `public/scheduler-bootstrap.php`) are deliberately NOT scanned — wiring
 * concrete classes together is their entire job — and tests construct
 * whatever they verify.
 *
 * The scan tokenizes each file, so names inside comments, docblocks and
 * strings never count: only code that would actually autoload another
 * module's class is a violation.
 */
class ModuleBoundariesTest extends TestCase
{
    /**
     * modules/<id> directory name => the namespace segment after
     * `Modules\`, derived the same way Composer's PSR-4 map does.
     *
     * @return array<string, string>
     */
    private static function moduleNamespaces(): array
    {
        $map = [];
        foreach (glob(self::root() . '/modules/*/src') ?: [] as $srcDir) {
            $moduleId = basename(dirname($srcDir));
            $map[$moduleId] = str_replace(' ', '', ucwords(str_replace('_', ' ', $moduleId)));
        }
        self::assertNotEmpty($map);

        return $map;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every `Modules\X\…` name a file's CODE references — `use` statements
     * and inline qualified names alike; comments and strings excluded by
     * tokenization.
     *
     * @return string[]
     */
    private static function moduleReferences(string $file): array
    {
        $source = file_get_contents($file);
        self::assertNotFalse($source);

        $references = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_NAME_QUALIFIED && $token[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            if (str_starts_with($name, 'Modules\\')) {
                $references[] = $name;
            }
        }

        return $references;
    }

    /** @return string[] */
    private static function phpFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function testCoreNeverReachesPastAModulesApiNamespace(): void
    {
        $violations = [];
        foreach (self::phpFilesUnder(self::root() . '/core') as $file) {
            foreach (self::moduleReferences($file) as $name) {
                $segments = explode('\\', $name);
                if (($segments[2] ?? '') !== 'Api') {
                    $violations[] = substr($file, strlen(self::root()) + 1) . ' -> ' . $name;
                }
            }
        }

        $this->assertSame([], $violations, "Core reaches into a module past its Api\\ namespace:\n" . implode("\n", $violations));
    }

    public function testNoModuleReachesPastAnotherModulesApiNamespace(): void
    {
        $violations = [];
        foreach (self::moduleNamespaces() as $moduleId => $ownNamespace) {
            foreach (self::phpFilesUnder(self::root() . '/modules/' . $moduleId . '/src') as $file) {
                foreach (self::moduleReferences($file) as $name) {
                    $segments = explode('\\', $name);
                    if (($segments[1] ?? '') === $ownNamespace) {
                        continue; // a module's own internals are its own business
                    }
                    if (($segments[2] ?? '') !== 'Api') {
                        $violations[] = substr($file, strlen(self::root()) + 1) . ' -> ' . $name;
                    }
                }
            }
        }

        $this->assertSame([], $violations, "A module reaches into another module past its Api\\ namespace:\n" . implode("\n", $violations));
    }

    /**
     * The strict contract's other half (ARCHITECTURE.md §7.5): an `Api\`
     * namespace is interfaces, immutable value objects and the module's
     * user-facing exception — so an Api file never drags the module's own
     * internals into every consumer's reach. The ONE sanctioned concrete
     * citizen is Gallery's `DelegatedAlbumManagerFactory`, a factory whose
     * entire purpose is assembling those internals FOR the consumer; it is
     * named here as the documented model, not silently skipped.
     */
    public function testApiNamespacesDoNotImportTheirOwnModulesInternals(): void
    {
        $sanctionedFactory = 'modules/gallery/src/Api/DelegatedAlbumManagerFactory.php';

        $violations = [];
        foreach (self::moduleNamespaces() as $moduleId => $ownNamespace) {
            $apiDir = self::root() . '/modules/' . $moduleId . '/src/Api';
            if (!is_dir($apiDir)) {
                continue;
            }
            foreach (self::phpFilesUnder($apiDir) as $file) {
                $relative = substr($file, strlen(self::root()) + 1);
                if ($relative === $sanctionedFactory) {
                    continue;
                }
                foreach (self::moduleReferences($file) as $name) {
                    $segments = explode('\\', $name);
                    if (($segments[1] ?? '') === $ownNamespace && ($segments[2] ?? '') !== 'Api') {
                        $violations[] = $relative . ' -> ' . $name;
                    }
                }
            }
        }

        $this->assertSame([], $violations, "An Api\\ file drags its module's internals into the public contract:\n" . implode("\n", $violations));
    }

    /**
     * The sanctioned factory must stay the ONLY one — if this fails
     * because the file moved or a second factory appeared, that is a
     * product decision to take in ARCHITECTURE.md §7.5 first.
     */
    public function testTheSanctionedApiFactoryStillExists(): void
    {
        $this->assertFileExists(self::root() . '/modules/gallery/src/Api/DelegatedAlbumManagerFactory.php');
    }
}
