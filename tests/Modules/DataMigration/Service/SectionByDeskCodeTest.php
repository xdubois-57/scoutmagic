<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Service;

use Core\Member\SectionService;
use Modules\DataMigration\Service\SectionByDeskCode;
use PHPUnit\Framework\TestCase;

/**
 * D10: a section named by an archive is found by its Desk code, and
 * nothing else — an absent, empty, non-string or unknown code is « no
 * section », which the import turns into a dropped link or an ignored
 * object, never into the whole unit.
 */
final class SectionByDeskCodeTest extends TestCase
{
    public function testASectionIsFoundByItsTrimmedDeskCodeAndLookedUpOnce(): void
    {
        $sections = $this->createMock(SectionService::class);
        $sections->expects($this->once())
            ->method('findByDeskCode')
            ->with('LOUV-A')
            ->willReturn([
                'id' => 7, 'desk_code' => 'LOUV-A', 'name' => 'Louveteaux', 'email' => null,
                'age_branch_id' => 2, 'branch_name' => 'Louveteaux', 'branch_sort_order' => 2, 'color' => null,
            ]);

        $lookup = new SectionByDeskCode($sections);

        $this->assertSame(7, $lookup->find(' LOUV-A '));
        $this->assertSame(7, $lookup->find('LOUV-A'));
    }

    public function testAnUnknownCodeIsNoSection(): void
    {
        $sections = $this->createStub(SectionService::class);
        $sections->method('findByDeskCode')->willReturn(null);

        $this->assertNull((new SectionByDeskCode($sections))->find('INCONNU'));
    }

    public function testAnAbsentEmptyOrNonStringCodeIsNoSectionWithoutAnyLookup(): void
    {
        $sections = $this->createMock(SectionService::class);
        $sections->expects($this->never())->method('findByDeskCode');
        $lookup = new SectionByDeskCode($sections);

        $this->assertNull($lookup->find(null));
        $this->assertNull($lookup->find('   '));
        $this->assertNull($lookup->find(12));
    }
}
