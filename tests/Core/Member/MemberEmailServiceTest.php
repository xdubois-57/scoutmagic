<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\EmailDomainValidator;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailException;
use Core\Member\MemberEmailRepository;
use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Feedback\Bounce\BounceService;
use Core\Mail\Feedback\Bounce\BounceSeverity;
use Core\Mail\Feedback\Bounce\BounceStateRepository;
use Core\Member\MemberEmailService;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberEmailServiceTest extends TestCase
{
    /**
     * Record every address `MailService::send()` is handed, in order.
     *
     * A doubled `send()` with no argument constraint accepts any
     * argument, so counting sends says a message left and says nothing
     * about where it went. Measured on this very file: replacing
     * `to: $to` in MemberEmailService with a fixed foreign address left
     * all forty-six tests green — a hundred and three assertions, not one
     * of them looking at the recipient (issue #439).
     *
     * That matters more here than almost anywhere: the thing being
     * delivered is an address-confirmation link. Sent to the wrong
     * person, it hands them the means to attach an address to somebody
     * else's profile.
     *
     * @param list<string> $recipients written to by the double
     */
    private function recordRecipients(InvocationOrder $times, array &$recipients): void
    {
        $this->mailService->expects($times)
            ->method('send')
            ->willReturnCallback(function (...$arguments) use (&$recipients): void {
                $recipients[] = (string) $arguments[0];
            });
    }

    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $repository;
    private MemberEmailService $service;
    private BounceStateRepository $bounceStates;
    private MailService&\PHPUnit\Framework\MockObject\MockObject $mailService;
    private int $memberId;
    private int $lastRowId = 0;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->mailService = $this->createMock(MailService::class);
        $connection = Connection::withPdo($this->pdo);

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        // Always-true stub — these tests exercise addEmail()'s own logic,
        // not a real DNS lookup (Core\Member\EmailDomainValidatorTest
        // covers the real implementation separately).
        $alwaysValidDomain = new class extends EmailDomainValidator {
            public function hasValidDomain(string $email): bool
            {
                return true;
            }
        };

        $this->service = new MemberEmailService(
            $this->repository,
            $this->mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig),
            new JournalService(new JournalRepository($this->pdo)),
            new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
),
            new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository($connection, $this->encryption)
),
            new ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité',
            $alwaysValidDomain,
            // A REAL bounce service over the same database, not null.
            // With null every bounce question answers « jamais rebondi »
            // before touching anything, and a test asserting « l'adresse
            // bloquée est exclue » would pass whatever the code does.
            new BounceService($this->bounceStates = new BounceStateRepository($this->pdo, $this->encryption))
        );

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    /**
     * Staff d'U section with a configured email — needed for the
     * unsubscribe-notification tests below.
     */
    private function createStaffDuSectionWithEmail(string $email): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('STAFFDU', \"Staff d'U\", 50)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare("INSERT INTO sections (desk_code, age_branch_id, name, email) VALUES ('STAFFDU', ?, \"Staff d'U\", ?)");
        $stmt->execute([$branchId, $email]);
    }

    // --- addEmail() ---

    public function testAddEmailRejectsInvalidFormat(): void
    {
        $this->expectException(MemberEmailException::class);
        $this->service->addEmail($this->memberId, 'not-an-email', null);
    }

    /**
     * Module addendum: "limit typos" via a DNS check on the domain — a
     * well-formed address whose domain doesn't resolve must still be
     * rejected.
     */
    public function testAddEmailRejectsAnUnresolvableDomain(): void
    {
        $neverValidDomain = new class extends EmailDomainValidator {
            public function hasValidDomain(string $email): bool
            {
                return false;
            }
        };
        $service = new MemberEmailService(
            $this->repository,
            $this->mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $this->createMock(\Twig\Environment::class)),
            new JournalService(new JournalRepository($this->pdo)),
            new SectionService(
    new SectionRepository(Connection::withPdo($this->pdo)),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
),
            new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
            new ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité',
            $neverValidDomain
        );

        $this->mailService->expects($this->never())->method('send');
        $this->expectException(MemberEmailException::class);
        $service->addEmail($this->memberId, 'someone@this-domain-does-not-resolve.invalid', null);
    }

    public function testAddEmailCreatesAPendingRowAndSendsConfirmation(): void
    {
        $recipients = [];
        $this->recordRecipients($this->once(), $recipients);

        $row = $this->service->addEmail($this->memberId, 'Secondary@Example.com', null);

        // The address that was added, normalised — and nobody else's.
        $this->assertSame(['secondary@example.com'], $recipients);
        $this->assertSame('secondary@example.com', $row->email);
        $this->assertTrue($row->isPending());
        $this->assertSame(MemberEmail::SOURCE_MANUAL, $row->source);
    }

    public function testAddEmailCapsTheNumberOfSelfAddedAddresses(): void
    {
        // Five unique addresses are allowed; the sixth is refused before it
        // creates a row or sends a confirmation — the mail-bomb cap (audit
        // M15). Exactly five sends.
        $recipients = [];
        $this->recordRecipients($this->exactly(5), $recipients);

        for ($i = 1; $i <= 5; $i++) {
            $this->service->addEmail($this->memberId, "addr{$i}@example.com", null);
        }

        // Five sends AND five different addresses, each the one just
        // added: a cap that let five confirmations leave for the same
        // address would count the same.
        $this->assertSame(
            ['addr1@example.com', 'addr2@example.com', 'addr3@example.com', 'addr4@example.com', 'addr5@example.com'],
            $recipients
        );

        try {
            $this->service->addEmail($this->memberId, 'one-too-many@example.com', null);
            $this->fail('Expected a MemberEmailException for exceeding the cap.');
        } catch (MemberEmailException $e) {
            $this->assertStringContainsString('maximum', $e->getMessage());
        }

        $this->assertCount(5, $this->repository->findByMember($this->memberId));
    }

    public function testAddingTheSameAddressTwiceReusesTheExistingRow(): void
    {
        $recipients = [];
        // Only the first add sends — the second is within the cooldown.
        $this->recordRecipients($this->once(), $recipients);

        $first = $this->service->addEmail($this->memberId, 'dup@example.com', null);
        $second = $this->service->addEmail($this->memberId, 'dup@example.com', null);

        $this->assertSame(['dup@example.com'], $recipients);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->repository->findByMember($this->memberId));
    }

    /**
     * A confirmation-send failure (e.g. no SMTP configured) must still
     * create the row — "Renvoyer" lets the member retry — but must also
     * leave a diagnosable trace, since previously the only visible symptom
     * was a generic flash message with no way to tell a real bug from a
     * misconfigured mail transport.
     */
    public function testAddEmailJournalsTheReasonWhenConfirmationSendFails(): void
    {
        $this->mailService->method('send')->willThrowException(new \Core\Mail\MailException('SMTP connect() failed: someone@example.com refused'));

        $this->expectException(\Core\Mail\MailException::class);
        try {
            $this->service->addEmail($this->memberId, 'someone@example.com', 7);
        } finally {
            // The row is kept even though the send failed.
            $this->assertCount(1, $this->repository->findByMember($this->memberId));

            $stmt = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'member_email_confirmation_send_failed'");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertCount(1, $rows);
            $context = json_decode($rows[0]['context'], true);
            $this->assertSame($this->memberId, $context['member_id']);
            $this->assertArrayHasKey('member_email_id', $context);
            $this->assertStringContainsString('SMTP connect() failed', $context['error']);
            // The address itself must never end up in the journal.
            $this->assertStringNotContainsString('someone@example.com', $context['error']);
            $this->assertSame(7, (int) $rows[0]['user_account_id']);
        }
    }

    // --- confirmEmail() (48h expiry, single-use, hashed storage) ---

    public function testConfirmEmailWithCorrectTokenMarksValid(): void
    {
        $rawToken = $this->addPendingRowAndCaptureToken('confirm@example.com');

        $result = $this->service->confirmEmail($this->lastRowId, $rawToken);

        $this->assertTrue($result);
        $row = $this->repository->findById($this->lastRowId);
        $this->assertTrue($row->isValid());
        $this->assertNotNull($row->confirmedAt);
    }

    public function testConfirmEmailFailsWithWrongToken(): void
    {
        $this->addPendingRowAndCaptureToken('wrongtoken@example.com');

        $this->assertFalse($this->service->confirmEmail($this->lastRowId, 'not-the-right-token'));
        $this->assertTrue($this->repository->findById($this->lastRowId)->isPending());
    }

    public function testConfirmEmailFailsWhenExpired(): void
    {
        $rawToken = $this->addPendingRowAndCaptureToken('expired@example.com');
        $this->pdo->prepare('UPDATE member_emails SET confirmation_expires_at = ? WHERE id = ?')
            ->execute(['2000-01-01 00:00:00', $this->lastRowId]);

        $this->assertFalse($this->service->confirmEmail($this->lastRowId, $rawToken));
    }

    public function testConfirmEmailIsSingleUse(): void
    {
        $rawToken = $this->addPendingRowAndCaptureToken('singleuse@example.com');

        $this->assertTrue($this->service->confirmEmail($this->lastRowId, $rawToken));
        // Same token, second click — hash was cleared by markValid(), so this must fail gracefully.
        $this->assertFalse($this->service->confirmEmail($this->lastRowId, $rawToken));
    }

    public function testConfirmEmailFailsGracefullyForAnUnknownId(): void
    {
        $this->assertFalse($this->service->confirmEmail(999999, 'whatever'));
    }

    // --- canConfirmEmail() — the GET confirm page's read-only check ---

    public function testCanConfirmEmailNeverConsumesTheToken(): void
    {
        $rawToken = $this->addPendingRowAndCaptureToken('precheck@example.com');

        // A mail scanner may hit the GET page any number of times — the
        // token must survive every one of them, then still confirm.
        $this->assertTrue($this->service->canConfirmEmail($this->lastRowId, $rawToken));
        $this->assertTrue($this->service->canConfirmEmail($this->lastRowId, $rawToken));
        $this->assertTrue($this->repository->findById($this->lastRowId)->isPending());

        $this->assertTrue($this->service->confirmEmail($this->lastRowId, $rawToken));
    }

    public function testCanConfirmEmailRejectsAWrongTokenWithoutMutating(): void
    {
        $this->addPendingRowAndCaptureToken('precheckwrong@example.com');

        $this->assertFalse($this->service->canConfirmEmail($this->lastRowId, 'not-the-right-token'));
        $this->assertTrue($this->repository->findById($this->lastRowId)->isPending());
    }

    public function testCanConfirmEmailIsFalseOnceConfirmed(): void
    {
        $rawToken = $this->addPendingRowAndCaptureToken('precheckused@example.com');
        $this->service->confirmEmail($this->lastRowId, $rawToken);

        $this->assertFalse($this->service->canConfirmEmail($this->lastRowId, $rawToken));
    }

    // --- resendConfirmation() (5 min cooldown, enforced server-side) ---

    public function testResendConfirmationRejectedWithinCooldown(): void
    {
        $row = $this->service->addEmail($this->memberId, 'cooldown@example.com', null);

        $this->expectException(MemberEmailException::class);
        $this->service->resendConfirmation($this->memberId, $row->id, null);
    }

    public function testResendConfirmationAllowedAfterCooldownElapses(): void
    {
        $row = $this->service->addEmail($this->memberId, 'aftercooldown@example.com', null);
        $this->pdo->prepare('UPDATE member_emails SET last_confirmation_sent_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable('-6 minutes'))->format('Y-m-d H:i:s'), $row->id]);

        $recipients = [];
        $this->recordRecipients($this->once(), $recipients);
        $updated = $this->service->resendConfirmation($this->memberId, $row->id, null);

        // A resend goes to the address being confirmed, not to whoever
        // asked for it.
        $this->assertSame(['aftercooldown@example.com'], $recipients);

        // The resend succeeded (no exception) and restarted its own
        // cooldown — confirms this is a fresh send, not a rejected one.
        $this->assertGreaterThan(0, $this->service->resendCooldownRemainingMinutes($updated));
    }

    public function testResendConfirmationRejectsAnotherMembersRow(): void
    {
        $row = $this->service->addEmail($this->memberId, 'notyours@example.com', null);
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK2')");
        $otherMemberId = (int) $this->pdo->lastInsertId();

        $this->expectException(MemberEmailException::class);
        $this->service->resendConfirmation($otherMemberId, $row->id, null);
    }

    // --- deleteEmail() (never for the Desk-sourced row) ---

    public function testDeleteEmailRemovesAPendingRow(): void
    {
        $row = $this->service->addEmail($this->memberId, 'todelete@example.com', null);

        $this->service->deleteEmail($this->memberId, $row->id, null);

        $this->assertNull($this->repository->findById($row->id));
    }

    public function testDeleteEmailRefusesADeskSourcedRow(): void
    {
        $deskRow = $this->repository->findOrCreateDeskOverride($this->memberId, 'desk@example.com');

        $this->expectException(MemberEmailException::class);
        $this->service->deleteEmail($this->memberId, $deskRow->id, null);
    }

    // --- reactivateEmail() (straight back to valid, no reconfirmation — works for desk rows too) ---

    public function testReactivateEmailFlipsInactiveBackToValidWithoutReconfirmation(): void
    {
        $row = $this->service->addEmail($this->memberId, 'reactivate@example.com', null);
        $this->repository->markInactive($row->id);

        $reactivated = $this->service->reactivateEmail($this->memberId, $row->id, null);

        $this->assertTrue($reactivated->isValid());
    }

    public function testReactivateEmailWorksForADeskSourcedRow(): void
    {
        $deskRow = $this->repository->findOrCreateDeskOverride($this->memberId, 'desk-reactivate@example.com');
        $this->repository->markInactive($deskRow->id);

        $reactivated = $this->service->reactivateEmail($this->memberId, $deskRow->id, null);

        $this->assertTrue($reactivated->isValid());
    }

    public function testReactivateEmailRejectsAnAlreadyValidRow(): void
    {
        $row = $this->service->addEmail($this->memberId, 'alreadyvalid@example.com', null);
        $this->repository->markValid($row->id);

        $this->expectException(MemberEmailException::class);
        $this->service->reactivateEmail($this->memberId, $row->id, null);
    }

    // --- resolveValidAddressesForMassMail() (Desk lazy override + secondary) ---

    public function testResolveValidAddressesIncludesDeskAndValidSecondary(): void
    {
        $row = $this->service->addEmail($this->memberId, 'secondary@example.com', null);
        $this->repository->markValid($row->id);

        $addresses = $this->service->resolveValidAddressesForMassMail($this->memberId, 'desk@example.com');

        $emails = array_map(fn(MemberEmail $e) => $e->email, $addresses);
        $this->assertContains('desk@example.com', $emails);
        $this->assertContains('secondary@example.com', $emails);
        $this->assertCount(2, $addresses);
    }

    public function testResolveValidAddressesExcludesPendingAndInactiveSecondary(): void
    {
        $pending = $this->service->addEmail($this->memberId, 'stillpending@example.com', null);
        $inactiveRow = $this->service->addEmail($this->memberId, 'wasactive@example.com', null);
        $this->repository->markValid($inactiveRow->id);
        $this->repository->markInactive($inactiveRow->id);

        $addresses = $this->service->resolveValidAddressesForMassMail($this->memberId, null);

        $this->assertSame([], $addresses);
    }

    public function testResolveValidAddressesExcludesAnUnsubscribedDeskAddress(): void
    {
        $deskRow = $this->repository->findOrCreateDeskOverride($this->memberId, 'unsubscribed-desk@example.com');
        $this->repository->markInactive($deskRow->id);

        $addresses = $this->service->resolveValidAddressesForMassMail($this->memberId, 'unsubscribed-desk@example.com');

        $this->assertSame([], $addresses);
    }

    // --- unsubscribe() (idempotent, mass-mail-only mechanism, revokes login too) ---

    public function testUnsubscribeMarksAValidRowInactive(): void
    {
        $row = $this->service->addEmail($this->memberId, 'tounsub@example.com', null);
        $this->repository->markValid($row->id);

        $this->service->unsubscribe($row->id);

        $this->assertTrue($this->repository->findById($row->id)->isInactive());
    }

    public function testUnsubscribeIsIdempotent(): void
    {
        $row = $this->service->addEmail($this->memberId, 'idempotent@example.com', null);
        $this->repository->markValid($row->id);

        $this->service->unsubscribe($row->id);
        $this->service->unsubscribe($row->id); // second call must not error

        $this->assertTrue($this->repository->findById($row->id)->isInactive());
    }

    public function testUnsubscribeOnAnUnknownIdIsANoOp(): void
    {
        $this->service->unsubscribe(999999); // must not throw
        $this->addToAssertionCount(1);
    }

    /**
     * Module addendum: on unsubscribe, a notification is sent to the
     * Staff d'U section's configured address AND a confirmation is sent
     * to the unsubscribed address itself, each listing the affected
     * member(s) by full name.
     */
    public function testUnsubscribeSendsStaffduNotificationAndConfirmationListingMemberNames(): void
    {
        $this->createStaffDuSectionWithEmail('staffdu@example.com');
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $scoutYearId, $this->encryption->encrypt('Jean', 'member_years.first_name'), $this->encryption->encrypt('Dupont', 'member_years.last_name')]);

        $row = $this->service->addEmail($this->memberId, 'notify@example.com', null);
        $this->repository->markValid($row->id);

        $recipients = [];
        $this->mailService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (...$args) use (&$recipients): void {
                $recipients[] = $args[0];
            });

        $this->service->unsubscribe($row->id);

        $this->assertContains('staffdu@example.com', $recipients);
        $this->assertContains('notify@example.com', $recipients);
    }

    /**
     * The same address can legitimately be linked to several members
     * (e.g. a parent's address added to more than one child) —
     * unsubscribing must apply to the address itself, not just the one
     * member profile the triggering send happened to be tied to.
     */
    public function testUnsubscribeAffectsEveryMemberSharingTheSameAddress(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK2')");
        $otherMemberId = (int) $this->pdo->lastInsertId();

        $row1 = $this->service->addEmail($this->memberId, 'shared@example.com', null);
        $this->repository->markValid($row1->id);
        $row2 = $this->service->addEmail($otherMemberId, 'shared@example.com', null);
        $this->repository->markValid($row2->id);

        $this->service->unsubscribe($row1->id);

        $this->assertTrue($this->repository->findById($row1->id)->isInactive());
        $this->assertTrue($this->repository->findById($row2->id)->isInactive());
    }

    public function testUnsubscribeRevokesLoginForADeskSourcedAddress(): void
    {
        $deskRow = $this->repository->findOrCreateDeskOverride($this->memberId, 'desk-login@example.com');
        $this->assertFalse($this->repository->isBlindIndexInactiveForMember($this->memberId, $this->encryption->blindIndex('desk-login@example.com', 'email')));

        $this->service->unsubscribe($deskRow->id);

        $this->assertTrue($this->repository->isBlindIndexInactiveForMember($this->memberId, $this->encryption->blindIndex('desk-login@example.com', 'email')));
    }

    // --- listForMember() (Desk row synthesis) ---

    public function testListForMemberSynthesizesAnAlwaysValidDeskRowWhenNeverOverridden(): void
    {
        $rows = $this->service->listForMember($this->memberId, 'live-desk@example.com');

        $this->assertCount(1, $rows);
        $this->assertSame('live-desk@example.com', $rows[0]->email);
        $this->assertTrue($rows[0]->isValid());
        $this->assertSame(MemberEmail::SOURCE_DESK, $rows[0]->source);
    }

    public function testListForMemberReflectsARealDeskOverrideRowsStatus(): void
    {
        $deskRow = $this->repository->findOrCreateDeskOverride($this->memberId, 'overridden-desk@example.com');
        $this->repository->markInactive($deskRow->id);

        $rows = $this->service->listForMember($this->memberId, 'overridden-desk@example.com');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->isInactive());
    }

    public function testListForMemberIncludesManualRowsAfterTheDeskRow(): void
    {
        $this->service->addEmail($this->memberId, 'manual@example.com', null);

        $rows = $this->service->listForMember($this->memberId, 'desk@example.com');

        $this->assertCount(2, $rows);
        $this->assertSame(MemberEmail::SOURCE_DESK, $rows[0]->source);
        $this->assertSame('manual@example.com', $rows[1]->email);
    }

    // ── addresses that bounce (roadmap IT-05) ─────────────────────────

    private function blockAddress(string $email): int
    {
        $now = new \DateTimeImmutable('2026-09-19 10:00:00');
        // The unit wrote to this address first — a bounce for one it
        // never wrote to is refused (see `mail_send_receipts`) — and the
        // address is one the site holds, since a receipt is stamped for
        // no other kind.
        DatabaseTestHelper::markAddressOnFile($this->pdo, $email);
        $this->bounceStates->recordSend($email, $now->modify('-1 hour'));
        $state = $this->bounceStates->record(
            $email,
            BounceCategory::NoSuchAddress,
            BounceSeverity::Permanent,
            '5.1.1',
            $now
        );
        self::assertNotNull($state);
        $this->bounceStates->block($state->id, $now);

        return $state->id;
    }

    /**
     * **The point of the whole chantier, in one assertion.** An address
     * the far end has refused twice is one every further message damages
     * the unit's reputation with, so it stops being resolved for a
     * mailing — while staying `valid`, because the member never asked for
     * anything to change (D19).
     */
    public function testABlockedAddressIsNotResolvedForAMailing(): void
    {
        $this->repository->create(
            $this->memberId, 'rebond@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->blockAddress('rebond@example.com');

        $addresses = $this->service->resolveValidAddressesForMassMail($this->memberId, null);

        $this->assertSame(
            [],
            array_map(static fn(MemberEmail $row): string => $row->email, $addresses)
        );
    }

    public function testTheOtherAddressesOfTheSameMemberAreUnaffected(): void
    {
        $this->repository->create(
            $this->memberId, 'rebond@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->repository->create(
            $this->memberId, 'bonne@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->blockAddress('rebond@example.com');

        $addresses = $this->service->resolveValidAddressesForMassMail($this->memberId, null);

        $this->assertSame(
            ['bonne@example.com'],
            array_map(static fn(MemberEmail $row): string => $row->email, $addresses)
        );
    }

    /**
     * A block is the SITE's decision and leaves `status` alone — the
     * three values of that column record the member's decisions, and
     * folding an automatic block into them would lose the why (D19).
     */
    public function testABlockedAddressKeepsTheStatusTheMemberChose(): void
    {
        $id = $this->repository->create(
            $this->memberId, 'rebond@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->blockAddress('rebond@example.com');

        $this->assertSame(MemberEmail::STATUS_VALID, $this->repository->findById($id)?->status);
    }

    public function testTheMemberSeesWhyTheirAddressStopped(): void
    {
        $id = $this->repository->create(
            $this->memberId, 'rebond@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->blockAddress('rebond@example.com');

        $row = $this->repository->findById($id);
        $this->assertNotNull($row);

        $state = $this->service->bounceFor($row);
        $this->assertSame(BounceCategory::NoSuchAddress, $state?->category);
        $this->assertTrue($state?->isBlocked());
    }

    public function testAnAddressThatNeverBouncedSaysNothingAtAll(): void
    {
        $id = $this->repository->create(
            $this->memberId, 'bonne@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $row = $this->repository->findById($id);
        $this->assertNotNull($row);

        $this->assertNull($this->service->bounceFor($row));
    }

    /** D19: the site placed this block, so the person it inconveniences may lift it. */
    public function testTheMemberLiftsTheBlockAndIsWrittenToAgain(): void
    {
        $id = $this->repository->create(
            $this->memberId, 'rebond@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->blockAddress('rebond@example.com');

        $this->assertTrue($this->service->unblockBounce($this->memberId, $id));

        $addresses = $this->service->resolveValidAddressesForMassMail($this->memberId, null);
        $this->assertSame(
            ['rebond@example.com'],
            array_map(static fn(MemberEmail $row): string => $row->email, $addresses)
        );
    }

    /**
     * **Lifting nothing answers false**, so the page can say so instead of
     * reporting a success over a no-op.
     *
     * Every way of getting here is ordinary: a double-submit, a
     * back-button resubmit (the CSRF token is not consumed on use), a
     * second guardian's stale tab, or a super-admin who lifted the block
     * first. The admin path already asked this question of
     * `BounceService::unblock()`; this one used to discard the answer and
     * flash « Adresse réactivée » regardless.
     */
    public function testLiftingABlockThatIsNoLongerThereAnswersFalse(): void
    {
        $id = $this->repository->create(
            $this->memberId, 'rebond@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->blockAddress('rebond@example.com');

        $this->assertTrue($this->service->unblockBounce($this->memberId, $id), 'the first one lifts it.');
        $this->assertFalse(
            $this->service->unblockBounce($this->memberId, $id),
            'and the second has nothing left to lift.'
        );
    }

    /**
     * An address that never bounced has no state at all — a direct POST,
     * or a row whose bounce was forgotten when a message got through.
     */
    public function testLiftingABlockOnAnAddressThatNeverBouncedAnswersFalse(): void
    {
        $id = $this->repository->create(
            $this->memberId, 'jamais@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );

        $this->assertFalse($this->service->unblockBounce($this->memberId, $id));
    }

    /**
     * The existing ownership guard, unchanged: this reaches nothing a
     * member could not already reach. A second member's address is
     * refused even though the block itself belongs to no member in
     * particular.
     */
    public function testAMemberCannotLiftTheBlockOnSomebodyElsesAddress(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK2')");
        $otherMemberId = (int) $this->pdo->lastInsertId();
        $id = $this->repository->create(
            $otherMemberId, 'autre@example.com', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $this->blockAddress('autre@example.com');

        $this->expectException(\Core\Member\MemberEmailException::class);
        $this->service->unblockBounce($this->memberId, $id);
    }

    /**
     * **Ownership is not control.** `addEmail()` accepts any
     * syntactically valid address as a `pending` row — that is what
     * claiming an address IS — and the unique index on `member_emails`
     * is per member, so naming one that already belongs to somebody else
     * succeeds. The bounce state, though, is keyed by the address's
     * blind index and belongs to the mailbox: were it answered here, one
     * member would read another's bounce category and dates off their
     * own page, having proven nothing.
     */
    public function testAnUnconfirmedClaimOnSomebodyElsesAddressIsToldNothingAboutIt(): void
    {
        $this->addPendingRowAndCaptureToken('voisin@example.com');
        $this->blockAddress('voisin@example.com');

        $row = $this->repository->findById($this->lastRowId);
        $this->assertNotNull($row);
        $this->assertTrue($row->isPending());

        $this->assertNull($this->service->bounceFor($row));
    }

    /**
     * And the same claim cannot LIFT that block — the half that matters,
     * since lifting it puts the unit back to writing at a mailbox that
     * refuses it, which is the reputation damage this whole chantier
     * exists to stop.
     */
    public function testAnUnconfirmedClaimCannotLiftTheBlockOnThatAddress(): void
    {
        $this->addPendingRowAndCaptureToken('voisin@example.com');
        $this->blockAddress('voisin@example.com');

        try {
            $this->service->unblockBounce($this->memberId, $this->lastRowId);
            $this->fail('An unconfirmed row must not reach the bounce state of the address it names.');
        } catch (\Core\Member\MemberEmailException $e) {
            // Deliberately indistinguishable from an id belonging to
            // nobody: naming the real reason would confirm, to whoever
            // asked, that the address is known here and suspended.
            $this->assertSame('Adresse introuvable.', $e->getMessage());
        }

        $this->assertTrue($this->bounceStates->find('voisin@example.com')?->isBlocked());
    }

    /**
     * The other side of that guard, and the reason it tests `confirmedAt`
     * rather than the status: once the link has been followed the member
     * has shown they read this mailbox, and self-service works exactly as
     * before.
     */
    public function testConfirmingTheAddressGivesTheMemberTheirButtonBack(): void
    {
        $rawToken = $this->addPendingRowAndCaptureToken('voisin@example.com');
        $this->blockAddress('voisin@example.com');
        $this->assertTrue($this->service->confirmEmail($this->lastRowId, $rawToken));

        $row = $this->repository->findById($this->lastRowId);
        $this->assertNotNull($row);
        $this->assertNotNull($this->service->bounceFor($row));

        $this->service->unblockBounce($this->memberId, $this->lastRowId);

        $this->assertFalse($this->bounceStates->find('voisin@example.com')?->isBlocked());
    }

    /**
     * Adds a pending row directly (bypassing addEmail()'s own MailService
     * call, since these tests only care about the token, not the send) and
     * captures the id + raw token for confirmEmail() assertions.
     */
    private function addPendingRowAndCaptureToken(string $email): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $this->lastRowId = $this->repository->create(
            $this->memberId, $email, MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_PENDING,
            $tokenHash, new \DateTimeImmutable('+48 hours')
        );

        return $rawToken;
    }
}
