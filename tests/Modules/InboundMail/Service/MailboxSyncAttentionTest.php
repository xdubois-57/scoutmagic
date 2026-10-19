<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Attention\AttentionPoint;
use Core\Security\EncryptionService;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailAttentionProvider;
use Modules\InboundMail\Task\SyncMailboxesHandler;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * Issue #718 § C points 8 and 9: a box that no longer synchronises, and a
 * module switched on with no box to read — both read from what the polling
 * task stored on the row, never by connecting.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailboxSyncAttentionTest extends TestCase
{
    private InboundMessageRepository $messages;
    private InboundMailboxRepository $mailboxes;
    private InMemorySettingService $settings;
    private \PDO $pdo;
    private int $boxId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo = $pdo;
        InboundMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->messages = new InboundMessageRepository($pdo, $encryption);
        $this->mailboxes = new InboundMailboxRepository($pdo, $encryption);
        $this->settings = new InMemorySettingService();
        $this->boxId = $this->mailboxes->create('Unité', ProviderType::FAKE, 'imap.example.be', 993, 'ssl', 'unite@example.be', 's', [], true);
    }

    /** @return AttentionPoint[] */
    private function pointsAt(string $now): array
    {
        return (new InboundMailAttentionProvider(
            $this->messages,
            $this->settings,
            $this->mailboxes,
            new \DateTimeImmutable($now)
        ))->collect(1);
    }

    public function testABoxThatSynchronisesRaisesNothing(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 09:45:00'));

        $this->assertSame([], $this->pointsAt('2026-10-18 10:00:00'));
    }

    public function testABoxFailingForAnHourIsUrgent(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 08:30:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Mot de passe refusé.', new \DateTimeImmutable('2026-10-18 09:00:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Mot de passe refusé.', new \DateTimeImmutable('2026-10-18 09:45:00'));

        $this->assertSame([], $this->pointsAt('2026-10-18 09:50:00'), 'fifty minutes since the first failure');

        $points = $this->pointsAt('2026-10-18 10:00:00');
        $this->assertCount(1, $points);
        $this->assertSame('La boîte « Unité » ne se synchronise plus', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('/config/courrier-entrant', $points[0]->actionUrl);
        $this->assertStringContainsString('18/10/2026 à 08:30', $points[0]->why, 'the last success is what the reader needs');
    }

    /**
     * The grace counts from the first failure, not from the last success:
     * a box idle for hours whose first poll fails is one failed pass.
     */
    public function testAFirstFailureLongAfterTheLastSuccessRaisesNothing(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 06:00:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 13:31:00'));

        $this->assertSame([], $this->pointsAt('2026-10-18 13:32:00'));
        $this->assertCount(1, $this->pointsAt('2026-10-18 14:31:00'), 'an hour of failures is reported');
    }

    /** Switching a box back on starts its failures over. */
    public function testSwitchingABoxBackOnForgetsTheFailuresBeforeThePause(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 06:00:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 06:15:00'));
        $this->mailboxes->setEnabled($this->boxId, false);
        $this->mailboxes->setEnabled($this->boxId, true);

        $this->assertSame([], $this->pointsAt('2026-10-18 13:30:00'), 'no failure since it came back');

        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 13:31:00'));
        $this->assertSame([], $this->pointsAt('2026-10-18 13:32:00'), 'one failed pass after the pause');
    }

    /** A success ends the run: the next failure starts a new one. */
    public function testASuccessEndsTheRunOfFailures(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 06:00:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 06:15:00'));
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 06:30:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 09:00:00'));

        $this->assertSame([], $this->pointsAt('2026-10-18 09:05:00'));
    }

    /** Saving the box's form with « active » ticked is switching it on too. */
    public function testReEnablingThroughTheFormForgetsThePastFailures(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 06:00:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 06:15:00'));
        $this->mailboxes->setEnabled($this->boxId, false);
        $this->mailboxes->update($this->boxId, 'Unité', 'imap.example.be', 993, 'ssl', 'unite@example.be', [], true);

        $this->assertSame([], $this->pointsAt('2026-10-18 13:30:00'));
    }

    /** Saving an enabled box's form does not reset a run that is still going. */
    public function testSavingAnEnabledBoxKeepsItsRunOfFailures(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 06:00:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 09:00:00'));
        $this->mailboxes->update($this->boxId, 'Unité', 'imap.example.be', 993, 'ssl', 'unite@example.be', [], true);

        $this->assertCount(1, $this->pointsAt('2026-10-18 10:30:00'));
    }

    /** One failed pass is a mail host restarting, not a broken box. */
    public function testAPassingFailureRaisesNothing(): void
    {
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 09:45:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 10:00:00'));

        $this->assertSame([], $this->pointsAt('2026-10-18 10:05:00'));
    }

    /** A long interval needs two failed passes in a row, not one. */
    public function testTheGraceFollowsTheSyncInterval(): void
    {
        $this->settings->values[SyncMailboxesHandler::SETTING_INTERVAL_MINUTES] = '240';
        $this->mailboxes->recordSuccess($this->boxId, new \DateTimeImmutable('2026-10-18 06:00:00'));
        $this->mailboxes->recordFailure($this->boxId, 'Délai dépassé.', new \DateTimeImmutable('2026-10-18 10:00:00'));

        $this->assertSame([], $this->pointsAt('2026-10-18 10:30:00'), 'one failed pass of four hours');
        $this->assertCount(1, $this->pointsAt('2026-10-18 14:00:00'));
    }

    public function testABoxThatNeverWorkedIsReportedAtOnce(): void
    {
        $this->mailboxes->recordFailure($this->boxId, 'Serveur introuvable.', new \DateTimeImmutable('2026-10-18 10:00:00'));

        $points = $this->pointsAt('2026-10-18 10:01:00');

        $this->assertCount(1, $points);
        $this->assertStringContainsString('jamais été relevée avec succès', $points[0]->why);
    }

    /** A box nobody has polled yet is not in error: nothing to say. */
    public function testABoxNeverPolledRaisesNothing(): void
    {
        $this->assertSame([], $this->pointsAt('2026-10-18 10:00:00'));
    }

    public function testADisabledBoxIsNotReportedAsFailing(): void
    {
        $this->mailboxes->create('Camps', ProviderType::FAKE, 'h', 993, 'ssl', 'camps@example.be', 's', [], true);
        $this->mailboxes->recordFailure($this->boxId, 'Mot de passe refusé.', new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->mailboxes->setEnabled($this->boxId, false);

        $this->assertSame([], $this->pointsAt('2026-10-18 10:00:00'));
    }

    public function testNoEnabledBoxIsAnAttentionPoint(): void
    {
        $this->mailboxes->setEnabled($this->boxId, false);

        $points = $this->pointsAt('2026-10-18 10:00:00');

        $this->assertCount(1, $points);
        $this->assertSame("Aucune boîte de courrier entrant n'est relevée", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    /**
     * The page is also read by roles that may not see the box's settings
     * (§7.4): its name, never its host, account or the stored error.
     */
    public function testThePointGivesNeitherTheHostNorTheAccountNorTheError(): void
    {
        $this->mailboxes->recordFailure($this->boxId, 'Mot de passe refusé.', new \DateTimeImmutable('2026-10-18 10:00:00'));

        $point = $this->pointsAt('2026-10-18 12:00:00')[0];
        $text = $point->title . ' ' . $point->why;

        $this->assertStringNotContainsString('example.be', $text);
        $this->assertStringNotContainsString('Mot de passe', $text);
    }

    /**
     * The page reads the sync state without decrypting anything: a box
     * whose stored account cannot be decrypted (a rotated key, a damaged
     * row) still says it is failing, and takes no other point down with it.
     */
    public function testAnUndecryptableAccountStillReportsTheBox(): void
    {
        $this->mailboxes->recordFailure($this->boxId, 'Mot de passe refusé.', new \DateTimeImmutable('2026-10-18 10:00:00'));
        $this->pdo->prepare('UPDATE inbound_mailboxes SET username_encrypted = ? WHERE id = ?')
            ->execute(['illisible', $this->boxId]);

        $points = $this->pointsAt('2026-10-18 12:00:00');

        $this->assertCount(1, $points);
        $this->assertSame('La boîte « Unité » ne se synchronise plus', $points[0]->title);
    }

    /** Without the box repository the provider is what it was: messages only. */
    public function testWithoutTheBoxesOnlyTheMessagesCount(): void
    {
        $this->mailboxes->setEnabled($this->boxId, false);

        $this->assertSame([], (new InboundMailAttentionProvider($this->messages))->collect(1));
    }
}
