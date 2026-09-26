<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Task;

use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Service\ConnectionService;

/**
 * Once a day: renews the Instagram token before it runs out, and checks
 * both connections still work.
 *
 * **Why renew at all.** An Instagram token lives sixty days and is only
 * renewed by asking — at least a day after it was issued, and before it
 * has expired; after that, only a person going back through Meta's
 * consent screen can repair it. Renewing once a week keeps it far from
 * both edges. A Facebook Page token obtained as this module obtains it
 * has no end, so it is only checked.
 *
 * **Why check daily.** A password change, an administrator removed from
 * the Page, an app switched off by Meta: each withdraws the authorisation
 * without a word to this site. The card's « Dernière vérification » and
 * the Api's list of destinations should know before a volunteer presses
 * « Publier ».
 *
 * The journal hears of a failure **once**, when a working connection
 * stops working — not every night after; a night when Meta or the network
 * does not answer changes nothing. The judgement is
 * {@see ConnectionService::check()}'s, the same one « Tester la connexion »
 * uses. The pass also drops a Facebook user token held for a Page choice
 * nobody finished.
 *
 * Re-arms itself daily through rearm(), never schedule()
 * (Tests\Architecture\RecurringTasksRearmTest).
 */
class CheckConnectionsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'check_connections';
    public const REFERENCE = 'daily';

    public function __construct(private readonly ?MetaClient $meta = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $service = new ConnectionService(
            new ConnectionRepository($pdo, $context->encryption),
            $context->journal,
            $this->meta ?? new MetaClient()
        );
        $now = new \DateTimeImmutable();

        $service->dropAbandonedPageChoice($now);
        foreach (SocialPlatform::cases() as $platform) {
            $service->check($platform, $now, null, true);
        }

        SchedulerService::forPdo($pdo)->rearm(
            'social',
            self::TASK_KEY,
            self::REFERENCE,
            new \DateTimeImmutable('tomorrow 04:20')
        );
    }
}
