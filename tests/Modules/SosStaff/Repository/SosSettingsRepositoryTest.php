<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Repository;

use Modules\SosStaff\Repository\SosSettingsRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class SosSettingsRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SosSettingsRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->repo = new SosSettingsRepository($this->pdo);
    }

    public function testFindDefaultNumberMemberIdReturnsNullWhenNoRowExistsYet(): void
    {
        $this->assertNull($this->repo->findDefaultNumberMemberId());
    }

    public function testSaveDefaultNumberMemberCreatesRowIfMissing(): void
    {
        $this->repo->saveDefaultNumberMember(42);

        $this->assertSame(42, $this->repo->findDefaultNumberMemberId());
    }

    public function testSaveDefaultNumberMemberOverwritesPreviousChoice(): void
    {
        $this->repo->saveDefaultNumberMember(7);
        $this->repo->saveDefaultNumberMember(9);

        $this->assertSame(9, $this->repo->findDefaultNumberMemberId());
    }

    public function testSavesReuseTheSameSingletonRow(): void
    {
        $this->repo->saveDefaultNumberMember(1);
        $this->repo->saveDefaultNumberMember(2);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM sos_settings')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testAreSectionDefaultsSeededDefaultsToFalse(): void
    {
        $this->assertFalse($this->repo->areSectionDefaultsSeeded());
    }

    public function testMarkSectionDefaultsSeededPersists(): void
    {
        $this->repo->markSectionDefaultsSeeded();

        $this->assertTrue($this->repo->areSectionDefaultsSeeded());
    }
}
