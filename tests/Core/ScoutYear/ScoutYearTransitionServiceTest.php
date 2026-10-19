<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\ImportJournalRepository;
use Core\Journal\JournalRepository;
use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearTransitionService;
use Core\ScoutYear\ScoutYearTransitionStepRepository;
use Core\ScoutYear\Step\Check\DeparturesStepCheck;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Core\ScoutYear\Step\StepMarkResult;
use Core\ScoutYear\Step\StepNature;
use Core\ScoutYear\Step\StepState;
use Core\ScoutYear\Step\TransitionStep;
use Core\ScoutYear\Step\TransitionWorkflow;
use Modules\Calendar\Api\ScoutYearEventCountProvider;
use Modules\Registration\Api\ScoutYearPreparationProvider;
use Modules\Registration\Api\ScoutYearTransitionVetoProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The « Année scoute » engine: which steps exist, in which order, phase
 * and nature, what makes each one done, and the server's refusal to mark
 * an observed step or a step whose check refuses it (chantier #930 lot B).
 *
 * The browser-level counterpart is tests/e2e/specs/scout-year-transition
 * .spec.js, which drives the same workflow through the real page. These
 * tests cover the shapes that scenario cannot reach cheaply — a site with
 * modules disabled, a check that finds something, a refusal to mark.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearTransitionServiceTest extends TestCase
{
    private const ALL_MODULES = ['registration', 'fees', 'calendar', 'trombinoscope'];

    private \PDO $pdo;
    private SettingService $settingService;
    private ScoutYearService $scoutYearService;
    private ScoutYearTransitionStepRepository $stepRepository;
    private ImportJournalRepository $importJournal;

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

        $this->stepRepository = new ScoutYearTransitionStepRepository($this->pdo);
        $this->importJournal = new ImportJournalRepository($this->pdo);

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
     * @param list<StepCheckProvider> $overrides
     */
    private function service(
        array $enabledModuleIds = [],
        ?ScoutYearPreparationProvider $preparation = null,
        ?ScoutYearEventCountProvider $eventCount = null,
        array $overrides = [],
        int $blockingRequests = 0
    ): ScoutYearTransitionService {
        $veto = $this->createStub(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn($blockingRequests);

        return TransitionServiceFactory::create(
            $this->pdo,
            $this->settingService,
            new ScoutYearAdminService($this->settingService, $this->importJournal, $veto),
            $enabledModuleIds,
            $preparation,
            $eventCount,
            $overrides
        );
    }

    private function context(
        ScoutYearTransitionService $service,
        ?int $previewId = null,
        ?int $staffYearId = null,
        ?string $today = null
    ): StepContext {
        return $service->contextFor(
            $this->targetYear,
            $this->publicYear,
            $previewId,
            $staffYearId,
            new \DateTimeImmutable($today ?? '2026-03-01')
        );
    }

    /**
     * @param array<int, string> $enabledModuleIds
     */
    private function build(
        array $enabledModuleIds = [],
        ?int $previewId = null,
        ?int $staffYearId = null,
        ?string $today = null,
        ?ScoutYearPreparationProvider $preparation = null,
        ?ScoutYearEventCountProvider $eventCount = null
    ): TransitionWorkflow {
        $service = $this->service($enabledModuleIds, $preparation, $eventCount);

        return $service->buildWorkflow($this->context($service, $previewId, $staffYearId, $today));
    }

    private function step(TransitionWorkflow $workflow, string $key): TransitionStep
    {
        return $workflow->step($key) ?? $this->fail("No step '{$key}' in the workflow.");
    }

    /**
     * @return array<int, string>
     */
    private function keys(TransitionWorkflow $workflow): array
    {
        return array_map(static fn(TransitionStep $s): string => $s->key, $workflow->steps);
    }

    private function givenTargetImport(?int $userId = null, int $memberCount = 42): int
    {
        return $this->importJournal->create((int) $this->targetYear['id'], $userId ?? 0, 50, $memberCount, 0);
    }

    // ---------------------------------------------------------------
    // Catalogue: order, phases, natures, modules
    // ---------------------------------------------------------------

    public function testEveryStepIsPresentInTheAdvisedOrderWhenEveryModuleIsEnabled(): void
    {
        $workflow = $this->build(self::ALL_MODULES);

        $this->assertSame([
            'departures',
            'passage',
            'confirm_registrations',
            'desk_staff',
            'desk_members',
            'import',
            'fees',
            'preview',
            'activate_staff',
            'ephemerides',
            'badges',
            'trombinoscope',
            'staff_photos',
            'activate_public',
        ], $this->keys($workflow));
        $this->assertSame(14, $workflow->stepCount());
        $this->assertSame(range(1, 14), array_map(static fn(TransitionStep $s): int => $s->number, $workflow->steps));
    }

    public function testThreePhasesInOrderWithTheirSteps(): void
    {
        $workflow = $this->build(self::ALL_MODULES);

        $this->assertSame(
            [ScoutYearTransitionService::PHASE_PREPARATION, ScoutYearTransitionService::PHASE_DESK, ScoutYearTransitionService::PHASE_SITE],
            array_map(static fn($p): string => $p->key, $workflow->phases)
        );
        $this->assertSame(
            ['desk_staff', 'desk_members', 'import', 'fees', 'preview'],
            array_map(static fn(TransitionStep $s): string => $s->key, $workflow->phases[1]->steps)
        );
    }

    /**
     * Three steps are the site's to observe; every other one, including
     * preview, passage, éphémérides and staff photos, is a human decision.
     */
    public function testOnlyImportAndTheTwoActivationsAreObserved(): void
    {
        $workflow = $this->build(self::ALL_MODULES);

        $observed = array_values(array_map(
            static fn(TransitionStep $s): string => $s->key,
            array_filter($workflow->steps, static fn(TransitionStep $s): bool => $s->nature === StepNature::OBSERVED)
        ));
        $this->assertSame(['import', 'activate_staff', 'activate_public'], $observed);
        $this->assertSame('Décision humaine', $this->step($workflow, 'preview')->nature->label());
    }

    /**
     * A module that is off takes its own steps with it, and the remaining
     * ones renumber — a chief never reads "étape 7 sur 14" on a site that
     * only has eight steps.
     */
    public function testStepsOfDisabledModulesDisappearAndTheRestRenumber(): void
    {
        $workflow = $this->build([]);

        $this->assertSame([
            'desk_staff',
            'desk_members',
            'import',
            'preview',
            'activate_staff',
            'badges',
            'staff_photos',
            'activate_public',
        ], $this->keys($workflow));
        $this->assertSame(range(1, 8), array_map(static fn(TransitionStep $s): int => $s->number, $workflow->steps));
    }

    public function testTheFeesStepBelongsToTheFeesModule(): void
    {
        $this->assertNotContains('fees', $this->keys($this->build(['registration', 'calendar', 'trombinoscope'])));
        $this->assertContains('fees', $this->keys($this->build(['fees'])));
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
            array_map(static fn($p): string => $p->key, $workflow->phases)
        );
    }

    public function testEachStepNamesItsOwnDetailTemplate(): void
    {
        $this->assertSame(
            'admin/scout_year/steps/staff_photos.html.twig',
            $this->step($this->build(), 'staff_photos')->detailTemplate()
        );
    }

    // ---------------------------------------------------------------
    // Engine wiring
    // ---------------------------------------------------------------

    public function testAPresentStepWithoutItsCheckIsAWiringError(): void
    {
        $service = new ScoutYearTransitionService(
            $this->stepRepository,
            $this->importJournal,
            new JournalRepository($this->pdo),
            $this->scoutYearService,
            TransitionServiceFactory::users($this->pdo),
            $this->settingService,
            [new DeparturesStepCheck()],
            []
        );

        $this->expectException(\LogicException::class);
        $service->buildWorkflow($this->context($service));
    }

    public function testACheckForAnUnknownStepIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->service([], null, null, [$this->checkFor('nonsense', new StepCheck())]);
    }

    // ---------------------------------------------------------------
    // Observed steps
    // ---------------------------------------------------------------

    /**
     * Done from import_journal — a successful import of the TARGET year —
     * and never from « the year has members ».
     */
    public function testImportIsDoneOnceTheTargetYearHasASuccessfulImport(): void
    {
        $step = $this->step($this->build(), 'import');
        $this->assertSame(StepState::TODO, $step->state);
        $this->assertSame('/admin/import?annee=' . $this->targetYear['id'], $step->primaryAction?->url);

        $this->importJournal->create((int) $this->publicYear['id'], 0, 10, 10, 0);
        $this->assertFalse($this->step($this->build(), 'import')->isDone(), "the public year's import is not the target's");

        $user = TransitionServiceFactory::users($this->pdo)->create('chef@example.invalid');
        TransitionServiceFactory::users($this->pdo)->updateProfile($user->id, 'Ada', 'Lovelace');
        $importId = $this->givenTargetImport($user->id, 142);

        $step = $this->step($this->build(), 'import');
        $this->assertTrue($step->isDone());
        $this->assertSame(142, $step->details['member_count']);
        $this->assertSame('Ada Lovelace', $step->details['imported_by']);
        $this->assertSame("/admin/import/{$importId}/rapport", $step->primaryAction?->url);
        $this->assertSame(['import_report', 'import'], array_map(static fn(StepAction $a): string => $a->id, $step->actions));
    }

    /**
     * Hard block 2, as the page shows it: blocked, and the primary action
     * is the import that unblocks it.
     */
    public function testActivateStaffIsBlockedUntilTheTargetIsImported(): void
    {
        $step = $this->step($this->build(), 'activate_staff');

        $this->assertSame(StepState::BLOCKED, $step->state);
        $this->assertTrue($step->isBlocking());
        $this->assertStringContainsString('Aucun import Desk réussi pour 2026-2027', (string) $step->blockReason);
        $this->assertSame('Importer 2026-2027', $step->primaryAction?->label);
        $activate = $step->actions[1];
        $this->assertSame('activate_staff', $activate->id);
        $this->assertTrue($activate->disabled);

        $this->givenTargetImport();
        $step = $this->step($this->build(), 'activate_staff');

        $this->assertSame(StepState::TODO, $step->state);
        $this->assertNull($step->blockReason);
        $this->assertSame('Activer pour les staffs', $step->primaryAction?->label);
        $this->assertTrue($step->primaryAction->isPost());
        $this->assertSame(['scout_year_id' => (string) $this->targetYear['id']], $step->primaryAction->fields);
        $this->assertStringContainsString("Aucun e-mail n'est envoyé", (string) $step->primaryAction->confirmMessage);
    }

    public function testActivateStaffIsDoneWhileTheStaffYearIsTheTarget(): void
    {
        $this->givenTargetImport();
        $step = $this->step($this->build([], null, (int) $this->targetYear['id']), 'activate_staff');

        $this->assertTrue($step->isDone());
        $this->assertSame('deactivate_staff', $step->primaryAction?->id);
    }

    /**
     * Hard block 3 as the page shows it — the same list the server
     * refuses on (ScoutYearAdminService::publicActivationBlockers()).
     */
    public function testActivatePublicIsBlockedForEveryReasonTheServerRefuses(): void
    {
        $service = $this->service([], null, null, [], 3);
        $step = $this->step($service->buildWorkflow($this->context($service)), 'activate_public');

        $this->assertSame(StepState::BLOCKED, $step->state);
        $this->assertSame(
            "La bascule est refusée tant que 2026-2027 n'est pas activée pour les staffs, aucun import réussi de "
            . "2026-2027 n'existe, 3 demande(s) d'inscription restent ouvertes.",
            $step->blockReason
        );
        $this->assertCount(3, $step->details['blockers']);
        $this->assertTrue($step->primaryAction?->isReview());
        $this->assertTrue($step->actions[1]->disabled);

        $this->givenTargetImport();
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->targetYear['id']);
        $service = $this->service();
        $step = $this->step(
            $service->buildWorkflow($this->context($service, null, (int) $this->targetYear['id'])),
            'activate_public'
        );

        $this->assertSame(StepState::TODO, $step->state);
        $this->assertFalse($step->actions[1]->disabled);
    }

    public function testActivatePublicIsDoneOnceThePublicYearIsTheTarget(): void
    {
        $service = $this->service();
        $workflow = $service->buildWorkflow($service->contextFor(
            $this->targetYear,
            $this->targetYear,
            null,
            null,
            new \DateTimeImmutable('2026-09-15')
        ));

        $this->assertTrue($this->step($workflow, 'activate_public')->isDone());
    }

    // ---------------------------------------------------------------
    // Human steps: done only by a mark
    // ---------------------------------------------------------------

    /**
     * Previewing is not reviewing: the session preview is said, never the
     * step done.
     */
    public function testPreviewIsHumanAndTheSessionPreviewMarksNothing(): void
    {
        $step = $this->step($this->build([], (int) $this->targetYear['id']), 'preview');

        $this->assertFalse($step->isDone());
        $this->assertTrue($step->details['preview_active']);
        $this->assertSame('clear_preview', $step->primaryAction?->id);

        $step = $this->step($this->build([], (int) $this->publicYear['id']), 'preview');
        $this->assertFalse($step->details['preview_active']);
        $this->assertSame('preview', $step->primaryAction?->id);
        $this->assertSame('Prévisualiser 2026-2027', $step->primaryAction?->label);
    }

    public function testPassageFindingsArePointsToCheckNeverADoneStep(): void
    {
        $step = $this->step($this->build(['registration'], null, null, null, $this->preparation(12, 3)), 'passage');
        $this->assertFalse($step->isDone());
        $this->assertSame(["3 animés sans section d'arrivée"], $step->pointsToCheck);

        $step = $this->step($this->build(['registration'], null, null, null, $this->preparation(12, 0)), 'passage');
        $this->assertFalse($step->isDone());
        $this->assertSame([], $step->pointsToCheck);
        $this->assertSame(['passage_count' => 12, 'unassigned_count' => 0], $step->details);
    }

    public function testNoPassageAtAllIsSaidAsSuch(): void
    {
        $step = $this->step($this->build(['registration'], null, null, null, $this->preparation(0, 0)), 'passage');

        $this->assertFalse($step->isDone());
        $this->assertSame(0, $step->details['passage_count']);
    }

    public function testEphemeridesCountIsInformationNeverTheStepDone(): void
    {
        $provider = $this->createMock(ScoutYearEventCountProvider::class);
        $provider->expects($this->once())
            ->method('countEventsBetween')
            ->with($this->targetYear['start_date'], $this->targetYear['end_date'])
            ->willReturn(7);

        $step = $this->step($this->build(['calendar'], null, null, null, null, $provider), 'ephemerides');

        $this->assertFalse($step->isDone());
        $this->assertSame(7, $step->details['event_count']);
        $this->assertSame([], $step->pointsToCheck);
    }

    /**
     * Before the import the target year has nothing to count: no point,
     * no figure, the waiting message.
     */
    public function testStaffPhotosWaitsForTheImport(): void
    {
        $this->givenTargetYearHasSectionsWithStaff([1]);

        $step = $this->step($this->build(), 'staff_photos');

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $step->pendingMessage);
        $this->assertSame([], $step->pointsToCheck);
        $this->assertArrayNotHasKey('section_count', $step->details);
    }

    public function testStaffPhotosMissingAreAPointToCheckAndAllPresentIsNotDone(): void
    {
        $this->givenTargetImport();
        $this->givenTargetYearHasSectionsWithStaff([1, 2]);
        $this->givenSectionPhoto(1, (int) $this->targetYear['id']);
        // Last year's photo is what the Staffs page falls back to; it is
        // emphatically not this year's photo having been taken.
        $this->givenSectionPhoto(2, (int) $this->publicYear['id']);

        $step = $this->step($this->build(), 'staff_photos');
        $this->assertSame(['1 section sans photo de groupe de 2026-2027'], $step->pointsToCheck);
        $this->assertSame(['section_count' => 2, 'with_photo_count' => 1], $step->details);

        $this->givenSectionPhoto(2, (int) $this->targetYear['id']);
        $step = $this->step($this->build(), 'staff_photos');
        $this->assertSame([], $step->pointsToCheck);
        $this->assertFalse($step->isDone());
    }

    public function testAHumanStepIsDoneOnceMarkedEvenWithPointsToCheck(): void
    {
        $service = $this->service(['registration'], $this->preparation(5, 2));
        $context = $this->context($service);

        $this->assertSame(StepMarkResult::MARKED, $service->markStep($context, 'passage', true, null)->outcome);

        $step = $this->step($service->buildWorkflow($context), 'passage');
        $this->assertSame(StepState::DONE, $step->state);
        $this->assertCount(1, $step->pointsToCheck);
        $this->assertNotNull($step->mark);

        $this->assertSame(StepMarkResult::UNMARKED, $service->markStep($context, 'passage', false, null)->outcome);
        $this->assertFalse($this->step($service->buildWorkflow($context), 'passage')->isDone());
    }

    public function testTheMarkSaysWhoAndWhen(): void
    {
        $users = TransitionServiceFactory::users($this->pdo);
        $user = $users->create('chef@example.invalid');
        $users->updateProfile($user->id, 'Grace', 'Hopper');
        $service = $this->service();
        $context = $this->context($service);

        $service->markStep($context, 'badges', true, $user->id);

        $mark = $this->step($service->buildWorkflow($context), 'badges')->mark;
        $this->assertSame('Grace Hopper', $mark?->doneBy);
        $this->assertInstanceOf(\DateTimeImmutable::class, $mark->doneAt);
    }

    /**
     * Marks belong to the transition being prepared, so next year's run
     * starts blank on its own — no reset step, nothing to clear by hand.
     */
    public function testMarksAreScopedToTheTargetYear(): void
    {
        $this->stepRepository->mark((int) $this->publicYear['id'], 'badges', null);

        $this->assertFalse($this->step($this->build(), 'badges')->isDone());
    }

    /**
     * An observed step's key is refused, so a forged request can never
     * make the page claim the public year moved when it did not — and a
     * stray row for one, should it exist, is ignored.
     */
    public function testAnObservedStepCannotBeMarked(): void
    {
        $service = $this->service();
        $context = $this->context($service);

        foreach (['import', 'activate_staff', 'activate_public'] as $key) {
            $this->assertSame(StepMarkResult::NOT_MARKABLE, $service->markStep($context, $key, true, null)->outcome);
            $this->assertFalse($service->isManualStep($key));
        }
        $this->assertSame([], $this->stepRepository->findByYear((int) $this->targetYear['id']));

        $this->stepRepository->mark((int) $this->targetYear['id'], 'activate_public', null);
        $this->assertFalse($this->step($service->buildWorkflow($context), 'activate_public')->isDone());
    }

    public function testAStepOfADisabledModuleOrAnUnknownKeyCannotBeMarked(): void
    {
        $service = $this->service([]);
        $context = $this->context($service);

        $this->assertSame(StepMarkResult::NOT_MARKABLE, $service->markStep($context, 'departures', true, null)->outcome);
        $this->assertSame(StepMarkResult::NOT_MARKABLE, $service->markStep($context, 'nonsense', true, null)->outcome);
        $this->assertSame([], $this->stepRepository->findByYear((int) $this->targetYear['id']));
    }

    /**
     * The mechanism lot E's step-1 campaign rule rides on: a check's
     * refusal is asked again on the mark request itself, refuses the mark
     * with its reason, and never refuses unmarking.
     */
    public function testACheckThatRefusesTheMarkIsEnforcedOnTheMarkRequest(): void
    {
        $refusing = $this->checkFor('departures', new StepCheck(markRefusal: 'La campagne est en cours.'));
        $service = $this->service(['registration'], null, null, [$refusing]);
        $context = $this->context($service);

        $result = $service->markStep($context, 'departures', true, null);
        $this->assertSame(StepMarkResult::REFUSED, $result->outcome);
        $this->assertSame('La campagne est en cours.', $result->reason);
        $this->assertSame([], $this->stepRepository->findByYear((int) $this->targetYear['id']));

        $step = $this->step($service->buildWorkflow($context), 'departures');
        $this->assertSame(StepState::TODO, $step->state);
        $this->assertTrue($step->isBlocking());

        // A step marked before the refusal appeared stays marked (a human
        // decision is not rewritten), and may still be unmarked.
        $this->stepRepository->mark((int) $this->targetYear['id'], 'departures', null);
        $step = $this->step($service->buildWorkflow($context), 'departures');
        $this->assertTrue($step->isDone());
        $this->assertFalse($step->isBlocking());
        $this->assertSame(StepMarkResult::UNMARKED, $service->markStep($context, 'departures', false, null)->outcome);
    }

    /**
     * Each field of a check counts only for the nature it belongs to: a
     * human step is never done by its check, an observed one never gets a
     * refusal to mark.
     */
    public function testTheEngineIgnoresTheFieldsOfTheOtherNature(): void
    {
        $service = $this->service([], null, null, [
            $this->checkFor('badges', new StepCheck(observedDone: true, blockReason: 'non')),
            $this->checkFor('import', new StepCheck(observedDone: false, markRefusal: 'non')),
        ]);
        $workflow = $service->buildWorkflow($this->context($service));

        $badges = $this->step($workflow, 'badges');
        $this->assertSame(StepState::TODO, $badges->state);
        $this->assertNull($badges->blockReason);
        $this->assertNull($this->step($workflow, 'import')->markRefusal);
    }

    // ---------------------------------------------------------------
    // Summary
    // ---------------------------------------------------------------

    public function testCurrentStepIsTheFirstUndoneOne(): void
    {
        $this->assertSame(1, $this->build(self::ALL_MODULES)->currentStepNumber());

        $this->stepRepository->mark((int) $this->targetYear['id'], 'departures', null);
        $this->assertSame(2, $this->build(self::ALL_MODULES)->currentStepNumber());
    }

    /** The import step opens the import page on the target year — which selects it only if its policy allows it. */
    public function testTheImportStepLinksToTheImportOfTheTargetYear(): void
    {
        $this->assertSame(
            '/admin/import?annee=' . $this->targetYear['id'],
            $this->step($this->build(), 'import')->primaryAction?->url
        );
    }

    /**
     * The highlighted step is "the first one still to do", so once there is
     * none there is nothing to highlight either — the workflow is over.
     */
    public function testNoStepIsCurrentOnceEveryStepIsDone(): void
    {
        $target = (int) $this->targetYear['id'];
        $this->givenTargetImport();
        foreach (['desk_staff', 'desk_members', 'preview', 'badges', 'staff_photos'] as $key) {
            $this->stepRepository->mark($target, $key, null);
        }

        $service = $this->service();
        $workflow = $service->buildWorkflow($service->contextFor(
            $this->targetYear,
            $this->targetYear,
            null,
            $target,
            new \DateTimeImmutable('2026-09-15')
        ));

        $this->assertSame($workflow->stepCount(), $workflow->doneCount());
        $this->assertSame(0, $workflow->currentStepNumber());
    }

    public function testTheSummaryCountsDoneStepsPointsAndBlocks(): void
    {
        $this->stepRepository->mark((int) $this->targetYear['id'], 'passage', null);
        $workflow = $this->build(['registration'], null, null, null, $this->preparation(4, 2));

        $this->assertSame(1, $workflow->doneCount());
        $this->assertSame(1, $workflow->pointsToCheckCount());
        // activate_staff and activate_public, both waiting for the import.
        $this->assertSame(2, $workflow->blockingCount());
    }

    // ---------------------------------------------------------------
    // « Dernière bascule »
    // ---------------------------------------------------------------

    public function testNoLastSwitchWhileTheJournalHoldsNone(): void
    {
        $this->assertNull($this->build()->lastPublicSwitch);
    }

    public function testTheLastSwitchIsReadFromTheJournal(): void
    {
        $users = TransitionServiceFactory::users($this->pdo);
        $user = $users->create('chef@example.invalid');
        $users->updateProfile($user->id, 'Xavier', 'Dubois');
        $journal = new JournalRepository($this->pdo);

        $this->pdo->exec(
            "INSERT INTO event_log (logged_at, user_account_id, category, event_type, level, description, context) VALUES "
            . "('2025-08-20 10:00:00', NULL, 'core', 'scout_year_public_activated', 'security', 'old', '"
            . json_encode(['old_year_id' => null, 'new_year_id' => (int) $this->publicYear['id']]) . "')"
        );
        $journal->insert(
            'core',
            'scout_year_public_activated',
            'security',
            'new',
            (string) json_encode([
                'old_year_id' => 999,
                'new_year_id' => 998,
                'old_year_label' => '2024-2025',
                'new_year_label' => '2025-2026',
            ]),
            $user->id
        );
        $journal->insert('core', 'scout_year_staff_activated', 'security', 'later, other event', null, null);

        $switch = $this->build()->lastPublicSwitch;

        $this->assertNotNull($switch);
        $this->assertSame('2024-2025', $switch->fromLabel);
        $this->assertSame('2025-2026', $switch->toLabel);
        $this->assertSame('Xavier Dubois', $switch->switchedBy);
    }

    /** An entry written before the labels were logged names its years through their ids. */
    public function testALastSwitchWithoutLabelsFallsBackOnTheYearIds(): void
    {
        $this->pdo->exec(
            "INSERT INTO event_log (logged_at, user_account_id, category, event_type, level, description, context) VALUES "
            . "('2025-08-20 10:00:00', NULL, 'core', 'scout_year_public_activated', 'security', 'old', '"
            . json_encode(['old_year_id' => null, 'new_year_id' => (int) $this->publicYear['id']]) . "')"
        );

        $switch = $this->build()->lastPublicSwitch;

        $this->assertNotNull($switch);
        $this->assertNull($switch->fromLabel);
        $this->assertSame('2025-2026', $switch->toLabel);
        $this->assertSame('2025-08-20', $switch->switchedAt->format('Y-m-d'));
        $this->assertNull($switch->switchedBy);
    }

    // ---------------------------------------------------------------
    // The Desk-encoding date
    // ---------------------------------------------------------------

    public function testDeskEncodingDateIsTheConfiguredDayInTheTargetYearsStartYear(): void
    {
        $this->assertSame('2026-08-15', $this->build()->deskEncodingDate);

        $this->settingService->setInternal(ScoutYearTransitionService::SETTING_DESK_ENCODING_DATE, '07-01');
        $this->assertSame('2026-07-01', $this->build()->deskEncodingDate);
    }

    /**
     * A signpost that cannot be read falls back to the default rather than
     * taking the page down with it.
     */
    public function testAMalformedDeskEncodingDateFallsBackToTheDefault(): void
    {
        $this->settingService->setInternal(ScoutYearTransitionService::SETTING_DESK_ENCODING_DATE, 'n\'importe quoi');

        $this->assertSame('2026-08-15', $this->build()->deskEncodingDate);
    }

    /**
     * The date decides which phase is labelled "en cours" and nothing else:
     * every step's state is identical on either side of it.
     */
    public function testTheDateOnlyMovesTheCurrentPhaseLabel(): void
    {
        $before = $this->build(self::ALL_MODULES, null, null, '2026-06-01');
        $after = $this->build(self::ALL_MODULES, null, null, '2026-09-01');

        $this->assertTrue($before->phases[0]->current);
        $this->assertFalse($before->phases[1]->current);
        $this->assertFalse($after->phases[0]->current);
        $this->assertTrue($after->phases[1]->current);

        $states = static fn(TransitionWorkflow $w): array => array_map(
            static fn(TransitionStep $s): StepState => $s->state,
            $w->steps
        );
        $this->assertSame($states($before), $states($after));
    }

    private function checkFor(string $key, StepCheck $check): StepCheckProvider
    {
        return new class ($key, $check) implements StepCheckProvider {
            public function __construct(private string $key, private StepCheck $check)
            {
            }

            public function stepKey(): string
            {
                return $this->key;
            }

            public function check(StepContext $context): StepCheck
            {
                return $this->check;
            }
        };
    }

    private function preparation(int $total, int $unassigned): ScoutYearPreparationProvider
    {
        $provider = $this->createStub(ScoutYearPreparationProvider::class);
        $provider->method('countPassages')->willReturn($total);
        $provider->method('countUnassignedPassages')->willReturn($unassigned);

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
