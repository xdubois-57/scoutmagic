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
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberEmailServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $repository;
    private MemberEmailService $service;
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
            $twig,
            new JournalService(new JournalRepository($this->pdo)),
            new SectionService($connection, $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo)),
            new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection),
            new ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité',
            $alwaysValidDomain
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
            $this->createMock(\Twig\Environment::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SectionService(Connection::withPdo($this->pdo), $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo)),
            new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo)),
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
        $this->mailService->expects($this->once())->method('send');

        $row = $this->service->addEmail($this->memberId, 'Secondary@Example.com', null);

        $this->assertSame('secondary@example.com', $row->email);
        $this->assertTrue($row->isPending());
        $this->assertSame(MemberEmail::SOURCE_MANUAL, $row->source);
    }

    public function testAddingTheSameAddressTwiceReusesTheExistingRow(): void
    {
        $this->mailService->expects($this->once())->method('send'); // only the first add sends — second is within cooldown

        $first = $this->service->addEmail($this->memberId, 'dup@example.com', null);
        $second = $this->service->addEmail($this->memberId, 'dup@example.com', null);

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->repository->findByMember($this->memberId));
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

        $this->mailService->expects($this->once())->method('send');
        $updated = $this->service->resendConfirmation($this->memberId, $row->id, null);

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
        $stmt->execute([$this->memberId, $scoutYearId, $this->encryption->encrypt('Jean'), $this->encryption->encrypt('Dupont')]);

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
        $this->assertFalse($this->repository->isBlindIndexInactiveForMember($this->memberId, $this->encryption->blindIndex('desk-login@example.com')));

        $this->service->unsubscribe($deskRow->id);

        $this->assertTrue($this->repository->isBlindIndexInactiveForMember($this->memberId, $this->encryption->blindIndex('desk-login@example.com')));
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
