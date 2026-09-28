<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\Repository\StaffedSectionRepository;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Modules\Covoiturage\Service\CarpoolViewerResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * **`CarpoolViewer::staffedSectionIds` means « the sections this account
 * staffs », not « the sections it may act on ».**
 *
 * The two differ for a chef d'unité, and the difference is a correctness
 * one: `SectionStaffAuthorizationService::getStaffedSections()` short-
 * circuits to EVERY section for an admin — right for a picker, and vacuous
 * for anybody asking whether a real staffing relationship exists.
 *
 * Raised in review of #664. `CarpoolService::creatorSectionId()` checks the
 * section it is about to freeze onto a carpool against this list; read from
 * the widened one, that check did nothing at all for a chef d'unité, and a
 * section named only by Desk's role-blind « Fonction principale » flag
 * would have been frozen on, handing its staff the passengers of children
 * they do not follow. Nothing is lost for an admin: `seesEverything()`
 * already grants them every carpool, which is where that rule belongs.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CarpoolViewerResolverTest extends TestCase
{
    private \PDO $pdo;
    private CarpoolViewerResolver $resolver;
    private int $staffedSectionId;
    private int $otherSectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)')
            ->execute(['LOU01', $branchId, 'Louveteaux']);
        $this->staffedSectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)')
            ->execute(['BAL01', $branchId, 'Baladins']);
        $this->otherSectionId = (int) $this->pdo->lastInsertId();

        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->resolver = new CarpoolViewerResolver(
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                $settings,
                new MemberYearRepository($this->pdo)
            ),
            new SectionStaffAuthorizationService(
                new StaffedSectionRepository(Connection::withPdo($this->pdo), H::encryption()),
                H::sections($this->pdo)
            )
        );
    }

    /**
     * A chef d'unité staffing ONE section gets that one, not both — the
     * whole point. Remove the switch to `ownStaffedSectionIds()` and this
     * comes back with every section of the site.
     */
    public function testAnAdminGetsOnlyTheSectionsTheyActuallyStaff(): void
    {
        H::linkAccountToSection($this->pdo, 1, $this->staffedSectionId, $this->currentYearId());

        $viewer = $this->resolver->resolve(1, H::emailOf(1), Role::ADMIN->value);

        $this->assertSame(
            [$this->staffedSectionId],
            $viewer->staffedSectionIds,
            'the widened « may act on » list came back instead of the sections actually staffed'
        );
        $this->assertNotContains(
            $this->otherSectionId,
            $viewer->staffedSectionIds,
            'a section this account has no function in was counted as staffed'
        );
    }

    /**
     * And nothing is taken away from them: an admin still sees every
     * carpool, through `seesEverything()` rather than through a padded
     * list of sections.
     */
    public function testAnAdminStillSeesEveryCarpool(): void
    {
        H::linkAccountToSection($this->pdo, 1, $this->staffedSectionId, $this->currentYearId());
        $carpoolId = H::carpool($this->pdo, 10, null, [], $this->otherSectionId);
        $carpool = (new \Modules\Covoiturage\Repository\CarpoolRepository($this->pdo))->findById($carpoolId);
        $this->assertNotNull($carpool);

        $viewer = $this->resolver->resolve(1, H::emailOf(1), Role::ADMIN->value);

        $this->assertFalse(
            $viewer->isStaffOf($carpool),
            'the admin counts as staff of a section they do not staff'
        );
        $this->assertTrue(
            $viewer->seesPassengersOf($carpool),
            'an admin lost sight of a carpool outside their own section'
        );
    }

    /**
     * The same defect the other way round: `getAllWithBranches()` leaves out
     * a hidden section, so through the widened list an admin staffing one
     * staffed NOTHING — and `creatorSectionId()` then froze no section onto
     * their carpool, the passengers invisible to the very team that drives
     * them. A section is hidden from the pickers; it still has staff.
     */
    public function testAHiddenSectionIsStillStaffed(): void
    {
        $this->pdo->prepare('UPDATE sections SET is_visible = 0 WHERE id = ?')
            ->execute([$this->staffedSectionId]);
        H::linkAccountToSection($this->pdo, 1, $this->staffedSectionId, $this->currentYearId());

        $viewer = $this->resolver->resolve(1, H::emailOf(1), Role::ADMIN->value);

        $this->assertSame(
            [$this->staffedSectionId],
            $viewer->staffedSectionIds,
            'hiding a section from the pickers unstaffed it'
        );
    }

    /** A plain chief is unchanged: the list was already role-filtered. */
    public function testAChiefGetsTheirOwnSections(): void
    {
        H::linkAccountToSection($this->pdo, 2, $this->staffedSectionId, $this->currentYearId());

        $viewer = $this->resolver->resolve(2, H::emailOf(2), Role::CHIEF->value);

        $this->assertSame([$this->staffedSectionId], $viewer->staffedSectionIds);
    }

    /**
     * The year the resolver will ask for — the fixture must hang its member
     * on that one, or the lookup finds nothing and every assertion above
     * passes on an empty list for the wrong reason.
     */
    private function currentYearId(): int
    {
        return $this->resolver->resolve(99, H::emailOf(99), Role::IDENTIFIED->value)->scoutYearId;
    }
}
