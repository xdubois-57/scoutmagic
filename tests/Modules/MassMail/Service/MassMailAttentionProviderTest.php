<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Attention\AttentionPoint;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Service\MassMailAttentionProvider;
use Modules\MassMail\Task\SendBatchHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * Issue #1443: a mailing shown « Envoi en cours » while no batch is
 * queued or running to send it — one urgent point per email, and nothing
 * while the chain is alive.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MassMailAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private DeliveryRepository $deliveries;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = $this->database();
        $this->deliveries = new DeliveryRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    protected function database(): \PDO
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);

        return $pdo;
    }

    /** @param list<string> $statuses one message per status */
    private function email(string $subject, string $status, array $statuses): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES (?, '<p>Corps</p>', ?, 'default_active_members', ?)"
        );
        $stmt->execute([$subject, $this->sectionId, $status]);
        $emailId = (int) $this->pdo->lastInsertId();

        foreach ($statuses as $index => $deliveryStatus) {
            $id = $this->deliveries->create($emailId, "m{$emailId}-{$index}@test.be");
            $this->pdo->prepare('UPDATE mass_mail_deliveries SET status = ? WHERE id = ?')->execute([$deliveryStatus, $id]);
        }

        return $emailId;
    }

    /** @return AttentionPoint[] */
    private function points(): array
    {
        return (new MassMailAttentionProvider(
            new EmailRepository($this->pdo),
            new SchedulerService(new SchedulerRepository($this->pdo))
        ))->collect(1);
    }

    public function testAMailingInProgressThatNothingSendsIsAnUrgentPoint(): void
    {
        $stalled = $this->email('Camp de Pâques', Email::STATUS_SENDING, ['pending', 'pending', 'sent']);
        $this->email('Déjà parti', Email::STATUS_SENT, ['sent']);

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame('L\'envoi « Camp de Pâques » est à l\'arrêt : 2 messages en attente', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('/mass-mail/' . $stalled, $points[0]->actionUrl);
    }

    public function testOneWaitingMessageIsSaidInTheSingular(): void
    {
        $this->email('Réunion', Email::STATUS_SENDING, ['pending']);

        $this->assertStringEndsWith(': 1 message en attente', $this->points()[0]->title);
    }

    /** @return iterable<string, array{string}> */
    public static function liveStatuses(): iterable
    {
        yield 'queued' => ['pending'];
        yield 'running' => ['processing'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveStatuses')]
    public function testNothingIsReportedWhileTheChainIsAlive(string $status): void
    {
        $this->email('Camp de Pâques', Email::STATUS_SENDING, ['pending']);
        $id = (new SchedulerRepository($this->pdo))->create(
            'mass_mail',
            'send_batch',
            date('Y-m-d H:i:s'),
            null,
            SendBatchHandler::REFERENCE
        );
        $this->pdo->exec("UPDATE scheduled_actions SET status = '{$status}' WHERE id = {$id}");

        $this->assertSame([], $this->points());
    }

    public function testAMailingWithNothingLeftToSendIsNotAPoint(): void
    {
        $this->email('Camp de Pâques', Email::STATUS_SENDING, ['sent', 'error']);

        $this->assertSame([], $this->points());
    }

    public function testItSaysWhichModuleItSpeaksFor(): void
    {
        $this->assertSame(
            'Envoi de mails',
            (new MassMailAttentionProvider(
                new EmailRepository($this->pdo),
                new SchedulerService(new SchedulerRepository($this->pdo))
            ))->sourceLabel()
        );
    }
}
