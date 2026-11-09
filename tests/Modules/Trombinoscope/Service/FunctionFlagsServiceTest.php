<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Service;

use Modules\Trombinoscope\Repository\FunctionFlagsRepository;
use Modules\Trombinoscope\Service\FunctionFlagsService;
use PHPUnit\Framework\TestCase;

class FunctionFlagsServiceTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testImportDefaultsAndManualChangesUseTheRealFlagStorage(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $pdo->exec('CREATE TABLE trombinoscope_function_flags (function_id INTEGER PRIMARY KEY, is_lead INTEGER NOT NULL DEFAULT 0)');
        $service = new FunctionFlagsService(new FunctionFlagsRepository($pdo));
        $hooks = new \Core\Module\HookRegistry();
        $hooks->register(\Core\Module\FunctionFlagsProvider::class, $service);
        $mapping = new \Core\Import\MappingResolver(
            new \Core\Import\FunctionRepository($pdo), new \Core\Import\AgeBranchRepository($pdo),
            new \Core\Import\ImportSectionRepository($pdo), new \Core\Import\FeeCategoryRepository($pdo),
            null, $hooks,
        );
        $expected = [];
        $ids = [];
        foreach ([
            'Animateur' => false, 'Animateur responsable' => true, 'Candidat animateur' => false,
            "Animateur d'unité" => true, 'Candidat cadre local' => false,
            "Collaborateur d'unité" => false, "Equipier d'unité" => false,
            'Iama local' => null, 'Scout' => null,
        ] as $code => $lead) {
            $ids[$code] = $mapping->resolveFunction($code);
            if ($lead !== null) {
                $expected[$ids[$code]] = $lead;
            }
        }
        $this->assertSame($expected, $service->getLeadFlags());
        $service->setLead($ids['Animateur responsable'], false);
        $service->setLead($ids['Animateur'], true);
        $expected[$ids['Animateur responsable']] = false;
        $expected[$ids['Animateur']] = true;
        $mapping->resetImportState();
        foreach (array_keys($ids) as $code) {
            $mapping->resolveFunction($code);
        }
        $this->assertSame($expected, $service->getLeadFlags());
        $this->assertSame(7, (int) $pdo->query('SELECT COUNT(*) FROM trombinoscope_function_flags')->fetchColumn());
    }

    public function testGetLeadFlagsDelegatesToRepository(): void
    {
        $repository = $this->createMock(FunctionFlagsRepository::class);
        $repository->expects($this->once())->method('getLeadFlags')->willReturn([1 => true]);

        $service = new FunctionFlagsService($repository);

        $this->assertSame([1 => true], $service->getLeadFlags());
    }

    public function testSetLeadDelegatesToRepository(): void
    {
        $repository = $this->createMock(FunctionFlagsRepository::class);
        $repository->expects($this->once())->method('setLead')->with(1, true);

        $service = new FunctionFlagsService($repository);
        $service->setLead(1, true);
    }

    public function testLabelsAreNonEmptyFrench(): void
    {
        $service = new FunctionFlagsService($this->createStub(FunctionFlagsRepository::class));

        $this->assertNotSame('', $service->getSectionLabel());
        $this->assertNotSame('', $service->getLeadLabel());
    }
}
