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
        $sectionService->method('hydrateMemberProfiles')->with([10, 20])->willReturn([
            10 => $this->makeProfile(10, 1, 'Alice'),
            20 => $this->makeProfile(20, 2, 'Bob'),
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
        $sectionService->method('hydrateMemberProfiles')->willReturn([10 => $this->makeProfile(10, 1, 'Alice')]);

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
        $sectionService->method('hydrateMemberProfiles')->willReturn([
            10 => $this->makeProfile(10, 1, 'Zoe'),
            20 => $this->makeProfile(20, 2, 'Amir'),
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
        $sectionService->method('hydrateMemberProfiles')->willReturn([]);

        $service = new TrombinoscopeService($repository, $sectionService);
        $result = $service->getSectionStaff(1, 1);

        $this->assertNull($result['lead']);
        $this->assertEmpty($result['staff']);
    }

    public function testImplementsSectionResponsableProviderReturningTheLead(): void
    {
        $repository = $this->createMock(TrombinoscopeRepository::class);
        $repository->method('getEligibleStaffForSection')->willReturn([
            ['member_year_id' => 10, 'is_lead' => true],
        ]);

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('hydrateMemberProfiles')->willReturn([10 => $this->makeProfile(10, 1, 'Alice')]);

        $service = new TrombinoscopeService($repository, $sectionService);

        $this->assertInstanceOf(\Core\Module\SectionResponsableProvider::class, $service);
        $this->assertSame('Alice', $service->getResponsable(1, 1)?->firstName);
    }

    public function testGetResponsableReturnsNullWhenNoneFlagged(): void
    {
        $repository = $this->createMock(TrombinoscopeRepository::class);
        $repository->method('getEligibleStaffForSection')->willReturn([
            ['member_year_id' => 10, 'is_lead' => false],
        ]);

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->method('hydrateMemberProfiles')->willReturn([10 => $this->makeProfile(10, 1, 'Alice')]);

        $service = new TrombinoscopeService($repository, $sectionService);

        $this->assertNull($service->getResponsable(1, 1));
    }

    public function testGetSectionStaffForSectionsHydratesEverybodyOnceAndSplitsPerSection(): void
    {
        $repository = $this->createMock(TrombinoscopeRepository::class);
        // Twice: once for the wall below, once more through getResponsables().
        $repository->expects($this->exactly(2))->method('getEligibleStaffForSections')->with([1, 2], 7)->willReturn([
            1 => [['member_year_id' => 10, 'is_lead' => true], ['member_year_id' => 20, 'is_lead' => false]],
            2 => [['member_year_id' => 30, 'is_lead' => false]],
        ]);
        $repository->expects($this->never())->method('getEligibleStaffForSection');

        $sectionService = $this->createMock(SectionService::class);
        $sectionService->expects($this->exactly(2))->method('hydrateMemberProfiles')->with([10, 20, 30])->willReturn([
            10 => $this->makeProfile(10, 1, 'Alice'),
            20 => $this->makeProfile(20, 2, 'Bob'),
            30 => $this->makeProfile(30, 3, 'Carol'),
        ]);

        $service = new TrombinoscopeService($repository, $sectionService);
        $result = $service->getSectionStaffForSections([1, 2], 7);

        $this->assertSame('Alice', $result[1]['lead']->firstName);
        $this->assertSame(['Bob'], array_map(fn($p) => $p->firstName, $result[1]['staff']));
        $this->assertNull($result[2]['lead']);
        $this->assertSame(['Carol'], array_map(fn($p) => $p->firstName, $result[2]['staff']));

        $responsables = $service->getResponsables([1, 2], 7);
        $this->assertSame('Alice', $responsables[1]->firstName);
        $this->assertNull($responsables[2]);
    }
}
