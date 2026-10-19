<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\ImportJournalRepository;
use Core\ScoutYear\ActivationBlocker;
use Core\ScoutYear\PublicYearActivationBlockedException;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\StaffYearActivationBlockedException;
use Modules\Registration\Api\ScoutYearTransitionVetoProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The only write path to the public and staff year settings, and so the
 * place where the workflow's hard blocks hold whoever calls it (chantier
 * specifications.md §16.3): no staff year without a successful import of it; no switch
 * for everyone without the staff year on it, without a successful import
 * of it, or against the registration veto.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearAdminServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private ImportJournalRepository $importJournal;
    private ScoutYearAdminService $adminService;
    private int $year;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id', null, '^[0-9]+$', null, false);
        $this->importJournal = new ImportJournalRepository($this->pdo);
        $this->adminService = new ScoutYearAdminService($this->settingService, $this->importJournal);
        $this->year = (new ScoutYearService($this->pdo))->ensureYear('2026-2027');
    }

    private function withVeto(int $blockingCount): ScoutYearAdminService
    {
        $veto = $this->createStub(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn($blockingCount);

        return new ScoutYearAdminService($this->settingService, $this->importJournal, $veto);
    }

    private function givenSuccessfulImport(int $yearId): void
    {
        $this->importJournal->create($yearId, 1, 10, 9, 0);
    }

    private function givenReadyForTheSwitch(): void
    {
        $this->givenSuccessfulImport($this->year);
        $this->adminService->activateStaffYear($this->year);
    }

    public function testActivateStaffYearSetsSettingOnceTheYearIsImported(): void
    {
        $this->givenSuccessfulImport($this->year);

        $this->adminService->activateStaffYear($this->year);

        $this->assertSame((string) $this->year, $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    /**
     * Hard block 2: the staff would be served an empty year.
     */
    public function testActivateStaffYearIsRefusedWithoutASuccessfulImport(): void
    {
        try {
            $this->adminService->activateStaffYear($this->year);
            $this->fail('Expected StaffYearActivationBlockedException');
        } catch (StaffYearActivationBlockedException $e) {
            $this->assertSame([ActivationBlocker::NO_SUCCESSFUL_IMPORT], $e->getBlockerCodes());
            $this->assertSame($this->year, $e->getYearId());
        }

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    /** Another year's import says nothing about this one. */
    public function testAnImportOfAnotherYearDoesNotUnblockTheStaffYear(): void
    {
        $other = (new ScoutYearService($this->pdo))->ensureYear('2025-2026');
        $this->givenSuccessfulImport($other);

        $this->assertNotSame([], $this->adminService->staffActivationBlockers($this->year));
        $this->assertSame([], $this->adminService->staffActivationBlockers($other));
    }

    /** Open registrations only remind at this step, they never block it. */
    public function testTheRegistrationVetoDoesNotBlockTheStaffYear(): void
    {
        $this->givenSuccessfulImport($this->year);

        $this->withVeto(4)->activateStaffYear($this->year);

        $this->assertSame((string) $this->year, $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testDeactivateStaffYearClearsSetting(): void
    {
        $this->givenReadyForTheSwitch();
        $this->adminService->deactivateStaffYear();

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testActivatePublicYearSetsCurrentAndClearsStaff(): void
    {
        $this->givenReadyForTheSwitch();

        $this->adminService->activatePublicYear($this->year);

        $this->assertSame((string) $this->year, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    /**
     * Every reason at once, in the order the workflow resolves them —
     * the page lists them all, so the exception must carry them all.
     */
    public function testActivatePublicYearReportsEveryBlockerAtOnce(): void
    {
        try {
            $this->withVeto(2)->activatePublicYear($this->year);
            $this->fail('Expected PublicYearActivationBlockedException');
        } catch (PublicYearActivationBlockedException $e) {
            $this->assertSame([
                ActivationBlocker::STAFF_YEAR_NOT_TARGET,
                ActivationBlocker::NO_SUCCESSFUL_IMPORT,
                ActivationBlocker::REGISTRATION_VETO,
            ], $e->getBlockerCodes());
            $this->assertSame(2, $e->getBlockingRequestCount());
        }

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testActivatePublicYearIsRefusedWhileTheStaffYearIsAnotherYear(): void
    {
        $this->givenSuccessfulImport($this->year);
        $other = (new ScoutYearService($this->pdo))->ensureYear('2025-2026');
        $this->givenSuccessfulImport($other);
        $this->adminService->activateStaffYear($other);

        $this->assertSame(
            [ActivationBlocker::STAFF_YEAR_NOT_TARGET],
            array_map(static fn(ActivationBlocker $b): string => $b->code, $this->adminService->publicActivationBlockers($this->year))
        );
        $this->expectException(PublicYearActivationBlockedException::class);
        $this->adminService->activatePublicYear($this->year);
    }

    /**
     * The staff year can be on the target without an import only through a
     * setting written by hand or restored from a backup — the switch must
     * still refuse.
     */
    public function testActivatePublicYearIsRefusedWithoutASuccessfulImport(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->year);

        $this->assertSame(
            [ActivationBlocker::NO_SUCCESSFUL_IMPORT],
            array_map(static fn(ActivationBlocker $b): string => $b->code, $this->adminService->publicActivationBlockers($this->year))
        );
        $this->expectException(PublicYearActivationBlockedException::class);
        $this->adminService->activatePublicYear($this->year);
    }

    /**
     * §8.38 — the veto, refused server-side by the service that executes
     * the transition, so a direct call (a replayed request) is refused too.
     */
    public function testActivatePublicYearIsRefusedByTheRegistrationVeto(): void
    {
        $this->givenReadyForTheSwitch();
        $adminService = $this->withVeto(5);

        try {
            $adminService->activatePublicYear($this->year);
            $this->fail('Expected PublicYearActivationBlockedException');
        } catch (PublicYearActivationBlockedException $e) {
            $this->assertSame([ActivationBlocker::REGISTRATION_VETO], $e->getBlockerCodes());
            $this->assertSame(5, $e->getBlockingRequestCount());
        }

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertSame((string) $this->year, $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testActivatePublicYearSucceedsWhenTheVetoReportsZero(): void
    {
        $this->givenReadyForTheSwitch();

        $this->withVeto(0)->activatePublicYear($this->year);

        $this->assertSame((string) $this->year, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    /**
     * §8.38 — no veto provider wired (registration module absent/disabled):
     * core never knows the module exists.
     */
    public function testCountBlockingRegistrationRequestsIsZeroWithoutProvider(): void
    {
        $this->assertSame(0, $this->adminService->countBlockingRegistrationRequests());
    }

    public function testCountBlockingRegistrationRequestsDelegatesToProvider(): void
    {
        $this->assertSame(3, $this->withVeto(3)->countBlockingRegistrationRequests());
    }

    public function testBlockersSayWhatToDoInFrench(): void
    {
        $import = new ActivationBlocker(ActivationBlocker::NO_SUCCESSFUL_IMPORT);
        $veto = new ActivationBlocker(ActivationBlocker::REGISTRATION_VETO, 7);
        $staff = new ActivationBlocker(ActivationBlocker::STAFF_YEAR_NOT_TARGET);

        $this->assertSame('Aucun import Desk réussi pour 2026-2027.', $import->message('2026-2027'));
        $this->assertSame('/admin/import?annee=12', $import->resolutionUrl(12));
        $this->assertStringStartsWith('7 demande(s)', $veto->message('2026-2027'));
        $this->assertSame('/config/inscriptions/demandes', $veto->resolutionUrl(12));
        $this->assertNull($staff->resolutionUrl(12));
        $this->assertSame("2026-2027 n'est pas activée pour les staffs.", $staff->message('2026-2027'));
    }
}
