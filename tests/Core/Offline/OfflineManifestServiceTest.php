<?php

declare(strict_types=1);

namespace Tests\Core\Offline;

use Core\Database\Connection;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\TemporaryMemberProviderInterface;
use Core\Offline\HelpPages;
use Core\Offline\LinkedMemberPages;
use Core\Offline\OfflineManifestService;
use Core\Offline\OfflinePageProvider;
use Core\Offline\OfflinePageProviders;
use Core\Offline\OfflineWhitelist;
use Core\Security\EncryptionService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The exact pages held offline (#883): fixed paths and the pages of each
 * provider, filtered by role, `last` apart — and nothing else.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class OfflineManifestServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private OfflineWhitelist $offlineWhitelist;
    private OfflinePageProviders $providers;
    private MemberService $memberService;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->offlineWhitelist = new OfflineWhitelist();
        $this->providers = new OfflinePageProviders();
        $this->memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
        );

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    private function buildService(?TemporaryMemberProviderInterface $temporaryMemberProvider = null): OfflineManifestService
    {
        $this->providers->register(new LinkedMemberPages(
            $this->memberService,
            new \Core\ScoutYear\ScoutYearResolver(
                new \Core\Config\ScoutYearService($this->pdo),
                new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                new MemberYearRepository($this->pdo)
            ),
            $temporaryMemberProvider
        ));
        $this->providers->register(new HelpPages(new HelpService(new HelpRegistry(dirname(__DIR__, 3) . '/docs/help'))));

        return new OfflineManifestService($this->offlineWhitelist, $this->providers);
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
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption),
    $provider
),
            $provider,
        ];
    }

    /** @return int the member_years id, which is what /members/{id} names */
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

        return $memberYearId;
    }

    // --- RBAC boundaries ---

    public function testGuestGetsTheFixedPublicPagesAndTheHelpLast(): void
    {
        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, null);

        $this->assertSame(['/', '/contact', '/sections', '/rgpd'], $manifest['pages']);
        $this->assertSame('/aide', $manifest['last'][0]);
        $this->assertContains('/aide/installer-application', $manifest['last']);
    }

    public function testGuestNeverGetsAMemberPageEvenIfSomehowGivenAnEmail(): void
    {
        $this->createLinkedMember('parent@test.example', 'DESK1');

        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, 'parent@test.example');

        foreach ($manifest['pages'] as $page) {
            $this->assertStringNotContainsString('/members/', $page);
        }
    }

    /** #883: the Staffs page is pre-downloaded now, like every entry. */
    public function testTheStaffsPageIsHeldForAnIntendantAndNotForAMember(): void
    {
        $this->assertContains('/chefs/staffs', $this->buildService()->buildManifest(Role::INTENDANT, null)['pages']);
        $this->assertNotContains('/chefs/staffs', $this->buildService()->buildManifest(Role::IDENTIFIED, null)['pages']);
    }

    public function testIdentifiedRoleNeverGetsAPageAboveItsRole(): void
    {
        $this->offlineWhitelist->registerModuleEntries('test', [
            ['path' => '/chiefs/only', 'provider' => null, 'prefix' => null, 'label' => 'Chefs', 'role_min' => 'chief', 'last' => false],
        ]);

        $this->assertNotContains('/chiefs/only', $this->buildService()->buildManifest(Role::IDENTIFIED, null)['pages']);
        $this->assertContains('/chiefs/only', $this->buildService()->buildManifest(Role::CHIEF, null)['pages']);
    }

    // --- The fiches of the linked members, and of nobody else ---

    /**
     * /members/{id} names a member_years row, not a member (MemberController::show()).
     * A member with no year of their own comes first, so that the two ids
     * differ and a page named after the wrong one cannot pass for right.
     */
    public function testEachLinkedMembersOwnFicheIsHeld(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK0')");
        $first = $this->createLinkedMember('parent@test.example', 'DESK1');
        $second = $this->createLinkedMember('parent@test.example', 'DESK2');
        $this->createLinkedMember('other@test.example', 'DESK3');

        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, 'parent@test.example');

        $memberPages = array_values(array_filter($manifest['pages'], static fn ($p) => str_starts_with($p, '/members')));
        $this->assertEqualsCanonicalizing(['/members/' . $first, '/members/' . $second], $memberPages);
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
        $manifest = $this->buildService($provider)->buildManifest(Role::IDENTIFIED, 'parent@test.example');

        $memberPages = array_values(array_filter($manifest['pages'], static fn ($p) => str_starts_with($p, '/members')));
        $this->assertCount(1, $memberPages);
    }

    public function testIdentifiedWithNoLinkedMembersGetsNoMemberPages(): void
    {
        $manifest = $this->buildService()->buildManifest(Role::IDENTIFIED, 'stranger@test.example');

        foreach ($manifest['pages'] as $page) {
            $this->assertStringNotContainsString('/members', $page);
        }
    }

    // --- Providers ---

    /**
     * A module's entry: its fixed page and its provider's pages are held
     * with no change anywhere but the module itself (#883).
     */
    public function testAModulesFixedPageAndProviderPagesAreHeld(): void
    {
        $this->offlineWhitelist->registerModuleEntries('test', [
            ['path' => '/fixe', 'provider' => null, 'prefix' => null, 'label' => 'Fixe', 'role_min' => 'public', 'last' => false],
            ['path' => null, 'provider' => FixedPages::class, 'prefix' => '/test', 'label' => 'Test', 'role_min' => 'public', 'last' => false],
        ]);
        $this->providers->register(new FixedPages(['/test', '/test/7', '/test/8']));

        $pages = $this->buildService()->buildManifest(Role::PUBLIC, null)['pages'];

        $this->assertSame(['/fixe', '/test', '/test/7', '/test/8'], array_slice($pages, -4));
    }

    /** A provider never widens its entry beyond the prefix it declares. */
    public function testAPageOutsideItsProvidersPrefixIsDropped(): void
    {
        $this->offlineWhitelist->registerModuleEntries('test', [
            ['path' => null, 'provider' => FixedPages::class, 'prefix' => '/test', 'label' => 'Test', 'role_min' => 'public', 'last' => false],
        ]);
        $outside = [
            '/testing', '/finance', '//evil.example/test', 'https://evil.example/test',
            // Under the prefix as a string, not as a path once resolved.
            '/test/../finance', '/test/./7', '/test//7',
            // Not an exact address: the list never holds a query or a fragment.
            '/test/7?page=2', '/test/7#top', '/test\\..\\finance',
        ];
        $this->providers->register(new FixedPages(['/test/1', ...$outside, '/test/2']));

        $pages = $this->buildService()->buildManifest(Role::PUBLIC, null)['pages'];

        $this->assertContains('/test/1', $pages);
        $this->assertContains('/test/2', $pages);
        foreach ($outside as $path) {
            $this->assertNotContains($path, $pages);
        }
    }

    public function testAProviderNobodyRegisteredContributesNothing(): void
    {
        $this->offlineWhitelist->registerModuleEntries('test', [
            ['path' => null, 'provider' => 'Modules\\Missing\\Pages', 'prefix' => '/missing', 'label' => 'X', 'role_min' => 'public', 'last' => false],
        ]);

        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, null);

        $this->assertSame(['/', '/contact', '/sections', '/rgpd'], $manifest['pages']);
    }

    public function testThereAreNoImagesAndNoDuplicates(): void
    {
        $this->offlineWhitelist->registerModuleEntries('test', [
            ['path' => '/contact', 'provider' => null, 'prefix' => null, 'label' => 'Contact', 'role_min' => 'public', 'last' => false],
        ]);

        $manifest = $this->buildService()->buildManifest(Role::PUBLIC, null);

        $this->assertSame(['pages', 'last'], array_keys($manifest), 'the images are read in the stored pages, not listed here');
        $this->assertSame(array_values(array_unique($manifest['pages'])), $manifest['pages']);
        $this->assertSame([], array_intersect($manifest['pages'], $manifest['last']));
    }
}

/** A provider answering a fixed list, for the tests above. */
final class FixedPages implements OfflinePageProvider
{
    /** @param list<string> $pages */
    public function __construct(private array $pages)
    {
    }

    public function pagesFor(Role $role, ?string $email): array
    {
        return $this->pages;
    }
}
