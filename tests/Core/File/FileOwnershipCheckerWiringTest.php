<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\FileAccessGuard;
use PHPUnit\Framework\TestCase;

/**
 * public/index.php's wiring order for Core\File\FileAccessGuard's
 * ownership-checker registry — a structural test reading the raw file,
 * same precedent as tests/Security/FileAccessAuditTest.php and
 * tests/Core/Http/MenuRegistrationOrderTest.php (nothing else can
 * exercise this procedural bootstrap's literal statement order).
 *
 * What it protects: the guard used to be constructed with a hardcoded
 * one-element checker array, hundreds of lines before
 * ModuleManager::loadEnabledModules() ran — so a checker provided by a
 * module could never be registered, and any module file scoped to its own
 * access rule would have been denied to everyone, fail-closed and silent.
 * Moving the construction back above the module blocks would reintroduce
 * exactly that, with every behavioural test still green, which is why the
 * ordering is pinned here.
 */
class FileOwnershipCheckerWiringTest extends TestCase
{
    private string $indexPhp;

    protected function setUp(): void
    {
        $this->indexPhp = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
    }

    private function offsetOf(string $needle): int
    {
        $offset = strpos($this->indexPhp, $needle);
        $this->assertNotFalse($offset, "public/index.php no longer contains: {$needle}");

        return $offset;
    }

    public function testTheGuardIsConstructedAfterModulesAreLoaded(): void
    {
        $this->assertLessThan(
            $this->offsetOf('new FileAccessGuard('),
            $this->offsetOf('$moduleManager->loadEnabledModules();'),
            'FileAccessGuard must be built after loadEnabledModules(), or a module can never register a checker'
        );
    }

    public function testTheGuardIsConstructedAfterEveryPerModuleWiringBlock(): void
    {
        // A module appends its checker from inside its own
        // `if (in_array('<module>', $moduleManager->getEnabledModuleIds(), true))`
        // block; the last of those must still come before the guard.
        $lastModuleBlock = strrpos($this->indexPhp, '$moduleManager->getEnabledModuleIds(), true))');
        $this->assertNotFalse($lastModuleBlock);

        $this->assertLessThan(
            $this->offsetOf('new FileAccessGuard('),
            $lastModuleBlock,
            'a checker appended by the last module block would be missed'
        );
    }

    public function testTheGuardIsBuiltFromTheSharedCheckerArrayAndNotALiteral(): void
    {
        $this->assertMatchesRegularExpression(
            '/new FileAccessGuard\(\s*\$fileRepository,\s*Role::fromString\(\$currentRole\),\s*\$linkedMemberIds,\s*\$fileOwnershipCheckers\s*\)/',
            $this->indexPhp,
            'the guard must receive the shared $fileOwnershipCheckers array, so module-appended checkers reach it'
        );

        // Core's own checker seeds that array where its dependencies exist.
        $this->assertStringContainsString(
            '$fileOwnershipCheckers = [$sectionDocumentOwnershipChecker];',
            $this->indexPhp
        );
        $this->assertLessThan(
            $this->offsetOf('new FileAccessGuard('),
            $this->offsetOf('$fileOwnershipCheckers = [$sectionDocumentOwnershipChecker];'),
            'the array must be seeded before the guard consumes it'
        );
    }

    public function testTheGuardIsConstructedImmediatelyBeforeItsOnlyConsumer(): void
    {
        $guardOffset = $this->offsetOf('new FileAccessGuard(');
        $controllerOffset = $this->offsetOf('new FileController(');

        $this->assertLessThan($controllerOffset, $guardOffset);
        // Two mentions only: the assignment, and the FileController
        // argument. A third would be a consumer that may well sit above
        // this construction point and would need checking against it.
        $this->assertSame(
            2,
            substr_count($this->indexPhp, '$fileAccessGuard'),
            'FileController is meant to be the guard\'s only consumer'
        );
    }

    public function testTheLinkedMemberIdsPassedToTheGuardAreSnapshottedBeforeTheMenuReresolvesThem(): void
    {
        // $linkedMembers is resolved twice (account header, then the
        // Espace membres menu). The guard must not silently depend on
        // whichever resolution happens to be current where it is now built.
        $snapshotOffset = $this->offsetOf('$linkedMemberIds = array_map(');
        $secondResolution = strrpos($this->indexPhp, '$linkedMembers = $memberService->getLinkedMembers(');
        $this->assertNotFalse($secondResolution);

        $this->assertLessThan($secondResolution, $snapshotOffset);
    }

    public function testTheGuardExposesNoWayToRegisterACheckerAfterConstruction(): void
    {
        // Immutability is what makes the ordering above the only contract:
        // no setter, no addOwnershipChecker() escape hatch.
        $publicMethods = array_map(
            fn(\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(FileAccessGuard::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        sort($publicMethods);
        $this->assertSame(['__construct', 'check'], $publicMethods);
    }
}
