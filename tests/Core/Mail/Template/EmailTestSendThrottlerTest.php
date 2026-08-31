<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Template\EmailTestSendThrottler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One test send per 30 seconds per account.
 *
 * The two properties worth pinning are the two that would go unnoticed:
 * the window is PER ACCOUNT (so one administrator leaning on the button
 * does not lock their colleague out), and a send that never happened
 * never counted (a failed relay must not cost the person debugging it
 * half a minute per attempt).
 *
 * @group database
 */
class EmailTestSendThrottlerTest extends TestCase
{
    private \PDO $pdo;
    private JournalService $journal;
    private EmailTestSendThrottler $throttler;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->throttler = new EmailTestSendThrottler($this->pdo);
    }

    public function testAnAccountThatHasNeverSentMaySendNow(): void
    {
        $this->assertTrue($this->throttler->allows(1));
        $this->assertSame(0, $this->throttler->secondsRemaining(1));
    }

    public function testAnAccountThatJustSentMustWait(): void
    {
        $this->recordSend(1);

        $this->assertFalse($this->throttler->allows(1));
        $this->assertGreaterThan(0, $this->throttler->secondsRemaining(1));
        $this->assertLessThanOrEqual(EmailTestSendThrottler::WINDOW_SECONDS, $this->throttler->secondsRemaining(1));
    }

    public function testTheWindowIsPerAccount(): void
    {
        $this->recordSend(1);

        $this->assertTrue(
            $this->throttler->allows(2),
            "One administrator's test must not lock their colleague out."
        );
    }

    public function testTheWindowHasPassedOnceItHasPassed(): void
    {
        $this->recordSendAt(1, '-31 seconds');

        $this->assertTrue($this->throttler->allows(1));
    }

    public function testAnUnattributableSendIsRefused(): void
    {
        // The RBAC guard makes this impossible on the route, which is
        // exactly why it must not be the case that quietly passes.
        $this->assertFalse($this->throttler->allows(null));
        $this->assertSame(EmailTestSendThrottler::WINDOW_SECONDS, $this->throttler->secondsRemaining(null));
    }

    public function testAnUnrelatedJournalEntryDoesNotCount(): void
    {
        $this->journal->log('core', 'email_template_customised', 'info', 'x', ['template_id' => 'a'], 1);

        $this->assertTrue($this->throttler->allows(1));
    }

    private function recordSend(int $userId): void
    {
        $this->journal->log(
            'core',
            EmailTestSendThrottler::JOURNAL_TYPE,
            'info',
            'Envoi de test',
            ['template_id' => 'magic_link'],
            $userId
        );
    }

    private function recordSendAt(int $userId, string $modifier): void
    {
        $this->recordSend($userId);

        $stmt = $this->pdo->prepare('UPDATE event_log SET logged_at = ? WHERE event_type = ?');
        $stmt->execute([
            (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s'),
            EmailTestSendThrottler::JOURNAL_TYPE,
        ]);
    }
}
