<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Service;

use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;
use Modules\Trombinoscope\Service\TrombinoscopeService;
use PHPUnit\Framework\TestCase;

class TrombinoscopeServiceTest extends TestCase
{
    private function makeProfile(int $memberYearId, int $memberId, string $firstName): MemberProfile
    {
        return new MemberProfile(
            memberYearId: $memberYearId,
            memberId: $memberId,
            deskId: 'D' . $memberId,
            firstName: $firstName,
            lastName: 'Test',
            totem: null,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: '2025-2026'
        );
    }

    public function testSeparatesLeadFromRestOfStaff(): void
    {
        $repository = $this->createMock(TrombinoscopeRepository::class);
        $repository->method('getEligibleStaffForSection')->willReturn([
            ['member_year_id' => 10, 'is_lead' => true],
            ['member_year_id' => 20, 'is_lead' => false],
        ]);

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('hydrateMemberProfile')->willReturnMap([
            [10, $this->makeProfile(10, 1, 'Alice')],
            [20, $this->makeProfile(20, 2, 'Bob')],
        ]);

        $service = new TrombinoscopeService($repository, $sectionService);
        $result = $service->getSectionStaff(1, 1);

        $this->assertSame('Alice', $result['lead']->firstName);
        $this->assertCount(1, $result['staff']);
        $this->assertSame('Bob', $result['staff'][0]->firstName);
    }

    public function testNoLeadWhenNoneFlagged(): void
    {
        $repository = $this->createMock(TrombinoscopeRepository::class);
        $repository->method('getEligibleStaffForSection')->willReturn([
            ['member_year_id' => 10, 'is_lead' => false],
        ]);

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('hydrateMemberProfile')->willReturn($this->makeProfile(10, 1, 'Alice'));

        $service = new TrombinoscopeService($repository, $sectionService);
        $result = $service->getSectionStaff(1, 1);

        $this->assertNull($result['lead']);
        $this->assertCount(1, $result['staff']);
    }

    public function testStaffSortedByDisplayName(): void
    {
        $repository = $this->createMock(TrombinoscopeRepository::class);
        $repository->method('getEligibleStaffForSection')->willReturn([
            ['member_year_id' => 10, 'is_lead' => false],
            ['member_year_id' => 20, 'is_lead' => false],
        ]);

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('hydrateMemberProfile')->willReturnMap([
            [10, $this->makeProfile(10, 1, 'Zoe')],
            [20, $this->makeProfile(20, 2, 'Amir')],
        ]);

        $service = new TrombinoscopeService($repository, $sectionService);
        $result = $service->getSectionStaff(1, 1);

        $this->assertSame('Amir', $result['staff'][0]->firstName);
        $this->assertSame('Zoe', $result['staff'][1]->firstName);
    }

    public function testSkipsMembersThatFailToHydrate(): void
    {
        $repository = $this->createMock(TrombinoscopeRepository::class);
        $repository->method('getEligibleStaffForSection')->willReturn([
            ['member_year_id' => 10, 'is_lead' => false],
        ]);

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('hydrateMemberProfile')->willReturn(null);

        $service = new TrombinoscopeService($repository, $sectionService);
        $result = $service->getSectionStaff(1, 1);

        $this->assertNull($result['lead']);
        $this->assertEmpty($result['staff']);
    }
}
