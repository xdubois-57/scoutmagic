<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Import\MemberYearRepository;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\PassageRosterRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\AutomaticAcceptancePolicy;
use Modules\Registration\Service\AutomaticAcceptanceService;
use Modules\Registration\Service\AutomaticDecisionEmailService;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\ForecastService;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\PriorityOrderService;
use Modules\Registration\Service\RecommendationService;
use Modules\Registration\Service\RequestDecisionService;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotService;

/**
 * The automatic acceptance of the « Oui » requests (issue #843, D7.2 and
 * IT-10): one pass of Service\AutomaticAcceptanceService — per open scout
 * year and slot, in queue order, one request at a time, under a lock per
 * slot — every hour.
 *
 * **It does nothing unless the automatic acceptance is active**
 * (Service\AutomaticAcceptancePolicy::isActive(): the switch, its date,
 * waitlists managed, the automatic sending on and the acceptance template
 * written), read at each pass.
 *
 * **A recurring poller**, re-armed in a `finally` — a failed pass does not
 * end the chain — an hour later, or at once after a pass that reached its
 * cap. An hour is soon enough: an accepted family hears of it only when
 * the acceptance e-mail leaves, a delay of at least an hour later
 * (IT-09). Seeded from public/scheduler-bootstrap.php, so a site reached
 * only by its crontab runs it too.
 *
 * Its services are built here, by hand, as every task of this module
 * builds its own (the scheduler constructs handlers with no argument).
 */
class AutoAcceptRequestsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'auto_accept_requests';
    public const REFERENCE = 'poll';

    /** The wait between two passes. */
    public const INTERVAL_SECONDS = 3600;

    /**
     * The first run of the chain, from an entry point (public/
     * scheduler-bootstrap.php). Idempotent: seed() adds nothing while the
     * chain is alive.
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->seed('registration', self::TASK_KEY, self::REFERENCE, new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $more = false;

        try {
            $more = self::buildService($context)->run()['more'];
        } finally {
            SchedulerService::forPdo($pdo)->rearmAfter(
                'registration',
                self::TASK_KEY,
                self::REFERENCE,
                $more ? 0 : self::INTERVAL_SECONDS
            );
        }
    }

    /**
     * The pass and everything it reads, over the task's connection — the
     * same collaborators public/index.php gives the decision dialog, so an
     * automatic acceptance is the decision a chief would take there.
     */
    public static function buildService(TaskContext $context): AutomaticAcceptanceService
    {
        $pdo = $context->connection->getPdo();
        $encryption = $context->encryption;
        $settings = $context->settings;
        $journal = $context->journal;

        $decisionEmailRepository = new DecisionEmailRepository($pdo);
        $requests = new RegistrationRequestRepository($pdo, $encryption, null, $decisionEmailRepository);
        $scoutYears = new ScoutYearService($pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYears, $settings, new MemberYearRepository($pdo));
        $ageBrackets = new AgeBracketRepository($pdo);

        $roster = new PassageRosterRepository($pdo, $encryption);
        $profiles = new MemberProfileRepository($context->connection, $encryption, new MemberBadgeRepository($pdo));
        $sections = new SectionService(new SectionRepository($context->connection), $profiles);
        $passage = new PassageService(
            $roster,
            $encryption,
            $sections,
            new SectionTransferRepository($pdo),
            $requests,
            $ageBrackets
        );
        $slots = new SlotService(
            $roster,
            $scoutYears,
            $settings,
            $ageBrackets,
            new SlotCapacityRepository($pdo),
            $requests,
            new ForecastService($roster, $encryption, $sections, $passage),
            new ReenrollmentRepository($pdo, $encryption)
        );

        $decisionEmails = new DecisionEmailService($decisionEmailRepository, $journal);
        $automaticEmails = new AutomaticDecisionEmailService($settings, $decisionEmails);
        $policy = new AutomaticAcceptancePolicy($settings, $slots, $automaticEmails);
        $recommendations = new RecommendationService(
            $requests,
            $slots,
            $ageBrackets,
            $scoutYears,
            $scoutYearResolver,
            new PriorityOrderService($settings)
        );
        $decisions = new RequestDecisionService(
            $requests,
            new RequestStatusService($requests, $journal),
            $recommendations,
            $scoutYears,
            $scoutYearResolver,
            $journal,
            $automaticEmails,
            $policy
        );

        return new AutomaticAcceptanceService(
            $policy,
            $recommendations,
            $decisions,
            $requests,
            $scoutYears,
            $journal
        );
    }
}
