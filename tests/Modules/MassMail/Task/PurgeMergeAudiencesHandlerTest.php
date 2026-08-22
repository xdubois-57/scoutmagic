<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Task\PurgeMergeAudiencesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * RGPD retention for imported mail-merge audiences: purged 18 months
 * after the send (merge_retention_months, read-only), orphans after 7
 * days, and never while a draft/test/sending email still references the
 * audience. The tracking history must survive the purge.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeMergeAudiencesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AudienceRepository $audienceRepository;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->audienceRepository = new AudienceRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();

        (new SettingService(new SettingRepository($this->pdo)))
            ->register('merge_retention_months', '18', 'number', 'label', 'desc', 'mass_mail', null, null, false);
    }

    private function buildContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }

    private function createAudience(string $createdAt): int
    {
        $id = $this->audienceRepository->createAudience('f.xlsx', 'Feuille1', ['Prenom'], 1, null);
        $this->pdo->prepare('UPDATE mass_mail_audiences SET created_at = ? WHERE id = ?')->execute([$createdAt, $id]);
        return $id;
    }

    private function createEmail(int $audienceId, string $status, ?string $sentAt): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, audience_id, status, sent_at)
             VALUES ('S', '<p>B</p>', ?, 'mail_merge', ?, ?, ?)"
        );
        $stmt->execute([$this->sectionId, $audienceId, $status, $sentAt]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testPurgesAudiencesSentLongerAgoThanRetentionAndKeepsRecentOnes(): void
    {
        $oldAudience = $this->createAudience('2020-01-01 00:00:00');
        $oldEmail = $this->createEmail($oldAudience, 'sent', '2020-06-01 00:00:00');
        $oldRowId = $this->audienceRepository->createRow($oldAudience, 2, null, 'a@test.be', ['Prenom' => 'A']);
        $this->pdo->prepare(
            "INSERT INTO mass_mail_recipients (email_id, audience_row_id, status) VALUES (?, ?, 'sent')"
        )->execute([$oldEmail, $oldRowId]);

        $recentAudience = $this->createAudience('2020-01-01 00:00:00');
        $this->createEmail($recentAudience, 'sent', (new \DateTimeImmutable('-1 month'))->format('Y-m-d H:i:s'));

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertNull($this->audienceRepository->findById($oldAudience));
        $this->assertNotNull($this->audienceRepository->findById($recentAudience));

        // The sent email and its tracking history survive, unlinked.
        $stmt = $this->pdo->prepare('SELECT audience_id FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$oldEmail]);
        $this->assertNull($stmt->fetchColumn() ?: null);
        $row = $this->pdo->query('SELECT audience_row_id, status FROM mass_mail_recipients')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['audience_row_id']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_audience_rows')->fetchColumn());
    }

    public function testNeverPurgesAnAudienceStillReferencedByADraft(): void
    {
        $audience = $this->createAudience('2020-01-01 00:00:00');
        $this->createEmail($audience, 'draft', null);

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertNotNull($this->audienceRepository->findById($audience));
    }

    public function testPurgesOrphanAudiencesAfterSevenDaysButKeepsFreshOnes(): void
    {
        $oldOrphan = $this->createAudience('2020-01-01 00:00:00');
        $freshOrphan = $this->createAudience((new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertNull($this->audienceRepository->findById($oldOrphan));
        $this->assertNotNull($this->audienceRepository->findById($freshOrphan));
    }

    public function testReschedulesItselfDaily(): void
    {
        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'purge_merge_audiences' AND status = 'pending'"
        );
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
