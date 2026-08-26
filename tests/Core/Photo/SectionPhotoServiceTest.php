<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use PHPUnit\Framework\TestCase;

class SectionPhotoServiceTest extends TestCase
{
    public function testResolveFileIdDelegatesToRepository(): void
    {
        $repository = $this->createMock(SectionPhotoRepository::class);
        $repository->expects($this->once())
            ->method('findFileIdForYearOrEarlier')
            ->with(42, 7)
            ->willReturn(99);

        $service = new SectionPhotoService($repository);

        $this->assertSame(99, $service->resolveFileId(42, 7));
    }

    public function testSetPhotoDelegatesToRepository(): void
    {
        $repository = $this->createMock(SectionPhotoRepository::class);
        $repository->expects($this->once())
            ->method('upsert')
            ->with(42, 7, 99, 3);

        $service = new SectionPhotoService($repository);
        $service->setPhoto(42, 7, 99, 3);
    }

    public function testResolveFileIdIsMemoizedPerSectionAndYear(): void
    {
        $repository = $this->createMock(SectionPhotoRepository::class);
        $repository->expects($this->once())
            ->method('findFileIdForYearOrEarlier')
            ->with(42, 7)
            ->willReturn(99);

        $service = new SectionPhotoService($repository);

        $this->assertSame(99, $service->resolveFileId(42, 7));
        $this->assertSame(99, $service->resolveFileId(42, 7));
    }

    public function testSetPhotoDropsTheMemo(): void
    {
        $repository = $this->createMock(SectionPhotoRepository::class);
        $repository->expects($this->exactly(2))
            ->method('findFileIdForYearOrEarlier')
            ->with(42, 7)
            ->willReturnOnConsecutiveCalls(null, 99);

        $service = new SectionPhotoService($repository);

        $this->assertNull($service->resolveFileId(42, 7));
        $service->setPhoto(42, 7, 99, 3);
        $this->assertSame(99, $service->resolveFileId(42, 7));
    }
}
