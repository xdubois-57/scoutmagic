<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

use Core\Config\AppClock;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\CapabilityToken;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\ConfirmationSendRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\ConfirmationSendLimiter;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\SecondaryEmailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class SecondaryEmailServiceTest extends TestCase
{
    private \PDO $pdo;
    private SecondaryEmailService $service;
    private RegistrationSecondaryEmailRepository $repository;
    private ConfirmationSendRepository $sends;
    private MailService&\PHPUnit\Framework\MockObject\MockObject $mailService;
    private int $requestId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->repository = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $this->mailService = $this->createMock(MailService::class);
        $journalService = $this->createStub(JournalService::class);

        // The real renderer over the shipped templates, not a mock: this
        // e-mail is declared `editable: false`, and a mocked renderer
        // would let that stop being true without a test noticing.
        $twig = \Core\View\TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['registration' => dirname(__DIR__, 4) . '/modules/registration/views']
        );

        $this->sends = new ConfirmationSendRepository($this->pdo);
        $this->service = new SecondaryEmailService(
            $this->repository,
            $this->mailService,
            EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'registration'),
            $journalService,
            'https://example.com',
            'Unité Test',
            new ConfirmationSendLimiter($this->sends)
        );

        $scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $created = $requestRepository->create($scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'origin@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestId = $created['id'];
    }

    public function testAddEmailRejectsInvalidAddress(): void
    {
        $this->expectException(RegistrationException::class);
        $this->service->addEmail($this->requestId, 'not-an-email');
    }

    public function testAddEmailSendsConfirmationAndCreatesAPendingRow(): void
    {
        // **To that address, not merely once.** A count says a message left;
        // it says nothing about where. The confirmation link is the one
        // thing that turns an address a parent typed into a confirmed one,
        // so a recipient mix-up here hands a stranger the means to claim
        // somebody else's — and an unconstrained `method('send')` accepts
        // any argument at all (issue #439).
        //
        // Lower-cased: the row records `secondary@example.com`, and the
        // e-mail has to go to the address that was stored rather than the
        // capitalisation that was typed.
        $this->mailService->expects($this->once())->method('send')
            ->with($this->identicalTo('secondary@example.com'));

        $row = $this->service->addEmail($this->requestId, 'Secondary@Example.com');

        $this->assertTrue($row->isPending());
        $this->assertSame('secondary@example.com', $row->email);
    }

    public function testAddingTheSameEmailTwiceReturnsSameRowWithoutDuplicateSend(): void
    {
        $this->mailService->expects($this->once())->method('send')
            ->with($this->identicalTo('secondary@example.com'));

        $first = $this->service->addEmail($this->requestId, 'secondary@example.com');
        // Second call within the resend cooldown must not re-send.
        $second = $this->service->addEmail($this->requestId, 'secondary@example.com');

        $this->assertSame($first->id, $second->id);
    }

    public function testConfirmEmailRequiresACorrectUnexpiredToken(): void
    {
        $this->mailService->method('send');
        // addEmail() generates its own random token internally — exercise
        // confirmEmail() directly against a row we control instead, mirroring
        // Core\Member\MemberEmailServiceTest's own confirm-flow tests.
        $rawToken = bin2hex(random_bytes(32));
        $id = $this->repository->create(
            $this->requestId,
            'secondary@example.com',
            CapabilityToken::hash($rawToken),
            new \DateTimeImmutable('+48 hours')
        );

        $this->assertFalse($this->service->confirmEmail($id, 'wrong-token'));
        $this->assertTrue($this->service->confirmEmail($id, $rawToken));

        $row = $this->repository->findById($id);
        $this->assertTrue($row->isValid());
    }

    public function testConfirmEmailRejectsExpiredToken(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $id = $this->repository->create(
            $this->requestId,
            'secondary@example.com',
            CapabilityToken::hash($rawToken),
            new \DateTimeImmutable('-1 hour')
        );

        $this->assertFalse($this->service->confirmEmail($id, $rawToken));
    }

    public function testCanConfirmEmailNeverConsumesTheToken(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $id = $this->repository->create(
            $this->requestId,
            'secondary@example.com',
            CapabilityToken::hash($rawToken),
            new \DateTimeImmutable('+48 hours')
        );

        // The GET confirm page may be prefetched any number of times — the
        // token must survive every check, then still confirm.
        $this->assertTrue($this->service->canConfirmEmail($id, $rawToken));
        $this->assertTrue($this->service->canConfirmEmail($id, $rawToken));
        $this->assertTrue($this->repository->findById($id)->isPending());

        $this->assertTrue($this->service->confirmEmail($id, $rawToken));
        $this->assertFalse($this->service->canConfirmEmail($id, $rawToken));
    }

    public function testCanConfirmEmailRejectsAWrongTokenWithoutMutating(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $id = $this->repository->create(
            $this->requestId,
            'secondary@example.com',
            CapabilityToken::hash($rawToken),
            new \DateTimeImmutable('+48 hours')
        );

        $this->assertFalse($this->service->canConfirmEmail($id, 'wrong-token'));
        $this->assertTrue($this->repository->findById($id)->isPending());
    }

    public function testRemoveEmailRejectsRowFromAnotherRequest(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $otherRequest = $requestRepository->create(
            RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31'),
            [
                'parent_name' => 'P2', 'child_last_name' => 'L2', 'child_first_name' => 'F2',
                'gender' => 'H', 'birth_date' => '2020-01-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'other@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ],
            null,
            []
        );

        $this->mailService->method('send');
        $row = $this->service->addEmail($this->requestId, 'secondary@example.com');

        $this->expectException(RegistrationException::class);
        $this->service->removeEmail($otherRequest['id'], $row->id);
    }

    public function testListForRequestReturnsOnlyRowsForThatRequest(): void
    {
        $this->mailService->method('send');
        $this->service->addEmail($this->requestId, 'a@example.com');
        $this->service->addEmail($this->requestId, 'b@example.com');

        $this->assertCount(2, $this->service->listForRequest($this->requestId));
    }

    /**
     * Issue #1126: the token addEmail() mails is the one its stored SHA-256
     * accepts — read back from the link in the e-mail itself, so a stored
     * hash that stopped matching what is sent would fail here.
     */
    public function testTheMailedTokenConfirmsAgainstItsStoredSha256(): void
    {
        $body = '';
        $sentTo = '';
        $this->mailService->expects($this->once())->method('send')
            ->willReturnCallback(function (string $to, string $subject, string $bodyHtml) use (&$body, &$sentTo): void {
                $sentTo = $to;
                $body = $bodyHtml;
            });

        $row = $this->service->addEmail($this->requestId, 'secondary@example.com');

        $this->assertSame('secondary@example.com', $sentTo);

        $this->assertSame(1, preg_match('/token=([0-9a-f]{64})/', $body, $match));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row->confirmationTokenHash);
        $this->assertSame(CapabilityToken::hash($match[1]), $row->confirmationTokenHash);
        $this->assertNotSame($match[1], $row->confirmationTokenHash);
        $this->assertFalse($this->service->confirmEmail($row->id, strrev($match[1])));
        $this->assertTrue($this->service->confirmEmail($row->id, $match[1]));
    }

    /** A bcrypt hash of the right token is no longer accepted: no fallback. */
    public function testAPasswordHashOfTheTokenIsNotAccepted(): void
    {
        $rawToken = CapabilityToken::generate();
        $id = $this->repository->create(
            $this->requestId,
            'secondary@example.com',
            password_hash($rawToken, PASSWORD_DEFAULT),
            new \DateTimeImmutable('+48 hours')
        );

        $this->assertFalse($this->service->canConfirmEmail($id, $rawToken));
    }

    public function testTheFifthAddressIsAcceptedAndTheSixthRefusedWithNothingWrittenOrSent(): void
    {
        $recipients = [];
        $this->mailService->expects($this->exactly(SecondaryEmailService::MAX_SECONDARY_EMAILS))->method('send')
            ->willReturnCallback(function (string $to) use (&$recipients): void {
                $recipients[] = $to;
            });
        $expected = [];
        for ($i = 1; $i <= SecondaryEmailService::MAX_SECONDARY_EMAILS; $i++) {
            $this->service->addEmail($this->requestId, "secondary{$i}@example.com");
            $expected[] = "secondary{$i}@example.com";
        }
        $this->assertSame(5, SecondaryEmailService::MAX_SECONDARY_EMAILS);

        try {
            $this->service->addEmail($this->requestId, 'one-too-many@example.com');
            $this->fail('the sixth address should be refused');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('nombre maximum', $e->getMessage());
        }

        $this->assertSame($expected, $recipients);
        $this->assertCount(5, $this->service->listForRequest($this->requestId));
        $this->assertSame(5, $this->sends->countSince($this->requestId, '2000-01-01 00:00:00'));
    }

    /** Every row counts toward the cap, a confirmed one as much as a pending one. */
    public function testTheCapCountsConfirmedAddressesToo(): void
    {
        $this->mailService->method('send');
        for ($i = 1; $i <= SecondaryEmailService::MAX_SECONDARY_EMAILS; $i++) {
            $row = $this->service->addEmail($this->requestId, "secondary{$i}@example.com");
            $this->repository->markValid($row->id);
        }

        $this->expectException(RegistrationException::class);
        $this->service->addEmail($this->requestId, 'one-too-many@example.com');
    }

    /** At the cap, re-adding an address already there is not a new one. */
    public function testReAddingAnExistingAddressStillWorksAtTheCap(): void
    {
        $this->mailService->method('send');
        $rows = [];
        for ($i = 1; $i <= SecondaryEmailService::MAX_SECONDARY_EMAILS; $i++) {
            $rows[] = $this->service->addEmail($this->requestId, "secondary{$i}@example.com");
        }

        $again = $this->service->addEmail($this->requestId, 'Secondary1@example.com');

        $this->assertSame($rows[0]->id, $again->id);
    }

    public function testANewAddressIsRefusedOnceTheHourlyLimitIsSpentWithNothingWrittenOrSent(): void
    {
        $now = AppClock::now()->format('Y-m-d H:i:s');
        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->sends->record($this->requestId, $now);
        }
        $this->mailService->expects($this->never())->method('send');

        try {
            $this->service->addEmail($this->requestId, 'secondary@example.com');
            $this->fail('the address should be refused past the hourly limit');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('réessayez dans une heure', $e->getMessage());
        }

        $this->assertSame([], $this->service->listForRequest($this->requestId));
        $this->assertSame(
            ConfirmationSendLimiter::LIMIT,
            $this->sends->countSince($this->requestId, '2000-01-01 00:00:00')
        );
    }

    /**
     * Removing an address deletes its row, so the cap alone would let
     * remove-then-add send e-mails for ever; the hourly limit stops it.
     */
    public function testTheRemoveThenAddLoopIsStoppedByTheHourlyLimit(): void
    {
        $this->mailService->expects($this->exactly(ConfirmationSendLimiter::LIMIT))->method('send')
            ->with($this->identicalTo('victim@example.com'));

        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $row = $this->service->addEmail($this->requestId, 'victim@example.com');
            $this->service->removeEmail($this->requestId, $row->id);
        }

        $this->expectException(RegistrationException::class);
        $this->service->addEmail($this->requestId, 'victim@example.com');
    }

    /** Sends older than an hour no longer count; those within it do. */
    public function testTheHourlyWindowIsRolling(): void
    {
        $this->mailService->method('send');
        $old = AppClock::now()->modify('-61 minutes')->format('Y-m-d H:i:s');
        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->sends->record($this->requestId, $old);
        }
        $this->service->addEmail($this->requestId, 'first@example.com');

        $recent = AppClock::now()->modify('-59 minutes')->format('Y-m-d H:i:s');
        for ($i = 1; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->sends->record($this->requestId, $recent);
        }

        $this->expectException(RegistrationException::class);
        $this->service->addEmail($this->requestId, 'second@example.com');
    }

    /**
     * The re-send of a pending address counts like a first send, and past
     * the limit it is skipped silently: the row comes back, no e-mail
     * leaves, and the token already mailed keeps working.
     */
    public function testAResendCountsAndIsSkippedSilentlyPastTheLimit(): void
    {
        $rawToken = CapabilityToken::generate();
        $id = $this->repository->create(
            $this->requestId,
            'secondary@example.com',
            CapabilityToken::hash($rawToken),
            new \DateTimeImmutable('+48 hours')
        );
        $this->elapseResendCooldown($id);

        $this->mailService->expects($this->once())->method('send')
            ->with($this->identicalTo('secondary@example.com'));
        $this->service->addEmail($this->requestId, 'secondary@example.com');
        $this->assertSame(1, $this->sends->countSince($this->requestId, '2000-01-01 00:00:00'));
        $this->assertFalse($this->service->canConfirmEmail($id, $rawToken), 'the re-send replaced the token');

        $now = AppClock::now()->format('Y-m-d H:i:s');
        for ($i = 1; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->sends->record($this->requestId, $now);
        }
        $this->elapseResendCooldown($id);
        $before = $this->repository->findById($id);

        $again = $this->service->addEmail($this->requestId, 'secondary@example.com');

        $this->assertSame($id, $again->id);
        $this->assertSame($before?->confirmationTokenHash, $this->repository->findById($id)?->confirmationTokenHash);
        $this->assertSame(
            ConfirmationSendLimiter::LIMIT,
            $this->sends->countSince($this->requestId, '2000-01-01 00:00:00')
        );
    }

    /** The hourly budget is per request. */
    public function testTheHourlyLimitIsPerRequest(): void
    {
        $now = AppClock::now()->format('Y-m-d H:i:s');
        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->sends->record($this->requestId, $now);
        }
        $other = $this->otherRequestId();
        $this->mailService->expects($this->once())->method('send')
            ->with($this->identicalTo('secondary@example.com'));

        $this->assertTrue($this->service->addEmail($other, 'secondary@example.com')->isPending());
    }

    private function elapseResendCooldown(int $emailId): void
    {
        $this->pdo->prepare('UPDATE registration_secondary_emails SET last_confirmation_sent_at = ? WHERE id = ?')
            ->execute(['2000-01-01 00:00:00', $emailId]);
    }

    private function otherRequestId(): int
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return (new RegistrationRequestRepository($this->pdo, $encryption))->create(
            RegistrationTestHelper::insertScoutYear($this->pdo, '2028-2029', '2028-09-01', '2029-08-31'),
            [
                'parent_name' => 'P3', 'child_last_name' => 'L3', 'child_first_name' => 'F3',
                'gender' => 'H', 'birth_date' => '2020-01-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'third@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ],
            null,
            []
        )['id'];
    }
}
