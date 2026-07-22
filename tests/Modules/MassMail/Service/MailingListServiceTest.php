<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\FunctionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\MailingListException;
use Modules\MassMail\Service\MailingListService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
class MailingListServiceTest extends TestCase
{
    private \PDO $pdo;
    private MailingListService $service;
    private int $scoutYearId;
    private int $sectionActiveId;
    private int $functionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->service = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo)
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('LOU01', {$branchId}, 'Meute A', 1)");
        $this->sectionActiveId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('LOU02', {$branchId}, 'Meute B', 0)");

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'identified')");
        $this->functionId = (int) $this->pdo->lastInsertId();
    }

    public function testDefaultListsOnlyIncludeActiveSectionsPlusTheTwoUnitWideLists(): void
    {
        $lists = $this->service->getDefaultLists();

        $labels = array_column($lists, 'label');
        $this->assertContains('Section - Meute A', $labels);
        $this->assertNotContains('Section - Meute B', $labels);
        $this->assertContains(MailingListService::ACTIVE_MEMBERS_LABEL, $labels);
        $this->assertContains(MailingListService::CHIEFS_LABEL, $labels);
    }

    public function testCreateCustomListRequiresAtLeastOneFunctionAndOneSection(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createCustomList('Liste vide', 'Description', [], [$this->sectionActiveId], null);
    }

    public function testCreateCustomListRequiresANonEmptyName(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createCustomList('  ', 'Description', [$this->functionId], [$this->sectionActiveId], null);
    }

    public function testCreateCustomListRequiresANonEmptyDescription(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createCustomList('Ma liste', '  ', [$this->functionId], [$this->sectionActiveId], null);
    }

    public function testCreateAndResolveCustomListRoundTrips(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description de la liste', [$this->functionId], [$this->sectionActiveId], null);

        $this->assertSame('Description de la liste', $list->description);
        $this->assertSame([$this->functionId], $this->service->getCustomListFunctionIds($list->id));
        $this->assertSame([$this->sectionActiveId], $this->service->getCustomListSectionIds($list->id));

        $resolved = $this->service->resolveMembers('custom', $list->id, null, $this->scoutYearId);
        $this->assertSame([], $resolved); // no members assigned yet, but resolves without error
    }

    public function testUpdateCustomListRequiresANonEmptyDescription(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [$this->sectionActiveId], null);

        $this->expectException(MailingListException::class);
        $this->service->updateCustomList($list->id, 'Ma liste', '', [$this->functionId], [$this->sectionActiveId]);
    }

    public function testDeleteCustomListBlockedWhenReferencedByAnEmail(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [$this->sectionActiveId], null);

        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, list_id, status)
             VALUES ('Sujet', '<p>Corps</p>', {$this->sectionActiveId}, 'custom', {$list->id}, 'draft')"
        );

        $this->expectException(MailingListException::class);
        $this->service->deleteCustomList($list->id);
    }

    public function testDeleteCustomListSucceedsWhenNotReferenced(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [$this->sectionActiveId], null);

        $this->service->deleteCustomList($list->id);

        $this->assertNull($this->service->getCustomListById($list->id));
    }

    public function testDefaultListsEachHaveANonEmptyDescription(): void
    {
        foreach ($this->service->getDefaultLists() as $list) {
            $this->assertNotSame('', trim($list['description']));
        }
    }
}
