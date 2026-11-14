<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\ConfirmationSendRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\ConfirmationSendLimiter;
use Modules\Registration\Service\SecondaryEmailService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * The one property of SecondaryEmailService::addEmail() only the real
 * engine can show: the cap and the hourly budget are read and spent under
 * a lock on the request's row (issue #1126), so two concurrent additions
 * cannot both read « one left » and both pass.
 *
 * One process cannot interleave with itself, so a second session plays
 * the addition already in flight: it holds a lock on the request's row, and an
 * addEmail() on this session must wait for it — here, until a one-second
 * lock wait gives up — instead of counting past it. SQLite, which every
 * other test of this service runs on, has no row locks to show this.
 */
#[Group('database')]
#[AllowMockObjectsWithoutExpectations]
final class SecondaryEmailServiceOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private SecondaryEmailService $service;
    private RegistrationSecondaryEmailRepository $repository;
    private ConfirmationSendRepository $sends;
    private MailService&\PHPUnit\Framework\MockObject\MockObject $mailService;
    private int $requestId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $this->sends = new ConfirmationSendRepository($this->pdo);
        $this->mailService = $this->createMock(MailService::class);

        $twig = \Core\View\TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['registration' => dirname(__DIR__, 4) . '/modules/registration/views']
        );
        $this->service = new SecondaryEmailService(
            $this->repository,
            $this->mailService,
            EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'registration'),
            $this->createStub(JournalService::class),
            'https://example.com',
            'Unité Test',
            new ConfirmationSendLimiter($this->sends)
        );

        $yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->requestId = RegistrationTestHelper::insertRequest($this->pdo, $yearId, 'origin@example.com');
    }

    public function testAnAdditionWaitsForTheOneAlreadyHoldingTheRequest(): void
    {
        $inFlight = self::productionEngineConnection($this->databaseName())->getPdo();
        $inFlight->beginTransaction();
        // A SHARED lock, deliberately: it conflicts with addEmail()'s own
        // FOR UPDATE, but not with the shared locks its foreign-key checks
        // take on insert — so an addEmail() that skipped the lock would sail
        // through, write, spend the budget and mail, and this test fail.
        $inFlight->prepare('SELECT id FROM registration_requests WHERE id = ? LOCK IN SHARE MODE')
            ->execute([$this->requestId]);

        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->mailService->expects($this->never())->method('send');

        try {
            $this->service->addEmail($this->requestId, 'second@example.com');
            $this->fail(
                'addEmail() counted the request\'s addresses while another addition held its row, so two '
                    . 'concurrent additions can both pass the cap'
            );
        } catch (\PDOException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        } finally {
            $inFlight->rollBack();
        }

        $this->assertSame([], $this->repository->findByRequest($this->requestId), 'nothing was written');
        $this->assertSame(0, $this->sends->countSince($this->requestId, '2000-01-01 00:00:00'), 'no budget spent');
    }

    public function testOnceTheOtherAdditionEndsThisOneProceeds(): void
    {
        $this->mailService->expects($this->once())->method('send')
            ->with($this->identicalTo('second@example.com'));

        $row = $this->service->addEmail($this->requestId, 'second@example.com');

        $this->assertTrue($row->isPending());
        $this->assertSame(1, $this->sends->countSince($this->requestId, '2000-01-01 00:00:00'));
        $this->assertFalse($this->pdo->inTransaction(), 'the transaction is closed before the e-mail leaves');
    }

    private function databaseName(): string
    {
        return (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
    }
}
