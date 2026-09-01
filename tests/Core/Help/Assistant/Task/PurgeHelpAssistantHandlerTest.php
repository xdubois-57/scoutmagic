<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Assistant\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Help\Assistant\AssistantCacheRepository;
use Core\Help\Assistant\AssistantRateLimitRepository;
use Core\Help\Assistant\Task\PurgeHelpAssistantHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PurgeHelpAssistantHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testDropsRateLimitRowsPastTheQuotaWindowAndKeepsTheRest(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO help_assistant_rate_limits (user_account_id, created_at) VALUES (?, ?)');
        $stmt->execute([1, (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s')]);
        (new AssistantRateLimitRepository($this->pdo))->record(1);

        (new PurgeHelpAssistantHandler())->handle([], $this->context);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM help_assistant_rate_limits')->fetchColumn()
        );
    }

    public function testDropsCachedAnswersPastTheRetentionAndKeepsTheRest(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO help_assistant_cache (fingerprint, answer, topic_ids, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([str_repeat('a', 64), 'Vieille réponse.', '[]', (new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s')]);
        (new AssistantCacheRepository($this->pdo))->store(str_repeat('b', 64), 'Réponse récente.', ['calendrier']);

        (new PurgeHelpAssistantHandler())->handle([], $this->context);

        $this->assertNull((new AssistantCacheRepository($this->pdo))->find(str_repeat('a', 64)));
        $this->assertNotNull((new AssistantCacheRepository($this->pdo))->find(str_repeat('b', 64)));
    }

    public function testReschedulesItselfForTomorrow(): void
    {
        // Core\Scheduler has no recurring-task concept, so a daily task
        // re-arms itself at the end of every run — the same shape as the
        // human-check purge and the notification purge.
        (new PurgeHelpAssistantHandler())->handle([], $this->context);

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('core', PurgeHelpAssistantHandler::TASK_KEY, PurgeHelpAssistantHandler::REFERENCE);

        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }
}
