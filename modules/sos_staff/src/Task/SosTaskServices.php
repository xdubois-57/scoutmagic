<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Import\MemberYearRepository;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\View\TwigFactory;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Repository\ExcludedSectionRepository;
use Modules\SosStaff\Repository\OnCallRepository;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Service\OnCallService;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectReconciler;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosSettingsService;

/**
 * The module's services, built from a TaskContext — task handlers have no
 * persistent DI container, only the shared infrastructure TaskContext
 * carries (see docs/module-development.md). One builder for both handlers
 * (ApplyRedirectHandler, ReconcileRedirectHandler), so a scheduled
 * transition and the hourly check cannot redirect through two differently
 * wired services.
 */
final class SosTaskServices
{
    private function __construct(
        public readonly RedirectService $redirectService,
        public readonly RedirectReconciler $reconciler
    ) {
    }

    public static function build(TaskContext $context): self
    {
        $pdo = $context->connection->getPdo();

        $sectionService = new SectionService(
            new SectionRepository($context->connection),
            new MemberProfileRepository($context->connection, $context->encryption, new MemberBadgeRepository($pdo))
        );
        $memberYearRepository = new MemberYearRepository($pdo);
        $memberEmails = new MemberEmailRepository($pdo, $context->encryption);
        $memberService = new MemberService(
            $memberYearRepository,
            new MemberProfileRepository($context->connection, $context->encryption),
            null,
            $memberEmails
        );

        $settingsService = new SosSettingsService(
            new ExcludedSectionRepository($pdo),
            new SosSettingsRepository($pdo),
            $sectionService,
            $memberYearRepository,
            new UnitStaffSectionService(new \Core\Member\Repository\UnitStaffSectionRepository($pdo)),
            $context->settings
        );

        // A simulated line where test_tools offers one (ARCHITECTURE.md
        // §8.63) — the same one the page talks to — null everywhere else.
        $simulatedProvider = $context->getOptional(PhoneProviderInterface::class);
        $providerConfigService = new ProviderConfigService(
            new ProviderCredentialRepository($pdo, $context->encryption),
            null,
            $simulatedProvider instanceof PhoneProviderInterface ? $simulatedProvider : null
        );

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['sos_staff' => dirname(__DIR__, 4) . '/modules/sos_staff/views']
        );

        // Core's templates plus this module's own: a handler runs outside
        // the composition root, so nothing has aggregated the manifests
        // for it (ARCHITECTURE.md §8.7bis). A customisation is still
        // honoured — that lives in the database, not in the registry.
        $emailTemplateRegistry = new \Core\Mail\Template\EmailTemplateRegistry();
        $emailTemplateRegistry->registerModuleManifest(
            \Core\Module\ModuleManifest::fromFile(dirname(__DIR__, 2) . '/module.json')
        );

        $redirectService = new RedirectService(
            $providerConfigService,
            $settingsService,
            $memberService,
            $context->userAccounts,
            $context->mailService,
            $context->journal,
            new \Core\Mail\Template\EmailTemplateRenderer(
                $twig,
                $emailTemplateRegistry,
                new \Core\Mail\Template\EmailTemplateOverrideRepository($pdo),
                $context->journal
            ),
            // Nullable on TaskContext, and nullable here: a scheduler run
            // built without the notification stack still applies the
            // redirection and simply tells nobody about it.
            $context->notifications,
            new MemberAccountResolver($memberYearRepository, $memberEmails, $context->userAccounts)
        );

        $onCallService = new OnCallService(
            new OnCallRepository($pdo),
            SchedulerService::forPdo($pdo),
            $settingsService
        );

        return new self(
            $redirectService,
            new RedirectReconciler(
                $onCallService,
                $redirectService,
                $providerConfigService,
                $settingsService,
                $context->journal
            )
        );
    }
}
