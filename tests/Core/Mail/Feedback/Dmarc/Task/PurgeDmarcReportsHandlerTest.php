<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Feedback\Dmarc\DmarcRecord;
use Core\Mail\Feedback\Dmarc\DmarcReport;
use Core\Mail\Feedback\Dmarc\DmarcReportRepository;
use Core\Mail\Feedback\Dmarc\Task\PurgeDmarcReportsHandler;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Operational data stops being read long before it stops existing
 * (roadmap IT-06).
 */
#[Group('database')]
class PurgeDmarcReportsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;
    private DmarcReportRepository $reports;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        // Prepared like everything else that talks to a database here
        // (AGENTS.md): « every SQL statement is prepared » is a shape, and
        // a shape a test file is exempt from is a shape the next file
        // copies without it.
        $this->pdo->prepare('PRAGMA foreign_keys = ON')->execute();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->reports = new DmarcReportRepository($this->pdo);

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

    private function report(string $id, int $endedDaysAgo): DmarcReport
    {
        return new DmarcReport(
            organisation: 'google.com',
            reportId: $id,
            domain: 'unite.be',
            begin: new \DateTimeImmutable('-' . ($endedDaysAgo + 1) . ' days'),
            end: new \DateTimeImmutable('-' . $endedDaysAgo . ' days'),
            policy: 'none',
            records: [new DmarcRecord('185.12.80.100', 5, 'none', true, true, 'unite.be')]
        );
    }

    public function testAReportPastTheWindowIsDroppedWithItsLines(): void
    {
        $this->reports->record(
            $this->report('old', PurgeDmarcReportsHandler::RETENTION_DAYS + 5),
            new \DateTimeImmutable()
        );

        (new PurgeDmarcReportsHandler())->handle([], $this->context);

        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM mail_dmarc_reports'));
        $this->assertSame(
            0,
            $this->scalar('SELECT COUNT(*) FROM mail_dmarc_sources'),
            'the lines follow their report.'
        );
    }

    public function testAReportInsideTheWindowIsKept(): void
    {
        $this->reports->record($this->report('recent', 10), new \DateTimeImmutable());

        (new PurgeDmarcReportsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM mail_dmarc_reports'));
    }

    /**
     * **The window is wider than the screen's**, and deliberately: the
     * page reports on thirty days, so ninety leaves two further windows
     * for somebody to answer « depuis quand ? » about a source they have
     * only just noticed.
     */
    public function testTheWindowIsWiderThanTheScreenReportsOn(): void
    {
        $this->assertGreaterThan(30, PurgeDmarcReportsHandler::RETENTION_DAYS);
    }

    /** A purge that removed nothing says nothing. */
    public function testAPurgeWithNothingToDoWritesNoJournalLine(): void
    {
        (new PurgeDmarcReportsHandler())->handle([], $this->context);

        $this->assertSame(
            0,
            $this->scalar("SELECT COUNT(*) FROM event_log WHERE event_type = 'mail_dmarc_reports_purged'")
        );
    }

    /** And one that removed something says so, without naming a source. */
    public function testThePurgeJournalsACountAndNoAddress(): void
    {
        $this->reports->record(
            $this->report('old', PurgeDmarcReportsHandler::RETENTION_DAYS + 5),
            new \DateTimeImmutable()
        );

        (new PurgeDmarcReportsHandler())->handle([], $this->context);

        $statement = $this->pdo->prepare(
            "SELECT context FROM event_log WHERE event_type = 'mail_dmarc_reports_purged'"
        );
        $statement->execute();
        $row = $statement->fetchColumn();

        $this->assertNotFalse($row);
        $this->assertStringNotContainsString('185.12.80.100', (string) $row);
        $this->assertSame(1, json_decode((string) $row, true)['dropped']);
    }

    /** It re-arms itself, or it runs once and never again. */
    public function testItRearmsItself(): void
    {
        (new PurgeDmarcReportsHandler())->handle([], $this->context);

        $this->assertSame(
            1,
            $this->scalar("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'purge_mail_dmarc_reports'")
        );
    }

    /** One prepared read, so no assertion here builds a statement by hand. */
    private function scalar(string $sql): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
