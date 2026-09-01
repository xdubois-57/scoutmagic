<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Badge\MemberBadgeRepository;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;

/**
 * Assigns the destination of every branch-change animé whose arrival branch
 * holds exactly ONE section — there is no decision to make there (module
 * spec's own "if there's only one possible section, just assign it" rule),
 * so staff are spared a pointless click.
 *
 * This used to run inside Controller\PassageController::index(), i.e. on
 * every single display of the page. It is a write, and writing on a GET
 * meant a link prefetch or an authenticated crawler could trigger it; more
 * practically, it does not belong in the render path of a page that is read
 * far more often than it changes. Moved here, the page only ever reads.
 *
 * Self-rescheduling hourly rather than being a first-class recurring task,
 * same pattern as Task\OpenRegistrationHandler and Task\
 * PurgeRegistrationRequestsHandler. Hourly is deliberate: a freshly imported
 * animé simply waits at most an hour for their obvious destination, and the
 * page still offers the single option in the picker meanwhile, so nothing is
 * ever blocked on this task running.
 *
 * Anchored on the PUBLIC year + 1, exactly like the page itself — see
 * Controller\PassageController's own docblock for why that is never
 * getEffectiveYear().
 */
class AutoAssignPassageHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'hourly';
    private const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $scoutYearService = new ScoutYearService($pdo);
        $scoutYearResolver = new ScoutYearResolver(
            $scoutYearService,
            $context->settings,
            new MemberYearRepository($pdo)
        );

        $publicYear = $scoutYearResolver->getCurrentPublicYear();
        $targetYearId = $scoutYearService->ensureYear(ScoutYearService::nextLabel($publicYear['label']));

        $transferRepository = new SectionTransferRepository($pdo);
        $passageService = new PassageService(
            $pdo,
            $context->encryption,
            new SectionService($context->connection, $context->encryption, new MemberBadgeRepository($pdo)),
            $transferRepository,
            new RegistrationRequestRepository($pdo, $context->encryption),
            new AgeBracketRepository($pdo)
        );

        $branchChanges = $passageService->getBranchChanges(
            (int) $publicYear['id'],
            (string) $publicYear['label'],
            $targetYearId
        );
        $assigned = $passageService->countSingleOptionDestinationsToAssign($branchChanges);
        $passageService->autoAssignSingleOptionDestinations($branchChanges, $targetYearId);

        if ($assigned > 0) {
            $context->journal->log(
                'registration',
                'registration_passage_auto_assigned',
                'info',
                'Destinations évidentes attribuées automatiquement (une seule section possible)',
                ['scout_year_id' => $targetYearId, 'count' => $assigned]
            );
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearmAfter('registration', 'auto_assign_passage', self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
