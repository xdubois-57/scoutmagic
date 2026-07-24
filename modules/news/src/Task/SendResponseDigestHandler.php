<?php

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

        $digestService = new DigestService(
            new FormRepository($pdo),
            new FormResponseRepository($pdo, $context->encryption),
            new ArticleRepository($pdo),
            new UserAccountRepository($pdo, $context->encryption),
            $context->mailService,
            $twig,
            (string) ($context->settings->get('site_name') ?: 'Unité scoute'),
            (string) ($context->settings->get('base_url') ?: '')
        );

        $digestService->sendPendingDigests();

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->schedule('news', 'send_response_digest', new \DateTimeImmutable('+1 day'), [], self::REFERENCE);
    }
}
