<?php

declare(strict_types=1);

namespace Modules\SosStaff\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\View\TwigFactory;
use Modules\SosStaff\Repository\ExcludedSectionRepository;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosSettingsService;

/**
 * Runs a single scheduled redirect transition (module spec §3/§4) — the
 * payload is exactly what Service\OnCallService::saveMonth() scheduled:
 * `date`, `member_id` (null = default number), `previous_member_id`, and
 * `scout_year_id` (stored at scheduling time rather than re-derived here,
 * so it can never drift from what was actually being viewed/saved).
 *
 * A fresh set of services is built from TaskContext on every run — task
 * handlers have no persistent DI container, only the shared infrastructure
 * TaskContext carries (see docs/module-development.md).
 */
class ApplyRedirectHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $sectionService = new SectionService($context->connection, $context->encryption, new MemberBadgeRepository($pdo));
        $memberYearRepository = new MemberYearRepository($pdo);
        $memberService = new MemberService($memberYearRepository, $context->encryption, $context->connection);

        $settingsService = new SosSettingsService(
            new ExcludedSectionRepository($pdo),
            new SosSettingsRepository($pdo),
            $sectionService,
            $memberYearRepository,
            new UnitStaffSectionService($pdo),
            $context->settings
        );

        $providerConfigService = new ProviderConfigService(new ProviderCredentialRepository($pdo, $context->encryption));

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['sos_staff' => dirname(__DIR__, 4) . '/modules/sos_staff/views']
        );

        $redirectService = new RedirectService(
            $providerConfigService,
            $settingsService,
            $memberService,
            $context->userAccounts,
            $context->mailService,
            $context->journal,
            $twig
        );

        $memberId = isset($payload['member_id']) && $payload['member_id'] !== null ? (int) $payload['member_id'] : null;
        $previousMemberId = isset($payload['previous_member_id']) && $payload['previous_member_id'] !== null
            ? (int) $payload['previous_member_id']
            : null;
        $scoutYearId = (int) ($payload['scout_year_id'] ?? 0);

        $redirectService->apply($memberId, $previousMemberId, $scoutYearId);
    }
}
