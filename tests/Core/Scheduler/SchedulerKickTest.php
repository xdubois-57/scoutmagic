<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerContinuation;
use Core\Scheduler\SchedulerKick;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The kick is what the two file-replacing handlers use to get the schema
 * migration started in a process of their own, immediately, having
 * deliberately not migrated in the process that replaced the files.
 *
 * Everything about it is opportunistic. By the time it runs, the update
 * or restore has already succeeded and the migration is already queued —
 * so every way of failing to send the request must leave the operation
 * untouched. A failed kick is a slower migration, never a failed update,
 * and these tests are that promise.
 */
class SchedulerKickTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/scheduler_kick_test_' . uniqid();
        mkdir($this->storagePath . '/keys', 0755, true);
        mkdir($this->storagePath . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['keys/master.key', 'config/secrets.enc'] as $file) {
            @unlink($this->storagePath . '/' . $file);
        }
        foreach (['keys', 'config'] as $dir) {
            @rmdir($this->storagePath . '/' . $dir);
        }
        @rmdir($this->storagePath);
    }

    private function context(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );
    }

    /**
     * The path that matters: a real secrets.enc carrying a real
     * continuation secret, so the kick gets all the way to
     * SchedulerContinuation::kick() instead of bailing out early.
     *
     * `base_url` is deliberately unset, so nothing is written to a socket
     * — a test that opened one would be testing the network. What proves
     * the kick really happened is the side effect that precedes the
     * request: kick() begins a FRESH chain, so a hop counter left over
     * from an unrelated chain is back to zero. An update is not a
     * continuation of whatever was running, and must get the full hop
     * budget.
     */
    public function testARealSecretGetsTheKickAllTheWayToAFreshChain(): void
    {
        $secretManager = new SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $secretManager->generateMasterKey();
        $secretManager->writeSecrets(['scheduler_continuation_secret' => 'a-secret-nobody-else-has']);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(SchedulerContinuation::HOPS_SETTING, '0', 'number', 'hops', 'test', null, null, null, false, 900);
        $settings->setInternal(SchedulerContinuation::HOPS_SETTING, '19');

        $context = new TaskContext(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $settings,
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );

        SchedulerKick::now($context);

        $this->assertSame(
            '0',
            (string) $settings->get(SchedulerContinuation::HOPS_SETTING),
            'the kick must have reached kick(), which begins a fresh chain'
        );
    }

    /**
     * No secrets.enc at all — the shape a host takes when secrets could
     * not be written, and one of several ways there is nowhere to send
     * the request. It reports that it sent nothing; it does not throw.
     */
    public function testAnInstallationWithNoSecretsReportsRatherThanThrows(): void
    {
        $this->assertFalse(SchedulerKick::now($this->context()));
    }

    /**
     * The same for a database that answers nothing useful: this runs
     * moments after a file tree was replaced under a live process, which
     * is not the moment to discover a new way to throw.
     */
    public function testAnUnusableConnectionIsStillNotAThrow(): void
    {
        $broken = new TaskContext(
            new Connection('invalid.invalid.host', 3306, 'nope', 'nope', 'nope'),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );

        $this->assertFalse(SchedulerKick::now($broken));
    }

    // --- fromPdo(): the same kick, from public/index.php's
    // pending-migration block, which has no TaskContext to offer ---

    /**
     * The hand-back after the last migration slice. Same proof as the
     * TaskContext path: `base_url` is unset so nothing reaches a socket,
     * and what shows the kick really happened is the fresh chain it
     * begins.
     */
    public function testFromPdoGetsTheKickAllTheWayToAFreshChain(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(SchedulerContinuation::HOPS_SETTING, '0', 'number', 'hops', 'test', null, null, null, false, 900);
        $settings->setInternal(SchedulerContinuation::HOPS_SETTING, '19');

        SchedulerKick::fromPdo(
            $this->pdo,
            new JournalService(new JournalRepository($this->pdo)),
            'a-secret-nobody-else-has'
        );

        $this->assertSame(
            '0',
            (string) (new SettingService(new SettingRepository($this->pdo)))->get(SchedulerContinuation::HOPS_SETTING),
            'the hand-back must have reached kick(), which begins a fresh chain'
        );
    }

    /**
     * An installation whose continuation secret has not been generated yet
     * — the first requests of a brand-new install. Nothing to authenticate
     * a hop with means no hop, and the queue drains the way it always did.
     */
    public function testFromPdoWithNoSecretSendsNothing(): void
    {
        $this->assertFalse(
            SchedulerKick::fromPdo($this->pdo, new JournalService(new JournalRepository($this->pdo)), '')
        );
    }

    /**
     * This one runs at the end of a migration slice, on a database that
     * has just had DDL run against it. A throw here would turn a finished
     * migration into a 500 on the endpoint driving the chain.
     */
    public function testFromPdoOnAnUnusableDatabaseIsStillNotAThrow(): void
    {
        $broken = new \PDO('sqlite::memory:');
        $broken->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->assertFalse(
            SchedulerKick::fromPdo($broken, new JournalService(new JournalRepository($this->pdo)), 'a-secret')
        );
    }
}
