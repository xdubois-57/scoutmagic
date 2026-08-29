<?php

declare(strict_types=1);

namespace Tests\Core\Offline;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Import\AgeBranchRepository;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\TemporaryMemberProviderInterface;
use Core\Member\UnitStaffSectionService;
use Core\Module\StaffDirectoryProvider;
use Core\Offline\OfflineManifestService;
use Core\Offline\OfflineWhitelist;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class OfflineManifestServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private OfflineWhitelist $offlineWhitelist;
    private FileRepository $fileRepository;
    private MemberPhotoService $memberPhotoService;
    private SectionPhotoService $sectionPhotoService;
    private SectionService $sectionService;
    private MemberService $memberService;
    private int $scoutYearId;
    private int $branchId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->offlineWhitelist = new OfflineWhitelist();
        $this->fileRepository = new FileRepository($this->pdo);
        $this->memberPhotoService = new MemberPhotoService(new MemberPhotoRepository($this->pdo));
        $this->sectionPhotoService = new SectionPhotoService(new SectionPhotoRepository($this->pdo));
        $memberBadgeRepository = new \Core\Badge\MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService($connection, $this->encryption, $memberBadgeRepository);
        $this->memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $this->branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$this->branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    private function buildService(
        ?StaffDirectoryProvider $staffDirectoryProvider = null,
        ?TemporaryMemberProviderInterface $temporaryMemberProvider = null
    ): OfflineManifestService {
        $hooks = new \Core\Module\HookRegistry();
        if ($staffDirectoryProvider !== null) {
            $hooks->register(StaffDirectoryProvider::class, $staffDirectoryProvider);
        }

        return new OfflineManifestService(
            $this->offlineWhitelist,
            $this->memberService,
            $this->memberPhotoService,
            $this->sectionPhotoService,
            $this->sectionService,
            new UnitStaffSectionService($this->pdo),
            new ScoutYearService($this->pdo),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new AgeBranchRepository($this->pdo),
            $hooks,
            $temporaryMemberProvider
        );
    }

    /**
     * A MemberService whose linked list carries an extra, temporarily-added
     * member (ARCHITECTURE.md §8.42) plus the provider that named it — the
     * pair the manifest has to recognise in order to drop it again.
     *
     * @return array{0: MemberService, 1: TemporaryMemberProviderInterface}
     */
    private function withTemporaryMember(int $memberYearId): array
    {
        $provider = new class ($memberYearId) implements TemporaryMemberProviderInterface {
            public function __construct(private int $memberYearId)
            {
            }

            public function resolveMemberYearId(string $email): ?int
            {
                return $this->memberYearId;
            }
        };

        return [
            new MemberService(
                new MemberYearRepository($this->pdo),
                $this->encryption,
                Connection::withPdo($this->pdo),
                $provider
            ),
            $provider,
        ];
    }

    private function createFile(string $roleMin = 'public'): int
    {
        static $n = 0;
        $n++;
        return $this->fileRepository->create("core/test/photo{$n}.jpg", 'photo.jpg', 'image/jpeg', 100, $roleMin, null, null);
    }

    private function createLinkedMember(string $email, string $deskId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();

        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'ANIME'")->fetchColumn();
        if ($functionId === 0) {
            $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIME', 'Animé', 'identified', 1)");
            $functionId = (int) $this->pdo->lastInsertId();
        }

        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('Jean', 'member_years.first_name'), $this->encryption->encrypt('Dupont', 'member_years.last_name'), $this->encryption->encrypt($email, 'member_years.email'), $blindIndex]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$memberYearId, $functionId, $this->sectionId]);

        return $memberId;
    }

    // --- RBAC boundaries ---

    public function testGuestGetsOnlyPublicPagesAndNoImagesRequiringAnEmail(): void
    {
        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, null);

        $this->assertSame(['/', '/contact', '/sections', '/rgpd', '/aide'], $manifest['pages']);
    }

    public function testGuestNeverGetsAMemberPageEvenIfSomehowGivenAnEmail(): void
    {
        // Defence in depth: PUBLIC role never includes the /members/ entry
        // at all (role_min identified), so an email is irrelevant here —
        // the entry itself is filtered out before member expansion runs.
        $this->createLinkedMember('parent@test.example', 'DESK1');

        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, 'parent@test.example');

        foreach ($manifest['pages'] as $page) {
            $this->assertStringNotContainsString('/members/', $page);
        }
    }

    public function testChiefRoleGetsChiefOnlyPagesWhenRegistered(): void
    {
        $this->offlineWhitelist->registerModuleEntries('member_stats', [
            ['path' => '/chiefs/stats', 'label' => 'Statistiques', 'match' => 'exact', 'role_min' => 'chief'],
        ]);

        $manifest = $this->buildService()->buildManifest(Role::CHIEF, null);

        $this->assertContains('/chiefs/stats', $manifest['pages']);
        $this->assertContains('/chefs/staffs', $manifest['pages']);
    }

    public function testIdentifiedRoleNeverGetsAChiefOnlyPage(): void
    {
        $this->offlineWhitelist->registerModuleEntries('member_stats', [
            ['path' => '/chiefs/stats', 'label' => 'Statistiques', 'match' => 'exact', 'role_min' => 'chief'],
        ]);

        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, null);

        $this->assertNotContains('/chiefs/stats', $manifest['pages']);
    }

    // --- /members/ expansion ---

    public function testMembersEntryExpandsToEachLinkedMembersOwnPage(): void
    {
        $this->createLinkedMember('parent@test.example', 'DESK1');
        $this->createLinkedMember('parent@test.example', 'DESK2');

        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, 'parent@test.example');

        $memberPages = array_values(array_filter($manifest['pages'], fn($p) => str_starts_with($p, '/members/')));
        $this->assertCount(2, $memberPages);
    }

    public function testTemporaryMemberIsExcludedFromTheManifest(): void
    {
        $this->createLinkedMember('parent@test.example', 'DESK1');
        $this->createLinkedMember('anime@test.example', 'DESK2');
        $temporaryMemberYearId = (int) $this->pdo->query(
            "SELECT my.id FROM member_years my JOIN members m ON my.member_id = m.id WHERE m.desk_id = 'DESK2'"
        )->fetchColumn();

        [$memberService, $provider] = $this->withTemporaryMember($temporaryMemberYearId);
        $this->memberService = $memberService;

        // The override IS in the linked list the rest of the app sees...
        $this->assertCount(2, $memberService->getLinkedMembers('parent@test.example', $this->scoutYearId));

        // ...but never in what the browser is told to cache offline, which
        // outlives the session that granted it.
        $manifest = $this->buildService(null, $provider)->buildManifest(Role::IDENTIFIED, 'parent@test.example');

        $memberPages = array_values(array_filter($manifest['pages'], fn($p) => str_starts_with($p, '/members/')));
        $this->assertCount(1, $memberPages);
    }

    public function testMembersEntryNeverAppearsLiterally(): void
    {
        $this->createLinkedMember('parent@test.example', 'DESK1');

        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, 'parent@test.example');

        $this->assertNotContains('/members/', $manifest['pages']);
    }

    public function testIdentifiedWithNoLinkedMembersGetsNoMemberPages(): void
    {
        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, 'stranger@test.example');

        foreach ($manifest['pages'] as $page) {
            $this->assertStringNotContainsString('/members/', $page);
        }
    }

    // --- images: exact variant URLs ---

    public function testHomeHeroImageUsesTheMdVariant(): void
    {
        $fileId = $this->createFile();
        $stmt = $this->pdo->prepare('INSERT INTO editable_contents (content_key, content_type, content_value) VALUES (?, ?, ?)');
        $stmt->execute(['home.hero', 'image', (string) $fileId]);

        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, null);

        $this->assertContains("/files/{$fileId}/md", $manifest['images']);
    }

    public function testContactStaffduPhotoUsesTheMdVariant(): void
    {
        $fileId = $this->createFile();
        $unitStaffSectionService = new UnitStaffSectionService($this->pdo);
        $staffduId = $unitStaffSectionService->ensureSection();
        $stmt = $this->pdo->prepare('INSERT INTO section_staff_photos (section_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$staffduId, $this->scoutYearId, $fileId]);

        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, null);

        $this->assertContains("/files/{$fileId}/md", $manifest['images']);
    }

    public function testSectionPhotoUsesTheMdVariant(): void
    {
        $fileId = $this->createFile();
        $stmt = $this->pdo->prepare('INSERT INTO section_staff_photos (section_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$this->sectionId, $this->scoutYearId, $fileId]);

        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, null);

        $this->assertContains("/files/{$fileId}/md", $manifest['images']);
    }

    public function testTrombinoscopeStaffPhotosUseTheThumbVariantAndRequireTheProvider(): void
    {
        $this->offlineWhitelist->registerModuleEntries('trombinoscope', [
            ['path' => '/trombinoscope', 'label' => 'Trombinoscope', 'match' => 'exact', 'role_min' => 'identified'],
        ]);
        $memberId = $this->createLinkedMember('staff@test.example', 'DESK3');
        $fileId = $this->createFile('identified');
        $stmt = $this->pdo->prepare('INSERT INTO member_photos (member_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberId, $this->scoutYearId, $fileId]);

        $provider = new class ($memberId) implements StaffDirectoryProvider {
            public function __construct(private int $id) {}
            public function getAllEligibleStaffMemberIds(int $scoutYearId): array { return [$this->id]; }
        };

        $manifest = $this->buildService($provider)->buildManifest(Role::IDENTIFIED, null);

        $this->assertContains("/files/{$fileId}/thumb", $manifest['images']);
    }

    public function testTrombinoscopePhotosAreAbsentWhenModuleDisabled(): void
    {
        // No offline entry registered for /trombinoscope and no provider —
        // same as the module being disabled.
        $memberId = $this->createLinkedMember('staff@test.example', 'DESK3');
        $fileId = $this->createFile('identified');
        $stmt = $this->pdo->prepare('INSERT INTO member_photos (member_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberId, $this->scoutYearId, $fileId]);

        $manifest = $this->buildService(null)->buildManifest(Role::IDENTIFIED, null);

        $this->assertNotContains("/files/{$fileId}/thumb", $manifest['images']);
    }

    public function testLinkedMembersOwnPhotoUsesTheThumbVariant(): void
    {
        $memberId = $this->createLinkedMember('parent@test.example', 'DESK1');
        $fileId = $this->createFile('identified');
        $stmt = $this->pdo->prepare('INSERT INTO member_photos (member_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberId, $this->scoutYearId, $fileId]);

        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, 'parent@test.example');

        $this->assertContains("/files/{$fileId}/thumb", $manifest['images']);
    }

    public function testLinkedMembersBranchLogoUsesTheMdVariant(): void
    {
        $ageBranchRepository = new AgeBranchRepository($this->pdo);
        $fileId = $this->createFile();
        $ageBranchRepository->setLogo($this->branchId, $fileId);
        $this->createLinkedMember('parent@test.example', 'DESK1');

        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, 'parent@test.example');

        $this->assertContains("/files/{$fileId}/md", $manifest['images']);
    }

    public function testImagesAndPagesHaveNoDuplicates(): void
    {
        $fileId = $this->createFile();
        $stmt = $this->pdo->prepare('INSERT INTO section_staff_photos (section_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$this->sectionId, $this->scoutYearId, $fileId]);

        $manifest = $this->buildService()->buildManifest(Role::CHIEF, null);

        $this->assertSame(array_unique($manifest['images']), array_values($manifest['images']));
        $this->assertSame(array_unique($manifest['pages']), array_values($manifest['pages']));
    }
}
