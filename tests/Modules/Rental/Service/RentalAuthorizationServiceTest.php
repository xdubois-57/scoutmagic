<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The module's security boundary. Its routes are `role_min: identified` —
 * as low as a logged-in visitor gets, because a manager need not be a chief
 * — so this service, not the route guard, is what actually protects an
 * asset's data. Every case below is therefore a real authorization
 * assertion, not a convenience check.
 */
class RentalAuthorizationServiceTest extends TestCase
{
    private \PDO $pdo;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;

    private const YEAR = 7;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->assetRepository = new RentalAssetRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
    }

    private function createAsset(string $name, string $slug, bool $archived = false): int
    {
        $id = $this->assetRepository->create('Local', $name, $slug, null, 1, null, null, null, true);
        if ($archived) {
            $this->assetRepository->setArchived($id, true);
        }

        return $id;
    }

    /**
     * A MemberService stub: which member ids an email resolves to, and
     * whether that email is unit staff. Those two answers are the entire
     * surface RentalAuthorizationService uses, and stubbing them keeps this
     * test about authorization rather than about member decryption.
     *
     * @param array<string, int[]> $membersByEmail
     * @param string[] $unitStaffEmails
     */
    private function service(array $membersByEmail, array $unitStaffEmails = []): RentalAuthorizationService
    {
        $memberService = new class ($membersByEmail, $unitStaffEmails) extends MemberService {
            /**
             * @param array<string, int[]> $membersByEmail
             * @param string[] $unitStaffEmails
             */
            public function __construct(
                private array $membersByEmail,
                private array $unitStaffEmails
            ) {
                // Deliberately does not call parent::__construct(): this
                // stub answers from its own arrays and never touches a
                // database, an encryption key, or a connection.
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

        return new RentalAuthorizationService($memberService, $this->assetRepository, $this->managerRepository);
    }

    public function testAManagerMayManageTheirOwnAsset(): void
    {
        $hall = $this->createAsset('Local', 'local');
        $this->managerRepository->grant($hall, 1, false);
        $service = $this->service(['manager@example.org' => [1]]);

        $this->assertTrue($service->canManageAssetId('manager@example.org', self::YEAR, $hall));
    }

    public function testAManagerMayNotManageSomeoneElsesAsset(): void
    {
        // The single most important case in this file: managing one asset
        // must never imply anything about another.
        $mine = $this->createAsset('Mon local', 'mon-local');
        $theirs = $this->createAsset('Leur local', 'leur-local');
        $this->managerRepository->grant($mine, 1, false);
        $this->managerRepository->grant($theirs, 2, false);
        $service = $this->service(['manager@example.org' => [1]]);

        $this->assertTrue($service->canManageAssetId('manager@example.org', self::YEAR, $mine));
        $this->assertFalse($service->canManageAssetId('manager@example.org', self::YEAR, $theirs));
    }

    public function testAnIdentifiedMemberWithNoGrantIsRefused(): void
    {
        $hall = $this->createAsset('Local', 'local');
        $service = $this->service(['someone@example.org' => [9]]);

        $this->assertFalse($service->canManageAssetId('someone@example.org', self::YEAR, $hall));
        $this->assertSame([], $service->listManageableAssets('someone@example.org', self::YEAR));
        $this->assertFalse($service->managesAnyAsset('someone@example.org', self::YEAR));
    }

    public function testAnAnonymousVisitorIsRefusedEverywhere(): void
    {
        $hall = $this->createAsset('Local', 'local');
        $this->managerRepository->grant($hall, 1, false);
        $service = $this->service(['manager@example.org' => [1]]);

        foreach ([null, ''] as $email) {
            $this->assertFalse($service->canManageAssetId($email, self::YEAR, $hall));
            $this->assertFalse($service->isUnitStaff($email, self::YEAR));
            $this->assertFalse($service->managesAnyAsset($email, self::YEAR));
            $this->assertSame([], $service->listManageableAssets($email, self::YEAR));
        }
    }

    public function testUnitStaffManagesEveryAssetWithoutAnyGrant(): void
    {
        $a = $this->createAsset('A', 'a');
        $b = $this->createAsset('B', 'b');
        $service = $this->service(['chief@example.org' => [5]], ['chief@example.org']);

        $this->assertTrue($service->canManageAssetId('chief@example.org', self::YEAR, $a));
        $this->assertTrue($service->canManageAssetId('chief@example.org', self::YEAR, $b));
        $this->assertCount(2, $service->listManageableAssets('chief@example.org', self::YEAR));
        $this->assertTrue($service->isUnitStaff('chief@example.org', self::YEAR));
    }

    public function testUnitStaffCannotBeLockedOutByADeactivatedGrant(): void
    {
        // Staff d'U authority is resolved separately from the grant table
        // precisely so a revoked or import-suspended row cannot strand a
        // unit chief outside their own unit's assets.
        $hall = $this->createAsset('Local', 'local');
        $this->managerRepository->grant($hall, 5, false);
        $this->managerRepository->deactivateForMembers([5]);
        $service = $this->service(['chief@example.org' => [5]], ['chief@example.org']);

        $this->assertTrue($service->canManageAssetId('chief@example.org', self::YEAR, $hall));
    }

    public function testADeactivatedGrantRemovesAccessForAnOrdinaryManager(): void
    {
        $hall = $this->createAsset('Local', 'local');
        $this->managerRepository->grant($hall, 1, false);
        $service = $this->service(['manager@example.org' => [1]]);
        $this->assertTrue($service->canManageAssetId('manager@example.org', self::YEAR, $hall));

        $this->managerRepository->deactivateForMembers([1]);

        $this->assertFalse($service->canManageAssetId('manager@example.org', self::YEAR, $hall));
        $this->assertSame([], $service->listManageableAssets('manager@example.org', self::YEAR));
    }

    public function testAnArchivedAssetStaysManageableByItsManager(): void
    {
        // Archiving hides an asset; it does not seal off the history its
        // managers still have to settle.
        $hall = $this->createAsset('Local', 'local', archived: true);
        $this->managerRepository->grant($hall, 1, false);
        $service = $this->service(['manager@example.org' => [1]]);

        $this->assertTrue($service->canManageAssetId('manager@example.org', self::YEAR, $hall));
        $this->assertCount(1, $service->listManageableAssets('manager@example.org', self::YEAR));
    }

    public function testCanManageAssetAcceptsTheEntityAndTheIdInterchangeably(): void
    {
        $hall = $this->createAsset('Local', 'local');
        $this->managerRepository->grant($hall, 1, false);
        $service = $this->service(['manager@example.org' => [1]]);
        $asset = $this->assetRepository->findById($hall);

        $this->assertNotNull($asset);
        $this->assertTrue($service->canManageAsset('manager@example.org', self::YEAR, $asset));
        $this->assertSame(
            $service->canManageAssetId('manager@example.org', self::YEAR, $hall),
            $service->canManageAsset('manager@example.org', self::YEAR, $asset)
        );
    }

    public function testEveryMemberLinkedToOneEmailContributesTheirGrants(): void
    {
        // One address can be linked to several members; all their grants
        // count, and the assets are deduplicated.
        $a = $this->createAsset('A', 'a');
        $b = $this->createAsset('B', 'b');
        $this->managerRepository->grant($a, 1, false);
        $this->managerRepository->grant($b, 2, false);
        $this->managerRepository->grant($a, 2, false);
        $service = $this->service(['household@example.org' => [1, 2]]);

        $assets = $service->listManageableAssets('household@example.org', self::YEAR);

        $this->assertCount(2, $assets);
        $this->assertSame(['A', 'B'], array_map(fn($x) => $x->name, $assets));
    }

    public function testManagesAnyAssetIsFalseForUnitStaffOnAnEmptyInstallation(): void
    {
        // Staff d'U implicitly manages every asset — but an installation
        // with no asset must not sprout a menu entry to an empty page.
        $service = $this->service(['chief@example.org' => [5]], ['chief@example.org']);
        $this->assertFalse($service->managesAnyAsset('chief@example.org', self::YEAR));

        $this->createAsset('Local', 'local');
        $this->assertTrue($service->managesAnyAsset('chief@example.org', self::YEAR));
    }

    public function testManagesAnyAssetIsFalseForUnitStaffWhenEveryAssetIsArchived(): void
    {
        $this->createAsset('Local', 'local', archived: true);
        $service = $this->service(['chief@example.org' => [5]], ['chief@example.org']);

        $this->assertFalse($service->managesAnyAsset('chief@example.org', self::YEAR));
    }

    public function testAnUnknownEmailResolvesToNoMembersAndIsRefused(): void
    {
        $hall = $this->createAsset('Local', 'local');
        $this->managerRepository->grant($hall, 1, false);
        $service = $this->service(['manager@example.org' => [1]]);

        $this->assertFalse($service->canManageAssetId('stranger@example.org', self::YEAR, $hall));
    }

    public function testAnUnknownAssetIdIsRefusedRatherThanDefaultingOpen(): void
    {
        $this->createAsset('Local', 'local');
        $service = $this->service(['manager@example.org' => [1]]);

        $this->assertFalse($service->canManageAssetId('manager@example.org', self::YEAR, 999999));
    }
}
