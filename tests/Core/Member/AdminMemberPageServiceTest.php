<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Member\AdminMemberPageService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The blocks of /admin/members/{id} that this iteration adds on top of
 * the moved detail card.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AdminMemberPageServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private AdminMemberPageService $service;
    private MemberService $memberService;
    private MemberEmailRepository $emailRepository;
    private int $currentYearId;
    private int $pastYearId;
    private int $memberId;
    private int $memberYearId;
    private int $louveteauxId;
    private int $baladinsId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->pastYearId = $scoutYearService->ensureYear('2024-2025');
        $this->currentYearId = $scoutYearService->ensureYear('2025-2026');

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('BAL01', {$branchId}, 'Ruche')");
        $this->baladinsId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')");
        $this->louveteauxId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIM', 'Animateur', 'chief', 1)");
        $functionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('TRES', 'Trésorier', 'chief', 1)");
        $secondFunctionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $this->memberId, $this->currentYearId,
            $this->enc->encrypt('Margaux', 'member_years.first_name'),
            $this->enc->encrypt('VANDENBRANDE', 'member_years.last_name'),
            $this->enc->encrypt('famille@ex.be', 'member_years.email'),
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberYearId, $functionId, $this->louveteauxId, 1]);
        $stmt->execute([$this->memberYearId, $secondFunctionId, $this->louveteauxId, 0]);

        $memberYearRepo = new \Core\Import\MemberYearRepository($this->pdo);
        $this->memberService = new MemberService($memberYearRepo, $this->enc, $connection);
        $badgeRepository = new MemberBadgeRepository($this->pdo);
        $this->emailRepository = new MemberEmailRepository($this->pdo, $this->enc);

        $this->service = new AdminMemberPageService(
            $badgeRepository,
            new MemberPhotoService(new MemberPhotoRepository($this->pdo)),
            new SectionMembershipRepository($this->pdo),
            new SectionService($connection, $this->enc, $badgeRepository),
            $scoutYearService,
            $this->emailRepository
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return $this->service->buildPageData(
            $this->memberService->getMemberProfile($this->memberYearId),
            $this->currentYearId
        );
    }

    public function testTheYearsFunctionsComeBackMainOneFirst(): void
    {
        $functions = $this->build()['functions'];

        $this->assertCount(2, $functions);
        $this->assertSame('Animateur', $functions[0]['label']);
        $this->assertTrue($functions[0]['is_main']);
        $this->assertSame('Meute', $functions[0]['section']);
        $this->assertFalse($functions[1]['is_main']);
    }

    public function testBadgesAreTheOnesAssignedForThisScoutYear(): void
    {
        $this->pdo->exec("INSERT INTO badges (name, is_default, is_active) VALUES ('Infirmier', 0, 1)");
        $badgeId = (int) $this->pdo->lastInsertId();
        (new MemberBadgeRepository($this->pdo))->assign($this->memberYearId, $badgeId, null);

        $badges = $this->build()['badges'];

        $this->assertCount(1, $badges);
        $this->assertSame('Infirmier', $badges[0]->name);
    }

    /**
     * The history is read from member_section_periods, keyed on
     * members.id — the persistent identity — so it survives every scout
     * year the member has lived through. That is the whole point of
     * showing it.
     */
    public function testSectionHistorySpansTheYearsAndMarksTheCurrentOne(): void
    {
        $this->insertPeriod($this->pastYearId, $this->baladinsId);
        $this->insertPeriod($this->currentYearId, $this->louveteauxId);

        $history = $this->build()['section_history'];

        $this->assertCount(2, $history);
        // Most recent first.
        $this->assertSame('2025-2026', $history[0]['scout_year_label']);
        $this->assertSame('Meute', $history[0]['section_name']);
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame('2024-2025', $history[1]['scout_year_label']);
        $this->assertSame('Ruche', $history[1]['section_name']);
        $this->assertFalse($history[1]['is_current']);
    }

    public function testSectionHistoryCollapsesTwoPeriodsOfTheSameYearAndSection(): void
    {
        // A member who left a section and came back inside one year has
        // two periods; the page shows where they were, not how many rows
        // the import wrote.
        $this->insertPeriod($this->currentYearId, $this->louveteauxId, '2025-09-01', '2025-11-30');
        $this->insertPeriod($this->currentYearId, $this->louveteauxId, '2026-01-05');

        $this->assertCount(1, $this->build()['section_history']);
    }

    public function testAMemberWithNoRecordedPeriodsGetsAnEmptyHistoryRatherThanAnError(): void
    {
        $this->assertSame([], $this->build()['section_history']);
    }

    public function testThePhotoIsResolvedForTheYearBeingShown(): void
    {
        $this->assertNull($this->build()['photo_file_id']);
    }

    /**
     * ARCHITECTURE.md §8.27: secondary addresses are strict self-service,
     * with no chief or admin bypass. Showing them is defensible; making
     * them editable is not — so the service hands back an address and a
     * state, and nothing this page could act on.
     */
    public function testSecondaryAddressesComeBackAsReadOnlyRows(): void
    {
        $this->emailRepository->create($this->memberId, 'margaux@ex.be', 'manual', 'valid', null, null);

        $rows = $this->build()['member_emails'];

        $this->assertCount(1, $rows);
        $this->assertSame('margaux@ex.be', $rows[0]['address']);
        $this->assertSame('valid', $rows[0]['status']);
        // No id, no token, no confirmation hash — nothing a mutation
        // endpoint could be built on from this data.
        $this->assertSame(['address', 'status'], array_keys($rows[0]));
    }

    /**
     * The Desk address has its own line in the Desk half of the page.
     * Repeating it under « Adresses secondaires » would say the member
     * added an address they never touched.
     */
    public function testTheDeskAddressIsNotListedAmongTheSecondaryOnes(): void
    {
        $this->emailRepository->create($this->memberId, 'famille@ex.be', 'desk', 'valid', null, null);
        $this->emailRepository->create($this->memberId, 'margaux@ex.be', 'manual', 'valid', null, null);

        $addresses = array_column($this->build()['member_emails'], 'address');

        $this->assertSame(['margaux@ex.be'], $addresses);
    }

    /**
     * ARCHITECTURE.md §8.3 — owner-scoped files carry an explicit
     * no-chief-and-no-admin-bypass guarantee. This page must never grow a
     * documents block, and the service is where that would first appear.
     */
    public function testThePageDataCarriesNoDocumentBlockAtAll(): void
    {
        $keys = array_keys($this->build());

        foreach ($keys as $key) {
            $this->assertStringNotContainsStringIgnoringCase('document', $key);
        }
    }

    private function insertPeriod(int $scoutYearId, int $sectionId, string $start = '2025-09-01', ?string $end = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $sectionId, $scoutYearId, $start, $end]);
    }
}
