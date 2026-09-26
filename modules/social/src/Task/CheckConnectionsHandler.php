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
use Modules\Social\Meta\MetaException;
use Modules\Social\Repository\ConnectionRepository;

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
 * stops working — not every night after.
 *
 * Re-arms itself daily through rearm(), never schedule()
 * (Tests\Architecture\RecurringTasksRearmTest).
 */
class CheckConnectionsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'check_connections';
    public const REFERENCE = 'daily';

    /** Meta refuses a renewal younger than a day; a week keeps well clear of it. */
    public const REFRESH_AFTER_DAYS = 7;

    public function __construct(private readonly ?MetaClient $meta = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $connections = new ConnectionRepository($pdo, $context->encryption);
        $meta = $this->meta ?? new MetaClient();

        foreach (SocialPlatform::cases() as $platform) {
            $this->checkOne($platform, $connections, $meta, $context);
        }

        SchedulerService::forPdo($pdo)->rearm(
            'social',
            self::TASK_KEY,
            self::REFERENCE,
            new \DateTimeImmutable('tomorrow 04:20')
        );
    }

    private function checkOne(
        SocialPlatform $platform,
        ConnectionRepository $connections,
        MetaClient $meta,
        TaskContext $context
    ): void {
        $connection = $connections->find($platform);
        if ($connection === null || !$connection->isConnected()) {
            return;
        }

        $now = new \DateTimeImmutable();
        $wasWorking = $connection->checkOk !== false;

        if ($connection->isExpired($now)) {
            $connections->recordCheck($platform, false, $now);
            if ($wasWorking) {
                $this->log($context, $platform, 'token_expired', 'warning', 'L\'autorisation a expiré. '
                    . 'Reconnectez le compte dans Configuration > Réseaux sociaux.');
            }

            return;
        }

        $token = $connections->secretsOf($platform)->accessToken;

        try {
            if ($platform === SocialPlatform::Instagram && $this->dueForRefresh($connection->tokenRefreshedAt, $now)) {
                $renewed = $meta->refreshInstagramToken($token);
                $token = $renewed['token'];
                $connections->recordRefresh(
                    $platform,
                    $token,
                    $renewed['expires_in'] === null ? null : $now->modify('+' . $renewed['expires_in'] . ' seconds'),
                    $now
                );
                $this->log($context, $platform, 'token_refreshed', 'info', 'Autorisation renouvelée.');
            }

            $name = $platform === SocialPlatform::Facebook
                ? $meta->facebookPageName((string) $connection->accountId, $token)
                : $meta->instagramAccount($token)['username'];
            $connections->recordCheck($platform, true, $now, $name);
        } catch (MetaException $e) {
            $connections->recordCheck($platform, false, $now);
            if ($wasWorking) {
                $this->log($context, $platform, 'auth_failed', 'warning', $e->getMessage(), $e->detail);
            }
        }
    }

    private function dueForRefresh(?\DateTimeImmutable $refreshedAt, \DateTimeImmutable $now): bool
    {
        return $refreshedAt === null || $refreshedAt <= $now->modify('-' . self::REFRESH_AFTER_DAYS . ' days');
    }

    private function log(
        TaskContext $context,
        SocialPlatform $platform,
        string $event,
        string $level,
        string $message,
        ?string $detail = null
    ): void {
        $context->journal->log(
            'social',
            $event,
            $level,
            $platform->label() . ' : ' . $message,
            array_filter(['platform' => $platform->value, 'detail' => $detail], static fn ($v) => $v !== null)
        );
    }
}
