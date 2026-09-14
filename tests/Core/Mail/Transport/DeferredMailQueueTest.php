<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\MailPurpose;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\DeferredMessage;
use Core\Mail\Transport\MailLane;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The deferral queue: which lanes defer, how long a message keeps
 * trying, and what is left on disk afterwards (D9, D16, D18).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeferredMailQueueTest extends TestCase
{
    private \PDO $pdo;
    private DeferredMailRepository $repository;
    private DeferredMailQueue $queue;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new DeferredMailRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->queue = new DeferredMailQueue($this->repository, $this->settings);

        // Registered here as the composition root registers them: both are
        // ordinary settings, editable from Configuration > Réglages, so a
        // test that writes one has to declare it first.
        $this->settings->register(
            DeferredMailQueue::SETTING_LIFETIME_HOURS,
            (string) DeferredMailQueue::DEFAULT_LIFETIME_HOURS,
            'number',
            'Durée de vie',
            'Test',
            null,
            '^[1-9][0-9]*$'
        );
        $this->settings->register(
            DeferredMailQueue::SETTING_ABANDONED_RETENTION_DAYS,
            (string) DeferredMailQueue::DEFAULT_ABANDONED_RETENTION_DAYS,
            'number',
            'Rétention',
            'Test',
            null,
            '^[1-9][0-9]*$'
        );
    }

    /**
     * **The decision D9 is most emphatic about.** A magic link delivered
     * tomorrow is not a magic link: the token lives fifteen minutes, and
     * the person is standing at a login form waiting to be told the
     * truth rather than reassured about later.
     */
    public function testTheAuthenticationLaneNeverDefers(): void
    {
        $this->assertFalse($this->queue->defers(MailLane::Authentication));
        $this->assertFalse(
            $this->queue->defer(MailLane::Authentication, MailPurpose::MagicLink, $this->payload(), 'tout est tombé')
        );
        $this->assertSame([], $this->repository->pendingCountByLane());
    }

    public function testTheOtherTwoLanesDefer(): void
    {
        $this->assertTrue($this->queue->defers(MailLane::Transactional));
        $this->assertTrue($this->queue->defers(MailLane::Bulk));

        $this->assertTrue(
            $this->queue->defer(MailLane::Transactional, MailPurpose::Ordinary, $this->payload(), 'quota épuisé')
        );
        $this->assertTrue($this->queue->defer(MailLane::Bulk, MailPurpose::Bulk, $this->payload(), 'quota épuisé'));

        $this->assertSame(
            ['bulk' => 1, 'transactional' => 1],
            $this->sorted($this->repository->pendingCountByLane())
        );
    }

    /**
     * What the queue holds is the CALL, and it survives the round trip
     * intact — including an attachment, whose bytes are carried because
     * its path will not exist tomorrow.
     */
    public function testTheMessageComesBackWhole(): void
    {
        $payload = $this->payload();
        $payload['attachments'] = [['name' => 'recu.pdf', 'content' => "%PDF-1.4\x00binary"]];
        $this->queue->defer(MailLane::Transactional, MailPurpose::Ordinary, $payload, 'raison');

        $due = $this->repository->due(10, '2099-01-01 00:00:00');

        $this->assertCount(1, $due);
        $this->assertSame('parent@exemple.test', $due[0]->payload['to']);
        $this->assertSame('Reçu de paiement', $due[0]->payload['subject']);
        $this->assertSame("%PDF-1.4\x00binary", $due[0]->payload['attachments'][0]['content']);
        $this->assertSame(['X-Custom' => 'v'], $due[0]->payload['extraHeaders']);
    }

    /**
     * D18: the body and the recipient are personal data, so what is on
     * disk is ciphertext. Asserted against the raw column rather than
     * through the repository, because the repository is the thing under
     * suspicion.
     */
    public function testWhatIsStoredIsCiphertext(): void
    {
        $this->queue->defer(MailLane::Transactional, MailPurpose::Ordinary, $this->payload(), 'raison');

        $stored = (string) $this->pdo->query('SELECT payload_encrypted FROM mail_deferred_messages')->fetchColumn();

        $this->assertStringNotContainsString('parent@exemple.test', $stored);
        $this->assertStringNotContainsString('Reçu de paiement', $stored);
        $this->assertStringNotContainsString('Bonjour', $stored);
    }

    /** The first retry is soon; the later ones are not (D16). */
    public function testTheRetryDelayGrows(): void
    {
        $this->queue->defer(MailLane::Bulk, MailPurpose::Bulk, $this->payload(), 'raison');
        $message = $this->repository->due(1, '2099-01-01 00:00:00')[0];

        $delays = [];
        foreach ([0, 1, 2, 3, 4, 5] as $attempts) {
            $at = $this->queue->nextAttemptFor(
                $this->withAttempts($message, $attempts, expiresAt: '2099-01-01 00:00:00'),
                null,
                '2026-09-13 10:00:00'
            );
            $delays[] = (int) round((strtotime((string) $at) - strtotime('2026-09-13 10:00:00')) / 60);
        }

        $this->assertSame([5, 15, 45, 120, 240, 240], $delays);
    }

    /**
     * A message whose next try would land past its own deadline has no
     * next try. Scheduling one anyway would leave the queue claiming it
     * still intends to send something it would refuse on arrival.
     */
    public function testAMessageOutOfTimeGetsNoFurtherAttempt(): void
    {
        $this->queue->defer(MailLane::Bulk, MailPurpose::Bulk, $this->payload(), 'raison');
        $message = $this->repository->due(1, '2099-01-01 00:00:00')[0];

        $this->assertNull($this->queue->nextAttemptFor(
            $this->withAttempts($message, 0, expiresAt: '2026-09-13 10:02:00'),
            null,
            '2026-09-13 10:00:00'
        ));
    }

    /**
     * The deadline is fixed when the message is queued, so lengthening
     * the setting later never resurrects something already given up on.
     */
    public function testTheDeadlineIsFixedWhenTheMessageIsQueued(): void
    {
        $this->settings->set(DeferredMailQueue::SETTING_LIFETIME_HOURS, '2');
        $this->queue->defer(MailLane::Bulk, MailPurpose::Bulk, $this->payload(), 'raison');
        $queued = $this->repository->due(1, '2099-01-01 00:00:00')[0];

        $this->settings->set(DeferredMailQueue::SETTING_LIFETIME_HOURS, '48');

        $this->assertSame(
            $queued->expiresAt,
            $this->repository->due(1, '2099-01-01 00:00:00')[0]->expiresAt
        );
    }

    /**
     * Above the ceiling the message is NOT queued — it fails as it would
     * have before. Queueing something that will drain without its
     * attachment would be worse than refusing loudly.
     */
    public function testAMessageTooHeavyToCarryIsNotDeferred(): void
    {
        $payload = $this->payload();
        $payload['attachments'] = [[
            'name' => 'camp.zip',
            'content' => str_repeat('x', DeferredMailQueue::MAX_ATTACHMENT_BYTES + 1),
        ]];

        $this->assertFalse($this->queue->defer(MailLane::Bulk, MailPurpose::Bulk, $payload, 'raison'));
        $this->assertSame([], $this->repository->pendingCountByLane());
    }

    /**
     * The Relance dialog buckets rather than counts (D17), **and the age
     * it buckets on is the age of the failure**, not of the message. A
     * message is only given up on some twenty hours after it was queued,
     * so reading `created_at` would drop every abandoned message straight
     * into « more than a day » and leave the two recent buckets
     * permanently empty — including the six-hour window the dialog
     * offers by default.
     */
    public function testAbandonedMessagesAreBucketedByAge(): void
    {
        $this->abandonAged('2026-09-13 09:00:00');
        $this->abandonAged('2026-09-12 20:00:00');
        $this->abandonAged('2026-09-10 10:00:00');
        $this->abandonAged('2026-09-01 10:00:00');

        $buckets = $this->queue->abandonedByAge(null, '2026-09-13 12:00:00');

        $this->assertSame(1, $buckets['recent'], 'under six hours');
        $this->assertSame(1, $buckets['day'], 'under a day');
        $this->assertSame(1, $buckets['week'], 'under a week');
        $this->assertSame(1, $buckets['older']);
        $this->assertSame(4, $buckets['total']);
    }

    /**
     * **The window the dialog offers by default has to be able to match
     * something.** It is measured from the moment the site gave up, and
     * that is twenty hours after the message was queued at the earliest —
     * so a window read against `created_at` would relaunch nothing at
     * all, every time, and the button would be quietly useless.
     */
    public function testTheShortestWindowRelaunchesThisMorningsFailures(): void
    {
        $justAbandoned = $this->abandonAged('2026-09-13 09:00:00');
        $yesterday = $this->abandonAged('2026-09-12 09:00:00');

        $revived = $this->queue->relaunch(
            [MailLane::Bulk],
            DeferredMailQueue::DEFAULT_WINDOW,
            '2026-09-13 12:00:00'
        );

        $this->assertSame(1, $revived);
        $this->assertSame([$yesterday], $this->repository->abandonedIds());
        $this->assertSame([$justAbandoned], array_map(
            static fn($message): int => $message->id,
            $this->repository->due(10, '2099-01-01 00:00:00')
        ));
    }

    /** And the default really is the shortest one the dialog offers. */
    public function testTheDefaultWindowIsTheShortestOne(): void
    {
        $this->assertSame(
            min(DeferredMailQueue::WINDOWS),
            DeferredMailQueue::WINDOWS[DeferredMailQueue::DEFAULT_WINDOW]
        );
    }

    /** Purging an abandoned message takes its body with it (D18). */
    public function testPurgingAnAbandonedMessageTakesItsBody(): void
    {
        $this->abandonAged('2026-08-01 10:00:00');

        $purged = $this->repository->purgeAbandonedBefore('2026-09-01 00:00:00');

        $this->assertSame(1, $purged);
        $this->assertSame(0, $this->repository->countAbandoned());
        $this->assertSame(
            '0',
            (string) $this->pdo->query('SELECT COUNT(*) FROM mail_deferred_messages')->fetchColumn()
        );
    }

    /**
     * **One unreadable row must not stop the queue.** `due()` orders by
     * date, so a row that threw on decryption would be first again on
     * every later pass and the whole drain — the purge with it — would
     * stop for good. It is abandoned instead: a message whose contents
     * cannot be read can never be sent, and saying so is the only honest
     * thing left to do with it.
     */
    public function testAnUnreadableRowIsAbandonedRatherThanBlockingTheQueue(): void
    {
        $this->queue->defer(MailLane::Bulk, MailPurpose::Bulk, $this->payload(), 'raison');
        $good = $this->queue->defer(MailLane::Transactional, MailPurpose::Ordinary, $this->payload(), 'raison');
        $this->assertTrue($good);

        // Damage the first row's ciphertext, exactly as a key change or a
        // truncating column would.
        $this->pdo->exec("UPDATE mail_deferred_messages SET payload_encrypted = 'n’importe quoi' WHERE id = 1");

        $due = $this->repository->due(10, '2099-01-01 00:00:00');

        $this->assertCount(1, $due, 'The readable message still comes back.');
        $this->assertSame('transactional', $due[0]->lane->value);
        $this->assertSame(1, $this->repository->countAbandoned());
    }

    /**
     * @return array{
     *     to: string, subject: string, bodyHtml: string, bodyText: string,
     *     replyTo: ?string, fromAddressOverride: ?string, fromNameOverride: ?string,
     *     extraHeaders: array<string, string>,
     *     attachments: array<int, array{name: string, content: string}>
     * }
     */
    private function payload(): array
    {
        return [
            'to' => 'parent@exemple.test',
            'subject' => 'Reçu de paiement',
            'bodyHtml' => '<p>Bonjour</p>',
            'bodyText' => 'Bonjour',
            'replyTo' => null,
            'fromAddressOverride' => null,
            'fromNameOverride' => null,
            'extraHeaders' => ['X-Custom' => 'v'],
            'attachments' => [],
        ];
    }

    private function withAttempts(DeferredMessage $message, int $attempts, string $expiresAt): DeferredMessage
    {
        return new DeferredMessage(
            $message->id,
            $message->lane,
            $message->purpose,
            $message->payload,
            $message->status,
            $attempts,
            $message->lastReason,
            $message->nextAttemptAt,
            $expiresAt,
            $message->createdAt,
            $message->settledAt
        );
    }

    /**
     * One abandoned message, given up on at `$settledAt`.
     *
     * `created_at` is set a day EARLIER on purpose, which is what a real
     * row looks like: nothing is abandoned until its deadline has passed.
     * A helper that made the two timestamps equal — as this one used to —
     * would let a bucket or a window read the wrong column and still pass.
     */
    private function abandonAged(string $settledAt): int
    {
        $queuedAt = date('Y-m-d H:i:s', strtotime($settledAt) - 86400);

        $id = $this->repository->add(
            MailLane::Bulk,
            MailPurpose::Bulk,
            $this->payload(),
            'raison',
            $queuedAt,
            $settledAt
        );
        $this->repository->abandon($id, 3, 'expiré', $settledAt);
        $this->pdo->prepare('UPDATE mail_deferred_messages SET created_at = ? WHERE id = ?')
            ->execute([$queuedAt, $id]);

        return $id;
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
