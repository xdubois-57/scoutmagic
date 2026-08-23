<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Module\MenuEntry;
use Core\Security\EncryptionService;
use Core\View\MenuBuilder;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalMenuHookService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Iteration 1's acceptance criterion: two public assets, one pinned to the
 * menu and one not, produce a coherent menu and index page.
 */
class RentalMenuHookServiceTest extends TestCase
{
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;

    private const YEAR = 7;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($pdo);

        $this->assetRepository = new RentalAssetRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->managerRepository = new RentalAssetManagerRepository($pdo);
    }

    private function createAsset(string $name, string $slug, bool $isPublic): int
    {
        return $this->assetRepository->create(
            'Local',
            $name,
            $slug,
            null,
            1,
            null,
            null,
            null,
            $isPublic
        );
    }

    /**
     * @param array<string, int[]> $membersByEmail
     * @param string[] $unitStaffEmails
     */
    private function hook(array $membersByEmail = [], array $unitStaffEmails = []): RentalMenuHookService
    {
        $memberService = new class ($membersByEmail, $unitStaffEmails) extends MemberService {
            /**
             * @param array<string, int[]> $membersByEmail
             * @param string[] $unitStaffEmails
             */
            public function __construct(private array $membersByEmail, private array $unitStaffEmails)
            {
            }

            public function getLinkedMembers(string $email, int $scoutYearId): array
            {
                return array_map(
                    fn(int $memberId) => new MemberProfile(
                        memberYearId: $memberId * 100,
                        memberId: $memberId,
                        deskId: 'D' . $memberId,
                        firstName: 'Prénom',
                        lastName: 'Nom',
                        totem: null,
                        quali: null,
                        gender: null,
                        birthDate: null,
                        phone: null,
                        mobile: null,
                        email: $email,
                        patrol: null,
                        formationLevel: null,
                        federationMailConsent: false,
                        unitMailConsent: false,
                        addresses: [],
                        functions: [],
                        scoutYearLabel: '2025-2026'
                    ),
                    $this->membersByEmail[$email] ?? []
                );
            }

            public function isUnitChief(string $email, int $scoutYearId): bool
            {
                return in_array($email, $this->unitStaffEmails, true);
            }
        };

        return new RentalMenuHookService(
            $this->assetRepository,
            new RentalAuthorizationService($memberService, $this->assetRepository, $this->managerRepository),
            self::YEAR
        );
    }

    /**
     * @param MenuEntry[] $entries
     * @return array<int, array{label: string, url: string, menu: string}>
     */
    private function simplify(array $entries): array
    {
        return array_map(
            fn(MenuEntry $e) => ['label' => $e->label, 'url' => $e->url, 'menu' => $e->menuId],
            $entries
        );
    }

    public function testNoAssetsProduceNoEntriesAtAll(): void
    {
        $this->assertSame([], $this->hook()->getMenuEntries(null));
        $this->assertSame([], $this->hook()->getMenuEntries('someone@example.org'));
    }

    public function testSeveralPublicAssetsStillProduceExactlyOneMenuEntry(): void
    {
        // The point of the whole entry set: however many assets a unit
        // publishes, "Notre unité" gains ONE line. Six assets used to mean
        // six entries, which pushed every other page in that menu off the
        // screen; each asset is reached from the index page instead.
        $this->createAsset('Local Saint-Georges', 'local-saint-georges', true);
        $this->createAsset('Terrain de la Sablière', 'terrain-de-la-sabliere', true);
        $this->createAsset('Remorque', 'remorque', true);

        $entries = $this->hook()->getMenuEntries(null);

        $this->assertSame([
            ['label' => 'Locations', 'url' => '/locations', 'menu' => MenuBuilder::MENU_NOTRE_UNITE],
        ], $this->simplify($entries));

        // All three are still listed by the index page itself.
        $this->assertCount(3, $this->hook()->listPublicAssets());
    }

    public function testNoEntryEverPointsAtASingleAsset(): void
    {
        // Guards the removal directly: an entry whose URL is deeper than
        // /locations would be a per-asset entry by another name.
        $this->createAsset('Local Saint-Georges', 'local-saint-georges', true);

        foreach ($this->hook()->getMenuEntries(null) as $entry) {
            $this->assertSame('/locations', $entry->url);
        }
    }

    public function testTheIndexPageExistsAsSoonAsOnePublicAssetExists(): void
    {
        // Without this the module would have no menu entry at all, and its
        // assets would be unreachable to anyone who did not already know
        // the URL (roadmap §6.2).
        $this->createAsset('Terrain', 'terrain', true);

        $entries = $this->hook()->getMenuEntries(null);

        $this->assertSame([
            ['label' => 'Locations', 'url' => '/locations', 'menu' => MenuBuilder::MENU_NOTRE_UNITE],
        ], $this->simplify($entries));
    }

    public function testAPrivateAssetProducesNoPublicEntry(): void
    {
        $this->createAsset('Local privé', 'local-prive', false);

        $this->assertSame([], $this->hook()->getMenuEntries(null));
        $this->assertSame([], $this->hook()->listPublicAssets());
    }

    public function testAnArchivedAssetDropsOutOfTheMenuAndTheIndex(): void
    {
        $id = $this->createAsset('Local', 'local', true);
        $this->assertCount(1, $this->hook()->getMenuEntries(null));

        $this->assetRepository->setArchived($id, true);

        $this->assertSame([], $this->hook()->getMenuEntries(null));
    }

    public function testPublicEntriesAreContributedForAnAnonymousVisitor(): void
    {
        // The generalised hook takes a nullable email precisely so a public
        // entry is expressible; the old one could not do this.
        $this->createAsset('Local', 'local', true);

        $entries = $this->hook()->getMenuEntries(null);

        $this->assertCount(1, $entries);
        foreach ($entries as $entry) {
            $this->assertSame('public', $entry->roleMin);
            $this->assertSame(MenuBuilder::MENU_NOTRE_UNITE, $entry->menuId);
        }
    }

    public function testMesLocationsAppearsOnlyForAnActualManager(): void
    {
        $asset = $this->createAsset('Local', 'local', true);
        $this->managerRepository->grant($asset, 1, false);

        $hook = $this->hook([
            'manager@example.org' => [1],
            'other@example.org' => [2],
        ]);

        $managerUrls = array_column($this->simplify($hook->getMenuEntries('manager@example.org')), 'url');
        $this->assertContains('/mes-locations', $managerUrls);

        $otherUrls = array_column($this->simplify($hook->getMenuEntries('other@example.org')), 'url');
        $this->assertNotContains('/mes-locations', $otherUrls);

        $anonymousUrls = array_column($this->simplify($hook->getMenuEntries(null)), 'url');
        $this->assertNotContains('/mes-locations', $anonymousUrls);
    }

    public function testMesLocationsAppearsForUnitStaffWithoutAnyGrant(): void
    {
        $this->createAsset('Local', 'local', true);
        $hook = $this->hook(['chief@example.org' => [5]], ['chief@example.org']);

        $urls = array_column($this->simplify($hook->getMenuEntries('chief@example.org')), 'url');

        $this->assertContains('/mes-locations', $urls);
    }

    public function testMesLocationsIsGatedOnIdentifiedAndSitsInEspaceAnimes(): void
    {
        $asset = $this->createAsset('Local', 'local', false);
        $this->managerRepository->grant($asset, 1, false);

        $entries = $this->hook(['manager@example.org' => [1]])->getMenuEntries('manager@example.org');

        $this->assertCount(1, $entries, 'A non-public asset contributes no public entry.');
        $this->assertSame('Mes locations', $entries[0]->label);
        $this->assertSame(MenuBuilder::MENU_ESPACE_ANIMES, $entries[0]->menuId);
        $this->assertSame('identified', $entries[0]->roleMin);
    }

    public function testEntriesRenderIntoTheRealMenuBuilderForAPublicVisitor(): void
    {
        // End-to-end through MenuBuilder itself, so a mistake in the entry's
        // roleMin or menu id shows up as a missing page rather than as a
        // passing unit test.
        $this->createAsset('Local Saint-Georges', 'local-saint-georges', true);
        $builder = new MenuBuilder(\Core\Security\Role::fromString('public'));

        foreach ($this->hook()->getMenuEntries(null) as $entry) {
            $builder->addPage(
                $entry->menuId,
                $entry->label,
                $entry->url,
                $entry->roleMin,
                $entry->order,
                $entry->isDynamic,
                $entry->subtitle,
                $entry->sortGroup
            );
        }

        $menus = $builder->build();
        $this->assertCount(1, $menus);
        $this->assertSame(MenuBuilder::MENU_NOTRE_UNITE, $menus[0]['id']);
        $this->assertSame(['Locations'], array_column($menus[0]['pages'], 'label'));
    }
}
