<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Task;

use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\PlaceSummaryService;
use Modules\LlmConnector\Api\LlmConnectorInterface;

/**
 * Regenerates the summaries of places whose stays or reviews changed,
 * then re-schedules itself for tomorrow.
 *
 * STALE ONLY — never every place, never on a web request, never on every
 * edit. A model call is slow and costs money; doing one on a page load
 * makes the page as slow as the slowest third party, and doing one per
 * edit would spend a call on each of the seven fields a chief touches
 * while filling in one form.
 *
 * The batch cap is the other half of that: a unit that just imported
 * twenty years of camps must not turn one task run into twenty model
 * calls. What is left stays stale and is picked up tomorrow.
 *
 * **The connector is injected, never built here** (ARCHITECTURE.md §7.5).
 * This module consumes `llm_connector`'s public API and must degrade to
 * "no summary" when that module is absent or disabled — which it cannot
 * do while reaching into that module's own repositories and services, as
 * this handler used to: those classes stop existing the moment the module
 * is removed from an install. Only a composition root knows
 * whether it is enabled, so this handler is registered by hand in
 * public/index.php AND public/cron.php, the same as `inbound_mail`'s
 * polling task (§8.58): a handler registered in only one of the two fails
 * unconditionally under the other, and a test pins both call sites.
 *
 * The nullable default keeps the manifest's auto-resolution (`new
 * $handlerClass()`) working rather than fatal — an installation whose
 * composition root somehow missed this handler gets no summaries, which
 * is the same outcome as `llm_connector` being off.
 */
class RefreshPlaceSummariesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'refresh_place_summaries';
    public const REFERENCE = 'camps_refresh_place_summaries';

    private const MAX_PER_RUN = 10;

    public function __construct(private ?LlmConnectorInterface $llm = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        if ((string) ($context->settings->get('camps_ai_summary_enabled', 'camps', '1') ?? '1') === '1') {
            $this->refresh($pdo, $context);
        }

        $this->rescheduleTomorrow($pdo);
    }

    private function refresh(\PDO $pdo, TaskContext $context): void
    {
        $places = new PlaceRepository($pdo);
        $service = new PlaceSummaryService(
            $places,
            new CampRepository($pdo, $context->encryption),
            new ReviewRepository($pdo),
            $this->llm
        );

        if (!$service->isAvailable()) {
            return;
        }

        $written = 0;
        foreach ($places->findStaleSummaries(self::MAX_PER_RUN) as $place) {
            if ($service->refresh($place)->wasWritten()) {
                $written++;
            }
        }

        if ($written > 0) {
            $context->journal->log(
                'camps',
                'place_summaries_refreshed',
                'info',
                sprintf('%d résumé(s) de lieu régénéré(s).', $written)
            );
        }
    }

    private function rescheduleTomorrow(\PDO $pdo): void
    {
        SchedulerService::forPdo($pdo)->rearm('camps', self::TASK_KEY, self::REFERENCE, 'tomorrow 05:00');
    }
}
