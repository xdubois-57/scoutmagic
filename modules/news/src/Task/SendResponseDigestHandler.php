<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\UserAccountRepository;
use Core\View\TwigFactory;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Service\DigestService;

/**
 * Self-rescheduling, once-daily task (same pattern as Modules\MassMail\
 * Task\SendBatchHandler's own SchedulerService reconstruction from
 * TaskContext — see docs/module-development.md). The very first run is
 * scheduled idempotently by Controller\NewsController::manage() (same
 * "ensure" pattern as Modules\Finance\Controller\ConfigController's
 * purge_old_movements task), so simply visiting the chief management
 * page is enough to keep the chain alive even if a run is ever missed.
 */
class SendResponseDigestHandler implements TaskHandlerInterface
{
    public const REFERENCE = 'daily';

    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 2) . '/views']
        );

        // Core's templates plus this module's own: a handler runs outside
        // the composition root, so nothing has aggregated the manifests
        // for it (ARCHITECTURE.md §8.7bis). A customisation is honoured
        // all the same — that lives in the database, not in the registry.
        $registry = new \Core\Mail\Template\EmailTemplateRegistry();
        $registry->registerModuleManifest(
            \Core\Module\ModuleManifest::fromFile(dirname(__DIR__, 2) . '/module.json')
        );

        $digestService = new DigestService(
            new FormRepository($pdo),
            new FormResponseRepository($pdo, $context->encryption),
            new ArticleRepository($pdo),
            new UserAccountRepository($pdo, $context->encryption),
            $context->mailService,
            new \Core\Mail\Template\EmailTemplateRenderer(
                $twig,
                $registry,
                new \Core\Mail\Template\EmailTemplateOverrideRepository($pdo),
                $context->journal
            ),
            (string) ($context->settings->get('site_name') ?: 'Unité scoute'),
            (string) ($context->settings->get('base_url') ?: '')
        );

        $digestService->sendPendingDigests();

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearm('news', 'send_response_digest', self::REFERENCE, new \DateTimeImmutable('+1 day'));
    }
}
