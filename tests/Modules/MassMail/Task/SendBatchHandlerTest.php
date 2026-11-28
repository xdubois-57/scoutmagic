<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Mail\Transport\BulkCadence;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\TargetEmailLink;
use Modules\MassMail\Task\SendBatchHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class SendBatchHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private DeliveryRepository $deliveryRepository;
    private TargetRepository $targetRepository;
    /** The fixture member's one target in the fixture email. */
    private int $targetId;
    private UserAccountRepository $userAccountRepository;
    private int $emailId;
    private int $memberId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->deliveryRepository = new DeliveryRepository($this->pdo, $this->encryption);
        $this->targetRepository = new TargetRepository($this->pdo);
        $this->userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet', '<p>Corps</p>', {$sectionId}, 'default_active_members', '" . Email::STATUS_SENDING . "')"
        );
        $this->emailId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        // One member, three addresses: one target, three messages, each
        // linked to it (issue #999).
        $this->targetId = $this->targetRepository->create($this->emailId, $this->memberId, $this->scoutYearId);
        for ($i = 0; $i < 3; $i++) {
            $deliveryId = $this->deliveryRepository->create($this->emailId, "member{$i}@test.be");
            $this->deliveryRepository->linkTargets($deliveryId, [$this->targetId]);
        }

        // How fast the mailing lane may go is a property of the relay
        // carrying it, not a setting of this module any more (D6,
        // ARCHITECTURE.md §8.106) — so the fixture is a provider on the
        // « Masse » lane whose cadence is two messages every five
        // minutes, which is exactly what the two removed settings used to
        // say.
        $providers = new MailProviderRepository($this->pdo);
        $chains = new LaneChainRepository($this->pdo);
        $providerId = $providers->create('Relais de test', null, 2, 5);
        $chains->append(MailLane::Bulk, $providerId, true);
        $this->bulkCadence = new BulkCadence(
            new MailProviderDirectory(
                $providers,
                new ProviderConnections([
                    ProviderConnections::prefixFor($providerId) . '_host' => 'smtp.test',
                ]),
                new SettingService(new SettingRepository($this->pdo))
            ),
            $chains,
            new SendCounterRepository($this->pdo)
        );
    }

    private ?BulkCadence $bulkCadence = null;

    /**
     * The fixture member's row for the year, at $email — the address the
     * message went to is theirs, which is what lets the account behind it
     * see the entry (MemberService::canAccess()).
     */
    private function giveTheMemberAYearAt(string $email, ?int $memberId = null, string $firstName = 'Jean'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId ?? $this->memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── Le « De : » qui part vraiment (issue #418) ─────────────────────

    /**
     * **The seed copy's `mailto:` unsubscribe, which had no address to name
     * in two cases out of three.** It was built from the section's address,
     * and that is null both for a section with no e-mail and — since issue
     * #418 — for one the site cannot sign for, so the header went out as
     * `<mailto:?subject=unsubscribe>`.
     *
     * That is not cosmetic here. This copy exists to measure where a
     * mailing lands, and a malformed `List-Unsubscribe` is exactly the
     * bulk-sender signal a provider weighs: the copy would have biased the
     * reading it was sent to take, in the direction that triggers a
     * reroute.
     *
     * @return ?\Core\Mail\Feedback\Seed\SeedCopyContent
     */
    private function seedCopyOfOneSend(): ?\Core\Mail\Feedback\Seed\SeedCopyContent
    {
        $this->pdo->exec('DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)');

        $copy = null;
        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->once())
            ->method('send')
            // The address is named rather than swallowed by `...$args`:
            // `send()` takes it first, and an expectation that never reads it
            // would pass while the batch wrote to somebody else
            // (`Tests\Architecture\MailDoublesNameTheirRecipientTest`).
            ->willReturnCallback(function (string $to, ...$rest) use (&$copy): void {
                self::assertSame('member0@test.be', $to, 'the one recipient left standing in this fixture');
                // One place further left than before, the address having been
                // taken out of the variadic.
                $copy = $rest[11] ?? null;
            });

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        return $copy;
    }

    public function testTheSeedCopyNamesAnAddressToUnsubscribeFromEvenWhenTheFromIsSubstituted(): void
    {
        $this->giveTheSectionTheAddress('meute-a@telenet.be');

        $copy = $this->seedCopyOfOneSend();

        $this->assertNotNull($copy);
        $this->assertSame(
            '<mailto:meute-a@telenet.be?subject=unsubscribe>',
            $copy->extraHeaders['List-Unsubscribe'] ?? null,
            'the substituted From: must not empty the header the copy is measured on.'
        );
    }

    public function testTheSeedCopyFallsBackToTheSitesAddressWhenTheSectionHasNone(): void
    {
        // The fixture's section carries no e-mail (see setUp).
        $copy = $this->seedCopyOfOneSend();

        $this->assertNotNull($copy);
        $this->assertSame(
            '<mailto:unite@test.be?subject=unsubscribe>',
            $copy->extraHeaders['List-Unsubscribe'] ?? null
        );
    }

    /**
     * **`&$seen` and not a returned array.** List destructuring assigns by
     * value, so returning `[&$seen, $mailService]` handed the test a
     * snapshot of the empty array and every assertion read nothing — which
     * is how this helper was written first, and it failed loudly rather
     * than passing on an empty capture.
     *
     * @param list<array{reply_to: ?string, from: ?string, name: ?string, headers: array<string, string>}> $seen
     */
    private function capturingMailService(array &$seen): MailService
    {
        $seen = [];
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willReturnCallback(
            function (
                string $to,
                string $subject,
                string $bodyHtml,
                string $bodyText = '',
                ?string $replyTo = null,
                array $attachments = [],
                ?string $fromAddressOverride = null,
                ?string $fromNameOverride = null,
                array $extraHeaders = []
            ) use (&$seen): void {
                $seen[] = [
                    'reply_to' => $replyTo,
                    'from' => $fromAddressOverride,
                    'name' => $fromNameOverride,
                    'headers' => $extraHeaders,
                ];
            }
        );

        return $mailService;
    }

    private function giveTheSectionTheAddress(string $address): void
    {
        $statement = $this->pdo->prepare('UPDATE sections SET email = ? WHERE id = 1');
        $statement->execute([$address]);
    }

    /**
     * A section on the site's own domain keeps its own `From:` — the
     * behaviour issue #418 explicitly left alone — and carries no
     * `Reply-To:`, because the `From:` is already where a reply should go.
     */
    public function testASectionTheSiteCanSignForKeepsItsOwnFromAndNeedsNoReplyTo(): void
    {
        $this->giveTheSectionTheAddress('meute-a@test.be');
        $seen = [];
        $mailService = $this->capturingMailService($seen);

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $this->assertNotSame([], $seen);
        $this->assertSame('meute-a@test.be', $seen[0]['from']);
        $this->assertSame('Meute A', $seen[0]['name']);
        $this->assertNull($seen[0]['reply_to']);
    }

    /**
     * **And the case the site cannot sign for, which used to go out and be
     * refused.** `telenet.be` publishes `p=reject`, so a mailing sent as
     * `meute-a@telenet.be` over this site's envelope and DKIM key was thrown
     * away at the far end with every screen here green — and no aggregate
     * report would ever have said so, since it goes to the From domain's
     * `rua=`, i.e. to Telenet.
     *
     * So: the `From:` is the site's own (a null override), the section is
     * still named to the recipient, and `Reply-To:` keeps « Répondre »
     * arriving at the section exactly as before.
     */
    public function testASectionTheSiteCannotSignForSendsUnderTheSiteWithTheSectionInReplyTo(): void
    {
        $this->giveTheSectionTheAddress('meute-a@telenet.be');
        $seen = [];
        $mailService = $this->capturingMailService($seen);

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $this->assertNotSame([], $seen);
        $this->assertNull($seen[0]['from'], 'the From: must be an address this site can sign for');
        $this->assertSame('Meute A (Test Unité)', $seen[0]['name']);
        $this->assertSame('meute-a@telenet.be', $seen[0]['reply_to']);
    }

    /**
     * A `MailService` double that answers `getDefaultSender()` the way the
     * real one does.
     *
     * **An unconfigured mock answers `[]`, and that is not a shape this
     * method ever returns**: `MailService::getDefaultSender()` builds it
     * from two string properties. Since issue #418 the mailing reads the
     * site's own address out of it — to decide whether it can sign for a
     * section's — and a null there quietly produced the malformed
     * `<mailto:?subject=unsubscribe>` header that this very file is here to
     * catch. One helper rather than twenty-two call sites, so the next
     * double cannot forget.
     */
    private function mailServiceDouble(): MailService
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('getDefaultSender')
            ->willReturn(['address' => 'unite@test.be', 'name' => 'Test Unité']);

        return $mailService;
    }

    private function buildContext(MailService $mailService): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir(),
            null,
            null,
            $this->bulkCadence
        );
    }

    private function buildContextWithNotifications(MailService $mailService, NotificationService $notificationService): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir(),
            $notificationService,
            null,
            $this->bulkCadence
        );
    }

    public function testProcessesOnlyBatchSizeRecipientsAndReschedulesWhenPendingRemain(): void
    {
        // **Which two, not merely two** (issue #439). A batch that sent the
        // first copy twice, or sent to the third recipient and left the
        // first pending, satisfies a count of two exactly as well — and a
        // mailing is the one feature here whose whole subject is who
        // receives what.
        $sentTo = [];
        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->exactly(2))->method('send')
            ->willReturnCallback(function (string $to) use (&$sentTo): void {
                $sentTo[] = $to;
            });

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $this->assertSame(
            ['member0@test.be', 'member1@test.be'],
            $sentTo,
            'the batch is the FIRST two pending recipients, in order, each exactly once'
        );

        $counts = $this->deliveryRepository->countGroupedByStatus($this->emailId);
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(1, $counts['pending']);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * An email imported from a former site is never sent from here (D11):
     * its import writes no pending message, and « Renvoyer » is refused. A
     * pending one could only be a fault, and it fails explicitly — neither
     * sent nor left to block the queue.
     */
    public function testAPendingMessageOfAnImportedEmailIsFailedRatherThanSent(): void
    {
        $this->pdo->prepare(
            "UPDATE mass_mail_emails SET list_type = 'imported', imported_at = '2026-11-09 10:00:00' WHERE id = ?"
        )->execute([$this->emailId]);

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $stmt = $this->pdo->prepare('SELECT DISTINCT status FROM mass_mail_deliveries ORDER BY status');
        $stmt->execute();
        $statuses = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('error', $statuses);
        $this->assertNotContains('sent', $statuses);
        $stmt = $this->pdo->prepare('SELECT error_message FROM mass_mail_deliveries WHERE status = ? LIMIT 1');
        $stmt->execute(['error']);
        $this->assertSame('Courriel importé, jamais envoyé', $stmt->fetchColumn());
    }

    /**
     * Issue #1437: the freeze read the suppression list once. A recipient
     * who unsubscribes between the freeze and their batch is not written
     * to: the message is not sent, its line carries the freeze's own
     * reason, and the journal says so — with no address.
     */
    public function testAnAddressUnsubscribedAfterTheFreezeIsNotSent(): void
    {
        (new \Modules\MassMail\Repository\SuppressedAddressRepository($this->pdo))->suppress('member0@test.be');

        $sentTo = [];
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willReturnCallback(function (string $to) use (&$sentTo): void {
            $sentTo[] = $to;
        });
        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $this->assertNotContains('member0@test.be', $sentTo);
        $skipped = array_values(array_filter(
            $this->deliveryRepository->findByEmailId($this->emailId),
            fn(Delivery $d) => $d->emailAddress === 'member0@test.be'
        ));
        $this->assertSame(Delivery::STATUS_ERROR, $skipped[0]->status);
        $this->assertSame('Adresse désinscrite des emails groupés', $skipped[0]->errorMessage);

        $rows = $this->pdo->query(
            "SELECT level, context, description FROM event_log
             WHERE category = 'mass_mail' AND event_type = 'delivery_skipped_unsubscribed'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('info', $rows[0]['level']);
        $context = json_decode((string) $rows[0]['context'], true);
        ksort($context);
        $this->assertSame(['delivery_id' => $skipped[0]->id, 'email_id' => $this->emailId], $context);
        $this->assertStringNotContainsString('@', (string) json_encode($rows));
        $this->assertStringStartsWith('Email groupé #' . $this->emailId . ' ', (string) $rows[0]['description']);
    }

    /**
     * Issue #1437, with #1032: an address reactivated after unsubscribing
     * is off the list again, and its queued message leaves as usual.
     */
    public function testAReactivatedAddressIsSentAgain(): void
    {
        $suppressed = new \Modules\MassMail\Repository\SuppressedAddressRepository($this->pdo);
        $suppressed->suppress('member0@test.be');
        $suppressed->reactivate('member0@test.be');

        $sentTo = [];
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willReturnCallback(function (string $to) use (&$sentTo): void {
            $sentTo[] = $to;
        });
        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $this->assertContains('member0@test.be', $sentTo);
    }

    public function testDoesNotRescheduleWhenNoPendingRemain(): void
    {
        // Shrink to 1 recipient so a single batch (size 2) drains everything.
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");

        $mailService = $this->mailServiceDouble();
        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    /**
     * **A receipt is not worth a mailing.**
     *
     * The bounce receipt is stamped after the copy has left and after
     * `recordSendSuccess()` is committed, and the only handler around
     * that loop catches `MailException`. So anything else thrown while
     * stamping — a `DecryptionException` on a row encrypted under a
     * rotated key, a `\ValueError` from a stored category that is no
     * longer a case, a `\PDOException` — escaped the loop outright.
     * `rescheduleIfPendingRemain()` never ran, and nothing else
     * reschedules a failed `send_batch`: every recipient still pending
     * stayed pending until somebody noticed and restarted the mailing by
     * hand.
     *
     * The missing table below stands in for that whole family: what
     * matters is that the failure is unrelated to the send that has
     * already succeeded.
     */
    public function testAReceiptThatCannotBeStampedDoesNotStrandTheRestOfTheMailing(): void
    {
        // `recordSend()` is called with `vouchedFor: true` here, so it
        // skips the `isOnFile()` lookup and goes straight to stamping —
        // which is the write this removes the ground from under.
        $this->pdo->exec('DROP TABLE mail_send_receipts');

        $sentTo = [];
        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->exactly(2))->method('send')
            ->willReturnCallback(function (string $to) use (&$sentTo): void {
                $sentTo[] = $to;
            });

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $this->assertSame(
            ['member0@test.be', 'member1@test.be'],
            $sentTo,
            'the receipt failing must not change WHO the batch wrote to'
        );

        $counts = $this->deliveryRepository->countGroupedByStatus($this->emailId);
        $this->assertSame(2, $counts['sent'], 'both copies left, so both are sent whatever the receipt did.');
        $this->assertSame(1, $counts['pending']);

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' "
            . "AND task_key = 'send_batch' AND status = 'pending'"
        );
        $this->assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'the next batch is still scheduled — without it the last recipient waits for a human.'
        );
    }

    /**
     * **The mailing has to vouch for its own recipients**, and it did not.
     *
     * `MailService::send()`'s suppression gate is conditioned entirely on
     * `$vouchesForRecipient`, and the parameter's own docblock names the
     * three call sites that should set it: « une notification à un membre,
     * un document envoyé à la personne qu'il concerne, un publipostage ».
     * The first two passed `true`. The mailing — the third — did not, so
     * the live gate never fired for mass mail at all.
     *
     * The freeze filters blocked addresses once, when the mailing is
     * queued. A batch then drains over a cadence spanning hours, and an
     * address blocked DURING that run — typically by bouncing an earlier
     * batch of this very mailing — kept receiving every later batch, the
     * only remaining check being that stale snapshot.
     */
    public function testEveryCopyVouchesForItsRecipientSoTheLiveGateApplies(): void
    {
        $seen = [];
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willReturnCallback(
            function (
                string $to,
                string $subject,
                string $bodyHtml,
                string $bodyText = '',
                ?string $replyTo = null,
                array $attachments = [],
                ?string $fromAddressOverride = null,
                ?string $fromNameOverride = null,
                array $extraHeaders = [],
                \Core\Mail\MailPurpose $purpose = \Core\Mail\MailPurpose::Ordinary,
                bool $vouchesForRecipient = false
            ) use (&$seen): void {
                $seen[] = $vouchesForRecipient;
            }
        );

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $this->assertNotSame([], $seen);
        $this->assertSame(
            array_fill(0, count($seen), true),
            $seen,
            'a copy that does not vouch walks straight past the suppression gate.'
        );
    }

    /**
     * And when that gate does fire mid-run, the tracking page keeps one
     * vocabulary: the same sentence the freeze writes for an address
     * already blocked when the mailing was queued. The exception's own
     * message is written for the MEMBER — it names their address page —
     * and this column is read by staff.
     */
    public function testAnAddressBlockedMidRunIsRecordedInThePagesOwnWords(): void
    {
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')
            ->willThrowException(\Core\Mail\SuppressedRecipientException::blocked());

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $recipients = $this->deliveryRepository->findByEmailId($this->emailId);
        $errored = array_values(array_filter($recipients, fn(Delivery $r) => $r->status === Delivery::STATUS_ERROR));
        $this->assertNotEmpty($errored);

        foreach ($errored as $recipient) {
            $this->assertSame('Adresse suspendue après des refus répétés', $recipient->errorMessage);
            $this->assertStringNotContainsString('@test.be', (string) $recipient->errorMessage);
        }
    }

    public function testMailExceptionMarksRecipientAsErrorWithoutLeakingAddress(): void
    {
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willThrowException(new MailException('550 relay denied'));

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $recipients = $this->deliveryRepository->findByEmailId($this->emailId);
        $errored = array_values(array_filter($recipients, fn(Delivery $r) => $r->status === Delivery::STATUS_ERROR));
        $this->assertNotEmpty($errored);
        foreach ($errored as $recipient) {
            $this->assertStringNotContainsString('@test.be', (string) $recipient->errorMessage);
        }
    }

    /**
     * The stored value is rendered, much later and with no catch block in
     * between, by views/tracking.html.twig — so the sanitising has to happen
     * at the WRITE. It used to store Core\Mail\MailException's message
     * verbatim, i.e. PHPMailer's ErrorInfo: raw SMTP English, on a page a
     * chief reads.
     */
    public function testASendFailureStoresAFrenchSentenceRatherThanThePhpMailerText(): void
    {
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willThrowException(new MailException(
            'SMTP Error: data not accepted. SMTP server error: 554 5.7.1 <x@test.be>: Relay access denied'
        ));

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $errored = array_values(array_filter(
            $this->deliveryRepository->findByEmailId($this->emailId),
            fn(Delivery $r) => $r->status === Delivery::STATUS_ERROR
        ));
        $this->assertNotEmpty($errored);
        foreach ($errored as $recipient) {
            $stored = (string) $recipient->errorMessage;
            $this->assertStringNotContainsString('SMTP', $stored);
            $this->assertStringNotContainsString('Relay access denied', $stored);
            $this->assertStringNotContainsString('5.7.1', $stored);
            $this->assertSame("Échec de l'envoi — voir le journal pour le détail technique.", $stored);
        }

    }

    /**
     * The other half of the same rule: sanitising the stored value must not
     * lose the transport error, which is the only thing that tells an admin
     * why nothing went out.
     */
    public function testASendFailureStillJournalsTheRealTransportError(): void
    {
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willThrowException(new MailException('554 5.7.1 Relay access denied'));

        $journal = $this->createStub(JournalService::class);
        $logged = [];
        $journal->method('log')->willReturnCallback(
            function (string $category, string $type, string $level, string $description, array $context = [], ?int $userId = null) use (&$logged): void {
                $logged[] = ['type' => $type, 'context' => $context];
            }
        );

        $handler = new SendBatchHandler();
        $handler->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir()
        ));

        $failures = array_values(array_filter($logged, fn(array $e) => $e['type'] === 'delivery_send_failed'));
        $this->assertNotEmpty($failures);
        $this->assertSame('554 5.7.1 Relay access denied', $failures[0]['context']['mail_error']);
    }

    /**
     * « Lot d'emails de masse envoyé — 6 envoyés, 1 erreur » is not a
     * trace of anything: a batch spans several emails at once, so it
     * names no mailing, no recipient and no reason. Every copy that
     * leaves now writes its own line, keyed on the same ids the tracking
     * page uses — and the email id sits in the DESCRIPTION, because that
     * is the only column /admin/journal's search box looks at.
     */
    public function testEveryCopyThatLeavesWritesItsOwnJournalLine(): void
    {
        $logged = [];
        $journal = $this->createStub(JournalService::class);
        $journal->method('log')->willReturnCallback(
            function (string $category, string $type, string $level, string $description, array $context = [], ?int $userId = null) use (&$logged): void {
                $logged[] = ['type' => $type, 'level' => $level, 'description' => $description, 'context' => $context];
            }
        );

        $handler = new SendBatchHandler();
        $handler->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->mailServiceDouble(),
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir(),
            null,
            null,
            $this->bulkCadence
        ));

        $sent = array_values(array_filter($logged, fn(array $e) => $e['type'] === 'delivery_sent'));
        // The « Masse » lane's provider sends two per lot (setUp()), and
        // this run sends that lot.
        $this->assertCount(2, $sent);
        foreach ($sent as $entry) {
            $this->assertSame('info', $entry['level']);
            $this->assertStringContainsString('#' . $this->emailId, $entry['description']);
            $this->assertSame($this->emailId, $entry['context']['email_id']);
            // The members the message concerns — never its address.
            $this->assertSame([$this->memberId], $entry['context']['member_ids']);
            $this->assertNotNull($entry['context']['delivery_id']);
            // SECURITY.md §11 — never the address, on the success path
            // any more than on the failure one.
            $this->assertStringNotContainsString('@', $entry['description']);
            $this->assertStringNotContainsString('@', (string) json_encode($entry['context']));
        }
    }

    /**
     * A transport failure is filed as `error`, the same word the
     * recipient row and the tracking page already use for it. It used to
     * be `info`, which is invisible to the one reader who arrives at
     * /admin/journal having filtered on « Erreur » to find exactly this.
     */
    public function testASendFailureIsFiledAsAnErrorAndNamesItsMailing(): void
    {
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willThrowException(new MailException('554 5.7.1 Relay access denied'));

        $logged = [];
        $journal = $this->createStub(JournalService::class);
        $journal->method('log')->willReturnCallback(
            function (string $category, string $type, string $level, string $description, array $context = [], ?int $userId = null) use (&$logged): void {
                $logged[] = ['type' => $type, 'level' => $level, 'description' => $description];
            }
        );

        $handler = new SendBatchHandler();
        $handler->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir()
        ));

        $failures = array_values(array_filter($logged, fn(array $e) => $e['type'] === 'delivery_send_failed'));
        $this->assertNotEmpty($failures);
        $this->assertSame('error', $failures[0]['level']);
        $this->assertStringContainsString('#' . $this->emailId, $failures[0]['description']);
    }

    /**
     * Module addendum (RFC 8058 one-click unsubscribe): every send must
     * carry both headers plus a human-facing footer link, and the
     * recipient row must end up with a verifiable token — never a bare
     * member/email id trusted from the URL alone.
     */
    public function testEverySendIncludesListUnsubscribeHeadersFooterAndAToken(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");
        $recipientBefore = $this->deliveryRepository->findByEmailId($this->emailId)[0];

        $capturedTo = null;
        $capturedExtraHeaders = null;
        $capturedBodyHtml = null;
        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->once())
            ->method('send')
            ->willReturnCallback(
                function (...$args) use (&$capturedTo, &$capturedExtraHeaders, &$capturedBodyHtml): void {
                    $capturedTo = $args[0];
                    $capturedBodyHtml = $args[2];
                    $capturedExtraHeaders = $args[8] ?? null;
                }
            );

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        // The address, captured with the rest: an unsubscribe token minted
        // for one recipient and mailed to another is the failure this
        // header exists to prevent, and a callback that reads every
        // argument BUT the first cannot see it (issue #439).
        $this->assertSame('member0@test.be', $capturedTo);

        $this->assertIsArray($capturedExtraHeaders);
        $this->assertArrayHasKey('List-Unsubscribe', $capturedExtraHeaders);
        $this->assertArrayHasKey('List-Unsubscribe-Post', $capturedExtraHeaders);
        $this->assertSame('List-Unsubscribe=One-Click', $capturedExtraHeaders['List-Unsubscribe-Post']);
        $this->assertMatchesRegularExpression('#^<.*/mass-mail/unsubscribe/\d+\?token=[a-f0-9]{64}>$#', $capturedExtraHeaders['List-Unsubscribe']);
        $this->assertStringContainsString('/mass-mail/unsubscribe/', $capturedBodyHtml);
        $this->assertStringContainsString('Se désinscrire des emails groupés', $capturedBodyHtml);

        $stmt = $this->pdo->prepare('SELECT unsubscribe_token_hash FROM mass_mail_deliveries WHERE id = ?');
        $stmt->execute([$recipientBefore->id]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    /**
     * **A seed copy of a publipostage carries nobody's merged data**
     * (roadmap IT-07).
     *
     * One copy is emitted per RUN, so whatever this argument holds is
     * what every seed mailbox receives — ordinary inboxes at Gmail or
     * Outlook, i.e. third parties. The first version passed
     * `$baseBodyHtml`, whose name says « base » only relative to the
     * unsubscribe link appended after it: on a merge it had already been
     * rendered from the first processed recipient's audience row, and the
     * subject with it. One arbitrary member's name went to every box, on
     * every campaign, while the privacy notice this iteration wrote
     * promised the copy carries nothing the site mints for one person.
     *
     * What travels instead is the campaign with each variable filled by
     * the name of its own column — the shape and the length of the real
     * message, none of its values, and the same string for every box.
     */
    public function testTheSeedCopyOfAMergeCarriesNoRecipientsValues(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");
        $recipient = $this->deliveryRepository->findByEmailId($this->emailId)[0];

        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom'], 1, null);
        $rowId = $audienceRepository->createRow($audienceId, 2, $this->memberId, null, ['Prenom' => 'Kaa']);
        $update = $this->pdo->prepare(
            "UPDATE mass_mail_emails
                SET subject = 'Camp de {{Prenom}}',
                    body_html = '<p>Bonjour {{Prenom}}, le camp approche.</p>',
                    list_type = 'mail_merge',
                    audience_id = ?
              WHERE id = ?"
        );
        $update->execute([$audienceId, $this->emailId]);
        $this->pdo->prepare('UPDATE mass_mail_deliveries SET audience_row_id = ? WHERE id = ?')
            ->execute([$rowId, $recipient->id]);

        $sentTo = null;
        $sentSubject = null;
        $sentBodyHtml = null;
        $copy = null;
        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->once())
            ->method('send')
            ->willReturnCallback(
                function (...$args) use (&$sentTo, &$sentSubject, &$sentBodyHtml, &$copy): void {
                    $sentTo = $args[0];
                    $sentSubject = $args[1];
                    $sentBodyHtml = $args[2];
                    $copy = $args[12] ?? null;
                }
            );

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        // **The real message IS personalised**, and it goes to the member
        // whose values were merged into it — a mailing that rendered Kaa's
        // row and posted it to somebody else is a worse defect than the one
        // this test is about, and it used to be invisible here.
        $this->assertSame('member0@test.be', $sentTo);
        $this->assertSame('Camp de Kaa', $sentSubject);
        $this->assertStringContainsString('Bonjour Kaa', (string) $sentBodyHtml);

        $this->assertInstanceOf(\Core\Mail\Feedback\Seed\SeedCopyContent::class, $copy);
        $this->assertStringNotContainsString('Kaa', $copy->bodyHtml, 'a member\'s merged value went to every seed box.');
        $this->assertStringNotContainsString('Kaa', $copy->bodyText);
        $this->assertStringNotContainsString('Kaa', (string) $copy->subject);

        // Rendered, not left raw: curly braces in a subject line are the
        // sort of oddity a filter weighs, and a copy scored on them would
        // measure itself rather than the campaign.
        $this->assertSame('Camp de Prenom', $copy->subject);
        $this->assertStringContainsString('Bonjour Prenom,', $copy->bodyHtml);
        $this->assertStringNotContainsString('{{', $copy->bodyHtml);
    }

    /** An ordinary mailing personalises no subject, so the copy keeps it. */
    public function testTheSeedCopyOfAnOrdinaryMailingKeepsTheCampaignsSubject(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");

        $sentTo = null;
        $copy = null;
        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (...$args) use (&$sentTo, &$copy): void {
                $sentTo = $args[0];
                $copy = $args[12] ?? null;
            });

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $this->assertSame('member0@test.be', $sentTo);
        $this->assertInstanceOf(\Core\Mail\Feedback\Seed\SeedCopyContent::class, $copy);
        $subject = $this->pdo->query('SELECT subject FROM mass_mail_emails')->fetchColumn();
        $this->assertSame($subject, $copy->subject);
    }

    public function testMarksParentEmailSentOnceAllRecipientsProcessed(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");

        $mailService = $this->mailServiceDouble();
        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $stmt = $this->pdo->prepare('SELECT status FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$this->emailId]);
        $this->assertSame(Email::STATUS_SENT, $stmt->fetchColumn());
    }

    private function buildNotificationService(): NotificationService
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $notificationService = new NotificationService(
            new NotificationRepository($this->pdo, $this->encryption),
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createStub(WebPush::class),
            $settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->userAccountRepository
        );
        $notificationService->registerModuleTypes('mass_mail', [[
            'id' => 'mass_mail.email_received', 'label' => 'Nouvel email reçu', 'description' => 'd',
            'group' => 'Emails groupés', 'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'off'],
        ]]);

        return $notificationService;
    }

    /**
     * A notification is best-effort: the copy has left by the time it is
     * attempted. One that throws — here dispatch() itself — must neither
     * turn that message into an error nor stop the rest of the batch, and
     * it is journaled without the address.
     */
    public function testAFailingNotificationCostsNeitherTheMessageNorTheRestOfTheBatch(): void
    {
        $deliveries = $this->deliveryRepository->findByEmailId($this->emailId);
        $this->giveTheMemberAYearAt($deliveries[0]->emailAddress);
        foreach ($deliveries as $delivery) {
            $this->userAccountRepository->create($delivery->emailAddress);
        }
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->method('dispatch')->willThrowException(new \RuntimeException('push down'));

        (new SendBatchHandler())->handle([], $this->buildContextWithNotifications($this->mailServiceDouble(), $notificationService));

        $statuses = $this->pdo->query(
            'SELECT status FROM mass_mail_deliveries WHERE email_id = ' . (int) $this->emailId
        )->fetchAll(\PDO::FETCH_COLUMN);
        // The batch holds two of the three: both sent, none turned into an
        // error, and the third still queued for the next batch.
        $counts = array_count_values($statuses);
        ksort($counts);
        $this->assertSame(['pending' => 1, 'sent' => 2], $counts);
        $rescheduled = $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'"
        )->fetchColumn();
        $this->assertSame(1, (int) $rescheduled);
        $logged = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'notification_failed'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotSame([], $logged);
        foreach ($this->deliveryRepository->findByEmailId($this->emailId) as $delivery) {
            $this->assertStringNotContainsString($delivery->emailAddress, implode(' ', $logged));
        }
    }

    public function testDispatchesEmailReceivedNotificationWhenRecipientHasALoginAccount(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");
        $recipient = $this->deliveryRepository->findByEmailId($this->emailId)[0];

        $account = $this->userAccountRepository->create($recipient->emailAddress);

        $memberYearId = $this->giveTheMemberAYearAt($recipient->emailAddress);

        $mailService = $this->mailServiceDouble();
        $notificationService = $this->buildNotificationService();

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications($mailService, $notificationService));

        $notifications = (new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($account->id);
        $this->assertCount(1, $notifications);
        $this->assertSame('mass_mail.email_received', $notifications[0]->typeId);
        $this->assertSame('Sujet', $notifications[0]->body);
        $this->assertSame('/members/' . $memberYearId . '/emails/' . $this->targetId, $notifications[0]->url);
    }

    /**
     * Issue #287 showed the notification reading the stored TEMPLATE —
     * « Camp de {{Prenom}} » — because it took `$email->subject` while
     * the substitution lived in a local variable one scope away. The
     * template must never reach it.
     *
     * The substituted subject, on the other hand, IS written — and that
     * is issue #292's whole point. It used to be replaced by « Un email
     * personnalisé vous a été envoyé. » because `notifications.body` is
     * written once and the core purge only ever deletes rows somebody has
     * READ, so the value would have outlived the 18-month merge retention.
     * Task\PurgeMergeAudiencesHandler now deletes these notifications by
     * type on that same horizon, read or not, so the value can be stored:
     * it stops existing on schedule.
     */
    public function testThePersonalisedSubjectIsWrittenNowThatItCanBePurged(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");
        $recipient = $this->deliveryRepository->findByEmailId($this->emailId)[0];
        $account = $this->userAccountRepository->create($recipient->emailAddress);

        $this->giveTheMemberAYearAt($recipient->emailAddress);

        // Turn the fixture's ordinary email into a publipostage whose
        // subject carries a variable, and give this recipient a row.
        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom'], 1, null);
        $rowId = $audienceRepository->createRow($audienceId, 2, $this->memberId, null, ['Prenom' => 'Kaa']);
        $update = $this->pdo->prepare(
            "UPDATE mass_mail_emails SET subject = 'Camp de {{Prenom}}', list_type = 'mail_merge', audience_id = ?
             WHERE id = ?"
        );
        $update->execute([$audienceId, $this->emailId]);
        $this->pdo->prepare('UPDATE mass_mail_deliveries SET audience_row_id = ? WHERE id = ?')
            ->execute([$rowId, $recipient->id]);

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications(
            $this->mailServiceDouble(),
            $this->buildNotificationService()
        ));

        $notifications = (new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($account->id);
        $this->assertCount(1, $notifications);
        // The subject as SENT — substituted, so the reader is told which
        // email arrived rather than that one did.
        $this->assertSame('Camp de Kaa', $notifications[0]->body);
        // The raw template must never survive, personalisation or not.
        $this->assertStringNotContainsString('{{', $notifications[0]->body);

        // And the url is the correlation key the purge rebuilds months
        // later to find this exact row — no column joins the two, so a
        // link written in any other shape would be a value nothing can
        // erase.
        $memberYearId = (int) $this->pdo->query('SELECT id FROM member_years')->fetchColumn();
        $this->assertSame(
            TargetEmailLink::url($memberYearId, $this->targetId),
            $notifications[0]->url
        );
    }

    /**
     * The invariant that keeps the previous test honest: a personalised
     * subject is stored only when it can later be found and deleted.
     *
     * `notifications.url` IS the correlation key — Task\PurgeMergeAudiencesHandler
     * rebuilds it for every recipient of an audience it erases. A recipient
     * whose `member_years` row for its own snapshot year has gone (a Desk
     * re-import or a year transition between queueing and sending) gets no
     * url, so the purge could never find the row again. Writing « Camp de
     * Kaa » there would recreate exactly the immortal personal value this
     * whole change exists to end, and the reader loses nothing: with no
     * url there is nothing to open either.
     */
    public function testAPersonalisedSubjectIsNotWrittenWhenNoLinkCanBeBuilt(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");
        $recipient = $this->deliveryRepository->findByEmailId($this->emailId)[0];
        $account = $this->userAccountRepository->create($recipient->emailAddress);

        // Deliberately NO member_years row: that is the whole scenario.
        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom'], 1, null);
        $rowId = $audienceRepository->createRow($audienceId, 2, $this->memberId, null, ['Prenom' => 'Kaa']);
        $update = $this->pdo->prepare(
            "UPDATE mass_mail_emails SET subject = 'Camp de {{Prenom}}', list_type = 'mail_merge', audience_id = ?
             WHERE id = ?"
        );
        $update->execute([$audienceId, $this->emailId]);
        $this->pdo->prepare('UPDATE mass_mail_deliveries SET audience_row_id = ? WHERE id = ?')
            ->execute([$rowId, $recipient->id]);

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications(
            $this->mailServiceDouble(),
            $this->buildNotificationService()
        ));

        $notifications = (new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($account->id);
        $this->assertCount(1, $notifications);
        $this->assertNull($notifications[0]->url);
        $this->assertStringNotContainsString('Kaa', $notifications[0]->body);
        $this->assertSame('Un email personnalisé vous a été envoyé.', $notifications[0]->body);
    }

    /**
     * The other half of that rule, and the common case: most
     * publipostages keep their variables in the BODY, so every recipient
     * shares one subject. Nothing per-recipient is written, so the
     * notification says what the mail is — losing that for every merge
     * would be paying a privacy price where there is nothing to protect.
     */
    public function testAMergeWhoseSubjectIsTheSameForEverybodyKeepsItsSubjectInTheNotification(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");
        $recipient = $this->deliveryRepository->findByEmailId($this->emailId)[0];
        $account = $this->userAccountRepository->create($recipient->emailAddress);

        $this->giveTheMemberAYearAt($recipient->emailAddress);

        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom'], 1, null);
        $rowId = $audienceRepository->createRow($audienceId, 2, $this->memberId, null, ['Prenom' => 'Kaa']);
        // Variables in the body only — the subject is shared.
        $update = $this->pdo->prepare(
            "UPDATE mass_mail_emails SET subject = 'Infos camp', body_html = '<p>Cher {{Prenom}}</p>',
                    list_type = 'mail_merge', audience_id = ? WHERE id = ?"
        );
        $update->execute([$audienceId, $this->emailId]);
        $this->pdo->prepare('UPDATE mass_mail_deliveries SET audience_row_id = ? WHERE id = ?')
            ->execute([$rowId, $recipient->id]);

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications(
            $this->mailServiceDouble(),
            $this->buildNotificationService()
        ));

        $notifications = (new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($account->id);
        $this->assertCount(1, $notifications);
        $this->assertSame('Infos camp', $notifications[0]->body);
    }

    public function testDoesNotDispatchWhenRecipientHasNoLoginAccount(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");

        $mailService = $this->mailServiceDouble();
        $notificationService = $this->buildNotificationService();

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications($mailService, $notificationService));

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    // --- Mail merge: per-recipient rendering at send time (ARCHITECTURE.md §8.61) ---

    /**
     * @param array<string, string> $data
     * @return array{0: int, 1: int} [merge email id, recipient id]
     */
    private function createMergeEmailWithRecipient(?array $data): array
    {
        $sectionId = (int) $this->pdo->query('SELECT id FROM sections LIMIT 1')->fetchColumn();
        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('f.xlsx', 'Feuille1', ['Prenom', 'Montant'], 1, null);

        $stmt = $this->pdo->prepare(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, audience_id, status)
             VALUES ('Infos {{Prenom}}', '<p>Cher {{Prenom}}, montant : {{Montant}} €</p>', ?, 'mail_merge', ?, '" . Email::STATUS_SENDING . "')"
        );
        $stmt->execute([$sectionId, $audienceId]);
        $emailId = (int) $this->pdo->lastInsertId();

        $rowId = $data !== null ? $audienceRepository->createRow($audienceId, 2, null, 'ext@test.be', $data) : null;
        $recipientId = $this->deliveryRepository->create(
            $emailId, 'ext@test.be', Delivery::STATUS_PENDING, null, null, $rowId
        );

        return [$emailId, $recipientId];
    }

    public function testMergeRecipientGetsTheirOwnRenderedSubjectAndBody(): void
    {
        $this->pdo->exec('DELETE FROM mass_mail_deliveries');
        $this->pdo->exec('DELETE FROM mass_mail_emails');
        [$emailId] = $this->createMergeEmailWithRecipient(['Prenom' => 'Louis', 'Montant' => '145']);

        $capturedTo = null;
        $capturedSubject = null;
        $capturedBody = null;
        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->once())->method('send')
            ->willReturnCallback(function (...$args) use (&$capturedTo, &$capturedSubject, &$capturedBody): void {
                $capturedTo = $args[0];
                $capturedSubject = $args[1];
                $capturedBody = $args[2];
            });

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        // Louis's rendered message goes to Louis's own external address.
        $this->assertSame('ext@test.be', $capturedTo);
        $this->assertSame('Infos Louis', $capturedSubject);
        $this->assertStringContainsString('Cher Louis, montant : 145 €', $capturedBody);
        // The unsubscribe footer still applies to an external recipient.
        $this->assertStringContainsString('/mass-mail/unsubscribe/', $capturedBody);

        $counts = $this->deliveryRepository->countGroupedByStatus($emailId);
        $this->assertSame(1, $counts['sent']);
    }

    public function testMergeValuesAreHtmlEscapedInTheRenderedBody(): void
    {
        $this->pdo->exec('DELETE FROM mass_mail_deliveries');
        $this->pdo->exec('DELETE FROM mass_mail_emails');
        $this->createMergeEmailWithRecipient(['Prenom' => '<script>alert(1)</script>', 'Montant' => '1']);

        $capturedBody = null;
        $mailService = $this->mailServiceDouble();
        $mailService->method('send')->willReturnCallback(function (...$args) use (&$capturedBody): void {
            $capturedBody = $args[2];
        });

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $this->assertStringNotContainsString('<script>', (string) $capturedBody);
        $this->assertStringContainsString('&lt;script&gt;', (string) $capturedBody);
    }

    public function testMergeRecipientWhoseAudienceRowWasPurgedFailsExplicitly(): void
    {
        $this->pdo->exec('DELETE FROM mass_mail_deliveries');
        $this->pdo->exec('DELETE FROM mass_mail_emails');
        [$emailId] = $this->createMergeEmailWithRecipient(null); // no audience row at all

        $mailService = $this->mailServiceDouble();
        $mailService->expects($this->never())->method('send');

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $recipients = $this->deliveryRepository->findByEmailId($emailId);
        $this->assertSame(Delivery::STATUS_ERROR, $recipients[0]->status);
        $this->assertSame('Données de publipostage purgées', $recipients[0]->errorMessage);
    }

    // ── The chain itself (issue #1443) ──────────────────────────────────

    /**
     * The row the scheduler would have claimed for this run: `processing`,
     * as SchedulerRepository::claimOverdue() leaves it before calling the
     * handler, and its id handed over the way SchedulerRunner does.
     *
     * @param array<string, mixed> $payload what the row was queued with
     * @return array<string, mixed> the payload handle() receives
     */
    private function claimedRun(array $payload = []): array
    {
        $id = (new SchedulerRepository($this->pdo))->create(
            'mass_mail',
            'send_batch',
            date('Y-m-d H:i:s'),
            $payload === [] ? null : (string) json_encode($payload),
            SendBatchHandler::REFERENCE
        );
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'processing' WHERE id = {$id}");

        return $payload + ['scheduled_action_id' => $id];
    }

    /** @return list<array<string, mixed>> the queued send_batch rows */
    private function pendingBatches(): array
    {
        return $this->pdo->query(
            "SELECT * FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch'
             AND status = 'pending' ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array{level: string, context: array<string, mixed>}> */
    private function journalLines(string $type): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT level, context FROM event_log WHERE category = 'mass_mail' AND event_type = ? ORDER BY id"
        );
        $stmt->execute([$type]);

        return array_map(
            static fn(array $row): array => [
                'level' => (string) $row['level'],
                'context' => (array) json_decode((string) $row['context'], true),
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * **The bug of issue #1443, first half.** Only `MailException` is
     * caught inside the loop, and the re-arm used to sit after it: a
     * `\PDOException` from the second message escaped, the run was marked
     * failed, and the two messages left stayed `pending` with nothing
     * queued to send them while the mailing read « Envoi en cours ».
     *
     * The exception still leaves handle() — the scheduler has to mark the
     * run failed — but the chain's next link is queued first, the journal
     * says what happened in the mailing's own category, and the next run
     * sends exactly the two messages the failed one did not.
     */
    public function testABatchThatThrowsAPdoExceptionStillQueuesTheNextOneWhichSendsTheRest(): void
    {
        $failing = $this->mailServiceDouble();
        $calls = 0;
        $failing->method('send')->willReturnCallback(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        });

        $payload = $this->claimedRun();
        try {
            (new SendBatchHandler())->handle($payload, $this->buildContext($failing));
            $this->fail('the batch\'s own exception must still reach the scheduler');
        } catch (\PDOException) {
        }

        $queued = $this->pendingBatches();
        $this->assertCount(1, $queued, 'the next batch is queued although this one threw');
        $this->assertSame(SendBatchHandler::REFERENCE, $queued[0]['reference']);
        $this->assertSame(['consecutive_failures' => 1], json_decode((string) $queued[0]['payload'], true));

        $lines = $this->journalLines('batch_failed');
        $this->assertCount(1, $lines);
        $this->assertSame('error', $lines[0]['level']);
        $this->assertSame([
            'task_id' => $payload['scheduled_action_id'],
            'pending' => 2,
            'error_class' => \PDOException::class,
            'consecutive_failures' => 1,
        ], $lines[0]['context']);

        $sentTo = [];
        $working = $this->mailServiceDouble();
        $working->method('send')->willReturnCallback(function (string $to) use (&$sentTo): void {
            $sentTo[] = $to;
        });
        (new SendBatchHandler())->handle(
            $this->claimedRun(['consecutive_failures' => 1]),
            $this->buildContext($working)
        );

        $this->assertSame(['member1@test.be', 'member2@test.be'], $sentTo, 'the next batch sends what was left');
        $this->assertSame(0, $this->deliveryRepository->countGroupedByStatus($this->emailId)['pending']);
    }

    /**
     * A batch that keeps failing stops the chain after the cap, instead of
     * failing once per interval for ever — and says so.
     */
    public function testTheChainStopsReArmingAfterTheCapOfConsecutiveFailures(): void
    {
        $failing = $this->mailServiceDouble();
        $failing->method('send')->willThrowException(new \RuntimeException('boom'));

        try {
            (new SendBatchHandler())->handle(
                $this->claimedRun(['consecutive_failures' => SendBatchHandler::MAX_CONSECUTIVE_FAILURES - 1]),
                $this->buildContext($failing)
            );
            $this->fail('the exception must reach the scheduler');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->pendingBatches(), 'the last failure the cap allows queues nothing');
        $lines = $this->journalLines('batch_failed');
        $this->assertCount(1, $lines);
        $this->assertSame(SendBatchHandler::MAX_CONSECUTIVE_FAILURES, $lines[0]['context']['consecutive_failures']);
        $this->assertSame(3, $lines[0]['context']['pending']);
    }

    /** One batch that goes through is the end of a series of failures. */
    public function testASuccessfulBatchResetsTheCountOfFailures(): void
    {
        (new SendBatchHandler())->handle(
            $this->claimedRun(['consecutive_failures' => 3]),
            $this->buildContext($this->mailServiceDouble())
        );

        $queued = $this->pendingBatches();
        $this->assertCount(1, $queued);
        $this->assertNull($queued[0]['payload'], 'the count starts again from nothing');
        $this->assertSame([], $this->journalLines('batch_failed'));
    }

    /**
     * **The bug of issue #1443, second half, on the handler's side.**
     * « Renvoyer » pressed while this batch ran queued a run of its own;
     * the batch's unguarded scheduleAfter() then queued another, and the
     * two chains lived until the queue was empty — a full batch each per
     * interval. The re-arm now finds the queued run and stands down.
     */
    public function testTheReArmStandsDownWhenARunIsAlreadyQueued(): void
    {
        $payload = $this->claimedRun();
        (new SchedulerService(new SchedulerRepository($this->pdo)))->scheduleAfter(
            'mass_mail',
            'send_batch',
            0,
            [],
            SendBatchHandler::REFERENCE
        );

        (new SendBatchHandler())->handle($payload, $this->buildContext($this->mailServiceDouble()));

        $this->assertCount(1, $this->pendingBatches());
    }

    // ── Seeding from the composition roots (issue #1443) ───────────────

    private function bootstrap(?\DateTimeImmutable $now = null): bool
    {
        return SendBatchHandler::bootstrap(
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->deliveryRepository,
            new JournalService(new JournalRepository($this->pdo)),
            $now
        );
    }

    /**
     * Messages waiting and no batch queued or running — a queue left by a
     * run whose re-arm failed with it, or by the version before this one:
     * the composition root queues exactly one run, however many requests
     * ask, and the journal says the chain was started again.
     */
    public function testPendingMessagesWithoutATaskAreSeededOnceAndJournalled(): void
    {
        $this->assertTrue($this->bootstrap());
        $this->assertFalse($this->bootstrap(), 'a second request finds the chain alive');
        $this->assertFalse($this->bootstrap());

        $queued = $this->pendingBatches();
        $this->assertCount(1, $queued);
        $this->assertSame(SendBatchHandler::REFERENCE, $queued[0]['reference']);

        $lines = $this->journalLines('batch_chain_recovered');
        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]['level']);
        $this->assertSame(['pending' => 3], $lines[0]['context']);
    }

    public function testNothingIsSeededWhenNoMessageWaits(): void
    {
        $this->pdo->exec("UPDATE mass_mail_deliveries SET status = 'sent'");

        $this->assertFalse($this->bootstrap());
        $this->assertSame([], $this->pendingBatches());
    }

    /**
     * A batch running right now is a live chain: it re-arms in its own
     * `finally`. A seed that only looked for a queued row would start a
     * second one here — the very birth of the duplicate of §8.5.
     */
    public function testNothingIsSeededWhileABatchIsRunning(): void
    {
        $this->claimedRun();

        $this->assertFalse($this->bootstrap());
        $this->assertSame([], $this->pendingBatches());
        $this->assertSame([], $this->journalLines('batch_chain_recovered'));
    }

    /**
     * A row queued before the chain had a reference is the chain too:
     * the task-wide guard sees it.
     */
    public function testARowQueuedWithoutTheReferenceCountsAsTheChain(): void
    {
        (new SchedulerService(new SchedulerRepository($this->pdo)))->scheduleAfter('mass_mail', 'send_batch', 0);

        $this->assertFalse($this->bootstrap());
        $this->assertCount(1, $this->pendingBatches());
    }

    /**
     * A chain stopped on its cap is not restarted on the next page view —
     * that would make the cap a one-minute pause — but it is after
     * HALT_HOURS, so a cause somebody fixed is picked up by itself.
     */
    public function testAChainStoppedOnItsCapIsLeftAloneForTheHaltThenRestarted(): void
    {
        $failing = $this->mailServiceDouble();
        $failing->method('send')->willThrowException(new \RuntimeException('boom'));
        $payload = $this->claimedRun(['consecutive_failures' => SendBatchHandler::MAX_CONSECUTIVE_FAILURES - 1]);
        try {
            (new SendBatchHandler())->handle($payload, $this->buildContext($failing));
        } catch (\RuntimeException) {
        }
        // What SchedulerRunner does with a run that threw.
        (new SchedulerRepository($this->pdo))->markFailed((int) $payload['scheduled_action_id'], 'boom');

        $this->assertFalse($this->bootstrap(), 'right after the halt');
        $this->assertFalse($this->bootstrap(new \DateTimeImmutable('+' . (SendBatchHandler::HALT_HOURS * 60 - 5) . ' minutes')));
        $this->assertSame([], $this->pendingBatches());

        $this->assertTrue($this->bootstrap(new \DateTimeImmutable('+' . (SendBatchHandler::HALT_HOURS * 60 + 5) . ' minutes')));
        $this->assertCount(1, $this->pendingBatches());
    }

    /** A failed run that was NOT the cap's — its re-arm failed with it — is restarted at once. */
    public function testAFailedRunBelowTheCapIsRestartedAtOnce(): void
    {
        $payload = $this->claimedRun(['consecutive_failures' => 1]);
        (new SchedulerRepository($this->pdo))->markFailed((int) $payload['scheduled_action_id'], 'boom');

        $this->assertTrue($this->bootstrap());
        $queued = $this->pendingBatches();
        $this->assertCount(1, $queued);
        $this->assertSame(
            ['consecutive_failures' => 2],
            json_decode((string) $queued[0]['payload'], true),
            'the restarted chain keeps counting, so the cap is still reached (#1557 review)'
        );
    }

    /**
     * The halt runs from when the capped run FAILED, not from when it was
     * due (#1557 review): a run due three hours ago that failed just now
     * still holds the chain.
     */
    public function testTheHaltCountsFromTheFailureNotFromWhenTheRunWasDue(): void
    {
        $payload = $this->claimedRun(['consecutive_failures' => SendBatchHandler::MAX_CONSECUTIVE_FAILURES - 1]);
        $id = (int) $payload['scheduled_action_id'];
        $dueAt = (new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s');
        $this->pdo->exec("UPDATE scheduled_actions SET run_at = '{$dueAt}' WHERE id = {$id}");
        (new SchedulerRepository($this->pdo))->markFailed($id, 'boom');

        $this->assertFalse($this->bootstrap());
        $this->assertSame([], $this->pendingBatches());
    }

    /**
     * A batch that went through is never read as a capped failure
     * (#1557 review): a successor that cannot be written does not make
     * handle() throw, the run ends `done`, and the next request queues the
     * chain again from a count of zero.
     */
    public function testASuccessfulBatchWhoseSuccessorCannotBeWrittenIsNotHalted(): void
    {
        $payload = $this->claimedRun(['consecutive_failures' => SendBatchHandler::MAX_CONSECUTIVE_FAILURES - 1]);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_successor BEFORE INSERT ON scheduled_actions BEGIN SELECT RAISE(ABORT, 'refused'); END"
        );

        (new SendBatchHandler())->handle($payload, $this->buildContext($this->mailServiceDouble()));

        $this->pdo->exec('DROP TRIGGER refuse_successor');
        (new SchedulerRepository($this->pdo))->markDone((int) $payload['scheduled_action_id']);
        $this->assertSame([], $this->pendingBatches());
        $this->assertTrue($this->bootstrap(), 'not held as a capped failure');
        $this->assertNull($this->pendingBatches()[0]['payload']);
    }

    /**
     * A journal that refuses the line of a copy that left does not end the
     * batch before its completion check (#1557 review): the mailing whose
     * last copy it was is marked sent, not left « Envoi en cours ».
     */
    public function testAJournalThatRefusesStillLetsTheMailingComplete(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_deliveries WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_deliveries)");
        $this->pdo->exec(
            "CREATE TRIGGER refuse_journal BEFORE INSERT ON event_log BEGIN SELECT RAISE(ABORT, 'refused'); END"
        );

        try {
            (new SendBatchHandler())->handle([], $this->buildContext($this->mailServiceDouble()));
        } catch (\PDOException) {
            // The completion's own journal line may refuse too, once the
            // mailing is marked: the run then fails, the mailing does not.
        }

        $stmt = $this->pdo->prepare('SELECT status FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$this->emailId]);
        $this->assertSame(Email::STATUS_SENT, $stmt->fetchColumn());
    }
}
