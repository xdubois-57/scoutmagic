<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\AgeBranchRepository;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use Core\Import\ImportSectionRepository;
use Core\Import\MappingResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MappingResolverTest extends TestCase
{
    private \PDO $pdo;
    private MappingResolver $resolver;
    private FunctionRepository $functionRepo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->functionRepo = new FunctionRepository($this->pdo);
        $ageBranchRepo = new AgeBranchRepository($this->pdo);
        $sectionRepo = new ImportSectionRepository($this->pdo);
        $feeRepo = new FeeCategoryRepository($this->pdo);

        $this->resolver = new MappingResolver($this->functionRepo, $ageBranchRepo, $sectionRepo, $feeRepo);
    }

    public function testResolveFunctionWithKnownFunctionReturnsExistingId(): void
    {
        $id = $this->functionRepo->create('Animateur', 'Animateur', 'chief', true);
        $resolved = $this->resolver->resolveFunction('Animateur');

        $this->assertSame($id, $resolved);
        $this->assertSame(0, $this->resolver->getNewFunctionsCount());
    }

    public function testResolveFunctionWithUnknownCreatesNew(): void
    {
        $id = $this->resolver->resolveFunction('Inconnu');

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->resolver->getNewFunctionsCount());
    }

    public function testResolveFunctionNewEntryHasIdentifiedRoleAndUnconfirmed(): void
    {
        $this->resolver->resolveFunction('Nouvelle Fonction');

        $fn = $this->functionRepo->findByDeskCode('Nouvelle Fonction');
        $this->assertNotNull($fn);
        $this->assertSame('identified', $fn['role']);
        $this->assertFalse($fn['confirmed']);
    }

    public function testResolveFunctionRoleNeverElevatedAutomatically(): void
    {
        // Create multiple unknown functions — none should get elevated
        $this->resolver->resolveFunction('Chef d\'unité');
        $this->resolver->resolveFunction('Administrateur');
        $this->resolver->resolveFunction('Directeur');

        foreach (['Chef d\'unité', 'Administrateur', 'Directeur'] as $code) {
            $fn = $this->functionRepo->findByDeskCode($code);
            $this->assertNotNull($fn);
            $this->assertSame('identified', $fn['role'], "Function '$code' should not be auto-elevated.");
            $this->assertFalse($fn['confirmed']);
        }
    }

    public function testResolveBranchAutoCreates(): void
    {
        $id = $this->resolver->resolveBranch('Louveteaux');
        $this->assertGreaterThan(0, $id);

        // Second call returns same ID
        $id2 = $this->resolver->resolveBranch('Louveteaux');
        $this->assertSame($id, $id2);
    }

    public function testResolveSectionAutoCreatesWithBranchLink(): void
    {
        $branchId = $this->resolver->resolveBranch('Baladins');
        $sectionId = $this->resolver->resolveSection('SV025B1', $branchId, 'Ribambelle');

        $this->assertGreaterThan(0, $sectionId);
    }

    public function testResolveSectionUsesDeskNameAsInitialName(): void
    {
        $branchId = $this->resolver->resolveBranch('Louveteaux');
        $this->resolver->resolveSection('SV025L1', $branchId, 'Meute Akela');

        $sectionRepo = new ImportSectionRepository($this->pdo);
        $section = $sectionRepo->findByDeskCode('SV025L1');
        $this->assertNotNull($section);
        $this->assertSame('Meute Akela', $section['name']);
    }

    public function testResolveFeeAutoCreates(): void
    {
        $id = $this->resolver->resolveFee('Tarif normal');
        $this->assertGreaterThan(0, $id);

        $id2 = $this->resolver->resolveFee('Tarif normal');
        $this->assertSame($id, $id2);
    }

    public function testGetNewFunctionsCountTracksCorrectly(): void
    {
        $this->assertSame(0, $this->resolver->getNewFunctionsCount());

        $this->resolver->resolveFunction('Fn1');
        $this->resolver->resolveFunction('Fn2');
        $this->assertSame(2, $this->resolver->getNewFunctionsCount());

        // Resolving same again should not increase count
        $this->resolver->resolveFunction('Fn1');
        $this->assertSame(2, $this->resolver->getNewFunctionsCount());
    }
}
