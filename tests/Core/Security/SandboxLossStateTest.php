<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SandboxLossState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A test sandbox armed and no longer acting (issue #1469): journaled once
 * per change, blocked until a superadmin releases it, and released only for
 * as long as that one loss lasts.
 */
#[Group('database')]
final class SandboxLossStateTest extends TestCase
{
    private \PDO $pdo;
    private JournalService $journal;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        SandboxLossState::register($this->settings());
    }

    /** Each call is a fresh request: its own SettingService cache, its own state. */
    private function settings(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    private function state(): SandboxLossState
    {
        return new SandboxLossState($this->settings());
    }

    /** @return list<array{type: string, level: string, context: ?string, user_account_id: mixed}> */
    private function journalLines(string $type): array
    {
        $statement = $this->pdo->prepare(
            "SELECT event_type AS type, level, context, user_account_id FROM event_log
             WHERE category = 'core' AND event_type = ? ORDER BY id"
        );
        $statement->execute([$type]);

        /** @var list<array{type: string, level: string, context: ?string, user_account_id: mixed}> */
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testNothingIsBlockedWhileNothingIsLost(): void
    {
        $state = $this->state();
        $state->record(SandboxLossState::CHANNEL_MAIL, null, $this->journal);

        $this->assertFalse($state->isBlocked(SandboxLossState::CHANNEL_MAIL));
        $this->assertNull($state->reason(SandboxLossState::CHANNEL_MAIL));
        $this->assertSame([], $this->journalLines('mail_capture_lost'));
        $this->assertSame([], $this->journalLines('mail_capture_loss_cleared'));
    }

    public function testALossBlocksAndIsJournaledOnceAcrossRequests(): void
    {
        for ($request = 0; $request < 3; $request++) {
            $state = $this->state();
            $state->record(
                SandboxLossState::CHANNEL_MAIL,
                SandboxLossState::REASON_PROFILE_MISSING,
                $this->journal
            );
            $this->assertTrue($state->isBlocked(SandboxLossState::CHANNEL_MAIL));
        }

        $lines = $this->journalLines('mail_capture_lost');
        $this->assertCount(1, $lines, 'One line per change of state, not one per request.');
        $this->assertSame('security', $lines[0]['level']);
        $this->assertSame(['reason' => 'profile_missing'], json_decode((string) $lines[0]['context'], true));
    }

    public function testTheTwoChannelsAreIndependent(): void
    {
        $state = $this->state();
        $state->record(SandboxLossState::CHANNEL_TELEPHONY, SandboxLossState::REASON_HOST_MISMATCH, $this->journal);

        $this->assertTrue($state->isBlocked(SandboxLossState::CHANNEL_TELEPHONY));
        $this->assertFalse($state->isBlocked(SandboxLossState::CHANNEL_MAIL));
        $lines = $this->journalLines('telephony_simulation_lost');
        $this->assertCount(1, $lines);
        $this->assertSame(['reason' => 'host_mismatch'], json_decode((string) $lines[0]['context'], true));
        $this->assertSame([], $this->journalLines('mail_capture_lost'));
    }

    public function testANewReasonIsANewLossAndIsJournaledAgain(): void
    {
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_PROFILE_MISSING, $this->journal);
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_MODULE_UNLOADED, $this->journal);

        $this->assertCount(2, $this->journalLines('mail_capture_lost'));
        $this->assertSame(
            SandboxLossState::REASON_MODULE_UNLOADED,
            $this->state()->reason(SandboxLossState::CHANNEL_MAIL)
        );
    }

    public function testAReleaseReopensAndIsJournaledWithTheAccount(): void
    {
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_PROFILE_MISSING, $this->journal);

        $this->assertTrue($this->state()->release(SandboxLossState::CHANNEL_MAIL, 42, $this->journal));

        $state = $this->state();
        $state->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_PROFILE_MISSING, $this->journal);
        $this->assertFalse($state->isBlocked(SandboxLossState::CHANNEL_MAIL), 'Released: real sending is open.');
        $this->assertTrue($state->isReleased(SandboxLossState::CHANNEL_MAIL));

        $lines = $this->journalLines('mail_capture_loss_released');
        $this->assertCount(1, $lines);
        $this->assertSame('security', $lines[0]['level']);
        $this->assertSame(42, (int) $lines[0]['user_account_id']);
        $this->assertCount(1, $this->journalLines('mail_capture_lost'), 'The same loss is not journaled again.');

        $this->assertFalse(
            $this->state()->release(SandboxLossState::CHANNEL_MAIL, 42, $this->journal),
            'Releasing twice changes nothing.'
        );
    }

    /**
     * A release whose journal line cannot be written is undone (review of
     * #1549): real sending is never reopened without a trace of who did it.
     */
    public function testAReleaseThatCannotBeJournaledIsUndone(): void
    {
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_PROFILE_MISSING, $this->journal);
        $failingJournal = new class (new JournalRepository($this->pdo)) extends JournalService {
            public function log(
                string $category,
                string $type,
                string $level,
                string $description,
                array $context = [],
                ?int $userId = null
            ): void {
                throw new \RuntimeException('journal indisponible');
            }
        };

        try {
            $this->state()->release(SandboxLossState::CHANNEL_MAIL, 42, $failingJournal);
            $this->fail('The journal failure must reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('journal indisponible', $e->getMessage());
        }

        $state = $this->state();
        $this->assertTrue($state->isBlocked(SandboxLossState::CHANNEL_MAIL), 'Still blocked: the release was undone.');
        $this->assertSame(SandboxLossState::REASON_PROFILE_MISSING, (string) $this->settings()->get(SandboxLossState::SETTING_MAIL));
        $this->assertTrue($state->release(SandboxLossState::CHANNEL_MAIL, 42, $this->journal), 'And it can be decided again.');
    }

    public function testNothingToReleaseWhileNothingIsLost(): void
    {
        $this->assertFalse($this->state()->release(SandboxLossState::CHANNEL_MAIL, 1, $this->journal));
        $this->assertSame([], $this->journalLines('mail_capture_loss_released'));
    }

    /**
     * When the sandbox acts again, the release goes with the loss it
     * answered: a later loss blocks again rather than inheriting it.
     */
    public function testRecoveryClearsTheReleaseSoALaterLossBlocksAgain(): void
    {
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_PROFILE_MISSING, $this->journal);
        $this->state()->release(SandboxLossState::CHANNEL_MAIL, 1, $this->journal);

        $recovered = $this->state();
        $recovered->record(SandboxLossState::CHANNEL_MAIL, null, $this->journal);
        $this->assertFalse($recovered->isBlocked(SandboxLossState::CHANNEL_MAIL));
        $this->assertFalse($recovered->isReleased(SandboxLossState::CHANNEL_MAIL));
        $this->assertSame('', (string) $this->settings()->get(SandboxLossState::SETTING_MAIL));
        $cleared = $this->journalLines('mail_capture_loss_cleared');
        $this->assertCount(1, $cleared);
        $this->assertSame('info', $cleared[0]['level']);

        $lostAgain = $this->state();
        $lostAgain->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_PROFILE_MISSING, $this->journal);
        $this->assertTrue($lostAgain->isBlocked(SandboxLossState::CHANNEL_MAIL));
        $this->assertCount(2, $this->journalLines('mail_capture_lost'));
    }

    /**
     * A request whose settings cache still holds an old release, while
     * other requests recovered and lost again since (#1549 review): the
     * loss it records is compared with the row as it is now, so the stale
     * release does not reopen real sending.
     */
    public function testAReleaseLeftInAStaleCacheDoesNotReopenANewLoss(): void
    {
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_MODULE_UNLOADED, $this->journal);
        $this->state()->release(SandboxLossState::CHANNEL_MAIL, 1, $this->journal);

        $staleSettings = $this->settings();
        $this->assertSame(
            SandboxLossState::REASON_MODULE_UNLOADED . ':released',
            (string) $staleSettings->get(SandboxLossState::SETTING_MAIL)
        );

        $this->state()->record(SandboxLossState::CHANNEL_MAIL, null, $this->journal);
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_MODULE_UNLOADED, $this->journal);

        $stale = new SandboxLossState($staleSettings);
        $stale->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_MODULE_UNLOADED, $this->journal);

        $this->assertTrue($stale->isBlocked(SandboxLossState::CHANNEL_MAIL));
        $this->assertFalse($stale->isReleased(SandboxLossState::CHANNEL_MAIL));
    }

    /**
     * Failing to remember a loss must never be what reopens real sending:
     * a row that cannot be written still blocks this request.
     */
    public function testALossThatCannotBeStoredStillBlocks(): void
    {
        $this->pdo->exec("DELETE FROM settings WHERE setting_key = '" . SandboxLossState::SETTING_MAIL . "'");

        $state = $this->state();
        $state->record(SandboxLossState::CHANNEL_MAIL, SandboxLossState::REASON_HOST_MISMATCH, $this->journal);

        $this->assertTrue($state->isBlocked(SandboxLossState::CHANNEL_MAIL));
    }

    public function testTheRowsAreInternalAndNeverListedForEditing(): void
    {
        $statement = $this->pdo->prepare('SELECT editable FROM settings WHERE module_id IS NULL AND setting_key = ?');
        foreach ([SandboxLossState::SETTING_MAIL, SandboxLossState::SETTING_TELEPHONY] as $key) {
            $statement->execute([$key]);
            $this->assertSame(0, (int) $statement->fetchColumn(), $key . ' must be registered non-editable');
        }
    }

    public function testAnUnknownReasonOrChannelIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->state()->record(SandboxLossState::CHANNEL_MAIL, 'because', $this->journal);
    }

    public function testEveryReasonHasFrenchWordsAndARemedy(): void
    {
        foreach (SandboxLossState::REASONS as $reason) {
            $this->assertNotSame('raison inconnue', SandboxLossState::reasonLabel($reason));
            $this->assertNotSame('', SandboxLossState::remedy($reason));
        }
        $this->assertSame('mail_capture_lost', SandboxLossState::lossEvent(SandboxLossState::CHANNEL_MAIL));
        $this->assertSame(
            'telephony_simulation_lost',
            SandboxLossState::lossEvent(SandboxLossState::CHANNEL_TELEPHONY)
        );
    }
}
