<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\SeedVerdict;
use Core\Mail\Feedback\Seed\Task\PurgeSeedCopiesHandler;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Pas encore » et « jamais » sont deux réponses, et c'est ici qu'on
 * tranche (roadmap IT-07).
 */
#[Group('database')]
class PurgeSeedCopiesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;
    private SeedCopyRepository $copies;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->copies = new SeedCopyRepository($this->pdo, $encryption);

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

    /**
     * **The decision the screen must not be left to make.** A copy sent
     * and not yet found is the ordinary state of every mailing still
     * going out; only elapsed time turns it into a refusal. Written down
     * once, here, it stays what it was — where a screen computing it on
     * the fly would change its own verdict while somebody watched.
     */
    public function testACopyNobodyEverSawBecomesNeverArrived(): void
    {
        $this->copies->claim('vieux', 'temoin@gmail.com', new \DateTimeImmutable('-5 days'));

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(SeedVerdict::Missing, $this->copies->forRun('vieux')[0]->verdict);
    }

    /**
     * **And a recent one is left alone**, which is the half that matters
     * more: an hour's patience would manufacture « jamais arrivé » for
     * copies that turn up perfectly well, and that is the one error this
     * screen must not make.
     */
    public function testARecentCopyIsStillWaiting(): void
    {
        $this->copies->claim('recent', 'temoin@gmail.com', new \DateTimeImmutable('-1 hour'));

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(SeedVerdict::Pending, $this->copies->forRun('recent')[0]->verdict);
    }

    /** An answer already given is never overwritten. */
    public function testACopyAlreadyFoundKeepsItsVerdict(): void
    {
        $sent = new \DateTimeImmutable('-5 days');
        $this->copies->claim('trouve', 'temoin@gmail.com', $sent);
        $this->copies->recordLanding('trouve', 'temoin@gmail.com', 'Junk', $sent);

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(SeedVerdict::Spam, $this->copies->forRun('trouve')[0]->verdict);
    }

    public function testResultsPastTheRetentionAreDropped(): void
    {
        $this->copies->claim(
            'antique',
            'temoin@gmail.com',
            new \DateTimeImmutable('-' . (PurgeSeedCopiesHandler::RETENTION_DAYS + 5) . ' days')
        );

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertCount(0, $this->copies->forRun('antique'));
    }

    /**
     * The window the screen shows is narrower than the one kept, so
     * « depuis quand ? » has an answer.
     */
    public function testTheRetentionIsWiderThanTheScreenReportsOn(): void
    {
        $this->assertGreaterThan(30, PurgeSeedCopiesHandler::RETENTION_DAYS);
        $this->assertLessThan(PurgeSeedCopiesHandler::RETENTION_DAYS, PurgeSeedCopiesHandler::GIVE_UP_AFTER_DAYS);
    }

    /** A sweep that did nothing says nothing. */
    public function testASweepWithNothingToDoWritesNoJournalLine(): void
    {
        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM event_log WHERE event_type = 'mail_seed_copies_swept'"));
    }

    /** And one that did says so — in counters, never an address. */
    public function testTheSweepJournalsCountsAndNoAddress(): void
    {
        $this->copies->claim('vieux', 'temoin-secret@gmail.com', new \DateTimeImmutable('-5 days'));

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $statement = $this->pdo->prepare("SELECT context FROM event_log WHERE event_type = 'mail_seed_copies_swept'");
        $statement->execute();
        $row = (string) $statement->fetchColumn();

        $this->assertStringNotContainsString('temoin-secret', $row);
        $this->assertSame(1, json_decode($row, true)['given_up']);
    }

    /** It re-arms itself, or it runs once and never again. */
    public function testItRearmsItself(): void
    {
        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(
            1,
            $this->scalar("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'purge_mail_seed_copies'")
        );
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
