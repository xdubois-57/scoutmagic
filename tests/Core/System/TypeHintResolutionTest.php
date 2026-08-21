<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Composer\Autoload\ClassLoader;
use Core\System\ComposerAutoloadSync;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * Every class named in a type hint anywhere in core/ or modules/ must
 * actually exist.
 *
 * A missing `use` statement is invisible to PHP until the hinted parameter
 * is passed something non-null: the type resolves against the CURRENT
 * namespace instead ("Modules\Groups\Service\X" silently read as
 * "Modules\Groups\Controller\X"), and the only symptom is a TypeError at
 * the call site. When that call site is public/index.php's wiring — which
 * runs on EVERY request, before routing — the whole site answers 500,
 * including pages that have nothing to do with the module concerned.
 *
 * Unit tests do not catch it: they build controllers with `null` for the
 * optional collaborator, which any type accepts. This walks the source
 * tree instead and resolves each hint through Reflection, so the mistake
 * fails here rather than in production.
 *
 * Return types and typed properties are checked the same way, for the same
 * reason.
 */
class TypeHintResolutionTest extends TestCase
{
    /**
     * Never a class name, but reported by Reflection as if it were one.
     */
    private const RELATIVE_TYPES = ['self', 'static', 'parent'];

    /**
     * A module added since the last `composer dump-autoload` is missing
     * from the compiled PSR-4 map, so every one of its classes would be
     * skipped below and this test would quietly stop covering it. The
     * application has exactly this problem on a host it cannot run
     * composer on, and solves it at boot with Core\System\ComposerAutoloadSync
     * (see public/index.php) — the same call, for the same reason.
     */
    public static function setUpBeforeClass(): void
    {
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                ComposerAutoloadSync::apply($autoloader[0], dirname(__DIR__, 3) . '/composer.json');

                return;
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function classProvider(): iterable
    {
        $root = dirname(__DIR__, 3);

        foreach (self::sourceDirectories($root) as $directory) {
            /** @var RecursiveDirectoryIterator $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $class = self::classNameIn((string) file_get_contents($file->getPathname()));
                if ($class === null) {
                    continue;
                }

                yield $class => [$class];
            }
        }
    }

    /**
     * core/ plus every module's src/ — never modules/*&#47;vendor/, which is
     * bundled third-party code excluded from static analysis too
     * (phpstan.neon).
     *
     * @return string[]
     */
    private static function sourceDirectories(string $root): array
    {
        $directories = [$root . '/core'];

        foreach ((array) glob($root . '/modules/*/src', GLOB_ONLYDIR) as $moduleSrc) {
            $directories[] = (string) $moduleSrc;
        }

        return $directories;
    }

    /**
     * The fully-qualified name of the one class/interface/trait/enum a file
     * declares, or null for a file that declares none (a helper file of
     * plain functions, a fixture). Source-level rather than
     * get_declared_classes(): a file is only ever loaded below, through the
     * real autoloader, once its name is known.
     */
    private static function classNameIn(string $source): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatch) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $classMatch) !== 1) {
            return null;
        }

        return trim($namespaceMatch[1]) . '\\' . $classMatch[1];
    }

    /**
     * @param ReflectionType|null $type
     * @return string[] the class names the hint resolves to, if any
     */
    private static function classNamesIn(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin() || in_array(strtolower($type->getName()), self::RELATIVE_TYPES, true)) {
                return [];
            }

            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                foreach (self::classNamesIn($member) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }

    private static function exists(string $class): bool
    {
        return class_exists($class) || interface_exists($class) || enum_exists($class);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classProvider')]
    public function testEveryTypeHintNamesAClassThatExists(string $class): void
    {
        $this->assertTrue(
            self::exists($class) || trait_exists($class),
            "{$class} is not autoloadable — its namespace does not match its path, or "
            . 'composer.json has no PSR-4 entry covering it'
        );

        $reflection = new ReflectionClass($class);
        $unresolved = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $this->collectUnresolved($method, $unresolved);
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $this->collectUnresolvedProperty($property, $unresolved);
        }

        $this->assertSame(
            [],
            $unresolved,
            "{$class} names a class that does not exist — almost always a missing `use` statement, "
            . 'which PHP resolves against the declaring namespace instead: ' . implode(', ', $unresolved)
        );
    }

    /**
     * @param string[] $unresolved
     */
    private function collectUnresolved(ReflectionMethod $method, array &$unresolved): void
    {
        foreach ($method->getParameters() as $parameter) {
            foreach (self::classNamesIn($parameter->getType()) as $name) {
                if (!self::exists($name)) {
                    $unresolved[] = "{$method->getName()}(\${$parameter->getName()}): {$name}";
                }
            }
        }

        foreach (self::classNamesIn($method->getReturnType()) as $name) {
            if (!self::exists($name)) {
                $unresolved[] = "{$method->getName()}(): {$name}";
            }
        }
    }

    /**
     * @param string[] $unresolved
     */
    private function collectUnresolvedProperty(ReflectionProperty $property, array &$unresolved): void
    {
        foreach (self::classNamesIn($property->getType()) as $name) {
            if (!self::exists($name)) {
                $unresolved[] = "\${$property->getName()}: {$name}";
            }
        }
    }
}
