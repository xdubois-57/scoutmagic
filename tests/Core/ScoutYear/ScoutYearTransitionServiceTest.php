<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearTransitionService;
use Core\ScoutYear\ScoutYearTransitionStepRepository;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Api\ScoutYearEventCountProvider;
use Modules\Registration\Api\ScoutYearPreparationProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The "Année scoute" workflow itself: which steps exist, in which phase,
 * what marks each one done, and what happens to the steps a module owns
 * when that module is off.
 *
 * The browser-level counterpart is tests/e2e/specs/scout-year-transition
 * .spec.js, which drives the same workflow through the real page. These
 * tests cover the shapes that scenario cannot reach cheaply — a site with
 * modules disabled, a signal that fires without anyone clicking, a manual
 * tick overriding a signal that never will.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearTransitionServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private ScoutYearService $scoutYearService;
    private ScoutYearResolver $resolver;
    private ScoutYearTransitionStepRepository $stepRepository;

    /** @var array{id: int, label: string, start_date: string, end_date: string} */
    private array $publicYear;
    /** @var array{id: int, label: string, start_date: string, end_date: string} */
    private array $targetYear;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id', null, '^[0-9]+$', null, false);
        $this->settingService->register(
            ScoutYearTransitionService::SETTING_DESK_ENCODING_DATE,
            '08-15',
            'text',
            'Bascule Desk',
            'Repère indicatif'
        );

        $this->resolver = new ScoutYearResolver($this->scoutYearService, $this->settingService, new MemberYearRepository($this->pdo));
        $this->stepRepository = new ScoutYearTransitionStepRepository($this->pdo);

        $publicId = $this->scoutYearService->ensureYear('2025-2026');
        $targetId = $this->scoutYearService->ensureYear('2026-2027');
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $publicId);

        /** @var array{id: int, label: string, start_date: string, end_date: string} $public */
        $public = $this->scoutYearService->findById($publicId);
        /** @var array{id: int, label: string, start_date: string, end_date: string} $target */
        $target = $this->scoutYearService->findById($targetId);
        $this->publicYear = $public;
        $this->targetYear = $target;
    }

    /**
     * @param array<int, string> $enabledModuleIds
     */
    private function service(
        array $enabledModuleIds = [],
        ?ScoutYearPreparationProvider $preparation = null,
        ?ScoutYearEventCountProvider $eventCount = null
    ): ScoutYearTransitionService {
        return new ScoutYearTransitionService(
            $this->resolver,
            $this->stepRepository,
            new SectionPhotoRepository($this->pdo),
            new UserAccountRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $this->settingService,
            $enabledModuleIds,
            $preparation,
            $eventCount
        );
    }

    /**
     * @param array<int, string> $enabledModuleIds
     * @return array{phases: array<int, array<string, mixed>>, current_step: int, step_count: int, desk_encoding_date: string}
     */
    private function build(
        array $enabledModuleIds = [],
        ?int $previewId = null,
        ?int $staffYearId = null,
        ?string $today = null,
        ?ScoutYearPreparationProvider $preparation = null,
        ?ScoutYearEventCountProvider $eventCount = null
    ): array {
        return $this->service($enabledModuleIds, $preparation, $eventCount)->buildWorkflow(
            $this->targetYear,
            $this->publicYear,
            $previewId,
            $staffYearId,
            (int) $this->publicYear['id'],
            new \DateTimeImmutable($today ?? '2026-03-01')
        );
    }

    /**
     * @param array{phases: array<int, array<string, mixed>>, current_step: int, step_count: int, desk_encoding_date: string} $workflow
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $workflow): array
    {
        $steps = [];
        foreach ($workflow['phases'] as $phase) {
            foreach ($phase['steps'] as $step) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * @param array{phases: array<int, array<string, mixed>>, current_step: int, step_count: int, desk_encoding_date: string} $workflow
     * @return array<string, mixed>
     */
    private function step(array $workflow, string $key): array
    {
        foreach ($this->flatten($workflow) as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        $this->fail("No step '{$key}' in the workflow.");
    }

    /**
     * @param array{phases: array<int, array<string, mixed>>, current_step: int, step_count: int, desk_encoding_date: string} $workflow
     * @return array<int, string>
     */
    private function keys(array $workflow): array
    {
        return array_map(static fn(array $s): string => (string) $s['key'], $this->flatten($workflow));
    }

    public function testEveryStepIsPresentWhenEveryModuleIsEnabled(): void
    {
        $workflow = $this->build(['registration', 'calendar', 'trombinoscope']);

        $this->assertSame([
            'departures',
            'passage',
            'confirm_registrations',
            'desk_staff',
            'desk_members',
            'fees',
            'preview',
            'import',
            'activate_staff',
            'ephemerides',
            'badges',
            'trombinoscope',
            'staff_photos',
            'activate_public',
        ], $this->keys($workflow));
        $this->assertSame(14, $workflow['step_count']);
    }

    public function testStepsAreNumberedContinuouslyInRenderedOrder(): void
    {
        $workflow = $this->build(['registration', 'calendar', 'trombinoscope']);

        $this->assertSame(range(1, 14), array_map(
            static fn(array $s): int => (int) $s['number'],
            $this->flatten($workflow)
        ));
    }

    public function testThreePhasesInOrder(): void
    {
        $workflow = $this->build(['registration', 'calendar', 'trombinoscope']);

        $this->assertSame(
            [ScoutYearTransitionService::PHASE_PREPARATION, ScoutYearTransitionService::PHASE_DESK, ScoutYearTransitionService::PHASE_SITE],
            array_map(static fn(array $p): string => (string) $p['key'], $workflow['phases'])
        );
    }

    /**
     * A module that is off takes its own steps with it, and the remaining
     * ones renumber — a chief never reads "étape 7 sur 14" on a site that
     * only has eleven steps.
     */
    public function testStepsOfDisabledModulesDisappearAndTheRestRenumber(): void
    {
        $workflow = $this->build([]);

        $keys = $this->keys($workflow);
        $this->assertNotContains('departures', $keys);
        $this->assertNotContains('passage', $keys);
        $this->assertNotContains('confirm_registrations', $keys);
        $this->assertNotContains('ephemerides', $keys);
        $this->assertNotContains('trombinoscope', $keys);
        $this->assertSame(9, $workflow['step_count']);
        $this->assertSame(range(1, 9), array_map(
            static fn(array $s): int => (int) $s['number'],
            $this->flatten($workflow)
        ));
    }

    /**
     * With registration off, the preparation phase has nothing left in it
     * at all — its heading goes too rather than standing over an empty list.
     */
    public function testAnEmptyPhaseIsDroppedEntirely(): void
    {
        $workflow = $this->build(['calendar', 'trombinoscope']);

        $this->assertSame(
            [ScoutYearTransitionService::PHASE_DESK, ScoutYearTransitionService::PHASE_SITE],
            array_map(static fn(array $p): string => (string) $p['key'], $workflow['phases'])
        );
    }

    public function testPreviewStepIsDoneOnlyForTheTargetYear(): void
    {
        $this->assertFalse($this->step($this->build(), 'preview')['done']);
        $this->assertFalse($this->step($this->build([], (int) $this->publicYear['id']), 'preview')['done']);
        $this->assertTrue($this->step($this->build([], (int) $this->targetYear['id']), 'preview')['done']);
    }

    public function testActivateStaffStepIsDoneOnlyWhenTheStaffYearIsTheTarget(): void
    {
        $this->assertFalse($this->step($this->build(), 'activate_staff')['done']);
        $this->assertTrue(
            $this->step($this->build([], null, (int) $this->targetYear['id']), 'activate_staff')['done']
        );
    }

    /**
     * Nothing imported yet is not a blocker any more (the order is advice),
     * but it is still worth saying out loud before a chief shows the staff
     * an empty year.
     */
    public function testActivateStaffWarnsWhileTheTargetYearHasNoMembers(): void
    {
        $this->assertNotNull($this->step($this->build(), 'activate_staff')['warning']);
    }

    public function testActivatePublicStepIsDoneOnceThePublicYearIsTheTarget(): void
    {
        $workflow = $this->service()->buildWorkflow(
            $this->targetYear,
            $this->targetYear,
            null,
            null,
            (int) $this->targetYear['id'],
            new \DateTimeImmutable('2026-09-15')
        );

        $this->assertTrue($this->step($workflow, 'activate_public')['done']);
    }

    /**
     * The highlighted step is "the first one still to do", so once there is
     * none there is nothing to highlight either — the workflow is over.
     */
    public function testNoStepIsCurrentOnceEveryStepIsDone(): void
    {
        $target = (int) $this->targetYear['id'];
        // import's own signal — members actually present for the target year.
        $this->givenTargetYearHasSectionsWithStaff([1]);
        foreach (['desk_staff', 'desk_members', 'fees', 'badges', 'staff_photos'] as $key) {
            $this->stepRepository->mark($target, $key, null);
        }

        $workflow = $this->service()->buildWorkflow(
            $this->targetYear,
            $this->targetYear,
            $target,
            $target,
            $target,
            new \DateTimeImmutable('2026-09-15')
        );

        $this->assertSame(
            [],
            array_values(array_filter($this->keys($workflow), fn(string $k): bool => !$this->step($workflow, $k)['done'])),
            'every step should be done in this fixture'
        );
        $this->assertSame(0, $workflow['current_step']);
    }

    public function testCurrentStepIsTheFirstUndoneOne(): void
    {
        $workflow = $this->build(['registration', 'calendar', 'trombinoscope']);
        $this->assertSame(1, $workflow['current_step']);

        $this->stepRepository->mark((int) $this->targetYear['id'], 'departures', null);
        $workflow = $this->build(['registration', 'calendar', 'trombinoscope']);
        $this->assertSame(2, $workflow['current_step']);
    }

    public function testManualStepIsDoneOnceMarkedAndNotAfterUnmarking(): void
    {
        $this->assertFalse($this->step($this->build(), 'fees')['done']);

        $this->stepRepository->mark((int) $this->targetYear['id'], 'fees', null);
        $step = $this->step($this->build(), 'fees');
        $this->assertTrue($step['done']);
        $this->assertTrue($step['manual_done']);
        $this->assertNotNull($step['done_at']);

        $this->stepRepository->unmark((int) $this->targetYear['id'], 'fees');
        $this->assertFalse($this->step($this->build(), 'fees')['done']);
    }

    /**
     * Marks belong to the transition being prepared, so next year's run
     * starts blank on its own — no reset step, nothing to clear by hand.
     */
    public function testMarksAreScopedToTheTargetYear(): void
    {
        $this->stepRepository->mark((int) $this->publicYear['id'], 'fees', null);

        $this->assertFalse($this->step($this->build(), 'fees')['done']);
    }

    public function testOnlyManualStepsCanBeTicked(): void
    {
        $service = $this->service(['registration', 'calendar', 'trombinoscope']);

        $this->assertTrue($service->isManualStep('fees'));
        $this->assertTrue($service->isManualStep('departures'));
        $this->assertFalse($service->isManualStep('activate_public'));
        $this->assertFalse($service->isManualStep('import'));
        $this->assertFalse($service->isManualStep('nonsense'));
    }

    /**
     * A step whose module is off is not merely hidden: its key is refused,
     * so a hand-crafted POST cannot leave a mark for a step nobody can see.
     */
    public function testAManualStepOfADisabledModuleIsRefused(): void
    {
        $service = $this->service([]);

        $this->assertFalse($service->isManualStep('departures'));
        $this->assertFalse($service->setManualStep((int) $this->targetYear['id'], 'departures', true, null));
        $this->assertSame([], $this->stepRepository->findByYear((int) $this->targetYear['id']));
    }

    public function testSetManualStepRefusesAnAutoDerivedStep(): void
    {
        $service = $this->service();

        $this->assertFalse($service->setManualStep((int) $this->targetYear['id'], 'activate_public', true, null));
        $this->assertSame([], $this->stepRepository->findByYear((int) $this->targetYear['id']));
    }

    public function testPassageStepFollowsTheModulesOwnCount(): void
    {
        $workflow = $this->build(['registration'], null, null, null, $this->preparation(12, 3));
        $step = $this->step($workflow, 'passage');

        $this->assertFalse($step['done']);
        $this->assertStringContainsString('3 passage(s) sur 12', (string) $step['progress']);

        $workflow = $this->build(['registration'], null, null, null, $this->preparation(12, 0));
        $step = $this->step($workflow, 'passage');

        $this->assertTrue($step['done']);
        $this->assertStringContainsString('tous avec une section', (string) $step['progress']);
    }

    /**
     * A unit where nobody changes branch has nothing to decide — that is a
     * legitimately finished step, not a stuck one.
     */
    public function testPassageStepIsDoneWhenNobodyChangesBranch(): void
    {
        $step = $this->step($this->build(['registration'], null, null, null, $this->preparation(0, 0)), 'passage');

        $this->assertTrue($step['done']);
        $this->assertStringContainsString('Aucun animé', (string) $step['progress']);
    }

    /**
     * A signal that will never fire for a given unit (a calendar kept
     * elsewhere) must not leave its step permanently unfinishable.
     */
    public function testAManualTickOverridesASignalThatDoesNotFire(): void
    {
        $this->assertFalse($this->step($this->build(['calendar'], null, null, null, null, $this->events(0)), 'ephemerides')['done']);

        $this->stepRepository->mark((int) $this->targetYear['id'], 'ephemerides', null);

        $this->assertTrue($this->step($this->build(['calendar'], null, null, null, null, $this->events(0)), 'ephemerides')['done']);
    }

    public function testEphemeridesStepIsDoneOnceTheYearHasEvents(): void
    {
        $step = $this->step($this->build(['calendar'], null, null, null, null, $this->events(7)), 'ephemerides');

        $this->assertTrue($step['done']);
        $this->assertStringContainsString('7 événement(s)', (string) $step['progress']);
    }

    /**
     * The count is asked over the target year's own calendar range, never
     * the public year's — the whole point is to look ahead.
     */
    public function testEphemeridesCountIsAskedOverTheTargetYearRange(): void
    {
        $provider = $this->createMock(ScoutYearEventCountProvider::class);
        $provider->expects($this->once())
            ->method('countEventsBetween')
            ->with($this->targetYear['start_date'], $this->targetYear['end_date'])
            ->willReturn(2);

        $this->build(['calendar'], null, null, null, null, $provider);
    }

    /**
     * Before the import the target year has no sections at all, so
     * "0 of 0 photographed" is not an achievement and must not read as one.
     */
    public function testStaffPhotosStepHasNoSignalWhileTheYearHasNoSections(): void
    {
        $step = $this->step($this->build(), 'staff_photos');

        $this->assertNull($step['auto_done']);
        $this->assertFalse($step['done']);
        $this->assertNull($step['progress']);
    }

    public function testStaffPhotosStepIsDoneOnceEverySectionHasThisYearsPhoto(): void
    {
        $this->givenTargetYearHasSectionsWithStaff([1, 2]);
        $this->givenSectionPhoto(1, (int) $this->targetYear['id']);

        $step = $this->step($this->build(), 'staff_photos');
        $this->assertFalse($step['done']);
        $this->assertSame('1/2 section(s) photographiée(s)', $step['progress']);

        $this->givenSectionPhoto(2, (int) $this->targetYear['id']);

        $step = $this->step($this->build(), 'staff_photos');
        $this->assertTrue($step['done']);
        $this->assertSame('2/2 section(s) photographiée(s)', $step['progress']);
    }

    /**
     * Last year's photo is what the Staffs page falls back to so a section
     * is never shown photo-less; it is emphatically not this year's photo
     * having been taken.
     */
    public function testLastYearsPhotoDoesNotCompleteThisYearsStep(): void
    {
        $this->givenTargetYearHasSectionsWithStaff([1]);
        $this->givenSectionPhoto(1, (int) $this->publicYear['id']);

        $this->assertFalse($this->step($this->build(), 'staff_photos')['done']);
    }

    public function testDeskEncodingDateIsTheConfiguredDayInTheTargetYearsStartYear(): void
    {
        $this->assertSame('2026-08-15', $this->build()['desk_encoding_date']);

        $this->settingService->setInternal(ScoutYearTransitionService::SETTING_DESK_ENCODING_DATE, '07-01');
        $this->assertSame('2026-07-01', $this->build()['desk_encoding_date']);
    }

    /**
     * A signpost that cannot be read falls back to the default rather than
     * taking the page down with it.
     */
    public function testAMalformedDeskEncodingDateFallsBackToTheDefault(): void
    {
        $this->settingService->setInternal(ScoutYearTransitionService::SETTING_DESK_ENCODING_DATE, 'n\'importe quoi');

        $this->assertSame('2026-08-15', $this->build()['desk_encoding_date']);
    }

    /**
     * The date decides which phase is labelled "en cours" and nothing else:
     * every step's done-state is identical on either side of it.
     */
    public function testTheDateOnlyMovesTheCurrentPhaseLabel(): void
    {
        $before = $this->build(['registration', 'calendar', 'trombinoscope'], null, null, '2026-06-01');
        $after = $this->build(['registration', 'calendar', 'trombinoscope'], null, null, '2026-09-01');

        $this->assertTrue($before['phases'][0]['current']);
        $this->assertFalse($before['phases'][1]['current']);
        $this->assertFalse($after['phases'][0]['current']);
        $this->assertTrue($after['phases'][1]['current']);

        $doneBefore = array_map(static fn(array $s): bool => (bool) $s['done'], $this->flatten($before));
        $doneAfter = array_map(static fn(array $s): bool => (bool) $s['done'], $this->flatten($after));
        $this->assertSame($doneBefore, $doneAfter);
        $this->assertSame($before['current_step'], $after['current_step']);
    }

    private function preparation(int $total, int $unassigned): ScoutYearPreparationProvider
    {
        $provider = $this->createMock(ScoutYearPreparationProvider::class);
        $provider->method('countPassages')->willReturn($total);
        $provider->method('countUnassignedPassages')->willReturn($unassigned);

        return $provider;
    }

    private function events(int $count): ScoutYearEventCountProvider
    {
        $provider = $this->createMock(ScoutYearEventCountProvider::class);
        $provider->method('countEventsBetween')->willReturn($count);

        return $provider;
    }

    /**
     * ScoutYearResolver::countSections() counts the sections that have an
     * active staffed member function that year — the same shape a real
     * Desk import produces.
     *
     * @param array<int, int> $sectionIds
     */
    private function givenTargetYearHasSectionsWithStaff(array $sectionIds): void
    {
        $this->pdo->exec("INSERT OR IGNORE INTO age_branches (id, desk_code, label) VALUES (1, 'BR', 'Branche')");

        foreach ($sectionIds as $sectionId) {
            $this->pdo->exec(
                "INSERT INTO sections (id, age_branch_id, desk_code) VALUES ({$sectionId}, 1, 'S{$sectionId}')"
            );

            $this->pdo->exec("INSERT INTO members (id, desk_id) VALUES ({$sectionId}, 'M{$sectionId}')");
            $this->pdo->exec(
                "INSERT INTO member_years (id, member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active) "
                . "VALUES ({$sectionId}, {$sectionId}, " . (int) $this->targetYear['id'] . ", 'x', 'y', 1)"
            );
            $this->pdo->exec(
                'INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES ('
                . $sectionId . ', 1, ' . $sectionId . ')'
            );
        }
    }

    private function givenSectionPhoto(int $sectionId, int $scoutYearId): void
    {
        $fileId = $sectionId * 10 + $scoutYearId;
        $this->pdo->exec(
            "INSERT INTO files (id, relative_path, original_name, mime_type, size_bytes) "
            . "VALUES ({$fileId}, 'p{$fileId}', 'p.jpg', 'image/jpeg', 1)"
        );
        (new SectionPhotoRepository($this->pdo))->upsert($sectionId, $scoutYearId, $fileId, null);
    }
}
