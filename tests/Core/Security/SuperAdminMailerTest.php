<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\Security\SuperAdminException;
use Core\Security\SuperAdminMailer;
use Core\Security\SuperAdminService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * Which changes send a mail, which do not, and what happens when the mail
 * transport is down.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SuperAdminMailerTest extends TestCase
{
    private \PDO $pdo;
    private UserAccountRepository $userRepo;
    private MailService&\PHPUnit\Framework\MockObject\MockObject $mailService;
    private SuperAdminService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userRepo = new UserAccountRepository($this->pdo, $encryption);

        $this->mailService = $this->createMock(MailService::class);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('corps du message');

        $this->service = new SuperAdminService(
            $this->userRepo,
            new JournalService(new JournalRepository($this->pdo)),
            new SuperAdminMailer($this->mailService, $twig, 'Unité Test', 'https://unite.example')
        );
    }

    public function testGrantingTheRightSendsTheNotification(): void
    {
        $actor = $this->userRepo->create('celui-qui-donne@example.com', true);

        $this->mailService->expects($this->once())
            ->method('send')
            ->with(
                $this->equalTo('nouveau@example.com'),
                $this->stringContains('administrateur')
            );

        $this->service->grant('nouveau@example.com', $actor->id);
    }

    public function testWithdrawingTheRightSendsTheNotification(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $other = $this->userRepo->create('autre@example.com', true);

        $this->mailService->expects($this->once())
            ->method('send')
            ->with($this->equalTo('autre@example.com'));

        $this->service->revoke($other->id, $me->id);
    }

    public function testDeactivatingSendsTheNotification(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $other = $this->userRepo->create('autre@example.com', true);

        $this->mailService->expects($this->once())
            ->method('send')
            ->with($this->equalTo('autre@example.com'), $this->stringContains('suspendu'));

        $this->service->deactivate($other->id, $me->id);
    }

    /**
     * The access simply works again — nothing to warn anybody about.
     */
    public function testReactivatingSendsNothing(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $other = $this->userRepo->create('autre@example.com', true);
        $this->userRepo->deactivate($other->id);

        $this->mailService->expects($this->never())->method('send');

        $this->service->reactivate($other->id, $me->id);
    }

    /**
     * A refused operation changed nothing, so there is nothing to
     * announce — and mailing somebody that their access was withdrawn
     * when it was not would be worse than saying nothing.
     */
    public function testARefusedWithdrawalSendsNothing(): void
    {
        $alone = $this->userRepo->create('seul@example.com', true);

        $this->mailService->expects($this->never())->method('send');

        $this->expectException(SuperAdminException::class);
        $this->service->revoke($alone->id, 999);
    }

    public function testARefusedSelfWithdrawalSendsNothing(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $this->userRepo->create('autre@example.com', true);

        $this->mailService->expects($this->never())->method('send');

        $this->expectException(SuperAdminException::class);
        $this->service->revoke($me->id, $me->id);
    }

    public function testARefusedDeactivationSendsNothing(): void
    {
        $alone = $this->userRepo->create('seul@example.com', true);

        $this->mailService->expects($this->never())->method('send');

        $this->expectException(SuperAdminException::class);
        $this->service->deactivate($alone->id, 999);
    }

    /**
     * Adding an address that is already a super admin changes nothing, so
     * it announces nothing either — otherwise clicking « Ajouter » twice
     * would mail the same person twice about a right they already had.
     */
    public function testGrantingARightSomebodyAlreadyHasSendsNothing(): void
    {
        $actor = $this->userRepo->create('moi@example.com', true);
        $this->userRepo->create('deja@example.com', true);

        $this->mailService->expects($this->never())->method('send');

        $this->service->grant('deja@example.com', $actor->id);
    }

    /**
     * The change has already happened by the time the mail is attempted.
     * A mail server being down must not undo it, or leave the caller with
     * an exception for something that did succeed.
     */
    public function testAMailFailureDoesNotUndoTheChange(): void
    {
        $me = $this->userRepo->create('moi@example.com', true);
        $other = $this->userRepo->create('autre@example.com', true);

        $this->mailService->method('send')
            ->willThrowException(new MailException('SMTP connect() failed'));

        $this->service->revoke($other->id, $me->id);

        $this->assertFalse($this->userRepo->findById($other->id)?->isSuperAdmin);
    }
}
