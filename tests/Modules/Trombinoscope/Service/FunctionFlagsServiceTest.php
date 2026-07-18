<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Service;

use Modules\Trombinoscope\Repository\FunctionFlagsRepository;
use Modules\Trombinoscope\Service\FunctionFlagsService;
use PHPUnit\Framework\TestCase;

class FunctionFlagsServiceTest extends TestCase
{
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
        $service = new FunctionFlagsService($this->createMock(FunctionFlagsRepository::class));

        $this->assertNotSame('', $service->getSectionLabel());
        $this->assertNotSame('', $service->getLeadLabel());
    }
}
